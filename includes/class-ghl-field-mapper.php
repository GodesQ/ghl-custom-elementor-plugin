<?php
/**
 * Field mapping helpers for Elementor form records.
 *
 * @package GHL_Elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

class GHL_Field_Mapper
{
    const INITIAL_SOURCE = 'Website Form';
    const PROGRESSIVE_FORM_NAME = 'Progressive Form';
    const LEAD_SCHEDULED_APPOINTMENT_FORM_NAME = 'Lead Schedule Appointment Form';
    const INITIAL_TAG = 'lead';
    const PROGRESSIVE_ADD_TAG = 'strong lead';
    const PROGRESSIVE_REMOVE_TAG = 'lead';
    const SCHEDULED_TAG = 'scheduled';
    const APPOINTMENT_DURATION_MINUTES = 30;
    const APPOINTMENT_TITLE = 'Scheduled Appointment';

    const INITIAL_CONTACT_FIELDS = [
        'contact.contact_role' => 'role',
        'contact.event_item' => 'interest',
        'contact.event_location' => 'event_address',
        'contact.event_date' => 'event_date',
    ];

    const INITIAL_OPPORTUNITY_FIELDS = [
        'opportunity.product_of_interest' => 'interest',
        'opportunity.event_location' => 'event_address',
        'opportunity.event_date' => 'event_date',
    ];

    const PROGRESSIVE_OPPORTUNITY_FIELDS = [
        'opportunity.floor_wrap_quantity' => 'floor_quantity',
        'opportunity.floor_wrap_size' => 'floor_size',
        'opportunity.floor_wrap_inspiration_1' => 'dance_floor_inspiration_1',
        'opportunity.floor_wrap_inspiration_2' => 'dance_floor_inspiration_2',
        'opportunity.floor_wrap_notes' => 'floor_notes',
        'opportunity.aisle_quantity' => 'aisle_quantity',
        'opportunity.aisle_size' => 'aisle_size',
        'opportunity.aisle_inspiration_1' => 'aisle_inspiration_1',
        'opportunity.aisle_inspiration_2' => 'aisle_inspiration_2',
        'opportunity.aisle_notes' => 'aisle_notes',
        'opportunity.breathtaking_enhancements_quantity' => 'enhancement_quantity',
        'opportunity.breathtaking_enhancements_size' => 'enhancement_type',
        'opportunity.breathtaking_enhancements_inspiration_1' => 'enhancement_inspiration_1',
        'opportunity.breathtaking_enhancements_inspiration_2' => 'enhancement_inspiration_2',
        'opportunity.breathtaking_enhancements_notes' => 'enhancement_notes',
        'opportunity.specialized_touches_quantity' => 'specialized_quantity',
        'opportunity.specialized_touches_size' => 'specialized_details',
        'opportunity.specialized_touches_inspiration_1' => 'specialized_inspiration_1',
        'opportunity.specialized_touches_inspiration_2' => 'specialized_inspiration_2',
        'opportunity.specialized_touches_notes' => 'specialized_notes',
        'opportunity.individualized_accents_quantity' => 'accent_quantity',
        'opportunity.individualized_accents_size' => 'accent_details',
        'opportunity.individualized_accents_inspiration_1' => 'accent_inspiration_1',
        'opportunity.individualized_accents_inspiration_2' => 'accent_inspiration_2',
        'opportunity.individualized_accents_notes' => 'accent_notes',
        'opportunity.item_finish' => 'dance_floor_wrap_finish',
        'opportunity.item_type' => 'dance_floor_wrap_type',
        'opportunity.venue_size' => 'venue_room_size',
    ];

    const PROGRESSIVE_WEBHOOK_FIELDS = [
        'floor_wrap_size' => 'floor_size',
        'floor_wrap_notes' => 'floor_notes',
        'aisle_size' => 'aisle_size',
        'aisle_notes' => 'aisle_notes',
        'breathtaking_enhancements_size' => 'enhancement_type',
        'breathtaking_enhancements_notes' => 'enhancement_notes',
        'specialized_touches_size' => 'specialized_details',
        'specialized_touches_notes' => 'specialized_notes',
        'individualized_accents_size' => 'accent_details',
        'individualized_accents_notes' => 'accent_notes',
    ];

    /**
     * Convert Elementor record fields into a simple sanitized array.
     *
     * @param object $record Elementor form record.
     * @return array
     */
    public function get_sanitized_fields($record)
    {
        $raw_fields = $record->get('fields');
        $fields = [];

        if (!is_array($raw_fields)) {
            return $fields;
        }

        foreach ($raw_fields as $id => $field) {
            $fields[$id] = sanitize_text_field($field['value'] ?? '');
        }

        return $fields;
    }

    /**
     * Build contact upsert payload.
     *
     * @param array  $fields Submitted fields.
     * @param string $location_id GHL location ID.
     * @param string $message_custom_field_id Optional GHL custom field ID for message.
     * @param string $assigned_user_id Optional assigned GHL user ID.
     * @param array  $additional_tags Additional contact tags.
     * @return array
     */
    public function build_contact_payload(array $fields, $location_id, $message_custom_field_id = '', $assigned_user_id = '', array $additional_tags = [])
    {
        $payload = [
            'locationId' => $location_id,
            'firstName' => $fields['first_name'] ?? '',
            'lastName' => $fields['last_name'] ?? '',
            'email' => $fields['email'] ?? '',
            'phone' => $fields['phone'] ?? '',
            'source' => $fields['source'] ?? self::INITIAL_SOURCE,
            'tags' => [
                self::INITIAL_TAG,
            ],
            'companyName' => $fields['company'] ?? '',
        ];

        if (!empty($additional_tags)) {
            $payload['tags'] = array_values(array_unique(array_merge($payload['tags'], $additional_tags)));
        }

        if (!empty($assigned_user_id)) {
            $payload['assignedTo'] = $assigned_user_id;
        }

        if (!empty($fields['message']) && !empty($message_custom_field_id)) {
            $payload['customFields'] = [
                [
                    'id' => $message_custom_field_id,
                    'value' => $fields['message'],
                ],
            ];
        }

        return $payload;
    }

    /**
     * Build opportunity create payload.
     *
     * @param array  $fields Submitted fields.
     * @param string $contact_id GHL contact ID.
     * @param array  $settings GHL settings.
     * @param string $assigned_user_id Optional assigned GHL user ID.
     * @return array
     */
    public function build_opportunity_payload(array $fields, $contact_id, array $settings, $assigned_user_id = '')
    {
        $full_name = trim(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? ''));

        if (empty($full_name)) {
            $full_name = ($fields['email'] ?? '') ?: ($fields['phone'] ?? '');
        }

        $payload = [
            'locationId' => $settings['location_id'],
            'pipelineId' => $settings['pipeline_id'],
            'pipelineStageId' => $settings['pipeline_stage_id'],
            'contactId' => $contact_id,
            'name' => 'Website Lead - ' . $full_name,
            'status' => 'open',
            'monetaryValue' => 0,
            'source' => $fields['source'] ?? self::INITIAL_SOURCE,
        ];

        if (!empty($assigned_user_id)) {
            $payload['assignedTo'] = $assigned_user_id;
        }

        return $payload;
    }

    /**
     * Build calendar appointment create payload.
     *
     * @param array  $fields Submitted fields.
     * @param string $location_id GHL location ID.
     * @param string $assigned_user_id Assigned GHL user ID.
     * @param string $calendar_id Assigned calendar ID.
     * @return array|\WP_Error
     */
    public function build_appointment_payload(array $fields, $location_id, $assigned_user_id, $calendar_id)
    {
        $contact_id = trim($fields['contact_id'] ?? '');
        $appointment_date = trim($fields['appointment_date'] ?? '');
        $appointment_time = trim($fields['appointment_time'] ?? '');
        $assigned_user_id = trim($assigned_user_id);
        $calendar_id = trim($calendar_id);

        if (empty($contact_id) || empty($appointment_date) || empty($appointment_time)) {
            return new \WP_Error(
                'ghl_appointment_missing_fields',
                'Contact ID, appointment date, and appointment time are required.'
            );
        }

        if (empty($assigned_user_id)) {
            return new \WP_Error(
                'ghl_appointment_missing_assigned_user',
                'Contact assigned user is required to schedule an appointment.'
            );
        }

        if (empty($calendar_id)) {
            return new \WP_Error(
                'ghl_appointment_missing_calendar',
                'Assigned user calendar is required to schedule an appointment.'
            );
        }

        try {
            $start_time = new \DateTimeImmutable($appointment_date . ' ' . $appointment_time, wp_timezone());
        } catch (\Exception $exception) {
            return new \WP_Error(
                'ghl_appointment_invalid_time',
                'Appointment date or time is invalid.'
            );
        }

        $end_time = $start_time->modify('+' . self::APPOINTMENT_DURATION_MINUTES . ' minutes');

        return [
            'calendarId' => $calendar_id,
            'locationId' => $location_id,
            'contactId' => $contact_id,
            'startTime' => $start_time->format(DATE_ATOM),
            'endTime' => $end_time->format(DATE_ATOM),
            'title' => self::APPOINTMENT_TITLE,
            'appointmentStatus' => 'confirmed',
            'assignedUserId' => $assigned_user_id,
            'ignoreFreeSlotValidation' => true,
        ];
    }

    /**
     * Build custom fields for an initial contact upsert.
     *
     * @param array          $fields Submitted fields.
     * @param GHL_API_Client $api_client API client.
     * @param string         $location_id GHL location ID.
     * @return array
     */
    public function build_initial_contact_custom_fields(array $fields, GHL_API_Client $api_client, $location_id)
    {
        $custom_fields = [];

        foreach (self::INITIAL_CONTACT_FIELDS as $field_key => $form_field_id) {
            $field_value = $fields[$form_field_id] ?? '';

            if ($field_value === null || $field_value === '') {
                continue;
            }

            $field_id = $api_client->get_custom_field_id_by_key($location_id, $field_key);

            if (empty($field_id)) {
                continue;
            }

            $custom_fields[] = [
                'id' => $field_id,
                'key' => $field_key,
                'field_value' => $field_value,
            ];
        }

        return $custom_fields;
    }

    /**
     * Build custom fields for an initial opportunity create.
     *
     * @param array          $fields Submitted fields.
     * @param GHL_API_Client $api_client API client.
     * @param string         $location_id GHL location ID.
     * @return array
     */
    public function build_initial_opportunity_custom_fields(array $fields, GHL_API_Client $api_client, $location_id)
    {
        $custom_fields = [];

        foreach (self::INITIAL_OPPORTUNITY_FIELDS as $field_key => $form_field_id) {
            $field_value = $fields[$form_field_id] ?? '';

            if ($field_value === null || $field_value === '') {
                continue;
            }

            $field_id = $api_client->get_custom_field_id_by_key($location_id, $field_key);

            if (empty($field_id)) {
                continue;
            }

            $custom_fields[] = [
                'id' => $field_id,
                'key' => $field_key,
                'field_value' => $field_value,
            ];
        }

        return $custom_fields;
    }

    /**
     * Build custom fields for a progressive opportunity update.
     *
     * @param array          $fields Submitted fields.
     * @param GHL_API_Client $api_client API client.
     * @param string         $location_id GHL location ID.
     * @return array
     */
    public function build_progressive_opportunity_custom_fields(array $fields, GHL_API_Client $api_client, $location_id)
    {
        $custom_fields = [];

        foreach (self::PROGRESSIVE_OPPORTUNITY_FIELDS as $field_key => $form_field_id) {
            $field_value = $fields[$form_field_id] ?? '';

            if ($field_value === null || $field_value === '' || (is_array($field_value) && empty($field_value))) {
                continue;
            }

            $field_id = $api_client->get_custom_field_id_by_key($location_id, $field_key);

            if (empty($field_id)) {
                continue;
            }

            $custom_fields[] = [
                'id' => $field_id,
                'key' => $field_key,
                'field_value' => $field_value,
            ];
        }

        return $custom_fields;
    }

    /**
     * Build progressive form webhook payload.
     *
     * @param string $contact_id Contact ID.
     * @param string $opportunity_id Opportunity ID.
     * @param array  $fields Submitted fields.
     * @return array
     */
    public function build_progressive_webhook_payload($contact_id, $opportunity_id, array $fields)
    {
        $payload = [
            'opportunity_id' => $opportunity_id,
            'contact_id' => $contact_id,
            'venue_size' => $fields['venue_room_size'] ?? '',
            'product_type' => $fields['dance_floor_wrap_type'] ?? '',
            'product_floor' => $fields['dance_floor_wrap_finish'] ?? '',
        ];

        foreach (self::PROGRESSIVE_WEBHOOK_FIELDS as $payload_key => $form_field_id) {
            $payload[$payload_key] = $fields[$form_field_id] ?? '';
        }

        return $payload;
    }
}
