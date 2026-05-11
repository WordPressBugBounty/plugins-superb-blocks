<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormSettings
{
    const OPTION_HCAPTCHA_SITE_KEY = 'superbaddons_form_hcaptcha_site_key';
    const OPTION_HCAPTCHA_SECRET_KEY = 'superbaddons_form_hcaptcha_secret_key';
    const OPTION_RECAPTCHA_SITE_KEY = 'superbaddons_form_recaptcha_site_key';
    const OPTION_RECAPTCHA_SECRET_KEY = 'superbaddons_form_recaptcha_secret_key';
    const OPTION_TURNSTILE_SITE_KEY = 'superbaddons_form_turnstile_site_key';
    const OPTION_TURNSTILE_SECRET_KEY = 'superbaddons_form_turnstile_secret_key';
    const OPTION_MAILCHIMP_API_KEY = 'superbaddons_form_mailchimp_api_key';
    const OPTION_BREVO_API_KEY = 'superbaddons_form_brevo_api_key';
    const OPTION_GOOGLE_SHEETS_CLIENT_EMAIL = 'superbaddons_form_google_sheets_client_email';
    const OPTION_GOOGLE_SHEETS_PRIVATE_KEY = 'superbaddons_form_google_sheets_private_key';

    /**
     * Check if an option key holds a secret that should be encrypted at rest.
     * Site keys are intentionally excluded — they are public (exposed in frontend HTML).
     */
    private static function IsSensitiveKey($key)
    {
        static $sensitive = null;
        if ($sensitive === null) {
            $sensitive = array(
                self::OPTION_HCAPTCHA_SECRET_KEY,
                self::OPTION_RECAPTCHA_SECRET_KEY,
                self::OPTION_TURNSTILE_SECRET_KEY,
                self::OPTION_MAILCHIMP_API_KEY,
                self::OPTION_BREVO_API_KEY,
                self::OPTION_GOOGLE_SHEETS_PRIVATE_KEY,
            );
        }
        return in_array($key, $sensitive, true);
    }

    public static function Get($key, $default = '')
    {
        $value = get_option($key, $default);
        if (!is_string($value)) {
            return $default;
        }
        if ($value !== '' && self::IsSensitiveKey($key)) {
            $decrypted = FormEncryption::Decrypt($value);
            // Decrypt returns false on HMAC/decryption failure.
            return $decrypted !== false ? $decrypted : $default;
        }
        return $value;
    }

    public static function Set($key, $value)
    {
        // Private keys contain newlines required by PEM format; sanitize_text_field would strip them.
        $value = ($key === self::OPTION_GOOGLE_SHEETS_PRIVATE_KEY)
            ? sanitize_textarea_field($value)
            : sanitize_text_field($value);
        if ($value !== '' && self::IsSensitiveKey($key)) {
            $value = FormEncryption::Encrypt($value);
        }
        update_option($key, $value);
    }

    /**
     * Check whether a setting has a non-empty value stored.
     */
    public static function HasValue($key)
    {
        $value = get_option($key, '');
        return is_string($value) && $value !== '';
    }

    /**
     * Return a masked representation of a stored secret (e.g. "••••••••abcd").
     * Returns empty string if no value is stored.
     */
    public static function GetMasked($key, $visible = 4)
    {
        $value = self::Get($key);
        if ($value === '') {
            return '';
        }
        $len = strlen($value);
        if ($len <= $visible) {
            return str_repeat("\xE2\x80\xA2", $len);
        }
        return str_repeat("\xE2\x80\xA2", $len - $visible) . substr($value, -$visible);
    }

    /**
     * Remove a stored setting entirely.
     */
    public static function Remove($key)
    {
        delete_option($key);
    }

    // ---- Webhook Secrets (per-form, individually encrypted) ----

    const OPTION_WEBHOOK_SECRETS = 'superbaddons_form_webhook_secrets';

    /**
     * Get the decrypted webhook secret for a form.
     *
     * @param string $form_id
     * @return string Secret or empty string.
     */
    public static function GetWebhookSecret($form_id)
    {
        $secrets = get_option(self::OPTION_WEBHOOK_SECRETS, array());
        if (!is_array($secrets) || !isset($secrets[$form_id]) || $secrets[$form_id] === '') {
            return '';
        }
        $decrypted = FormEncryption::Decrypt($secrets[$form_id]);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Store an encrypted webhook secret for a form.
     *
     * @param string $form_id
     * @param string $secret Plaintext secret.
     */
    public static function SetWebhookSecret($form_id, $secret)
    {
        $secrets = get_option(self::OPTION_WEBHOOK_SECRETS, array());
        if (!is_array($secrets)) {
            $secrets = array();
        }
        $secret = sanitize_text_field($secret);
        if ($secret === '') {
            unset($secrets[$form_id]);
        } else {
            $secrets[$form_id] = FormEncryption::Encrypt($secret);
        }
        update_option(self::OPTION_WEBHOOK_SECRETS, $secrets, false);
    }

    /**
     * Check whether a form has a webhook secret configured.
     *
     * @param string $form_id
     * @return bool
     */
    public static function HasWebhookSecret($form_id)
    {
        $secrets = get_option(self::OPTION_WEBHOOK_SECRETS, array());
        return is_array($secrets) && isset($secrets[$form_id]) && $secrets[$form_id] !== '';
    }

    /**
     * Remove the webhook secret for a form.
     *
     * @param string $form_id
     */
    public static function RemoveWebhookSecret($form_id)
    {
        $secrets = get_option(self::OPTION_WEBHOOK_SECRETS, array());
        if (is_array($secrets) && isset($secrets[$form_id])) {
            unset($secrets[$form_id]);
            update_option(self::OPTION_WEBHOOK_SECRETS, $secrets, false);
        }
    }

    public static function GetCaptchaConfig()
    {
        return array(
            'hcaptchaSiteKey' => self::Get(self::OPTION_HCAPTCHA_SITE_KEY),
            'recaptchaSiteKey' => self::Get(self::OPTION_RECAPTCHA_SITE_KEY),
            'turnstileSiteKey' => self::Get(self::OPTION_TURNSTILE_SITE_KEY),
        );
    }

    /**
     * Count forms using a specific CAPTCHA provider.
     *
     * @param string $captcha_type e.g. 'hcaptcha', 'recaptcha_v2', 'recaptcha_v3', 'turnstile'
     * @return int
     */
    public static function CountFormsUsingCaptcha($captcha_type)
    {
        $registry = FormRegistry::GetAll();
        $count = 0;
        foreach (array_keys($registry) as $form_id) {
            $config = get_option(FormRegistry::CONFIG_PREFIX . sanitize_key($form_id), array());
            if (is_array($config) && isset($config['captchaType']) && $config['captchaType'] === $captcha_type) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Count forms using a specific CAPTCHA provider group (combines reCAPTCHA v2 and v3).
     *
     * @param string $provider 'hcaptcha', 'recaptcha', or 'turnstile'
     * @return int
     */
    public static function CountFormsUsingCaptchaProvider($provider)
    {
        $registry = FormRegistry::GetAll();
        $count = 0;
        $match_types = array($provider);
        if ($provider === 'recaptcha') {
            $match_types = array('recaptcha_v2', 'recaptcha_v3');
        }
        foreach (array_keys($registry) as $form_id) {
            $config = get_option(FormRegistry::CONFIG_PREFIX . sanitize_key($form_id), array());
            if (is_array($config) && isset($config['captchaType']) && in_array($config['captchaType'], $match_types, true)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Count forms using a specific integration (Mailchimp or Brevo).
     *
     * @param string $integration 'mailchimp' or 'brevo'
     * @return int
     */
    public static function CountFormsUsingIntegration($integration)
    {
        $registry = FormRegistry::GetAll();
        $count = 0;
        $attr_key = $integration === 'mailchimp' ? 'mailchimpEnabled' : 'brevoEnabled';
        foreach (array_keys($registry) as $form_id) {
            $config = get_option(FormRegistry::CONFIG_PREFIX . sanitize_key($form_id), array());
            if (is_array($config) && !empty($config[$attr_key])) {
                $count++;
            }
        }
        return $count;
    }
}
