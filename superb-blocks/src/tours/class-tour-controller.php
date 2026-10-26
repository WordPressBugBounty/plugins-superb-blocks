<?php

namespace SuperbAddons\Tours\Controllers;

use SuperbAddons\Admin\Controllers\TroubleshootingController;
use SuperbAddons\Components\Admin\Modal;
use SuperbAddons\Data\Controllers\LogController;
use SuperbAddons\Data\Controllers\RestController;
use SuperbAddons\Data\Utils\ScriptTranslations;
use Exception;

defined('ABSPATH') || exit();

class TourController
{
    const TOUR_GUTENBERG = 'superb-tour-gutenberg';
    const GUTENBERG_TOUR_BLOCKS = 'blocks';
    const GUTENBERG_TOUR_PATTERNS = 'patterns';
    const TOUR_ELEMENTOR = 'superb-tour-elementor';

    const TOUR_ELEMENTOR_PAGE_ID_OPTION = 'superbaddons_elementor_tour_id';
    // Marks tutorial pages. The value is the time the tutorial was last started.
    const TOUR_PAGE_META = '_superbaddons_tour_page';

    const TOUR_DASHBOARD_WELCOME_META = 'superbaddons_tour_dashboard_complete';
    const TOUR_BLOCK_THEME_META = 'superbaddons_tour_block_theme_complete';

    const TOUR_NONCE_ACTION = 'superbaddons-tour';
    const TOUR_NONCE_PARAM = 'superbaddons-tour-nonce';

    public function __construct()
    {
        add_action('enqueue_block_editor_assets', array($this, 'MaybeLoadGutenbergTour'), 0);
        add_action('elementor/editor/before_enqueue_scripts', array($this, 'MaybeLoadElementorTour'), 0);
        // Core's daily cleanup event, so no extra cron event is needed.
        add_action('wp_scheduled_delete', array(self::class, 'RemoveStaleTourPages'));
    }

    public function MaybeLoadGutenbergTour()
    {
        if (!isset($_GET[self::TOUR_GUTENBERG]) || !isset($_GET[self::TOUR_NONCE_PARAM])) return;
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET[self::TOUR_NONCE_PARAM])), self::TOUR_NONCE_ACTION)) return;
        global $pagenow;
        if ('post-new.php' !== $pagenow) {
            return;
        }

        $tour_param = sanitize_text_field(wp_unslash($_GET[self::TOUR_GUTENBERG]));
        $this->GutenbergTourAssets($tour_param);
    }

    public function MaybeLoadElementorTour()
    {
        if (!isset($_GET[self::TOUR_ELEMENTOR])) return;
        global $pagenow;
        if ('post.php' !== $pagenow) {
            return;
        }

        if (!isset($_GET['action']) || $_GET['action'] !== 'elementor' || !isset($_GET['post'])) return;

        $tour_page_id = absint($_GET['post']);
        if (!$tour_page_id || !self::IsTourPage($tour_page_id)) return;

        $tour_param = sanitize_text_field(wp_unslash($_GET[self::TOUR_ELEMENTOR]));
        if (!wp_verify_nonce($tour_param, 'superbaddons-tour-' . $tour_page_id)) {
            return;
        }
        $this->ElementorTourAssets($tour_param, $tour_page_id);
    }

    private function GutenbergTourAssets($tour_param)
    {
        $this->TourAssets();
        add_action('admin_footer', function () {
            new Modal();
        });

        if ($tour_param === self::GUTENBERG_TOUR_BLOCKS) {
            wp_enqueue_script(
                'superb-addons-guided-tours',
                SUPERBADDONS_ASSETS_PATH . '/js/guided-tours/gutenberg-blocks.js',
                array('wp-i18n', 'jquery'),
                SUPERBADDONS_VERSION,
                true
            );
            ScriptTranslations::Set('superb-addons-guided-tours');
        } else 
        if ($tour_param === self::GUTENBERG_TOUR_PATTERNS) {
            wp_enqueue_script(
                'superb-addons-guided-tours',
                SUPERBADDONS_ASSETS_PATH . '/js/guided-tours/gutenberg-patterns.js',
                array('wp-i18n', 'jquery'),
                SUPERBADDONS_VERSION,
                true
            );
            ScriptTranslations::Set('superb-addons-guided-tours');
        }
    }

    private function ElementorTourAssets($tour_param, $tour_page_id)
    {
        $this->TourAssets();
        add_action('elementor/editor/footer', function () {
            new Modal();
        });
        wp_enqueue_script(
            'superb-addons-guided-tours',
            SUPERBADDONS_ASSETS_PATH . '/js/guided-tours/elementor-sections.js',
            array('wp-i18n', 'jquery'),
            SUPERBADDONS_VERSION,
            true
        );
        ScriptTranslations::Set('superb-addons-guided-tours');
        wp_localize_script('superb-addons-guided-tours', 'superbaddonstroubleshooting_g', array(
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::REST_NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "tour_nonce" => isset($tour_param) ? esc_html($tour_param) : false,
                "tour_page" => absint($tour_page_id),
                "routes" => array(
                    "tutorial" => TroubleshootingController::TUTORIAL_ROUTE,
                )
            ),
        ));
    }

    private function TourAssets()
    {
        wp_enqueue_style(
            'superbaddons-driver',
            SUPERBADDONS_ASSETS_PATH . '/lib/driver.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_style(
            'superb-addons-admin-modal',
            SUPERBADDONS_ASSETS_PATH . '/css/admin-modal.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
    }

    public static function GetElementorTourURL()
    {
        if (!current_user_can('edit_pages')) {
            return false;
        }

        $tour_page_id = absint(get_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION));
        $tour_page = $tour_page_id ? get_post($tour_page_id) : null;
        $is_draft_page = $tour_page && $tour_page->post_status === 'draft' && self::IsTourPage($tour_page_id);
        $can_reuse = $is_draft_page
            && absint($tour_page->post_author) === get_current_user_id()
            && current_user_can('edit_post', $tour_page_id);

        if (!$can_reuse) {
            // Keep the previous draft identifiable for the daily cleanup.
            if ($is_draft_page) {
                add_post_meta($tour_page_id, self::TOUR_PAGE_META, time(), true);
            }

            $tour_page_id = wp_insert_post(array(
                'post_title' => __('Superb Addons Tutorial', "superb-blocks"),
                'post_type' => 'page',
                'post_status' => 'draft',
                'post_author' => get_current_user_id(),
                'meta_input' => array(self::TOUR_PAGE_META => time()),
            ));
            if (!$tour_page_id || is_wp_error($tour_page_id)) {
                return false;
            }
            update_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION, $tour_page_id, false);
        } else {
            update_post_meta($tour_page_id, self::TOUR_PAGE_META, time());
        }

        $edit_link = get_edit_post_link($tour_page_id, 'raw');
        if (empty($edit_link)) {
            return false;
        }

        $nonce = wp_create_nonce('superbaddons-tour-' . $tour_page_id);
        return add_query_arg(array(self::TOUR_ELEMENTOR => $nonce, "action" => "elementor"), $edit_link);
    }

    public static function IsTourPage($page_id)
    {
        $page_id = absint($page_id);
        if (!$page_id || get_post_type($page_id) !== 'page') {
            return false;
        }

        if (get_post_meta($page_id, self::TOUR_PAGE_META, true)) {
            return true;
        }

        // A stored page from before the marker meta existed also needs the tutorial title.
        return $page_id === absint(get_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION)) && self::HasTourTitle(get_post($page_id));
    }

    private static function HasTourTitle($post)
    {
        if (!$post) {
            return false;
        }

        $title = trim($post->post_title);
        return $title === 'Superb Addons Tutorial' || $title === __('Superb Addons Tutorial', "superb-blocks");
    }

    public static function IsTourCompleted($meta_key)
    {
        return (bool) get_user_meta(get_current_user_id(), $meta_key, true);
    }

    public static function MarkTourCompleted($meta_key)
    {
        update_user_meta(get_current_user_id(), $meta_key, true);
    }

    public static function CleanUpTourPage($nonce, $page_id = 0)
    {
        $option_page_id = absint(get_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION));
        $tour_page_id = absint($page_id);
        if (!$tour_page_id) {
            // Requests without a page id refer to the stored page.
            $tour_page_id = $option_page_id;
        }
        if (!$tour_page_id) {
            return;
        }

        if (!wp_verify_nonce($nonce, 'superbaddons-tour-' . $tour_page_id)) {
            return;
        }

        // Only unpublished drafts are removed.
        if (!self::IsTourPage($tour_page_id) || get_post_status($tour_page_id) !== 'draft' || !current_user_can('delete_post', $tour_page_id)) {
            return;
        }

        wp_delete_post($tour_page_id, true);
        if ($option_page_id === $tour_page_id) {
            delete_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION);
        }
    }

    public static function RemoveStaleTourPages()
    {
        try {
            $cutoff = time() - DAY_IN_SECONDS;
            $page_ids = get_posts(array(
                'post_type' => 'page',
                'post_status' => 'draft',
                'posts_per_page' => 50,
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the marker meta is the only way to find tutorial pages.
                'meta_query' => array(
                    array(
                        'key' => self::TOUR_PAGE_META,
                        'value' => $cutoff,
                        'compare' => '<',
                        'type' => 'NUMERIC',
                    ),
                ),
            ));
            $page_ids = array_map('absint', $page_ids);

            // Pages created before the marker meta existed.
            $option_page_id = absint(get_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION));
            $option_page = $option_page_id ? get_post($option_page_id) : null;
            if ($option_page && $option_page->post_type === 'page' && $option_page->post_status === 'draft' && !get_post_meta($option_page_id, self::TOUR_PAGE_META, true) && self::HasTourTitle($option_page)) {
                // post_modified is set on every draft, unlike post_modified_gmt.
                $modified = get_post_timestamp($option_page, 'modified');
                if ($modified && $modified < $cutoff) {
                    $page_ids[] = $option_page_id;
                }
            }

            foreach ($page_ids as $page_id) {
                // A tutorial page edited during the last day stays.
                $modified = get_post_timestamp($page_id, 'modified');
                if ($modified && $modified >= $cutoff) {
                    continue;
                }
                if (wp_trash_post($page_id) && $page_id === $option_page_id) {
                    delete_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION);
                }
            }
        } catch (Exception $ex) {
            LogController::HandleException($ex);
        }
    }
}
