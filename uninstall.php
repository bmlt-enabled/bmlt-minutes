<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$minutes_options = [
	'bmlt_minutes_server',
	'bmlt_minutes_service_body',
	'bmlt_minutes_default_committees',
	'bmlt_minutes_sort_order',
	'bmlt_minutes_show_uploader',
	'bmlt_minutes_max_upload_mb',
];

foreach ( $minutes_options as $opt ) {
	delete_option( $opt );
}

$posts = get_posts(
	[
		'post_type'   => 'bmlt_minutes',
		'numberposts' => -1,
		'post_status' => 'any',
		'fields'      => 'ids',
	]
);

foreach ( $posts as $post_id ) {
	wp_delete_post( $post_id, true );
}
