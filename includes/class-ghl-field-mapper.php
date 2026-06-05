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
    const INITIAL_TAG = 'Lead';
    const PROGRESSIVE_ADD_TAG = 'strong lead';
    const PROGRESSIVE_REMOVE_TAG = 'lead';
    const APPOINTMENT_CALENDAR_ID = 'z06rKBsUpIlR15xL0XUB';
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
        'opportunity.item_finish' => 'dance_floor_wrap_finish',
        'opportunity.item_type' => 'dance_floor_wrap_type',
        'opportunity.venue_size' => 'venue_room_size',
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
     * @return array
     */
    public function build_contact_payload(array $fields, $location_id, $message_custom_field_id = '', $assigned_user_id = '')
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
     * @return array|\WP_Error
     */
    public function build_appointment_payload(array $fields, $location_id, $assigned_user_id)
    {
        $contact_id = trim($fields['contact_id'] ?? '');
        $appointment_date = trim($fields['appointment_date'] ?? '');
        $appointment_time = trim($fields['appointment_time'] ?? '');
        $assigned_user_id = trim($assigned_user_id);

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
            'calendarId' => self::APPOINTMENT_CALENDAR_ID,
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
}
