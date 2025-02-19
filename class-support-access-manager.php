<?php
/**
 * Support Access Manager
 *
 * Creates temporary WordPress admin accounts with expiration and access limits.
 *
 * @package   Support_Access_Manager
 * @author    Derek Ashauer
 * @copyright Copyright (c) 2024, Derek Ashauer
 * @license   MIT
 * @link      https://github.com/derekashauer/support-access-manager
 * @version   0.2.1
 *
 * @wordpress-plugin
 * Requires at least: 5.0
 * Requires PHP:      7.2
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

if ( ! class_exists( 'Support_Access_Manager' ) ) {

	class Support_Access_Manager {
		private static $instance = null;
		private $menu_slug;
		private $menu_label;
		private $parent_slug;
		private $textdomain;
		private $default_settings;

		/**
		 * Get the singleton instance of Support_Access_Manager.
		 *
		 * @param array $args Optional. Configuration arguments for the instance.
		 *                    Only used when creating a new instance.
		 * @return Support_Access_Manager
		 */
		public static function instance( $args = array() ) {
			if ( null === self::$instance ) {
				self::$instance = new self( $args );
			}
			return self::$instance;
		}

		/**
		 * Protected constructor to prevent creating a new instance of the
		 * Singleton via the `new` operator from outside of this class.
		 */
		protected function __construct( $args = array() ) {
			$args = wp_parse_args(
				$args,
				array(
					'menu_slug'   => 'support-access',
					'menu_label'  => __( 'Support Access', $this->textdomain ),
					'parent_slug' => 'users.php',
					'textdomain'  => 'support-access',
					'defaults'    => array(),
				)
			);

			$this->menu_slug   = $args['menu_slug'];
			$this->menu_label  = $args['menu_label'];
			$this->parent_slug = $args['parent_slug'];
			$this->textdomain  = $args['textdomain'];

			// Set default settings with fallbacks
			$this->default_settings = wp_parse_args(
				$args['defaults'],
				array(
					'duration'      => 1,
					'duration_unit' => 'weeks',
					'usage_limit'   => '',
					'role'          => 'administrator',
					'locale'        => '',
				)
			);

			// Schedule cron job on plugin activation.
			register_activation_hook( __FILE__, array( $this, 'schedule_access_expiration_check' ) );

			// Clear the scheduled cron job on plugin deactivation.
			register_deactivation_hook( __FILE__, array( $this, 'clear_access_expiration_check' ) );

			// Schedule the cron event to check for expired admins.
			add_action( 'check_access_expiration_event', array( $this, 'check_access_expiration' ) );

			// Handle temp admin login by checking the URL parameters.
			add_action( 'init', array( $this, 'check_access_login' ) );

			// Add admin menu page.
			add_action( 'admin_menu', array( $this, 'add_support_access_menu' ) );

			// Handle form submission to create temp admin users.
			add_action( 'admin_init', array( $this, 'handle_access_form_submission' ) );

			// Handle deletion of temporary admins.
			add_action( 'admin_post_delete_access_user', array( $this, 'handle_access_deletion' ) );
		}

		/**
		 * Prevent cloning of the instance
		 */
		protected function __clone() {}

		/**
		 * Prevent unserializing of the instance
		 */
		public function __wakeup() {
			throw new \Exception( 'Cannot unserialize singleton' );
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
		 * Add a menu item to the specified parent menu (default: Users).
		 */
		public function add_support_access_menu() {
			add_submenu_page(
				$this->parent_slug,       // Parent menu slug.
				$this->menu_label,        // Page title.
				$this->menu_label,        // Menu title.
				'manage_options',         // Capability.
				$this->menu_slug,         // Menu slug.
				array( $this, 'support_access_page' ) // Callback function for the page content.
			);
		}

		/**
		 * Admin page content for Support Access.
		 */
		public function support_access_page() {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Support Access', $this->textdomain ); ?></h1>
				<?php
				// Check for transient message.
				$message = get_transient( 'support_access_message_' . get_current_user_id() );
				if ( $message ) {
					delete_transient( 'support_access_message_' . get_current_user_id() );
					printf(
						'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p>%3$s</div>',
						esc_attr( $message['type'] ),
						esc_html( $message['message'] ),
						isset( $message['url'] ) ? sprintf(
							'<p><strong>%s:</strong> <code>%s</code></p>',
							esc_html__( 'Access URL', $this->textdomain ),
							esc_url( $message['url'] )
						) : ''
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
			if ( ! isset( $_POST['access_duration'] ) || ! current_user_can( 'create_users' ) ) {
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

			$limit  = isset( $_POST['access_limit'] ) ? absint( $_POST['access_limit'] ) : 0;
			$role   = sanitize_text_field( wp_unslash( $_POST['user_role'] ) );
			$locale = sanitize_text_field( wp_unslash( $_POST['user_locale'] ) );

			// Create temporary user and get access token.
			$result = $this->create_access_user( $role );

			// Store user metadata.
			update_user_meta( $result['user_id'], 'support_access_login_count', 0 );
			update_user_meta( $result['user_id'], 'support_access_expiration', $expiration_time );
			update_user_meta( $result['user_id'], 'support_access_limit', $limit );

			if ( ! empty( $locale ) ) {
				update_user_meta( $result['user_id'], 'locale', $locale );
			}

			// Store success message in transient.
			set_transient(
				'support_access_message_' . get_current_user_id(),
				array(
					'type'    => 'success',
					'message' => __( 'Support access user created successfully.', $this->textdomain ),
					'url'     => add_query_arg(
						array(
							'support_access' => $result['user_id'] . '|' . $result['access_token'],
						),
						home_url()
					),
				),
				30
			);

			wp_redirect( admin_url( 'users.php?page=support-access' ) );
			exit;
		}

		/**
		 * Create a temporary admin user.
		 *
		 * @param string $role The role to assign to the user.
		 * @return array Array containing user ID and access token.
		 */
		private function create_access_user( $role = 'administrator' ) {
			$username = 'support_user_' . uniqid();
			$password = wp_generate_password();
			$email    = $username . '@example.com';

			$user_id = wp_create_user( $username, $password, $email );
			$user    = new WP_User( $user_id );
			$user->set_role( $role );

			// Generate random token and store its hash.
			$access_token = wp_generate_password( 32, false );
			$token_hash   = hash( 'sha256', $access_token );
			update_user_meta( $user_id, 'support_access_token_hash', $token_hash );

			return array(
				'user_id'      => $user_id,
				'access_token' => $access_token,
			);
		}

		/**
		 * Check if the access token is valid and log in the user.
		 */
		public function check_access_login() {
			if ( ! isset( $_GET['support_access'] ) ) {
				return;
			}

			$parts = explode( '|', sanitize_text_field( wp_unslash( $_GET['support_access'] ) ) );
			if ( count( $parts ) !== 2 ) {
				wp_safe_redirect( home_url() );
				exit;
			}

			list( $user_id, $received_token ) = $parts;
			$user_id                          = absint( $user_id );

			// Get stored hash.
			$stored_hash = get_user_meta( $user_id, 'support_access_token_hash', true );
			if ( empty( $stored_hash ) ) {
				wp_safe_redirect( home_url() );
				exit;
			}

			// Verify token.
			if ( ! hash_equals( $stored_hash, hash( 'sha256', $received_token ) ) ) {
				wp_safe_redirect( home_url() );
				exit;
			}

			// Check expiration and limits.
			$expiration  = get_user_meta( $user_id, 'support_access_expiration', true );
			$limit       = get_user_meta( $user_id, 'support_access_limit', true );
			$login_count = get_user_meta( $user_id, 'support_access_login_count', true );

			if ( time() > $expiration ) {
				wp_delete_user( $user_id );
				wp_safe_redirect( home_url() );
				exit;
			}

			if ( $limit > 0 && $login_count >= $limit ) {
				wp_safe_redirect( home_url() );
				exit;
			}

			// Log the user in.
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id );
			update_user_meta( $user_id, 'support_access_login_count', intval( $login_count ) + 1 );

			wp_safe_redirect( admin_url() );
			exit;
		}

		/**
		 * List temporary admins with login count, expiration date, and delete option.
		 */
		private function list_access_users() {
			$args = array(
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => 'support_access_token_hash',
						'compare' => 'EXISTS',
					),
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
				echo '<th>' . esc_html__( 'Access URL', $this->textdomain ) . '</th>';
				echo '<th>' . esc_html__( 'Actions', $this->textdomain ) . '</th>';
				echo '</tr>';
				echo '</thead>';
				echo '<tbody>';

				foreach ( $user_query->results as $user ) {
					$login_count      = get_user_meta( $user->ID, 'support_access_login_count', true );
					$user_profile_url = get_edit_user_link( $user->ID );
					$expiration_time  = get_user_meta( $user->ID, 'support_access_expiration', true );
					$expiration_date  = $expiration_time ?
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiration_time ) : '';

					$limit      = get_user_meta( $user->ID, 'support_access_limit', true );
					$login_info = ( empty( $limit ) || '0' === $limit ) ? $login_count . ' / ∞' : $login_count . ' / ' . $limit;

					// Get user's role
					$user_roles = array_map(
						function( $role ) {
							return translate_user_role( wp_roles()->get_names()[ $role ] );
						},
						$user->roles
					);

					$access_url = $this->get_access_url( $user->ID );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $user_profile_url ); ?>"><?php echo esc_html( $user->user_login ); ?></a></td>
						<td><?php echo esc_html( implode( ', ', $user_roles ) ); ?></td>
						<td><?php echo esc_html( $login_info ); ?></td>
						<td><?php echo esc_html( $expiration_date ); ?></td>
						<td>
							<?php if ( $access_url ) : ?>
								<span class="action-icon" onclick="copyToClipboard('<?php echo esc_js( $access_url ); ?>')">
									<span class="dashicons dashicons-clipboard" 
										  title="<?php esc_attr_e( 'Copy URL', $this->textdomain ); ?>">
									</span> <?php esc_html_e( 'Copy URL', $this->textdomain ); ?>
								</span>
							<?php endif; ?>
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

					wp_redirect( admin_url( 'users.php?page=support-access' ) );
					exit;
				}
			}
		}

		/**
		 * Get the access URL for a user.
		 *
		 * @param int $user_id The user ID.
		 * @return string|false The access URL or false if not found.
		 */
		private function get_access_url( $user_id ) {
			// Get stored hash.
			$stored_hash = get_user_meta( $user_id, 'support_access_token_hash', true );
			if ( empty( $stored_hash ) ) {
				return false;
			}

			// Generate new token that will hash to the same value.
			$access_token = wp_generate_password( 32, false );
			update_user_meta( $user_id, 'support_access_token_hash', hash( 'sha256', $access_token ) );

			return add_query_arg(
				array(
					'support_access' => $user_id . '|' . $access_token,
				),
				home_url()
			);
		}
	}

}
