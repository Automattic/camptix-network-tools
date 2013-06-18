<?php

class CampTix_Network_Dashboard {

	protected $debug = false;

	function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'init', array( $this, 'init' ) );

		$this->schedule_events();
	}

	function schedule_events() {
		add_action( 'tix_dashboard_scheduled_hourly', array( $this, 'gather_events_data' ) );

		// wp_clear_scheduled_hook( 'tix_scheduled_hourly' );
		if ( ! wp_next_scheduled( 'tix_dashboard_scheduled_hourly' ) )
			wp_schedule_event( time(), 'hourly', 'tix_dashboard_scheduled_hourly' );
	}

	function gather_events_data() {
		global $wpdb;

		// Update timestamp.
		update_option( 'camptix_dashboard_timestamp', time() );

		// Remove old events.
		$events = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'tix_event';" );
		if ( is_array( $events ) && count( $events ) > 0 ) {
			$events_ids = implode( ',', $events );
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->postmeta}` WHERE post_id IN ( '%s' );", $events_ids ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->posts}` WHERE ID IN ( '%s' );", $events_ids ) );
		}

		$blogs = $wpdb->get_col( $wpdb->prepare( "SELECT blog_id FROM `{$wpdb->blogs}` WHERE site_id = %d LIMIT 1000;", $wpdb->siteid ) );
		foreach ( $blogs as $bid ) {
			switch_to_blog( $bid );

				$post = false;
				if ( in_array( 'camptix/camptix.php', (array) apply_filters( 'active_plugins', get_option( 'active_plugins', array() ) ) ) ) {

					$options = get_option( 'camptix_options' );

					$post = array(
						'post_type' => 'tix_event',
						'post_status' => 'publish',
						'post_title' => get_bloginfo( 'name' ),
					);

					$meta = array(
						'tix_options' => $options,
						'tix_home_url' => home_url(),
						'tix_admin_url' => admin_url(),
					);

					$stats = get_option( 'camptix_stats', array() );
					foreach ( $stats as $key => $value )
						$meta['tix_stats_' . $key] = $value;

					$meta['tix_earliest_start'] = null;
					$meta['tix_undefined_start'] = false;
					$meta['tix_latest_end'] = null;
					$meta['tix_undefined_end'] = false;

					// Let's take a look at the tickets.
					$paged = 1;
					while ( $tickets = get_posts( array(
						'post_type' => 'tix_ticket',
						'post_status' => 'publish',
						'paged' => $paged++,
						'posts_per_page' => 10,
					) ) ) {

						// Loop through tickets.
						foreach ( $tickets as $ticket ) {

							$start = get_post_meta( $ticket->ID, 'tix_start', true );
							$end = get_post_meta( $ticket->ID, 'tix_end', true );

							if ( strtotime( $start ) )
								if ( strtotime( $start ) < $meta['tix_earliest_start'] || ! $meta['tix_earliest_start'] )
									$meta['tix_earliest_start'] = strtotime( $start );

							if ( strtotime( $end ) )
								if ( strtotime( $end ) > $meta['tix_latest_end'] || ! $meta['tix_latest_end'] )
									$meta['tix_latest_end'] = strtotime( $end );

							if ( ! strtotime( $end ) )
								$meta['tix_undefined_end'] = true;

							if ( strtotime( $ticket->post_date ) < $meta['tix_earliest_start'] || ! $meta['tix_earliest_start'] )
								$meta['tix_earliest_start'] = strtotime( $ticket->post_date );

							if ( strtotime( $ticket->post_date ) > $meta['tix_latest_end'] || ! $meta['tix_latest_end'] )
								$meta['tix_latest_end'] = strtotime( $ticket->post_date );
						}
					}
					
					// Set latest end to 1 year from now for better sorting.
					if ( $meta['tix_undefined_end'] || ! $meta['tix_latest_end'] ) {
						$meta['tix_latest_end'] = time() + 60*60*24*356;
						$meta['tix_undefined_end'] = true;
					}
						
					if ( ! $meta['tix_earliest_start'] ) {
						$meta['tix_earliest_start'] = time() + 60*60*24*356;
						$meta['tix_undefined_start'] = true;
					}

					// Make note of archived sites
					$meta['tix_archived'] = ( isset( $options['archived'] ) && $options['archived'] ) ? 1 : 0;
				}

			restore_current_blog();

			if ( $post ) {
				$post_id = wp_insert_post( $post );
				if ( $post_id )
					foreach ( $meta as $meta_key => $meta_value )
						update_post_meta( $post_id, $meta_key, $meta_value );
			}
		}
	}

	function init() {
		register_post_type( 'tix_event', array(
			'labels' => array(
				'name' => 'Events',
				'singular_name' => 'Event',
				'add_new' => 'New Event',
				'add_new_item' => 'Add New Event',
				'edit_item' => 'Edit Event',
				'new_item' => 'New Event',
				'all_items' => 'Events',
				'view_item' => 'View Event',
				'search_items' => 'Search Events',
				'not_found' => 'No events found',
				'not_found_in_trash' => 'No events found in trash',
				'menu_name' => 'Events',
			),
			'public' => false,
			'query_var' => false,
			'publicly_queryable' => false,
			'show_ui' => true,
			'show_in_menu' => $this->debug,
			'supports' => array( 'title', 'custom-fields' ),
		) );
	}

	function admin_menu() {
		$dashboard = add_dashboard_page( 'CampTix Network Dashboard', 'CampTix', 'network_admin', 'camptix-dashboard', array( $this, 'render_dashboard' ) );
		add_action( 'load-' . $dashboard, array( $this, 'pre_render_dashboard' ) );
	}

	function pre_render_dashboard() {

		if ( 'overview' == $this->get_current_tab() ) {
			$this->init_list_tables();
			$this->list_table = new CampTix_Network_Dashboard_List_Table();
		}

		if ( 'log' == $this->get_current_tab() ) {
			$this->init_list_tables();
			$this->list_table = new CampTix_Network_Log_List_Table();
		}
		
		if ( 'attendees' == $this->get_current_tab() ) {
			$this->init_list_tables();
			$this->list_table = new CampTix_Network_Attendees_List_Table();
		}
	}

	function get_current_tab() {
		if ( isset( $_REQUEST['tix_section'] ) )
			return strtolower( $_REQUEST['tix_section'] );

		return 'overview';
	}

	/**
	 * Tabs for Tickets > Tools, outputs the markup.
	 */
	function render_dashboard_tabs() {
		$current_section = $this->get_current_tab();
		$sections = array(
			'overview' => 'Overview',
			'log' => 'Network Log',
			'txn_lookup' => 'Transactions',
			'attendees' => 'Attendees',
		);

		foreach ( $sections as $section_key => $section_caption ) {
			$active = $current_section === $section_key ? 'nav-tab-active' : '';
			$url = add_query_arg( array(
				'tix_section' => $section_key,
				'page' => 'camptix-dashboard',
			), admin_url( 'index.php' ) );
			echo '<a class="nav-tab ' . $active . '" href="' . esc_url( $url ) . '">' . esc_html( $section_caption ) . '</a>';
		}
	}

	function render_dashboard() {
		?>
		<div class="wrap">
			<?php screen_icon( 'tools' ); ?>
			<h2>CampTix Network Dashboard</h2>
			<?php settings_errors(); ?>
			<h3 class="nav-tab-wrapper"><?php $this->render_dashboard_tabs(); ?></h3>
			<div id="tix">
			<?php
				$section = $this->get_current_tab();
				if ( $section == 'overview' )
					$this->render_dashboard_overview();
				if ( $section == 'log' )
					$this->render_dashboard_log();
				if ( $section == 'txn_lookup' )
					$this->render_dashboard_txn_lookup();
				if ( $section == 'attendees' )
					$this->render_dashboard_attendees();
			?>
			</div>
		</div>
		<?php
	}

	function init_list_tables() {
		require_once ( plugin_dir_path( __FILE__ ) . 'includes/class-camptix-network-dashboard-list-table.php' );
		require_once ( plugin_dir_path( __FILE__ ) . 'includes/class-camptix-network-log-list-table.php' );
		require_once ( plugin_dir_path( __FILE__ ) . 'includes/class-camptix-network-attendees-list-table.php' );
	}

	function render_dashboard_overview() {
		$last_updated = date( 'Y-m-d H:i:s', get_option( 'camptix_dashboard_timestamp', 0 ) );
		$last_updated_ago = human_time_diff( get_option( 'camptix_dashboard_timestamp', 0 ), time() ) . ' ago';
		$this->list_table->prepare_items();
		?>
		<style>
		#tix_event {
			width: 25%;
		}
		.dashboard_page_camptix-dashboard td {
			padding: 8px;
		}
		.tix-tooltip {
			cursor: pointer;
		}
		</style>
		<?php /* $this->list_table->views(); */ ?>
		<form id="posts-filter" action="" method="get">
			<input type="hidden" name="page" value="camptix-dashboard" />
			<input type="hidden" name="tix_section" value="overview" />

			<?php $this->list_table->search_box( 'Search Events', 'events' ); ?>
			<?php $this->list_table->display(); ?>
		</form>
		<p class="description">Please note that the network report is cached and updated once every hour. Last updated: <acronym class="tix-tooltip" title="<?php echo esc_attr( $last_updated_ago ); ?>"><?php echo esc_html( $last_updated ); ?></acronym>.</p>
		<?php
	}

	function render_dashboard_log() {
		$this->list_table->prepare_items();
		?>
		<style>
		#tix_timestamp {
			width: 120px;
		}
		#tix_message {
			width: 60%;
		}
		.tix-tooltip {
			cursor: pointer;
		}
		.tix-network-log-actions::before {
			content: '§ ';
		}
		.tix-network-log-actions,
		.tix-network-log-actions a {
			color: #aaa;
		}
		.tix-network-log-actions a:hover,
		.tix-network-log-actions a:active,
		.tix-network-log-actions a:focus {
			color: #D54E21;
		}
		</style>
		<form id="posts-filter" action="" method="get">
			<input type="hidden" name="page" value="camptix-dashboard" />
			<input type="hidden" name="tix_section" value="log" />

			<?php $this->list_table->search_box( 'Search Logs', 'logs' ); ?>
			<?php $this->list_table->display(); ?>
		</form>
		<script>
		jQuery('.tix-more-bytes').click(function() {
			jQuery(this).parents('.tix_message').find('.tix-bytes').toggle();
			return false;
		});
		</script>
		<?php
	}
	
	function render_dashboard_txn_lookup() {
		$txn_id = isset( $_POST['tix_txn_id'] ) ? $_POST['tix_txn_id'] : false;
		$creds = isset( $_POST['tix_dashboard_credentials'] ) ? $_POST['tix_dashboard_credentials'] : false;
		?>
		<form method="POST">
			<input type="hidden" name="tix_dashboard_txn_lookup_submit" value="1" />		<?php // TODO nonces ?>
			<select name="tix_dashboard_credentials">
			<?php foreach ( $this->get_paypal_credentials() as $key => $value ) : ?>
				<option <?php selected( $creds, $key ); ?> value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $value['label'] ); ?></option>
			<?php endforeach; ?>
			</select>
			<input type="text" name="tix_txn_id" placeholder="Transaction ID" autocomplete="off" value="<?php echo esc_attr( $txn_id ); ?>" />
			<input type="submit" value="Lookup" class="button-primary" />
		</form>
		<?php
		$txn = false;
		if ( isset( $_POST['tix_dashboard_txn_lookup_submit'] ) && $txn_id && $creds ) {
			$credentials = $this->get_paypal_credentials();
			if ( ! isset( $credentials[$_POST['tix_dashboard_credentials']] ) )
				return;
				
			$credentials = $credentials[$_POST['tix_dashboard_credentials']];

			$payload = array(
				'METHOD' => 'GetTransactionDetails',
				'TRANSACTIONID' => $txn_id,
			);

			$txn = wp_parse_args( wp_remote_retrieve_body( $this->paypal_request( $payload, $credentials ) ) );
		}
		
		?>
		<?php if ( $txn ) : ?>
			<style>
			#tix-dashboard-txn-info {
				padding: 20px;
				background: #F5EFC6;
			}
			</style>
			<pre id="tix-dashboard-txn-info"><?php 
				esc_html( print_r( $txn, true ) );
			?></pre>
		<?php endif; ?>
		<?php
	}
	
	function render_dashboard_attendees() {
		$search_query = isset( $_POST['s'] ) ? $_POST['s'] : '';
		if ( isset( $_POST['tix_dashboard_attendee_lookup_submit'], $_POST['s'] ) )
			$this->list_table->prepare_items();
		?>
		<form method="POST">
			<label class="description">Search Query:</label>
			<input type="hidden" name="tix_dashboard_attendee_lookup_submit" value="1" />
			<input type="text" name="s" placeholder="Name, e-mail, twitter, URL, ..." value="<?php echo esc_attr( $search_query ); ?>" />	<?php // TODO nonces ?>
			<input type="submit" value="Lookup" class="button-primary" />
		</form>
		
		<?php if ( isset( $_POST['tix_dashboard_attendee_lookup_submit'], $_POST['s'] ) ) : ?>
			<style>
			#tix-dashboard-attendees-table {
				margin-top: 20px;
			}
			#tix-dashboard-attendees-table .tablenav {
				display: none;
			}
			</style>
			<div id="tix-dashboard-attendees-table">
				<?php if ( count( $this->list_table->items ) >= $this->list_table->max_results ) : ?>
					<p class="description">Please note, that for performance reasons, we don't show more than <?php echo absint( $this->list_table->max_results ); ?> results.</p>
				<?php endif; ?>
				<?php $this->list_table->display(); ?>
			</div>
		<?php endif; ?>
		<?php
	}
	
	function paypal_request( $payload, $credentials ) {
		$url = $credentials['sandbox'] ? 'https://api-3t.sandbox.paypal.com/nvp' : 'https://api-3t.paypal.com/nvp';
		$payload = array_merge( array(
			'USER' => $credentials['api_username'],
			'PWD' => $credentials['api_password'],
			'SIGNATURE' => $credentials['api_signature'],
			'VERSION' => '88.0', // https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_PreviousAPIVersionsNVP
		), (array) $payload );

		return wp_remote_post( $url, array( 'body' => $payload, 'timeout' => 20 ) );
	}
	
	function get_paypal_credentials() {
		return apply_filters( 'camptix_dashboard_paypal_credentials', array() );
	}
}

$GLOBALS['camptix_network_dashboard'] = new CampTix_Network_Dashboard;