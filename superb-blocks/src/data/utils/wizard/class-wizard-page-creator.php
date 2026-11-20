<?php

namespace SuperbAddons\Data\Utils\Wizard;

use SuperbAddons\Admin\Controllers\Wizard\WizardRestorationPointController;
use SuperbAddons\Gutenberg\Controllers\GutenbergController;
use SuperbAddons\Library\Controllers\LibraryRequestController;
use WP_Error;

defined('ABSPATH') || exit();

class WizardPageCreator
{
    public static function CreateTemplatePages($selection_data, $wizardData)
    {
        $theme_templates = get_block_templates();
        if (empty($theme_templates) || empty(array_column($theme_templates, 'slug'))) {
            return rest_ensure_response(['success' => false, 'text' => esc_html__("Theme templates could not be loaded.", "superb-blocks")]);
        }
        $theme_template_slugs = array_column($theme_templates, 'slug');

        $page_stages = self::GetPageStages($wizardData);

        if (empty($page_stages)) {
            throw new WizardException(esc_html__('Something went wrong. No page stages found in selections.', 'superb-blocks'));
        }

        $menu_items = self::CreateAndGetPageMenuItems(
            $page_stages,
            $selection_data,
            $theme_template_slugs,
            $wizardData->IsRestore()
        );

        return $menu_items;
    }

    private static function GetPageStages($wizardData)
    {
        $page_stages = [];

        foreach ($wizardData->GetStages() as $stage_type) {
            if (in_array($stage_type, WizardStageTypes::PAGE_STAGES)) {
                $page_stages[] = $stage_type;
            }
        }

        return $page_stages;
    }

    private static function CreateAndGetPageMenuItems($stage_types, $selections, $available_templates, $is_restore = false)
    {
        // WordPress lists the posts on a separate page only on a site with a static front page.
        // A run whose blog selection becomes the posts page keeps the reading settings in line with that.
        // Restore runs are left as they are, and one of the theme's own page templates lists the posts by itself.
        $blog_selections = isset($selections[WizardStageTypes::BLOG_PAGE_STAGE]) && is_array($selections[WizardStageTypes::BLOG_PAGE_STAGE]) ? $selections[WizardStageTypes::BLOG_PAGE_STAGE] : array();
        $align_reading_settings = !$is_restore
            && in_array(WizardStageTypes::BLOG_PAGE_STAGE, $stage_types, true)
            && isset($blog_selections[0])
            && !self::IsThemePageTemplateSelection($blog_selections[0]);

        $menu_items = array();
        foreach ($stage_types as $stage_type) {
            if (!isset($selections[$stage_type]) || empty($selections[$stage_type])) {
                continue;
            }
            $created_menu_items = self::HandlePageCreation($stage_type, $selections[$stage_type], $available_templates, $align_reading_settings);
            $menu_items = array_merge($menu_items, $created_menu_items);
        }

        return $menu_items;
    }

    private static function HandlePageCreation($type, $selections, $available_templates, $align_reading_settings = false)
    {
        switch ($type) {
            case WizardStageTypes::FRONT_PAGE_STAGE:
                return self::HandleFrontPageCreation($selections[0], $available_templates, $align_reading_settings);
            case WizardStageTypes::BLOG_PAGE_STAGE:
                return self::HandleBlogPageCreation($selections[0], $available_templates, $align_reading_settings);
            case WizardStageTypes::TEMPLATE_PAGE_STAGE:
                return self::HandleTemplatePageCreation($selections, $available_templates);
            default:
                return [];
        }
    }

    private static function HandleFrontPageCreation($selection, $available_templates, $align_reading_settings = false)
    {
        $datatype = $selection['type'];
        // Set basic page type for creation.
        $selection['type'] = $datatype === WizardItemTypes::PAGE ? WizardItemTypes::PAGE : WizardItemTypes::WP_TEMPLATE;

        if (!boolval($selection['isChanged'])) {
            if ($align_reading_settings) {
                self::MaybeCreateStaticFrontPage($selection);
            }
            return [self::GetMenuItemArr(0, $selection)];
        }

        if ($datatype === 'static') {
            return [self::GetMenuItemArr(0, $selection)];
        }

        if ($datatype === 'blog') {
            update_option('show_on_front', 'posts');
            return [self::GetMenuItemArr(0, $selection)];
        }

        $created_page_id = self::CreateTemplatePage($selection, $available_templates, 'front-page');
        if (!$created_page_id) return [];

        update_option('show_on_front', 'page');
        update_option('page_on_front', $created_page_id);
        return [self::GetMenuItemArr(0, $selection)];
    }

    /**
     * The front page is kept and the run goes on to a blog selection. A site that shows its latest posts
     * through the theme's front-page template gets a static front page here, the same page a run makes
     * when a front page layout is selected, so that the Blog page of the run lists the posts.
     */
    private static function MaybeCreateStaticFrontPage($selection)
    {
        if (get_option('show_on_front') === 'page') {
            return;
        }

        $front_page_template = get_block_template(get_stylesheet() . "//front-page");
        if (!$front_page_template || !isset($front_page_template->id) || self::ListsThePostsOfTheRequest($front_page_template->content)) {
            // Without a front-page template, or with one that lists the posts of the request, the front itself
            // is the posts listing and stays that. HandleBlogPageCreation covers that.
            return;
        }

        $created_page_id = self::InsertPage($selection, self::GetFrontPageTemplatePageContent(), false);
        if (!$created_page_id) {
            return;
        }

        update_option('show_on_front', 'page');
        update_option('page_on_front', $created_page_id);
    }

    /**
     * Whether block content holds a Query Loop that takes its posts from the request (the "inherit" setting),
     * in the content itself or in a pattern it places. On a static front page such a loop would show the page.
     */
    private static function ListsThePostsOfTheRequest($content, $depth = 0)
    {
        if ($depth > 3 || !is_string($content) || (strpos($content, 'wp:query') === false && strpos($content, 'wp:pattern') === false)) {
            return false;
        }

        $parsed_blocks = parse_blocks($content);
        foreach (_flatten_blocks($parsed_blocks) as $block) {
            if ('core/query' === $block['blockName']) {
                // A Query Loop without a query attribute uses the block's default, which takes the posts from the request.
                if (!isset($block['attrs']['query']) || !empty($block['attrs']['query']['inherit'])) {
                    return true;
                }
                continue;
            }

            if ('core/pattern' !== $block['blockName'] || empty($block['attrs']['slug']) || !is_string($block['attrs']['slug'])) {
                continue;
            }
            $pattern = \WP_Block_Patterns_Registry::get_instance()->get_registered($block['attrs']['slug']);
            if (is_array($pattern) && isset($pattern['content']) && self::ListsThePostsOfTheRequest($pattern['content'], $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a selection is one of the theme's own page templates: not the front-page, home or index template,
     * and not a file version or a restore point of one of them.
     */
    private static function IsThemePageTemplateSelection($selection)
    {
        if (!is_array($selection) || !isset($selection['type'], $selection['slug']) || $selection['type'] !== WizardItemTypes::WP_TEMPLATE) {
            return false;
        }

        $slug = (string) $selection['slug'];
        if (strpos($slug, WizardItemIdAffix::RESTORATION_POINT) !== false || strpos($slug, WizardItemIdAffix::FILE_TEMPLATE) !== false) {
            return false;
        }

        return !in_array($slug, array('front-page', 'home', 'index'), true);
    }

    private static function HandleBlogPageCreation($selection, $available_templates, $align_reading_settings = false)
    {
        $datatype = $selection['type'];
        $is_theme_page_template = self::IsThemePageTemplateSelection($selection);
        // Set basic page type for creation.
        $selection['type'] = $selection['type'] === WizardItemTypes::PAGE ? WizardItemTypes::PAGE : WizardItemTypes::WP_TEMPLATE;

        $is_kept = !boolval($selection['isChanged']) || $datatype === 'static';

        // One of the theme's own page templates lists the posts by itself, so the Blog page is an ordinary page
        // with that template. It is not set as the posts page (WordPress shows the home or index template there),
        // and the home and index templates stay as they are.
        if ($is_theme_page_template && !$is_kept) {
            $created_page_id = self::CreateTemplatePage($selection, $available_templates, 'page');
            if (!$created_page_id) return [];

            return [self::GetMenuItemArr($created_page_id, $selection)];
        }

        // On a site that shows its latest posts on the front, the front is the posts listing and a separate
        // page would not list them. A selected layout goes into the template of that listing, no page is made,
        // and the menu item leads to the front.
        if ($align_reading_settings && get_option('show_on_front') !== 'page') {
            if (!$is_kept && !self::CreateTemplatePage($selection, $available_templates, 'blog-template')) {
                return [];
            }
            return [self::GetMenuItemArr(0, $selection)];
        }

        if ($is_kept) {
            $blog_page_id = get_option('page_for_posts');
            if ($blog_page_id) {
                return [self::GetMenuItemArr($blog_page_id, $selection)];
            }
        }

        $created_page_id = self::CreateTemplatePage($selection, $available_templates, 'blog');
        if (!$created_page_id) return [];

        update_option('page_for_posts', $created_page_id);
        return [self::GetMenuItemArr($created_page_id, $selection)];
    }

    private static function HandleTemplatePageCreation($selections, $available_templates)
    {
        $menu_items = array();
        foreach ($selections as $selection) {
            $created_page_id = self::CreateTemplatePage($selection, $available_templates, 'page');
            if (!$created_page_id) {
                continue;
            }
            $menu_items[] = self::GetMenuItemArr($created_page_id, $selection);
        }
        return $menu_items;
    }

    private static function CreateTemplatePage($selection, $available_templates, $page_type)
    {
        $template_content = false;
        $template_slug = false;

        if ($selection['type'] === WizardItemTypes::WP_TEMPLATE) {
            list($template_content, $template_slug) = self::GetWPTemplateDataAndSlug($selection, $available_templates);
        } elseif ($selection['type'] === WizardItemTypes::PAGE) {
            list($template_content, $template_slug) = self::GetAddonsTemplateDataAndSlug($selection);
        }

        if (empty($template_content) || empty($template_slug)) {
            return false;
        }
        if ($page_type === 'front-page') {
            return self::HandleFrontPageTemplate($selection, $template_content, $template_slug, $selection['type'] === WizardItemTypes::PAGE);
        }
        if ($page_type === 'blog' || $page_type === 'blog-template') {
            return self::HandleBlogPageTemplate($selection, $template_content, $template_slug, $selection['type'] === WizardItemTypes::PAGE, $page_type === 'blog');
        }

        return self::InsertPage($selection, $template_content, $template_slug);
    }


    private static function GetWPTemplateDataAndSlug($selection, $available_templates)
    {
        $template_content = false;
        $template_slug = false;

        $is_restoration_point = WizardCreationUtil::IsRestorationPoint($selection['slug'], $selection['type'], WizardItemTypes::WP_TEMPLATE);
        $is_file_template = WizardCreationUtil::IsFileTemplate($selection['slug'], $selection['type'], WizardItemTypes::WP_TEMPLATE);
        $is_theme_template_page = $selection['slug'] !== "front-page" && $selection['slug'] !== "home" && $selection['slug'] !== "index";

        if ($is_restoration_point) {
            $template_slug = $selection['slug'];
            $restoration_point = WizardRestorationPointController::GetTemplateRestorationPoint($template_slug, WizardItemTypes::WP_TEMPLATE);
            if (!$restoration_point) {
                return [false, false];
            }
            $template_content = $restoration_point['content'];
        } else if ($is_theme_template_page) {
            if (!in_array($selection['slug'], $available_templates)) {
                return [false, false];
            }

            $template_slug = $selection['slug'];
            $template_content = '<!-- wp:paragraph -->
            <p>' . esc_html__("This is a theme template page created by Superb Addons. You can edit this page's content and its template. If the blocks you want to edit aren't in the page content, they're part of the template: open the page settings sidebar, click the template name under \"Template\", and choose \"Edit template\".", "superb-blocks") . '</p>
            <!-- /wp:paragraph -->';
        } else if ($is_file_template) {
            $template_slug = $selection['slug'];
            $file_template = get_block_file_template(get_stylesheet() . '//' . $template_slug, WizardItemTypes::WP_TEMPLATE);
            if (!$file_template) {
                return [false, false];
            }
            $template_content = $file_template->content;
        } else {
            $template_slug = $selection['slug'];
            $block_template = get_block_template(get_stylesheet() . '//' . $template_slug, WizardItemTypes::WP_TEMPLATE);
            if (!$block_template) {
                return [false, false];
            }
            $template_content = $block_template->content;
        }

        return [$template_content, $template_slug];
    }

    private static function GetAddonsTemplateDataAndSlug($selection)
    {
        $data = LibraryRequestController::GetInsertDataV2(
            array(
                'id' => $selection['slug'],
                'package' => $selection['package'],
            ),
            LibraryRequestController::GUTENBERG_TYPE_PAGE
        );
        if (!$data || !isset($data['content']) || isset($data['access_failed'])) {
            return [false, false];
        }

        $data = GutenbergController::GutenbergDataImportAction($data);

        $template_slug = AddonsPageTemplateUtil::TEMPLATE_ID;
        $template_content = $data['content'];
        // Users without unfiltered_html get the content as core would store it for them
        if (!current_user_can('unfiltered_html')) {
            $template_content = wp_kses_post($template_content);
        }

        return [$template_content, $template_slug];
    }

    private static function HandleTemplatePostRevisionCreationOrUpdate($template_post_slug, $template_object, $template_content, $include_addons_template_content)
    {
        $restoration_point = WizardRestorationPointController::CreateTemplateRestorationPoint($template_object->slug, WizardItemTypes::WP_TEMPLATE);
        if (!$restoration_point) {
            throw new WizardException(esc_html__('Template restoration point could not be created. If the issue persists, please contact support.', 'superb-blocks'));
        }

        $template_content = $include_addons_template_content ? AddonsPageTemplateUtil::GetAddonsPageTemplateContent($template_content) : $template_content;
        $current_template_post_id = WizardCreationUtil::GetTemplatePostID($template_post_slug, WizardItemTypes::WP_TEMPLATE);
        if ($current_template_post_id) {
            wp_update_post(
                array(
                    'ID' => $current_template_post_id,
                    // wp_update_post calls wp_unslash internally; slash so real
                    // backslashes in imported block markup survive intact.
                    'post_content' => wp_slash($template_content)
                )
            );
        } else {
            wp_insert_post(
                array(
                    'post_title' => $template_object->title,
                    'post_excerpt' => $template_object->description,
                    'post_name' => $template_post_slug,
                    // wp_insert_post calls wp_unslash internally; slash so real
                    // backslashes in imported block markup survive intact.
                    'post_content' => wp_slash($template_content),
                    'post_status' => 'publish',
                    'post_type' => WizardItemTypes::WP_TEMPLATE,
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                    'tax_input' => array(
                        'wp_theme' => array(get_stylesheet())
                    )
                )
            );
        }
    }

    private static function HandleFrontPageTemplate($selection, $template_content, $template_slug, $include_addons_template_content)
    {
        $front_page_template = get_block_template(get_stylesheet() . "//front-page");
        $is_front_page_and_has_front_page_template = $front_page_template && isset($front_page_template->id);

        if ($is_front_page_and_has_front_page_template) {
            $template_content = WizardCreationUtil::MaybeUpdateQueryLoopBlockInherit($template_content);
            self::HandleTemplatePostRevisionCreationOrUpdate('front-page', $front_page_template, $template_content, $include_addons_template_content);

            if (get_option('show_on_front') === 'page' && get_option('page_on_front')) {
                return get_option('page_on_front');
            }

            $template_slug = false;

            $template_content = self::GetFrontPageTemplatePageContent();
        }

        return self::InsertPage($selection, $template_content, $template_slug);
    }

    private static function GetFrontPageTemplatePageContent()
    {
        return '<!-- wp:paragraph -->
            <p>' . esc_html__("This is a front page template page created by Superb Addons. You can edit this page's content and its template. If the blocks you want to edit can't be found in the page content, please edit the front page template.", "superb-blocks") . '</p>
            <!-- /wp:paragraph -->';
    }

    private static function HandleBlogPageTemplate($selection, $template_content, $template_slug, $include_addons_template_content, $with_page = true)
    {
        $template_post_slug = 'home';
        $blog_page_template = get_block_template(get_stylesheet() . "//home");
        if (!$blog_page_template || !isset($blog_page_template->id)) {
            $blog_page_template = get_block_template(get_stylesheet() . "//index");
            $template_post_slug = 'index';
        }
        $is_blog_page_and_has_blog_page_template = $blog_page_template && isset($blog_page_template->id);

        if ($is_blog_page_and_has_blog_page_template) {
            $template_content = WizardCreationUtil::MaybeUpdateQueryLoopBlockInherit($template_content);
            self::HandleTemplatePostRevisionCreationOrUpdate($template_post_slug, $blog_page_template, $template_content, $include_addons_template_content);
        }

        if (!$with_page) {
            // The front of the site lists the posts through this template: there is no page to make.
            return true;
        }

        if ($is_blog_page_and_has_blog_page_template) {
            if (get_option('show_on_front') === 'page' && get_option('page_for_posts')) {
                return get_option('page_for_posts');
            }

            $template_slug = false;

            $template_content = '<!-- wp:paragraph -->
            <p>' . esc_html__("This is a blog page template page created by Superb Addons. You can edit this page's content and its template. If the blocks you want to edit can't be found in the page content, please edit the blog template (home or index).", "superb-blocks") . '</p>
            <!-- /wp:paragraph -->';
        }

        return self::InsertPage($selection, $template_content, $template_slug);
    }

    private static function InsertPage($selection, $template_content, $template_slug)
    {
        $post_data = array(
            'post_title' => esc_html(isset($selection['customTitle']) ? $selection['customTitle'] : $selection['title']),
            // wp_insert_post calls wp_unslash internally; slash so real
            // backslashes in imported block markup survive intact.
            'post_content' => wp_slash($template_content),
            'post_status' => 'publish',
            'post_type' => 'page'
        );
        if ($template_slug) {
            $post_data['page_template'] = $template_slug;
        }

        $created_page_id = wp_insert_post($post_data);

        if ($created_page_id instanceof WP_Error) {
            return false;
        }

        return $created_page_id;
    }

    private static function GetMenuItemArr($id, $selection)
    {
        $url = $id === 0 ? get_home_url() : get_permalink($id);
        return [
            'id' => absint($id),
            'title' => esc_attr(isset($selection['customTitle']) ? $selection['customTitle'] : $selection['title']),
            'url' => esc_url($url)
        ];
    }
}
