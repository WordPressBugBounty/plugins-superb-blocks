<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormGoogleAuth
{
    const TRANSIENT_KEY = 'spb_google_token';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    /**
     * Get a valid access token, using cached transient or fetching a new one.
     *
     * @return string|\WP_Error Access token string or WP_Error on failure.
     */
    public static function GetAccessToken()
    {
        $cached = get_transient(self::TRANSIENT_KEY);
        if (!empty($cached)) {
            return $cached;
        }

        $client_email = FormSettings::Get(FormSettings::OPTION_GOOGLE_SHEETS_CLIENT_EMAIL);
        $private_key = FormSettings::Get(FormSettings::OPTION_GOOGLE_SHEETS_PRIVATE_KEY);

        if (empty($client_email) || empty($private_key)) {
            return new \WP_Error('no_credentials', __('Google Sheets credentials are not configured.', 'superb-blocks'));
        }

        $jwt = self::CreateJWT($client_email, $private_key, self::SCOPE);
        if (is_wp_error($jwt)) {
            return $jwt;
        }

        $response = wp_remote_post(self::TOKEN_URL, array(
            'body' => array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return new \WP_Error('token_request_failed', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $msg = isset($body['error_description']) ? $body['error_description'] : sprintf('HTTP %d', $code);
            return new \WP_Error('token_error', $msg);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $access_token = isset($body['access_token']) ? $body['access_token'] : '';
        if (empty($access_token)) {
            return new \WP_Error('no_token', __('No access token in response.', 'superb-blocks'));
        }

        // Cache for 50 minutes (tokens last 60 min)
        set_transient(self::TRANSIENT_KEY, $access_token, 50 * 60);

        return $access_token;
    }

    /**
     * Create a signed JWT for Google Service Account authentication.
     *
     * @param string $client_email Service account email.
     * @param string $private_key PEM-encoded private key.
     * @param string $scopes Space-separated scopes.
     * @return string|\WP_Error The signed JWT or WP_Error on failure.
     */
    private static function CreateJWT($client_email, $private_key, $scopes)
    {
        $now = time();
        $header = array('alg' => 'RS256', 'typ' => 'JWT');
        $claims = array(
            'iss' => $client_email,
            'scope' => $scopes,
            'aud' => self::TOKEN_URL,
            'exp' => $now + 3600,
            'iat' => $now,
        );

        $segments = array();
        $segments[] = self::Base64UrlEncode(wp_json_encode($header));
        $segments[] = self::Base64UrlEncode(wp_json_encode($claims));

        $signing_input = implode('.', $segments);

        $signature = '';
        $sign_result = openssl_sign($signing_input, $signature, $private_key, 'sha256WithRSAEncryption');
        if (!$sign_result) {
            return new \WP_Error('jwt_sign_failed', __('Failed to sign JWT. Check that the private key is valid.', 'superb-blocks'));
        }

        $segments[] = self::Base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * Base64 URL-safe encode.
     *
     * @param string $data Raw data.
     * @return string Base64url encoded string.
     */
    private static function Base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
