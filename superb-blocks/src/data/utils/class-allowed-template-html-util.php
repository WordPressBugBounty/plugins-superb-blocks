<?php

namespace SuperbAddons\Data\Utils;

defined('ABSPATH') || exit();

class AllowedTemplateHTMLUtil
{
    public static function enable_safe_styles()
    {
        add_filter("safe_style_css", array(__CLASS__, "_add_display_safe_style_css"));
    }

    public static function disable_safe_styles()
    {
        remove_filter("safe_style_css", array(__CLASS__, "_add_display_safe_style_css"));
    }

    public static function _add_display_safe_style_css($styles)
    {
        if (!is_array($styles)) {
            $styles = [];
        }
        $styles[] = 'display';
        return $styles;
    }
}
