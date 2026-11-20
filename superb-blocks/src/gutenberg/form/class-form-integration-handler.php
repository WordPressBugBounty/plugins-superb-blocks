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
     * Drop the fields flagged sensitive (and signatures) before a record goes to a list provider: the
     * provider needs the e-mail address and the ordinary fields, not the values the site keeps encrypted.
     *
     * @param array $fields Submitted field values.
     * @param array $form_fields_config Form field definitions.
     * @return array
     */
    private static function WithoutSensitiveFields($fields, $form_fields_config)
    {
        if (!is_array($fields)) {
            return array();
        }
        if (empty($form_fields_config) || !is_array($form_fields_config)) {
            return $fields;
        }
        foreach (self::GetSensitiveFieldIds($form_fields_config) as $fid) {
            if ($fid !== '') {
                unset($fields[$fid]);
            }
        }
        return $fields;
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
     * The public address each validated host resolved to (host => address), used by Request() to send the
     * request to the address that was checked.
     *
     * @var array
     */
    private static $checked_addresses = array();

    /**
     * Header names a form author's custom webhook headers cannot set: the ones the request itself sets, and the
     * ones that describe the connection or the message framing.
     *
     * @var array
     */
    private static $reserved_header_names = array(
        'content-type',
        'content-length',
        'host',
        'connection',
        'transfer-encoding',
        'upgrade',
        'te',
        'trailer',
        'keep-alive',
        'expect',
        'proxy-authorization',
        'proxy-connection',
        'user-agent',
        'x-superb-signature',
    );

    /**
     * Validate a URL a form author chose for an outbound HTTP request. Core's wp_http_validate_url refuses
     * loopback and private ranges; this also refuses the site's own hosts, localhost names, link-local
     * (169.254.0.0/16), shared (100.64.0.0/10), reserved and multicast addresses, and anything but http(s).
     * The host's A and AAAA records are all checked, and the address that passed is kept so that Request()
     * below sends the request there, without following redirects.
     *
     * @param string $url URL to validate.
     * @return string|false Validated URL or false if refused.
     */
    public static function ValidateOutboundUrl($url)
    {
        $url = esc_url_raw(is_string($url) ? trim($url) : '', array('http', 'https'));
        if (empty($url)) {
            return false;
        }
        $url = wp_http_validate_url($url);
        if (!$url) {
            return false;
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }
        $host = strtolower(trim($host, '.'));
        if ($host === 'localhost' || substr($host, -10) === '.localhost' || substr($host, -6) === '.local') {
            return false;
        }
        foreach (array(home_url(), site_url(), rest_url()) as $own) {
            $own_host = wp_parse_url($own, PHP_URL_HOST);
            if (is_string($own_host) && strtolower(trim($own_host, '.')) === $host) {
                return false;
            }
        }

        $addresses = self::ResolvePublicAddresses($host);
        if (empty($addresses)) {
            return false;
        }
        self::$checked_addresses[$host] = $addresses[0];

        return $url;
    }

    /**
     * The addresses a host name resolves to (A and AAAA records, IPv4 first), or an empty array when the name
     * does not resolve or any of its addresses is not a public one. An address literal is checked as it is.
     *
     * @param string $host Lower-case host name or address literal, without brackets.
     * @return array
     */
    private static function ResolvePublicAddresses($host)
    {
        $literal = trim($host, '[]');
        if (filter_var($literal, FILTER_VALIDATE_IP)) {
            return self::IsPublicAddress($literal) ? array($literal) : array();
        }

        $ipv4 = array();
        $ipv6 = array();
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record() raises a warning when a lookup fails; that case is handled below as no address.
        $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A | DNS_AAAA) : false;
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $ipv4[] = $record['ip'];
                } elseif (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ipv6[] = $record['ipv6'];
                }
            }
        }
        if (empty($ipv4) && empty($ipv6)) {
            $ip = gethostbyname($host);
            if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ipv4[] = $ip;
            }
        }

        $addresses = array_values(array_unique(array_merge($ipv4, $ipv6)));
        if (empty($addresses)) {
            return array();
        }
        foreach ($addresses as $address) {
            if (!self::IsPublicAddress($address)) {
                return array();
            }
        }
        return $addresses;
    }

    /**
     * Whether an IPv4 or IPv6 address is a public unicast address. PHP's filter flags cover the private,
     * loopback, link-local and unspecified ranges; the remaining special-purpose ranges are listed here.
     *
     * @param string $address
     * @return bool
     */
    private static function IsPublicAddress($address)
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = array_map('intval', explode('.', $address));
            if (count($parts) !== 4) {
                return false;
            }
            // Shared address space (100.64.0.0/10), IETF protocol assignments (192.0.0.0/24), benchmarking
            // (198.18.0.0/15), multicast and the class E range (224.0.0.0 and above)
            if ($parts[0] === 100 && $parts[1] >= 64 && $parts[1] <= 127) {
                return false;
            }
            if ($parts[0] === 192 && $parts[1] === 0 && $parts[2] === 0) {
                return false;
            }
            if ($parts[0] === 198 && ($parts[1] === 18 || $parts[1] === 19)) {
                return false;
            }
            if ($parts[0] >= 224) {
                return false;
            }
            return true;
        }

        $binary = inet_pton($address);
        if ($binary === false || strlen($binary) !== 16) {
            return false;
        }
        $hex = bin2hex($binary);
        // Multicast (ff00::/8), documentation (2001:db8::/32), NAT64 well-known prefix (64:ff9b::/96),
        // discard-only (100::/64) and IPv4-mapped addresses (::ffff:0:0/96), whose IPv4 part is checked
        if (substr($hex, 0, 2) === 'ff' || substr($hex, 0, 8) === '20010db8' || substr($hex, 0, 24) === '0064ff9b0000000000000000' || substr($hex, 0, 16) === '0100000000000000') {
            return false;
        }
        if (substr($hex, 0, 24) === '00000000000000000000ffff') {
            $mapped = long2ip(hexdec(substr($hex, 24, 8)));
            return $mapped !== false && self::IsPublicAddress($mapped);
        }
        return true;
    }

    /**
     * Send a request to a URL that passed ValidateOutboundUrl: core re-validates the address
     * (reject_unsafe_urls), no redirect is followed, and with the cURL transport the connection goes to the
     * address ValidateOutboundUrl checked, so no second name lookup can point it elsewhere.
     *
     * @param string $url  Validated URL.
     * @param array  $args wp_remote_request arguments.
     * @return array|\WP_Error
     */
    public static function Request($url, $args)
    {
        $args['redirection'] = 0;
        $args['reject_unsafe_urls'] = true;

        $host = wp_parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? strtolower(trim($host, '.')) : '';
        $address = isset(self::$checked_addresses[$host]) ? self::$checked_addresses[$host] : '';
        if ($address === '' || !defined('CURLOPT_RESOLVE') || !function_exists('curl_setopt')) {
            return wp_remote_request($url, $args);
        }

        $port = wp_parse_url($url, PHP_URL_PORT);
        if (!$port) {
            $port = wp_parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80;
        }
        $pin = function ($handle) use ($host, $port, $address) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- the handle is the WordPress HTTP API's own (http_api_curl); the option keeps its request on the address that was checked.
            curl_setopt($handle, CURLOPT_RESOLVE, array($host . ':' . intval($port) . ':' . $address));
        };
        add_action('http_api_curl', $pin, 10, 1);
        $response = wp_remote_request($url, $args);
        remove_action('http_api_curl', $pin, 10);
        return $response;
    }

    /**
     * The datacenter part of a Mailchimp API key ("<key>-us21"), which names the API host.
     *
     * @param string $api_key
     * @return string|false The datacenter, or false if the key doesn't carry one.
     */
    private static function GetMailchimpDatacenter($api_key)
    {
        $dc_parts = explode('-', (string) $api_key);
        if (count($dc_parts) < 2 || !preg_match('/^[a-z0-9]+$/i', $dc_parts[1])) {
            return false;
        }
        return $dc_parts[1];
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

        $dc = self::GetMailchimpDatacenter($api_key);
        if (!$dc) {
            return new \WP_Error('api_error', __('Invalid Mailchimp API key format.', 'superb-blocks'), array('status' => 400));
        }

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
    public static function SendToMailchimp($list_ids, $email, $fields = array(), $form_fields_config = array())
    {
        $fields = self::WithoutSensitiveFields($fields, $form_fields_config);
        $api_key = FormSettings::Get(FormSettings::OPTION_MAILCHIMP_API_KEY);
        if (empty($api_key) || empty($email)) {
            return false;
        }

        if (!is_array($list_ids)) {
            $list_ids = array($list_ids);
        }

        $dc = self::GetMailchimpDatacenter($api_key);
        if (!$dc) {
            return false;
        }

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
     * Request headers of a webhook call: the fixed ones, the signature when a secret is set, and the form's
     * custom headers. A submission and the editor's test request share these rules.
     *
     * @param string $json Request body.
     * @param string $secret HMAC signing secret.
     * @param array $headers Custom headers [{key, value}].
     * @return array Header name => value.
     */
    public static function WebhookRequestHeaders($json, $secret = '', $headers = array())
    {
        $request_headers = array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'SuperbAddons/' . SUPERBADDONS_VERSION,
        );

        // HMAC signature
        if (!empty($secret)) {
            $request_headers['X-Superb-Signature'] = 'sha256=' . hash_hmac('sha256', $json, $secret);
        }

        // Custom headers: up to 20 plain header names; the names in $reserved_header_names keep the values set above
        if (is_array($headers)) {
            $added = 0;
            foreach ($headers as $h) {
                if (!is_array($h) || !isset($h['key']) || !is_string($h['key'])) {
                    continue;
                }
                $name = trim($h['key']);
                if ($name === '' || strlen($name) > 64 || !preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                    continue;
                }
                if (in_array(strtolower($name), self::$reserved_header_names, true)) {
                    continue;
                }
                $value = isset($h['value']) && is_scalar($h['value']) ? sanitize_text_field((string) $h['value']) : '';
                $request_headers[$name] = substr($value, 0, 1024);
                if (++$added >= 20) {
                    break;
                }
            }
        }

        return $request_headers;
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

        $request_headers = self::WebhookRequestHeaders($json, $secret, $headers);

        $response = self::Request($validated_url, array(
            'method' => strtoupper($method),
            'headers' => $request_headers,
            'body' => $json,
            'timeout' => 5,
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

            // Ensure unique column headers so two fields sharing a label don't collide
            // when values are matched back to columns by header text below.
            $base_label = $label;
            $suffix = 2;
            while (in_array($label, $header_labels, true)) {
                $label = $base_label . ' (' . $suffix . ')';
                $suffix++;
            }

            $header_labels[] = $label;
            $row_values[] = (string) $value;
        }

        $base_url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheet_id);
        $auth_headers = array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        );

        // A1 notation requires sheet names with spaces or special characters to be wrapped in
        // single quotes (embedded quotes doubled). Use rawurlencode for path segments so a space
        // becomes %20, not '+' (which is a literal plus in a URL path and would target the wrong sheet).
        $quoted_sheet = "'" . str_replace("'", "''", $range) . "'";

        // Check if header row exists
        $check_url = $base_url . '/values/' . rawurlencode($quoted_sheet . '!1:1');
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
                $update_url = $base_url . '/values/' . rawurlencode($quoted_sheet . '!1:1') . '?valueInputOption=RAW';
                $update_response = wp_remote_request($update_url, array(
                    'method' => 'PUT',
                    'headers' => $auth_headers,
                    'body' => wp_json_encode(array('values' => array($updated_headers))),
                    'timeout' => 15,
                ));

                // If the header could not be extended, bail rather than appending values whose
                // columns have no header, which would land permanently misaligned in the sheet.
                if (is_wp_error($update_response)) {
                    return array('sent' => false, 'error' => $update_response->get_error_message());
                }
                $update_code = wp_remote_retrieve_response_code($update_response);
                if ($update_code < 200 || $update_code >= 300) {
                    return array('sent' => false, 'error' => sprintf('Google Sheets API returned HTTP %d', $update_code));
                }

                $ordered_values = array_merge($ordered_values, $new_values);
            }

            $values_to_append[] = $ordered_values;
        }

        // Append values. RAW (not USER_ENTERED) so submitted text is stored literally and a
        // value beginning with = + - @ is never evaluated as a spreadsheet formula (CSV injection).
        $append_url = $base_url . '/values/' . rawurlencode($quoted_sheet) . ':append?valueInputOption=RAW';
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
        // Slack issues incoming webhooks under one host and path
        if (
            wp_parse_url($validated_url, PHP_URL_SCHEME) !== 'https'
            || strtolower((string) wp_parse_url($validated_url, PHP_URL_HOST)) !== 'hooks.slack.com'
            || strpos((string) wp_parse_url($validated_url, PHP_URL_PATH), '/services/') !== 0
        ) {
            return array('sent' => false, 'error' => __('Slack webhook URLs start with https://hooks.slack.com/services/.', 'superb-blocks'));
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

        $response = self::Request($validated_url, array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
            'timeout' => 5,
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
    public static function SendToBrevo($list_ids, $email, $fields = array(), $form_fields_config = array())
    {
        $fields = self::WithoutSensitiveFields($fields, $form_fields_config);
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
