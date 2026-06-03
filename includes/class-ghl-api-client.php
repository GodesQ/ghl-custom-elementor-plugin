<?php
/**
 * GoHighLevel API client.
 *
 * @package GHL_Elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

class GHL_API_Client
{
    const BASE_URL = 'https://services.leadconnectorhq.com';
    const API_VERSION = '2023-02-21';
    const TIMEOUT = 20;

    /**
     * @var string
     */
    private $token;

    /**
     * @var GHL_Logger
     */
    private $logger;

    /**
     * @param string     $token API token.
     * @param GHL_Logger $logger Logger instance.
     */
    public function __construct($token, GHL_Logger $logger)
    {
        $this->token = $this->normalize_token($token);
        $this->logger = $logger;
    }

    /**
     * Create or update a GHL contact.
     *
     * @param array $payload Contact payload.
     * @return array|\WP_Error
     */
    public function upsert_contact(array $payload)
    {
        return $this->request('/contacts/upsert', 'POST', $payload);
    }

    /**
     * Get a GHL contact by ID.
     *
     * @param string $contact_id Contact ID.
     * @return array|\WP_Error
     */
    public function get_contact($contact_id)
    {
        return $this->request('/contacts/' . rawurlencode($contact_id), 'GET');
    }

    /**
     * Get users for a GHL location.
     *
     * @param string $location_id Location ID.
     * @return array|\WP_Error
     */
    public function get_users_by_location($location_id)
    {
        return $this->request('/users/?locationId=' . rawurlencode($location_id), 'GET');
    }

    /**
     * Get pipelines for a GHL location.
     *
     * @param string $location_id Location ID.
     * @return array|\WP_Error
     */
    public function get_pipelines($location_id)
    {
        return $this->request('/opportunities/pipelines?locationId=' . rawurlencode($location_id), 'GET');
    }

    /**
     * Create a GHL opportunity.
     *
     * @param array $payload Opportunity payload.
     * @return array|\WP_Error
     */
    public function create_opportunity(array $payload)
    {
        return $this->request('/opportunities/', 'POST', $payload);
    }

    /**
     * Create a GHL calendar appointment.
     *
     * @param array $payload Appointment payload.
     * @return array|\WP_Error
     */
    public function create_appointment(array $payload)
    {
        return $this->request('/calendars/events/appointments', 'POST', $payload);
    }

    /**
     * Update a GHL opportunity.
     *
     * @param string $opportunity_id Opportunity ID.
     * @param array  $payload Opportunity payload.
     * @return array|\WP_Error
     */
    public function update_opportunity($opportunity_id, array $payload)
    {
        return $this->request('/opportunities/' . rawurlencode($opportunity_id), 'PUT', $payload);
    }

    /**
     * Add tags to a contact.
     *
     * @param string $contact_id Contact ID.
     * @param array  $tags Tags to add.
     * @return array|\WP_Error
     */
    public function add_contact_tags($contact_id, array $tags)
    {
        return $this->request('/contacts/' . rawurlencode($contact_id) . '/tags', 'POST', ['tags' => $tags]);
    }

    /**
     * Remove tags from a contact.
     *
     * @param string $contact_id Contact ID.
     * @param array  $tags Tags to remove.
     * @return array|\WP_Error
     */
    public function remove_contact_tags($contact_id, array $tags)
    {
        return $this->request('/contacts/' . rawurlencode($contact_id) . '/tags', 'DELETE', ['tags' => $tags]);
    }

    /**
     * Get GHL custom field ID by custom field key.
     *
     * @param string $location_id Location ID.
     * @param string $field_key Custom field key.
     * @return string
     */
    public function get_custom_field_id_by_key($location_id, $field_key)
    {
        if (empty($location_id) || empty($field_key)) {
            return '';
        }

        $cache_key = 'ghl_cf_id_' . md5($location_id . '|' . $field_key);
        $cached = get_transient($cache_key);

        if (!empty($cached)) {
            return $cached;
        }

        $response = $this->request(
            sprintf('/locations/%s/customFields/%s', rawurlencode($location_id), rawurlencode($field_key)),
            'GET'
        );

        if (is_wp_error($response)) {
            $this->logger->error('Custom field lookup failed.', [
                'field_key' => $field_key,
                'error' => $response->get_error_message(),
            ]);

            return '';
        }

        $field_id = '';

        if (!empty($response['customField']['id'])) {
            $field_id = $response['customField']['id'];
        } elseif (!empty($response['id'])) {
            $field_id = $response['id'];
        }

        if (!empty($field_id)) {
            set_transient($cache_key, $field_id, DAY_IN_SECONDS);
        }

        return $field_id;
    }

    /**
     * Normalize GHL user list response.
     *
     * @param array $response API response.
     * @return array
     */
    public function normalize_users(array $response)
    {
        $items = $this->extract_list($response, ['users', 'data']);
        $users = [];

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));

            if ($name === '') {
                $name = trim((string) (($item['firstName'] ?? '') . ' ' . ($item['lastName'] ?? '')));
            }

            if ($name === '') {
                $name = (string) ($item['email'] ?? $item['id']);
            }

            $users[] = [
                'id' => (string) $item['id'],
                'name' => $name,
                'email' => (string) ($item['email'] ?? ''),
            ];
        }

        usort($users, [$this, 'sort_by_name']);

        return $users;
    }

    /**
     * Normalize GHL pipeline list response.
     *
     * @param array $response API response.
     * @return array
     */
    public function normalize_pipelines(array $response)
    {
        $items = $this->extract_list($response, ['pipelines', 'data']);
        $pipelines = [];

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) {
                continue;
            }

            $pipelines[] = [
                'id' => (string) $item['id'],
                'name' => (string) ($item['name'] ?? $item['id']),
                'stages' => $this->normalize_stages($item['stages'] ?? []),
            ];
        }

        usort($pipelines, [$this, 'sort_by_name']);

        return $pipelines;
    }

    /**
     * Get normalized stages from a selected pipeline.
     *
     * @param array  $pipelines Normalized pipelines.
     * @param string $pipeline_id Selected pipeline ID.
     * @return array
     */
    public function get_pipeline_stages(array $pipelines, $pipeline_id)
    {
        foreach ($pipelines as $pipeline) {
            if (!is_array($pipeline) || ($pipeline['id'] ?? '') !== $pipeline_id) {
                continue;
            }

            return is_array($pipeline['stages'] ?? null) ? $pipeline['stages'] : [];
        }

        return [];
    }

    /**
     * Send an HTTP request to GHL.
     *
     * @param string $endpoint API endpoint path.
     * @param string $method HTTP method.
     * @param array  $payload Request payload.
     * @return array|\WP_Error
     */
    public function request($endpoint, $method = 'GET', array $payload = [])
    {
        $args = [
            'method' => $method,
            'timeout' => self::TIMEOUT,
            'headers' => $this->get_headers(),
        ];

        if (!empty($payload)) {
            $args['body'] = wp_json_encode($payload);
        }

        $response = wp_remote_request(self::BASE_URL . $endpoint, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        $this->logger->info('GHL API response received.', [
            'endpoint' => $endpoint,
            'method' => $method,
            'status_code' => $status_code,
        ]);

        if ($status_code < 200 || $status_code >= 300) {
            if ($status_code === 401) {
                $this->logger->error('GHL authentication failed.', $this->get_token_debug_context());
            }

            return new \WP_Error(
                'ghl_api_error',
                'GHL API returned status ' . $status_code . ': ' . $body,
                [
                    'endpoint' => $endpoint,
                    'status_code' => $status_code,
                ]
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Build standard API headers.
     *
     * @return array
     */
    private function get_headers()
    {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Version' => self::API_VERSION,
        ];
    }

    /**
     * Normalize a token copied from GHL or an Authorization header.
     *
     * @param string $token API token.
     * @return string
     */
    private function normalize_token($token)
    {
        $token = trim((string) $token);
        $token = preg_replace('/^Bearer\s+/i', '', $token);
        $token = preg_replace('/\s+/', '', $token);

        return trim((string) $token);
    }

    /**
     * Build non-sensitive token details for authentication debugging.
     *
     * @return array
     */
    private function get_token_debug_context()
    {
        return [
            'token_length' => strlen($this->token),
            'token_starts_with_bearer' => stripos($this->token, 'Bearer ') === 0 ? 'yes' : 'no',
            'token_is_empty' => empty($this->token) ? 'yes' : 'no',
        ];
    }

    /**
     * Extract a list from common API response containers.
     *
     * @param array $response API response.
     * @param array $keys Candidate list keys.
     * @return array
     */
    private function extract_list(array $response, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                return $response[$key];
            }
        }

        return $this->is_list_array($response) ? $response : [];
    }

    /**
     * Normalize pipeline stages.
     *
     * @param mixed $stages Raw stages.
     * @return array
     */
    private function normalize_stages($stages)
    {
        if (!is_array($stages)) {
            return [];
        }

        $normalized = [];

        foreach ($stages as $stage) {
            if (!is_array($stage) || empty($stage['id'])) {
                continue;
            }

            $normalized[] = [
                'id' => (string) $stage['id'],
                'name' => (string) ($stage['name'] ?? $stage['id']),
            ];
        }

        return $normalized;
    }

    /**
     * Sort normalized GHL rows by display name.
     *
     * @param array $first First item.
     * @param array $second Second item.
     * @return int
     */
    private function sort_by_name(array $first, array $second)
    {
        return strcasecmp($first['name'] ?? '', $second['name'] ?? '');
    }

    /**
     * Determine whether an array is a sequential list.
     *
     * @param array $array Array to inspect.
     * @return bool
     */
    private function is_list_array(array $array)
    {
        $expected = 0;

        foreach (array_keys($array) as $key) {
            if ($key !== $expected) {
                return false;
            }

            $expected++;
        }

        return true;
    }
}
