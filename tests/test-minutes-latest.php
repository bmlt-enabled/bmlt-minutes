<?php
/**
 * [bmlt_minutes_latest]: which minutes it picks, and how each kind of document is shown (BMLT_Minutes_Document_HTML
 * for Word, OpenDocument, Excel, CSV and text; PDF.js markup for PDFs).
 */

class Test_BMLT_Minutes_Latest extends WP_UnitTestCase {

	/** @var array<string, int> Committee term ids by slug. */
	private static $c = array();

	/** @var string Directory for the documents the tests write. */
	private static $dir = '';

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		$add                = static function ( string $name, string $slug, int $parent = 0 ): int {
			$t = wp_insert_term( $name, 'bmlt_committee', array( 'slug' => $slug, 'parent' => $parent ) );
			return (int) $t['term_id'];
		};
		self::$c['asc']     = $add( 'Area Service Committee', 'l-asc' );
		self::$c['policy']  = $add( 'Policy', 'l-policy', self::$c['asc'] );
		self::$c['pr']      = $add( 'Public Relations', 'l-pr' );
		self::$dir          = get_temp_dir() . 'bmlt-minutes-latest-' . wp_generate_password( 8, false );
		wp_mkdir_p( self::$dir );
	}

	public static function wpTearDownAfterClass() {
		foreach ( (array) glob( self::$dir . '/*' ) as $f ) {
			unlink( $f );
		}
		rmdir( self::$dir );
	}

	public function set_up() {
		parent::set_up();
		wp_dequeue_script( 'bmlt-minutes-latest' );
		BMLT_Minutes::frontend_assets();
	}

	/**
	 * A published minutes post.
	 *
	 * @param array $extra meeting (Y-m-d), url, file (a path to upload as its document), content, password.
	 */
	private function minutes( string $title, string $posted, array $committees, array $extra = array() ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'     => 'bmlt_minutes',
				'post_title'    => $title,
				'post_status'   => 'publish',
				'post_date'     => $posted,
				'post_content'  => $extra['content'] ?? '',
				'post_password' => $extra['password'] ?? '',
			)
		);
		wp_set_object_terms( $id, array_map( static fn( $s ) => self::$c[ $s ], $committees ), 'bmlt_committee' );
		if ( isset( $extra['meeting'] ) ) {
			update_post_meta( $id, '_bmlt_minutes_date', $extra['meeting'] );
		}
		if ( isset( $extra['file'] ) ) {
			$att = self::factory()->attachment->create_upload_object( $extra['file'], $id );
			update_post_meta( $id, '_bmlt_minutes_attachment_id', $att );
		} elseif ( isset( $extra['url'] ) ) {
			update_post_meta( $id, '_bmlt_minutes_url', $extra['url'] );
		}
		return $id;
	}

	private function render( string $atts = '' ): string {
		return do_shortcode( '[bmlt_minutes_latest' . ( '' !== $atts ? ' ' . $atts : '' ) . ']' );
	}

	/**
	 * A zip package (.docx, .xlsx, .odt, .ods) from its parts.
	 *
	 * @param array<string, string> $parts Path in the package => content.
	 */
	private static function package( string $name, array $parts ): string {
		$path = self::$dir . '/' . $name;
		$zip  = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $parts as $part => $content ) {
			$zip->addFromString( $part, $content );
		}
		$zip->close();
		return $path;
	}

	private static function file( string $name, string $content ): string {
		$path = self::$dir . '/' . $name;
		file_put_contents( $path, $content );
		return $path;
	}

	/** A one-page PDF with a line of text. */
	private static function pdf(): string {
		return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj 4 0 obj<</Length 44>>stream\nBT /F1 18 Tf 72 720 Td (Minutes) Tj ET\nendstream endobj 5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";
	}

	private static function docx( string $body ): string {
		$w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
		return self::package(
			'minutes-' . wp_generate_password( 6, false ) . '.docx',
			array(
				'[Content_Types].xml'          => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
				'word/document.xml'            => '<?xml version="1.0" encoding="UTF-8"?><w:document ' . $w . '><w:body>' . $body . '<w:sectPr/></w:body></w:document>',
				'word/styles.xml'              => '<?xml version="1.0"?><w:styles ' . $w . '>'
					. '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/></w:style>'
					. '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/></w:style>'
					. '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/></w:style>'
					. '<w:style w:type="paragraph" w:styleId="MyHeading"><w:name w:val="My Heading"/><w:basedOn w:val="Heading2"/></w:style>'
					. '</w:styles>',
				'word/numbering.xml'           => '<?xml version="1.0"?><w:numbering ' . $w . '>'
					. '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="•"/></w:lvl><w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%2."/></w:lvl></w:abstractNum>'
					. '<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="lowerLetter"/><w:lvlText w:val="%1)"/></w:lvl><w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="lowerRoman"/><w:lvlText w:val="%2."/></w:lvl></w:abstractNum>'
					. '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>'
					. '</w:numbering>',
				'word/_rels/document.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
					. '<Relationship Id="rId9" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://na.org/" TargetMode="External"/>'
					. '<Relationship Id="rId10" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="javascript:alert(1)" TargetMode="External"/>'
					. '</Relationships>',
			)
		);
	}

	private static function p( string $text, string $props = '', string $run_props = '' ): string {
		return '<w:p>' . ( '' !== $props ? '<w:pPr>' . $props . '</w:pPr>' : '' ) . '<w:r>' . ( '' !== $run_props ? '<w:rPr>' . $run_props . '</w:rPr>' : '' ) . '<w:t xml:space="preserve">' . htmlspecialchars( $text, ENT_XML1 ) . '</w:t></w:r></w:p>';
	}

	private static function num( int $id, int $level ): string {
		return '<w:numPr><w:ilvl w:val="' . $level . '"/><w:numId w:val="' . $id . '"/></w:numPr>';
	}

	// ---- Which minutes ---------------------------------------------------------------------------------------

	public function test_the_newest_minutes_of_the_committee_and_its_sub_committees() {
		$this->minutes( 'Policy 2025', '2025-03-01 12:00:00', array( 'policy' ), array( 'content' => 'Old policy.', 'meeting' => '2026-12-01' ) );
		$this->minutes( 'Policy 2026', '2026-03-01 12:00:00', array( 'policy' ), array( 'content' => 'New policy.', 'meeting' => '2026-02-01' ) );
		$this->minutes( 'PR minutes', '2026-05-01 12:00:00', array( 'pr' ), array( 'content' => 'PR text.' ) );

		$html = $this->render( 'committee="l-policy"' );
		$this->assertStringContainsString( 'Policy 2026', $html );
		$this->assertStringContainsString( 'New policy.', $html );
		$this->assertStringNotContainsString( 'Old policy.', $html );
		$this->assertStringContainsString( 'Policy 2026', $this->render( 'committee="l-asc"' ), 'sub-committees are included' );
		$this->assertStringContainsString( 'PR minutes', $this->render(), 'all committees by default' );
		$this->assertStringContainsString( 'Policy 2025', $this->render( 'committee="l-policy" date="meeting"' ), 'by meeting date' );
		$this->assertStringContainsString( 'No minutes published yet.', $this->render( 'committee="no-such-committee"' ) );
	}

	public function test_title_date_download_and_heading_level() {
		update_option( 'date_format', 'F j, Y' );
		$this->minutes( 'June minutes', '2026-06-14 12:00:00', array( 'pr' ), array( 'url' => 'https://example.org/june.pdf', 'meeting' => '2026-06-14' ) );
		$html = $this->render( 'committee="l-pr"' );
		$this->assertStringContainsString( '<h2 class="bmlt-minutes-latest__title">June minutes</h2>', $html );
		$this->assertStringContainsString( '<span class="bmlt-minutes-latest__date">June 14, 2026</span>', $html );
		$this->assertStringContainsString( '<a class="bmlt-minutes-latest__download" href="https://example.org/june.pdf" download>Download (PDF)</a>', $html );

		$this->assertStringContainsString( '<h4 class="bmlt-minutes-latest__title">', $this->render( 'committee="l-pr" heading_level="4"' ) );
		$bare = $this->render( 'committee="l-pr" show_title="false" show_date="false" download="false"' );
		$this->assertStringNotContainsString( 'bmlt-minutes-latest__title', $bare );
		$this->assertStringNotContainsString( 'bmlt-minutes-latest__meta', $bare );
	}

	// ---- PDFs ------------------------------------------------------------------------------------------------

	public function test_a_pdf_is_shown_with_pdf_js_and_the_browser_viewer_without_javascript() {
		$this->minutes( 'Policy', '2026-06-14 12:00:00', array( 'policy' ), array( 'file' => self::file( 'minutes.pdf', self::pdf() ) ) );
		$html = $this->render( 'committee="l-policy"' );
		$this->assertMatchesRegularExpression( '#<div class="bmlt-minutes-latest__pdf" data-bmlt-minutes-pdf="http[^"]+/minutes[^"]*\.pdf" data-bmlt-minutes-pdfjs="http[^"]+/pdfjs/"#', $html );
		$this->assertStringContainsString( 'data-bmlt-minutes-page="Page %1$s of %2$s"', $html );
		$this->assertMatchesRegularExpression( '#<noscript><object class="bmlt-minutes-latest__embed" data="[^"]+\.pdf" type="application/pdf"#', $html );
		$this->assertTrue( wp_script_is( 'bmlt-minutes-latest', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'bmlt-minutes', 'enqueued' ) );
		foreach ( array( 'pdf.min.js', 'pdf.worker.min.js', 'LICENSE', 'cmaps/UniJIS-UTF16-H.bcmap', 'standard_fonts/LiberationSans-Regular.ttf', 'wasm/openjpeg.wasm' ) as $f ) {
			$this->assertFileExists( dirname( BMLT_MINUTES_FILE ) . '/pdfjs/' . $f );
		}
	}

	// ---- Word ------------------------------------------------------------------------------------------------

	public function test_word_documents_become_html() {
		$body = self::p( 'Area Policy', '<w:pStyle w:val="Title"/>' )
			. '<w:p><w:pPr><w:pStyle w:val="Heading1"/>' . self::num( 2, 0 ) . '</w:pPr><w:bookmarkStart w:id="1" w:name="_Toc1"/><w:r><w:t>Purpose</w:t></w:r><w:bookmarkEnd w:id="1"/></w:p>'
			. '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">Bold </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>run</w:t></w:r><w:r><w:t xml:space="preserve"> and </w:t></w:r><w:r><w:rPr><w:i/><w:u w:val="single"/></w:rPr><w:t>styled</w:t></w:r><w:r><w:rPr><w:strike/></w:rPr><w:t>gone</w:t></w:r><w:r><w:rPr><w:vertAlign w:val="superscript"/></w:rPr><w:t>2</w:t></w:r></w:p>'
			. self::p( 'Centred', '<w:jc w:val="center"/>' )
			. self::p( 'First bullet', self::num( 1, 0 ) )
			. self::p( 'Nested number', self::num( 1, 1 ) )
			. self::p( 'Second nested', self::num( 1, 1 ) )
			. self::p( 'Second bullet', self::num( 1, 0 ) )
			. self::p( 'Based on heading 2', '<w:pStyle w:val="MyHeading"/>' )
			. '<w:p><w:pPr><w:pStyle w:val="Heading1"/>' . self::num( 2, 0 ) . '</w:pPr><w:r><w:t>Finances</w:t></w:r></w:p>'
			. '<w:p><w:hyperlink r:id="rId9"><w:r><w:t>NA World Services</w:t></w:r></w:hyperlink><w:r><w:t xml:space="preserve"> </w:t></w:r><w:hyperlink r:id="rId10"><w:r><w:t>bad link</w:t></w:r></w:hyperlink></w:p>'
			// A Word field link, and a table of contents entry with its page number field.
			. '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> HYPERLINK "https://example.org/report" </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>Report</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>'
			. '<w:p><w:hyperlink w:anchor="_Toc1"><w:r><w:t>Purpose</w:t></w:r><w:r><w:tab/></w:r><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText> PAGEREF _Toc1 \h </w:instrText></w:r><w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>7</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:hyperlink></w:p>'
			// Google Docs' kind: the heading inside the page number field.
			. '<w:p><w:r><w:fldChar w:fldCharType="begin"/><w:instrText> PAGEREF _Toc1 \h </w:instrText><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>Purpose again</w:t><w:tab/><w:t>12</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>'
			. '<w:tbl><w:tr><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr>' . self::p( 'Wide' ) . '</w:tc></w:tr>'
			. '<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr>' . self::p( 'Tall' ) . '</w:tc><w:tc>' . self::p( 'B2' ) . '</w:tc></w:tr>'
			. '<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc><w:tc>' . self::p( 'B3' ) . '</w:tc></w:tr></w:tbl>'
			. self::p( '<script>alert(1)</script> & "quotes"' );
		$this->minutes( 'Policy', '2026-06-14 12:00:00', array( 'policy' ), array( 'file' => self::docx( $body ) ) );
		$html = $this->render( 'committee="l-policy"' );
		$doc  = substr( $html, (int) strpos( $html, '<div class="bmlt-minutes-latest__document bmlt-minutes-doc bmlt-minutes-doc--docx">' ) );

		$this->assertStringContainsString( '<h3>Area Policy</h3>', $doc, 'Title: one level below the minutes\' title' );
		$this->assertMatchesRegularExpression( '#<h3 id="bmlt-minutes-doc-[0-9a-f]{6}-_Toc1">a\) Purpose</h3>#', $doc, 'a numbered heading keeps its label' );
		$this->assertStringContainsString( '<h4>Based on heading 2</h4>', $doc );
		$this->assertStringContainsString( '<h3>b) Finances</h3>', $doc, 'counted as Word counts' );
		$this->assertStringContainsString( '<p><strong>Bold run</strong> and <em><u>styled</u></em><s>gone</s><sup>2</sup></p>', $doc );
		$this->assertStringContainsString( '<p class="bmlt-minutes-doc__center">Centred</p>', $doc );
		$this->assertStringContainsString( '<ul><li>First bullet<ol><li>Nested number</li><li>Second nested</li></ol></li><li>Second bullet</li></ul>', $doc );
		$this->assertStringContainsString( '<a href="https://na.org/">NA World Services</a> bad link', $doc, 'javascript: links are dropped' );
		$this->assertStringContainsString( '<a href="https://example.org/report">Report</a>', $doc );
		$this->assertMatchesRegularExpression( '#<p><a href="\#bmlt-minutes-doc-[0-9a-f]{6}-_Toc1">Purpose</a></p>#', $doc, 'contents entry: linked, page number gone' );
		$this->assertMatchesRegularExpression( '#<p><a href="\#bmlt-minutes-doc-[0-9a-f]{6}-_Toc1">Purpose again</a></p>#', $doc );
		$this->assertStringContainsString( '<tr><td colspan="2">Wide</td></tr><tr><td rowspan="2">Tall</td><td>B2</td></tr><tr><td>B3</td></tr>', $doc );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;quotes&quot;', $doc );
		$this->assertStringNotContainsString( '<script', $doc );
		$this->assertStringNotContainsString( 'javascript:', $doc );
	}

	public function test_a_list_that_carries_on_after_a_paragraph_keeps_counting() {
		$body = self::p( 'One', self::num( 2, 0 ) ) . self::p( 'Two', self::num( 2, 0 ) ) . self::p( 'Between' ) . self::p( 'Three', self::num( 2, 0 ) )
			. self::p( 'Deep', self::num( 2, 1 ) );
		$html = (string) BMLT_Minutes_Document_HTML::convert( self::docx( $body ), 'docx' );
		$this->assertSame( '<ol type="a"><li>One</li><li>Two</li></ol><p>Between</p><ol type="a" start="3"><li>Three<ol type="i"><li>Deep</li></ol></li></ol>', $html );
		// A list starting at a deeper level: unmarked items hold it.
		$html = (string) BMLT_Minutes_Document_HTML::convert( self::docx( self::p( 'Deep first', self::num( 1, 1 ) ) ), 'docx' );
		$this->assertSame( '<ul class="bmlt-minutes-doc__nest"><li class="bmlt-minutes-doc__nest"><ol><li>Deep first</li></ol></li></ul>', $html );
	}

	public function test_conversions_are_cached_until_the_file_changes() {
		$path = self::docx( self::p( 'Version one' ) );
		$this->assertStringContainsString( 'Version one', (string) BMLT_Minutes_Document_HTML::cached( $path, 'docx', 3 ) );
		// The same file: from the cache (a bad file at the same path, same time and size, is not read again).
		$this->assertStringContainsString( 'Version one', (string) BMLT_Minutes_Document_HTML::cached( $path, 'docx', 3 ) );
		$copy = self::docx( self::p( 'Version two' ) );
		rename( $copy, $path );
		touch( $path, time() + 60 );
		clearstatcache();
		$this->assertStringContainsString( 'Version two', (string) BMLT_Minutes_Document_HTML::cached( $path, 'docx', 3 ) );
	}

	public function test_broken_and_hostile_files_are_offered_for_download() {
		$this->assertNull( BMLT_Minutes_Document_HTML::convert( self::file( 'broken.docx', 'not a zip' ), 'docx' ) );
		$this->assertNull( BMLT_Minutes_Document_HTML::convert( self::package( 'empty.docx', array( 'word/other.xml' => '<x/>' ) ), 'docx' ) );
		// An external entity: never read.
		$secret = self::file( 'secret.txt', 'TOP-SECRET' );
		$xxe    = self::package(
			'xxe.docx',
			array( 'word/document.xml' => '<?xml version="1.0"?><!DOCTYPE d [<!ENTITY x SYSTEM "file://' . $secret . '">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>A&x;B</w:t></w:r></w:p></w:body></w:document>' )
		);
		$this->assertStringNotContainsString( 'TOP-SECRET', (string) BMLT_Minutes_Document_HTML::convert( $xxe, 'docx' ) );

		$this->minutes( 'Broken', '2026-06-14 12:00:00', array( 'policy' ), array( 'file' => self::file( 'broken-minutes.docx', 'not a zip' ) ) );
		$html = $this->render( 'committee="l-policy"' );
		$this->assertStringContainsString( 'This document cannot be shown in the page. Download it to read it.', $html );
		$this->assertMatchesRegularExpression( '#<a class="bmlt-minutes-latest__download" href="[^"]+broken-minutes[^"]*\.docx" download>Download \(DOCX\)</a>#', $html );
	}

	// ---- OpenDocument, Excel, CSV, text -----------------------------------------------------------------------

	public function test_opendocument_text() {
		$ns   = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:xlink="http://www.w3.org/1999/xlink"';
		$path = self::package(
			'minutes.odt',
			array(
				'content.xml' => '<?xml version="1.0"?><office:document-content ' . $ns . '><office:automatic-styles>'
					. '<style:style style:name="T1" style:family="text"><style:text-properties fo:font-weight="bold"/></style:style>'
					. '<text:list-style style:name="L1"><text:list-level-style-number text:level="1"/></text:list-style>'
					. '</office:automatic-styles><office:body><office:text>'
					. '<text:h text:outline-level="1">Minutes</text:h>'
					. '<text:p>Opened at <text:span text:style-name="T1">7pm</text:span>.<text:s text:c="2"/>See <text:a xlink:href="https://na.org/">NA</text:a>.<text:line-break/>Next line</text:p>'
					. '<text:list text:style-name="L1"><text:list-item><text:p>Motion one</text:p></text:list-item><text:list-item><text:p>Motion two</text:p></text:list-item></text:list>'
					. '<table:table><table:table-row><table:table-cell table:number-columns-spanned="2"><text:p>Wide</text:p></table:table-cell><table:covered-table-cell/></table:table-row>'
					. '<table:table-row><table:table-cell><text:p>A</text:p></table:table-cell><table:table-cell><text:p>B</text:p></table:table-cell></table:table-row></table:table>'
					. '</office:text></office:body></office:document-content>',
			)
		);
		$html = (string) BMLT_Minutes_Document_HTML::convert( $path, 'odt', 3 );
		$this->assertStringContainsString( '<h3>Minutes</h3>', $html );
		$this->assertStringContainsString( '<p>Opened at <strong>7pm</strong>.  See <a href="https://na.org/">NA</a>.<br>Next line</p>', $html );
		$this->assertStringContainsString( '<ol><li>Motion one</li><li>Motion two</li></ol>', $html );
		$this->assertStringContainsString( '<tr><td colspan="2">Wide</td></tr><tr><td>A</td><td>B</td></tr>', $html );
	}

	public function test_excel_and_opendocument_spreadsheets() {
		$s    = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
		$path = self::package(
			'treasurer.xlsx',
			array(
				'xl/workbook.xml'            => '<?xml version="1.0"?><workbook ' . $s . '><sheets><sheet name="Report" sheetId="1" r:id="rId1"/><sheet name="Secret" sheetId="2" state="hidden" r:id="rId2"/></sheets></workbook>',
				'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="worksheets/sheet2.xml"/></Relationships>',
				'xl/sharedStrings.xml'       => '<?xml version="1.0"?><sst ' . $s . '><si><t>Item</t></si><si><t>Amount</t></si><si><r><t>Rent &amp; </t></r><r><t>&lt;b&gt;</t></r></si><si><t>Date</t></si></sst>',
				'xl/styles.xml'              => '<?xml version="1.0"?><styleSheet ' . $s . '><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>',
				'xl/worksheets/sheet1.xml'   => '<?xml version="1.0"?><worksheet ' . $s . '><sheetData>'
					. '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>3</v></c></row>'
					. '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>125.5</v></c><c r="C2" s="1"><v>46174</v></c></row>'
					. '<row r="4"><c r="B4"><v>0.30000000000000004</v></c><c r="D4" t="inlineStr"><is><t>note</t></is></c></row>'
					. '</sheetData></worksheet>',
				'xl/worksheets/sheet2.xml'   => '<?xml version="1.0"?><worksheet ' . $s . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>hidden sheet</t></is></c></row></sheetData></worksheet>',
			)
		);
		update_option( 'date_format', 'Y-m-d' );
		$html = (string) BMLT_Minutes_Document_HTML::convert( $path, 'xlsx' );
		$this->assertStringContainsString( '<caption>Report</caption>', $html );
		$this->assertStringContainsString( '<tr><td>Item</td><td>Amount</td><td>Date</td><td></td></tr>', $html );
		$this->assertStringContainsString( '<tr><td>Rent &amp; &lt;b&gt;</td><td>125.5</td><td>2026-06-01</td><td></td></tr>', $html );
		$this->assertStringContainsString( '<tr><td></td><td></td><td></td><td></td></tr><tr><td></td><td>0.3</td><td></td><td>note</td></tr>', $html );
		$this->assertStringNotContainsString( 'hidden sheet', $html );

		$o    = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"';
		$path = self::package(
			'treasurer.ods',
			array(
				'content.xml' => '<?xml version="1.0"?><office:document-content ' . $o . '><office:body><office:spreadsheet><table:table table:name="Budget">'
					. '<table:table-row><table:table-cell><text:p>Rent</text:p></table:table-cell><table:table-cell office:value-type="float"><text:p>$100.00</text:p></table:table-cell><table:table-cell table:number-columns-repeated="16380"/></table:table-row>'
					. '<table:table-row table:number-rows-repeated="1048574"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
					. '</table:table></office:spreadsheet></office:body></office:document-content>',
			)
		);
		$this->assertSame( '<div class="bmlt-minutes-doc__table"><table><caption>Budget</caption><tbody><tr><td>Rent</td><td>$100.00</td></tr></tbody></table></div>', BMLT_Minutes_Document_HTML::convert( $path, 'ods' ) );
	}

	public function test_csv_and_text() {
		$csv = self::file( 'motions.csv', "Motion;Result\n\"Buy \"\"coffee\"\"\";Passed\n<b>x</b>;Failed\n" );
		$this->assertSame( '<div class="bmlt-minutes-doc__table"><table><tbody><tr><td>Motion</td><td>Result</td></tr><tr><td>Buy &quot;coffee&quot;</td><td>Passed</td></tr><tr><td>&lt;b&gt;x&lt;/b&gt;</td><td>Failed</td></tr></tbody></table></div>', BMLT_Minutes_Document_HTML::convert( $csv, 'csv' ) );

		$txt = self::file( 'minutes.txt', "\xEF\xBB\xBFOpened 7pm.\r\nQuorum met.\r\n\r\nClosed \x96 8pm." );
		$this->assertSame( '<p>Opened 7pm.<br>' . "\n" . 'Quorum met.</p><p>Closed – 8pm.</p>', BMLT_Minutes_Document_HTML::convert( $txt, 'txt' ), 'Windows-1252 text is read as such' );
		$this->assertNull( BMLT_Minutes_Document_HTML::convert( self::file( 'empty.txt', "  \n" ), 'txt' ) );
	}

	// ---- Everything else -------------------------------------------------------------------------------------

	public function test_minutes_written_in_the_post_show_their_text() {
		$this->minutes( 'Notes', '2026-06-14 12:00:00', array( 'pr' ), array( 'content' => "<!-- wp:paragraph -->\n<p>Typed minutes.</p>\n<!-- /wp:paragraph -->" ) );
		$html = $this->render( 'committee="l-pr"' );
		$this->assertStringContainsString( '<div class="bmlt-minutes-latest__text">', $html );
		$this->assertMatchesRegularExpression( '#<p( class="wp-block-paragraph")?>Typed minutes.</p>#', $html );
		$this->assertStringNotContainsString( 'no document', $html );
	}

	public function test_web_pages_and_old_formats_get_a_link() {
		$this->minutes( 'Google', '2026-06-14 12:00:00', array( 'pr' ), array( 'url' => 'https://docs.google.com/document/d/abc/edit' ) );
		$html = $this->render( 'committee="l-pr"' );
		$this->assertStringContainsString( 'This document is on another website.', $html );
		$this->assertStringContainsString( '<a class="bmlt-minutes-latest__download" href="https://docs.google.com/document/d/abc/edit" target="_blank" rel="noopener">Open the document</a>', $html );

		$this->minutes( 'Old Word', '2026-06-15 12:00:00', array( 'pr' ), array( 'url' => 'https://example.org/old.doc' ) );
		$this->assertStringContainsString( 'This document cannot be shown in the page.', $this->render( 'committee="l-pr"' ) );

		$this->minutes( 'Nothing', '2026-06-16 12:00:00', array( 'pr' ) );
		$this->assertStringContainsString( 'These minutes have no document.', $this->render( 'committee="l-pr"' ) );
	}

	public function test_password_protected_minutes_ask_for_the_password_and_reveal_nothing() {
		$this->minutes(
			'Unredacted',
			'2026-06-14 12:00:00',
			array( 'policy' ),
			array(
				'password' => 'members',
				'content'  => 'Private text.',
				'file'     => self::docx( self::p( 'Private document' ) ),
			)
		);
		$html = $this->render( 'committee="l-policy"' );
		$this->assertStringContainsString( 'Unredacted', $html );
		$this->assertStringContainsString( 'post_password', $html, 'WordPress\'s password form' );
		$this->assertStringNotContainsString( 'Private', $html );
		$this->assertStringNotContainsString( '.docx', $html );
		$this->assertStringNotContainsString( 'bmlt-minutes-latest__download', $html );
	}

	public function test_minutes_that_show_themselves_do_not_loop() {
		$this->minutes( 'Loop', '2026-06-14 12:00:00', array( 'pr' ), array( 'content' => 'Before [bmlt_minutes_latest committee="l-pr"] after' ) );
		$html = $this->render( 'committee="l-pr"' );
		$this->assertSame( 1, substr_count( $html, 'bmlt-minutes-latest__title' ) );
		$this->assertStringContainsString( 'Before  after', $html );
	}

	public function test_uninstall_removes_the_converted_documents() {
		$path = self::docx( self::p( 'Kept for a while' ) );
		BMLT_Minutes_Document_HTML::cached( $path, 'docx', 3 );
		set_transient( 'other_plugin_cache', 'x' );
		global $wpdb;
		$count = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%bmlt\\_minutes\\_doc\\_%'" );
		$this->assertSame( 2, $count(), 'the HTML and its expiry time' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'bmlt-minutes/minutes.php' );
		}
		require dirname( BMLT_MINUTES_FILE ) . '/uninstall.php';
		$this->assertSame( 0, $count() );
		$this->assertSame( 'x', get_transient( 'other_plugin_cache' ) );
		BMLT_Minutes::activate(); // uninstall.php removed the role and capabilities.
	}

	public function test_heading_level_out_of_range_is_the_default() {
		$this->minutes( 'June', '2026-06-14 12:00:00', array( 'pr' ), array( 'content' => 'Text.' ) );
		$this->assertStringContainsString( '<h2 class="bmlt-minutes-latest__title">June</h2>', $this->render( 'committee="l-pr" heading_level="9"' ) );
	}
}
