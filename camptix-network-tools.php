<?php
/*
* Plugin Name: CampTix Network Tools
* Plugin URI: http://wordcamp.org
* Description: Tools for managing CampTix installations across a WordPress Multisite network.
* Version: 0.1
* Author: Automattic
* Author URI: http://wordcamp.org
* License: GPLv2 or later
* Network: true
*/

class CampTix_Network_Tools {
	private $options;
	private $db_version = 20130618;
	const PLUGIN_URL = "http://wordpress.org/plugins/camptix-network-tools";

	function __construct() {
		add_action( 'init',             array( $this, 'init' ) );
		add_action( 'camptix_pre_init', array( $this, 'camptix_pre_init' ) );
		add_action( 'camptix_init',     array( $this, 'camptix_init' ) );
	}

	function init() {
		$this->options = array_merge( array(
			'db_version' => 0,
		), get_site_option( 'camptix_nt_options', array() ) );
		$this->options = $this->validate_options( $this->options );

		if ( $this->options['db_version'] != $this->db_version ) {
			$this->upgrade();
			update_site_option( 'camptix_nt_options', $this->options );
		}
	}

	function validate_options( $options ) {
		$options['db_version'] = absint( $options['db_version'] );

		return $options;
	}

	function upgrade() {
		global $wpdb;

		$charset_collate = '';
		if ( ! empty( $wpdb->charset ) )
			$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
		if ( ! empty( $wpdb->collate ) )
			$charset_collate .= " COLLATE $wpdb->collate";

		$table_name = $wpdb->base_prefix . "camptix_log";
		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			timestamp timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
			blog_id bigint(20) NOT NULL,
			object_id bigint(20) NOT NULL,
			message text NOT NULL,
			section varchar(32) DEFAULT 'general',
			data text NOT NULL,
			UNIQUE KEY id (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
		$this->options['db_version'] = $this->db_version;
	}

	function camptix_pre_init() {
		add_action( 'camptix_log_raw',        array( $this, 'camptix_log_raw' ), 10, 4 );
		add_action( 'camptix_log_raw',        array( $this, 'camptix_log_email_notifications' ), 10, 4 );
		add_filter( 'camptix_default_addons', array( $this, 'camptix_default_addons' ) );
	}

	function camptix_init() {
		add_action( 'camptix_add_meta_boxes', array( $this, 'camptix_add_meta_boxes' ), 11 );
	}

	// Disable logging to postmeta tables since we'll be logging to a dedicated, global table instead
	function camptix_default_addons( $addons ) {
		unset( $addons['logging-meta'] );
		return $addons;
	}

	function camptix_add_meta_boxes() {
		$post_types = array(
			'tix_attendee',
			'tix_ticket',
			'tix_email',
			'tix_coupon',
		);
		foreach ( $post_types as $post_type )
			add_meta_box( 'tix_db_log', 'CampTix DB Log', array( $this, 'metabox_log' ), $post_type, 'normal' );
	}

	/**
	 * CampTix Log metabox for various post types.
	 */
	function metabox_log() {
		global $post, $camptix, $wpdb;

		if ( ! get_current_blog_id() || ! $post->ID )
			return;

		$rows = array();
		$table_name = $wpdb->base_prefix . "camptix_log";
		$entries = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_name WHERE blog_id = %d AND object_id = %d ORDER BY id ASC;", get_current_blog_id(), $post->ID ) );

		// Add entries as rows.
		foreach ( $entries as $entry ) {
			$message = esc_html( $entry->message );
			$data = json_decode( $entry->data );
			if ( $data ) {
				$message .= ' <a href="#" class="tix-more-bytes">data</a>';
				$message .= '<pre class="tix-bytes" style="display: none;">' . esc_html( print_r( $data, true ) ) . '</pre>';
			}
			$rows[] = array( date( 'Y-m-d H:i:s', strtotime( $entry->timestamp ) ), $message );
		}

		if ( count( $rows ) < 1 )
			$rows[] = array( 'No log entries yet.', '' );

		$camptix->table( $rows, 'tix-log-table' );
		?>

		<p class="description">Note: Some relevant log entries may not be displayed here. Check the global error log to get a complete picture of activity.</p>

		<script>
			jQuery('.tix-more-bytes').click(function() {
				jQuery(this).parent().find('.tix-bytes').toggle();
				return false;
			});
		</script>

	<?php
	}

	/**
	 * Logs to a db
	 */
	function camptix_log_raw( $message, $post_id, $data, $section = 'general' ) {
		global $wpdb, $blog_id, $camptix;

		$table_name = $wpdb->base_prefix . "camptix_log";
		$wpdb->insert( $table_name, array(
			'blog_id' => $blog_id,
			'object_id' => $post_id,
			'message' => $message,
			'data' => json_encode( stripslashes_deep( $data ) ),
			'section' => $section,
		) );
		$camptix->tmp( 'last_log_id', $wpdb->insert_id );

		$entry = array(
			'url' => home_url(),
			'timestamp' => time(),
			'message' => $message,
			'data' => $data,
			'module' => $section,
		);

		if ( $post_id ) {
			$entry['post_id'] = absint( $post_id );
			$entry['edit_post_link'] = esc_url_raw( add_query_arg( array( 'post' => absint( $post_id ), 'action' => 'edit' ), admin_url( 'post.php' ) ) );
		}

		// Error-log log
		if ( isset( $entry['message'] ) ) {
			$url = parse_url( home_url() );
			$prefix = sprintf( 'CampTix (%s): ', $url['host'] );
			error_log( $prefix . $entry['message'] );
		}
	}

	/*
	 * Sends e-mail notifications on log events that match pre-defined regular expressions
	 */
	function camptix_log_email_notifications( $message, $post_id, $data, $section ) {
		global $camptix;

		$expressions = apply_filters( 'camptix_nt_notification_expressions', array() );

		if ( $expressions ) {
			foreach ( $expressions as $expression => $addresses ) {
				if ( preg_match( $expression, $message .' '. print_r( $data, true ) ) ) {
					$email_body = sprintf(
						"The following CampTix log entry matches an expression you've subscribed to.\n\nSite: %s\nMessage: %s\nRegular Expression: %s\nTimestamp: %s\n\nMore information is available in the Network Log: <%s>",
						get_bloginfo( 'name' ),
						esc_html( $message ),
						esc_html( $expression ),
						date( 'Y-m-d H:i:s' ),	// assumes MySQL timezone matches PHP timezone, and that next clock tick hasn't occurred after record insertion
						add_query_arg(
							array(
								'tix_section' => 'log',
								'page' => 'camptix-dashboard',
								's' => 'id:' . absint( $camptix->tmp( 'last_log_id' ) ),
								'dashboard_log_search_logs_nonce' => wp_create_nonce( 'dashboard_log_search_logs' ),
							),
							get_admin_url()
						)	// assumes recipient has access to Network Log
					);

					wp_mail( $addresses, 'CampTix Log Notification', $email_body );
					break;
				}
			}
		}
	}

	function __destruct() {
		if ( isset( $this->log_file ) && $this->log_file )
			fclose( $this->log_file );
	}
}

$GLOBALS['camptix_network_tools'] = new CampTix_Network_Tools();
require_once( plugin_dir_path( __FILE__ ) . 'network-dashboard.php' );