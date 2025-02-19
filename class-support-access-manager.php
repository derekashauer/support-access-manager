<?php
if ( ! class_exists( 'Support_Access_Manager' ) ) {

	class Support_Access_Manager {
		private $menu_slug;
		private $menu_label;
		private $parent_slug;
		private $default_settings;

		/**
		 * Constructor for Support Access Manager
		 *
		 * @param array $args {
		 *     Optional. Array of settings for configuring the support access manager.
		 *
		 *     @type string $menu_slug    Slug for the admin menu page. Default 'support-access'.
		 *     @type string $menu_label   Label for the admin menu item. Default 'Support Access'.
		 *     @type string $parent_slug  Parent menu slug. Default 'users.php'.
		 *     @type array  $defaults {
		 *         Optional. Default values for the user creation form.
		 *
		 *         @type int    $duration      Default duration value. Default 1.
		 *         @type string $duration_unit Default duration unit (hours|days|weeks|months). Default 'weeks'.
		 *         @type int    $timeout       Default timeout in hours. Default empty.
		 *         @type int    $usage_limit   Default usage limit. Default empty (unlimited).
		 *         @type string $role          Default user role. Default 'administrator'.
		 *         @type string $locale        Default user locale. Default empty (site default).
		 *     }
		 * }
		 */
		public function __construct( $args = array() ) {
			$args = wp_parse_args(
				$args,
				array(
					'menu_slug'   => 'support-access',
					'menu_label'  => 'Support Access',
					'parent_slug' => 'users.php',
					'defaults'    => array(),
				)
			);

			$this->menu_slug   = $args['menu_slug'];
			$this->menu_label  = $args['menu_label'];
			$this->parent_slug = $args['parent_slug'];

			// Set default settings with fallbacks
			$this->default_settings = wp_parse_args(
				$args['defaults'],
				array(
					'duration'      => 1,
					'duration_unit' => 'weeks',
					'timeout'       => '',
					'usage_limit'   => '',
					'role'          => 'administrator',
					'locale'        => '',
				)
			);

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
			$args = array(
				'meta_key'   => 'support_access_token',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => 'support_access_expiration',
						'compare' => 'EXISTS',
					),
				),
			);

			$user_query = new WP_User_Query( $args );

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
				<?php
				// Check for transient message
				$message = get_transient( 'support_access_message_' . get_current_user_id() );
				if ( $message ) {
					delete_transient( 'support_access_message_' . get_current_user_id() );
					printf(
						'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
						esc_attr( $message['type'] ),
						esc_html( $message['message'] )
					);
				}
				?>
				
				<form method="post" class="support-access-form">
					<?php wp_nonce_field( 'create_temp_admin' ); ?>
					<table class="form-table">
						<tbody>

							<tr>
								<th scope="row">
									<label for="access_duration"><?php esc_html_e( 'Access Duration:', 'support-access' ); ?></label>
								</th>
								<td>
									<input type="number" 
										   name="access_duration" 
										   id="access_duration" 
										   value="<?php echo esc_attr( $this->default_settings['duration'] ); ?>" 
										   min="1" 
										   class="small-text" 
										   required>
									<select name="access_duration_unit" id="access_duration_unit">
										<option value="hours" <?php selected( $this->default_settings['duration_unit'], 'hours' ); ?>>
											<?php esc_html_e( 'Hours', 'support-access' ); ?>
										</option>
										<option value="days" <?php selected( $this->default_settings['duration_unit'], 'days' ); ?>>
											<?php esc_html_e( 'Days', 'support-access' ); ?>
										</option>
										<option value="weeks" <?php selected( $this->default_settings['duration_unit'], 'weeks' ); ?>>
											<?php esc_html_e( 'Weeks', 'support-access' ); ?>
										</option>
										<option value="months" <?php selected( $this->default_settings['duration_unit'], 'months' ); ?>>
											<?php esc_html_e( 'Months', 'support-access' ); ?>
										</option>
									</select>
									<p class="description">
										<?php esc_html_e( 'How long the temporary user account will exist before being automatically deleted.', 'support-access' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="access_timeout"><?php esc_html_e( 'Login Link Timeout:', 'support-access' ); ?></label>
								</th>
								<td>
									<input type="number" 
										   name="access_timeout" 
										   id="access_timeout" 
										   value="<?php echo esc_attr( $this->default_settings['timeout'] ); ?>" 
										   min="1" 
										   class="small-text">
									<p class="description">
										<?php esc_html_e( 'Number of hours the login link remains valid after generation. Leave empty for no timeout (link works until account expires).', 'support-access' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="access_limit"><?php esc_html_e( 'Usage Limit:', 'support-access' ); ?></label>
								</th>
								<td>
									<input type="number" 
										   name="access_limit" 
										   id="access_limit" 
										   value="<?php echo esc_attr( $this->default_settings['usage_limit'] ); ?>" 
										   min="0" 
										   class="small-text">
									<p class="description">
										<?php esc_html_e( 'Maximum number of times the login link can be used. Enter 0 or leave empty for unlimited uses.', 'support-access' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="user_role"><?php esc_html_e( 'User Role:', 'support-access' ); ?></label>
								</th>
								<td>
									<select name="user_role" id="user_role">
										<?php
										$roles = wp_roles()->get_names();
										foreach ( $roles as $role_id => $role_name ) {
											printf(
												'<option value="%s" %s>%s</option>',
												esc_attr( $role_id ),
												selected( $role_id, $this->default_settings['role'], false ),
												esc_html( $role_name )
											);
										}
										?>
									</select>
									<p class="description">
										<?php esc_html_e( 'The WordPress role assigned to the temporary user. Choose the minimum role needed for the support task.', 'support-access' ); ?>
									</p>
								</td>
							</tr>

							<tr>
								<th scope="row">
									<label for="user_locale"><?php esc_html_e( 'User Language:', 'support-access' ); ?></label>
								</th>
								<td>
									<?php
									require_once ABSPATH . 'wp-admin/includes/translation-install.php';
									$translations = wp_get_available_translations();
									$languages    = array(
										'' => sprintf(
											/* translators: %s: Current site language name */
											__( 'Site Default - %s', 'support-access' ),
											$translations[ get_locale() ]['native_name'] ?? 'English (United States)'
										),
									);

									// Add installed languages
									$installed_languages = get_available_languages();
									foreach ( $installed_languages as $locale ) {
										if ( isset( $translations[ $locale ] ) ) {
											$languages[ $locale ] = $translations[ $locale ]['native_name'];
										}
									}

									// Always include English (US) if not already added
									if ( ! isset( $languages['en_US'] ) ) {
										$languages['en_US'] = 'English (United States)';
									}

									$current_locale = get_locale();
									?>
									<select name="user_locale" id="user_locale">
										<?php
										foreach ( $languages as $locale => $native_name ) {
											printf(
												'<option value="%s" %s>%s</option>',
												esc_attr( $locale ),
												selected( $locale, $this->default_settings['locale'], false ),
												esc_html( $native_name )
											);
										}
										?>
									</select>
									<p class="description">
										<?php esc_html_e( 'The WordPress admin interface language for this temporary user. Choose "Site Default" to use the site\'s language setting.', 'support-access' ); ?>
									</p>
								</td>
							</tr>

						</tbody>
					</table>

					<?php submit_button( __( 'Create Temporary User', 'support-access' ) ); ?>
				</form>

				<?php $this->list_temp_admins(); ?>
			</div>

			<script type="text/javascript">
				jQuery(document).ready(function($) {
					$('#access_duration_type').on('change', function() {
						if ($(this).val() === 'custom') {
							$('#custom_date_wrapper').show();
						} else {
							$('#custom_date_wrapper').hide();
						}
					});

					// Move copyToClipboard outside jQuery and make it global
					window.copyToClipboard = function(url) {
						// Use modern clipboard API if available
						if (navigator.clipboard && window.isSecureContext) {
							navigator.clipboard.writeText(url).then(() => {
								alert('URL copied to clipboard!');
							}).catch(() => {
								// Fallback to older method if clipboard API fails
								fallbackCopyToClipboard(url);
							});
						} else {
							// Fallback for older browsers
							fallbackCopyToClipboard(url);
						}
					};

					function fallbackCopyToClipboard(url) {
						const tempInput = document.createElement('input');
						tempInput.value = url;
						document.body.appendChild(tempInput);
						tempInput.select();
						document.execCommand('copy');
						document.body.removeChild(tempInput);
						alert('URL copied to clipboard!');
					}
				});
			</script>

			<style>
			.temp-access-url {
				display: inline-block;
				max-width: 250px;
				vertical-align: middle;
				font-family: monospace;
				background: #f0f0f1;
				padding: 2px 6px;
				border-radius: 3px;
				border: 1px solid #c3c4c7;
			}
			.temp-access-url:hover {
				background: #fff;
			}
			.action-icon {
				color: #50575e;
				cursor: pointer;
				padding: 4px;
				text-decoration: none;
				vertical-align: middle;
			}
			.action-icon:hover {
				color: #135e96;
			}
			.action-icon.delete {
				color: #b32d2e;
			}
			.action-icon.delete:hover {
				color: #991b1c;
			}
			</style>
			<?php
		}

		// Handle form submission and create the temp admin user
		public function handle_temp_admin_form_submission() {
			if ( ! isset( $_POST['access_duration'] ) || ! current_user_can( 'manage_options' ) ) {
				return;
			}

			if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'create_temp_admin' ) ) {
				wp_die( 'Invalid nonce' );
			}

			// Calculate expiration time based on duration and unit
			$duration = absint( $_POST['access_duration'] );
			$unit     = sanitize_text_field( wp_unslash( $_POST['access_duration_unit'] ) );

			switch ( $unit ) {
				case 'hours':
					$expiration_time = time() + ( $duration * HOUR_IN_SECONDS );
					break;
				case 'days':
					$expiration_time = time() + ( $duration * DAY_IN_SECONDS );
					break;
				case 'weeks':
					$expiration_time = time() + ( $duration * WEEK_IN_SECONDS );
					break;
				case 'months':
					$expiration_time = time() + ( $duration * MONTH_IN_SECONDS );
					break;
				default:
					$expiration_time = time() + WEEK_IN_SECONDS; // Default to 1 week
			}

			$timeout = ! empty( $_POST['access_timeout'] ) ? absint( $_POST['access_timeout'] ) * HOUR_IN_SECONDS : 0;
			$limit   = isset( $_POST['access_limit'] ) ? absint( $_POST['access_limit'] ) : 0;
			$role    = sanitize_text_field( wp_unslash( $_POST['user_role'] ) );
			$locale  = sanitize_text_field( wp_unslash( $_POST['user_locale'] ) );

			// Create temporary user and URL
			$temp_user_id = $this->create_temp_admin_user( $role );

			// Generate access token data
			$token_data = array(
				'id'    => $temp_user_id,
				'time'  => time(),
				'nonce' => wp_generate_password( 12, false ), // Add a nonce for extra security
			);

			$data         = base64_encode( json_encode( $token_data ) );
			$hash         = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // Use WordPress salt instead of hardcoded key
			$access_token = $data . '.' . $hash;

			$temp_user_url = add_query_arg(
				array(
					'support_access' => $access_token,
				),
				home_url()
			);

			// Store user metadata
			update_user_meta( $temp_user_id, 'support_access_url', $temp_user_url );
			update_user_meta( $temp_user_id, 'support_access_token', $access_token ); // Store the token for verification
			update_user_meta( $temp_user_id, 'temp_admin_login_count', 0 );
			update_user_meta( $temp_user_id, 'support_access_expiration', $expiration_time );
			update_user_meta( $temp_user_id, 'support_access_timeout', $timeout );
			update_user_meta( $temp_user_id, 'support_access_limit', $limit );

			if ( ! empty( $locale ) ) {
				update_user_meta( $temp_user_id, 'locale', $locale );
			}

			// Store success message in transient with unique key for this user
			set_transient(
				'support_access_message_' . get_current_user_id(),
				array(
					'type'    => 'success',
					'message' => __( 'Support access user created successfully.', 'support-access' ),
				),
				30 // Expire after 30 seconds
			);

			wp_redirect( admin_url( 'users.php?page=support-access' ) );
			exit;
		}

		// Create temporary admin user
		private function create_temp_admin_user( $role = 'administrator' ) {
			$username = 'support_user_' . uniqid();
			$password = wp_generate_password();
			$email    = $username . '@example.com';

			$user_id = wp_create_user( $username, $password, $email );
			$user    = new WP_User( $user_id );
			$user->set_role( $role );

			return $user_id;
		}

		// Check if the temp_admin hash matches and log in the user
		public function check_temp_admin_login() {
			if ( ! isset( $_GET['support_access'] ) ) {
				return;
			}

			$access_token = sanitize_text_field( wp_unslash( $_GET['support_access'] ) );

			// Split token into data and hash
			$parts = explode( '.', $access_token );
			if ( count( $parts ) !== 2 ) {
				wp_safe_redirect( home_url() );
				exit;
			}

			list( $data, $received_hash ) = $parts;

			// Verify hash
			$expected_hash = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
			if ( ! hash_equals( $expected_hash, $received_hash ) ) {
				wp_safe_redirect( home_url() );
				exit;
			}

			// Decode the data
			$decoded = json_decode( base64_decode( $data ), true );
			if ( ! $decoded || ! isset( $decoded['id'] ) || ! isset( $decoded['time'] ) || ! isset( $decoded['nonce'] ) ) {
				wp_safe_redirect( home_url() );
				exit;
			}

			$temp_user_id = absint( $decoded['id'] );

			// Verify user exists and token matches
			$stored_token = get_user_meta( $temp_user_id, 'support_access_token', true );
			if ( empty( $stored_token ) || $stored_token !== $access_token ) {
				wp_safe_redirect( home_url() );
				exit;
			}

			// Get user metadata
			$expiration  = get_user_meta( $temp_user_id, 'support_access_expiration', true );
			$timeout     = get_user_meta( $temp_user_id, 'support_access_timeout', true );
			$limit       = get_user_meta( $temp_user_id, 'support_access_limit', true );
			$login_count = get_user_meta( $temp_user_id, 'temp_admin_login_count', true );

			// Check if account has expired
			if ( time() > $expiration ) {
				wp_delete_user( $temp_user_id );
				wp_safe_redirect( home_url() );
				exit;
			}

			// Check login count limit
			if ( $limit > 0 && $login_count >= $limit ) {
				wp_safe_redirect( home_url() );
				exit;
			}

			// Check URL timeout
			if ( ! empty( $timeout ) ) {
				if ( time() > ( $decoded['time'] + $timeout ) ) {
					wp_safe_redirect( home_url() );
					exit;
				}
			}

			// All checks passed, log the user in
			wp_set_current_user( $temp_user_id );
			wp_set_auth_cookie( $temp_user_id );

			// Increment the login count
			update_user_meta( $temp_user_id, 'temp_admin_login_count', intval( $login_count ) + 1 );

			wp_safe_redirect( admin_url() );
			exit;
		}

		// List temporary admins with login count, expiration date, timeout, and delete option
		private function list_temp_admins() {
			$args = array(
				'meta_key'   => 'support_access_token',
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => 'support_access_expiration',
						'compare' => 'EXISTS',
					),
				),
			);

			$user_query = new WP_User_Query( $args );
			if ( ! empty( $user_query->results ) ) {
				echo '<h2>' . esc_html__( 'Temporary Users', 'support-access' ) . '</h2>';
				echo '<table class="wp-list-table widefat fixed striped users">';
				echo '<thead>';
				echo '<tr>';
				echo '<th>' . esc_html__( 'Username', 'support-access' ) . '</th>';
				echo '<th>' . esc_html__( 'Role', 'support-access' ) . '</th>';
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
					$expiration_date  = $expiration_time ?
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiration_time ) : '';
					$timeout_date     = $timeout_time ?
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiration_time + $timeout_time ) : '';

					$limit      = get_user_meta( $user->ID, 'support_access_limit', true );
					$login_info = ( empty( $limit ) || '0' === $limit ) ? $login_count . ' / ∞' : $login_count . ' / ' . $limit;

					// Get user's role
					$user_roles = array_map(
						function( $role ) {
							return translate_user_role( wp_roles()->get_names()[ $role ] );
						},
						$user->roles
					);

					?>
					<tr>
						<td><a href="<?php echo esc_url( $user_profile_url ); ?>"><?php echo esc_html( $user->user_login ); ?></a></td>
						<td><?php echo esc_html( implode( ', ', $user_roles ) ); ?></td>
						<td><?php echo esc_html( $login_info ); ?></td>
						<td><?php echo esc_html( $expiration_date ); ?></td>
						<td><?php echo esc_html( $timeout_date ); ?></td>
						<td>
							<?php
							$url_preview = substr( $temp_user_url, 0, 35 ) . '...';
							?>
							<span class="action-icon" onclick="copyToClipboard('<?php echo esc_js( $temp_user_url ); ?>')">
							<span class="dashicons dashicons-clipboard" 
								  title="<?php esc_attr_e( 'Copy URL', 'support-access' ); ?>">
							</span> <?php _e( 'Copy URL', 'support-access' ); ?>
				</span>
						</td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'delete_temp_admin_' . $user->ID ); ?>
								<input type="hidden" name="action" value="delete_temp_admin">
								<input type="hidden" name="delete_temp_admin_id" value="<?php echo esc_attr( $user->ID ); ?>">
								<button type="submit" class="action-icon delete" style="border: none; background: none; padding: 0;">
									<span class="dashicons dashicons-trash" 
										  title="<?php esc_attr_e( 'Delete User', 'support-access' ); ?>">
									</span> <?php esc_html_e( 'Delete', 'support-access' ); ?>
								</button>
							</form>
						</td>
					</tr>
					<?php
				}

				echo '</tbody>';
				echo '</table>';
			}
		}

		public function handle_temp_admin_deletion() {
			if ( isset( $_POST['delete_temp_admin_id'] ) && current_user_can( 'manage_options' ) ) {
				$user_id = absint( $_POST['delete_temp_admin_id'] );
				if ( wp_verify_nonce( $_POST['_wpnonce'], 'delete_temp_admin_' . $user_id ) ) {
					wp_delete_user( $user_id );

					// Store deletion message in transient
					set_transient(
						'support_access_message_' . get_current_user_id(),
						array(
							'type'    => 'success',
							'message' => __( 'Support access user deleted successfully.', 'support-access' ),
						),
						30
					);

					wp_redirect( admin_url( 'users.php?page=support-access' ) );
					exit;
				}
			}
		}
	}

}
