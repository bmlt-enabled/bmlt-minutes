<?php
/**
 * Tests for the BMLT Minutes plugin.
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
	 * Pass an empty string for $date to create an undated post (no META_DATE row);
	 * the shortcode still lists those (see test_render_shortcode_includes_undated_minutes).
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
		if ( '' !== $date ) {
			update_post_meta( $post_id, BMLT_Minutes::META_DATE, $date );
		}
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
		$this->assertTrue( shortcode_exists( 'bmlt_minutes' ) );
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
		$html = do_shortcode( '[bmlt_minutes]' );
		$this->assertStringContainsString( 'bmlt-minutes--empty', $html );
		$this->assertStringContainsString( 'No minutes published yet.', $html );
	}

	public function test_render_shortcode_lists_published_minutes(): void {
		$post_id = $this->make_minutes(
			[ 'post_title' => 'May 2026 ASC Minutes' ],
			'2026-05-15'
		);
		update_post_meta( $post_id, BMLT_Minutes::META_URL, 'https://example.org/may.pdf' );

		$html = do_shortcode( '[bmlt_minutes group_by="none"]' );

		$this->assertStringContainsString( 'May 2026 ASC Minutes', $html );
		$this->assertStringContainsString( 'https://example.org/may.pdf', $html );
		$this->assertStringContainsString( 'bmlt-minutes__list', $html );
	}

	public function test_render_shortcode_respects_year_filter(): void {
		$this->make_minutes( [ 'post_title' => 'Old Minutes' ], '2024-03-01' );
		$this->make_minutes( [ 'post_title' => 'Newer Minutes' ], '2026-03-01' );

		$html = do_shortcode( '[bmlt_minutes year="2026" group_by="none"]' );

		$this->assertStringContainsString( 'Newer Minutes', $html );
		$this->assertStringNotContainsString( 'Old Minutes', $html );
	}

	public function test_render_shortcode_includes_undated_minutes(): void {
		$this->make_minutes( [ 'post_title' => 'Dated Minutes' ], '2026-05-01' );
		$this->make_minutes( [ 'post_title' => 'Undated Minutes' ], '' );

		$html = do_shortcode( '[bmlt_minutes group_by="none"]' );

		$this->assertStringContainsString( 'Dated Minutes', $html );
		$this->assertStringContainsString( 'Undated Minutes', $html );
	}

	public function test_render_shortcode_uses_saved_sort_order_when_no_attr(): void {
		$this->make_minutes( [ 'post_title' => 'January Minutes' ], '2026-01-01' );
		$this->make_minutes( [ 'post_title' => 'December Minutes' ], '2026-12-01' );

		update_option( 'bmlt_minutes_sort_order', 'asc' );
		$asc = do_shortcode( '[bmlt_minutes group_by="none"]' );
		$this->assertLessThan(
			strpos( $asc, 'December Minutes' ),
			strpos( $asc, 'January Minutes' ),
			'With sort order "asc" the oldest minutes should render first.'
		);

		update_option( 'bmlt_minutes_sort_order', 'desc' );
		$desc = do_shortcode( '[bmlt_minutes group_by="none"]' );
		$this->assertLessThan(
			strpos( $desc, 'January Minutes' ),
			strpos( $desc, 'December Minutes' ),
			'With sort order "desc" the newest minutes should render first.'
		);

		delete_option( 'bmlt_minutes_sort_order' );
	}

	public function test_render_shortcode_filters_by_committee_slug(): void {
		$term_a = wp_insert_term( 'Test Committee A', BMLT_Minutes::TAX_COMMITTEE );
		$term_b = wp_insert_term( 'Test Committee B', BMLT_Minutes::TAX_COMMITTEE );

		$post_a = $this->make_minutes( [ 'post_title' => 'In Committee A' ], '2026-05-01' );
		wp_set_object_terms( $post_a, [ $term_a['term_id'] ], BMLT_Minutes::TAX_COMMITTEE );

		$post_b = $this->make_minutes( [ 'post_title' => 'In Committee B' ], '2026-05-02' );
		wp_set_object_terms( $post_b, [ $term_b['term_id'] ], BMLT_Minutes::TAX_COMMITTEE );

		$html = do_shortcode( '[bmlt_minutes committee="test-committee-a" group_by="none"]' );

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

		$html = do_shortcode( '[bmlt_minutes group_by="none"]' );

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

	// -------------------------------------------------------------------------
	// Capabilities & roles
	// -------------------------------------------------------------------------

	public function test_administrator_gets_minutes_capabilities(): void {
		$admin = get_role( 'administrator' );
		foreach ( BMLT_Minutes::minutes_capabilities() as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), "administrator should have {$cap}" );
		}
	}

	public function test_minutes_manager_role_exists_with_scoped_caps(): void {
		$role = get_role( BMLT_Minutes::ROLE_MANAGER );
		$this->assertNotNull( $role, 'Minutes Manager role should be created on activation.' );

		// Has the minutes caps + the basics needed to reach wp-admin / upload.
		$this->assertTrue( $role->has_cap( BMLT_Minutes::PRIMARY_CAP ) );
		$this->assertTrue( $role->has_cap( 'publish_bmlt_minutes' ) );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
		$this->assertTrue( $role->has_cap( 'read' ) );

		// But NOT generic content access.
		$this->assertFalse( $role->has_cap( 'edit_posts' ) );
		$this->assertFalse( $role->has_cap( 'edit_pages' ) );
		$this->assertFalse( $role->has_cap( 'manage_options' ) );
	}

	public function test_minutes_manager_can_edit_minutes_but_not_posts(): void {
		$user_id = self::factory()->user->create( [ 'role' => BMLT_Minutes::ROLE_MANAGER ] );
		$user    = get_user_by( 'id', $user_id );

		$this->assertTrue( user_can( $user, 'edit_bmlt_minutes' ) );
		$this->assertTrue( user_can( $user, 'publish_bmlt_minutes' ) );
		$this->assertFalse( user_can( $user, 'edit_posts' ), 'Minutes Manager must not gain generic post editing.' );
	}

	public function test_map_meta_cap_resolves_edit_post_for_manager(): void {
		$author_id  = self::factory()->user->create( [ 'role' => BMLT_Minutes::ROLE_MANAGER ] );
		$manager_id = self::factory()->user->create( [ 'role' => BMLT_Minutes::ROLE_MANAGER ] );

		$post_id = self::factory()->post->create(
			[
				'post_type'   => BMLT_Minutes::CPT,
				'post_author' => $author_id,
			]
		);

		// A manager can edit minutes posts (incl. others', via edit_others_bmlt_minutes).
		$this->assertTrue( user_can( $manager_id, 'edit_post', $post_id ) );
	}

	// -------------------------------------------------------------------------
	// Per-user "Can manage minutes" toggle
	// -------------------------------------------------------------------------

	public function test_user_cap_toggle_grants_caps_when_checked(): void {
		$admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$target_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $admin_id );

		$_POST = [
			BMLT_Minutes::USER_CAP_NONCE_FIELD => wp_create_nonce( BMLT_Minutes::USER_CAP_NONCE_ACTION ),
			'bmlt_minutes_can_manage'          => '1',
		];

		BMLT_Minutes::save_user_capability_field( $target_id );

		$target = get_user_by( 'id', $target_id );
		$this->assertTrue( user_can( $target, BMLT_Minutes::PRIMARY_CAP ) );
		$this->assertTrue( user_can( $target, 'publish_bmlt_minutes' ) );
		$this->assertTrue( user_can( $target, 'upload_files' ) );
		$this->assertFalse( user_can( $target, 'edit_posts' ) );

		$_POST = [];
	}

	public function test_user_cap_toggle_revokes_caps_when_unchecked(): void {
		$admin_id  = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$target_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $admin_id );

		$target = get_user_by( 'id', $target_id );
		foreach ( BMLT_Minutes::minutes_capabilities() as $cap ) {
			$target->add_cap( $cap );
		}
		$this->assertTrue( user_can( $target_id, BMLT_Minutes::PRIMARY_CAP ) );

		// No checkbox key in POST = unchecked.
		$_POST = [
			BMLT_Minutes::USER_CAP_NONCE_FIELD => wp_create_nonce( BMLT_Minutes::USER_CAP_NONCE_ACTION ),
		];

		BMLT_Minutes::save_user_capability_field( $target_id );

		$target = get_user_by( 'id', $target_id );
		$this->assertFalse( user_can( $target, BMLT_Minutes::PRIMARY_CAP ) );

		$_POST = [];
	}

	public function test_user_cap_toggle_requires_promote_users(): void {
		$editor_id = self::factory()->user->create( [ 'role' => 'author' ] ); // no promote_users
		$target_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $editor_id );

		$_POST = [
			BMLT_Minutes::USER_CAP_NONCE_FIELD => wp_create_nonce( BMLT_Minutes::USER_CAP_NONCE_ACTION ),
			'bmlt_minutes_can_manage'          => '1',
		];

		BMLT_Minutes::save_user_capability_field( $target_id );

		$this->assertFalse( user_can( $target_id, BMLT_Minutes::PRIMARY_CAP ), 'A non-privileged user must not be able to grant minutes caps.' );

		$_POST = [];
	}

	// -------------------------------------------------------------------------
	// Committee scope
	// -------------------------------------------------------------------------

	/**
	 * Helper: Area A (with an H&I child), Area B, and a Minutes Manager scoped to Area A.
	 *
	 * @return array{area_a:int,area_a_hi:int,area_b:int,user:int}
	 */
	private function make_scoped_fixture(): array {
		$area_a    = wp_insert_term( 'Area A', BMLT_Minutes::TAX_COMMITTEE )['term_id'];
		$area_a_hi = wp_insert_term( 'Area A H&I', BMLT_Minutes::TAX_COMMITTEE, [ 'parent' => $area_a ] )['term_id'];
		$area_b    = wp_insert_term( 'Area B', BMLT_Minutes::TAX_COMMITTEE )['term_id'];
		$user      = self::factory()->user->create( [ 'role' => BMLT_Minutes::ROLE_MANAGER ] );
		update_user_meta( $user, BMLT_Minutes::USER_META_COMMITTEES, [ $area_a ] );
		// Term hierarchy is cached per request; a freshly inserted child isn't in it yet.
		delete_option( BMLT_Minutes::TAX_COMMITTEE . '_children' );
		return compact( 'area_a', 'area_a_hi', 'area_b', 'user' );
	}

	/**
	 * Helper: run the Minutes list query as the main query so pre_get_posts
	 * handlers that check is_main_query() apply, then restore the global.
	 *
	 * @return int[]
	 */
	private function run_admin_list_query(): array {
		$previous               = $GLOBALS['wp_the_query'];
		$query                  = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		try {
			return $query->query(
				[
					'post_type'      => BMLT_Minutes::CPT,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				]
			);
		} finally {
			$GLOBALS['wp_the_query'] = $previous;
		}
	}

	public function test_committee_scope_is_null_when_unset_and_for_admins(): void {
		$manager = self::factory()->user->create( [ 'role' => BMLT_Minutes::ROLE_MANAGER ] );
		$this->assertNull( BMLT_Minutes::committee_scope( $manager ) );

		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		update_user_meta( $admin, BMLT_Minutes::USER_META_COMMITTEES, [ 999999 ] );
		$this->assertNull( BMLT_Minutes::committee_scope( $admin ), 'Administrators are never scoped.' );
	}

	public function test_committee_scope_includes_descendants_and_fails_closed(): void {
		$f     = $this->make_scoped_fixture();
		$scope = BMLT_Minutes::committee_scope( $f['user'] );
		$this->assertEqualsCanonicalizing( [ $f['area_a'], $f['area_a_hi'] ], $scope );

		wp_delete_term( $f['area_a_hi'], BMLT_Minutes::TAX_COMMITTEE );
		wp_delete_term( $f['area_a'], BMLT_Minutes::TAX_COMMITTEE );
		$this->assertSame( [], BMLT_Minutes::committee_scope( $f['user'] ), 'Deleting every scoped committee must not widen access to everything.' );
	}

	public function test_scoped_user_can_edit_in_scope_and_child_but_not_out_of_scope(): void {
		$f = $this->make_scoped_fixture();

		$in_scope = $this->make_minutes();
		wp_set_object_terms( $in_scope, [ $f['area_a'] ], BMLT_Minutes::TAX_COMMITTEE );
		$in_child = $this->make_minutes();
		wp_set_object_terms( $in_child, [ $f['area_a_hi'] ], BMLT_Minutes::TAX_COMMITTEE );
		$outside = $this->make_minutes();
		wp_set_object_terms( $outside, [ $f['area_b'] ], BMLT_Minutes::TAX_COMMITTEE );

		$this->assertTrue( user_can( $f['user'], 'edit_post', $in_scope ) );
		$this->assertTrue( user_can( $f['user'], 'edit_post', $in_child ) );
		$this->assertFalse( user_can( $f['user'], 'edit_post', $outside ) );
		$this->assertFalse( user_can( $f['user'], 'delete_post', $outside ) );
		$this->assertFalse( user_can( $f['user'], 'publish_post', $outside ) );

		// An unscoped manager is unaffected.
		$other = self::factory()->user->create( [ 'role' => BMLT_Minutes::ROLE_MANAGER ] );
		$this->assertTrue( user_can( $other, 'edit_post', $outside ) );
	}

	public function test_scoped_user_can_edit_own_uncategorized_post_only(): void {
		$f     = $this->make_scoped_fixture();
		$own   = $this->make_minutes( [ 'post_author' => $f['user'] ] );
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$other = $this->make_minutes( [ 'post_author' => $admin ] );

		$this->assertTrue( user_can( $f['user'], 'edit_post', $own ), 'A new draft has no committee yet; its author must still be able to edit it.' );
		$this->assertFalse( user_can( $f['user'], 'edit_post', $other ) );
	}

	public function test_scope_does_not_affect_other_post_types(): void {
		$f       = $this->make_scoped_fixture();
		$user    = get_user_by( 'id', $f['user'] );
		$user->add_cap( 'edit_posts' );
		$post_id = self::factory()->post->create(
			[
				'post_status' => 'draft',
				'post_author' => $f['user'],
			]
		);
		$this->assertTrue( user_can( $f['user'], 'edit_post', $post_id ) );
	}

	public function test_term_listing_is_narrowed_in_admin_for_scoped_user(): void {
		$f = $this->make_scoped_fixture();
		wp_set_current_user( $f['user'] );
		set_current_screen( 'edit-' . BMLT_Minutes::CPT );

		$ids = get_terms(
			[
				'taxonomy'   => BMLT_Minutes::TAX_COMMITTEE,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		$this->assertEqualsCanonicalizing( [ $f['area_a'], $f['area_a_hi'] ], $ids );

		$objects = get_terms(
			[
				'taxonomy'   => BMLT_Minutes::TAX_COMMITTEE,
				'hide_empty' => false,
			]
		);
		$this->assertEqualsCanonicalizing( [ $f['area_a'], $f['area_a_hi'] ], wp_list_pluck( $objects, 'term_id' ) );

		// A post's own terms are never filtered, otherwise the scope check would see nothing.
		$post_id = $this->make_minutes();
		wp_set_current_user( 0 );
		wp_set_object_terms( $post_id, [ $f['area_b'] ], BMLT_Minutes::TAX_COMMITTEE );
		wp_set_current_user( $f['user'] );
		$this->assertSame( [ $f['area_b'] ], wp_get_object_terms( $post_id, BMLT_Minutes::TAX_COMMITTEE, [ 'fields' => 'ids' ] ) );

		set_current_screen( 'front' );
		wp_set_current_user( 0 );
	}

	public function test_term_listing_is_not_narrowed_on_frontend(): void {
		$f = $this->make_scoped_fixture();
		wp_set_current_user( $f['user'] );
		set_current_screen( 'front' );

		$ids = get_terms(
			[
				'taxonomy'   => BMLT_Minutes::TAX_COMMITTEE,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		$this->assertContains( $f['area_b'], $ids );
		wp_set_current_user( 0 );
	}

	public function test_out_of_scope_terms_are_stripped_on_assignment(): void {
		$f       = $this->make_scoped_fixture();
		$post_id = $this->make_minutes( [ 'post_author' => $f['user'] ] );
		wp_set_current_user( $f['user'] );

		wp_set_object_terms( $post_id, [ $f['area_a'], $f['area_b'] ], BMLT_Minutes::TAX_COMMITTEE );

		$this->assertSame( [ $f['area_a'] ], wp_get_object_terms( $post_id, BMLT_Minutes::TAX_COMMITTEE, [ 'fields' => 'ids' ] ) );
		wp_set_current_user( 0 );
	}

	public function test_rest_publish_requires_in_scope_committee(): void {
		$f = $this->make_scoped_fixture();
		wp_set_current_user( $f['user'] );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . BMLT_Minutes::CPT );
		$request->set_param( 'title', 'Area minutes' );
		$request->set_param( 'status', 'publish' );

		$request->set_param( BMLT_Minutes::TAX_COMMITTEE, [ $f['area_b'] ] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'bmlt_minutes_committee_required', $response->as_error()->get_error_code() );

		$request->set_param( BMLT_Minutes::TAX_COMMITTEE, [] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status(), 'Publishing with no committee at all must be refused for scoped users.' );

		$request->set_param( BMLT_Minutes::TAX_COMMITTEE, [ $f['area_a_hi'], $f['area_b'] ] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );
		$post_id = (int) $response->get_data()['id'];
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( [ $f['area_a_hi'] ], wp_get_object_terms( $post_id, BMLT_Minutes::TAX_COMMITTEE, [ 'fields' => 'ids' ] ), 'The out-of-scope committee must be dropped even when sent alongside a valid one.' );

		wp_set_current_user( 0 );
	}

	public function test_rest_publish_is_unrestricted_for_unscoped_user(): void {
		$f     = $this->make_scoped_fixture();
		$other = self::factory()->user->create( [ 'role' => BMLT_Minutes::ROLE_MANAGER ] );
		wp_set_current_user( $other );

		$request = new WP_REST_Request( 'POST', '/wp/v2/' . BMLT_Minutes::CPT );
		$request->set_param( 'title', 'Anywhere' );
		$request->set_param( 'status', 'publish' );
		$request->set_param( BMLT_Minutes::TAX_COMMITTEE, [ $f['area_b'] ] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );

		wp_set_current_user( 0 );
	}

	public function test_classic_publish_without_in_scope_committee_is_demoted_to_draft(): void {
		$f = $this->make_scoped_fixture();
		wp_set_current_user( $f['user'] );

		$post_id = wp_insert_post(
			[
				'post_type'   => BMLT_Minutes::CPT,
				'post_title'  => 'Forgot the committee',
				'post_status' => 'publish',
				'post_author' => $f['user'],
				'tax_input'   => [ BMLT_Minutes::TAX_COMMITTEE => [ '0', (string) $f['area_b'] ] ],
			]
		);
		$this->assertSame( 'draft', get_post_status( $post_id ) );
		$this->assertSame( [], wp_get_object_terms( $post_id, BMLT_Minutes::TAX_COMMITTEE, [ 'fields' => 'ids' ] ) );
		$this->assertStringContainsString( 'bmlt_minutes_committee_required=1', BMLT_Minutes::scope_redirect_notice( 'post.php?post=1&action=edit' ) );

		$post_id = wp_insert_post(
			[
				'post_type'   => BMLT_Minutes::CPT,
				'post_title'  => 'Filed correctly',
				'post_status' => 'publish',
				'post_author' => $f['user'],
				'tax_input'   => [ BMLT_Minutes::TAX_COMMITTEE => [ '0', (string) $f['area_a'] ] ],
			]
		);
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		wp_set_current_user( 0 );
	}

	public function test_admin_list_is_narrowed_to_scope(): void {
		$f = $this->make_scoped_fixture();

		$in_scope = $this->make_minutes( [ 'post_title' => 'Area A minutes' ] );
		wp_set_object_terms( $in_scope, [ $f['area_a_hi'] ], BMLT_Minutes::TAX_COMMITTEE );
		$outside = $this->make_minutes( [ 'post_title' => 'Area B minutes' ] );
		wp_set_object_terms( $outside, [ $f['area_b'] ], BMLT_Minutes::TAX_COMMITTEE );
		$uncategorized = $this->make_minutes( [ 'post_title' => 'No committee' ] );

		wp_set_current_user( $f['user'] );
		set_current_screen( 'edit-' . BMLT_Minutes::CPT );

		$ids = $this->run_admin_list_query();
		$this->assertContains( $in_scope, $ids );
		$this->assertContains( $uncategorized, $ids );
		$this->assertNotContains( $outside, $ids );

		set_current_screen( 'front' );
		wp_set_current_user( 0 );
	}

	public function test_admin_list_is_not_narrowed_for_unscoped_user(): void {
		$f       = $this->make_scoped_fixture();
		$outside = $this->make_minutes( [ 'post_title' => 'Area B minutes' ] );
		wp_set_object_terms( $outside, [ $f['area_b'] ], BMLT_Minutes::TAX_COMMITTEE );
		$other = self::factory()->user->create( [ 'role' => BMLT_Minutes::ROLE_MANAGER ] );

		wp_set_current_user( $other );
		set_current_screen( 'edit-' . BMLT_Minutes::CPT );

		$ids = $this->run_admin_list_query();
		$this->assertContains( $outside, $ids );

		set_current_screen( 'front' );
		wp_set_current_user( 0 );
	}

	public function test_profile_save_stores_valid_committees_and_clears_when_empty(): void {
		$f        = $this->make_scoped_fixture();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$_POST = [
			BMLT_Minutes::USER_CAP_NONCE_FIELD => wp_create_nonce( BMLT_Minutes::USER_CAP_NONCE_ACTION ),
			'bmlt_minutes_committees'          => [ (string) $f['area_b'], '999999', 'junk', (string) $f['area_b'] ],
		];
		BMLT_Minutes::save_user_capability_field( $f['user'] );
		$this->assertSame( [ $f['area_b'] ], BMLT_Minutes::selected_committees( $f['user'] ) );

		$_POST = [
			BMLT_Minutes::USER_CAP_NONCE_FIELD => wp_create_nonce( BMLT_Minutes::USER_CAP_NONCE_ACTION ),
		];
		BMLT_Minutes::save_user_capability_field( $f['user'] );
		$this->assertSame( [], BMLT_Minutes::selected_committees( $f['user'] ) );
		$this->assertNull( BMLT_Minutes::committee_scope( $f['user'] ) );

		$_POST = [];
		wp_set_current_user( 0 );
	}

	public function test_profile_save_requires_promote_users_for_scope(): void {
		$f         = $this->make_scoped_fixture();
		$author_id = self::factory()->user->create( [ 'role' => 'author' ] );
		wp_set_current_user( $author_id );

		$_POST = [
			BMLT_Minutes::USER_CAP_NONCE_FIELD => wp_create_nonce( BMLT_Minutes::USER_CAP_NONCE_ACTION ),
			'bmlt_minutes_committees'          => [ (string) $f['area_b'] ],
		];
		BMLT_Minutes::save_user_capability_field( $f['user'] );
		$this->assertSame( [ $f['area_a'] ], BMLT_Minutes::selected_committees( $f['user'] ) );

		$_POST = [];
		wp_set_current_user( 0 );
	}

	public function test_profile_field_renders_committee_checklist(): void {
		$f        = $this->make_scoped_fixture();
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		ob_start();
		BMLT_Minutes::render_user_capability_field( get_user_by( 'id', $f['user'] ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Committee Scope', $html );
		$this->assertMatchesRegularExpression( '/value="' . $f['area_a'] . '"\s+checked=/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="' . $f['area_b'] . '"\s+checked=/', $html );
		$this->assertStringContainsString( 'value="' . $f['area_b'] . '"', $html );
		$this->assertStringContainsString( 'Area A H&amp;I', $html );

		wp_set_current_user( 0 );
	}

	public function test_render_shortcode_groups_nested_committees_by_full_path(): void {
		$f = $this->make_scoped_fixture();
		wp_insert_term( 'Area B H&I', BMLT_Minutes::TAX_COMMITTEE, [ 'parent' => $f['area_b'] ] );
		$area_b_hi = get_term_by( 'name', 'Area B H&I', BMLT_Minutes::TAX_COMMITTEE )->term_id;

		$post_a = $this->make_minutes( [ 'post_title' => 'A HI minutes' ] );
		wp_set_object_terms( $post_a, [ $f['area_a_hi'] ], BMLT_Minutes::TAX_COMMITTEE );
		$post_b = $this->make_minutes( [ 'post_title' => 'B HI minutes' ] );
		wp_set_object_terms( $post_b, [ $area_b_hi ], BMLT_Minutes::TAX_COMMITTEE );

		$html = do_shortcode( '[bmlt_minutes]' );

		$this->assertStringContainsString( 'Area A / Area A H&amp;I', $html );
		$this->assertStringContainsString( 'Area B / Area B H&amp;I', $html );
	}
}
