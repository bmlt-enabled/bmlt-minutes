<?php
/**
 * Plugin Name: Minutes
 * Plugin URI: https://wordpress.org/plugins/minutes/
 * Description: Publish NA service committee meeting minutes (PDF, DOCX, XLSX, Google Doc links) with a simple shortcode.
 * Version: 1.0.0
 * Author: bmltenabled
 * Author URI: https://bmlt.app
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: minutes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMLT_MINUTES_VERSION', '1.0.0' );
define( 'BMLT_MINUTES_FILE', __FILE__ );
define( 'BMLT_MINUTES_URL', plugin_dir_url( __FILE__ ) );
define( 'BMLT_MINUTES_PATH', plugin_dir_path( __FILE__ ) );

class BMLT_Minutes {

	private static ?self $instance = null;

	const CPT             = 'bmlt_minutes';
	const TAX_COMMITTEE   = 'bmlt_committee';
	const META_DATE       = '_bmlt_minutes_date';
	const META_URL        = '_bmlt_minutes_url';
	const META_ATTACHMENT = '_bmlt_minutes_attachment_id';
	const NONCE_FIELD     = 'bmlt_minutes_meta_nonce';
	const NONCE_ACTION    = 'bmlt_minutes_meta_save';

	const DEFAULT_COMMITTEES = [
		'Area Service Committee',
		'Regional Service Committee',
		'Hospitals & Institutions',
		'Public Relations',
		'Activities',
		'Outreach',
		'Literature',
		'Policy',
	];

	const ALLOWED_EXTENSIONS = [ 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'txt', 'rtf', 'csv' ];

	const OPTION_MAX_UPLOAD_MB = 'bmlt_minutes_max_upload_mb';
	const DEFAULT_MAX_UPLOAD_MB = 10;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ static::class, 'register_cpt' ] );
		add_action( 'init', [ static::class, 'register_taxonomy' ] );
		add_action( 'init', [ static::class, 'register_post_meta' ] );

		add_action( 'add_meta_boxes', [ static::class, 'add_meta_boxes' ] );
		add_action( 'save_post_' . self::CPT, [ static::class, 'save_meta' ], 10, 2 );
		add_filter( 'wp_insert_post_data', [ static::class, 'apply_password_field' ], 10, 2 );

		add_filter( 'the_content', [ static::class, 'append_document_link' ] );

		add_action( 'admin_enqueue_scripts', [ static::class, 'admin_assets' ] );
		add_action( 'wp_enqueue_scripts', [ static::class, 'frontend_assets' ] );

		add_action( 'admin_menu', [ static::class, 'admin_menu' ] );
		add_action( 'admin_init', [ static::class, 'register_settings' ] );

		add_filter( 'manage_' . self::CPT . '_posts_columns', [ static::class, 'admin_columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ static::class, 'admin_column_content' ], 10, 2 );
		add_filter( 'manage_edit-' . self::CPT . '_sortable_columns', [ static::class, 'admin_sortable_columns' ] );
		add_action( 'pre_get_posts', [ static::class, 'admin_orderby_meeting_date' ] );

		add_shortcode( 'minutes', [ static::class, 'render_shortcode' ] );

		add_filter( 'upload_size_limit', [ static::class, 'cap_upload_size' ] );
		add_filter( 'wp_handle_upload_prefilter', [ static::class, 'reject_oversize_upload' ] );

		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ static::class, 'settings_link' ] );
	}

	// -------------------------------------------------------------------------
	// Activation
	// -------------------------------------------------------------------------

	public static function activate(): void {
		self::register_cpt();
		self::register_taxonomy();
		self::seed_committees();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	private static function seed_committees(): void {
		$existing = get_terms(
			[
				'taxonomy'   => self::TAX_COMMITTEE,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);
		if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
			return;
		}
		foreach ( self::DEFAULT_COMMITTEES as $name ) {
			if ( ! term_exists( $name, self::TAX_COMMITTEE ) ) {
				wp_insert_term( $name, self::TAX_COMMITTEE );
			}
		}
	}

	// -------------------------------------------------------------------------
	// CPT, Taxonomy, Meta
	// -------------------------------------------------------------------------

	public static function register_cpt(): void {
		register_post_type(
			self::CPT,
			[
				'labels'        => [
					'name'               => __( 'Meeting Minutes', 'minutes' ),
					'singular_name'      => __( 'Meeting Minutes', 'minutes' ),
					'add_new'            => __( 'Add New', 'minutes' ),
					'add_new_item'       => __( 'Add New Minutes', 'minutes' ),
					'edit_item'          => __( 'Edit Minutes', 'minutes' ),
					'new_item'           => __( 'New Minutes', 'minutes' ),
					'view_item'          => __( 'View Minutes', 'minutes' ),
					'search_items'       => __( 'Search Minutes', 'minutes' ),
					'not_found'          => __( 'No minutes found.', 'minutes' ),
					'not_found_in_trash' => __( 'No minutes found in trash.', 'minutes' ),
					'menu_name'          => __( 'Minutes', 'minutes' ),
				],
				'public'        => true,
				'show_ui'       => true,
				'show_in_menu'  => true,
				'show_in_rest'  => true,
				'menu_position' => 25,
				'menu_icon'     => 'dashicons-media-document',
				'supports'      => [ 'title', 'editor', 'author', 'revisions', 'excerpt' ],
				'has_archive'   => true,
				'rewrite'       => [ 'slug' => 'minutes' ],
				'capability_type' => 'post',
			]
		);
	}

	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAX_COMMITTEE,
			self::CPT,
			[
				'labels'            => [
					'name'          => __( 'Committees', 'minutes' ),
					'singular_name' => __( 'Committee', 'minutes' ),
					'menu_name'     => __( 'Committees', 'minutes' ),
					'add_new_item'  => __( 'Add New Committee', 'minutes' ),
					'edit_item'     => __( 'Edit Committee', 'minutes' ),
				],
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => [ 'slug' => 'committee' ],
			]
		);
	}

	public static function register_post_meta(): void {
		register_post_meta(
			self::CPT,
			self::META_DATE,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => [ static::class, 'sanitize_date' ],
				'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
			]
		);
		register_post_meta(
			self::CPT,
			self::META_URL,
			[
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
			]
		);
		register_post_meta(
			self::CPT,
			self::META_ATTACHMENT,
			[
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn() => current_user_can( 'edit_posts' ),
			]
		);
	}

	public static function sanitize_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		// Accept YYYY-MM-DD only; anything else gets normalized via strtotime.
		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}

	// -------------------------------------------------------------------------
	// Admin: Meta Box
	// -------------------------------------------------------------------------

	public static function add_meta_boxes(): void {
		add_meta_box(
			'bmlt_minutes_document',
			__( 'Minutes Document', 'minutes' ),
			[ static::class, 'render_meta_box' ],
			self::CPT,
			'normal',
			'high'
		);
	}

	public static function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$date          = (string) get_post_meta( $post->ID, self::META_DATE, true );
		$url           = (string) get_post_meta( $post->ID, self::META_URL, true );
		$attachment_id = (int) get_post_meta( $post->ID, self::META_ATTACHMENT, true );

		$attachment_url      = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
		$attachment_filename = $attachment_id ? basename( (string) get_attached_file( $attachment_id ) ) : '';
		$password            = (string) $post->post_password;
		?>
		<table class="form-table">
			<tr>
				<th scope="row"><label for="bmlt_minutes_date"><?php esc_html_e( 'Meeting Date', 'minutes' ); ?></label></th>
				<td>
					<input type="date" id="bmlt_minutes_date" name="bmlt_minutes_date"
						   value="<?php echo esc_attr( $date ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'The date the meeting took place (used for sorting and grouping).', 'minutes' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Attached File', 'minutes' ); ?></th>
				<td>
					<input type="hidden" id="bmlt_minutes_attachment_id" name="bmlt_minutes_attachment_id"
						   value="<?php echo esc_attr( (string) $attachment_id ); ?>" />
					<div id="bmlt_minutes_attachment_preview" style="margin-bottom:8px;">
						<?php if ( $attachment_id && $attachment_url ) : ?>
							<span class="dashicons dashicons-media-document" style="vertical-align:middle;"></span>
							<a href="<?php echo esc_url( $attachment_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $attachment_filename ); ?></a>
						<?php else : ?>
							<em><?php esc_html_e( 'No file selected.', 'minutes' ); ?></em>
						<?php endif; ?>
					</div>
					<button type="button" class="button" id="bmlt_minutes_pick_file"><?php esc_html_e( 'Upload / Select File', 'minutes' ); ?></button>
					<button type="button" class="button" id="bmlt_minutes_clear_file" <?php disabled( ! $attachment_id ); ?>><?php esc_html_e( 'Remove File', 'minutes' ); ?></button>
					<p class="description">
						<?php esc_html_e( 'PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, ODT, ODS, TXT, RTF, CSV.', 'minutes' ); ?>
						<?php
						/* translators: %s: maximum upload size, e.g. "10 MB" */
						printf( esc_html__( 'Maximum file size: %s.', 'minutes' ), esc_html( size_format( self::max_upload_bytes() ) ) );
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt_minutes_url"><?php esc_html_e( 'External Link', 'minutes' ); ?></label></th>
				<td>
					<input type="url" id="bmlt_minutes_url" name="bmlt_minutes_url"
						   value="<?php echo esc_attr( $url ); ?>"
						   class="large-text" placeholder="https://docs.google.com/document/d/..." />
					<p class="description"><?php esc_html_e( 'Alternative to an uploaded file — e.g. a Google Doc, Dropbox, or OneDrive link. If both are set, the uploaded file takes priority.', 'minutes' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bmlt_minutes_password"><?php esc_html_e( 'Password Protection', 'minutes' ); ?></label></th>
				<td>
					<input type="text" id="bmlt_minutes_password" name="bmlt_minutes_password"
						   value="<?php echo esc_attr( $password ); ?>"
						   class="regular-text" autocomplete="off"
						   placeholder="<?php esc_attr_e( 'Leave blank for public access', 'minutes' ); ?>" />
					<p class="description">
						<?php esc_html_e( 'Optional. If set, visitors must enter this password to view the document. Useful for minutes containing personal details that have not been redacted. Share the password with members through your usual channels.', 'minutes' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_meta( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['bmlt_minutes_date'] ) ) {
			$date = self::sanitize_date( sanitize_text_field( wp_unslash( $_POST['bmlt_minutes_date'] ) ) );
			if ( '' === $date ) {
				delete_post_meta( $post_id, self::META_DATE );
			} else {
				update_post_meta( $post_id, self::META_DATE, $date );
			}
		}

		if ( isset( $_POST['bmlt_minutes_url'] ) ) {
			$url = esc_url_raw( wp_unslash( $_POST['bmlt_minutes_url'] ) );
			if ( '' === $url ) {
				delete_post_meta( $post_id, self::META_URL );
			} else {
				update_post_meta( $post_id, self::META_URL, $url );
			}
		}

		if ( isset( $_POST['bmlt_minutes_attachment_id'] ) ) {
			$attachment_id = absint( wp_unslash( $_POST['bmlt_minutes_attachment_id'] ) );
			if ( 0 === $attachment_id ) {
				delete_post_meta( $post_id, self::META_ATTACHMENT );
			} else {
				update_post_meta( $post_id, self::META_ATTACHMENT, $attachment_id );
			}
		}
	}

	/**
	 * Apply the password from our meta-box field before the post is written.
	 * Runs on wp_insert_post_data so the value lands in wp_posts.post_password
	 * without a second update or an infinite save_post loop.
	 */
	public static function apply_password_field( array $data, array $postarr ): array {
		if ( ! isset( $data['post_type'] ) || self::CPT !== $data['post_type'] ) {
			return $data;
		}
		if ( ! isset( $postarr['bmlt_minutes_password'], $postarr[ self::NONCE_FIELD ] ) ) {
			return $data;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $postarr[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return $data;
		}
		$data['post_password'] = sanitize_text_field( wp_unslash( $postarr['bmlt_minutes_password'] ) );
		return $data;
	}

	// -------------------------------------------------------------------------
	// Admin: List Table Columns
	// -------------------------------------------------------------------------

	public static function admin_columns( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['bmlt_minutes_date'] = __( 'Meeting Date', 'minutes' );
				$new['bmlt_minutes_file'] = __( 'File / Link', 'minutes' );
			}
		}
		return $new;
	}

	public static function admin_column_content( string $column, int $post_id ): void {
		if ( 'bmlt_minutes_date' === $column ) {
			$date = (string) get_post_meta( $post_id, self::META_DATE, true );
			echo $date ? esc_html( mysql2date( get_option( 'date_format', 'F j, Y' ), $date, true ) ) : '—';
			return;
		}
		if ( 'bmlt_minutes_file' === $column ) {
			[ $url, $label, $type ] = self::resolve_document( $post_id );
			if ( '' === $url ) {
				echo '—';
				return;
			}
			printf(
				'<a href="%s" target="_blank" rel="noopener"><span class="dashicons %s" style="vertical-align:middle"></span> %s</a>',
				esc_url( $url ),
				esc_attr( self::dashicon_for_type( $type ) ),
				esc_html( $label )
			);
		}
	}

	public static function admin_sortable_columns( array $columns ): array {
		$columns['bmlt_minutes_date'] = 'bmlt_minutes_date';
		return $columns;
	}

	public static function admin_orderby_meeting_date( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'bmlt_minutes_date' !== $query->get( 'orderby' ) ) {
			return;
		}
		$query->set( 'meta_key', self::META_DATE );
		$query->set( 'orderby', 'meta_value' );
	}

	// -------------------------------------------------------------------------
	// Document resolution
	// -------------------------------------------------------------------------

	/**
	 * Returns [url, label, type] for the document attached to a minutes post.
	 * Uploaded attachment takes precedence over external URL. type is the lowercase
	 * file extension when knowable, or 'link' for external URLs without one.
	 *
	 * @return array{0:string,1:string,2:string}
	 */
	public static function resolve_document( int $post_id ): array {
		$attachment_id = (int) get_post_meta( $post_id, self::META_ATTACHMENT, true );
		if ( $attachment_id ) {
			$url      = (string) wp_get_attachment_url( $attachment_id );
			$file     = (string) get_attached_file( $attachment_id );
			$filename = $file ? basename( $file ) : '';
			$ext      = $filename ? strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) : '';
			$label    = '' !== $filename ? $filename : $url;
			$type     = '' !== $ext ? $ext : 'file';
			return [ $url, $label, $type ];
		}
		$url = (string) get_post_meta( $post_id, self::META_URL, true );
		if ( '' === $url ) {
			return [ '', '', '' ];
		}
		$host_raw = wp_parse_url( $url, PHP_URL_HOST );
		$host     = is_string( $host_raw ) ? $host_raw : '';
		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ext      = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			$ext = self::external_host_type( $host );
		}
		$label = '' !== $host ? $host : $url;
		$type  = '' !== $ext ? $ext : 'link';
		return [ $url, $label, $type ];
	}

	private static function external_host_type( string $host ): string {
		$host = strtolower( $host );
		if ( str_contains( $host, 'docs.google.com' ) || str_contains( $host, 'drive.google.com' ) ) {
			return 'google';
		}
		if ( str_contains( $host, 'dropbox.com' ) ) {
			return 'dropbox';
		}
		if ( str_contains( $host, 'onedrive.live.com' ) || str_contains( $host, 'sharepoint.com' ) ) {
			return 'onedrive';
		}
		return 'link';
	}

	private static function dashicon_for_type( string $type ): string {
		return match ( $type ) {
			'pdf'               => 'dashicons-pdf',
			'doc', 'docx', 'odt', 'rtf' => 'dashicons-media-document',
			'xls', 'xlsx', 'ods', 'csv' => 'dashicons-media-spreadsheet',
			'ppt', 'pptx', 'odp' => 'dashicons-media-interactive',
			'txt'               => 'dashicons-media-text',
			'google'            => 'dashicons-google',
			default             => 'dashicons-admin-links',
		};
	}

	/**
	 * On single Minutes views, append the document link below the post content.
	 * When the post is password-protected, WordPress replaces the content with
	 * the password form upstream — this filter returns early so the doc link
	 * is only ever revealed after a correct password unlocks the post.
	 */
	public static function append_document_link( string $content ): string {
		if ( ! is_singular( self::CPT ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post_id = get_the_ID();
		if ( ! $post_id || post_password_required( $post_id ) ) {
			return $content;
		}
		[ $url, $label, $type ] = self::resolve_document( $post_id );
		if ( '' === $url ) {
			return $content;
		}
		wp_enqueue_style( 'minutes' );

		$date_raw = (string) get_post_meta( $post_id, self::META_DATE, true );
		$date_html = '';
		if ( '' !== $date_raw ) {
			$date_display = mysql2date( get_option( 'date_format', 'F j, Y' ), $date_raw, true );
			$date_html    = sprintf(
				'<p class="bmlt-minutes__single-date"><strong>%s</strong> %s</p>',
				esc_html__( 'Meeting Date:', 'minutes' ),
				esc_html( $date_display )
			);
		}

		$icon = self::dashicon_for_type( $type );
		$link_html = sprintf(
			'<p class="bmlt-minutes__single-link"><a class="bmlt-minutes__button" href="%s" target="_blank" rel="noopener"><span class="dashicons %s" aria-hidden="true"></span> %s</a></p>',
			esc_url( $url ),
			esc_attr( $icon ),
			esc_html__( 'View Document', 'minutes' )
		);

		return $date_html . $content . $link_html;
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public static function admin_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || self::CPT !== $screen->post_type ) {
			return;
		}
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script(
			'bmlt-minutes-admin',
			BMLT_MINUTES_URL . 'js/admin.js',
			[ 'jquery' ],
			BMLT_MINUTES_VERSION,
			true
		);
		wp_localize_script(
			'bmlt-minutes-admin',
			'BMLT_MINUTES_ADMIN',
			[
				'pickTitle'    => __( 'Select or upload minutes document', 'minutes' ),
				'pickButton'   => __( 'Use this file', 'minutes' ),
				'allowedExt'   => self::ALLOWED_EXTENSIONS,
				'noFileLabel'  => __( 'No file selected.', 'minutes' ),
				'maxUpload'    => self::max_upload_bytes(),
				/* translators: %s: maximum upload size, e.g. "10 MB" */
				'tooLargeMsg'  => sprintf( __( 'That file is larger than the %s limit for minutes uploads.', 'minutes' ), size_format( self::max_upload_bytes() ) ),
			]
		);
	}

	// -------------------------------------------------------------------------
	// Upload size cap (Minutes editor context only)
	// -------------------------------------------------------------------------

	public static function max_upload_bytes(): int {
		$mb = (int) get_option( self::OPTION_MAX_UPLOAD_MB, self::DEFAULT_MAX_UPLOAD_MB );
		if ( $mb < 1 ) {
			$mb = self::DEFAULT_MAX_UPLOAD_MB;
		}
		return $mb * 1024 * 1024;
	}

	public static function sanitize_max_upload_mb( $value ): int {
		$value = (int) $value;
		if ( $value < 1 ) {
			return self::DEFAULT_MAX_UPLOAD_MB;
		}
		// Cap at PHP / WP's hard upload limit if known, so the option can't promise
		// more than the server will actually accept.
		$server_limit_bytes = (int) wp_max_upload_size();
		if ( $server_limit_bytes > 0 ) {
			$server_limit_mb = (int) floor( $server_limit_bytes / ( 1024 * 1024 ) );
			if ( $server_limit_mb > 0 && $value > $server_limit_mb ) {
				return $server_limit_mb;
			}
		}
		return $value;
	}

	public static function cap_upload_size( $limit ) {
		$limit = (int) $limit;
		if ( ! self::is_minutes_upload_context() ) {
			return $limit;
		}
		return ( 0 === $limit || $limit > self::max_upload_bytes() ) ? self::max_upload_bytes() : $limit;
	}

	public static function reject_oversize_upload( array $file ): array {
		if ( ! self::is_minutes_upload_context() ) {
			return $file;
		}
		if ( isset( $file['size'] ) && (int) $file['size'] > self::max_upload_bytes() ) {
			$file['error'] = sprintf(
				/* translators: %s: maximum upload size, e.g. "10 MB" */
				__( 'File is too large. Minutes uploads are capped at %s.', 'minutes' ),
				size_format( self::max_upload_bytes() )
			);
		}
		return $file;
	}

	/**
	 * Detect whether the current request is an upload happening from the Minutes editor.
	 * Covers both regular admin screens (get_current_screen) and async-upload.php POSTs
	 * (where post_id / referrer point back at a Minutes post).
	 */
	private static function is_minutes_upload_context(): bool {
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && self::CPT === $screen->post_type ) {
				return true;
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only context detection; no state changes. The actual upload is gated by WP core's own nonces.
		if ( isset( $_REQUEST['post_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See note above.
			$post_id = absint( wp_unslash( $_REQUEST['post_id'] ) );
			if ( $post_id && self::CPT === get_post_type( $post_id ) ) {
				return true;
			}
		}
		if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$referer = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
			$query   = (string) wp_parse_url( $referer, PHP_URL_QUERY );
			if ( '' !== $query ) {
				parse_str( $query, $args );
				if ( isset( $args['post_type'] ) && self::CPT === $args['post_type'] ) {
					return true;
				}
				if ( isset( $args['post'] ) && self::CPT === get_post_type( absint( $args['post'] ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function frontend_assets(): void {
		wp_register_style(
			'minutes',
			BMLT_MINUTES_URL . 'css/minutes.css',
			[ 'dashicons' ],
			BMLT_MINUTES_VERSION
		);
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			[
				'committee'    => '',
				'year'         => '',
				'limit'        => -1,
				'order'        => 'desc',
				'group_by'     => 'committee',
				'show_excerpt' => 'false',
			],
			is_array( $atts ) ? $atts : [],
			'minutes'
		);

		wp_enqueue_style( 'minutes' );

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_tax_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A minutes archive inherently needs to filter by committee taxonomy and meeting-date meta. Site owners can add an index on _bmlt_minutes_date if their dataset is huge; for a typical service-body list this is fine.
		$args = [
			'post_type'      => self::CPT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['limit'],
			'meta_key'       => self::META_DATE,
			'orderby'        => 'meta_value',
			'order'          => 'asc' === strtolower( (string) $atts['order'] ) ? 'ASC' : 'DESC',
		];

		if ( '' !== $atts['committee'] ) {
			$slugs = array_filter( array_map( 'sanitize_title', array_map( 'trim', explode( ',', (string) $atts['committee'] ) ) ) );
			if ( ! empty( $slugs ) ) {
				$args['tax_query'] = [
					[
						'taxonomy' => self::TAX_COMMITTEE,
						'field'    => 'slug',
						'terms'    => $slugs,
					],
				];
			}
		}

		if ( '' !== $atts['year'] && ctype_digit( (string) $atts['year'] ) ) {
			$year                = (int) $atts['year'];
			$args['meta_query']  = [
				[
					'key'     => self::META_DATE,
					'value'   => [ sprintf( '%04d-01-01', $year ), sprintf( '%04d-12-31', $year ) ],
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				],
			];
		}
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_tax_query,WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		$query = new WP_Query( $args );
		if ( ! $query->have_posts() ) {
			return '<div class="bmlt-minutes bmlt-minutes--empty">' . esc_html__( 'No minutes published yet.', 'minutes' ) . '</div>';
		}

		$group_by    = in_array( (string) $atts['group_by'], [ 'committee', 'year', 'none' ], true ) ? (string) $atts['group_by'] : 'committee';
		$show_excerpt = filter_var( $atts['show_excerpt'], FILTER_VALIDATE_BOOLEAN );

		$groups = [];
		foreach ( $query->posts as $post ) {
			$key = match ( $group_by ) {
				'committee' => self::primary_committee_label( $post->ID ),
				'year'      => self::meeting_year( $post->ID ),
				default     => '',
			};
			$groups[ $key ][] = $post;
		}
		wp_reset_postdata();

		if ( 'committee' === $group_by ) {
			ksort( $groups );
		} elseif ( 'year' === $group_by ) {
			krsort( $groups );
		}

		ob_start();
		echo '<div class="bmlt-minutes">';
		foreach ( $groups as $heading => $posts ) {
			if ( 'none' !== $group_by && '' !== $heading ) {
				echo '<h3 class="bmlt-minutes__group">' . esc_html( $heading ) . '</h3>';
			}
			echo '<ul class="bmlt-minutes__list">';
			foreach ( $posts as $post ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_item() escapes all dynamic values internally via esc_html / esc_attr / esc_url.
				echo self::render_item( $post, $show_excerpt );
			}
			echo '</ul>';
		}
		echo '</div>';
		return (string) ob_get_clean();
	}

	private static function primary_committee_label( int $post_id ): string {
		$terms = get_the_terms( $post_id, self::TAX_COMMITTEE );
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			return __( 'Uncategorized', 'minutes' );
		}
		return (string) $terms[0]->name;
	}

	private static function meeting_year( int $post_id ): string {
		$date = (string) get_post_meta( $post_id, self::META_DATE, true );
		if ( '' === $date ) {
			$date = get_the_date( 'Y-m-d', $post_id );
		}
		$ts = strtotime( $date );
		return $ts ? gmdate( 'Y', $ts ) : __( 'Undated', 'minutes' );
	}

	private static function render_item( WP_Post $post, bool $show_excerpt ): string {
		$is_locked    = post_password_required( $post );
		$date_raw     = (string) get_post_meta( $post->ID, self::META_DATE, true );
		$date_display = $date_raw ? mysql2date( get_option( 'date_format', 'F j, Y' ), $date_raw, true ) : '';

		// When locked, route to the single permalink (where WP shows the password
		// form) and never expose the underlying file/URL or its type.
		if ( $is_locked ) {
			$href      = get_permalink( $post );
			$icon      = 'dashicons-lock';
			$ext_label = '';
			$external  = false;
			$item_cls  = 'bmlt-minutes__item bmlt-minutes__item--locked';
		} else {
			[ $url, $label, $type ] = self::resolve_document( $post->ID );
			$href      = '' !== $url ? $url : get_permalink( $post );
			$icon      = self::dashicon_for_type( $type );
			$ext_label = '' !== $type && 'link' !== $type ? strtoupper( $type ) : '';
			$external  = '' !== $url;
			$item_cls  = 'bmlt-minutes__item';
		}

		ob_start();
		?>
		<li class="<?php echo esc_attr( $item_cls ); ?>">
			<a class="bmlt-minutes__link" href="<?php echo esc_url( $href ); ?>" <?php echo $external ? 'target="_blank" rel="noopener"' : ''; ?>>
				<span class="bmlt-minutes__icon dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
				<span class="bmlt-minutes__title"><?php echo esc_html( get_the_title( $post ) ); ?></span>
				<?php if ( $is_locked ) : ?>
					<span class="bmlt-minutes__type bmlt-minutes__type--locked"><?php esc_html_e( 'Protected', 'minutes' ); ?></span>
				<?php elseif ( '' !== $ext_label ) : ?>
					<span class="bmlt-minutes__type"><?php echo esc_html( $ext_label ); ?></span>
				<?php endif; ?>
				<?php if ( '' !== $date_display ) : ?>
					<span class="bmlt-minutes__date"><?php echo esc_html( $date_display ); ?></span>
				<?php endif; ?>
			</a>
			<?php if ( $show_excerpt && ! $is_locked ) : ?>
				<?php $excerpt = get_the_excerpt( $post ); ?>
				<?php if ( '' !== $excerpt ) : ?>
					<div class="bmlt-minutes__excerpt"><?php echo esc_html( $excerpt ); ?></div>
				<?php endif; ?>
			<?php endif; ?>
		</li>
		<?php
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	public static function settings_link( array $links ): array {
		$url     = admin_url( 'edit.php?post_type=' . self::CPT . '&page=bmlt-minutes-settings' );
		$links[] = "<a href='{$url}'>Settings</a>";
		return $links;
	}

	public static function admin_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . self::CPT,
			__( 'Minutes Settings', 'minutes' ),
			__( 'Settings', 'minutes' ),
			'manage_options',
			'bmlt-minutes-settings',
			[ static::class, 'settings_page' ]
		);
	}

	public static function register_settings(): void {
		$group = 'bmlt-minutes-group';

		register_setting( $group, 'bmlt_minutes_server', 'esc_url_raw' );
		register_setting( $group, 'bmlt_minutes_service_body', 'sanitize_text_field' );
		register_setting( $group, 'bmlt_minutes_sort_order', 'sanitize_text_field' );
		register_setting(
			$group,
			self::OPTION_MAX_UPLOAD_MB,
			[
				'type'              => 'integer',
				'sanitize_callback' => [ static::class, 'sanitize_max_upload_mb' ],
				'default'           => self::DEFAULT_MAX_UPLOAD_MB,
			]
		);
	}

	public static function settings_page(): void {
		$server         = (string) get_option( 'bmlt_minutes_server', '' );
		$service_body   = (string) get_option( 'bmlt_minutes_service_body', '' );
		$sort_order     = (string) get_option( 'bmlt_minutes_sort_order', 'desc' );
		$max_upload_mb  = (int) get_option( self::OPTION_MAX_UPLOAD_MB, self::DEFAULT_MAX_UPLOAD_MB );
		$server_cap_mb  = (int) floor( wp_max_upload_size() / ( 1024 * 1024 ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Minutes Settings', 'minutes' ); ?></h1>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'bmlt-minutes-group' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><label for="bmlt_minutes_server"><?php esc_html_e( 'BMLT Server URL', 'minutes' ); ?></label></th>
						<td>
							<input type="url" id="bmlt_minutes_server" name="bmlt_minutes_server"
								   value="<?php echo esc_attr( $server ); ?>"
								   class="regular-text" placeholder="https://your-server/main_server/" />
							<p class="description"><?php esc_html_e( 'Optional. Used for service body lookups and documentation links.', 'minutes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmlt_minutes_service_body"><?php esc_html_e( 'Service Body ID', 'minutes' ); ?></label></th>
						<td>
							<input type="text" id="bmlt_minutes_service_body" name="bmlt_minutes_service_body"
								   value="<?php echo esc_attr( $service_body ); ?>"
								   class="regular-text" placeholder="42" />
							<p class="description"><?php esc_html_e( 'Optional. The BMLT service body this site represents (informational).', 'minutes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bmlt_minutes_sort_order"><?php esc_html_e( 'Default Sort Order', 'minutes' ); ?></label></th>
						<td>
							<select id="bmlt_minutes_sort_order" name="bmlt_minutes_sort_order">
								<option value="desc" <?php selected( $sort_order, 'desc' ); ?>><?php esc_html_e( 'Newest first', 'minutes' ); ?></option>
								<option value="asc" <?php selected( $sort_order, 'asc' ); ?>><?php esc_html_e( 'Oldest first', 'minutes' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Used by the [minutes] shortcode when no order attribute is provided.', 'minutes' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPTION_MAX_UPLOAD_MB ); ?>"><?php esc_html_e( 'Maximum Upload Size (MB)', 'minutes' ); ?></label></th>
						<td>
							<input type="number" min="1" step="1"
								   id="<?php echo esc_attr( self::OPTION_MAX_UPLOAD_MB ); ?>"
								   name="<?php echo esc_attr( self::OPTION_MAX_UPLOAD_MB ); ?>"
								   value="<?php echo esc_attr( (string) $max_upload_mb ); ?>"
								   class="small-text" />
							<?php esc_html_e( 'MB', 'minutes' ); ?>
							<p class="description">
								<?php esc_html_e( 'Per-file cap applied to uploads on the Minutes editor. Does not affect uploads elsewhere.', 'minutes' ); ?>
								<?php if ( $server_cap_mb > 0 ) : ?>
									<?php
									printf(
										/* translators: %d: server upload limit in MB */
										esc_html__( 'Your server allows up to %d MB; the cap will be clamped to that if set higher.', 'minutes' ),
										(int) $server_cap_mb
									);
									?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Shortcode Usage', 'minutes' ); ?></h2>
			<p><?php esc_html_e( 'Show all minutes, grouped by committee:', 'minutes' ); ?></p>
			<code>[minutes]</code>

			<p><?php esc_html_e( 'Only one committee, latest first, year heading:', 'minutes' ); ?></p>
			<code>[minutes committee="area-service-committee" group_by="year"]</code>

			<p><?php esc_html_e( 'Limit + show excerpt:', 'minutes' ); ?></p>
			<code>[minutes limit="10" group_by="none" show_excerpt="true"]</code>

			<h3><?php esc_html_e( 'Attributes', 'minutes' ); ?></h3>
			<ul style="list-style:disc;padding-left:20px;">
				<li><code>committee</code> — <?php esc_html_e( 'Slug or comma-separated slugs of Committee taxonomy terms to filter.', 'minutes' ); ?></li>
				<li><code>year</code> — <?php esc_html_e( 'Filter to a single year (uses Meeting Date).', 'minutes' ); ?></li>
				<li><code>limit</code> — <?php esc_html_e( 'Max items (-1 = no limit).', 'minutes' ); ?></li>
				<li><code>order</code> — <code>desc</code> <?php esc_html_e( '(newest first)', 'minutes' ); ?> | <code>asc</code></li>
				<li><code>group_by</code> — <code>committee</code> | <code>year</code> | <code>none</code></li>
				<li><code>show_excerpt</code> — <code>true</code> | <code>false</code></li>
			</ul>
		</div>
		<?php
	}
}

register_activation_hook( __FILE__, [ 'BMLT_Minutes', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BMLT_Minutes', 'deactivate' ] );
BMLT_Minutes::get_instance();
