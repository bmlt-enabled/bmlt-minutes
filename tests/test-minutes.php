<?php
/**
 * Tests for the Minutes plugin.
 */

class Test_BMLT_Minutes extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// WP_UnitTestCase::tear_down() truncates every table, including terms — so the
		// committees seeded by BMLT_Minutes::activate() during bootstrap are gone by the
		// time the second test runs. Re-seed in setUp so tests start from a known state.
		wp_cache_flush();
		BMLT_Minutes::activate();
	}

	public function tear_down() {
		delete_option( BMLT_Minutes::OPTION_MAX_UPLOAD_MB );
		parent::tear_down();
	}

	/**
	 * Helper: create a published Minutes post with the meta_date set.
	 * The shortcode query orderby='meta_value' on META_DATE does an INNER JOIN, so
	 * posts without that meta won't appear in the rendered list.
	 */
	private function make_minutes( array $args = [], string $date = '2026-05-01' ): int {
		$post_id = self::factory()->post->create(
			array_merge(
				[
					'post_type'   => BMLT_Minutes::CPT,
					'post_status' => 'publish',
				],
				$args
			)
		);
		update_post_meta( $post_id, BMLT_Minutes::META_DATE, $date );
		return $post_id;
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public function test_cpt_is_registered(): void {
		$this->assertTrue( post_type_exists( BMLT_Minutes::CPT ) );
		$pt = get_post_type_object( BMLT_Minutes::CPT );
		$this->assertNotNull( $pt );
		$this->assertTrue( $pt->public );
		$this->assertTrue( $pt->show_in_rest );
	}

	public function test_committee_taxonomy_is_registered(): void {
		$this->assertTrue( taxonomy_exists( BMLT_Minutes::TAX_COMMITTEE ) );
		$tax = get_taxonomy( BMLT_Minutes::TAX_COMMITTEE );
		$this->assertTrue( $tax->hierarchical );
		$this->assertTrue( $tax->show_in_rest );
	}

	public function test_post_meta_is_registered_with_sanitizers(): void {
		// register_post_meta's sanitize_callback gets applied on update_post_meta.
		// If the meta keys are registered with the date sanitizer, a non-ISO input
		// will be normalized to YYYY-MM-DD on write.
		$post_id = self::factory()->post->create( [ 'post_type' => BMLT_Minutes::CPT ] );

		update_post_meta( $post_id, BMLT_Minutes::META_DATE, 'May 24, 2026' );
		$this->assertSame( '2026-05-24', get_post_meta( $post_id, BMLT_Minutes::META_DATE, true ) );

		update_post_meta( $post_id, BMLT_Minutes::META_ATTACHMENT, '42' );
		$this->assertSame( 42, (int) get_post_meta( $post_id, BMLT_Minutes::META_ATTACHMENT, true ) );
	}

	public function test_minutes_shortcode_is_registered(): void {
		$this->assertTrue( shortcode_exists( 'minutes' ) );
	}

	public function test_seed_committees_creates_defaults_when_empty(): void {
		// setUp already called activate() with empty terms, so defaults should be present.
		wp_cache_flush();
		$terms = get_terms(
			[
				'taxonomy'   => BMLT_Minutes::TAX_COMMITTEE,
				'hide_empty' => false,
				'fields'     => 'names',
			]
		);
		// pre_term_name filters html-encode '&' to '&amp;' at write time, so decode
		// for comparison against the in-code DEFAULT_COMMITTEES list.
		$decoded = array_map( static fn( $name ) => html_entity_decode( $name, ENT_QUOTES ), $terms );

		$this->assertSame(
			count( BMLT_Minutes::DEFAULT_COMMITTEES ),
			count( $decoded ),
			'Activation should have seeded exactly DEFAULT_COMMITTEES on an empty taxonomy.'
		);
		foreach ( BMLT_Minutes::DEFAULT_COMMITTEES as $expected ) {
			$this->assertContains( $expected, $decoded );
		}
	}

	public function test_seed_committees_skips_when_terms_exist(): void {
		// Wipe defaults, drop in a single custom term, then re-activate.
		// activate() must NOT restore defaults when the taxonomy is non-empty.
		$existing = get_terms(
			[
				'taxonomy'   => BMLT_Minutes::TAX_COMMITTEE,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		foreach ( $existing as $term_id ) {
			wp_delete_term( $term_id, BMLT_Minutes::TAX_COMMITTEE );
		}
		wp_insert_term( 'Custom Committee', BMLT_Minutes::TAX_COMMITTEE );
		wp_cache_flush();

		BMLT_Minutes::activate();

		$term = get_term_by( 'name', 'Area Service Committee', BMLT_Minutes::TAX_COMMITTEE );
		$this->assertFalse( $term, 'Activation must not re-seed defaults when the taxonomy is non-empty.' );
	}

	// -------------------------------------------------------------------------
	// Sanitizers
	// -------------------------------------------------------------------------

	public function test_sanitize_date_accepts_iso_format(): void {
		$this->assertSame( '2026-05-24', BMLT_Minutes::sanitize_date( '2026-05-24' ) );
	}

	public function test_sanitize_date_normalizes_other_formats(): void {
		$this->assertSame( '2026-05-24', BMLT_Minutes::sanitize_date( 'May 24, 2026' ) );
		$this->assertSame( '2026-01-02', BMLT_Minutes::sanitize_date( '01/02/2026' ) );
	}

	public function test_sanitize_date_returns_empty_on_invalid(): void {
		$this->assertSame( '', BMLT_Minutes::sanitize_date( 'not a date' ) );
		$this->assertSame( '', BMLT_Minutes::sanitize_date( '' ) );
	}

	public function test_sanitize_max_upload_mb_rejects_invalid(): void {
		$this->assertSame( BMLT_Minutes::DEFAULT_MAX_UPLOAD_MB, BMLT_Minutes::sanitize_max_upload_mb( 0 ) );
		$this->assertSame( BMLT_Minutes::DEFAULT_MAX_UPLOAD_MB, BMLT_Minutes::sanitize_max_upload_mb( -5 ) );
		$this->assertSame( BMLT_Minutes::DEFAULT_MAX_UPLOAD_MB, BMLT_Minutes::sanitize_max_upload_mb( 'abc' ) );
	}

	public function test_sanitize_max_upload_mb_clamps_to_server_cap(): void {
		// Force a very small server cap so any "high" value gets clamped.
		add_filter(
			'upload_size_limit',
			static fn() => 2 * 1024 * 1024,
			999
		);
		$this->assertSame( 2, BMLT_Minutes::sanitize_max_upload_mb( 500 ) );
		remove_all_filters( 'upload_size_limit', 999 );
	}

	public function test_max_upload_bytes_default(): void {
		delete_option( BMLT_Minutes::OPTION_MAX_UPLOAD_MB );
		$this->assertSame( BMLT_Minutes::DEFAULT_MAX_UPLOAD_MB * 1024 * 1024, BMLT_Minutes::max_upload_bytes() );
	}

	public function test_max_upload_bytes_reads_option(): void {
		update_option( BMLT_Minutes::OPTION_MAX_UPLOAD_MB, 25 );
		$this->assertSame( 25 * 1024 * 1024, BMLT_Minutes::max_upload_bytes() );
	}

	// -------------------------------------------------------------------------
	// resolve_document() precedence
	// -------------------------------------------------------------------------

	public function test_resolve_document_returns_empty_when_neither_set(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => BMLT_Minutes::CPT ] );
		$result  = BMLT_Minutes::resolve_document( $post_id );
		$this->assertSame( [ '', '', '' ], $result );
	}

	public function test_resolve_document_uses_url_when_only_url_set(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => BMLT_Minutes::CPT ] );
		update_post_meta( $post_id, BMLT_Minutes::META_URL, 'https://docs.google.com/document/d/abc/edit' );

		[ $url, $label, $type ] = BMLT_Minutes::resolve_document( $post_id );

		$this->assertSame( 'https://docs.google.com/document/d/abc/edit', $url );
		$this->assertSame( 'docs.google.com', $label );
		$this->assertSame( 'google', $type );
	}

	public function test_resolve_document_classifies_dropbox_link(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => BMLT_Minutes::CPT ] );
		update_post_meta( $post_id, BMLT_Minutes::META_URL, 'https://www.dropbox.com/s/xyz/notes' );

		[ , , $type ] = BMLT_Minutes::resolve_document( $post_id );
		$this->assertSame( 'dropbox', $type );
	}

	public function test_resolve_document_uses_extension_for_direct_files(): void {
		$post_id = self::factory()->post->create( [ 'post_type' => BMLT_Minutes::CPT ] );
		update_post_meta( $post_id, BMLT_Minutes::META_URL, 'https://example.org/files/notes.pdf' );

		[ , , $type ] = BMLT_Minutes::resolve_document( $post_id );
		$this->assertSame( 'pdf', $type );
	}

	public function test_resolve_document_attachment_takes_precedence(): void {
		$post_id       = self::factory()->post->create( [ 'post_type' => BMLT_Minutes::CPT ] );
		$attachment_id = self::factory()->attachment->create_object(
			[
				'file'           => 'notes.pdf',
				'post_mime_type' => 'application/pdf',
				'post_parent'    => $post_id,
				'post_title'     => 'Minutes',
			]
		);

		update_post_meta( $post_id, BMLT_Minutes::META_URL, 'https://example.org/should-be-ignored.pdf' );
		update_post_meta( $post_id, BMLT_Minutes::META_ATTACHMENT, $attachment_id );

		[ $url, , $type ] = BMLT_Minutes::resolve_document( $post_id );

		$this->assertSame( 'pdf', $type );
		$this->assertStringNotContainsString( 'should-be-ignored', $url, 'Attachment URL must win over external URL.' );
	}

	// -------------------------------------------------------------------------
	// Shortcode rendering
	// -------------------------------------------------------------------------

	public function test_render_shortcode_empty_state(): void {
		$html = do_shortcode( '[minutes]' );
		$this->assertStringContainsString( 'bmlt-minutes--empty', $html );
		$this->assertStringContainsString( 'No minutes published yet.', $html );
	}

	public function test_render_shortcode_lists_published_minutes(): void {
		$post_id = $this->make_minutes(
			[ 'post_title' => 'May 2026 ASC Minutes' ],
			'2026-05-15'
		);
		update_post_meta( $post_id, BMLT_Minutes::META_URL, 'https://example.org/may.pdf' );

		$html = do_shortcode( '[minutes group_by="none"]' );

		$this->assertStringContainsString( 'May 2026 ASC Minutes', $html );
		$this->assertStringContainsString( 'https://example.org/may.pdf', $html );
		$this->assertStringContainsString( 'bmlt-minutes__list', $html );
	}

	public function test_render_shortcode_respects_year_filter(): void {
		$this->make_minutes( [ 'post_title' => 'Old Minutes' ], '2024-03-01' );
		$this->make_minutes( [ 'post_title' => 'Newer Minutes' ], '2026-03-01' );

		$html = do_shortcode( '[minutes year="2026" group_by="none"]' );

		$this->assertStringContainsString( 'Newer Minutes', $html );
		$this->assertStringNotContainsString( 'Old Minutes', $html );
	}

	public function test_render_shortcode_filters_by_committee_slug(): void {
		$term_a = wp_insert_term( 'Test Committee A', BMLT_Minutes::TAX_COMMITTEE );
		$term_b = wp_insert_term( 'Test Committee B', BMLT_Minutes::TAX_COMMITTEE );

		$post_a = $this->make_minutes( [ 'post_title' => 'In Committee A' ], '2026-05-01' );
		wp_set_object_terms( $post_a, [ $term_a['term_id'] ], BMLT_Minutes::TAX_COMMITTEE );

		$post_b = $this->make_minutes( [ 'post_title' => 'In Committee B' ], '2026-05-02' );
		wp_set_object_terms( $post_b, [ $term_b['term_id'] ], BMLT_Minutes::TAX_COMMITTEE );

		$html = do_shortcode( '[minutes committee="test-committee-a" group_by="none"]' );

		$this->assertStringContainsString( 'In Committee A', $html );
		$this->assertStringNotContainsString( 'In Committee B', $html );
	}

	public function test_render_shortcode_hides_document_url_for_locked_post(): void {
		$post_id = $this->make_minutes(
			[
				'post_title'    => 'Members Only Minutes',
				'post_password' => 'secret',
			],
			'2026-05-15'
		);
		update_post_meta( $post_id, BMLT_Minutes::META_URL, 'https://example.org/private.pdf' );

		$html = do_shortcode( '[minutes group_by="none"]' );

		$this->assertStringContainsString( 'Members Only Minutes', $html );
		$this->assertStringContainsString( 'bmlt-minutes__item--locked', $html );
		$this->assertStringContainsString( 'dashicons-lock', $html );
		$this->assertStringNotContainsString( 'private.pdf', $html, 'Locked posts must not expose the underlying document URL.' );
	}

	// -------------------------------------------------------------------------
	// Single-view content filter
	// -------------------------------------------------------------------------

	public function test_append_document_link_returns_content_unchanged_when_no_document(): void {
		$post_id = $this->make_minutes( [ 'post_content' => 'Some minutes body.' ] );
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$filtered = BMLT_Minutes::append_document_link( 'Some minutes body.' );
		$this->assertSame( 'Some minutes body.', $filtered );
	}

	public function test_append_document_link_appends_link_on_single_view(): void {
		$post_id = $this->make_minutes( [ 'post_content' => 'Some minutes body.' ] );
		update_post_meta( $post_id, BMLT_Minutes::META_URL, 'https://example.org/april.pdf' );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$filtered = BMLT_Minutes::append_document_link( 'Some minutes body.' );

		$this->assertStringContainsString( 'https://example.org/april.pdf', $filtered );
		$this->assertStringContainsString( 'bmlt-minutes__single-link', $filtered );
	}

	// -------------------------------------------------------------------------
	// Password handling via wp_insert_post_data filter
	// -------------------------------------------------------------------------

	public function test_apply_password_field_ignores_other_post_types(): void {
		$data    = [
			'post_type'     => 'post',
			'post_password' => '',
		];
		$postarr = [];

		$result = BMLT_Minutes::apply_password_field( $data, $postarr );
		$this->assertSame( '', $result['post_password'] );
	}

	public function test_apply_password_field_requires_nonce(): void {
		$data    = [
			'post_type'     => BMLT_Minutes::CPT,
			'post_password' => '',
		];
		$postarr = [
			'bmlt_minutes_password' => 'topsecret',
			// No nonce field present — filter must bail.
		];

		$result = BMLT_Minutes::apply_password_field( $data, $postarr );
		$this->assertSame( '', $result['post_password'] );
	}

	public function test_apply_password_field_writes_password_when_nonce_valid(): void {
		$data    = [
			'post_type'     => BMLT_Minutes::CPT,
			'post_password' => '',
		];
		$postarr = [
			'bmlt_minutes_password'      => 'topsecret',
			BMLT_Minutes::NONCE_FIELD    => wp_create_nonce( BMLT_Minutes::NONCE_ACTION ),
		];

		$result = BMLT_Minutes::apply_password_field( $data, $postarr );
		$this->assertSame( 'topsecret', $result['post_password'] );
	}
}
