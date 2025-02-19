<?php
class Support_Access_Manager {
	private static $instance = null;
	private $textdomain;
	private $default_settings;

	/**
	 * Get the singleton instance.
	 *
	 * @param array $args {
	 *     Optional. Configuration arguments.
	 *
	 *     @type string $textdomain Text domain for translations. Default 'support-access'.
	 *     @type array  $defaults   Default values for the user creation form.
	 * }
	 * @return Support_Access_Manager
	 */
	public static function get_instance( $args = array() ) {
		if ( null === self::$instance ) {
			self::$instance = new self( $args );
		}
		return self::$instance;
	}

	/**
	 * Protected constructor to prevent creating a new instance.
	 */
	protected function __construct( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'textdomain' => 'support-access',
				'defaults'   => array(),
			)
		);

		$this->textdomain = $args['textdomain'];

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

		// Add menu under Users
		add_action( 'admin_menu', array( $this, 'add_menu' ) );

		// Schedule cron job on plugin activation.
		register_activation_hook( __FILE__, array( $this, 'schedule_access_expiration_check' ) );

		// Clear the scheduled cron job on plugin deactivation.
		register_deactivation_hook( __FILE__, array( $this, 'clear_access_expiration_check' ) );

		// Schedule the cron event to check for expired admins.
		add_action( 'check_access_expiration_event', array( $this, 'check_access_expiration' ) );

		// Handle temp admin login by checking the URL parameters.
		add_action( 'init', array( $this, 'check_access_login' ) );

		// Handle form submission to create temp admin users.
		add_action( 'admin_init', array( $this, 'handle_access_form_submission' ) );

		// Handle deletion of temporary admins.
		add_action( 'admin_post_delete_access_user', array( $this, 'handle_access_deletion' ) );
	}

	/**
	 * Schedule the cron job when the plugin is activated.
	 */
	public function schedule_access_expiration_check() {
		if ( ! wp_next_scheduled( 'check_access_expiration_event' ) ) {
			wp_schedule_event( time(), 'hourly', 'check_access_expiration_event' );
		}
	}

	/**
	 * Clear the scheduled cron job on plugin deactivation.
	 */
	public function clear_access_expiration_check() {
		$timestamp = wp_next_scheduled( 'check_access_expiration_event' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'check_access_expiration_event' );
		}
	}

	/**
	 * Check for expired admins.
	 */
	public function check_access_expiration() {
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

	/**
	 * Add the menu item under Users.
	 */
	public function add_menu() {
		add_submenu_page(
			'users.php',
			__( 'Temporary Access', 'support-access' ),
			__( 'Temporary Access', 'support-access' ),
			'manage_options',
			'temporary-access',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Admin page content for Support Access.
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Support Access', $this->textdomain ); ?></h1>
			<?php
			// Check for transient message.
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
				<?php wp_nonce_field( 'create_access_user' ); ?>
				<table class="form-table">
					<tbody>

						<tr>
							<th scope="row">
								<label for="access_duration"><?php esc_html_e( 'Access Duration:', $this->textdomain ); ?></label>
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
										<?php esc_html_e( 'Hours', $this->textdomain ); ?>
									</option>
									<option value="days" <?php selected( $this->default_settings['duration_unit'], 'days' ); ?>>
										<?php esc_html_e( 'Days', $this->textdomain ); ?>
									</option>
									<option value="weeks" <?php selected( $this->default_settings['duration_unit'], 'weeks' ); ?>>
										<?php esc_html_e( 'Weeks', $this->textdomain ); ?>
									</option>
									<option value="months" <?php selected( $this->default_settings['duration_unit'], 'months' ); ?>>
										<?php esc_html_e( 'Months', $this->textdomain ); ?>
									</option>
								</select>
								<p class="description">
									<?php esc_html_e( 'How long the temporary user account will exist before being automatically deleted.', $this->textdomain ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="access_timeout"><?php esc_html_e( 'Login Link Timeout:', $this->textdomain ); ?></label>
							</th>
							<td>
								<input type="number" 
									   name="access_timeout" 
									   id="access_timeout" 
									   value="<?php echo esc_attr( $this->default_settings['timeout'] ); ?>" 
									   min="1" 
									   class="small-text">
								<p class="description">
									<?php esc_html_e( 'Number of hours the login link remains valid after generation. Leave empty for no timeout (link works until account expires).', $this->textdomain ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="access_limit"><?php esc_html_e( 'Usage Limit:', $this->textdomain ); ?></label>
							</th>
							<td>
								<input type="number" 
									   name="access_limit" 
									   id="access_limit" 
									   value="<?php echo esc_attr( $this->default_settings['usage_limit'] ); ?>" 
									   min="0" 
									   class="small-text">
								<p class="description">
									<?php esc_html_e( 'Maximum number of times the login link can be used. Enter 0 or leave empty for unlimited uses.', $this->textdomain ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="user_role"><?php esc_html_e( 'User Role:', $this->textdomain ); ?></label>
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
									<?php esc_html_e( 'The WordPress role assigned to the temporary user. Choose the minimum role needed for the support task.', $this->textdomain ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="user_locale"><?php esc_html_e( 'User Language:', $this->textdomain ); ?></label>
							</th>
							<td>
								<?php
								require_once ABSPATH . 'wp-admin/includes/translation-install.php';
								$translations = wp_get_available_translations();
								$languages    = array(
									'' => sprintf(
										/* translators: %s: Current site language name */
										__( 'Site Default - %s', $this->textdomain ),
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
									<?php esc_html_e( 'The WordPress admin interface language for this temporary user. Choose "Site Default" to use the site\'s language setting.', $this->textdomain ); ?>
								</p>
							</td>
						</tr>

					</tbody>
				</table>

				<?php submit_button( __( 'Create User', $this->textdomain ) ); ?>
			</form>

			<?php $this->list_access_users(); ?>
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

	/**
	 * Handle form submission and create the temp admin user.
	 */
	public function handle_access_form_submission() {
		if ( ! isset( $_POST['access_duration'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'create_access_user' ) ) {
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
				$expiration_time = time() + WEEK_IN_SECONDS; // Default to 1 week.
		}

		$timeout = ! empty( $_POST['access_timeout'] ) ? absint( $_POST['access_timeout'] ) * HOUR_IN_SECONDS : 0;
		$limit   = isset( $_POST['access_limit'] ) ? absint( $_POST['access_limit'] ) : 0;
		$role    = sanitize_text_field( wp_unslash( $_POST['user_role'] ) );
		$locale  = sanitize_text_field( wp_unslash( $_POST['user_locale'] ) );

		// Create temporary user and URL.
		$access_user_id = $this->create_access_user( $role );

		// Generate access token data.
		$token_data = array(
			'id'    => $access_user_id,
			'time'  => time(),
			'nonce' => wp_generate_password( 12, false ), // Add a nonce for extra security.
		);

		$data         = base64_encode( json_encode( $token_data ) );
		$hash         = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) ); // Use WordPress salt instead of hardcoded key.
		$access_token = $data . '.' . $hash;

		$access_url = add_query_arg(
			array(
				'support_access' => $access_token,
			),
			home_url()
		);

		// Store user metadata.
		update_user_meta( $access_user_id, 'support_access_url', $access_url );
		update_user_meta( $access_user_id, 'support_access_token', $access_token ); // Store the token for verification.
		update_user_meta( $access_user_id, 'support_access_login_count', 0 );
		update_user_meta( $access_user_id, 'support_access_expiration', $expiration_time );
		update_user_meta( $access_user_id, 'support_access_timeout', $timeout );
		update_user_meta( $access_user_id, 'support_access_limit', $limit );

		if ( ! empty( $locale ) ) {
			update_user_meta( $access_user_id, 'locale', $locale );
		}

		// Store success message in transient with unique key for this user.
		set_transient(
			'support_access_message_' . get_current_user_id(),
			array(
				'type'    => 'success',
				'message' => __( 'Support access user created successfully.', $this->textdomain ),
			),
			30 // Expire after 30 seconds.
		);

		wp_redirect( admin_url( 'users.php?page=temporary-access' ) );
		exit;
	}

	/**
	 * Create a temporary admin user.
	 *
	 * @param string $role The role to assign to the user. Default is 'administrator'.
	 * @return int The ID of the newly created user.
	 */
	private function create_access_user( $role = 'administrator' ) {
		$username = 'support_user_' . uniqid();
		$password = wp_generate_password();
		$email    = $username . '@example.com';

		$user_id = wp_create_user( $username, $password, $email );
		$user    = new WP_User( $user_id );
		$user->set_role( $role );

		return $user_id;
	}

	/**
	 * Check if the temp_admin hash matches and log in the user.
	 */
	public function check_access_login() {
		if ( ! isset( $_GET['support_access'] ) ) {
			return;
		}

		$access_token = sanitize_text_field( wp_unslash( $_GET['support_access'] ) );

		// Split token into data and hash.
		$parts = explode( '.', $access_token );
		if ( count( $parts ) !== 2 ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		list( $data, $received_hash ) = $parts;

		// Verify hash.
		$expected_hash = hash_hmac( 'sha256', $data, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected_hash, $received_hash ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		// Decode the data.
		$decoded = json_decode( base64_decode( $data ), true );
		if ( ! $decoded || ! isset( $decoded['id'] ) || ! isset( $decoded['time'] ) || ! isset( $decoded['nonce'] ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		$access_user_id = absint( $decoded['id'] );

		// Verify user exists and token matches.
		$stored_token = get_user_meta( $access_user_id, 'support_access_token', true );
		if ( empty( $stored_token ) || $stored_token !== $access_token ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		// Get user metadata.
		$expiration  = get_user_meta( $access_user_id, 'support_access_expiration', true );
		$timeout     = get_user_meta( $access_user_id, 'support_access_timeout', true );
		$limit       = get_user_meta( $access_user_id, 'support_access_limit', true );
		$login_count = get_user_meta( $access_user_id, 'support_access_login_count', true );

		// Check if account has expired
		if ( time() > $expiration ) {
			wp_delete_user( $access_user_id );
			wp_safe_redirect( home_url() );
			exit;
		}

		// Check login count limit.
		if ( $limit > 0 && $login_count >= $limit ) {
			wp_safe_redirect( home_url() );
			exit;
		}

		// Check URL timeout.
		if ( ! empty( $timeout ) ) {
			if ( time() > ( $decoded['time'] + $timeout ) ) {
				wp_safe_redirect( home_url() );
				exit;
			}
		}

		// All checks passed, log the user in.
		wp_set_current_user( $access_user_id );
		wp_set_auth_cookie( $access_user_id );

		// Increment the login count.
		update_user_meta( $access_user_id, 'support_access_login_count', intval( $login_count ) + 1 );

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * List temporary admins with login count, expiration date, timeout, and delete option.
	 */
	private function list_access_users() {
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
			echo '<h2>' . esc_html__( 'Temporary Users', $this->textdomain ) . '</h2>';
			echo '<table class="wp-list-table widefat fixed striped users">';
			echo '<thead>';
			echo '<tr>';
			echo '<th>' . esc_html__( 'Username', $this->textdomain ) . '</th>';
			echo '<th>' . esc_html__( 'Role', $this->textdomain ) . '</th>';
			echo '<th>' . esc_html__( 'Login Count', $this->textdomain ) . '</th>';
			echo '<th>' . esc_html__( 'Expiration Date', $this->textdomain ) . '</th>';
			echo '<th>' . esc_html__( 'Login Link Timeout', $this->textdomain ) . '</th>';
			echo '<th>' . esc_html__( 'Access URL', $this->textdomain ) . '</th>';
			echo '<th>' . esc_html__( 'Actions', $this->textdomain ) . '</th>';
			echo '</tr>';
			echo '</thead>';
			echo '<tbody>';

			foreach ( $user_query->results as $user ) {
				$login_count      = get_user_meta( $user->ID, 'support_access_login_count', true );
				$user_profile_url = get_edit_user_link( $user->ID );
				$access_url       = get_user_meta( $user->ID, 'support_access_url', true );
				$expiration_time  = get_user_meta( $user->ID, 'support_access_expiration', true );
				$timeout_time     = get_user_meta( $user->ID, 'support_access_timeout', true );
				$expiration_date  = $expiration_time ?
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiration_time ) : '';
				$timeout_date     = $timeout_time ?
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiration_time + $timeout_time ) : '';

				$limit      = get_user_meta( $user->ID, 'support_access_limit', true );
				$login_info = ( empty( $limit ) || '0' === $limit ) ? $login_count . ' / ∞' : $login_count . ' / ' . $limit;

				// Get user's role.
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
						$url_preview = substr( $access_url, 0, 35 ) . '...';
						?>
						<span class="action-icon" onclick="copyToClipboard('<?php echo esc_js( $access_url ); ?>')">
						<span class="dashicons dashicons-clipboard" 
							  title="<?php esc_attr_e( 'Copy URL', $this->textdomain ); ?>">
						</span> <?php _e( 'Copy URL', $this->textdomain ); ?>
						</span>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'delete_access_user_' . $user->ID ); ?>
							<input type="hidden" name="action" value="delete_access_user">
							<input type="hidden" name="delete_access_user_id" value="<?php echo esc_attr( $user->ID ); ?>">
							<button type="submit" class="action-icon delete" style="border: none; background: none; padding: 0;">
								<span class="dashicons dashicons-trash" 
									  title="<?php esc_attr_e( 'Delete User', $this->textdomain ); ?>">
								</span> <?php esc_html_e( 'Delete', $this->textdomain ); ?>
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

	/**
	 * Handle temporary admin deletion.
	 */
	public function handle_access_deletion() {
		if ( isset( $_POST['delete_access_user_id'] ) && current_user_can( 'manage_options' ) ) {
			$user_id = absint( $_POST['delete_access_user_id'] );
			if ( wp_verify_nonce( $_POST['_wpnonce'], 'delete_access_user_' . $user_id ) ) {
				wp_delete_user( $user_id );

				// Store deletion message in transient.
				set_transient(
					'support_access_message_' . get_current_user_id(),
					array(
						'type'    => 'success',
						'message' => __( 'Support access user deleted successfully.', $this->textdomain ),
					),
					30
				);

				wp_redirect( admin_url( 'users.php?page=temporary-access' ) );
				exit;
			}
		}
	}
}
