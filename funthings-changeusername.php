<?php

	if (!defined('ABSPATH')) exit; // Exit if accessed directly

	/**
	 * Plugin Name: Fun Things&trade; - Change Usernames
	 * Plugin URI: https://lowlovelformat.dev
	 * Description: Allows admins to change usernames.
	 * Version: 2.2
	 * Author: RexAK
	 * Author URI: https://lowlovelformat.dev
	 * License: GPLv2
	 */

	require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';
	use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
	$update_checker = Puc_v4_Factory::buildUpdateChecker(
		'https://github.com/RexAK-SOF/FunThings', // GitHub repository URL.
		__FILE__, // Full path to the main plugin file.
		'funthings-changeusername' // Plugin slug.
	);
	$update_checker->getVcsApi()->enableReleaseAssets();

	$update_checker->setBranch('main');
	add_filter('upgrader_source_selection', function ($source, $remote_source, $upgrader) {
		$plugin_slug = 'funthings-changeusername'; // Your plugin slug.

		// Check if the extracted folder contains the plugin slug.
		if (strpos(basename($source), $plugin_slug) === false) {
			$corrected_path = trailingslashit($remote_source) . $plugin_slug;
			if (@rename($source, $corrected_path)) {
				return $corrected_path;
			}
		}

		return $source;
	}, 10, 3);
	/**
	 * Main Class for Fun Things - Change Usernames Plugin
	 *
	 * This class handles:
	 * - Adding a custom field to the WordPress user profile screen for username changes.
	 * - Saving and validating the new username.
	 * - Keeping track of the original username and updates.
	 *
	 * @since 1.0
	 */

	class FUNTHINGS_Change_Username {

		/**
		 * Plugin version
		 *
		 * @var string
		 */
		public string $version = '2.2';

		/**
		 * Constructor.
		 *
		 * Initializes the hooks for adding and saving the custom username field.
		 *
		 * @since 1.0
		 */
		function __construct() {
			// Add the custom field to the user profile screen.
			add_action('personal_options', [$this, 'custom_user_profile_field'], 999);

			// Save the custom field data when the user profile is updated.
			add_action('edit_user_profile_update', [$this, 'save_change_username_personal_options']);

			// add a quick style for the custom field
			add_action('admin_enqueue_scripts', [$this, 'enqueue_funthings_styles']);

		}

		/**
		 * Adds a custom "Change Username" field to the user profile screen.
		 *
		 * This function ensures:
		 * - Profile owners do not see the "Change Username" field.
		 * - Proper sanitization and escaping for outputs.
		 * - CSS is loaded via `wp_enqueue_style` instead of inline.
		 *
		 * @param WP_User $user The user object for the profile being edited.
		 * @since 1.0
		 */
		function custom_user_profile_field($user) {
			// Prevent profile owners from seeing this field.
			if ($user->ID === get_current_user_id()) {
				return;
			}

			// Fetch the original username if it exists.
			$original_name = '';
			$stored_original = get_user_meta($user->ID, 'original_username', true);
			if (!empty($stored_original)) {
				$original_name = '<br>' . esc_html__('Original Username: ', 'funthings') . esc_html($stored_original);
			}
			?>
			<table class="form-table" id="funthings-change-username">
				<tr>
					<th>
						<label for="change_username"><?php esc_html_e('Change Username', 'funthings'); ?></label>
					</th>
					<td>
						<input type="text" name="change_username" id="change_username"
							   value="<?php echo esc_attr(get_user_meta($user->ID, 'change_username', true)); ?>"
							   class="regular-text"/><br/>
						<span class="description">
                    <?php esc_html_e('This will permanently change the username and may have unforeseen effects. Use at your own risk!', 'funthings'); ?>
					<?php wp_nonce_field('funthings_change_username', 'change_username_nonce'); ?>

					<?php echo $original_name; ?>
                </span>
					</td>
				</tr>
			</table>
			<?php
		}

		/**
		 * Saves the new username entered in the "Change Username" field.
		 *
		 * This function:
		 * - Verifies nonce for security.
		 * - Sanitizes the input username.
		 * - Ensures the username is unique and not in conflict.
		 * - Updates the username directly in the database using `$wpdb`.
		 * - Keeps track of the original username for reference.
		 * - Displays success or error messages to the admin.
		 *
		 * @param int $user_id The ID of the user being updated.
		 * @since 1.0
		 */
		function save_change_username_personal_options($user_id) {

			// Security: Verify nonce to prevent CSRF.
			if (
				!isset($_POST['change_username_nonce']) ||
				!wp_verify_nonce($_POST['change_username_nonce'], 'funthings_change_username')
			) {
				wp_die(__('Security check failed. Please try again.', 'funthings'));
			}

			// Permission check: Ensure current user can edit this user.
			if (!current_user_can('edit_user', $user_id)) {
				wp_die(__('You do not have permission to edit this user.', 'funthings'));
			}

			// Input validation: Ensure the username field is set and not empty.
			if (empty($_POST['change_username'])) {
				return;
			}

			// Sanitize the new username.
			$new_username = sanitize_user($_POST['change_username'], true);

			// Check for conflicts: Ensure the new username does not already exist.
			if (username_exists($new_username)) {
				add_action('admin_notices', function () {
					echo '<div class="notice notice-error"><p>' . esc_html__('The username already exists. Please choose another.', 'funthings') . '</p></div>';
				});
				return;
			}

			// Retrieve and store the original username if not already saved.
			$original_username = get_user_meta($user_id, 'original_username', true);
			if (!$original_username) {
				$current_user_data = get_userdata($user_id);
				update_user_meta($user_id, 'original_username', $current_user_data->user_login);
			}

			// Prevent redundant updates: Check if the new username is the same as the current one.
			$current_user_data = get_userdata($user_id);
			if ($current_user_data->user_login === $new_username) {
				return;
			}

			// Update the username directly in the database.
			global $wpdb;
			$result = $wpdb->update(
				$wpdb->users, // Table name
				['user_login' => $new_username], // New data
				['ID' => $user_id], // Condition
				['%s'], // Data format
				['%d']  // Where clause format
			);

			if (false === $result) {
				// Show error notice if the update failed.
				add_action('admin_notices', function () {
					echo '<div class="notice notice-error"><p>' . esc_html__('Failed to update the username. Please try again.', 'funthings') . '</p></div>';
				});
				return;
			}

			// Update user meta to track the last username change.
			update_user_meta($user_id, 'last_username_update', $new_username);

			// Success notice.
			add_action('admin_notices', function () {
				echo '<div class="notice notice-success"><p>' . esc_html__('Username successfully updated.', 'funthings') . '</p></div>';
			});
		}


		/**
		 * Enqueues custom styles for the "Change Username" field.
		 *
		 * Loads CSS on the user profile and user edit screens only.
		 *
		 * @since 1.0
		 */
		function enqueue_funthings_styles($hook) {
			// Load styles only on profile.php and user-edit.php screens.
			if ('profile.php' === $hook || 'user-edit.php' === $hook) {
				wp_enqueue_style(
					'funthings-admin-styles',
					plugin_dir_url(__FILE__) . 'css/admin-style.css',
					[],
					'1.0',
					'all'
				);
			}
		}
	}

	// Initialize the plugin.
	$funthings_change_username = new FUNTHINGS_Change_Username();

