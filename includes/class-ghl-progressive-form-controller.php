<?php
/**
 * Public progressive form data endpoint.
 *
 * @package GHL_Elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

class GHL_Progressive_Form_Controller
{
    const REST_NAMESPACE = 'ghl-elementor/v1';
    const EVENT_ITEM_FIELD_KEY = 'contact.event_item';
    const PROGRESSIVE_WEBHOOK_URL = 'https://services.leadconnectorhq.com/hooks/7M5Xl7fUp1LSYtHLt72T/webhook-trigger/35864415-50cc-4cb2-ae42-a4177467476e';

    /**
     * @var GHL_Elementor_Settings
     */
    private $settings_repository;

    /**
     * @var GHL_Logger
     */
    private $logger;

    /**
     * @var array
     */
    private $uploaded_file_field_keys = [];

    public function __construct(GHL_Elementor_Settings $settings_repository)
    {
        $this->settings_repository = $settings_repository;
        $this->logger = new GHL_Logger();
    }

    /**
     * Register WordPress hooks.
     */
    public function register()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST routes.
     */
    public function register_routes()
    {
        register_rest_route(
            self::REST_NAMESPACE,
            '/progressive-form/contact',
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_contact_event_items'],
                'permission_callback' => '__return_true',
                'args' => [
                    'contact_id' => [
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/progressive-form/opportunity',
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'submit_opportunity_event_details'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * Return safe progressive form event item data for a GHL contact.
     *
     * @param \WP_REST_Request $request REST request.
     * @return \WP_REST_Response
     */
    public function get_contact_event_items(\WP_REST_Request $request)
    {
        $contact_id = trim((string) $request->get_param('contact_id'));
        $settings = $this->settings_repository->get();

        if ($contact_id === '' || empty($settings['token'])) {
            $this->logger->error('Progressive form contact lookup skipped.', [
                'has_contact_id' => $contact_id === '' ? 'no' : 'yes',
                'has_token' => empty($settings['token']) ? 'no' : 'yes',
            ]);

            return $this->build_response([], []);
        }

        $api_client = new GHL_API_Client($settings['token'], $this->logger);
        $contact_response = $api_client->get_contact($contact_id);

        if (is_wp_error($contact_response)) {
            $this->logger->error('Progressive form contact lookup failed.', [
                'contact_id' => $contact_id,
                'error' => $contact_response->get_error_message(),
            ]);

            return $this->build_response([], []);
        }

        $event_items = $this->parse_event_items($this->extract_event_item_value($contact_response));
        $event_item_field_id = empty($settings['location_id'])
            ? ''
            : $api_client->get_custom_field_id_by_key($settings['location_id'], self::EVENT_ITEM_FIELD_KEY);

        if (empty($event_items) && $event_item_field_id !== '') {
            $event_items = $this->parse_event_items(
                $this->extract_event_item_value($contact_response, $event_item_field_id)
            );
        }

        $allowed_steps = $this->normalize_allowed_steps($event_items);

        if (empty($allowed_steps)) {
            $this->logger->error('Progressive form contact had no matching event items.', [
                'contact_id' => $contact_id,
                'event_items' => $event_items,
            ]);
        }

        return $this->build_response($event_items, $allowed_steps);
    }

    /**
     * Save progressive custom HTML widget values to GHL contact and opportunity custom fields.
     *
     * @param \WP_REST_Request $request REST request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function submit_opportunity_event_details(\WP_REST_Request $request)
    {
        $settings = $this->settings_repository->get();

        if (empty($settings['token']) || empty($settings['location_id'])) {
            $this->logger->error('Progressive submission skipped because GHL is not configured.');

            return new \WP_Error(
                'ghl_progressive_not_configured',
                'GHL integration is not configured.',
                ['status' => 500]
            );
        }

        $fields = $this->get_submission_fields($request);

        if (is_wp_error($fields)) {
            return $fields;
        }

        $contact_id = trim((string) ($fields['contact_id'] ?? $request->get_param('contact_id')));
        $opportunity_id = trim((string) ($fields['opportunity_id'] ?? $request->get_param('opportunity_id')));

        if ($contact_id === '' || $opportunity_id === '') {
            $this->logger->error('Progressive opportunity submission missing IDs.', [
                'has_contact_id' => $contact_id === '' ? 'no' : 'yes',
                'has_opportunity_id' => $opportunity_id === '' ? 'no' : 'yes',
            ]);

            return new \WP_Error(
                'ghl_progressive_missing_ids',
                'Contact ID and opportunity ID are required.',
                ['status' => 400]
            );
        }

        unset($fields['contact_id'], $fields['opportunity_id']);

        $api_client = new GHL_API_Client($settings['token'], $this->logger);
        $field_mapper = new GHL_Field_Mapper();
        $contact_custom_fields = $field_mapper->build_progressive_html_contact_custom_fields(
            $fields,
            $api_client,
            $settings['location_id']
        );
        $opportunity_custom_fields = $field_mapper->build_progressive_html_opportunity_custom_fields(
            $fields,
            $api_client,
            $settings['location_id']
        );
        $custom_fields = array_merge($contact_custom_fields, $opportunity_custom_fields);

        if (empty($custom_fields)) {
            $this->logger->info('Progressive submission had no custom fields to update.', [
                'contact_id' => $contact_id,
                'opportunity_id' => $opportunity_id,
            ]);
            $this->send_progressive_webhook($field_mapper, $contact_id, $opportunity_id, $fields);

            return rest_ensure_response([
                'success' => true,
                'message' => 'No event details were submitted.',
                'updated_fields' => [],
            ]);
        }

        $this->logger->info('Progressive submission fields mapped.', [
            'contact_id' => $contact_id,
            'opportunity_id' => $opportunity_id,
            'contact_fields' => array_values(array_map([$this, 'get_custom_field_key'], $contact_custom_fields)),
            'opportunity_fields' => array_values(array_map([$this, 'get_custom_field_key'], $opportunity_custom_fields)),
            'file_fields' => $this->get_file_field_keys($fields),
        ]);

        if (!empty($contact_custom_fields)) {
            $contact_field_groups = $this->split_custom_fields_by_upload_type(
                $contact_custom_fields,
                GHL_Field_Mapper::PROGRESSIVE_HTML_CONTACT_FIELDS
            );
            $contact_response = $this->update_contact_custom_fields(
                $api_client,
                $contact_id,
                $contact_field_groups['standard'],
                $contact_field_groups['file']
            );

            if (is_wp_error($contact_response)) {
                $this->logger->error('Progressive contact custom field update failed.', [
                    'contact_id' => $contact_id,
                    'error' => $contact_response->get_error_message(),
                ]);

                return new \WP_Error(
                    'ghl_progressive_update_failed',
                    'GHL contact could not be updated.',
                    ['status' => 502]
                );
            }
        }

        if (!empty($opportunity_custom_fields)) {
            $opportunity_response = $this->update_opportunity_custom_fields(
                $api_client,
                $opportunity_id,
                $opportunity_custom_fields,
                []
            );

            if (is_wp_error($opportunity_response)) {
                $this->logger->error('Progressive opportunity custom field update failed.', [
                    'opportunity_id' => $opportunity_id,
                    'error' => $opportunity_response->get_error_message(),
                ]);

                return new \WP_Error(
                    'ghl_progressive_update_failed',
                    'GHL opportunity could not be updated.',
                    ['status' => 502]
                );
            }
        }

        $this->update_progressive_contact_tags($api_client, $contact_id);
        $updated_fields = array_values(array_map([$this, 'get_custom_field_key'], $custom_fields));
        $this->send_progressive_webhook($field_mapper, $contact_id, $opportunity_id, $fields);

        $this->logger->info('Progressive event details updated.', [
            'contact_id' => $contact_id,
            'opportunity_id' => $opportunity_id,
            'updated_fields' => $updated_fields,
        ]);

        return rest_ensure_response([
            'success' => true,
            'message' => 'Event details were submitted.',
            'updated_fields' => $updated_fields,
        ]);
    }

    /**
     * Send progressive form details to the configured webhook.
     *
     * @param GHL_Field_Mapper $field_mapper Field mapper.
     * @param string           $contact_id Contact ID.
     * @param string           $opportunity_id Opportunity ID.
     * @param array            $fields Submitted fields.
     */
    private function send_progressive_webhook(GHL_Field_Mapper $field_mapper, $contact_id, $opportunity_id, array $fields)
    {
        $payload = $field_mapper->build_progressive_webhook_payload($contact_id, $opportunity_id, $fields);

        $response = wp_remote_post(
            self::PROGRESSIVE_WEBHOOK_URL,
            [
                'timeout' => GHL_API_Client::TIMEOUT,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            $this->logger->error('Progressive webhook failed.', [
                'error' => $response->get_error_message(),
                'opportunity_id' => $opportunity_id,
            ]);
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code < 200 || $status_code >= 300) {
            $this->logger->error('Progressive webhook returned non-success status.', [
                'status_code' => $status_code,
                'opportunity_id' => $opportunity_id,
            ]);
            return;
        }

        $this->logger->info('Progressive webhook sent.', [
            'status_code' => $status_code,
            'opportunity_id' => $opportunity_id,
        ]);
    }

    /**
     * Build sanitized field values from body params and uploaded file URLs.
     *
     * @param \WP_REST_Request $request REST request.
     * @return array|\WP_Error
     */
    private function get_submission_fields(\WP_REST_Request $request)
    {
        $fields = [];
        $this->uploaded_file_field_keys = [];

        foreach ($request->get_body_params() as $key => $value) {
            $key = sanitize_key($key);

            if ($key === '') {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', array_map('sanitize_text_field', $value));
            }

            $fields[$key] = sanitize_text_field((string) $value);
        }

        foreach ($request->get_file_params() as $key => $file) {
            $key = sanitize_key($key);

            if ($key === '') {
                continue;
            }

            $file_urls = $this->upload_progressive_files($file);

            if (is_wp_error($file_urls)) {
                return $file_urls;
            }

            if (!empty($file_urls)) {
                $fields[$key] = $this->format_file_urls_for_custom_field($file_urls);
                $this->uploaded_file_field_keys[] = $key;
            }
        }

        return $fields;
    }

    /**
     * Format uploaded file URLs for a single GHL file custom field.
     *
     * @param array $file_urls Uploaded file URLs.
     * @return string
     */
    private function format_file_urls_for_custom_field(array $file_urls)
    {
        $file_urls = array_values(array_filter(array_map('esc_url_raw', $file_urls)));

        return (string) ($file_urls[0] ?? '');
    }

    /**
     * Upload files to WordPress and return public URLs for GHL file fields.
     *
     * @param mixed $file Uploaded file array.
     * @return array|\WP_Error
     */
    private function upload_progressive_files($file)
    {
        if (!is_array($file) || empty($file['name'])) {
            return [];
        }

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $file_urls = [];

        foreach ($this->normalize_uploaded_files($file) as $uploaded_file) {
            $error = (int) ($uploaded_file['error'] ?? UPLOAD_ERR_OK);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                return new \WP_Error(
                    'ghl_progressive_upload_failed',
                    'One of the uploaded files could not be processed.',
                    ['status' => 400]
                );
            }

            $result = wp_handle_upload(
                $uploaded_file,
                [
                    'test_form' => false,
                    'mimes' => $this->get_allowed_upload_mimes(),
                    'unique_filename_callback' => [$this, 'make_unique_upload_filename'],
                ]
            );

            if (!empty($result['error'])) {
                return new \WP_Error(
                    'ghl_progressive_upload_failed',
                    $result['error'],
                    ['status' => 400]
                );
            }

            if (!empty($result['url'])) {
                $file_urls[] = esc_url_raw($result['url']);
            }
        }

        return $file_urls;
    }

    /**
     * Build a fresh filename so GHL sees repeat uploads as new file values.
     *
     * @param string $dir Upload directory.
     * @param string $name Original filename without extension.
     * @param string $ext Original extension including dot.
     * @return string
     */
    public function make_unique_upload_filename($dir, $name, $ext)
    {
        $base_name = sanitize_file_name($name);

        if ($base_name === '') {
            $base_name = 'ghl-upload';
        }

        return sprintf(
            '%s-%s-%s%s',
            $base_name,
            gmdate('YmdHis'),
            strtolower(wp_generate_password(6, false, false)),
            $ext
        );
    }

    /**
     * Normalize single and multiple upload arrays into single-file arrays.
     *
     * @param array $file Uploaded file array.
     * @return array
     */
    private function normalize_uploaded_files(array $file)
    {
        if (!is_array($file['name'])) {
            return [$file];
        }

        $files = [];

        foreach ($file['name'] as $index => $name) {
            $files[] = [
                'name' => $name,
                'type' => $file['type'][$index] ?? '',
                'tmp_name' => $file['tmp_name'][$index] ?? '',
                'error' => $file['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $file['size'][$index] ?? 0,
            ];
        }

        return $files;
    }

    /**
     * Supported file types for progressive inspiration uploads.
     *
     * @return array
     */
    private function get_allowed_upload_mimes()
    {
        return [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
        ];
    }

    /**
     * Return submitted file field keys for diagnostics without logging URLs.
     *
     * @param array $fields Submitted fields.
     * @return array
     */
    private function get_file_field_keys(array $fields)
    {
        $file_fields = $this->uploaded_file_field_keys;

        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $file_fields[] = $key;
            }
        }

        return $file_fields;
    }

    /**
     * Split uploaded file custom fields from normal text custom fields.
     *
     * @param array $custom_fields Mapped GHL custom fields.
     * @param array $field_mapping GHL field keys mapped to form field IDs.
     * @return array
     */
    private function split_custom_fields_by_upload_type(array $custom_fields, array $field_mapping)
    {
        $uploaded_field_lookup = array_fill_keys($this->uploaded_file_field_keys, true);
        $groups = [
            'standard' => [],
            'file' => [],
        ];

        foreach ($custom_fields as $custom_field) {
            $field_key = (string) ($custom_field['key'] ?? '');
            $form_field_id = $field_mapping[$field_key] ?? '';
            $group_key = isset($uploaded_field_lookup[$form_field_id]) ? 'file' : 'standard';

            $groups[$group_key][] = $custom_field;
        }

        return $groups;
    }

    /**
     * Update contact custom fields, resetting uploaded file fields before replacement.
     *
     * @param GHL_API_Client $api_client API client.
     * @param string         $contact_id Contact ID.
     * @param array          $standard_custom_fields Non-file custom fields.
     * @param array          $file_custom_fields Uploaded file custom fields.
     * @return array|\WP_Error
     */
    private function update_contact_custom_fields(
        GHL_API_Client $api_client,
        $contact_id,
        array $standard_custom_fields,
        array $file_custom_fields
    ) {
        $response = [];

        if (!empty($standard_custom_fields)) {
            $response = $api_client->update_contact($contact_id, [
                'customFields' => $standard_custom_fields,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }
        }

        if (empty($file_custom_fields)) {
            return $response;
        }

        $clear_response = $api_client->update_contact($contact_id, [
            'customFields' => $this->build_file_custom_field_clear_payload($file_custom_fields),
        ]);

        if (is_wp_error($clear_response)) {
            $this->logger->error('Progressive contact file field reset failed before upload update.', [
                'contact_id' => $contact_id,
                'file_fields' => array_values(array_map([$this, 'get_custom_field_key'], $file_custom_fields)),
                'error' => $clear_response->get_error_message(),
            ]);

            return $clear_response;
        }

        return $api_client->update_contact($contact_id, [
            'customFields' => $file_custom_fields,
        ]);
    }

    /**
     * Update opportunity custom fields, resetting uploaded file fields first.
     *
     * @param GHL_API_Client $api_client API client.
     * @param string         $opportunity_id Opportunity ID.
     * @param array          $standard_custom_fields Non-file custom fields.
     * @param array          $file_custom_fields Uploaded file custom fields.
     * @return array|\WP_Error
     */
    private function update_opportunity_custom_fields(
        GHL_API_Client $api_client,
        $opportunity_id,
        array $standard_custom_fields,
        array $file_custom_fields
    ) {
        if (empty($file_custom_fields)) {
            return $api_client->update_opportunity($opportunity_id, [
                'customFields' => $standard_custom_fields,
            ]);
        }

        if (!empty($standard_custom_fields)) {
            $standard_response = $api_client->update_opportunity($opportunity_id, [
                'customFields' => $standard_custom_fields,
            ]);

            if (is_wp_error($standard_response)) {
                return $standard_response;
            }
        }

        $clear_response = $api_client->update_opportunity($opportunity_id, [
            'customFields' => $this->build_file_custom_field_clear_payload($file_custom_fields),
        ]);

        if (is_wp_error($clear_response)) {
            $this->logger->error('Progressive file field reset failed before upload update.', [
                'opportunity_id' => $opportunity_id,
                'file_fields' => array_values(array_map([$this, 'get_custom_field_key'], $file_custom_fields)),
                'error' => $clear_response->get_error_message(),
            ]);
        }

        return $api_client->update_opportunity($opportunity_id, [
            'customFields' => $file_custom_fields,
        ]);
    }

    /**
     * Build empty values for uploaded file fields before setting replacements.
     *
     * @param array $file_custom_fields Uploaded file custom fields.
     * @return array
     */
    private function build_file_custom_field_clear_payload(array $file_custom_fields)
    {
        $clear_fields = [];

        foreach ($file_custom_fields as $custom_field) {
            $clear_fields[] = [
                'id' => $custom_field['id'],
                'key' => $custom_field['key'],
                'field_value' => '',
            ];
        }

        return $clear_fields;
    }

    /**
     * Add/remove progressive tags without blocking the opportunity update.
     *
     * @param GHL_API_Client $api_client API client.
     * @param string         $contact_id Contact ID.
     */
    private function update_progressive_contact_tags(GHL_API_Client $api_client, $contact_id)
    {
        $add_response = $api_client->add_contact_tags($contact_id, [GHL_Field_Mapper::PROGRESSIVE_ADD_TAG]);

        if (is_wp_error($add_response)) {
            $this->logger->error('Adding progressive contact tag failed.', [
                'contact_id' => $contact_id,
                'error' => $add_response->get_error_message(),
            ]);
        }

        $remove_response = $api_client->remove_contact_tags($contact_id, [GHL_Field_Mapper::PROGRESSIVE_REMOVE_TAG]);

        if (is_wp_error($remove_response)) {
            $this->logger->error('Removing original contact tag failed.', [
                'contact_id' => $contact_id,
                'error' => $remove_response->get_error_message(),
            ]);
        }
    }

    /**
     * Return a field key for API response summaries.
     *
     * @param array $custom_field Mapped custom field.
     * @return string
     */
    private function get_custom_field_key(array $custom_field)
    {
        return (string) ($custom_field['key'] ?? '');
    }

    /**
     * Build public-safe response data.
     *
     * @param array $event_items Event item labels.
     * @param array $allowed_steps Normalized allowed step keys.
     * @return \WP_REST_Response
     */
    private function build_response(array $event_items, array $allowed_steps)
    {
        return rest_ensure_response([
            'event_items' => array_values($event_items),
            'allowed_steps' => array_values($allowed_steps),
        ]);
    }

    /**
     * Extract the Event Item custom field value from known GHL response shapes.
     *
     * @param array $contact_response GHL contact response.
     * @return mixed
     */
    private function extract_event_item_value(array $contact_response, $event_item_field_id = '')
    {
        $contact = isset($contact_response['contact']) && is_array($contact_response['contact'])
            ? $contact_response['contact']
            : $contact_response;

        foreach (['event_item', self::EVENT_ITEM_FIELD_KEY, '{{' . self::EVENT_ITEM_FIELD_KEY . '}}'] as $key) {
            if (isset($contact[$key])) {
                return $contact[$key];
            }
        }

        foreach (['customFields', 'customField', 'custom_fields'] as $container_key) {
            if (empty($contact[$container_key])) {
                continue;
            }

            $value = $this->extract_event_item_from_custom_fields($contact[$container_key], $event_item_field_id);

            if ($value !== null) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Extract Event Item value from custom field lists or maps.
     *
     * @param mixed $custom_fields Custom fields container.
     * @return mixed|null
     */
    private function extract_event_item_from_custom_fields($custom_fields, $event_item_field_id = '')
    {
        if (!is_array($custom_fields)) {
            return null;
        }

        if (array_key_exists(self::EVENT_ITEM_FIELD_KEY, $custom_fields)) {
            return $custom_fields[self::EVENT_ITEM_FIELD_KEY];
        }

        if (array_key_exists('{{' . self::EVENT_ITEM_FIELD_KEY . '}}', $custom_fields)) {
            return $custom_fields['{{' . self::EVENT_ITEM_FIELD_KEY . '}}'];
        }

        if ($event_item_field_id !== '' && array_key_exists($event_item_field_id, $custom_fields)) {
            return $custom_fields[$event_item_field_id];
        }

        foreach ($custom_fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $field_key = $this->normalize_field_key((string) ($field['key'] ?? $field['fieldKey'] ?? $field['name'] ?? ''));
            $field_id = trim((string) ($field['id'] ?? $field['fieldId'] ?? ''));

            if (
                $field_key !== self::EVENT_ITEM_FIELD_KEY
                && $field_key !== 'event_item'
                && ($event_item_field_id === '' || $field_id !== $event_item_field_id)
            ) {
                continue;
            }

            foreach (['field_value', 'value', 'fieldValue'] as $value_key) {
                if (array_key_exists($value_key, $field)) {
                    return $field[$value_key];
                }
            }
        }

        return null;
    }

    /**
     * Normalize a GHL custom field key.
     *
     * @param string $field_key Raw field key.
     * @return string
     */
    private function normalize_field_key($field_key)
    {
        $field_key = trim($field_key);
        $field_key = preg_replace('/^\{\{\s*/', '', $field_key);
        $field_key = preg_replace('/\s*\}\}$/', '', $field_key);

        return strtolower($field_key);
    }

    /**
     * Convert a custom field value into clean Event Item labels.
     *
     * @param mixed $value Event item value.
     * @return array
     */
    private function parse_event_items($value)
    {
        $items = [];

        if (is_array($value)) {
            foreach ($value as $item) {
                $items = array_merge($items, $this->parse_event_items($item));
            }

            return array_values(array_unique($items));
        }

        foreach (explode(',', (string) $value) as $item) {
            $item = trim(sanitize_text_field($item));

            if ($item !== '') {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * Normalize Event Item labels into supported form step keys.
     *
     * @param array $event_items Event item labels.
     * @return array
     */
    private function normalize_allowed_steps(array $event_items)
    {
        $allowed_steps = [];

        foreach ($event_items as $event_item) {
            $step_key = $this->map_event_item_to_step_key($event_item);

            if ($step_key !== '') {
                $allowed_steps[] = $step_key;
            }
        }

        return array_values(array_unique($allowed_steps));
    }

    /**
     * Map an Event Item label to a known form step key.
     *
     * @param string $event_item Event item label.
     * @return string
     */
    private function map_event_item_to_step_key($event_item)
    {
        $normalized = $this->slugify($event_item);
        $aliases = [
            'dance-floors' => 'dance-floors',
            'dance-floor' => 'dance-floors',
            'dance-floor-wraps' => 'dance-floors',
            'dance-floor-wrap' => 'dance-floors',
            'aisles' => 'aisles',
            'aisle' => 'aisles',
            'breathtaking-enhancements' => 'breathtaking-enhancements',
            'breathtaking-enhancement' => 'breathtaking-enhancements',
            'enhancements' => 'breathtaking-enhancements',
            'enhancement' => 'breathtaking-enhancements',
            'specialized-touches' => 'specialized-touches',
            'specialized-touch' => 'specialized-touches',
            'individualized-accents' => 'individualized-accents',
            'individualized-accent' => 'individualized-accents',
        ];

        return $aliases[$normalized] ?? '';
    }

    /**
     * Convert a label into a simple ASCII slug.
     *
     * @param string $label Raw label.
     * @return string
     */
    private function slugify($label)
    {
        $label = strtolower(trim((string) $label));
        $label = preg_replace('/[^a-z0-9]+/', '-', $label);

        return trim((string) $label, '-');
    }
}
