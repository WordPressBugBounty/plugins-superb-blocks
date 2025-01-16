<?php

namespace SuperbAddons\Library\Controllers;

use SuperbAddons\Data\Utils\AllowedTemplateHTMLUtil;

defined('ABSPATH') || exit();


class LibraryController
{
    public static function InsertTemplates()
    {
        self::OutputTemplates(false);
    }

    public static function InsertTemplatesWithWrapper()
    {
        self::OutputTemplates();
    }

    private static function OutputTemplates($with_wrapper = true)
    {
        AllowedTemplateHTMLUtil::enable_safe_styles();
        $allowed_html = AllowedTemplateHTMLUtil::get_allowed_html();
        ob_start();
        if ($with_wrapper) {
            echo '<div class="superb-addons-template-library-page-wrapper" style="display:none;">';
        }
        include(SUPERBADDONS_PLUGIN_DIR . 'src/library/templates/library-page.php');
        if ($with_wrapper) {
            echo '</div>';
        }
        $template = ob_get_clean();
        echo '<script type="text/template" id="tmpl-superbaddons-superb-library-page">' . wp_kses($template, $allowed_html) . '</script>';
        ob_start();
        include(SUPERBADDONS_PLUGIN_DIR . 'src/library/templates/library-item.php');
        $template = ob_get_clean();
        echo '<script type="text/template" id="tmpl-superbaddons-superb-library-item">' . wp_kses($template, $allowed_html) . '</script>';
        ob_start();
        include(SUPERBADDONS_PLUGIN_DIR . 'src/library/templates/library-menu-item.php');
        $template = ob_get_clean();
        echo '<script type="text/template" id="tmpl-superbaddons-superb-library-menu-item">' . wp_kses($template, $allowed_html) . '</script>';
        AllowedTemplateHTMLUtil::disable_safe_styles();
    }
}
