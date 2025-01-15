<?php

namespace SuperbAddons\Tours\Controllers;

use SuperbAddons\Admin\Controllers\TroubleshootingController;
use SuperbAddons\Components\Admin\Modal;
use SuperbAddons\Data\Controllers\RestController;
use SuperbAddons\Data\Utils\ScriptTranslations;

defined('ABSPATH') || exit();

class TourController
{
    const TOUR_GUTENBERG = 'superb-tour-gutenberg';
    const GUTENBERG_TOUR_BLOCKS = 'blocks';
    const GUTENBERG_TOUR_PATTERNS = 'patterns';
    const TOUR_ELEMENTOR = 'superb-tour-elementor';

    const TOUR_ELEMENTOR_PAGE_ID_OPTION = 'superbaddons_elementor_tour_id';

    const TOUR_NONCE_ACTION = 'superbaddons-tour';
    const TOUR_NONCE_PARAM = 'superbaddons-tour-nonce';

    public function __construct()
    {
        add_action('enqueue_block_editor_assets', array($this, 'MaybeLoadGutenbergTour'), 0);
        add_action('elementor/editor/before_enqueue_scripts', array($this, 'MaybeLoadElementorTour'), 0);
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

        $tour_page_id = get_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION);
        if (!$tour_page_id || $tour_page_id !== $_GET['post']) return;

        $tour_param = sanitize_text_field(wp_unslash($_GET[self::TOUR_ELEMENTOR]));
        if (!wp_verify_nonce($tour_param, 'superbaddons-tour-' . $tour_page_id)) {
            return;
        }
        $this->ElementorTourAssets($tour_param);
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

    private function ElementorTourAssets($tour_param)
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
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "tour_nonce" => isset($tour_param) ? esc_html($tour_param) : false,
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
        $tour_page_id = get_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION);
        $post_status = $tour_page_id ? get_post_status($tour_page_id) : false;
        if (!$tour_page_id || $post_status !== 'draft') {
            $tour_page_id = wp_insert_post(array(
                'post_title' => __('Superb Addons Tutorial', "superb-blocks"),
                'post_type' => 'page',
                'post_status' => 'draft'
            ));
            update_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION, $tour_page_id, false);
        } else {
        }

        $nonce = wp_create_nonce('superbaddons-tour-' . $tour_page_id);
        $edit_link = get_edit_post_link($tour_page_id);
        return add_query_arg(array(self::TOUR_ELEMENTOR => $nonce, "action" => "elementor"), $edit_link);
    }

    public static function CleanUpTourPage($nonce)
    {
        $tour_page_id = get_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION);
        if (!$tour_page_id) {
            return;
        }

        if (!wp_verify_nonce($nonce, 'superbaddons-tour-' . $tour_page_id)) {
            return;
        }

        wp_delete_post($tour_page_id, true);
        delete_option(self::TOUR_ELEMENTOR_PAGE_ID_OPTION);
    }
}
