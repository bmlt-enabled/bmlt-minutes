<?php
/**
 * Tests for the [bmlt_minutes_by_month] shortcode.
 */

class Test_BMLT_Minutes_By_Month extends WP_UnitTestCase {

	/** @var array<string, int> Committee term ids by slug. */
	private static $c = [];

	public function set_up() {
		parent::set_up();
		// Same reset as test-minutes.php: tear_down() truncates terms between classes.
		wp_cache_flush();
		BMLT_Minutes::activate();
		delete_option( 'bmlt_minutes_sort_order' );
		wp_dequeue_script( 'bmlt-minutes-by-month' );
		// Created per test so they roll back with it; activate() already seeds some of these names.
		$add            = static function ( string $name, string $slug, int $parent = 0 ): int {
			$existing = term_exists( $name, BMLT_Minutes::TAX_COMMITTEE, $parent );
			if ( is_array( $existing ) ) {
				wp_update_term( (int) $existing['term_id'], BMLT_Minutes::TAX_COMMITTEE, [ 'slug' => $slug ] );
				return (int) $existing['term_id'];
			}
			$term = wp_insert_term( $name, BMLT_Minutes::TAX_COMMITTEE, [ 'slug' => $slug, 'parent' => $parent ] );
			return (int) $term['term_id'];
		};
		self::$c        = [];
		self::$c['asc'] = $add( 'Area Service Committee', 'asc' );
		self::$c['richmond'] = $add( 'Richmond Area', 'richmond', self::$c['asc'] );
		self::$c['ndana']    = $add( 'New Dominion Area', 'ndana', self::$c['asc'] );
		self::$c['rich-pr']  = $add( 'Public Relations', 'rich-pr', self::$c['richmond'] );
		self::$c['ndana-pr'] = $add( 'Public Relations', 'ndana-pr', self::$c['ndana'] );
		self::$c['policy']   = $add( 'Policy', 'policy' );
	}

	/**
	 * A published minutes post.
	 *
	 * @param string   $title      Title.
	 * @param string   $posted     Post date (Y-m-d H:i:s).
	 * @param string[] $committees Committee slugs.
	 * @param array    $extra      meeting (Y-m-d), url, status, password.
	 */
	private function minutes( string $title, string $posted, array $committees, array $extra = array() ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'     => 'bmlt_minutes',
				'post_title'    => $title,
				'post_status'   => $extra['status'] ?? 'publish',
				'post_date'     => $posted,
				'post_password' => $extra['password'] ?? '',
			)
		);
		wp_set_object_terms( $id, array_map( static fn( $s ) => self::$c[ $s ], $committees ), 'bmlt_committee' );
		if ( isset( $extra['meeting'] ) ) {
			update_post_meta( $id, '_bmlt_minutes_date', $extra['meeting'] );
		}
		update_post_meta( $id, '_bmlt_minutes_url', $extra['url'] ?? 'https://example.org/' . sanitize_title( $title ) . '.pdf' );
		return $id;
	}

	private function render( string $atts = '' ): string {
		return do_shortcode( '[bmlt_minutes_by_month' . ( '' !== $atts ? ' ' . $atts : '' ) . ']' );
	}

	private function xpath( string $html ): DOMXPath {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		return new DOMXPath( $doc );
	}

	/**
	 * Committee → year → month → document titles (File blocks and list links, in page order), read back from the HTML.
	 *
	 * @return array<string, array<string, array<string, string[]>>>
	 */
	private function outline( string $html ): array {
		$xp  = $this->xpath( $html );
		$out = array();
		foreach ( $xp->query( '//section[contains(@class,"bmlt-minutes-archive__committee")]' ) as $section ) {
			$committee = trim( $xp->query( './*[contains(@class,"bmlt-minutes-archive__committee-title")]', $section )->item( 0 )->textContent );
			foreach ( $xp->query( './div[@class="bmlt-minutes-archive__year"]', $section ) as $year_div ) {
				$year = trim( $xp->query( './*[@class="bmlt-minutes-archive__year-title"]', $year_div )->item( 0 )->textContent );
				foreach ( $xp->query( './details[contains(@class,"bmlt-minutes-archive__month")]', $year_div ) as $details ) {
					$month = trim( $xp->query( './summary/*[@class="bmlt-minutes-archive__month-title"]', $details )->item( 0 )->textContent );
					$out[ $committee ][ $year ][ $month ] = array();
					foreach ( $xp->query( './/span[@class="bmlt-minutes__title"] | .//div[contains(@class,"wp-block-file")]/a[not(@download)]', $details ) as $title ) {
						$out[ $committee ][ $year ][ $month ][] = trim( $title->textContent );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * "Year-Month" of each month section that starts open.
	 *
	 * @return string[]
	 */
	private function open_months( string $html ): array {
		$xp  = $this->xpath( $html );
		$out = array();
		foreach ( $xp->query( '//details[contains(@class,"bmlt-minutes-archive__month")][@open]' ) as $details ) {
			$year  = trim( $xp->query( './ancestor::div[@class="bmlt-minutes-archive__year"]/*[@class="bmlt-minutes-archive__year-title"]', $details )->item( 0 )->textContent );
			$out[] = $year . '-' . trim( $xp->query( './summary/*[@class="bmlt-minutes-archive__month-title"]', $details )->item( 0 )->textContent );
		}
		return $out;
	}

	public function test_groups_by_committee_then_year_and_month_posted_newest_first() {
		$this->minutes( 'Richmond Sep', '2026-09-14 12:00:00', array( 'richmond' ) );
		$this->minutes( 'Richmond Aug', '2026-08-09 12:00:00', array( 'richmond' ) );
		$this->minutes( 'Richmond Aug report', '2026-08-09 13:00:00', array( 'richmond' ) );
		$this->minutes( 'Richmond 2025', '2025-10-12 12:00:00', array( 'richmond' ) );
		$this->minutes( 'Policy Jan', '2026-01-11 12:00:00', array( 'policy' ) );

		$this->assertSame(
			array(
				'Policy'        => array( '2026' => array( 'January' => array( 'Policy Jan' ) ) ),
				'Richmond Area' => array(
					'2026' => array(
						'September' => array( 'Richmond Sep' ),
						'August'    => array( 'Richmond Aug report', 'Richmond Aug' ),
					),
					'2025' => array( 'October' => array( 'Richmond 2025' ) ),
				),
			),
			$this->outline( $this->render() )
		);
	}

	public function test_order_asc_and_the_bmlt_minutes_default_order_setting() {
		$this->minutes( 'A', '2025-03-01 12:00:00', array( 'policy' ) );
		$this->minutes( 'B', '2026-02-01 12:00:00', array( 'policy' ) );
		$this->minutes( 'C', '2026-05-01 12:00:00', array( 'policy' ) );
		$asc = array( 'Policy' => array( '2025' => array( 'March' => array( 'A' ) ), '2026' => array( 'February' => array( 'B' ), 'May' => array( 'C' ) ) ) );

		$this->assertSame( $asc, $this->outline( $this->render( 'order="asc"' ) ) );
		update_option( 'bmlt_minutes_sort_order', 'asc' );
		$this->assertSame( $asc, $this->outline( $this->render() ), 'BMLT Minutes\' default sort order applies' );
		$this->assertSame( array( 2026, 2025 ), array_keys( $this->outline( $this->render( 'order="desc"' ) )['Policy'] ) );
	}

	public function test_meeting_date_groups_by_meeting_and_falls_back_to_posting_date() {
		// Posted in September for an August meeting, as most minutes are.
		$this->minutes( 'August meeting', '2026-09-05 20:00:00', array( 'policy' ), array( 'meeting' => '2026-08-09' ) );
		$this->minutes( 'No meeting date', '2026-07-01 12:00:00', array( 'policy' ) );
		$bad = $this->minutes( 'Bad meeting date', '2026-06-01 12:00:00', array( 'policy' ) );
		// BMLT Minutes cleans dates it saves; older or imported data may still hold one that is not a date.
		global $wpdb;
		$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $bad, 'meta_key' => '_bmlt_minutes_date', 'meta_value' => '2026-02-31' ) );
		wp_cache_delete( $bad, 'post_meta' );

		$this->assertSame(
			array( 'Policy' => array( '2026' => array( 'September' => array( 'August meeting' ), 'July' => array( 'No meeting date' ), 'June' => array( 'Bad meeting date' ) ) ) ),
			$this->outline( $this->render() ),
			'default: the month it was posted'
		);
		$this->assertSame(
			array( 'Policy' => array( '2026' => array( 'August' => array( 'August meeting' ), 'July' => array( 'No meeting date' ), 'June' => array( 'Bad meeting date' ) ) ) ),
			$this->outline( $this->render( 'date="meeting"' ) )
		);
	}

	public function test_year_filter_uses_the_chosen_date() {
		$this->minutes( 'Dec meeting', '2026-01-04 12:00:00', array( 'policy' ), array( 'meeting' => '2025-12-14' ) );
		$this->minutes( 'Old', '2025-05-01 12:00:00', array( 'policy' ) );

		$this->assertSame( array( 'Policy' => array( '2026' => array( 'January' => array( 'Dec meeting' ) ) ) ), $this->outline( $this->render( 'year="2026"' ) ) );
		$this->assertSame( array( 'Policy' => array( '2025' => array( 'December' => array( 'Dec meeting' ), 'May' => array( 'Old' ) ) ) ), $this->outline( $this->render( 'year="2025" date="meeting"' ) ) );
		$this->assertStringContainsString( 'No minutes published yet.', $this->render( 'year="1999"' ) );
		$this->assertCount( 1, $this->outline( $this->render( 'year="20x6"' ) ), 'an invalid year is ignored' );
	}

	public function test_committee_filter_includes_sub_committees_and_unknown_shows_nothing() {
		$this->minutes( 'Area', '2026-03-01 12:00:00', array( 'asc' ) );
		$this->minutes( 'Richmond', '2026-03-02 12:00:00', array( 'richmond' ) );
		$this->minutes( 'Richmond PR', '2026-03-03 12:00:00', array( 'rich-pr' ) );
		$this->minutes( 'NDANA', '2026-03-04 12:00:00', array( 'ndana' ) );
		$this->minutes( 'Policy', '2026-03-05 12:00:00', array( 'policy' ) );

		$this->assertSame( array( 'Public Relations', 'Richmond Area' ), array_keys( $this->outline( $this->render( 'committee="richmond"' ) ) ) );
		$this->assertSame( array( 'New Dominion Area', 'Policy' ), array_keys( $this->outline( $this->render( 'committee="ndana, policy"' ) ) ) );
		$this->assertCount( 5, $this->outline( $this->render( 'committee="asc,policy"' ) ) );
		$this->assertStringContainsString( 'No minutes published yet.', $this->render( 'committee="no-such-committee"' ) );
	}

	public function test_minutes_in_two_committees_are_listed_under_each_and_uncategorized_last() {
		$this->minutes( 'Shared', '2026-04-12 12:00:00', array( 'ndana', 'policy' ) );
		$this->minutes( 'Loose', '2026-04-13 12:00:00', array() );

		$outline = $this->outline( $this->render() );
		$this->assertSame( array( 'New Dominion Area', 'Policy', 'Uncategorized' ), array_keys( $outline ) );
		$this->assertSame( array( 'Shared' ), $outline['Policy']['2026']['April'] );
		$this->assertSame( array( 'Shared' ), $outline['New Dominion Area']['2026']['April'] );

		$filtered = $this->outline( $this->render( 'committee="policy"' ) );
		$this->assertSame( array( 'Policy' ), array_keys( $filtered ), 'only the filtered committee, no Uncategorized' );
	}

	public function test_committees_with_the_same_name_show_their_path() {
		$this->minutes( 'R', '2026-02-01 12:00:00', array( 'rich-pr' ) );
		$this->minutes( 'N', '2026-02-01 12:00:00', array( 'ndana-pr' ) );
		$this->minutes( 'P', '2026-02-01 12:00:00', array( 'policy' ) );

		$this->assertSame(
			array( 'Area Service Committee / New Dominion Area / Public Relations', 'Area Service Committee / Richmond Area / Public Relations', 'Policy' ),
			array_keys( $this->outline( $this->render() ) )
		);
		// Only one of them listed: the plain name is enough.
		$this->assertSame( array( 'Public Relations' ), array_keys( $this->outline( $this->render( 'committee="rich-pr"' ) ) ) );
	}

	public function test_only_published_minutes() {
		$this->minutes( 'Published', '2026-02-01 12:00:00', array( 'policy' ) );
		$this->minutes( 'Draft', '2026-02-02 12:00:00', array( 'policy' ), array( 'status' => 'draft' ) );
		$this->minutes( 'Private', '2026-02-03 12:00:00', array( 'policy' ), array( 'status' => 'private' ) );
		self::factory()->post->create( array( 'post_title' => 'A blog post', 'post_date' => '2026-02-04 12:00:00' ) );

		$this->assertSame( array( 'Policy' => array( '2026' => array( 'February' => array( 'Published' ) ) ) ), $this->outline( $this->render() ) );
	}

	public function test_password_protected_minutes_do_not_reveal_the_document() {
		$id   = $this->minutes( 'Unredacted', '2026-02-01 12:00:00', array( 'policy' ), array( 'password' => 'secret', 'url' => 'https://example.org/private-minutes.pdf' ) );
		$html = $this->render();

		$this->assertStringNotContainsString( 'private-minutes', $html );
		$this->assertStringNotContainsString( '>PDF<', $html );
		$this->assertStringContainsString( 'href="' . esc_url( get_permalink( $id ) ) . '"', $html );
		$this->assertStringContainsString( 'bmlt-minutes__item--locked', $html );
		$this->assertStringContainsString( 'Protected', $html );
	}

	public function test_files_show_as_file_blocks_and_links_stay_links() {
		update_option( 'date_format', 'F j, Y' );
		$this->minutes( 'Sheet', '2026-02-01 12:00:00', array( 'policy' ), array( 'url' => 'https://example.org/treasurer.xlsx', 'meeting' => '2026-01-11' ) );
		$this->minutes( 'Word', '2026-02-02 12:00:00', array( 'policy' ), array( 'url' => 'https://example.org/minutes.docx' ) );
		$this->minutes( 'Google', '2026-02-03 12:00:00', array( 'policy' ), array( 'url' => 'https://docs.google.com/document/d/abc/edit' ) );
		$html = $this->render();
		$xp   = $this->xpath( $html );

		// Word and Excel files: the File block's link and Download button, no preview (browsers cannot show them).
		foreach ( array( 'treasurer.xlsx' => 'Sheet', 'minutes.docx' => 'Word' ) as $file => $title ) {
			$block = $xp->query( '//div[contains(@class,"bmlt-minutes-archive__doc--' . pathinfo( $file, PATHINFO_EXTENSION ) . '")]/div[@class="wp-block-file"]' );
			$this->assertSame( 1, $block->length, $file );
			$link = $xp->query( './a[not(@download)]', $block->item( 0 ) )->item( 0 );
			$this->assertSame( 'https://example.org/' . $file, $link->getAttribute( 'href' ) );
			$this->assertSame( $title, $link->textContent );
			$button = $xp->query( './a[@download]', $block->item( 0 ) )->item( 0 );
			$this->assertSame( 'https://example.org/' . $file, $button->getAttribute( 'href' ) );
			$this->assertSame( 'Download', $button->textContent );
			$this->assertSame( $link->getAttribute( 'id' ), $button->getAttribute( 'aria-describedby' ) );
			$this->assertSame( 0, $xp->query( './/object', $block->item( 0 ) )->length );
		}
		$this->assertStringContainsString( '<p class="bmlt-minutes-archive__date">January 11, 2026</p>', $html );
		// A Google Doc is a web page, not a file: a link as in BMLT Minutes' list.
		$this->assertStringContainsString( 'dashicons-google', $html );
		$this->assertSame( 1, $xp->query( '//li[@class="bmlt-minutes__item"]' )->length );

		$this->assertStringNotContainsString( 'bmlt-minutes-archive__date', $this->render( 'show_date="false"' ) );
		$list = $this->render( 'display="list"' );
		$this->assertStringNotContainsString( 'wp-block-file', $list );
		$this->assertStringContainsString( '<span class="bmlt-minutes__type">XLSX</span>', $list );
		$this->assertStringContainsString( '<span class="bmlt-minutes__date">January 11, 2026</span>', $list );
	}

	public function test_pdf_previews_load_in_the_open_month_and_wait_in_closed_ones() {
		$this->minutes( 'September', '2026-09-14 12:00:00', array( 'policy' ), array( 'url' => 'https://example.org/sep.pdf' ) );
		$this->minutes( 'August', '2026-08-09 12:00:00', array( 'policy' ), array( 'url' => 'https://example.org/aug.pdf' ) );
		$this->minutes( 'Richmond', '2025-05-01 12:00:00', array( 'richmond' ), array( 'url' => 'https://example.org/rich.pdf' ) );
		$html = $this->render();
		$xp   = $this->xpath( $html );

		$this->assertSame( array( '2026-September', '2025-May' ), $this->open_months( $html ), 'each committee\'s newest month (Policy, then Richmond Area)' );
		$open = $xp->query( '//details[@open]//object' );
		$this->assertSame( 2, $open->length );
		foreach ( $open as $embed ) {
			$this->assertStringStartsWith( 'https://example.org/', $embed->getAttribute( 'data' ) );
			$this->assertSame( 'application/pdf', $embed->getAttribute( 'type' ) );
			$this->assertSame( 'width:100%;height:500px', $embed->getAttribute( 'style' ) );
			$this->assertStringContainsString( 'wp-block-file__embed', $embed->getAttribute( 'class' ) );
			// WordPress rendered the block: its script hides the preview where PDFs cannot be shown (phones). (WordPress
			// 6.6 and later also add "hidden" until the script runs.)
			$this->assertSame( '!state.hasPdfPreview', $embed->getAttribute( 'data-wp-bind--hidden' ) );
			$this->assertSame( 'core/file', $embed->parentNode->getAttribute( 'data-wp-interactive' ) );
		}
		$closed = $xp->query( '//details[not(@open)]//object' );
		$this->assertSame( 1, $closed->length );
		$this->assertFalse( $closed->item( 0 )->hasAttribute( 'data' ), 'a closed month does not download its PDFs' );
		$this->assertSame( 'https://example.org/aug.pdf', $closed->item( 0 )->getAttribute( 'data-bmlt-minutes-src' ) );
		$this->assertTrue( wp_script_is( 'bmlt-minutes-by-month', 'enqueued' ) );

		wp_dequeue_script( 'bmlt-minutes-by-month' );
		$all = $this->render( 'open="all"' );
		$this->assertStringNotContainsString( 'data-bmlt-minutes-src', $all );
		$this->assertFalse( wp_script_is( 'bmlt-minutes-by-month', 'enqueued' ), 'nothing waits, so no script' );

		$this->assertStringContainsString( 'height:300px', $this->render( 'preview_height="300"' ) );
		$this->assertStringContainsString( 'height:500px', $this->render( 'preview_height="tall"' ) );
		$none = $this->render( 'preview_height="0"' );
		$this->assertStringNotContainsString( '<object', $none );
		$this->assertStringContainsString( 'wp-block-file__button', $none );
	}

	public function test_the_newest_month_opens_whichever_way_the_list_is_sorted() {
		$this->minutes( 'Old', '2025-12-01 12:00:00', array( 'policy' ) );
		$this->minutes( 'New', '2026-03-01 12:00:00', array( 'policy' ) );
		$this->minutes( 'Middle', '2026-01-01 12:00:00', array( 'policy' ) );

		$this->assertSame( array( '2026-March' ), $this->open_months( $this->render() ) );
		$this->assertSame( array( '2026-March' ), $this->open_months( $this->render( 'order="asc"' ) ) );
		$this->assertSame( array( '2025-December' ), $this->open_months( $this->render( 'year="2025"' ) ) );
		$this->assertSame( array( '2026-January', '2026-March' ), $this->open_months( $this->render( 'order="asc" open="all" year="2026"' ) ) );
		$this->assertSame( array(), $this->open_months( $this->render( 'open="none"' ) ) );
		$this->assertSame( array( '2026-March' ), $this->open_months( $this->render( 'open="bogus"' ) ) );
	}

	public function test_titles_and_committee_names_are_escaped() {
		$id  = $this->minutes( 'Minutes', '2026-02-01 12:00:00', array( 'policy' ) );
		$pdf = $this->minutes( 'PDF', '2026-02-02 12:00:00', array( 'policy' ), array( 'url' => 'https://example.org/a.pdf' ) );
		// Written to the database directly: WordPress filters markup out of titles and names it saves, but imported
		// data or an administrator with unfiltered_html can still hold it.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_title' => '<script>alert(1)</script>Minutes' ), array( 'ID' => $id ) );
		$wpdb->update( $wpdb->posts, array( 'post_title' => '"><script>alert(2)</script>' ), array( 'ID' => $pdf ) );
		clean_post_cache( $pdf );
		$wpdb->update( $wpdb->terms, array( 'name' => 'Policy <img src=x onerror=alert(1)>' ), array( 'term_id' => self::$c['policy'] ) );
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => 'javascript:alert(1)' ), array( 'post_id' => $id, 'meta_key' => '_bmlt_minutes_url' ) );
		wp_cache_delete( $id, 'post_meta' );
		clean_post_cache( $id );
		clean_term_cache( self::$c['policy'], 'bmlt_committee' );
		$html = $this->render();

		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;Minutes', $html );
		$this->assertStringContainsString( 'Policy &lt;img src=x onerror=alert(1)&gt;', $html );
		$this->assertStringContainsString( '&gt;&lt;script&gt;alert(2)&lt;/script&gt;</a>', $html, 'File block link' );
		// The curly quote is a character or, before WordPress 6.6, an entity.
		$this->assertMatchesRegularExpression( '/aria-label="Embed of (“|&#8220;)&gt;\."/', $html, 'File block preview label (tags stripped)' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$wpdb->update( $wpdb->terms, array( 'name' => 'Policy' ), array( 'term_id' => self::$c['policy'] ) );
		clean_term_cache( self::$c['policy'], 'bmlt_committee' );
	}

	public function test_heading_levels_and_counts() {
		$this->minutes( 'New', '2026-02-01 12:00:00', array( 'policy' ) );
		$this->minutes( 'Newer', '2026-02-02 12:00:00', array( 'policy' ) );

		$html = $this->render( 'heading_level="2"' );
		$this->assertStringContainsString( '<h2 class="bmlt-minutes__group bmlt-minutes-archive__committee-title">Policy</h2>', $html );
		$this->assertStringContainsString( '<h3 class="bmlt-minutes-archive__year-title">2026</h3>', $html );
		$this->assertStringContainsString( '<h4 class="bmlt-minutes-archive__month-title">February</h4> <span class="bmlt-minutes-archive__count">2 documents</span>', $html );
		$this->assertStringContainsString( '<h3 class="bmlt-minutes__group', $this->render( 'heading_level="9"' ), 'out of range: the default' );
	}

	public function test_month_names_follow_the_site_language() {
		global $wp_locale;
		$this->minutes( 'M', '2026-03-01 12:00:00', array( 'policy' ) );
		$saved                     = $wp_locale->month['03'];
		$wp_locale->month['03']    = 'Mars';
		try {
			$this->assertArrayHasKey( 'Mars', $this->outline( $this->render() )['Policy']['2026'] );
		} finally {
			$wp_locale->month['03'] = $saved;
		}
	}

	public function test_empty_and_styles() {
		$this->assertStringContainsString( 'No minutes published yet.', $this->render() );
		$this->assertTrue( wp_style_is( 'bmlt-minutes', 'enqueued' ) );
	}
}
