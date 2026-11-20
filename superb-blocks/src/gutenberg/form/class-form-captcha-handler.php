<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormCaptchaHandler
{
    /** reCAPTCHA v3: the action the form script requests its token for, and the lowest score accepted. */
    const RECAPTCHA_V3_ACTION = 'submit';
    const RECAPTCHA_V3_MIN_SCORE = 0.5;

    /**
     * Verify a captcha token based on type.
     *
     * @param string $type Captcha type
     * @param string $token Captcha token from frontend
     * @return bool|string True on success, error message on failure
     */
    public static function Verify($type, $token)
    {
        if ($type === 'honeypot') {
            return true; // Honeypot is checked in the controller
        }

        if (empty($token)) {
            return __('Captcha verification failed. Please try again.', 'superb-blocks');
        }

        switch ($type) {
            case 'hcaptcha':
                return self::VerifyHCaptcha($token);
            case 'recaptcha_v2':
            case 'recaptcha_v3':
                return self::VerifyRecaptcha($token, $type);
            case 'turnstile':
                return self::VerifyTurnstile($token);
            default:
                return true;
        }
    }

    private static function VerifyHCaptcha($token)
    {
        $secret = FormSettings::Get(FormSettings::OPTION_HCAPTCHA_SECRET_KEY);
        if (empty($secret)) {
            return __('hCaptcha is not configured.', 'superb-blocks');
        }

        $response = wp_remote_post('https://hcaptcha.com/siteverify', array(
            'body' => array(
                'secret' => $secret,
                'response' => $token,
            ),
        ));

        return self::ParseVerificationResponse($response);
    }

    private static function VerifyRecaptcha($token, $type = 'recaptcha_v2')
    {
        $secret = FormSettings::Get(FormSettings::OPTION_RECAPTCHA_SECRET_KEY);
        if (empty($secret)) {
            return __('reCAPTCHA is not configured.', 'superb-blocks');
        }

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
            'body' => array(
                'secret' => $secret,
                'response' => $token,
            ),
        ));

        return self::ParseVerificationResponse($response, $type);
    }

    private static function VerifyTurnstile($token)
    {
        $secret = FormSettings::Get(FormSettings::OPTION_TURNSTILE_SECRET_KEY);
        if (empty($secret)) {
            return __('Turnstile is not configured.', 'superb-blocks');
        }

        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Server-to-server Turnstile token verification required by the Cloudflare Turnstile API. No assets are loaded from this URL.
        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
            'body' => array(
                'secret' => $secret,
                'response' => $token,
            ),
        ));

        return self::ParseVerificationResponse($response);
    }

    private static function ParseVerificationResponse($response, $type = '')
    {
        if (is_wp_error($response)) {
            return __('Captcha verification failed. Please try again.', 'superb-blocks');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['success']) || $body['success'] !== true) {
            return __('Captcha verification failed. Please try again.', 'superb-blocks');
        }

        // reCAPTCHA v3 answers with a score and the action the token was requested for: a token counts when it
        // was issued for this form's submit action with a score at or above the threshold (filterable)
        if ($type === 'recaptcha_v3') {
            $min_score = floatval(apply_filters('superbaddons_form_recaptcha_v3_min_score', self::RECAPTCHA_V3_MIN_SCORE));
            $action = isset($body['action']) && is_string($body['action']) ? $body['action'] : '';
            $score = isset($body['score']) && is_numeric($body['score']) ? floatval($body['score']) : -1;
            if ($action !== self::RECAPTCHA_V3_ACTION || $score < $min_score) {
                return __('Captcha verification failed. Please try again.', 'superb-blocks');
            }
        }

        return true;
    }
}
