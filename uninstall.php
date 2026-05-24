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
