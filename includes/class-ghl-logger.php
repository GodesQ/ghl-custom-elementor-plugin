<?php
/**
 * Logging helper for the GHL Elementor integration.
 *
 * @package GHL_Elementor
 */

if (!defined('ABSPATH')) {
    exit;
}

class GHL_Logger
{
    /**
     * Log an informational message when WordPress debugging is enabled.
     *
     * @param string $message Message to log.
     * @param array  $context Optional scalar context values.
     */
    public function info($message, array $context = [])
    {
        $this->log($message, $context);
    }

    /**
     * Log an error message when WordPress debugging is enabled.
     *
     * @param string $message Message to log.
     * @param array  $context Optional scalar context values.
     */
    public function error($message, array $context = [])
    {
        $this->log($message, $context);
    }

    /**
     * Write a sanitized log entry.
     *
     * @param string $message Message to log.
     * @param array  $context Optional scalar context values.
     */
    private function log($message, array $context = [])
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $entry = '[GHL Elementor] ' . $message;

        if (!empty($context)) {
            $entry .= ' ' . wp_json_encode($this->sanitize_context($context));
        }

        error_log($entry);
    }

    /**
     * Remove sensitive values and keep logs compact.
     *
     * @param array $context Context to sanitize.
     * @return array
     */
    private function sanitize_context(array $context)
    {
        $sensitive_keys = [
            'authorization',
            'email',
            'firstName',
            'lastName',
            'phone',
            'token',
        ];

        foreach ($context as $key => $value) {
            if (in_array((string) $key, $sensitive_keys, true)) {
                $context[$key] = '[redacted]';
                continue;
            }

            if (is_string($value) && strlen($value) > 500) {
                $context[$key] = substr($value, 0, 500) . '...';
            }
        }

        return $context;
    }
}
