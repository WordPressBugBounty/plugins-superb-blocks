<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormIntegrationHandler
{
    /**
     * Get field IDs that should be excluded from external integrations.
     *
     * @param array $form_fields_config Form field definitions.
     * @return array Field IDs to exclude.
     */
    private static function GetSensitiveFieldIds($form_fields_config)
    {
        $ids = array();
        foreach ($form_fields_config as $field_def) {
            if (!empty($field_def['sensitive']) || (!empty($field_def['fieldType']) && $field_def['fieldType'] === 'signature')) {
                $ids[] = isset($field_def['fieldId']) ? $field_def['fieldId'] : '';
            }
        }
        return $ids;
    }

    /**
     * Extract filenames from a file field value.
     *
     * @param array $file_value Array of file data arrays.
     * @return array List of filenames.
     */
    private static function ExtractFilenames($file_value)
    {
        $filenames = array();
        foreach ($file_value as $file) {
            $filenames[] = isset($file['name']) ? $file['name'] : '';
        }
        return $filenames;
    }

    /**
     * Escape text for safe use in Slack mrkdwn.
     *
     * @param string $text Raw text.
     * @return string Escaped text.
     */
    private static function EscapeSlackMrkdwn($text)
    {
        return str_replace(
            array('&', '<', '>'),
            array('&amp;', '&lt;', '&gt;'),
            $text
        );
    }

    /**
     * Validate a URL for safe outbound HTTP requests (blocks private/internal IPs).
     *
     * @param string $url URL to validate.
     * @return string|false Validated URL or false if blocked.
     */
    private static function ValidateOutboundUrl($url)
    {
        $url = esc_url_raw($url);
        if (empty($url)) {
            return false;
        }
        return wp_http_validate_url($url);
    }

    /**
     * Fetch available Mailchimp lists/audiences.
     *
     * @return array|\WP_Error Array of {id, name} on success, WP_Error on failure.
     */
    public static function GetMailchimpLists()
    {
        $api_key = FormSettings::Get(FormSettings::OPTION_MAILCHIMP_API_KEY);
        if (empty($api_key)) {
            return new \WP_Error('no_api_key', __('Mailchimp API key is not configured.', 'superb-blocks'), array('status' => 400));
        }

        $dc_parts = explode('-', $api_key);
        if (count($dc_parts) < 2) {
            return new \WP_Error('api_error', __('Invalid Mailchimp API key format.', 'superb-blocks'), array('status' => 400));
        }
        $dc = $dc_parts[1];

        $url = 'https://' . $dc . '.api.mailchimp.com/3.0/lists?count=100&fields=lists.id,lists.name';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key),
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', __('Failed to connect to Mailchimp.', 'superb-blocks'), array('status' => 502));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('api_error', __('Mailchimp API returned an error.', 'superb-blocks'), array('status' => $code));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $lists = array();
        if (isset($body['lists']) && is_array($body['lists'])) {
            foreach ($body['lists'] as $list) {
                $lists[] = array(
                    'id' => isset($list['id']) ? sanitize_text_field($list['id']) : '',
                    'name' => isset($list['name']) ? sanitize_text_field($list['name']) : '',
                );
            }
        }

        return $lists;
    }

    /**
     * Fetch available Brevo (Sendinblue) lists.
     *
     * @return array|\WP_Error Array of {id, name} on success, WP_Error on failure.
     */
    public static function GetBrevoLists()
    {
        $api_key = FormSettings::Get(FormSettings::OPTION_BREVO_API_KEY);
        if (empty($api_key)) {
            return new \WP_Error('no_api_key', __('Brevo API key is not configured.', 'superb-blocks'), array('status' => 400));
        }

        $url = 'https://api.brevo.com/v3/contacts/lists?limit=50&offset=0';

        $response = wp_remote_get($url, array(
            'headers' => array(
                'api-key' => $api_key,
                'Accept' => 'application/json',
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', __('Failed to connect to Brevo.', 'superb-blocks'), array('status' => 502));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('api_error', __('Brevo API returned an error.', 'superb-blocks'), array('status' => $code));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $lists = array();
        if (isset($body['lists']) && is_array($body['lists'])) {
            foreach ($body['lists'] as $list) {
                $lists[] = array(
                    'id' => isset($list['id']) ? intval($list['id']) : 0,
                    'name' => isset($list['name']) ? sanitize_text_field($list['name']) : '',
                );
            }
        }

        return $lists;
    }

    /**
     * Send subscriber to Mailchimp.
     *
     * @param array $list_ids List/audience IDs to subscribe to.
     * @param string $email
     * @param array $fields Additional merge fields
     * @return bool
     */
    public static function SendToMailchimp($list_ids, $email, $fields = array())
    {
        $api_key = FormSettings::Get(FormSettings::OPTION_MAILCHIMP_API_KEY);
        if (empty($api_key) || empty($email)) {
            return false;
        }

        if (!is_array($list_ids)) {
            $list_ids = array($list_ids);
        }

        $dc_parts = explode('-', $api_key);
        if (count($dc_parts) < 2) {
            return false;
        }
        $dc = $dc_parts[1];

        $merge_fields = array();
        if (!empty($fields)) {
            foreach ($fields as $key => $value) {
                $merge_fields[strtoupper(sanitize_key($key))] = sanitize_text_field($value);
            }
        }

        $success = false;
        foreach ($list_ids as $list_id) {
            if (empty($list_id)) {
                continue;
            }

            $url = 'https://' . $dc . '.api.mailchimp.com/3.0/lists/' . urlencode($list_id) . '/members';

            $body = array(
                'email_address' => sanitize_email($email),
                'status' => 'subscribed',
            );

            if (!empty($merge_fields)) {
                $body['merge_fields'] = $merge_fields;
            }

            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key),
                    'Content-Type' => 'application/json',
                ),
                'body' => wp_json_encode($body),
                'timeout' => 15,
            ));

            if (!is_wp_error($response)) {
                $code = wp_remote_retrieve_response_code($response);
                if ($code === 200 || $code === 204) {
                    $success = true;
                }
            }
        }

        return $success;
    }

    /**
     * Send form submission data to a webhook URL.
     *
     * @param string $url Webhook URL.
     * @param string $method HTTP method (POST, PUT, PATCH).
     * @param string $form_id Form ID.
     * @param string $form_name Form name.
     * @param array $fields Submitted fields (field_id => value).
     * @param array $form_fields_config Form field definitions.
     * @param string $secret HMAC signing secret.
     * @param array $headers Custom headers [{key, value}].
     * @return array ['sent' => bool, 'code' => int, 'error' => string|null]
     */
    public static function SendWebhook($url, $method, $form_id, $form_name, $fields, $form_fields_config, $secret = '', $headers = array())
    {
        if (empty($url)) {
            return array('sent' => false, 'code' => 0, 'error' => 'No URL provided.');
        }

        $validated_url = self::ValidateOutboundUrl($url);
        if (!$validated_url) {
            return array('sent' => false, 'code' => 0, 'error' => __('Invalid or blocked URL.', 'superb-blocks'));
        }

        $allowed_methods = array('POST', 'PUT', 'PATCH');
        if (!in_array(strtoupper($method), $allowed_methods, true)) {
            $method = 'POST';
        }

        // Build field data for payload
        $payload_fields = array();
        $sensitive_field_ids = self::GetSensitiveFieldIds($form_fields_config);

        foreach ($form_fields_config as $field_def) {
            $fid = isset($field_def['fieldId']) ? $field_def['fieldId'] : '';
            if (empty($fid) || in_array($fid, $sensitive_field_ids, true)) {
                continue;
            }

            $label = isset($field_def['label']) ? $field_def['label'] : $fid;
            $type = isset($field_def['fieldType']) ? $field_def['fieldType'] : 'text';
            $value = isset($fields[$fid]) ? $fields[$fid] : '';

            if ($type === 'file' && is_array($value)) {
                $value = self::ExtractFilenames($value);
            }

            $payload_fields[$fid] = array(
                'label' => $label,
                'value' => $value,
                'type' => $type,
            );
        }

        $payload = array(
            'form_id' => $form_id,
            'form_name' => $form_name,
            'submitted_at' => gmdate('c'),
            'fields' => $payload_fields,
        );

        $json = wp_json_encode($payload);

        $request_headers = array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'SuperbAddons/' . SUPERBADDONS_VERSION,
        );

        // HMAC signature
        if (!empty($secret)) {
            $request_headers['X-Superb-Signature'] = 'sha256=' . hash_hmac('sha256', $json, $secret);
        }

        // Custom headers
        if (is_array($headers)) {
            foreach ($headers as $h) {
                if (!empty($h['key'])) {
                    $request_headers[sanitize_text_field($h['key'])] = sanitize_text_field(isset($h['value']) ? $h['value'] : '');
                }
            }
        }

        $response = wp_remote_request($validated_url, array(
            'method' => strtoupper($method),
            'headers' => $request_headers,
            'body' => $json,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array('sent' => false, 'code' => 0, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $success = $code >= 200 && $code < 300;
        return array(
            'sent' => $success,
            'code' => $code,
            'error' => $success ? null : sprintf('HTTP %d', $code),
        );
    }

    /**
     * Send form submission data to Google Sheets.
     *
     * @param string $spreadsheet_id_or_url Spreadsheet ID or full URL.
     * @param string $sheet_name Sheet name (empty for first sheet).
     * @param array $fields Submitted fields (field_id => value).
     * @param array $form_fields_config Form field definitions.
     * @return array ['sent' => bool, 'error' => string|null]
     */
    public static function SendToGoogleSheets($spreadsheet_id_or_url, $sheet_name, $fields, $form_fields_config)
    {
        // Extract spreadsheet ID from URL if needed
        $spreadsheet_id = $spreadsheet_id_or_url;
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $spreadsheet_id_or_url, $matches)) {
            $spreadsheet_id = $matches[1];
        }

        if (empty($spreadsheet_id)) {
            return array('sent' => false, 'error' => 'No spreadsheet ID provided.');
        }

        $token = FormGoogleAuth::GetAccessToken();
        if (is_wp_error($token)) {
            return array('sent' => false, 'error' => $token->get_error_message());
        }

        $range = !empty($sheet_name) ? $sheet_name : 'Sheet1';

        // Build ordered labels and values from form field config
        $sensitive_field_ids = self::GetSensitiveFieldIds($form_fields_config);

        $header_labels = array();
        $row_values = array();
        foreach ($form_fields_config as $field_def) {
            $fid = isset($field_def['fieldId']) ? $field_def['fieldId'] : '';
            if (empty($fid) || in_array($fid, $sensitive_field_ids, true)) {
                continue;
            }

            $label = isset($field_def['label']) ? $field_def['label'] : $fid;
            $type = isset($field_def['fieldType']) ? $field_def['fieldType'] : 'text';
            $value = isset($fields[$fid]) ? $fields[$fid] : '';

            if ($type === 'file' && is_array($value)) {
                $value = implode(', ', self::ExtractFilenames($value));
            }

            if (is_array($value)) {
                $value = wp_json_encode($value);
            }

            $header_labels[] = $label;
            $row_values[] = (string) $value;
        }

        $base_url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($spreadsheet_id);
        $auth_headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        );

        // Check if header row exists
        $check_url = $base_url . '/values/' . urlencode($range . '!1:1');
        $check_response = wp_remote_get($check_url, array(
            'headers' => $auth_headers,
            'timeout' => 15,
        ));

        if (is_wp_error($check_response)) {
            return array('sent' => false, 'error' => $check_response->get_error_message());
        }

        $check_code = wp_remote_retrieve_response_code($check_response);
        if ($check_code < 200 || $check_code >= 300) {
            return array('sent' => false, 'error' => sprintf('Google Sheets API returned HTTP %d', $check_code));
        }

        $check_body = json_decode(wp_remote_retrieve_body($check_response), true);
        $existing_headers = isset($check_body['values'][0]) ? $check_body['values'][0] : array();

        $values_to_append = array();

        if (empty($existing_headers)) {
            // No headers yet: prepend header row (note: concurrent first submissions may duplicate this row)
            $values_to_append[] = $header_labels;
            $values_to_append[] = $row_values;
        } else {
            // Match columns by existing header labels
            $ordered_values = array();
            foreach ($existing_headers as $existing_label) {
                $idx = array_search($existing_label, $header_labels, true);
                if ($idx !== false) {
                    $ordered_values[] = $row_values[$idx];
                } else {
                    $ordered_values[] = '';
                }
            }

            // Detect new labels not yet in the header row and append them
            $new_labels = array();
            $new_values = array();
            foreach ($header_labels as $i => $label) {
                if (!in_array($label, $existing_headers, true)) {
                    $new_labels[] = $label;
                    $new_values[] = $row_values[$i];
                }
            }

            if (!empty($new_labels)) {
                // Update the header row to include new columns
                $updated_headers = array_merge($existing_headers, $new_labels);
                $update_url = $base_url . '/values/' . urlencode($range . '!1:1') . '?valueInputOption=RAW';
                wp_remote_request($update_url, array(
                    'method' => 'PUT',
                    'headers' => $auth_headers,
                    'body' => wp_json_encode(array('values' => array($updated_headers))),
                    'timeout' => 15,
                ));
                $ordered_values = array_merge($ordered_values, $new_values);
            }

            $values_to_append[] = $ordered_values;
        }

        // Append values
        $append_url = $base_url . '/values/' . urlencode($range) . ':append?valueInputOption=USER_ENTERED';
        $append_response = wp_remote_post($append_url, array(
            'headers' => $auth_headers,
            'body' => wp_json_encode(array('values' => $values_to_append)),
            'timeout' => 15,
        ));

        if (is_wp_error($append_response)) {
            return array('sent' => false, 'error' => $append_response->get_error_message());
        }

        $append_code = wp_remote_retrieve_response_code($append_response);
        $success = $append_code >= 200 && $append_code < 300;
        return array(
            'sent' => $success,
            'error' => $success ? null : sprintf('Google Sheets API returned HTTP %d', $append_code),
        );
    }

    /**
     * Send form submission notification to Slack via Incoming Webhook.
     *
     * @param string $webhook_url Slack Incoming Webhook URL.
     * @param string $form_name Form name.
     * @param array $fields Submitted fields (field_id => value).
     * @param array $form_fields_config Form field definitions.
     * @return array ['sent' => bool, 'error' => string|null]
     */
    public static function SendToSlack($webhook_url, $form_name, $fields, $form_fields_config)
    {
        if (empty($webhook_url)) {
            return array('sent' => false, 'error' => 'No webhook URL provided.');
        }

        $validated_url = self::ValidateOutboundUrl($webhook_url);
        if (!$validated_url) {
            return array('sent' => false, 'error' => __('Invalid or blocked URL.', 'superb-blocks'));
        }

        $sensitive_field_ids = self::GetSensitiveFieldIds($form_fields_config);

        // Build Slack Block Kit message
        $blocks = array();
        $blocks[] = array(
            'type' => 'header',
            'text' => array(
                'type' => 'plain_text',
                'text' => sprintf(
                    /* translators: %s: form name */
                    __('New submission: %s', 'superb-blocks'),
                    $form_name ? $form_name : __('Unnamed Form', 'superb-blocks')
                ),
                'emoji' => true,
            ),
        );

        foreach ($form_fields_config as $field_def) {
            $fid = isset($field_def['fieldId']) ? $field_def['fieldId'] : '';
            if (empty($fid) || in_array($fid, $sensitive_field_ids, true)) {
                continue;
            }

            $label = isset($field_def['label']) ? $field_def['label'] : $fid;
            $type = isset($field_def['fieldType']) ? $field_def['fieldType'] : 'text';
            $value = isset($fields[$fid]) ? $fields[$fid] : '';

            if ($type === 'file' && is_array($value)) {
                $value = implode(', ', self::ExtractFilenames($value));
            }

            if (is_array($value)) {
                $value = wp_json_encode($value);
            }

            $escaped_label = self::EscapeSlackMrkdwn($label);
            $escaped_value = $value !== '' ? self::EscapeSlackMrkdwn($value) : '_empty_';

            $blocks[] = array(
                'type' => 'section',
                'text' => array(
                    'type' => 'mrkdwn',
                    'text' => '*' . $escaped_label . '*' . "\n" . $escaped_value,
                ),
            );
        }

        $payload = array('blocks' => $blocks);

        $response = wp_remote_post($validated_url, array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return array('sent' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $success = $code === 200;
        return array(
            'sent' => $success,
            'error' => $success ? null : sprintf('Slack returned HTTP %d', $code),
        );
    }

    /**
     * Send contact to Brevo (Sendinblue).
     *
     * @param array $list_ids List IDs to subscribe to.
     * @param string $email
     * @param array $fields Additional attributes
     * @return bool
     */
    public static function SendToBrevo($list_ids, $email, $fields = array())
    {
        $api_key = FormSettings::Get(FormSettings::OPTION_BREVO_API_KEY);
        if (empty($api_key) || empty($email)) {
            return false;
        }

        if (!is_array($list_ids)) {
            $list_ids = array($list_ids);
        }

        $int_list_ids = array();
        foreach ($list_ids as $lid) {
            if (!empty($lid)) {
                $int_list_ids[] = intval($lid);
            }
        }
        if (empty($int_list_ids)) {
            return false;
        }

        $url = 'https://api.brevo.com/v3/contacts';

        $body = array(
            'email' => sanitize_email($email),
            'listIds' => $int_list_ids,
            'updateEnabled' => true,
        );

        if (!empty($fields)) {
            $attributes = array();
            foreach ($fields as $key => $value) {
                $attributes[strtoupper(sanitize_key($key))] = sanitize_text_field($value);
            }
            if (!empty($attributes)) {
                $body['attributes'] = $attributes;
            }
        }

        $response = wp_remote_post($url, array(
            'headers' => array(
                'api-key' => $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
            'body' => wp_json_encode($body),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        return $code === 201 || $code === 204;
    }
}
