<?php
/**
 * Plugin bootstrap class.
 *
 * @package GHL_Elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

class GHL_Elementor_Plugin
{
    const VERSION = '1.0.0';

    /**
     * @var GHL_Elementor_Settings
     */
    private $settings_repository;

    /**
     * @var GHL_Elementor_Admin_Page
     */
    private $admin_page;

    public function __construct()
    {
        $this->settings_repository = new GHL_Elementor_Settings();
        $this->admin_page = new GHL_Elementor_Admin_Page($this->settings_repository);
    }

    /**
     * Register WordPress hooks.
     */
    public function register()
    {
        add_action('elementor_pro/forms/actions/register', [$this, 'register_form_action']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        $this->admin_page->register();
    }

    /**
     * Register the custom Elementor Pro form action.
     *
     * @param object $form_actions_registrar Elementor form action registrar.
     */
    public function register_form_action($form_actions_registrar)
    {
        if (!class_exists('\ElementorPro\Modules\Forms\Classes\Action_Base')) {
            return;
        }

        require_once GHL_ELEMENTOR_PLUGIN_DIR . 'includes/class-ghl-elementor-form-action.php';

        $form_actions_registrar->register(new GHL_Elementor_Form_Action());
    }

    /**
     * Enqueue frontend helper script for progressive form hidden fields.
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            'ghl-elementor-progressive-form-prefill',
            GHL_ELEMENTOR_PLUGIN_URL . 'assets/js/progressive-form-prefill.js',
            [],
            self::VERSION,
            true
        );
    }
}
