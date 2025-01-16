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

    public static function get_allowed_html()
    {
        // Get 'post' allowed html tags for wp_kses
        $allowed_html = wp_kses_allowed_html('post');
        // Add additional required tags
        $allowed_html['select'] = array(
            'class' => true,
            'id' => true,
            'name' => true,
            'disabled' => true,
            'required' => true,
            'multiple' => true,
        );
        $allowed_html['option'] = array(
            'class' => true,
            'id' => true,
            'name' => true,
            'value' => true,
            'selected' => true,
            'disabled' => true,
        );
        $allowed_html['input'] = array(
            'class' => true,
            'id' => true,
            'name' => true,
            'type' => true,
            'value' => true,
            'checked' => true,
            'disabled' => true,
            'readonly' => true,
            'required' => true,
            'placeholder' => true,
            'maxlength' => true,
            'data-action' => true,
        );
        $allowed_html['svg'] = array(
            'class' => true,
            'id' => true,
            'xmlns' => true,
            'width' => true,
            'height' => true,
            'viewbox' => true,
            'fill' => true,
        );
        $allowed_html['path'] = array(
            'd' => true,
            'fill' => true,
        );

        return $allowed_html;
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
