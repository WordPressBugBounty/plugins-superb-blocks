<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormEmailConfigCheck
{
    /**
     * Known SMTP plugin directory prefixes.
     */
    private static $known_smtp_plugins = array(
        'wp-mail-smtp/',
        'post-smtp/',
        'fluent-smtp/',
        'easy-wp-smtp/',
        'smtp-mailer/',
        'wp-smtp/',
        'mailgun/',
        'sparkpost/',
        'sendgrid-email-delivery-simplified/',
        'amazon-ses/',
        'offload-ses/',
        'wp-ses/',
        'smtp2go/',
        'postmark-approved-wordpress-plugin/',
        'mailpoet/',
    );

    /**
     * Check if the site appears to have email sending configured.
     *
     * Returns true if email is likely working, false if it almost certainly is not.
     * Only returns false when PHP mail() is disabled AND no SMTP plugin/override is detected.
     *
     * @return bool
     */
    public static function IsConfigured()
    {
        // If an SMTP plugin is active, email is configured
        if (self::HasSmtpPlugin()) {
            return true;
        }

        // If wp_mail has been overridden by a plugin, email is configured
        if (self::IsWpMailOverridden()) {
            return true;
        }

        // If phpmailer_init has listeners, a plugin is configuring the mailer
        if (has_action('phpmailer_init')) {
            return true;
        }

        // If PHP mail() function is available, default sending should work
        if (function_exists('mail')) {
            return true;
        }

        // PHP mail() is disabled and no SMTP plugin/override detected
        return false;
    }

    /**
     * Check if a known SMTP plugin is active.
     *
     * @return bool
     */
    private static function HasSmtpPlugin()
    {
        $active_plugins = get_option('active_plugins', array());
        if (!is_array($active_plugins)) {
            return false;
        }

        foreach ($active_plugins as $plugin) {
            foreach (self::$known_smtp_plugins as $prefix) {
                if (strpos($plugin, $prefix) === 0) {
                    return true;
                }
            }
        }

        // Check network-active plugins on multisite
        if (is_multisite()) {
            $network_plugins = get_site_option('active_sitewide_plugins', array());
            if (is_array($network_plugins)) {
                foreach (array_keys($network_plugins) as $plugin) {
                    foreach (self::$known_smtp_plugins as $prefix) {
                        if (strpos($plugin, $prefix) === 0) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if the wp_mail function has been overridden by a plugin.
     *
     * WordPress defines wp_mail in pluggable.php using function_exists(),
     * so plugins can provide their own implementation.
     *
     * @return bool
     */
    private static function IsWpMailOverridden()
    {
        if (!function_exists('wp_mail')) {
            return false;
        }

        $ref = new \ReflectionFunction('wp_mail');
        $file = $ref->getFileName();

        // If wp_mail is NOT defined in pluggable.php, it has been overridden
        return strpos($file, 'pluggable.php') === false;
    }
}
