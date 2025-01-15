<?php

namespace SuperbAddons\Data\Utils;

defined('ABSPATH') || exit();

use Exception;
use SuperbAddons\Data\Utils\QuietSkin;

require_once(ABSPATH . 'wp-admin/includes/theme-install.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/misc.php');
require_once(ABSPATH . 'wp-admin/includes/theme.php');
require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');

class ThemeInstaller
{
    public static function Install($theme)
    {
        $activated = self::ActivateTheme($theme);
        if ($activated) {
            return true;
        }

        $themes_api = \themes_api(
            'theme_information',
            array(
                'slug' => $theme,
                'fields' => array(
                    'description' => false,
                    'sections' => false,
                    'requires' => false,
                    'rating' => false,
                    'ratings' => false,
                    'downloaded' => false,
                    'last_updated' => false,
                    'added' => false,
                    'tags' => false,
                    'compatibility' => false,
                    'homepage' => false,
                    'donate_link' => false,
                ),
            )
        );

        if (is_wp_error($themes_api)) {
            return false;
        }

        $upgrader = new \Theme_Upgrader(new QuietSkin());

        $installation_result = $upgrader->install($themes_api->download_link);

        if (!$installation_result || is_wp_error($installation_result)) {
            return false;
        }

        return self::ActivateTheme($theme);
    }

    private static function ActivateTheme($theme_slug)
    {
        // Activate theme
        $theme = wp_get_theme($theme_slug);
        if ($theme->exists()) {
            if (function_exists('validate_theme_requirements')) {
                $validated = validate_theme_requirements($theme_slug);
                if (is_wp_error($validated)) {
                    throw new ThemeInstallerException(esc_html(wp_strip_all_tags($validated->get_error_message())));
                }
            }

            switch_theme($theme->get_stylesheet());
            return true;
        }

        return false;
    }
}
