<?php

if ( ! class_exists( 'Support_Access_Manager' ) ) {

	class Support_Access_Manager {
		private $menu_slug;
		private $menu_label;
		private $parent_slug;

		// Constructor accepts parameters for menu position, label, and parent slug
		public function __construct( $menu_slug = 'support-access', $menu_label = 'Support Access', $parent_slug = 'users.php' ) {
			$this->menu_slug   = $menu_slug;
			$this->menu_label  = $menu_label;
			$this->parent_slug = $parent_slug;

			// Schedule cron job on plugin activation
			register_activation_hook( __FILE__, array( $this, 'schedule_temp_admin_expiration_check' ) );

			// Clear the scheduled cron job on plugin deactivation
			register_deactivation_hook( __FILE__, array( $this, 'clear_temp_admin_expiration_check' ) );

			// Schedule the cron event to check for expired admins
			add_action( 'check_temp_admin_expiration_event', array( $this, 'check_temp_admin_expiration' ) );

			// Handle temp admin login by checking the URL parameters
			add_action( 'init', array( $this, 'check_temp_admin_login' ) );

			// Add admin menu page
			add_action( 'admin_menu', array( $this, 'add_support_access_menu' ) );

			// Handle form submission to create temp admin users
			add_action( 'admin_init', array( $this, 'handle_temp_admin_form_submission' ) );

			// Handle deletion of temporary admins
			add_action( 'admin_post_delete_temp_admin', array( $this, 'handle_temp_admin_deletion' ) );
		}

		// Schedule the cron job when the plugin is activated
		public function schedule_temp_admin_expiration_check() {
			if ( ! wp_next_scheduled( 'check_temp_admin_expiration_event' ) ) {
				wp_schedule_event( time(), 'hourly', 'check_temp_admin_expiration_event' );
			}
		}

		// Clear the scheduled cron job on plugin deactivation
		public function clear_temp_admin_expiration_check() {
			$timestamp = wp_next_scheduled( 'check_temp_admin_expiration_event' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'check_temp_admin_expiration_event' );
			}
		}

		// Hook into the scheduled event to check for expired temporary admin users
		public function check_temp_admin_expiration() {
			$user_query = new WP_User_Query(
				array(
					'role'     => 'administrator',
					'meta_key' => 'support_access_expiration',
				)
			);

			if ( ! empty( $user_query->results ) ) {
				foreach ( $user_query->results as $user ) {
					$expiration_time = get_user_meta( $user->ID, 'support_access_expiration', true );
					if ( $expiration_time && time() > $expiration_time ) {
						wp_delete_user( $user->ID );
					}
				}
			}
		}

		// Add menu item to the specified parent menu (default: Users)
		public function add_support_access_menu() {
			add_submenu_page(
				$this->parent_slug,       // Parent menu slug
				$this->menu_label,        // Page title
				$this->menu_label,        // Menu title
				'manage_options',         // Capability
				$this->menu_slug,         // Menu slug
				array( $this, 'support_access_page' ) // Callback function for the page content
			);
		}

		// Admin page content for Support Access
		public function support_access_page() {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Support Access', 'support-access' ); ?></h1>
				<?php if ( isset( $_GET['message'] ) ) : ?>
					<div class="updated"><p><?php echo esc_html( $_GET['message'] ); ?></p></div>
				<?php endif; ?>
				<form method="post">
					<label for="access_duration"><?php esc_html_e( 'Temporary Admin Access Duration (days):', 'support-access' ); ?></label>
					<input type="number" name="access_duration" id="access_duration" value="1" min="1" required>
					<br>
					<label for="access_timeout"><?php esc_html_e( 'Timeout Duration (hours):', 'support-access' ); ?></label>
					<input type="number" name="access_timeout" id="access_timeout" value="" min="1">
					<br>
					<label for="access_limit"><?php esc_html_e( 'Limit Number of Uses:', 'support-access' ); ?></label>
					<input type="number" name="access_limit" id="access_limit" value="" min="0">
					<br>
					<?php submit_button( 'Generate Temporary Admin Access' ); ?>
				</form>
	
				<?php if ( ! get_option( 'support_access_user_id' ) ) : ?>
					<p><?php esc_html_e( 'No temporary admin user created yet.', 'support-access' ); ?></p>
				<?php endif; ?>
	
				<h2><?php esc_html_e( 'Temporary Admin Users', 'support-access' ); ?></h2>
				<?php $this->list_temp_admins(); ?>
			</div>
			<script type="text/javascript">
				function copyToClipboard(url) {
					var tempInput = document.createElement('input');
					tempInput.value = url;
					document.body.appendChild(tempInput);
					tempInput.select();
					document.execCommand('copy');
					document.body.removeChild(tempInput);
					alert('URL copied to clipboard!');
				}
			</script>
			<?php
		}

		// Handle form submission and create the temp admin user
		public function handle_temp_admin_form_submission() {
			if ( isset( $_POST['access_duration'] ) && current_user_can( 'manage_options' ) ) {
				$duration = absint( $_POST['access_duration'] );
				$timeout  = ! empty( $_POST['access_timeout'] ) ? absint( $_POST['access_timeout'] ) * HOUR_IN_SECONDS : 0; // Convert hours to seconds or set to 0
				$limit    = absint( $_POST['access_limit'] );

				// Create temporary user and URL
				$temp_user_id    = $this->create_temp_admin_user();
				$temp_user_url   = $this->generate_temp_user_url( $temp_user_id );
				$expiration_time = time() + ( $duration * DAY_IN_SECONDS );

				// Store URL, expiration time, timeout, and use limit
				update_user_meta( $temp_user_id, 'support_access_url', $temp_user_url );
				update_user_meta( $temp_user_id, 'temp_admin_login_count', 0 );
				update_user_meta( $temp_user_id, 'support_access_expiration', $expiration_time );
				update_user_meta( $temp_user_id, 'support_access_timeout', $timeout );
				update_user_meta( $temp_user_id, 'support_access_limit', $limit );

				wp_redirect( add_query_arg( 'message', 'Temporary Admin Access URL generated successfully.', admin_url( 'users.php?page=support-access' ) ) );
				exit;
			}
		}

		// Create temporary admin user
		private function create_temp_admin_user() {
			$username = 'support_admin_' . uniqid();
			$password = wp_generate_password();
			$email    = $username . '@example.com';
			$user_id  = wp_create_user( $username, $password, $email );
			$user     = new WP_User( $user_id );
			$user->set_role( 'administrator' );

			$hash = hash_hmac( 'sha256', $user_id . time(), 'your-secret-key' );
			update_user_meta( $user_id, 'support_access_hash', $hash );  // Store the hash

			return $user_id;
		}

		// Generate the temporary URL
		private function generate_temp_user_url( $user_id ) {
			$hash = hash_hmac( 'sha256', $user_id . time(), 'your-secret-key' );
			return add_query_arg(
				array(
					'temp_admin' => $user_id,
					'hash'       => $hash,
				),
				home_url()
			);
		}

		// Check if the temp_admin hash matches and log in the user
		public function check_temp_admin_login() {
			if ( isset( $_GET['temp_admin'] ) && isset( $_GET['hash'] ) ) {
				$temp_user_id = absint( $_GET['temp_admin'] );
				$hash         = sanitize_text_field( $_GET['hash'] );
				$stored_hash  = get_user_meta( $temp_user_id, 'support_access_hash', true );
				$timeout      = get_user_meta( $temp_user_id, 'support_access_timeout', true );
				$limit        = get_user_meta( $temp_user_id, 'support_access_limit', true );
				$login_count  = get_user_meta( $temp_user_id, 'temp_admin_login_count', true );

				// If hash matches and login count is within limit
				if ( $stored_hash && hash_equals( $stored_hash, $hash ) && $login_count < $limit ) {
					// Check if the URL has expired
					if ( time() > ( get_user_meta( $temp_user_id, 'support_access_expiration', true ) + $timeout ) ) {
						wp_redirect( home_url() ); // Redirect to an error page or home
						exit;
					}

					wp_set_current_user( $temp_user_id );
					wp_set_auth_cookie( $temp_user_id );

					// Increment the login count
					update_user_meta( $temp_user_id, 'temp_admin_login_count', $login_count + 1 );

					wp_redirect( admin_url() );
					exit;
				} else {
					wp_redirect( home_url() ); // Redirect to an error page or home
					exit;
				}
			}
		}

		// List temporary admins with login count, expiration date, timeout, and delete option
		private function list_temp_admins() {
			$args = array(
				'role'     => 'administrator',
				'meta_key' => 'temp_admin_login_count',
			);

			$user_query = new WP_User_Query( $args );
			if ( ! empty( $user_query->results ) ) {
				echo '<table class="wp-list-table widefat fixed striped users">';
				echo '<thead>';
				echo '<tr>';
				echo '<th>' . esc_html__( 'Username', 'support-access' ) . '</th>';
				echo '<th>' . esc_html__( 'Login Count', 'support-access' ) . '</th>';
				echo '<th>' . esc_html__( 'Expiration Date', 'support-access' ) . '</th>';
				echo '<th>' . esc_html__( 'Login Link Timeout', 'support-access' ) . '</th>';
				echo '<th>' . esc_html__( 'Access URL', 'support-access' ) . '</th>';
				echo '<th>' . esc_html__( 'Actions', 'support-access' ) . '</th>';
				echo '</tr>';
				echo '</thead>';
				echo '<tbody>';

				foreach ( $user_query->results as $user ) {
					$login_count      = get_user_meta( $user->ID, 'temp_admin_login_count', true );
					$user_profile_url = get_edit_user_link( $user->ID );
					$temp_user_url    = get_user_meta( $user->ID, 'support_access_url', true );
					$expiration_time  = get_user_meta( $user->ID, 'support_access_expiration', true );
					$timeout_time     = get_user_meta( $user->ID, 'support_access_timeout', true );
					$expiration_date  = $expiration_time ? date( 'Y-m-d H:i:s', $expiration_time ) : '';
					$timeout_date     = $timeout_time ? date( 'Y-m-d H:i:s', $expiration_time + $timeout_time ) : '';

					$limit      = get_user_meta( $user->ID, 'support_access_limit', true );
					$login_info = ( $limit == 0 ) ? '∞' : $login_count . ' / ' . $limit;

					?>
					<tr>
						<td><a href="<?php echo esc_url( $user_profile_url ); ?>"><?php echo esc_html( $user->user_login ); ?></a></td>
						<td><?php echo esc_html( $login_info ); ?></td>
						<td><?php echo esc_html( $expiration_date ); ?></td>
						<td><?php echo esc_html( $timeout_date ); ?></td>
						<td>
							<input type="text" readonly value="<?php echo esc_url( $temp_user_url ); ?>" style="width:100%; padding: 10px;">
							<button type="button" onclick="copyToClipboard('<?php echo esc_js( $temp_user_url ); ?>')" class="button"><?php esc_html_e( 'Copy URL', 'support-access' ); ?></button>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'delete_temp_admin_' . $user->ID ); ?>
								<input type="hidden" name="action" value="delete_temp_admin">
								<input type="hidden" name="delete_temp_admin_id" value="<?php echo esc_attr( $user->ID ); ?>">
								<button type="submit" class="button"><?php esc_html_e( 'Delete', 'support-access' ); ?></button>
							</form>
						</td>
					</tr>
					<?php
				}

				echo '</tbody>';
				echo '</table>';
			} else {
				echo '<p>' . esc_html__( 'No temporary admin users found.', 'support-access' ) . '</p>';
			}
		}

		public function handle_temp_admin_deletion() {
			if ( isset( $_POST['delete_temp_admin_id'] ) && current_user_can( 'manage_options' ) ) {
				$user_id = absint( $_POST['delete_temp_admin_id'] );
				if ( wp_verify_nonce( $_POST['_wpnonce'], 'delete_temp_admin_' . $user_id ) ) {
					wp_delete_user( $user_id );
					wp_redirect( add_query_arg( 'message', 'Temporary admin deleted successfully.', admin_url( 'users.php?page=support-access' ) ) );
					exit;
				}
			}
		}
	}

	// Instantiate the class
	new Support_Access_Manager();

}
