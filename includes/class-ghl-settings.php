<?php
/**
 * Shared dashboard settings for the GHL Elementor integration.
 *
 * @package GHL_Elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

class GHL_Elementor_Settings
{
    const OPTION_NAME = 'ghl_elementor_dashboard_settings';

    const US_STATES = [
        'alabama' => 'AL',
        'alaska' => 'AK',
        'arizona' => 'AZ',
        'arkansas' => 'AR',
        'california' => 'CA',
        'colorado' => 'CO',
        'connecticut' => 'CT',
        'delaware' => 'DE',
        'district of columbia' => 'DC',
        'florida' => 'FL',
        'georgia' => 'GA',
        'hawaii' => 'HI',
        'idaho' => 'ID',
        'illinois' => 'IL',
        'indiana' => 'IN',
        'iowa' => 'IA',
        'kansas' => 'KS',
        'kentucky' => 'KY',
        'louisiana' => 'LA',
        'maine' => 'ME',
        'maryland' => 'MD',
        'massachusetts' => 'MA',
        'michigan' => 'MI',
        'minnesota' => 'MN',
        'mississippi' => 'MS',
        'missouri' => 'MO',
        'montana' => 'MT',
        'nebraska' => 'NE',
        'nevada' => 'NV',
        'new hampshire' => 'NH',
        'new jersey' => 'NJ',
        'new mexico' => 'NM',
        'new york' => 'NY',
        'north carolina' => 'NC',
        'north dakota' => 'ND',
        'ohio' => 'OH',
        'oklahoma' => 'OK',
        'oregon' => 'OR',
        'pennsylvania' => 'PA',
        'rhode island' => 'RI',
        'south carolina' => 'SC',
        'south dakota' => 'SD',
        'tennessee' => 'TN',
        'texas' => 'TX',
        'utah' => 'UT',
        'vermont' => 'VT',
        'virginia' => 'VA',
        'washington' => 'WA',
        'west virginia' => 'WV',
        'wisconsin' => 'WI',
        'wyoming' => 'WY',
    ];

    /**
     * Get saved settings with defaults.
     *
     * @return array
     */
    public function get()
    {
        $settings = get_option(self::OPTION_NAME, []);

        if (!is_array($settings)) {
            $settings = [];
        }

        return wp_parse_args($settings, $this->get_defaults());
    }

    /**
     * Save settings.
     *
     * @param array $settings Settings to save.
     */
    public function save(array $settings)
    {
        $settings = wp_parse_args($settings, $this->get_defaults());
        $settings['setup_complete'] = $this->is_setup_complete($settings);

        update_option(self::OPTION_NAME, $settings, false);
    }

    /**
     * Sanitize dashboard form input and merge with current settings.
     *
     * @param array $input Raw form input.
     * @return array
     */
    public function sanitize_input(array $input)
    {
        $settings = $this->get();

        $settings['token'] = trim(sanitize_textarea_field($input['token'] ?? ''));
        $settings['location_id'] = sanitize_text_field($input['location_id'] ?? '');
        $settings['pipeline_id'] = sanitize_text_field($input['pipeline_id'] ?? '');
        $settings['message_custom_field_id'] = sanitize_text_field($input['message_custom_field_id'] ?? '');
        $settings['redirect_url'] = esc_url_raw($input['redirect_url'] ?? '');
        $settings['default_user_id'] = sanitize_text_field($input['default_user_id'] ?? '');
        $settings['default_pipeline_stage_id'] = sanitize_text_field($input['default_pipeline_stage_id'] ?? '');
        $settings['state_user_map'] = $this->sanitize_map($input['state_user_map'] ?? []);
        $settings['user_stage_map'] = $this->sanitize_map($input['user_stage_map'] ?? []);

        return $settings;
    }

    /**
     * Normalize a submitted or parsed state to the option key.
     *
     * @param string $state State name or abbreviation.
     * @return string
     */
    public function normalize_state($state)
    {
        $state = strtolower(trim((string) $state));

        if ($state === '') {
            return '';
        }

        foreach (self::US_STATES as $name => $abbr) {
            if ($state === $name || $state === strtolower($abbr)) {
                return $name;
            }
        }

        return $state;
    }

    /**
     * Determine the sales state from Elementor fields.
     *
     * @param array $fields Submitted fields.
     * @return string
     */
    public function get_sales_state_from_fields(array $fields)
    {
        foreach (['sales_state', 'state', 'event_state'] as $field_id) {
            if (!empty($fields[$field_id])) {
                return $this->normalize_state($fields[$field_id]);
            }
        }

        foreach (['event_address', 'event_location', 'address'] as $field_id) {
            if (empty($fields[$field_id])) {
                continue;
            }

            $state = $this->parse_state_from_text($fields[$field_id]);

            if ($state !== '') {
                return $state;
            }
        }

        return '';
    }

    /**
     * Get ordered state options for admin forms.
     *
     * @return array
     */
    public function get_state_options()
    {
        return self::US_STATES;
    }

    /**
     * Default option shape.
     *
     * @return array
     */
    private function get_defaults()
    {
        return [
            'token' => '',
            'location_id' => '',
            'pipeline_id' => '',
            'message_custom_field_id' => '',
            'redirect_url' => '',
            'default_user_id' => '',
            'default_pipeline_stage_id' => '',
            'state_user_map' => [],
            'user_stage_map' => [],
            'users' => [],
            'pipelines' => [],
            'stages' => [],
            'setup_complete' => false,
            'last_sync' => '',
        ];
    }

    /**
     * Sanitize a string map.
     *
     * @param mixed $map Raw map.
     * @return array
     */
    private function sanitize_map($map)
    {
        if (!is_array($map)) {
            return [];
        }

        $sanitized = [];

        foreach ($map as $key => $value) {
            $key = sanitize_text_field($key);
            $value = sanitize_text_field($value);

            if ($key === '' || $value === '') {
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Parse a US state name or abbreviation from address text.
     *
     * @param string $text Address or location text.
     * @return string
     */
    private function parse_state_from_text($text)
    {
        $text = strtolower((string) $text);

        foreach (self::US_STATES as $name => $abbr) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/i', $text)) {
                return $name;
            }

            if (preg_match('/(?:^|[\s,])' . preg_quote(strtolower($abbr), '/') . '(?:$|[\s,])/', $text)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * Determine whether the setup has enough saved data to route leads.
     *
     * @param array $settings Settings.
     * @return bool
     */
    private function is_setup_complete(array $settings)
    {
        return !empty($settings['token'])
            && !empty($settings['location_id'])
            && !empty($settings['pipeline_id'])
            && !empty($settings['default_user_id'])
            && !empty($settings['default_pipeline_stage_id'])
            && !empty($settings['state_user_map'])
            && !empty($settings['user_stage_map']);
    }
}
