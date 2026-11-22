<?php
/**
 * Turns a minutes document into HTML for [bmlt_minutes_latest]: Word (.docx), OpenDocument text (.odt), Excel
 * (.xlsx), OpenDocument spreadsheet (.ods), CSV and plain text. PDFs are drawn in the browser instead (js/latest.js).
 *
 * Only what reads well in a page is kept: headings, paragraphs, bold, italic, underline, strikethrough, superscript
 * and subscript, links, lists and tables (a sheet each for spreadsheets). Pictures, page layout, headers, footers,
 * comments and tracked deletions are left out. Every piece of text is escaped here, and the result goes through
 * wp_kses_post() as well.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHP's DOM classes name their properties so (childNodes, localName, namespaceURI, textContent...).

final class BMLT_Minutes_Document_HTML {

	/** Extensions convert() handles. */
	public const TYPES = [ 'docx', 'odt', 'xlsx', 'ods', 'csv', 'txt' ];

	/** Larger files are not converted (they are offered for download instead). */
	public const MAX_FILE_BYTES = 20971520;

	/** The most a part inside a .docx/.odt/.xlsx/.ods may unpack to, so a zip bomb cannot exhaust memory. */
	private const MAX_PART_BYTES = 52428800;

	/** Rows and columns shown of each sheet or CSV file. */
	private const MAX_ROWS = 2000;
	private const MAX_COLS = 60;

	/** Converted HTML is kept (as a transient) when it is no larger than this. */
	private const MAX_CACHED_BYTES = 1048576;

	private const W    = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
	private const R    = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
	private const PR   = 'http://schemas.openxmlformats.org/package/2006/relationships';
	private const S    = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
	private const TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
	private const TBL  = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
	private const OFF  = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
	private const STY  = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
	private const FO   = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';
	private const XL   = 'http://www.w3.org/1999/xlink';

	/** Heading level that the document's own top-level headings take (set per conversion). */
	private int $top = 3;

	/** @var array<string, array{0: string, 1: string}> .docx relationship id => [target, mode]. */
	private array $rels = [];

	/** @var array<string, int> .docx paragraph style id => heading level 1..6 (0 for the Title style). */
	private array $heading_styles = [];

	/** Prefix of the ids given to bookmarked paragraphs, so a table of contents links to its headings. */
	private string $anchor = 'bmlt-minutes-doc-';

	/** @var array<string, array<int, array{tag: string, fmt: string, text: string, start: int}>> .docx numId => level => its list tag, number format, label ("%1)") and first number. */
	private array $numbering = [];

	/** @var array<string, array{0: string, 1: int}> .docx paragraph style id => [numId, level] numbered by the style itself. */
	private array $style_numbering = [];

	/** @var array<string, array<int, int>> .docx numId => level => the last number given, as Word counts. */
	private array $counters = [];

	/** @var array<string, array<string, bool>> .odt automatic text style name => bold/italic/underline/strike/sup/sub. */
	private array $odt_styles = [];

	/** @var array<string, string> .odt list style name => "ul" or "ol" (from its first level). */
	private array $odt_lists = [];

	/** @var string[] Open lists while converting a .docx (innermost last). */
	private array $list_stack = [];

	/**
	 * The document as HTML, from the cache when the same file was converted before. Returns null when the file
	 * cannot be read or converted (the caller then offers it for download).
	 *
	 * @param string $path    The document on disk.
	 * @param string $type    Its extension (one of TYPES).
	 * @param int    $top     Heading level for the document's top-level headings (2..6).
	 */
	public static function cached( string $path, string $type, int $top ): ?string {
		if ( ! in_array( $type, self::TYPES, true ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$size = (int) filesize( $path );
		if ( $size <= 0 || $size > self::MAX_FILE_BYTES ) {
			return null;
		}
		$key  = 'bmlt_minutes_doc_' . md5( implode( '|', [ $path, (string) filemtime( $path ), (string) $size, $type, (string) $top, BMLT_MINUTES_VERSION ] ) );
		$html = get_transient( $key );
		if ( is_string( $html ) ) {
			return '' === $html ? null : $html;
		}
		$html = self::convert( $path, $type, $top );
		if ( null === $html ) {
			set_transient( $key, '', HOUR_IN_SECONDS ); // Not convertible: don't try on every page view.
			return null;
		}
		if ( strlen( $html ) <= self::MAX_CACHED_BYTES ) {
			set_transient( $key, $html, 30 * DAY_IN_SECONDS );
		}
		return $html;
	}

	/**
	 * The document as HTML, or null when it cannot be converted.
	 */
	public static function convert( string $path, string $type, int $top = 3 ): ?string {
		$c      = new self();
		$c->top = max( 1, min( 6, $top ) );
		try {
			switch ( $type ) {
				case 'docx':
					$html = $c->docx( $path );
					break;
				case 'odt':
					$html = $c->odt( $path );
					break;
				case 'xlsx':
					$html = $c->xlsx( $path );
					break;
				case 'ods':
					$html = $c->ods( $path );
					break;
				case 'csv':
					$html = $c->csv( $path );
					break;
				case 'txt':
					$html = $c->txt( $path );
					break;
				default:
					$html = null;
			}
		} catch ( Throwable $e ) {
			$html = null;
		}
		if ( null === $html || '' === trim( wp_strip_all_tags( $html ) ) ) {
			return null;
		}
		return wp_kses_post( $html );
	}

	// ---- Shared ------------------------------------------------------------------------------------------------

	/**
	 * One part of a zip package as a DOM, or null when it is missing, too large or not XML.
	 */
	private static function part( ZipArchive $zip, string $name ): ?DOMDocument {
		$stat = $zip->statName( $name );
		if ( false === $stat || $stat['size'] > self::MAX_PART_BYTES ) {
			return null;
		}
		$xml = $zip->getFromName( $name );
		if ( ! is_string( $xml ) || '' === $xml ) {
			return null;
		}
		$doc = new DOMDocument();
		// LIBXML_NONET: never fetch anything. External entities are not loaded (PHP 8's default), and no
		// LIBXML_NOENT, so entities are not expanded.
		$old = libxml_use_internal_errors( true );
		$ok  = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		return $ok ? $doc : null;
	}

	private static function open_zip( string $path ): ?ZipArchive {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}
		$zip = new ZipArchive();
		return true === $zip->open( $path, ZipArchive::RDONLY ) ? $zip : null;
	}

	/**
	 * Text runs wrapped in the formatting they carry.
	 *
	 * @param array<string, bool> $f bold, italic, underline, strike, sup, sub.
	 */
	private static function format( string $html, array $f ): string {
		if ( '' === $html ) {
			return '';
		}
		$tags = [
			'sup'       => 'sup',
			'sub'       => 'sub',
			'strike'    => 's',
			'underline' => 'u',
			'italic'    => 'em',
			'bold'      => 'strong',
		];
		foreach ( $tags as $key => $tag ) {
			if ( ! empty( $f[ $key ] ) ) {
				$html = '<' . $tag . '>' . $html . '</' . $tag . '>';
			}
		}
		return $html;
	}

	/**
	 * Joins runs with the same formatting ("<strong>a</strong><strong>b</strong>" from Word's many runs) and trims
	 * line breaks off the ends.
	 */
	private static function tidy( string $html ): string {
		do {
			$before = $html;
			$html   = (string) preg_replace( '#</(strong|em|u|s|sup|sub)><\1>#', '', $html );
			$html   = (string) preg_replace( '#(<a href="([^"]*)">(?:(?!</a>).)*)</a>(\s*)<a href="\2">#s', '$1$3', $html );
		} while ( $html !== $before );
		$html = (string) preg_replace( '#(\s|<br>)+((?:</[a-z]+>)*)$#', '$2', $html );
		return (string) preg_replace( '#^(\s|<br>)+#', '', $html );
	}

	private static function link( string $html, string $url ): string {
		$url = esc_url( $url, [ 'http', 'https', 'mailto', 'tel' ] );
		return '' === $url || '' === $html ? $html : '<a href="' . $url . '">' . $html . '</a>';
	}

	private function heading( int $level, string $html, string $id = '' ): string {
		$h = 'h' . min( 6, $this->top + max( 0, $level - 1 ) );
		return '<' . $h . $id . '>' . $html . '</' . $h . '>';
	}

	/**
	 * A table from rows of cell HTML (each cell [html, colspan, rowspan]), with empty trailing rows and columns
	 * dropped.
	 *
	 * @param list<list<array{0: string, 1: int, 2: int}>> $rows
	 */
	private static function table( array $rows, string $caption = '' ): string {
		while ( [] !== $rows && self::row_is_empty( end( $rows ) ) ) {
			array_pop( $rows );
		}
		if ( [] === $rows ) {
			return '';
		}
		$out = '<div class="bmlt-minutes-doc__table"><table>';
		if ( '' !== $caption ) {
			$out .= '<caption>' . esc_html( $caption ) . '</caption>';
		}
		$out .= '<tbody>';
		foreach ( $rows as $row ) {
			$out .= '<tr>';
			foreach ( $row as $cell ) {
				$out .= '<td' . ( $cell[1] > 1 ? ' colspan="' . (int) $cell[1] . '"' : '' ) . ( $cell[2] > 1 ? ' rowspan="' . (int) $cell[2] . '"' : '' ) . '>' . $cell[0] . '</td>';
			}
			$out .= '</tr>';
		}
		return $out . '</tbody></table></div>';
	}

	/**
	 * @param list<array{0: string, 1: int, 2: int}> $row
	 */
	private static function row_is_empty( array $row ): bool {
		foreach ( $row as $cell ) {
			if ( '' !== trim( wp_strip_all_tags( $cell[0] ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Cells of a plain grid (strings), trailing empty columns dropped, as table() rows.
	 *
	 * @param list<list<string>> $grid Cell HTML.
	 * @return list<list<array{0: string, 1: int, 2: int}>>
	 */
	private static function grid_rows( array $grid ): array {
		$width = 0;
		foreach ( $grid as $row ) {
			foreach ( $row as $i => $cell ) {
				if ( '' !== trim( $cell ) ) {
					$width = max( $width, $i + 1 );
				}
			}
		}
		$rows = [];
		foreach ( $grid as $row ) {
			$cells = [];
			for ( $i = 0; $i < $width; $i++ ) {
				$cells[] = [ $row[ $i ] ?? '', 1, 1 ];
			}
			$rows[] = $cells;
		}
		return $rows;
	}

	// ---- Word (.docx) ------------------------------------------------------------------------------------------

	private function docx( string $path ): ?string {
		$zip = self::open_zip( $path );
		if ( null === $zip ) {
			return null;
		}
		$this->anchor = 'bmlt-minutes-doc-' . substr( md5( $path ), 0, 6 ) . '-';
		$doc          = self::part( $zip, 'word/document.xml' );
		if ( null === $doc ) {
			$zip->close();
			return null;
		}
		$this->docx_rels( self::part( $zip, 'word/_rels/document.xml.rels' ) );
		$this->docx_styles( self::part( $zip, 'word/styles.xml' ) );
		$this->docx_numbering( self::part( $zip, 'word/numbering.xml' ) );
		$zip->close();

		$body = $doc->getElementsByTagNameNS( self::W, 'body' )->item( 0 );
		return $body instanceof DOMElement ? $this->docx_blocks( $body ) : null;
	}

	private function docx_rels( ?DOMDocument $rels ): void {
		if ( null === $rels ) {
			return;
		}
		foreach ( $rels->getElementsByTagNameNS( self::PR, 'Relationship' ) as $rel ) {
			$this->rels[ $rel->getAttribute( 'Id' ) ] = [ $rel->getAttribute( 'Target' ), $rel->getAttribute( 'TargetMode' ) ];
		}
	}

	private static function wval( ?DOMElement $el, string $name = 'val' ): string {
		return null === $el ? '' : $el->getAttributeNS( self::W, $name );
	}

	private static function wchild( DOMElement $el, string $name ): ?DOMElement {
		foreach ( $el->childNodes as $child ) {
			if ( $child instanceof DOMElement && self::W === $child->namespaceURI && $name === $child->localName ) {
				return $child;
			}
		}
		return null;
	}

	private function docx_styles( ?DOMDocument $styles ): void {
		if ( null === $styles ) {
			return;
		}
		$based = [];
		foreach ( $styles->getElementsByTagNameNS( self::W, 'style' ) as $style ) {
			if ( 'paragraph' !== self::wval( $style, 'type' ) ) {
				continue;
			}
			$id   = self::wval( $style, 'styleId' );
			$name = strtolower( self::wval( self::wchild( $style, 'name' ) ) );
			$ppr  = self::wchild( $style, 'pPr' );
			$lvl  = null === $ppr ? '' : self::wval( self::wchild( $ppr, 'outlineLvl' ) );
			$snum = null === $ppr ? null : self::wchild( $ppr, 'numPr' );
			if ( null !== $snum && '' !== self::wval( self::wchild( $snum, 'numId' ) ) ) {
				$this->style_numbering[ $id ] = [ self::wval( self::wchild( $snum, 'numId' ) ), (int) self::wval( self::wchild( $snum, 'ilvl' ) ) ];
			}
			if ( 'title' === $name ) {
				$this->heading_styles[ $id ] = 0;
			} elseif ( 1 === preg_match( '/^heading ([1-6])$/', $name, $m ) ) {
				$this->heading_styles[ $id ] = (int) $m[1];
			} elseif ( '' !== $lvl && ctype_digit( $lvl ) && (int) $lvl < 6 ) {
				$this->heading_styles[ $id ] = (int) $lvl + 1;
			}
			$parent = self::wval( self::wchild( $style, 'basedOn' ) );
			if ( '' !== $parent ) {
				$based[ $id ] = $parent;
			}
		}
		// Styles based on a heading style are headings of the same level.
		foreach ( $based as $id => $parent ) {
			for ( $hops = 0; ! isset( $this->heading_styles[ $id ] ) && '' !== $parent && $hops < 10; $hops++ ) {
				if ( isset( $this->heading_styles[ $parent ] ) ) {
					$this->heading_styles[ $id ] = $this->heading_styles[ $parent ];
				}
				$parent = $based[ $parent ] ?? '';
			}
		}
	}

	private function docx_numbering( ?DOMDocument $numbering ): void {
		if ( null === $numbering ) {
			return;
		}
		$abstract = [];
		foreach ( $numbering->getElementsByTagNameNS( self::W, 'abstractNum' ) as $an ) {
			$levels = [];
			foreach ( $an->getElementsByTagNameNS( self::W, 'lvl' ) as $lvl ) {
				$fmt   = self::wval( self::wchild( $lvl, 'numFmt' ) );
				$start = self::wval( self::wchild( $lvl, 'start' ) );
				$levels[ (int) self::wval( $lvl, 'ilvl' ) ] = [
					'tag'   => in_array( $fmt, [ 'bullet', 'none', '' ], true ) ? 'ul' : 'ol',
					'fmt'   => '' === $fmt ? 'bullet' : $fmt,
					'text'  => self::wval( self::wchild( $lvl, 'lvlText' ) ),
					'start' => '' === $start ? 1 : (int) $start,
				];
			}
			$abstract[ self::wval( $an, 'abstractNumId' ) ] = $levels;
		}
		foreach ( $numbering->getElementsByTagNameNS( self::W, 'num' ) as $num ) {
			$this->numbering[ self::wval( $num, 'numId' ) ] = $abstract[ self::wval( self::wchild( $num, 'abstractNumId' ) ) ] ?? [];
		}
	}

	/**
	 * Paragraphs and tables of a body, table cell or content control.
	 */
	private function docx_blocks( DOMElement $parent ): string {
		$out = '';
		foreach ( $parent->childNodes as $node ) {
			if ( ! $node instanceof DOMElement || self::W !== $node->namespaceURI ) {
				continue;
			}
			switch ( $node->localName ) {
				case 'p':
					$out .= $this->docx_paragraph( $node );
					break;
				case 'tbl':
					$out .= $this->close_lists() . $this->docx_table( $node );
					break;
				case 'sdt':
					$content = self::wchild( $node, 'sdtContent' );
					$out    .= null === $content ? '' : $this->docx_blocks( $content );
					break;
				case 'customXml':
				case 'ins':
					$out .= $this->docx_blocks( $node );
					break;
			}
		}
		return $out . $this->close_lists();
	}

	private function docx_paragraph( DOMElement $p ): string {
		$ppr   = self::wchild( $p, 'pPr' );
		$style = null === $ppr ? '' : self::wval( self::wchild( $ppr, 'pStyle' ) );
		$num   = null === $ppr ? null : self::wchild( $ppr, 'numPr' );
		$align = null === $ppr ? '' : self::wval( self::wchild( $ppr, 'jc' ) );
		$state = [
			'field' => '',
			'instr' => '',
			'url'   => '',
			'buf'   => '',
		];
		$html = self::tidy( $this->docx_inline( $p, $state ) );
		// The paragraph's first bookmark (a heading's "_Toc..." one, say) becomes its id, for links to it.
		$id = '';
		foreach ( $p->getElementsByTagNameNS( self::W, 'bookmarkStart' ) as $mark ) {
			$name = self::wval( $mark, 'name' );
			if ( '' !== $name && '_GoBack' !== $name ) {
				$id = ' id="' . esc_attr( $this->anchor . sanitize_html_class( $name ) ) . '"';
				break;
			}
		}

		// Numbered by the paragraph, or by its style.
		$num_id = '';
		$level  = 0;
		if ( null !== $num ) {
			$num_id = self::wval( self::wchild( $num, 'numId' ) );
			$level  = (int) self::wval( self::wchild( $num, 'ilvl' ) );
		} elseif ( isset( $this->style_numbering[ $style ] ) ) {
			[ $num_id, $level ] = $this->style_numbering[ $style ];
		}
		$level    = max( 0, min( 8, $level ) );
		$numbered = '' !== $num_id && '0' !== $num_id && isset( $this->numbering[ $num_id ][ $level ] ) && '' !== $html;
		if ( $numbered ) {
			$this->count( $num_id, $level );
		}

		// A numbered heading ("a) Policy") stays a heading, with its label.
		if ( isset( $this->heading_styles[ $style ] ) && '' !== $html ) {
			$label = $numbered ? $this->label( $num_id, $level ) : '';
			return $this->close_lists() . $this->heading( max( 1, $this->heading_styles[ $style ] ), ( '' !== $label ? esc_html( $label ) . ' ' : '' ) . $html, $id );
		}
		if ( $numbered ) {
			$def = $this->numbering[ $num_id ][ $level ];
			return $this->list_item( $level, $def['tag'], $html, $id, 'ol' === $def['tag'] ? self::list_attrs( $def['fmt'], $this->counters[ $num_id ][ $level ] ) : '' );
		}
		$out = $this->close_lists();
		if ( '' === $html ) {
			return $out;
		}
		$class = in_array( $align, [ 'center', 'right', 'end' ], true ) ? ' class="bmlt-minutes-doc__' . ( 'center' === $align ? 'center' : 'right' ) . '"' : '';
		return $out . '<p' . $id . $class . '>' . $html . '</p>';
	}

	/**
	 * A list item at a level (0 = outermost), opening and closing lists around it.
	 */
	private function list_item( int $level, string $type, string $html, string $id = '', string $attrs = '' ): string {
		$out = '';
		while ( count( $this->list_stack ) > $level + 1 ) {
			$out .= '</li></' . array_pop( $this->list_stack ) . '>';
		}
		if ( count( $this->list_stack ) === $level + 1 ) {
			if ( end( $this->list_stack ) === $type ) {
				$out .= '</li>';
			} else {
				$out .= '</li></' . array_pop( $this->list_stack ) . '>';
			}
		}
		$opened = false;
		while ( count( $this->list_stack ) < $level + 1 ) {
			if ( $opened ) {
				$out .= '<li class="bmlt-minutes-doc__nest">'; // A level skipped: an unmarked item holds the deeper list.
			}
			$here               = count( $this->list_stack ) === $level;
			$this->list_stack[] = $here ? $type : 'ul';
			$out               .= '<' . end( $this->list_stack ) . ( $here ? $attrs : ' class="bmlt-minutes-doc__nest"' ) . '>';
			$opened             = true;
		}
		return $out . '<li' . $id . '>' . $html;
	}

	/**
	 * Counts a numbered paragraph as Word does: the next number at its level, and the levels below start again.
	 */
	private function count( string $num_id, int $level ): void {
		foreach ( array_keys( $this->counters[ $num_id ] ?? [] ) as $deeper ) {
			if ( $deeper > $level ) {
				unset( $this->counters[ $num_id ][ $deeper ] );
			}
		}
		$this->counters[ $num_id ][ $level ] = isset( $this->counters[ $num_id ][ $level ] ) ? $this->counters[ $num_id ][ $level ] + 1 : $this->numbering[ $num_id ][ $level ]['start'];
	}

	/**
	 * A numbered paragraph's label as Word shows it ("a)", "2.1", "(iv)"), from its level's text ("%1)").
	 */
	private function label( string $num_id, int $level ): string {
		$def = $this->numbering[ $num_id ][ $level ];
		if ( in_array( $def['fmt'], [ 'bullet', 'none' ], true ) ) {
			return '';
		}
		return trim(
			(string) preg_replace_callback(
				'/%([1-9])/',
				function ( array $m ) use ( $num_id ): string {
					$at  = (int) $m[1] - 1;
					$fmt = $this->numbering[ $num_id ][ $at ]['fmt'] ?? 'decimal';
					return self::number( $this->counters[ $num_id ][ $at ] ?? ( $this->numbering[ $num_id ][ $at ]['start'] ?? 1 ), $fmt );
				},
				$def['text']
			)
		);
	}

	private static function number( int $n, string $fmt ): string {
		switch ( $fmt ) {
			case 'lowerLetter':
			case 'upperLetter':
				$s = '';
				for ( $k = max( 1, $n ); $k > 0; $k = intdiv( $k - 1, 26 ) ) {
					$s = chr( 97 + ( $k - 1 ) % 26 ) . $s;
				}
				return 'upperLetter' === $fmt ? strtoupper( $s ) : $s;
			case 'lowerRoman':
			case 'upperRoman':
				$s = '';
				foreach ( [
					1000 => 'm',
					900 => 'cm',
					500 => 'd',
					400 => 'cd',
					100 => 'c',
					90 => 'xc',
					50 => 'l',
					40 => 'xl',
					10 => 'x',
					9 => 'ix',
					5 => 'v',
					4 => 'iv',
					1 => 'i',
				] as $v => $r ) {
					for ( ; $n >= $v; $n -= $v ) {
						$s .= $r;
					}
				}
				return 'upperRoman' === $fmt ? strtoupper( $s ) : $s;
			case 'decimalZero':
				return sprintf( '%02d', $n );
			default:
				return (string) $n;
		}
	}

	/**
	 * An ordered list's type and first number, so a list that carries on after a paragraph keeps counting.
	 */
	private static function list_attrs( string $fmt, int $start ): string {
		$type = [
			'lowerLetter' => 'a',
			'upperLetter' => 'A',
			'lowerRoman'  => 'i',
			'upperRoman'  => 'I',
		][ $fmt ] ?? '';
		return ( '' !== $type ? ' type="' . $type . '"' : '' ) . ( $start > 1 ? ' start="' . $start . '"' : '' );
	}

	private function close_lists(): string {
		$out = '';
		while ( [] !== $this->list_stack ) {
			$out .= '</li></' . array_pop( $this->list_stack ) . '>';
		}
		return $out;
	}

	/**
	 * The text of a paragraph (or hyperlink, field, inserted text...), formatted. $state follows complex fields
	 * across runs, so a HYPERLINK field's result becomes a link.
	 *
	 * @param array{field: string, instr: string, url: string, buf: string} $state
	 */
	private function docx_inline( DOMElement $parent, array &$state ): string {
		$out = '';
		foreach ( $parent->childNodes as $node ) {
			if ( ! $node instanceof DOMElement || self::W !== $node->namespaceURI ) {
				continue;
			}
			switch ( $node->localName ) {
				case 'r':
					$out .= $this->docx_run( $node, $state );
					break;
				case 'hyperlink':
					$inner = $this->docx_inline( $node, $state );
					$rid   = $node->getAttributeNS( self::R, 'id' );
					$mark  = self::wval( $node, 'anchor' );
					if ( isset( $this->rels[ $rid ] ) && 'External' === $this->rels[ $rid ][1] ) {
						$out .= self::link( $inner, $this->rels[ $rid ][0] );
					} elseif ( '' !== $mark && '' !== $inner ) {
						// A link inside the document (a table of contents entry): to the bookmarked paragraph.
						$out .= '<a href="#' . esc_attr( $this->anchor . sanitize_html_class( $mark ) ) . '">' . $inner . '</a>';
					} else {
						$out .= $inner;
					}
					break;
				case 'fldSimple':
					$inner = $this->docx_inline( $node, $state );
					$url   = self::field_url( self::wval( $node, 'instr' ) );
					$out  .= '' !== $url ? self::link( $inner, $url ) : $inner;
					break;
				case 'smartTag':
				case 'customXml':
				case 'ins':
				case 'moveTo':
				case 'bdo':
				case 'dir':
					$out .= $this->docx_inline( $node, $state );
					break;
				case 'sdt':
					$content = self::wchild( $node, 'sdtContent' );
					$out    .= null === $content ? '' : $this->docx_inline( $content, $state );
					break;
			}
		}
		return $out;
	}

	/**
	 * What is left of a page-number field's result without the number. Word's tables of contents put only the number
	 * there; Google Docs puts the heading too ("Agenda<tab>7"). A PAGEREF to a bookmark links to it.
	 *
	 * @param array{field: string, instr: string, url: string, buf: string} $state
	 */
	private function page_ref( array $state ): string {
		$text = trim( (string) preg_replace( '/[\t ]+([0-9]+|[ivxlcdm]+)\s*$/i', '', $state['buf'] ) );
		if ( '' === $text || 1 === preg_match( '/^([0-9]+|[ivxlcdm]+)$/i', $text ) ) {
			return '';
		}
		$html = esc_html( str_replace( "\t", ' ', $text ) );
		if ( 1 === preg_match( '/^\s*PAGEREF\s+(\S+)/i', $state['instr'], $m ) ) {
			return '<a href="#' . esc_attr( $this->anchor . sanitize_html_class( $m[1] ) ) . '">' . $html . '</a>';
		}
		return $html;
	}

	private static function field_url( string $instr ): string {
		return 1 === preg_match( '/^\s*HYPERLINK\s+"([^"]+)"/i', $instr, $m ) ? $m[1] : '';
	}

	/**
	 * @param array{field: string, instr: string, url: string, buf: string} $state
	 */
	private function docx_run( DOMElement $r, array &$state ): string {
		$rpr  = self::wchild( $r, 'rPr' );
		$f    = [];
		$text = '';
		if ( null !== $rpr ) {
			foreach ( [
				'b' => 'bold',
				'i' => 'italic',
				'strike' => 'strike',
				'dstrike' => 'strike',
			] as $tag => $key ) {
				$el = self::wchild( $rpr, $tag );
				if ( null !== $el && ! in_array( self::wval( $el ), [ '0', 'false', 'off' ], true ) ) {
					$f[ $key ] = true;
				}
			}
			$u = self::wchild( $rpr, 'u' );
			if ( null !== $u && ! in_array( self::wval( $u ), [ 'none', '0', 'false' ], true ) ) {
				$f['underline'] = true;
			}
			$va = self::wval( self::wchild( $rpr, 'vertAlign' ) );
			if ( 'superscript' === $va ) {
				$f['sup'] = true;
			} elseif ( 'subscript' === $va ) {
				$f['sub'] = true;
			}
		}
		foreach ( $r->childNodes as $node ) {
			if ( ! $node instanceof DOMElement || self::W !== $node->namespaceURI ) {
				continue;
			}
			switch ( $node->localName ) {
				case 'fldChar':
					$kind = self::wval( $node, 'fldCharType' );
					if ( 'begin' === $kind ) {
						$state = [
							'field' => 'instr',
							'instr' => '',
							'url'   => '',
							'buf'   => '',
						];
					} elseif ( 'separate' === $kind ) {
						// A page number (PAGEREF, PAGE, NUMPAGES) means nothing in a web page: see page_ref().
						$state['field'] = 1 === preg_match( '/^\s*(PAGEREF|PAGE|NUMPAGES|SECTIONPAGES)\b/i', $state['instr'] ) ? 'skip' : 'result';
						$state['url']   = self::field_url( $state['instr'] );
					} elseif ( 'end' === $kind ) {
						if ( 'skip' === $state['field'] ) {
							$text .= $this->page_ref( $state );
						}
						$state = [
							'field' => '',
							'instr' => '',
							'url'   => '',
							'buf'   => '',
						];
					}
					break;
				case 'instrText':
					$state['instr'] .= $node->textContent;
					break;
				case 't':
					if ( 'skip' === $state['field'] ) {
						$state['buf'] .= $node->textContent;
					} else {
						$text .= esc_html( $node->textContent );
					}
					break;
				case 'tab':
					if ( 'skip' === $state['field'] ) {
						$state['buf'] .= "\t";
					} else {
						$text .= ' ';
					}
					break;
				case 'br':
					$text .= 'page' === self::wval( $node, 'type' ) ? '' : '<br>';
					break;
				case 'cr':
					$text .= '<br>';
					break;
				case 'noBreakHyphen':
					$text .= '&#8209;';
					break;
				case 'softHyphen':
					$text .= '&shy;';
					break;
			}
		}
		$html = self::format( $text, $f );
		return 'result' === $state['field'] && '' !== $state['url'] ? self::link( $html, $state['url'] ) : $html;
	}

	private function docx_table( DOMElement $tbl ): string {
		$rows   = [];
		$origin = []; // grid column => [row index, cell index] of the cell a vertical merge started in.
		foreach ( $tbl->childNodes as $tr ) {
			if ( ! $tr instanceof DOMElement || self::W !== $tr->namespaceURI || 'tr' !== $tr->localName ) {
				continue;
			}
			$r     = count( $rows );
			$cells = [];
			$col   = 0;
			foreach ( $tr->childNodes as $tc ) {
				if ( ! $tc instanceof DOMElement || self::W !== $tc->namespaceURI ) {
					continue;
				}
				if ( 'sdt' === $tc->localName ) {
					$content = self::wchild( $tc, 'sdtContent' );
					$tc      = null === $content ? null : self::wchild( $content, 'tc' );
				}
				if ( null === $tc || 'tc' !== $tc->localName ) {
					continue;
				}
				$tcpr  = self::wchild( $tc, 'tcPr' );
				$span  = null === $tcpr ? 1 : max( 1, (int) self::wval( self::wchild( $tcpr, 'gridSpan' ) ) );
				$vm    = null === $tcpr ? null : self::wchild( $tcpr, 'vMerge' );
				$merge = null === $vm ? '' : ( 'restart' === self::wval( $vm ) ? 'restart' : 'continue' );
				if ( 'continue' === $merge && isset( $origin[ $col ] ) ) {
					[ $or, $oc ] = $origin[ $col ];
					++$rows[ $or ][ $oc ][2];
					$col += $span;
					continue;
				}
				$saved            = $this->list_stack;
				$this->list_stack = [];
				$html             = $this->docx_blocks( $tc );
				$this->list_stack = $saved;
				// A cell of one paragraph needs no <p>.
				if ( 1 === substr_count( $html, '<p' ) && 1 === preg_match( '#^<p[^>]*>(.*)</p>$#s', $html, $m ) ) {
					$html = $m[1];
				}
				if ( 'restart' === $merge ) {
					$origin[ $col ] = [ $r, count( $cells ) ];
				} else {
					unset( $origin[ $col ] );
				}
				$cells[] = [ $html, $span, 1 ];
				$col    += $span;
			}
			$rows[] = $cells;
		}
		return self::table( $rows );
	}

	// ---- OpenDocument text (.odt) ------------------------------------------------------------------------------

	private function odt( string $path ): ?string {
		$zip = self::open_zip( $path );
		if ( null === $zip ) {
			return null;
		}
		$content = self::part( $zip, 'content.xml' );
		$styles  = self::part( $zip, 'styles.xml' );
		$zip->close();
		if ( null === $content ) {
			return null;
		}
		foreach ( [ $styles, $content ] as $doc ) {
			if ( null !== $doc ) {
				$this->odt_read_styles( $doc );
			}
		}
		$text = $content->getElementsByTagNameNS( self::OFF, 'text' )->item( 0 );
		return $text instanceof DOMElement ? $this->odt_blocks( $text ) : null;
	}

	private function odt_read_styles( DOMDocument $doc ): void {
		foreach ( $doc->getElementsByTagNameNS( self::STY, 'style' ) as $style ) {
			$props = null;
			foreach ( $style->childNodes as $child ) {
				if ( $child instanceof DOMElement && self::STY === $child->namespaceURI && 'text-properties' === $child->localName ) {
					$props = $child;
				}
			}
			if ( null === $props ) {
				continue;
			}
			$pos = $props->getAttributeNS( self::STY, 'text-position' );
			$f   = [
				'bold'      => 'bold' === $props->getAttributeNS( self::FO, 'font-weight' ) || (int) $props->getAttributeNS( self::FO, 'font-weight' ) >= 600,
				'italic'    => 'italic' === $props->getAttributeNS( self::FO, 'font-style' ),
				'underline' => ! in_array( $props->getAttributeNS( self::STY, 'text-underline-style' ), [ '', 'none' ], true ),
				'strike'    => ! in_array( $props->getAttributeNS( self::STY, 'text-line-through-style' ), [ '', 'none' ], true ),
				'sup'       => str_starts_with( $pos, 'super' ) || ( '' !== $pos && (float) $pos > 0 ),
				'sub'       => str_starts_with( $pos, 'sub' ) || ( '' !== $pos && (float) $pos < 0 ),
			];
			$this->odt_styles[ $style->getAttributeNS( self::STY, 'name' ) ] = $f;
		}
		foreach ( $doc->getElementsByTagNameNS( self::TEXT, 'list-style' ) as $list ) {
			$type = 'ul';
			foreach ( $list->childNodes as $level ) {
				if ( $level instanceof DOMElement ) {
					$type = 'list-level-style-number' === $level->localName ? 'ol' : 'ul';
					break;
				}
			}
			$this->odt_lists[ $list->getAttributeNS( self::STY, 'name' ) ] = $type;
		}
	}

	private function odt_blocks( DOMElement $parent ): string {
		$out = '';
		foreach ( $parent->childNodes as $node ) {
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			if ( self::TBL === $node->namespaceURI && 'table' === $node->localName ) {
				$out .= $this->odt_table( $node );
				continue;
			}
			if ( self::TEXT !== $node->namespaceURI ) {
				continue;
			}
			switch ( $node->localName ) {
				case 'h':
					$html = self::tidy( $this->odt_inline( $node ) );
					$out .= '' === $html ? '' : $this->heading( max( 1, (int) $node->getAttributeNS( self::TEXT, 'outline-level' ) ), $html );
					break;
				case 'p':
					$html = self::tidy( $this->odt_inline( $node ) );
					$out .= '' === $html ? '' : '<p>' . $html . '</p>';
					break;
				case 'list':
					$out .= $this->odt_list( $node, 'ul' );
					break;
				case 'section':
				case 'index-body':
				case 'table-of-content':
					$out .= $this->odt_blocks( $node );
					break;
			}
		}
		return $out;
	}

	private function odt_list( DOMElement $list, string $inherited ): string {
		$type  = $this->odt_lists[ $list->getAttributeNS( self::TEXT, 'style-name' ) ] ?? $inherited;
		$items = '';
		foreach ( $list->childNodes as $item ) {
			if ( ! $item instanceof DOMElement || self::TEXT !== $item->namespaceURI || ! in_array( $item->localName, [ 'list-item', 'list-header' ], true ) ) {
				continue;
			}
			$html = '';
			foreach ( $item->childNodes as $child ) {
				if ( ! $child instanceof DOMElement || self::TEXT !== $child->namespaceURI ) {
					continue;
				}
				if ( 'list' === $child->localName ) {
					$html .= $this->odt_list( $child, $type );
				} elseif ( in_array( $child->localName, [ 'p', 'h' ], true ) ) {
					$text  = self::tidy( $this->odt_inline( $child ) );
					$html .= ( '' !== $html && '' !== $text ? '<br>' : '' ) . $text;
				}
			}
			$items .= '' === $html ? '' : '<li>' . $html . '</li>';
		}
		return '' === $items ? '' : '<' . $type . '>' . $items . '</' . $type . '>';
	}

	private function odt_inline( DOMElement $parent ): string {
		$out = '';
		foreach ( $parent->childNodes as $node ) {
			if ( $node instanceof DOMText ) {
				$out .= esc_html( $node->data );
				continue;
			}
			if ( ! $node instanceof DOMElement ) {
				continue;
			}
			if ( self::TEXT !== $node->namespaceURI ) {
				continue; // Frames, drawings, annotations.
			}
			switch ( $node->localName ) {
				case 'span':
					$out .= self::format( $this->odt_inline( $node ), $this->odt_styles[ $node->getAttributeNS( self::TEXT, 'style-name' ) ] ?? [] );
					break;
				case 'a':
					$out .= self::link( $this->odt_inline( $node ), $node->getAttributeNS( self::XL, 'href' ) );
					break;
				case 's':
					$out .= str_repeat( ' ', max( 1, min( 100, (int) $node->getAttributeNS( self::TEXT, 'c' ) ) ) );
					break;
				case 'tab':
					$out .= ' ';
					break;
				case 'line-break':
					$out .= '<br>';
					break;
				case 'note':
				case 'bookmark':
				case 'bookmark-start':
				case 'bookmark-end':
				case 'soft-page-break':
				case 'tracked-changes':
					break;
				default:
					$out .= $this->odt_inline( $node ); // Fields (dates, page numbers...), meta, ruby text.
			}
		}
		return $out;
	}

	/**
	 * An OpenDocument table (text documents and spreadsheets).
	 */
	private function odt_table( DOMElement $table, bool $sheet = false ): string {
		$rows = [];
		foreach ( $this->odt_rows( $table ) as $row ) {
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
			$rep = max( 1, (int) $row->getAttributeNS( self::TBL, 'number-rows-repeated' ) );
			$cells = [];
			$width = 0;
			foreach ( $row->childNodes as $cell ) {
				if ( ! $cell instanceof DOMElement || self::TBL !== $cell->namespaceURI || $width >= self::MAX_COLS ) {
					continue;
				}
				$crep = max( 1, (int) $cell->getAttributeNS( self::TBL, 'number-columns-repeated' ) );
				if ( 'covered-table-cell' === $cell->localName ) {
					$width += $crep;
					continue;
				}
				if ( 'table-cell' !== $cell->localName ) {
					continue;
				}
				$html = $sheet ? $this->ods_cell( $cell ) : $this->odt_cell( $cell );
				$span = max( 1, (int) $cell->getAttributeNS( self::TBL, 'number-columns-spanned' ) );
				$down = max( 1, (int) $cell->getAttributeNS( self::TBL, 'number-rows-spanned' ) );
				for ( $i = 0; $i < min( $crep, self::MAX_COLS - $width ); $i++ ) {
					$cells[] = [ $html, $span, $down ];
				}
				$width += $crep;
			}
			// Spreadsheets repeat empty rows to the end of the sheet: only repeat rows with something in them.
			$rep = self::row_is_empty( $cells ) ? min( $rep, 1 ) : min( $rep, self::MAX_ROWS - count( $rows ) );
			for ( $i = 0; $i < $rep; $i++ ) {
				$rows[] = $cells;
			}
		}
		if ( $sheet ) {
			// Spreadsheet cells never span here, so drop empty trailing columns.
			$rows = self::grid_rows( array_map( static fn( $r ) => array_map( static fn( $c ) => $c[0], $r ), $rows ) );
		}
		return self::table( $rows, $sheet ? $table->getAttributeNS( self::TBL, 'name' ) : '' );
	}

	/**
	 * @return DOMElement[] The table's rows, from header-rows and row-groups too.
	 */
	private function odt_rows( DOMElement $parent ): array {
		$rows = [];
		foreach ( $parent->childNodes as $node ) {
			if ( ! $node instanceof DOMElement || self::TBL !== $node->namespaceURI ) {
				continue;
			}
			if ( 'table-row' === $node->localName ) {
				$rows[] = $node;
			} elseif ( in_array( $node->localName, [ 'table-header-rows', 'table-rows', 'table-row-group' ], true ) ) {
				$rows = array_merge( $rows, $this->odt_rows( $node ) );
			}
		}
		return $rows;
	}

	private function odt_cell( DOMElement $cell ): string {
		$html = $this->odt_blocks( $cell );
		if ( 1 === substr_count( $html, '<p>' ) && 1 === preg_match( '#^<p>(.*)</p>$#s', $html, $m ) ) {
			return $m[1];
		}
		return $html;
	}

	// ---- OpenDocument spreadsheet (.ods) -----------------------------------------------------------------------

	private function ods( string $path ): ?string {
		$zip = self::open_zip( $path );
		if ( null === $zip ) {
			return null;
		}
		$content = self::part( $zip, 'content.xml' );
		$styles  = self::part( $zip, 'styles.xml' );
		$zip->close();
		if ( null === $content ) {
			return null;
		}
		foreach ( [ $styles, $content ] as $doc ) {
			if ( null !== $doc ) {
				$this->odt_read_styles( $doc );
			}
		}
		$out = '';
		foreach ( $content->getElementsByTagNameNS( self::TBL, 'table' ) as $table ) {
			$out .= $this->odt_table( $table, true );
		}
		return $out;
	}

	private function ods_cell( DOMElement $cell ): string {
		$lines = [];
		foreach ( $cell->childNodes as $p ) {
			if ( $p instanceof DOMElement && self::TEXT === $p->namespaceURI && 'p' === $p->localName ) {
				$lines[] = trim( $this->odt_inline( $p ) );
			}
		}
		return implode( '<br>', $lines );
	}

	// ---- Excel (.xlsx) -----------------------------------------------------------------------------------------

	private function xlsx( string $path ): ?string {
		$zip = self::open_zip( $path );
		if ( null === $zip ) {
			return null;
		}
		$book = self::part( $zip, 'xl/workbook.xml' );
		if ( null === $book ) {
			$zip->close();
			return null;
		}
		$rels    = [];
		$relsdoc = self::part( $zip, 'xl/_rels/workbook.xml.rels' );
		if ( null !== $relsdoc ) {
			foreach ( $relsdoc->getElementsByTagNameNS( self::PR, 'Relationship' ) as $rel ) {
				$target                           = $rel->getAttribute( 'Target' );
				$rels[ $rel->getAttribute( 'Id' ) ] = str_starts_with( $target, '/' ) ? ltrim( $target, '/' ) : 'xl/' . $target;
			}
		}
		$strings = $this->xlsx_strings( self::part( $zip, 'xl/sharedStrings.xml' ) );
		$dates   = $this->xlsx_date_styles( self::part( $zip, 'xl/styles.xml' ) );

		$out = '';
		foreach ( $book->getElementsByTagNameNS( self::S, 'sheet' ) as $sheet ) {
			$state = $sheet->getAttribute( 'state' );
			$file  = $rels[ $sheet->getAttributeNS( self::R, 'id' ) ] ?? '';
			if ( 'hidden' === $state || 'veryHidden' === $state || '' === $file ) {
				continue;
			}
			$doc = self::part( $zip, $file );
			if ( null !== $doc ) {
				$out .= $this->xlsx_sheet( $doc, $strings, $dates, $sheet->getAttribute( 'name' ) );
			}
		}
		$zip->close();
		return $out;
	}

	/**
	 * @return string[] Shared strings, escaped.
	 */
	private function xlsx_strings( ?DOMDocument $doc ): array {
		$out = [];
		if ( null === $doc ) {
			return $out;
		}
		foreach ( $doc->getElementsByTagNameNS( self::S, 'si' ) as $si ) {
			$out[] = self::xlsx_text( $si );
		}
		return $out;
	}

	/**
	 * The text of a string item (plain or rich), escaped, without phonetic guides.
	 */
	private static function xlsx_text( DOMElement $si ): string {
		$text = '';
		foreach ( $si->getElementsByTagNameNS( self::S, 't' ) as $t ) {
			if ( $t->parentNode instanceof DOMElement && 'rPh' !== $t->parentNode->localName ) {
				$text .= $t->textContent;
			}
		}
		return nl2br( esc_html( $text ), false );
	}

	/**
	 * @return array<int, bool> Cell style indexes whose number format is a date or time.
	 */
	private function xlsx_date_styles( ?DOMDocument $doc ): array {
		$dates = [];
		if ( null === $doc ) {
			return $dates;
		}
		$custom = [];
		foreach ( $doc->getElementsByTagNameNS( self::S, 'numFmt' ) as $fmt ) {
			$code = (string) preg_replace( '/"[^"]*"|\[[^\]]*\]|\\\\./', '', $fmt->getAttribute( 'formatCode' ) );
			$custom[ (int) $fmt->getAttribute( 'numFmtId' ) ] = 1 === preg_match( '/[dmyhs]/i', $code );
		}
		$xfs = $doc->getElementsByTagNameNS( self::S, 'cellXfs' )->item( 0 );
		if ( ! $xfs instanceof DOMElement ) {
			return $dates;
		}
		$i = 0;
		foreach ( $xfs->getElementsByTagNameNS( self::S, 'xf' ) as $xf ) {
			$id          = (int) $xf->getAttribute( 'numFmtId' );
			$dates[ $i ] = ( $id >= 14 && $id <= 22 ) || ( $id >= 45 && $id <= 47 ) || ! empty( $custom[ $id ] );
			++$i;
		}
		return $dates;
	}

	/**
	 * @param string[]         $strings Shared strings.
	 * @param array<int, bool> $dates   Date styles.
	 */
	private function xlsx_sheet( DOMDocument $doc, array $strings, array $dates, string $name ): string {
		$grid = [];
		$r    = 0;
		foreach ( $doc->getElementsByTagNameNS( self::S, 'row' ) as $row ) {
			$r = $row->hasAttribute( 'r' ) ? (int) $row->getAttribute( 'r' ) - 1 : $r;
			if ( $r < 0 || $r >= self::MAX_ROWS ) {
				break;
			}
			$c = 0;
			foreach ( $row->getElementsByTagNameNS( self::S, 'c' ) as $cell ) {
				if ( 1 === preg_match( '/^([A-Z]+)\d+$/', $cell->getAttribute( 'r' ), $m ) ) {
					$c = 0;
					foreach ( str_split( $m[1] ) as $letter ) {
						$c = $c * 26 + ord( $letter ) - 64;
					}
					--$c;
				}
				if ( $c >= self::MAX_COLS ) {
					break;
				}
				$grid[ $r ][ $c ] = $this->xlsx_value( $cell, $strings, $dates );
				++$c;
			}
			++$r;
		}
		if ( [] === $grid ) {
			return '';
		}
		$rows = [];
		for ( $i = 0, $n = max( array_keys( $grid ) ); $i <= $n; $i++ ) {
			$row    = $grid[ $i ] ?? [];
			$rows[] = [] === $row ? [] : array_replace( array_fill( 0, max( array_keys( $row ) ) + 1, '' ), $row );
		}
		return self::table( self::grid_rows( $rows ), $name );
	}

	/**
	 * @param string[]         $strings Shared strings.
	 * @param array<int, bool> $dates   Date styles.
	 */
	private function xlsx_value( DOMElement $cell, array $strings, array $dates ): string {
		$type = $cell->getAttribute( 't' );
		$v    = $cell->getElementsByTagNameNS( self::S, 'v' )->item( 0 );
		$raw  = null === $v ? '' : (string) $v->textContent;
		switch ( $type ) {
			case 's':
				return $strings[ (int) $raw ] ?? '';
			case 'inlineStr':
				$is = $cell->getElementsByTagNameNS( self::S, 'is' )->item( 0 );
				return $is instanceof DOMElement ? self::xlsx_text( $is ) : '';
			case 'b':
				return '1' === $raw ? 'TRUE' : 'FALSE';
			case 'str':
			case 'e':
				return esc_html( $raw );
		}
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			return esc_html( $raw );
		}
		$style = (int) $cell->getAttribute( 's' );
		if ( ! empty( $dates[ $style ] ) && (float) $raw > 0 && (float) $raw < 2958466 ) {
			// Excel counts days from 1899-12-30 (day 25569 is 1970-01-01); the fraction is the time of day.
			$days    = (float) $raw;
			$seconds = (int) round( ( $days - floor( $days ) ) * 86400 );
			$format  = floor( $days ) > 0 ? (string) get_option( 'date_format', 'F j, Y' ) : '';
			if ( $seconds > 0 ) {
				$format = trim( $format . ' ' . (string) get_option( 'time_format', 'g:i a' ) );
			}
			return esc_html( gmdate( $format, (int) round( ( $days - 25569 ) * 86400 ) ) );
		}
		$num = (float) $raw;
		return esc_html( floor( $num ) === $num && abs( $num ) < 1e15 ? number_format( $num, 0, '.', '' ) : (string) round( $num, 10 ) );
	}

	// ---- CSV and plain text ------------------------------------------------------------------------------------

	private static function read_text( string $path ): ?string {
		$text = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local upload, read once and cached.
		if ( ! is_string( $text ) ) {
			return null;
		}
		$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = (string) mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		return str_replace( [ "\r\n", "\r" ], "\n", $text );
	}

	private function csv( string $path ): ?string {
		$text = self::read_text( $path );
		if ( null === $text ) {
			return null;
		}
		$first = strtok( $text, "\n" );
		$first = false === $first ? '' : $first;
		$delim = ',';
		foreach ( [ ';', "\t", '|' ] as $d ) {
			if ( substr_count( $first, $d ) > substr_count( $first, $delim ) ) {
				$delim = $d;
			}
		}
		$grid   = [];
		$handle = fopen( 'php://memory', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream for fgetcsv.
		if ( false === $handle ) {
			return null;
		}
		fwrite( $handle, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $handle );
		while ( count( $grid ) < self::MAX_ROWS ) {
			$row = fgetcsv( $handle, 0, $delim, '"', '' );
			if ( false === $row ) {
				break;
			}
			$grid[] = array_map( static fn( $v ) => nl2br( esc_html( (string) $v ), false ), array_slice( $row, 0, self::MAX_COLS ) );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return self::table( self::grid_rows( $grid ) );
	}

	private function txt( string $path ): ?string {
		$text = self::read_text( $path );
		if ( null === $text ) {
			return null;
		}
		$out = '';
		$paras = preg_split( '/\n\s*\n/', trim( $text ) );
		foreach ( false === $paras ? [] : $paras as $para ) {
			$out .= '<p>' . nl2br( esc_html( trim( $para ) ), false ) . '</p>';
		}
		return $out;
	}
}
