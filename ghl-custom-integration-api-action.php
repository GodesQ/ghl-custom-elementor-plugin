<?php
/**
 * Plugin Name: GHL Elementor Form Action
 * Description: Sends Elementor Pro form submissions to GoHighLevel Contacts and Opportunities.
 * Version: 2.0.1
 * Text Domain: ghl-elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('GHL_ELEMENTOR_PLUGIN_FILE')) {
    define('GHL_ELEMENTOR_PLUGIN_FILE', __FILE__);
}

if (!defined('GHL_ELEMENTOR_PLUGIN_DIR')) {
    define('GHL_ELEMENTOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('GHL_ELEMENTOR_PLUGIN_URL')) {
    define('GHL_ELEMENTOR_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once GHL_ELEMENTOR_PLUGIN_DIR . 'includes/class-ghl-logger.php';
require_once GHL_ELEMENTOR_PLUGIN_DIR . 'includes/class-ghl-api-client.php';
require_once GHL_ELEMENTOR_PLUGIN_DIR . 'includes/class-ghl-settings.php';
require_once GHL_ELEMENTOR_PLUGIN_DIR . 'includes/class-ghl-field-mapper.php';
require_once GHL_ELEMENTOR_PLUGIN_DIR . 'includes/class-ghl-admin-page.php';
require_once GHL_ELEMENTOR_PLUGIN_DIR . 'includes/class-ghl-plugin.php';

$ghl_elementor_plugin = new GHL_Elementor_Plugin();
$ghl_elementor_plugin->register();
