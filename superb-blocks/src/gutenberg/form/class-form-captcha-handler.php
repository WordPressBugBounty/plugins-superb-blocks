<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormCaptchaHandler
{
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
                return self::VerifyRecaptcha($token);
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

    private static function VerifyRecaptcha($token)
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

        return self::ParseVerificationResponse($response);
    }

    private static function VerifyTurnstile($token)
    {
        $secret = FormSettings::Get(FormSettings::OPTION_TURNSTILE_SECRET_KEY);
        if (empty($secret)) {
            return __('Turnstile is not configured.', 'superb-blocks');
        }

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
            'body' => array(
                'secret' => $secret,
                'response' => $token,
            ),
        ));

        return self::ParseVerificationResponse($response);
    }

    private static function ParseVerificationResponse($response)
    {
        if (is_wp_error($response)) {
            return __('Captcha verification failed. Please try again.', 'superb-blocks');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($body['success']) && $body['success'] === true) {
            return true;
        }

        return __('Captcha verification failed. Please try again.', 'superb-blocks');
    }
}
