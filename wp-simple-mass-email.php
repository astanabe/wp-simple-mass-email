<?php
/**
 * Plugin Name:       Simple Mass Email
 * Plugin URI:        https://github.com/astanabe/wp-simple-mass-email
 * Description:       A simple mass email sending plugin for WordPress
 * Author:            Akifumi S. Tanabe
 * Author URI:        https://github.com/astanabe
 * License:           GNU General Public License v2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-simple-mass-email
 * Domain Path:       /languages
 * Version:           0.1.0
 * Requires at least: 6.4
 *
 * @package           WP_Simple_Mass_Email
 */

// Security check
if (!defined('ABSPATH')) {
	exit;
}

// Create email tables
function wp_simple_mass_email_create_email_tables() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_content = $wpdb->prefix . 'wp_simple_mass_email_email_content';
	$sql_content = "CREATE TABLE IF NOT EXISTS $table_content (
		subject TEXT NOT NULL,
		body TEXT NOT NULL,
		status ENUM('active', 'paused', 'completed') NOT NULL DEFAULT 'completed',
		batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 1000
	) $charset_collate;";
	$table_recipients = $wpdb->prefix . 'wp_simple_mass_email_email_recipients';
	$sql_recipients = "CREATE TABLE IF NOT EXISTS $table_recipients (
		user_id BIGINT(20) NOT NULL PRIMARY KEY
	) $charset_collate;";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql_content);
	dbDelta($sql_recipients);
}
register_activation_hook(__FILE__, 'wp_simple_mass_email_create_email_tables');

// Delete email tables
function wp_simple_mass_email_delete_email_tables() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'wp_simple_mass_email_email_content';
	$table_recipients = $wpdb->prefix . 'wp_simple_mass_email_email_recipients';
	$content_exists = $wpdb->get_var("SELECT COUNT(*) FROM $table_content");
	$recipients_exists = $wpdb->get_var("SELECT COUNT(*) FROM $table_recipients");
	$cron_exists = wp_next_scheduled('wp_simple_mass_email_email_send');
	if ($content_exists > 0 || $recipients_exists > 0 || $cron_exists) {
		deactivate_plugins(plugin_basename(__FILE__));
		wp_die('<p>There are pending email jobs. Please cancel them before deactivating the plugin.</p><p><a href="' . esc_url(admin_url('admin.php?page=wp-simple-mass-email-send-email')) . '">Go to Email Management</a></p>', 'Plugin Deactivation Error', ['back_link' => true]);
	}
	wp_clear_scheduled_hook('wp_simple_mass_email_email_send');
	$wpdb->query("DROP TABLE IF EXISTS $table_content");
	$wpdb->query("DROP TABLE IF EXISTS $table_recipients");
}
register_deactivation_hook(__FILE__, 'wp_simple_mass_email_delete_email_tables');

// Function to display admin notice
function wp_simple_mass_email_display_admin_notice($message, $type = 'success') {
	wp_admin_notice(
		$message,
			[
				'type'    => $type, // 'success', 'error', 'warning', 'info'
				'dismiss' => true,
			]
	);
}

// Add "Mass Email" submenu to "Settings" menu of dashboard
function wp_simple_mass_email_add_send_email_to_dashboard() {
	add_management_page(
		'Mass Email',
		'Mass Email',
		'manage_options',
		'wp-simple-mass-email-send-email',
		'wp_simple_mass_email_send_email_page_screen'
	);
}
add_action('admin_menu', 'wp_simple_mass_email_add_send_email_to_dashboard');

// Screen function for "Mass Email" submenu of "Settings" menu of dashboard
function wp_simple_mass_email_send_email_page_screen() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'wp_simple_mass_email_email_content';
	$table_recipients = $wpdb->prefix . 'wp_simple_mass_email_email_recipients';
	$current_job = $wpdb->get_row("SELECT * FROM $table_content LIMIT 1");
	$is_sending = $current_job && ($current_job->status === 'active');
	$is_paused = $current_job && ($current_job->status === 'paused');
	echo '<div class="wrap"><h1>Mass Email</h1>';
	if ($is_sending) {
		echo '<h2>Sending Emails...</h2>';
		echo '<p>Email is currently being sent. You can pause the sending process.</p>';
		echo '<form method="post">';
		wp_nonce_field('wp_simple_mass_email_pause_send_email', 'wp_simple_mass_email_pause_send_email_nonce');
		echo '<input type="hidden" name="wp_simple_mass_email_pause_send_email" value="1">';
		echo '<p><input type="submit" class="button button-warning" value="Pause Sending"></p></form>';
		echo '<table class="form-table">
			<tr><th>Email Subject:</th><td><strong>' . esc_html($current_job->subject) . '</strong></td></tr>
			<tr><th>Email Body:</th><td><pre>' . esc_html($current_job->body) . '</pre></td></tr>';
		echo '<tr><th>Sending batch size every 10 min:</th><td><strong>' . esc_html($current_job->batch_size) . '</strong></td></tr>';
		$recipients = $wpdb->get_results("SELECT user_id FROM $table_recipients LIMIT $current_job->batch_size");
		$user_ids = wp_list_pluck($recipients, 'user_id');
		$args = [
			'include' => $user_ids,
			'fields'  => 'user_login',
		];
		$wp_user_query = new WP_User_Query($args);
		$user_logins = $wp_user_query->get_results();
		if (!empty($user_logins)) {
			echo '<tr><th>Recipient User(s) of next batch:</th><td><strong>' . implode('</strong><br /><strong>', $user_logins) . '</strong></td></tr>';
		}
		echo '</table>';
	}
	else if ($is_paused) {
		echo '<h2>Pausing Sending Emails...</h2>';
		echo '<p>Email sending is paused. You can resume or cancel the process.</p>';
		echo '<form method="post">';
		wp_nonce_field('wp_simple_mass_email_resume_send_email', 'wp_simple_mass_email_resume_send_email_nonce');
		echo '<input type="hidden" name="wp_simple_mass_email_resume_send_email" value="1">';
		echo '<p><input type="submit" class="button button-primary" value="Resume Sending"></p></form>';
		echo '<form method="post">';
		wp_nonce_field('wp_simple_mass_email_cancel_send_email', 'wp_simple_mass_email_cancel_send_email_nonce');
		echo '<input type="hidden" name="wp_simple_mass_email_cancel_send_email" value="1">';
		echo '<p><input type="submit" class="button button-danger" value="Cancel Sending"></p></form>';
		echo '<table class="form-table">
			<tr><th>Email Subject:</th><td><strong>' . esc_html($current_job->subject) . '</strong></td></tr>
			<tr><th>Email Body:</th><td><pre>' . esc_html($current_job->body) . '</pre></td></tr>';
		echo '<tr><th>Sending batch size every 10 min:</th><td><strong>' . esc_html($current_job->batch_size) . '</strong></td></tr>';
		$recipients = $wpdb->get_results("SELECT user_id FROM $table_recipients LIMIT $current_job->batch_size");
		$user_ids = wp_list_pluck($recipients, 'user_id');
		$args = [
			'include' => $user_ids,
			'fields'  => 'user_login',
		];
		$wp_user_query = new WP_User_Query($args);
		$user_logins = $wp_user_query->get_results();
		if (!empty($user_logins)) {
			echo '<tr><th>Recipient User(s) of next batch:</th><td><strong>' . implode('</strong><br /><strong>', $user_logins) . '</strong></td></tr>';
		}
		echo '</table>';
	}
	else {
		if (isset($_POST['wp_simple_mass_email_confirm_send_email']) && check_admin_referer('wp_simple_mass_email_confirm_send_email', 'wp_simple_mass_email_confirm_send_email_nonce')) {
			$subject = sanitize_text_field(wp_unslash($_POST['email_subject']));
			$body = sanitize_textarea_field(wp_unslash($_POST['email_body']));
			$roles = [];
			foreach ($_POST['email_recipient_roles'] as $role_key) {
				$role_key = sanitize_text_field($role_key);
				if (isset(wp_roles()->roles[$role_key])) {
					$roles[] = $role_key;
				}
				else {
					wp_simple_mass_email_display_admin_notice('Recipient role "' . $role_key . '" is invalid.', 'error');
					wp_safe_redirect(wp_get_referer());
					exit;
				}
			}
			$group_ids = [];
			if (function_exists('bp_is_active') && bp_is_active('groups')) {
				foreach ($_POST['email_recipient_groups'] as $group_id) {
					$group_id = sanitize_text_field($group_id);
					if (groups_get_group($group_id)) {
						$group_ids[] = $group_id;
					}
					else {
						wp_simple_mass_email_display_admin_notice('Recipient group "' . $group_id . '" is invalid.', 'error');
						wp_safe_redirect(wp_get_referer());
						exit;
					}
				}
			}
			$unlogged_only = !empty($_POST['email_unlogged_only']);
			$batch_size = sanitize_text_field($_POST['email_batch_size']);
			if (empty($subject) || empty($body) || empty($batch_size) || (empty($roles) && empty($group_ids))) {
				wp_simple_mass_email_display_admin_notice('Email subject, body, batch size and recipient roles or groups are required.', 'error');
				wp_safe_redirect(wp_get_referer());
				exit;
			}
			if (!empty($roles) && !empty($group_ids)) {
				wp_simple_mass_email_display_admin_notice('Both recipient roles and groups are given but this is invalid.', 'error');
				wp_safe_redirect(wp_get_referer());
				exit;
			}
			if ($batch_size < 10 || $batch_size > 10000) {
				wp_simple_mass_email_display_admin_notice('Batch size "' . $batch_size . '" is invalid.', 'error');
				wp_safe_redirect(wp_get_referer());
				exit;
			}
			echo '<h2>Confirm Email</h2>';
			echo '<p>Please review the email details before sending.</p>';
			echo '<table class="form-table">
				<tr><th>Email Subject:</th><td><strong>' . esc_html($subject) . '</strong></td></tr>
				<tr><th>Email Body:</th><td><pre>' . esc_html($body) . '</pre></td></tr>';
			$role_names = [];
			foreach ($roles as $role_key) {
				$role_data = wp_roles()->roles[$role_key];
				$role_names[] = esc_html($role_data['name']);
			}
			echo '<tr><th>Recipient Role(s):</th><td><strong>' . implode('</strong><br /><strong>', $role_names) . '</strong></td></tr>';
			if (function_exists('bp_is_active') && bp_is_active('groups')) {
				$group_names = [];
				foreach ($group_ids as $group_id) {
					$group = groups_get_group($group_id);
					$group_names[] = esc_html($group->name);
				}
				echo '<tr><th>Recipient Group(s):</th><td><strong>' . implode('</strong><br /><strong>', $group_names) . '</strong></td></tr>';
			}
			echo '<tr><th>Limit to users who never logged in:</th><td><strong>' . ($unlogged_only ? 'Yes' : 'No') . '</strong></td></tr>
				<tr><th>Sending batch size every 10 min:</th><td><strong>' . esc_html($batch_size) . '</strong></td></tr>
				</table>';
			echo '<form method="post">';
			wp_nonce_field('wp_simple_mass_email_perform_send_email', 'wp_simple_mass_email_perform_send_email_nonce');
			echo '<input type="hidden" name="email_subject" value="' . esc_attr($subject) . '">';
			echo '<input type="hidden" name="email_body" value="' . esc_attr($body) . '">';
			foreach ($roles as $role_key) {
				echo '<input type="hidden" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '">';
			}
			foreach ($group_ids as $group_id) {
				echo '<input type="hidden" name="email_recipient_groups[]" value="' . esc_attr($group_id) . '">';
			}
			echo '<input type="hidden" name="email_unlogged_only" value="' . ($unlogged_only ? '1' : '') . '">';
			echo '<input type="hidden" name="email_batch_size" value="' . esc_attr($batch_size) . '">';
			echo '<input type="hidden" name="wp_simple_mass_email_perform_send_email" value="1">';
			echo '<p><input type="submit" class="button button-primary" value="Send Email"></p></form>';
			echo '<form method="post">';
			wp_nonce_field('wp_simple_mass_email_edit_send_email', 'wp_simple_mass_email_edit_send_email_nonce');
			echo '<input type="hidden" name="email_subject" value="' . esc_attr($subject) . '">';
			echo '<input type="hidden" name="email_body" value="' . esc_attr($body) . '">';
			foreach ($roles as $role_key) {
				echo '<input type="hidden" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '">';
			}
			foreach ($group_ids as $group_id) {
				echo '<input type="hidden" name="email_recipient_groups[]" value="' . esc_attr($group_id) . '">';
			}
			echo '<input type="hidden" name="email_unlogged_only" value="' . ($unlogged_only ? '1' : '') . '">';
			echo '<input type="hidden" name="email_batch_size" value="' . esc_attr($batch_size) . '">';
			echo '<input type="hidden" name="wp_simple_mass_email_edit_send_email" value="1">';
			echo '<p><input type="submit" class="button" value="Edit"></p></form>';
		}
		else {
			if (isset($_POST['wp_simple_mass_email_edit_send_email']) && check_admin_referer('wp_simple_mass_email_edit_send_email', 'wp_simple_mass_email_edit_send_email_nonce')) {
				$subject = sanitize_text_field(wp_unslash($_POST['email_subject']));
				$body = sanitize_textarea_field(wp_unslash($_POST['email_body']));
				$roles = [];
				foreach ($_POST['email_recipient_roles'] as $role_key) {
					$role_key = sanitize_text_field($role_key);
					if (isset(wp_roles()->roles[$role_key])) {
						$roles[] = $role_key;
					}
					else {
						wp_simple_mass_email_display_admin_notice('Recipient role "' . $role_key . '" is invalid.', 'error');
						wp_safe_redirect(wp_get_referer());
						exit;
					}
				}
				$group_ids = [];
				if (function_exists('bp_is_active') && bp_is_active('groups')) {
					foreach ($_POST['email_recipient_groups'] as $group_id) {
						$group_id = sanitize_text_field($group_id);
						if (groups_get_group($group_id)) {
							$group_ids[] = $group_id;
						}
						else {
							wp_simple_mass_email_display_admin_notice('Recipient group "' . $group_id . '" is invalid.', 'error');
							wp_safe_redirect(wp_get_referer());
							exit;
						}
					}
				}
				$unlogged_only = !empty($_POST['email_unlogged_only']);
				$batch_size = sanitize_text_field($_POST['email_batch_size']);
				if (empty($subject) || empty($body) || empty($batch_size) || (empty($roles) && empty($group_ids))) {
					wp_simple_mass_email_display_admin_notice('Email subject, body, batch size and recipient roles or groups are required.', 'error');
					wp_safe_redirect(wp_get_referer());
					exit;
				}
				if (!empty($roles) && !empty($group_ids)) {
					wp_simple_mass_email_display_admin_notice('Both recipient roles and groups are given but this is invalid.', 'error');
					wp_safe_redirect(wp_get_referer());
					exit;
				}
				if ($batch_size < 10 || $batch_size > 10000) {
					wp_simple_mass_email_display_admin_notice('Batch size "' . $batch_size . '" is invalid.', 'error');
					wp_safe_redirect(wp_get_referer());
					exit;
				}
				echo '<h2>Edit Email</h2>';
				echo '<p>Input the email message sent to users.</p><p>The following variables can be used in email subject.</p><ul><li>{user_login}</li><li>{site_title}</li></ul><p>The following variables can be used in email body.</p><ul><li>{user_login}</li><li>{user_email}</li><li>{login_url}</li><li>{home_url}</li>';
				if (function_exists('bp_members_get_user_url')) {
					echo '<li>{profile_url}</li>';
				}
				echo '<li>{site_title}</li><li>{resetpass_url}</li></ul>';
				echo '<form method="post">';
				wp_nonce_field('wp_simple_mass_email_confirm_send_email', 'wp_simple_mass_email_confirm_send_email_nonce');
				echo '<table class="form-table">
					<tr><th><label for="email_subject">Email Subject</label></th>
					<td><input type="text" id="email_subject" name="email_subject" class="regular-text" value="' . esc_attr($subject) . '"></td></tr>
					<tr><th><label for="email_body">Email Body</label></th>
					<td><textarea id="email_body" name="email_body" rows="5" class="large-text">' . esc_attr($body) . '</textarea></td></tr>
					<tr><th><label for="email_recipient_roles">Recipient Roles</label></th><td>';
				$all_roles = wp_roles()->roles;
				foreach ($all_roles as $role_key => $role_data) {
					if (in_array($role_key, $roles)) {
						echo '<input type="checkbox" id="email_recipient_roles" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '" checked>' . esc_html($role_data['name']) . '<br />';
					}
					else {
						echo '<input type="checkbox" id="email_recipient_roles" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '">' . esc_html($role_data['name']) . '<br />';
					}
				}
				echo '</td></tr>';
				if (function_exists('bp_is_active') && bp_is_active('groups')) {
					$all_groups = wp_simple_mass_email_get_all_groups();
					if (!empty($all_groups)) {
						echo '<tr><th><label for="email_recipient_groups">Recipient Groups</label></th><td>';
						foreach ($all_groups as $group) {
							if (in_array($group['id'], $group_ids)) {
								echo '<input type="checkbox" id="email_recipient_groups" name="email_recipient_groups[]" value="' . esc_attr($group['id']) . '" checked>' . esc_html($group['name']) . '<br />';
							}
							else {
								echo '<input type="checkbox" id="email_recipient_groups" name="email_recipient_groups[]" value="' . esc_attr($group['id']) . '">' . esc_html($group['name']) . '<br />';
							}
						}
						echo '</td></tr>';
					}
				}
				echo '<tr><th><label for="email_unlogged_only">Limit to users who never logged in</label></th><td>';
				echo '<input type="checkbox" id="email_unlogged_only" name="email_unlogged_only"' . ($unlogged_only ? ' checked' : '') . '>';
				echo '</td></tr>
					<tr><th><label for="email_batch_size">Sending batch size every 10 min</label></th>
					<td><select id="email_batch_size" name="email_batch_size">';
				$batch_sizes = [10, 50, 100, 500, 1000, 5000, 10000];
				foreach ($batch_sizes as $value) {
					if ($value == $batch_size) {
						echo '<option value="' . $value . '" selected>' . $value . '</option>';
					}
					else {
						echo '<option value="' . $value . '">' . $value . '</option>';
					}
				}
				echo '</select></td></tr>
					</table>';
				echo '<input type="hidden" name="wp_simple_mass_email_confirm_send_email" value="1">';
				echo '<p><input type="submit" class="button button-primary" value="Confirm Email"></p></form>';
			}
			else {
				echo '<h2>Input Email</h2>';
				echo '<p>Input the email message sent to users.</p><p>The following variables can be used in email subject.</p><ul><li>{user_login}</li><li>{site_title}</li></ul><p>The following variables can be used in email body.</p><ul><li>{user_login}</li><li>{user_email}</li><li>{login_url}</li><li>{home_url}</li>';
				if (function_exists('bp_members_get_user_url')) {
					echo '<li>{profile_url}</li>';
				}
				echo '<li>{site_title}</li><li>{resetpass_url}</li></ul>';
				echo '<form method="post">';
				wp_nonce_field('wp_simple_mass_email_confirm_send_email', 'wp_simple_mass_email_confirm_send_email_nonce');
				echo '<table class="form-table">
					<tr><th><label for="email_subject">Email Subject</label></th>
					<td><input type="text" id="email_subject" name="email_subject" class="regular-text"></td></tr>
					<tr><th><label for="email_body">Email Body</label></th>
					<td><textarea id="email_body" name="email_body" rows="5" class="large-text"></textarea></td></tr>
					<tr><th><label for="email_recipient_roles">Recipient Roles</label></th><td>';
				$all_roles = wp_roles()->roles;
				foreach ($all_roles as $role_key => $role_data) {
					echo '<input type="checkbox" id="email_recipient_roles" name="email_recipient_roles[]" value="' . esc_attr($role_key) . '">' . esc_html($role_data['name']) . '<br />';
				}
				echo '</td></tr>';
				if (function_exists('bp_is_active') && bp_is_active('groups')) {
					$all_groups = wp_simple_mass_email_get_all_groups();
					if (!empty($all_groups)) {
						echo '<tr><th><label for="email_recipient_groups">Recipient Groups</label></th><td>';
						foreach ($all_groups as $group) {
							echo '<input type="checkbox" id="email_recipient_groups" name="email_recipient_groups[]" value="' . esc_attr($group['id']) . '">' . esc_html($group['name']) . '<br />';
						}
						echo '</td></tr>';
					}
				}
				echo '<tr><th><label for="email_unlogged_only">Limit to users who never logged in</label></th>
					<td><input type="checkbox" id="email_unlogged_only" name="email_unlogged_only"></td></tr>
					<tr><th><label for="email_batch_size">Sending batch size every 10 min</label></th>
					<td><select id="email_batch_size" name="email_batch_size">
						<option value="10">10</option>
						<option value="50">50</option>
						<option value="100">100</option>
						<option value="500">500</option>
						<option value="1000" selected>1000</option>
						<option value="5000">5000</option>
						<option value="10000">10000</option>
					</select></td></tr>
					</table>';
				echo '<input type="hidden" name="wp_simple_mass_email_confirm_send_email" value="1">';
				echo '<p><input type="submit" class="button button-primary" value="Confirm Email"></p></form>';
			}
		}
	}
	echo '</div>';
}

// Add custom schedules
function wp_simple_mass_email_add_cron_schedules($schedules) {
	$schedules['wp_simple_mass_email_every_ten_minutes'] = [
		'interval' => 600, // = 10 minutes
		'display'  => __('Every 10 Minutes')
	];
	return $schedules;
}
add_filter('cron_schedules', 'wp_simple_mass_email_add_cron_schedules');

// Button push handling
function wp_simple_mass_email_handle_email_controls() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'wp_simple_mass_email_email_content';
	$table_recipients = $wpdb->prefix . 'wp_simple_mass_email_email_recipients';
	if (isset($_POST['wp_simple_mass_email_pause_send_email']) && check_admin_referer('wp_simple_mass_email_pause_send_email', 'wp_simple_mass_email_pause_send_email_nonce')) {
		$wpdb->query("UPDATE $table_content SET status='paused' WHERE status='active'");
		wp_simple_mass_email_display_admin_notice('Email sending has been paused.', 'success');
	}
	if (isset($_POST['wp_simple_mass_email_resume_send_email']) && check_admin_referer('wp_simple_mass_email_resume_send_email', 'wp_simple_mass_email_resume_send_email_nonce')) {
		$wpdb->query("UPDATE $table_content SET status='active' WHERE status='paused'");
		wp_simple_mass_email_display_admin_notice('Email sending has resumed.', 'success');
		if (!wp_next_scheduled('wp_simple_mass_email_email_send')) {
			wp_schedule_event(time(), 'wp_simple_mass_email_every_ten_minutes', 'wp_simple_mass_email_email_send');
		}
	}
	if (isset($_POST['wp_simple_mass_email_cancel_send_email']) && check_admin_referer('wp_simple_mass_email_cancel_send_email', 'wp_simple_mass_email_cancel_send_email_nonce')) {
		$wpdb->query("DELETE FROM $table_content");
		$wpdb->query("DELETE FROM $table_recipients");
		wp_clear_scheduled_hook('wp_simple_mass_email_email_send');
		wp_simple_mass_email_display_admin_notice('Email sending has been completely canceled.', 'success');
	}
}
add_action('admin_init', 'wp_simple_mass_email_handle_email_controls');

// Send email
function wp_simple_mass_email_perform_send_email() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'wp_simple_mass_email_email_content';
	$table_recipients = $wpdb->prefix . 'wp_simple_mass_email_email_recipients';
	if (!isset($_POST['wp_simple_mass_email_perform_send_email']) || !check_admin_referer('wp_simple_mass_email_perform_send_email', 'wp_simple_mass_email_perform_send_email_nonce')) {
		return;
	}
	$subject = wp_specialchars_decode(sanitize_text_field(wp_unslash($_POST['email_subject'])), ENT_QUOTES);
	$body = wp_specialchars_decode(sanitize_textarea_field(wp_unslash($_POST['email_body'])), ENT_QUOTES);
	$roles = [];
	foreach ($_POST['email_recipient_roles'] as $role_key) {
		$role_key = sanitize_text_field($role_key);
		if (isset(wp_roles()->roles[$role_key])) {
			$roles[] = $role_key;
		}
		else {
			wp_simple_mass_email_display_admin_notice('Recipient role "' . $role_key . '" is invalid.', 'error');
			return;
		}
	}
	$group_ids = [];
	if (function_exists('bp_is_active') && bp_is_active('groups')) {
		foreach ($_POST['email_recipient_groups'] as $group_id) {
			$group_id = sanitize_text_field($group_id);
			if (groups_get_group($group_id)) {
				$group_ids[] = $group_id;
			}
			else {
				wp_simple_mass_email_display_admin_notice('Recipient group "' . $group_id . '" is invalid.', 'error');
				return;
			}
		}
	}
	$unlogged_only = !empty($_POST['email_unlogged_only']);
	$batch_size = sanitize_text_field($_POST['email_batch_size']);
	if (empty($subject) || empty($body) || empty($batch_size) || (empty($roles) && empty($group_ids))) {
		wp_simple_mass_email_display_admin_notice('Email subject, body, batch size and recipient roles or groups are required.', 'error');
		return;
	}
	if (!empty($roles) && !empty($group_ids)) {
		wp_simple_mass_email_display_admin_notice('Both recipient roles and groups are given but this is invalid.', 'error');
		return;
	}
	if ($batch_size < 10 || $batch_size > 10000) {
		wp_simple_mass_email_display_admin_notice('Batch size "' . $batch_size . '" is invalid.', 'error');
		return;
	}
	$wpdb->query("DELETE FROM $table_content");
	$wpdb->insert(
		$table_content,
		[
			'subject'    => $subject,
			'body'       => $body,
			'status'     => 'active',
			'batch_size' => $batch_size
		],
		['%s', '%s', '%s', '%d']
	);
	$page = 1;
	$total_users = 0;
	if (!empty($roles)) {
		do {
			$user_ids = wp_simple_mass_email_get_user_ids_by_roles($roles, $unlogged_only, 10000, $page);
			if (empty($user_ids)) {
				$new_user_ids = wp_simple_mass_email_get_user_ids_by_roles($roles, $unlogged_only, 10000, $page + 1);
				if (!empty($new_user_ids)) {
					$user_ids = $new_user_ids;
				} else {
					break;
				}
			}
			$values = [];
			$placeholders = [];
			foreach ($user_ids as $user_id) {
				$values[] = $user_id;
				$placeholders[] = "(%d)";
			}
			$query = "INSERT INTO $table_recipients (user_id) VALUES " . implode(',', $placeholders);
			$wpdb->query($wpdb->prepare($query, ...$values));
			$total_users += count($user_ids);
			$page++;
		} while (count($user_ids) >= 10000);
	}
	else if (!empty($group_ids)) {
		do {
			$user_ids = wp_simple_mass_email_get_user_ids_by_group_ids($group_ids, 10000, $page);
			if (empty($user_ids)) {
				$new_user_ids = wp_simple_mass_email_get_user_ids_by_group_ids($group_ids, 10000, $page + 1);
				if (!empty($new_user_ids)) {
					$user_ids = $new_user_ids;
				} else {
					break;
				}
			}
			$insert_user_ids = [];
			if ($unlogged_only) {
				$wp_user_query = new WP_User_Query([
					'include'    => $user_ids,
					'fields'     => 'ID',
					'meta_query' => [
						[
							'key'     => 'last_login',
							'compare' => 'NOT EXISTS'
						]
					]
				]);
				$insert_user_ids = $wp_user_query->get_results();
			}
			else {
				$insert_user_ids = $user_ids;
			}
			if (!empty($insert_user_ids)) {
				$values = [];
				$placeholders = [];
				foreach ($insert_user_ids as $insert_user_id) {
					$values[] = $insert_user_id;
					$placeholders[] = "(%d)";
				}
				$query = "INSERT INTO $table_recipients (user_id) VALUES " . implode(',', $placeholders);
				$wpdb->query($wpdb->prepare($query, ...$values));
				$total_users += count($insert_user_ids);
			}
			$page++;
		} while (count($user_ids) >= 10000);
	}
	if ($total_users === 0) {
		wp_simple_mass_email_display_admin_notice('No users found to send email.', 'error');
		return;
	}
	if (!wp_next_scheduled('wp_simple_mass_email_email_send')) {
		wp_schedule_event(time(), 'wp_simple_mass_email_every_ten_minutes', 'wp_simple_mass_email_email_send');
	}
	wp_simple_mass_email_display_admin_notice('Email sending has been reserved to run: <strong>' . esc_html($subject) . '</strong> to ' . esc_html($total_users) . ' user(s). Email sending will run within 10 minutes. You can pause email sending.', 'success');
}
add_action('admin_init', 'wp_simple_mass_email_perform_send_email');

// Send email in cron job
function wp_simple_mass_email_send_email_cron() {
	global $wpdb;
	$table_content = $wpdb->prefix . 'wp_simple_mass_email_email_content';
	$table_recipients = $wpdb->prefix . 'wp_simple_mass_email_email_recipients';
	$current_job = $wpdb->get_row("SELECT * FROM $table_content LIMIT 1");
	if (empty($current_job) || $current_job->status === 'paused') {
		return;
	}
	$recipients = $wpdb->get_results("SELECT user_id FROM $table_recipients LIMIT $current_job->batch_size");
	if (empty($recipients)) {
		$wpdb->query("DELETE FROM $table_content");
		wp_clear_scheduled_hook('wp_simple_mass_email_email_send');
		return;
	}
	$contains_resetpass_url = strpos($current_job->body, '{resetpass_url}') !== false;
	$usleep_time = (int) ((60 / $current_job->batch_size) * 1000000);
	foreach ($recipients as $recipient) {
		$user = get_userdata($recipient->user_id);
		if ($user) {
			$login_url = wp_login_url();
			$home_url = home_url();
			if (function_exists('bp_members_get_user_url')) {
				$profile_url = bp_members_get_user_url($recipient->user_id);
			}
			$site_title = get_bloginfo('name');
			$subject = str_replace(
				['{user_login}', '{site_title}'],
				[$user->user_login, $site_title],
				$current_job->subject
			);
			$body = str_replace(
				['{user_login}', '{user_email}', '{login_url}', '{home_url}', '{site_title}'],
				[$user->user_login, $user->user_email, $login_url, $home_url, $site_title],
				$current_job->body
			);
			if (isset($profile_url)) {
				$body = str_replace('{profile_url}', $profile_url, $body);
			}
			if ($contains_resetpass_url) {
				$key = get_password_reset_key($user);
				$resetpass_url = add_query_arg(
					[
					'action' => 'rp',
					'key'    => $key,
					'login'  => rawurlencode($user->user_login),
					],
					$login_url
				);
				$body = str_replace('{resetpass_url}', $resetpass_url, $body);
			}
			wp_mail($user->user_email, $subject, $body);
			usleep($usleep_time);
		}
	}
	$user_ids = wp_list_pluck($recipients, 'user_id');
	$wpdb->query("DELETE FROM $table_recipients WHERE user_id IN (" . implode(',', $user_ids) . ")");
	$remaining_users = $wpdb->get_var("SELECT COUNT(*) FROM $table_recipients");
	if ($remaining_users == 0) {
		$wpdb->query("DELETE FROM $table_content");
		wp_clear_scheduled_hook('wp_simple_mass_email_email_send');
	}
}
add_action('wp_simple_mass_email_email_send', 'wp_simple_mass_email_send_email_cron');

// Get users by roles
function wp_simple_mass_email_get_user_ids_by_roles($roles, $unlogged_only, $batch_size = 10000, $page = 1) {
	$args = [
		'role__in'  => empty($roles) ? null : $roles,
		'fields'    => 'ID',
		'number'    => $batch_size,
		'paged'     => $page,
	];
	if ($unlogged_only) {
		$args['meta_query'] = [
			[
				'key'     => 'last_login',
				'compare' => 'NOT EXISTS',
			],
		];
	}
	$wp_user_query = new WP_User_Query($args);
	$user_ids = $wp_user_query->get_results();
	if (empty($user_ids)) {
		return [];
	}
	return $user_ids;
}

// Get users by groups
function wp_simple_mass_email_get_user_ids_by_group_ids($group_ids, $batch_size = 10000, $page = 1) {
	$args = [
		'group_id'  => $group_ids,
		'per_page'  => $batch_size,
		'page'      => $page
	];
	$bp_group_member_query = new BP_Group_Member_Query($args);
	$user_ids = $bp_group_member_query->get_group_member_ids();
	if (empty($user_ids)) {
		return [];
	}
	return $user_ids;
}

// Get all groups (max: 999)
function wp_simple_mass_email_get_all_groups() {
	if (!function_exists('bp_has_groups')) {
		return [];
	}
	$groups = [];
	if (bp_has_groups(['per_page' => 999, 'orderby' => 'total_member_count', 'order' => 'DESC'])) {
		while (bp_groups()) {
			bp_the_group();
			$groups[] = ['id' => bp_get_group_id(), 'name' => bp_get_group_name()];
		}
	}
	return $groups;
}
