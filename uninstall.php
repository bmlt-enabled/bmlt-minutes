<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$bmlt_minutes_options = [
	'bmlt_minutes_server',
	'bmlt_minutes_service_body',
	'bmlt_minutes_default_committees',
	'bmlt_minutes_sort_order',
	'bmlt_minutes_show_uploader',
	'bmlt_minutes_max_upload_mb',
];

foreach ( $bmlt_minutes_options as $bmlt_minutes_option ) {
	delete_option( $bmlt_minutes_option );
}

$bmlt_minutes_posts = get_posts(
	[
		'post_type'   => 'bmlt_minutes',
		'numberposts' => -1,
		'post_status' => 'any',
		'fields'      => 'ids',
	]
);

foreach ( $bmlt_minutes_posts as $bmlt_minutes_post_id ) {
	wp_delete_post( $bmlt_minutes_post_id, true );
}

// Remove the dedicated role and strip the custom capabilities from every role.
// Kept in sync with BMLT_Minutes::minutes_capabilities() / ROLE_MANAGER.
$bmlt_minutes_caps = [
	'edit_bmlt_minutes',
	'edit_others_bmlt_minutes',
	'edit_published_bmlt_minutes',
	'edit_private_bmlt_minutes',
	'publish_bmlt_minutes',
	'read_private_bmlt_minutes',
	'delete_bmlt_minutes',
	'delete_others_bmlt_minutes',
	'delete_published_bmlt_minutes',
	'delete_private_bmlt_minutes',
];

remove_role( 'minutes_manager' );

// Per-user committee scope (BMLT_Minutes::USER_META_COMMITTEES).
delete_metadata( 'user', 0, '_bmlt_minutes_committees', '', true );

foreach ( wp_roles()->roles as $bmlt_minutes_role_slug => $bmlt_minutes_role_data ) {
	$bmlt_minutes_role = get_role( $bmlt_minutes_role_slug );
	if ( ! $bmlt_minutes_role ) {
		continue;
	}
	foreach ( $bmlt_minutes_caps as $bmlt_minutes_cap ) {
		$bmlt_minutes_role->remove_cap( $bmlt_minutes_cap );
	}
}

// Documents [bmlt_minutes_latest] converted to HTML (BMLT_Minutes_Document_HTML::cached()), on every site.
$bmlt_minutes_drop_docs = static function (): void {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transients have no "delete by prefix" API; this runs once, on uninstall.
	$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( '_transient_bmlt_minutes_doc_' ) . '%' ) );
	foreach ( $names as $name ) {
		delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
	}
};
if ( is_multisite() ) {
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	) as $bmlt_minutes_site ) {
		switch_to_blog( (int) $bmlt_minutes_site );
		$bmlt_minutes_drop_docs();
		restore_current_blog();
	}
} else {
	$bmlt_minutes_drop_docs();
}
