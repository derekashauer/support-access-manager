<?php
/**
 * Plugin Name: Support Access Manager
 * Description: Create temporary WordPress admin users with expiration and access limits.
 * Version: 1.0.0
 * Author: Derek Ashauer
 * Author URI: https://www.ashwebstudio.com
 * License: GPL-3.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Otherwise, load the class manually.
if ( ! class_exists( 'Support_Access_Manager' ) ) {
	require_once __DIR__ . '/class-support-access-manager.php';
	// Instantiate the class
	new Support_Access_Manager();
}
