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

        // Installing writes to wp-content/themes: the capability the Themes screen asks for, which also covers
        // DISALLOW_FILE_MODS and, on a network, limits installs to network administrators.
        if (!current_user_can('install_themes')) {
            throw new ThemeInstallerException(esc_html__('You do not have permission to install themes on this site.', 'superb-blocks'));
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

        // The upgrader asks for filesystem credentials on servers without direct file access; that form belongs
        // on the Themes screen, not in a REST response
        if ('direct' !== get_filesystem_method()) {
            throw new ThemeInstallerException(esc_html__('WordPress cannot install themes automatically on this server because filesystem credentials are required. Please install the theme from the Themes screen.', 'superb-blocks'));
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

            if (!current_user_can('switch_themes')) {
                throw new ThemeInstallerException(esc_html__('You do not have permission to switch themes on this site.', 'superb-blocks'));
            }
            // On a network a site activates only the themes enabled for it, as its Themes screen lists them
            if (is_multisite() && !is_super_admin() && !$theme->is_allowed()) {
                throw new ThemeInstallerException(esc_html__('This theme has not been enabled for your site by the network administrator.', 'superb-blocks'));
            }

            switch_theme($theme->get_stylesheet());
            return true;
        }

        return false;
    }
}
