<?php
/**
 * Elementor Pro form action for GoHighLevel.
 *
 * @package GHL_Elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

class GHL_Elementor_Form_Action extends \ElementorPro\Modules\Forms\Classes\Action_Base
{
    const ACTION_NAME = 'ghl_contact_opportunity';
    const TEXT_DOMAIN = 'ghl-elementor';
    const PROGRESSIVE_WEBHOOK_URL = 'https://services.leadconnectorhq.com/hooks/7M5Xl7fUp1LSYtHLt72T/webhook-trigger/35864415-50cc-4cb2-ae42-a4177467476e';

    /**
     * @var GHL_Logger
     */
    private $logger;

    /**
     * @var GHL_Field_Mapper
     */
    private $field_mapper;

    /**
     * @var GHL_Elementor_Settings
     */
    private $settings_repository;

    public function __construct()
    {
        $this->logger = new GHL_Logger();
        $this->field_mapper = new GHL_Field_Mapper();
        $this->settings_repository = new GHL_Elementor_Settings();
    }

    /**
     * Action identifier.
     *
     * @return string
     */
    public function get_name()
    {
        return self::ACTION_NAME;
    }

    /**
     * Action label shown in Elementor.
     *
     * @return string
     */
    public function get_label()
    {
        return esc_html__('GHL Contact + Opportunity', self::TEXT_DOMAIN);
    }

    /**
     * Register Elementor controls for this form action.
     *
     * @param object $widget Elementor form widget.
     */
    public function register_settings_section($widget)
    {
        $widget->start_controls_section(
            'section_ghl_api',
            [
                'label' => esc_html__('GHL API', self::TEXT_DOMAIN),
                'condition' => [
                    'submit_actions' => $this->get_name(),
                ],
            ]
        );

        $widget->add_control(
            'ghl_dashboard_notice',
            [
                'type' => \Elementor\Controls_Manager::RAW_HTML,
                'raw' => esc_html__('GHL credentials, users, and pipeline routing are managed in WordPress Dashboard > GHL Config.', self::TEXT_DOMAIN),
                'content_classes' => 'elementor-descriptor',
            ]
        );

        $widget->add_control(
            'ghl_redirect_url',
            [
                'label' => esc_html__('Redirect URL After GHL Submit', self::TEXT_DOMAIN),
                'type' => \Elementor\Controls_Manager::URL,
                'label_block' => true,
                'placeholder' => 'https://gotoshout.com/lead-progressive-form-sample',
            ]
        );

        $widget->end_controls_section();
    }

    /**
     * Execute the form action.
     *
     * @param object $record Elementor form record.
     * @param object $ajax_handler Elementor AJAX handler.
     */
    public function run($record, $ajax_handler)
    {
        $settings = $this->get_settings($record);
        $fields = $this->field_mapper->get_sanitized_fields($record);
        $form_name = (string) $record->get_form_settings('form_name');

        if ($form_name === GHL_Field_Mapper::PROGRESSIVE_FORM_NAME) {
            $this->handle_progressive_form($settings, $fields, $ajax_handler);
            return;
        }

        if ($form_name === GHL_Field_Mapper::LEAD_SCHEDULED_APPOINTMENT_FORM_NAME) {
            $this->handle_scheduled_appointment_form($settings, $fields, $ajax_handler);
            return;
        }

        $this->handle_initial_form($settings, $fields, $ajax_handler);
    }

    /**
     * Remove private token on Elementor export.
     *
     * @param array $element Elementor element data.
     * @return array
     */
    public function on_export($element)
    {
        unset($element['settings']['ghl_token']);
        unset($element['settings']['ghl_location_id']);
        unset($element['settings']['ghl_pipeline_id']);
        unset($element['settings']['ghl_pipeline_stage_id']);
        unset($element['settings']['ghl_message_custom_field_id']);

        return $element;
    }

    /**
     * Handle the first form submission that creates a contact and opportunity.
     *
     * @param array  $settings Action settings.
     * @param array  $fields Submitted fields.
     * @param object $ajax_handler Elementor AJAX handler.
     */
    private function handle_initial_form(array $settings, array $fields, $ajax_handler)
    {
        if (!$this->has_required_settings($settings, ['token', 'location_id', 'pipeline_id', 'default_user_id', 'default_pipeline_stage_id'])) {
            $ajax_handler->add_error_message('GHL integration is not configured.');
            return;
        }

        if (empty($fields['email']) && empty($fields['phone'])) {
            $ajax_handler->add_error_message('Email or phone is required.');
            return;
        }

        $api_client = $this->make_api_client($settings);

        $routing = $this->resolve_routing($settings, $fields);

        $contact_payload = $this->field_mapper->build_contact_payload(
            $fields,
            $settings['location_id'],
            $settings['message_custom_field_id'],
            $routing['assigned_user_id']
        );

        $custom_fields = $this->field_mapper->build_initial_contact_custom_fields(
            $fields,
            $api_client,
            $settings['location_id']
        );

        if (!empty($custom_fields)) {
            $contact_payload['customFields'] = array_merge($contact_payload['customFields'] ?? [], $custom_fields);
        }

        $contact_response = $api_client->upsert_contact($contact_payload);

        if (is_wp_error($contact_response)) {
            $this->logger->error('Contact upsert failed.', ['error' => $contact_response->get_error_message()]);
            $ajax_handler->add_error_message('Could not save contact to GHL.');
            return;
        }

        $contact_id = $this->extract_contact_id($contact_response);

        if (empty($contact_id)) {
            $this->logger->error('Contact response missing ID.');
            $ajax_handler->add_error_message('GHL contact was saved but no contact ID was returned.');
            return;
        }

        $opportunity_settings = $settings;
        $opportunity_settings['pipeline_stage_id'] = $routing['pipeline_stage_id'];
        $opportunity_payload = $this->field_mapper->build_opportunity_payload(
            $fields,
            $contact_id,
            $opportunity_settings,
            $routing['assigned_user_id']
        );

        $opportunity_response = $api_client->create_opportunity($opportunity_payload);

        if (is_wp_error($opportunity_response)) {
            $this->logger->error('Opportunity creation failed.', ['error' => $opportunity_response->get_error_message()]);
            $ajax_handler->add_error_message('Contact saved, but opportunity could not be created.');
            return;
        }

        $opportunity_id = $this->extract_opportunity_id($opportunity_response);

        if (empty($opportunity_id)) {
            $this->logger->error('Opportunity response missing ID.');
            $ajax_handler->add_error_message('Opportunity was created but no opportunity ID was returned.');
            return;
        }

        $this->logger->info('Contact and opportunity created.', [
            'contact_id' => $contact_id,
            'opportunity_id' => $opportunity_id,
        ]);

        $this->add_redirect_response($settings, $contact_id, $opportunity_id, $ajax_handler);
    }

    /**
     * Handle the progressive form that updates an existing contact/opportunity.
     *
     * @param array  $settings Action settings.
     * @param array  $fields Submitted fields.
     * @param object $ajax_handler Elementor AJAX handler.
     */
    private function handle_progressive_form(array $settings, array $fields, $ajax_handler)
    {
        if (!$this->has_required_settings($settings, ['token', 'location_id'])) {
            $ajax_handler->add_error_message('GHL integration is not configured.');
            return;
        }

        $contact_id = $fields['contact_id'] ?? '';
        $opportunity_id = $fields['opportunity_id'] ?? '';
        $api_client = $this->make_api_client($settings);

        if (!empty($contact_id)) {
            $this->update_progressive_contact_tags($api_client, $contact_id);
        } else {
            $this->logger->error('Progressive form missing contact ID.');
        }

        if (empty($opportunity_id)) {
            $this->logger->error('Progressive form missing opportunity ID.');
            $ajax_handler->add_error_message('GHL opportunity could not be updated.');
            return;
        }

        $custom_fields = $this->field_mapper->build_progressive_custom_fields(
            $fields,
            $api_client,
            $settings['location_id']
        );

        $opportunity_payload = [];

        if (!empty($custom_fields)) {
            $opportunity_payload['customFields'] = $custom_fields;
        }

        if (!empty($settings['pipeline_id'])) {
            $opportunity_payload['pipelineId'] = $settings['pipeline_id'];
        }

        if (!empty($settings['pipeline_stage_id'])) {
            $opportunity_payload['pipelineStageId'] = $settings['pipeline_stage_id'];
        }

        if (empty($opportunity_payload)) {
            $this->logger->info('Progressive opportunity update skipped because payload was empty.');
            $this->add_redirect_response($settings, $contact_id, $opportunity_id, $ajax_handler);
            return;
        }

        $opportunity_response = $api_client->update_opportunity($opportunity_id, $opportunity_payload);

        if (is_wp_error($opportunity_response)) {
            $this->logger->error('Progressive opportunity update failed.', [
                'error' => $opportunity_response->get_error_message(),
            ]);
            $ajax_handler->add_error_message('GHL opportunity could not be updated.');
            return;
        }

        $this->logger->info('Progressive opportunity updated.', ['opportunity_id' => $opportunity_id]);
        $this->send_progressive_webhook($contact_id, $opportunity_id, $fields);
        $this->add_redirect_response($settings, $contact_id, $opportunity_id, $ajax_handler);
    }

    /**
     * Handle the scheduled appointment form that creates a GHL calendar appointment.
     *
     * @param array  $settings Action settings.
     * @param array  $fields Submitted fields.
     * @param object $ajax_handler Elementor AJAX handler.
     */
    private function handle_scheduled_appointment_form(array $settings, array $fields, $ajax_handler)
    {
        if (!$this->has_required_settings($settings, ['token', 'location_id'])) {
            $ajax_handler->add_error_message('GHL integration is not configured.');
            return;
        }

        $contact_id = trim($fields['contact_id'] ?? '');
        $opportunity_id = $fields['opportunity_id'] ?? '';

        if (empty($contact_id) || empty($fields['appointment_date']) || empty($fields['appointment_time'])) {
            $this->logger->error('Scheduled appointment missing required fields.');
            $ajax_handler->add_error_message('Contact ID, appointment date, and appointment time are required.');
            return;
        }

        $api_client = $this->make_api_client($settings);
        $contact_response = $api_client->get_contact($contact_id);

        if (is_wp_error($contact_response)) {
            $this->logger->error('Scheduled appointment contact lookup failed.', [
                'error' => $contact_response->get_error_message(),
                'contact_id' => $contact_id,
            ]);
            $ajax_handler->add_error_message('Could not load contact from GHL.');
            return;
        }

        $assigned_user_id = $this->extract_contact_assigned_user_id($contact_response);

        if (empty($assigned_user_id)) {
            $this->logger->error('Scheduled appointment contact missing assigned user.', [
                'contact_id' => $contact_id,
            ]);
            $ajax_handler->add_error_message('GHL contact does not have an assigned user.');
            return;
        }

        $appointment_payload = $this->field_mapper->build_appointment_payload(
            $fields,
            $settings['location_id'],
            $assigned_user_id
        );

        if (is_wp_error($appointment_payload)) {
            $this->logger->error('Scheduled appointment payload failed validation.', [
                'error' => $appointment_payload->get_error_message(),
            ]);
            $ajax_handler->add_error_message($appointment_payload->get_error_message());
            return;
        }

        $appointment_response = $api_client->create_appointment($appointment_payload);

        if (is_wp_error($appointment_response)) {
            $this->logger->error('Scheduled appointment creation failed.', [
                'error' => $appointment_response->get_error_message(),
            ]);
            $ajax_handler->add_error_message('GHL appointment could not be scheduled.');
            return;
        }

        $this->logger->info('Scheduled appointment created.', [
            'contact_id' => $contact_id,
            'calendar_id' => GHL_Field_Mapper::APPOINTMENT_CALENDAR_ID,
            'assigned_user_id' => $assigned_user_id,
        ]);

        $this->add_redirect_response($settings, $contact_id, $opportunity_id, $ajax_handler);
    }

    /**
     * Send progressive form details to the configured webhook.
     *
     * @param string $contact_id Contact ID.
     * @param string $opportunity_id Opportunity ID.
     * @param array  $fields Submitted fields.
     */
    private function send_progressive_webhook($contact_id, $opportunity_id, array $fields)
    {
        $payload = [
            'opportunity_id' => $opportunity_id,
            'contact_id' => $contact_id,
            'venue_size' => $fields['venue_room_size'] ?? '',
            'product_type' => $fields['dance_floor_wrap_type'] ?? '',
            'product_floor' => $fields['dance_floor_wrap_finish'] ?? '',
        ];

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
     * Add/remove progressive form tags on a contact.
     *
     * @param GHL_API_Client $api_client API client.
     * @param string         $contact_id Contact ID.
     */
    private function update_progressive_contact_tags(GHL_API_Client $api_client, $contact_id)
    {
        $add_response = $api_client->add_contact_tags($contact_id, [GHL_Field_Mapper::PROGRESSIVE_ADD_TAG]);

        if (is_wp_error($add_response)) {
            $this->logger->error('Adding progressive contact tag failed.', ['error' => $add_response->get_error_message()]);
        }

        $remove_response = $api_client->remove_contact_tags($contact_id, [GHL_Field_Mapper::PROGRESSIVE_REMOVE_TAG]);

        if (is_wp_error($remove_response)) {
            $this->logger->error('Removing original contact tag failed.', ['error' => $remove_response->get_error_message()]);
        }
    }

    /**
     * Normalize dashboard action settings.
     *
     * @param object $record Elementor form record.
     * @return array
     */
    private function get_settings($record)
    {
        $settings = $this->settings_repository->get();
        $form_settings = $record->get('form_settings');
        $form_settings = is_array($form_settings) ? $form_settings : [];

        return [
            'token' => trim($settings['token'] ?? ''),
            'location_id' => trim($settings['location_id'] ?? ''),
            'pipeline_id' => trim($settings['pipeline_id'] ?? ''),
            'pipeline_stage_id' => trim($settings['default_pipeline_stage_id'] ?? ''),
            'default_user_id' => trim($settings['default_user_id'] ?? ''),
            'default_pipeline_stage_id' => trim($settings['default_pipeline_stage_id'] ?? ''),
            'message_custom_field_id' => trim($settings['message_custom_field_id'] ?? ''),
            'redirect_url' => $this->get_redirect_url($form_settings, $settings),
            'state_user_map' => is_array($settings['state_user_map'] ?? null) ? $settings['state_user_map'] : [],
            'user_stage_map' => is_array($settings['user_stage_map'] ?? null) ? $settings['user_stage_map'] : [],
        ];
    }

    /**
     * Extract redirect URL from Elementor with dashboard fallback.
     *
     * @param array $form_settings Elementor form settings.
     * @param array $dashboard_settings Dashboard settings.
     * @return string
     */
    private function get_redirect_url(array $form_settings, array $dashboard_settings)
    {
        $redirect_setting = $form_settings['ghl_redirect_url'] ?? [];

        if (is_array($redirect_setting) && !empty($redirect_setting['url'])) {
            return esc_url_raw($redirect_setting['url']);
        }

        return esc_url_raw($dashboard_settings['redirect_url'] ?? '');
    }

    /**
     * Check required normalized settings.
     *
     * @param array $settings Normalized settings.
     * @param array $keys Required keys.
     * @return bool
     */
    private function has_required_settings(array $settings, array $keys)
    {
        foreach ($keys as $key) {
            if (empty($settings[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create an API client for current settings.
     *
     * @param array $settings Normalized settings.
     * @return GHL_API_Client
     */
    private function make_api_client(array $settings)
    {
        return new GHL_API_Client($settings['token'], $this->logger);
    }

    /**
     * Resolve assigned user and pipeline stage for a submitted lead.
     *
     * @param array $settings Dashboard settings.
     * @param array $fields Submitted fields.
     * @return array
     */
    private function resolve_routing(array $settings, array $fields)
    {
        $sales_state = $this->settings_repository->get_sales_state_from_fields($fields);
        $assigned_user_id = '';
        $pipeline_stage_id = '';

        if ($sales_state !== '' && !empty($settings['state_user_map'][$sales_state])) {
            $assigned_user_id = $settings['state_user_map'][$sales_state];
        }

        if ($assigned_user_id === '') {
            $assigned_user_id = $settings['default_user_id'];
            $this->logger->error('GHL state routing used default user.', [
                'sales_state' => $sales_state,
            ]);
        }

        if (!empty($settings['user_stage_map'][$assigned_user_id])) {
            $pipeline_stage_id = $settings['user_stage_map'][$assigned_user_id];
        }

        if ($pipeline_stage_id === '') {
            $pipeline_stage_id = $settings['default_pipeline_stage_id'];
            $this->logger->error('GHL user stage routing used default stage.', [
                'sales_state' => $sales_state,
                'assigned_user_id' => $assigned_user_id,
            ]);
        }

        return [
            'sales_state' => $sales_state,
            'assigned_user_id' => $assigned_user_id,
            'pipeline_stage_id' => $pipeline_stage_id,
        ];
    }

    /**
     * Add redirect URL to Elementor response.
     *
     * @param array  $settings Action settings.
     * @param string $contact_id Contact ID.
     * @param string $opportunity_id Opportunity ID.
     * @param object $ajax_handler Elementor AJAX handler.
     */
    private function add_redirect_response(array $settings, $contact_id, $opportunity_id, $ajax_handler)
    {
        if (empty($settings['redirect_url'])) {
            return;
        }

        $redirect_url = add_query_arg(
            [
                'contact_id' => $contact_id,
                'opportunity_id' => $opportunity_id,
            ],
            $settings['redirect_url']
        );

        $ajax_handler->add_response_data('redirect_url', esc_url_raw($redirect_url));
        $this->logger->info('Redirect URL added to Elementor response.');
    }

    /**
     * Extract contact ID from known GHL response shapes.
     *
     * @param array $response API response.
     * @return string|null
     */
    private function extract_contact_id(array $response)
    {
        if (isset($response['contact']['id'])) {
            return $response['contact']['id'];
        }

        if (isset($response['id'])) {
            return $response['id'];
        }

        if (isset($response['contactId'])) {
            return $response['contactId'];
        }

        return null;
    }

    /**
     * Extract contact assigned user ID from known GHL response shapes.
     *
     * @param array $response API response.
     * @return string|null
     */
    private function extract_contact_assigned_user_id(array $response)
    {
        if (isset($response['contact']['assignedTo'])) {
            return $response['contact']['assignedTo'];
        }

        if (isset($response['assignedTo'])) {
            return $response['assignedTo'];
        }

        return null;
    }

    /**
     * Extract opportunity ID from known GHL response shapes.
     *
     * @param array $response API response.
     * @return string|null
     */
    private function extract_opportunity_id(array $response)
    {
        if (isset($response['opportunity']['id'])) {
            return $response['opportunity']['id'];
        }

        if (isset($response['id'])) {
            return $response['id'];
        }

        if (isset($response['opportunityId'])) {
            return $response['opportunityId'];
        }

        return null;
    }
}
