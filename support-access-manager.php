<?php
/**
 * Plugin Name: Support Access Manager
 * Description: Create temporary WordPress admin users with expiration and access limits.
 * Version: 1.0.0
 * Author: Derek Ashauer
 * Author URI: https://www.ashwebstudio.com
 * License: MIT
 *
 * @package Support_Access_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Load the class file if it hasn't been loaded yet
if ( ! class_exists( 'Support_Access_Manager' ) ) {
	require_once __DIR__ . '/class-support-access-manager.php';
}

// Get or create the instance with default settings
Support_Access_Manager::instance();
