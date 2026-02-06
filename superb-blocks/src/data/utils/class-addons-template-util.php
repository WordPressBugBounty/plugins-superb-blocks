<?php

namespace SuperbAddons\Data\Utils\Wizard;

use SuperbAddons\Data\Utils\AllowedTemplateHTMLUtil;
use WP_Block_Template;

defined('ABSPATH') || exit();

class AddonsPageTemplateUtil
{
    const TEMPLATE_ID = 'superbaddons-page-template';
    const PLUGIN_SLUG = 'superb-blocks/plugin';

    public static function GetAddonsPageBlockTemplateObject()
    {
        $template_content = false;
        $template_source  = "plugin";

        $template                 = new WP_Block_Template();
        $template->type           = WizardItemTypes::WP_TEMPLATE;
        $template->theme          = self::PLUGIN_SLUG;
        $template->slug           = self::TEMPLATE_ID;
        $template->id             = self::PLUGIN_SLUG . '//' . self::TEMPLATE_ID;
        $template->title          = __("Superb Addons Template Page", "superb-blocks");
        $template->description    = __("This is a basic full width page template that includes a header and footer. This template is used when creating template pages in Superb Addons.", "superb-blocks");
        $template->origin         = 'plugin';
        $template->status         = 'publish';
        $template->has_theme_file = true;
        $template->is_custom      = true;
        $template->post_types     = ['page'];

        // Get the saved custom template from the database if it exists.
        $custom_template_query_args = array(
            'post_type'      => WizardItemTypes::WP_TEMPLATE,
            'posts_per_page' => 1,
            'name'           => self::TEMPLATE_ID,
            'no_found_rows'  => true,
            // Ensure we only get template parts for the current theme -> Tax query is used like in WP core
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            'tax_query'      => array(
                array(
                    'taxonomy' => 'wp_theme',
                    'field'    => 'name',
                    'terms'    => array(self::PLUGIN_SLUG, get_stylesheet()),
                ),
            ),
        );

        $custom_template_query   = new \WP_Query($custom_template_query_args);
        $custom_superb_templates = $custom_template_query->posts;

        if ($custom_superb_templates && !empty($custom_superb_templates)) {
            $template_content = $custom_superb_templates[0]->post_content;
            $template_source = "custom";
        } else {
            $page_content = '<!-- wp:post-content {"align":"full","layout":{"type":"default"},"lock":{"move":true,"remove":true}} /-->';
            $template_content = self::GetAddonsPageTemplateContent($page_content);
        }

        $template->source         = $template_source;
        $template->content        = $template_content;

        return $template;
    }

    public static function GetAddonsPageTemplateContent($page_content)
    {
        $template = '<!-- wp:template-part {"slug":"header","lock":{"move":true,"remove":true}} /-->' . "\n";
        $template .= '<!-- wp:group {"tagName":"main","lock":{"move":true,"remove":true},"style":{"spacing":{"margin":{"top":"0rem"},"padding":{"top":"0","bottom":"0","left":"0","right":"0"},"blockGap":"var:preset|spacing|superbspacing-medium"}},"layout":{"type":"default"}} -->' . "\n";
        $template .= '<main class="wp-block-group" style="margin-top:0rem;padding-top:0;padding-right:0;padding-bottom:0;padding-left:0">' . "\n";

        AllowedTemplateHTMLUtil::enable_safe_styles();
        $template .= wp_kses($page_content, "post");
        AllowedTemplateHTMLUtil::disable_safe_styles();

        $template .= '</main>' . "\n";
        $template .= '<!-- /wp:group -->' . "\n";
        $template .= '<!-- wp:template-part {"slug":"footer","lock":{"move":true,"remove":true}} /-->';

        return $template;
    }

    public static function GetAddonsTemplatePartFallbackObject($slug, $area, $title, $description, $content, $is_file_template)
    {
        $template_content = $content;
        $template_source  = self::PLUGIN_SLUG;

        $template                 = new WP_Block_Template();
        $template->type           = WizardItemTypes::WP_TEMPLATE_PART;
        $template->theme          = get_stylesheet();
        $template->slug           = $slug;
        $template->id             = get_stylesheet() . '//' . $slug;
        $template->title          = $title;
        $template->description    = $description;
        $template->origin         = 'theme';
        $template->status         = 'publish';
        $template->has_theme_file = true;
        $template->is_custom      = true;
        $template->area           = $area;

        if (!$is_file_template) {
            // Get the saved custom template from the database if it exists.
            $custom_template_query_args = array(
                'post_type'      => WizardItemTypes::WP_TEMPLATE_PART,
                'posts_per_page' => -1,
                'no_found_rows'  => true,
                // Ensure we only get template parts for the current theme -> Tax query is used like in WP core
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                'tax_query' => array(
                    'relation' => 'AND',
                    array(
                        'taxonomy' => 'wp_theme',
                        'field' => 'name',
                        'terms' => get_stylesheet()
                    ),
                    array(
                        'taxonomy' => 'wp_template_part_area',
                        'field'    => 'slug',
                        'terms'    => array($area),
                    ),
                )
            );

            $custom_template_query   = new \WP_Query($custom_template_query_args);
            $custom_superb_templates = $custom_template_query->posts;

            if ($custom_superb_templates && !empty($custom_superb_templates)) {
                foreach ($custom_superb_templates as $custom_template) {
                    if ($custom_template->post_name === $slug) {
                        $template_content = $custom_template->post_content;
                        $template_source = "custom";
                        break;
                    }
                }
            }
        }

        $template->source         = $template_source;
        $template->content        = $template_content;

        return $template;
    }

    public static function GetAddonsHeaderTemplatePartObject($is_file_template)
    {
        return self::GetAddonsTemplatePartFallbackObject(
            'header',
            'header',
            __("Superb Addons Header", "superb-blocks"),
            __("This is a basic header template part that includes a site title and navigation menu. This template part is used as a fallback when no header template part exists in the current theme.", "superb-blocks"),
            self::GetAddonsHeaderTemplatePartContent(),
            $is_file_template
        );
    }

    public static function GetAddonsHeaderTemplatePartContent()
    {
        $template = '<!-- wp:group {"lock":{"move":true,"remove":true},"layout":{"type":"constrained"}} -->' . "\n";
        $template .= '<div class="wp-block-group"><!-- wp:group {"tagName":"header","lock":{"move":true,"remove":true},"style":{"spacing":{"padding":{"top":"var:preset|spacing|superbspacing-small","bottom":"var:preset|spacing|superbspacing-small","left":"var:preset|spacing|superbspacing-small","right":"var:preset|spacing|superbspacing-small"},"blockGap":"var:preset|spacing|superbspacing-small"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->' . "\n";
        $template .= '<header class="wp-block-group" style="padding-top:var(--wp--preset--spacing--superbspacing-small);padding-right:var(--wp--preset--spacing--superbspacing-small);padding-bottom:var(--wp--preset--spacing--superbspacing-small);padding-left:var(--wp--preset--spacing--superbspacing-small)"><!-- wp:site-title {"level":0,"lock":{"move":true,"remove":true}} /-->' . "\n";
        $template .= '<!-- wp:navigation {"lock":{"move":true,"remove":true},"style":{"spacing":{"blockGap":"var:preset|spacing|superbspacing-small"}}} /--></header>' . "\n";
        $template .= '<!-- /wp:group --></div>' . "\n";
        $template .= '<!-- /wp:group -->';

        return $template;
    }

    public static function GetAddonsFooterTemplatePartObject($is_file_template)
    {
        return self::GetAddonsTemplatePartFallbackObject(
            'footer',
            'footer',
            __("Superb Addons Footer", "superb-blocks"),
            __("This is a basic footer template part that includes site info. This template part is used as a fallback when no footer template part exists in the current theme.", "superb-blocks"),
            self::GetAddonsFooterTemplatePartContent(),
            $is_file_template
        );
    }

    public static function GetAddonsFooterTemplatePartContent()
    {
        $template = '<!-- wp:group {"lock":{"move":true,"remove":true},"layout":{"type":"constrained"}} -->' . "\n";
        $template .= '<div class="wp-block-group"><!-- wp:group {"tagName":"footer","lock":{"move":true,"remove":true},"style":{"spacing":{"padding":{"top":"var:preset|spacing|superbspacing-small","bottom":"var:preset|spacing|superbspacing-small","left":"var:preset|spacing|superbspacing-small","right":"var:preset|spacing|superbspacing-small"},"blockGap":"var:preset|spacing|superbspacing-small"}},"layout":{"type":"constrained"}} -->' . "\n";
        $template .= '<footer class="wp-block-group" style="padding-top:var(--wp--preset--spacing--superbspacing-small);padding-right:var(--wp--preset--spacing--superbspacing-small);padding-bottom:var(--wp--preset--spacing--superbspacing-small);padding-left:var(--wp--preset--spacing--superbspacing-small)"><!-- wp:site-info {"lock":{"move":true,"remove":true}} /--></footer>' . "\n";
        $template .= '<!-- /wp:group --></div>' . "\n";
        $template .= '<!-- /wp:group -->';

        return $template;
    }
}
