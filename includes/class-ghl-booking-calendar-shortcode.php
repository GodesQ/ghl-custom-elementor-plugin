<?php
/**
 * Dynamic GHL booking calendar shortcode.
 *
 * @package GHL_Elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

class GHL_Booking_Calendar_Shortcode
{
    const SHORTCODE = 'ghl_booking_calendar';
    const BOOKING_BASE_URL = 'https://api.leadconnectorhq.com/widget/booking/';
    const FORM_EMBED_SCRIPT_URL = 'https://link.msgsndr.com/js/form_embed.js';
    const FORM_EMBED_SCRIPT_HANDLE = 'ghl-leadconnector-form-embed';

    /**
     * @var GHL_Elementor_Settings
     */
    private $settings_repository;

    /**
     * @var GHL_Logger
     */
    private $logger;

    public function __construct(GHL_Elementor_Settings $settings_repository)
    {
        $this->settings_repository = $settings_repository;
        $this->logger = new GHL_Logger();
    }

    /**
     * Register shortcode hooks.
     */
    public function register()
    {
        add_shortcode(self::SHORTCODE, [$this, 'render']);
    }

    /**
     * Render the booking calendar iframe for the current contact owner.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function render($atts = [])
    {
        $atts = shortcode_atts(
            [
                'height' => 700,
                'contact_param' => 'contact_id',
                'opportunity_param' => 'opportunity_id',
            ],
            $atts,
            self::SHORTCODE
        );

        $height = max(300, absint($atts['height']));
        $contact_param = sanitize_key($atts['contact_param']);
        $opportunity_param = sanitize_key($atts['opportunity_param']);
        $contact_id = $this->get_query_value($contact_param);
        $opportunity_id = $this->get_query_value($opportunity_param);
        $settings = $this->settings_repository->get();
        $calendar_id = $this->resolve_calendar_id($settings, $contact_id);

        if (empty($calendar_id)) {
            return $this->render_unavailable_message();
        }

        wp_enqueue_script(
            self::FORM_EMBED_SCRIPT_HANDLE,
            self::FORM_EMBED_SCRIPT_URL,
            [],
            null,
            true
        );

        $iframe_url = $this->build_iframe_url($calendar_id, $contact_id, $opportunity_id);
        $iframe_id = 'ghl-booking-calendar-' . wp_unique_id();

        return sprintf(
            '<iframe src="%1$s" height="%2$d" style="width: 100%%; border: none; overflow: hidden; min-height: %2$dpx;" scrolling="no" id="%3$s" title="%4$s"></iframe>',
            esc_url($iframe_url),
            $height,
            esc_attr($iframe_id),
            esc_attr__('Schedule Appointment', 'ghl-elementor')
        );
    }

    /**
     * Read and sanitize a query string value.
     *
     * @param string $param Query parameter name.
     * @return string
     */
    private function get_query_value($param)
    {
        if ($param === '' || !isset($_GET[$param])) {
            return '';
        }

        return sanitize_text_field(wp_unslash($_GET[$param]));
    }

    /**
     * Resolve the calendar ID from the contact owner with default-user fallback.
     *
     * @param array  $settings Dashboard settings.
     * @param string $contact_id GHL contact ID.
     * @return string
     */
    private function resolve_calendar_id(array $settings, $contact_id)
    {
        $calendar_map = is_array($settings['user_calendar_map'] ?? null) ? $settings['user_calendar_map'] : [];
        $default_calendar_id = $this->get_default_calendar_id($settings, $calendar_map);

        if ($contact_id !== '' && !empty($settings['token'])) {
            $assigned_user_id = $this->get_contact_assigned_user_id($settings, $contact_id);

            if ($assigned_user_id !== '' && !empty($calendar_map[$assigned_user_id])) {
                return $calendar_map[$assigned_user_id];
            }

            $this->logger->error('Booking calendar using default calendar fallback.', [
                'contact_id' => $contact_id,
                'assigned_user_id' => $assigned_user_id,
            ]);
        } elseif ($contact_id === '') {
            $this->logger->error('Booking calendar contact ID missing; using default calendar fallback.');
        } else {
            $this->logger->error('Booking calendar token missing; using default calendar fallback.', [
                'contact_id' => $contact_id,
            ]);
        }

        if ($default_calendar_id !== '') {
            return $default_calendar_id;
        }

        $this->logger->error('Booking calendar could not resolve a calendar.', [
            'contact_id' => $contact_id,
            'default_user_id' => $settings['default_user_id'] ?? '',
        ]);

        return '';
    }

    /**
     * Get the default user's assigned calendar.
     *
     * @param array $settings Dashboard settings.
     * @param array $calendar_map User-to-calendar map.
     * @return string
     */
    private function get_default_calendar_id(array $settings, array $calendar_map)
    {
        $default_user_id = trim((string) ($settings['default_user_id'] ?? ''));

        if ($default_user_id === '' || empty($calendar_map[$default_user_id])) {
            return '';
        }

        return trim((string) $calendar_map[$default_user_id]);
    }

    /**
     * Fetch a contact and return its assigned user ID.
     *
     * @param array  $settings Dashboard settings.
     * @param string $contact_id GHL contact ID.
     * @return string
     */
    private function get_contact_assigned_user_id(array $settings, $contact_id)
    {
        $api_client = new GHL_API_Client($settings['token'], $this->logger);
        $contact_response = $api_client->get_contact($contact_id);

        if (is_wp_error($contact_response)) {
            $this->logger->error('Booking calendar contact lookup failed.', [
                'error' => $contact_response->get_error_message(),
                'contact_id' => $contact_id,
            ]);

            return '';
        }

        if (isset($contact_response['contact']['assignedTo'])) {
            return trim((string) $contact_response['contact']['assignedTo']);
        }

        if (isset($contact_response['assignedTo'])) {
            return trim((string) $contact_response['assignedTo']);
        }

        return '';
    }

    /**
     * Build the GHL iframe URL.
     *
     * @param string $calendar_id Calendar ID.
     * @param string $contact_id Contact ID.
     * @param string $opportunity_id Opportunity ID.
     * @return string
     */
    private function build_iframe_url($calendar_id, $contact_id, $opportunity_id)
    {
        $url = self::BOOKING_BASE_URL . rawurlencode($calendar_id);
        $query_args = [];

        if ($contact_id !== '') {
            $query_args['contact_id'] = $contact_id;
        }

        if ($opportunity_id !== '') {
            $query_args['opportunity_id'] = $opportunity_id;
        }

        if (!empty($query_args)) {
            $url = add_query_arg($query_args, $url);
        }

        return $url;
    }

    /**
     * Render a public-safe unavailable state.
     *
     * @return string
     */
    private function render_unavailable_message()
    {
        return sprintf(
            '<div class="ghl-booking-calendar-unavailable">%s</div>',
            esc_html__('We could not load the scheduling calendar. Please contact us for help booking your appointment.', 'ghl-elementor')
        );
    }
}
