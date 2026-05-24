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

foreach ( wp_roles()->roles as $bmlt_minutes_role_slug => $bmlt_minutes_role_data ) {
	$bmlt_minutes_role = get_role( $bmlt_minutes_role_slug );
	if ( ! $bmlt_minutes_role ) {
		continue;
	}
	foreach ( $bmlt_minutes_caps as $bmlt_minutes_cap ) {
		$bmlt_minutes_role->remove_cap( $bmlt_minutes_cap );
	}
}
