<?php

namespace SuperbAddons\Admin\Controllers;

defined('ABSPATH') || exit();

use Exception;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Controllers\LogController;
use SuperbAddons\Data\Controllers\Option;
use SuperbAddons\Gutenberg\Controllers\GutenbergEnhancementsController;
use SuperbAddons\Library\Controllers\FavoritesController;
use SuperbAddons\Gutenberg\Form\FormGoogleAuth;
use SuperbAddons\Gutenberg\Form\FormRegistry;
use SuperbAddons\Gutenberg\Form\FormSettings;
use SuperbAddons\Gutenberg\Form\FormSubmissionCPT;
use SuperbAddons\Gutenberg\Form\FormSubmissionHandler;
use SuperbAddons\Gutenberg\Popup\PopupRegistry;
use SuperbAddons\Tours\Controllers\TourController;

class PluginResetController
{
    /**
     * Completely wipe every trace of plugin data from this site.
     *
     * Order matters: license removal must run before its option row is deleted
     * so the remote server is notified; the form registry must be read before
     * its option row is deleted so every dynamic config option gets cleaned up.
     *
     * @param bool $include_submissions When true, also deletes every stored form submission CPT post.
     * @return bool
     */
    public static function RemoveAllPluginData($include_submissions = false)
    {
        // 1. License first — notify remote server before the option row is deleted locally.
        //    Guarded so we don't make a pointless remote call when no key is registered.
        if (KeyController::HasRegisteredKey()) {
            try {
                KeyController::RemoveKey();
            } catch (Exception $e) {
                // RemoveKey already logs internally; swallow so a network failure
                // does not block the rest of the local cleanup.
            }
        }

        // 2. Unschedule cron hooks.
        $cron_hooks = array(
            'superbaddons_share_error_logs',
            'superb_blocks_template_restoration_cleanup',
            'spb_form_spam_purge',
            'spb_form_retention_purge',
        );
        foreach ($cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }

        // 3. Iterate form registry BEFORE deleting it so we can clean up every
        //    dynamic spb_form_cfg_{form_id} option row.
        $form_registry = get_option(FormRegistry::OPTION_KEY, array());
        if (is_array($form_registry)) {
            foreach ($form_registry as $form_id => $_unused) {
                delete_option(FormRegistry::CONFIG_PREFIX . sanitize_key($form_id));
            }
        }

        // 4. Delete every option row the plugin has ever written.
        $option_keys = array(
            // Core plugin options (with constants where available)
            Option::KEY_DOMAIN,
            Option::SETTINGS,
            Option::COMPATIBILITY_SETTINGS,
            Option::DISABLED_BLOCKS,
            Option::GLOBAL_ENHANCEMENTS,
            // Core plugin options without constants
            'superbaddonslibrary_service_version',
            'superbaddonslibrary_gutenberg_patterns',
            'superbaddonslibrary_gutenberg_pages',
            'superbaddonslibrary_elementor_sections',
            'superbaddons_errorlogs',
            'superbaddons_wizard_completed_themes',
            'superbaddons_wizard_navigation_post_id',
            'superbaddons_elementor_tour_id',
            'superbaddons_pre_activation',
            'superbaddons_css_block_target_valid_labels',
            // Form settings
            FormSettings::OPTION_HCAPTCHA_SITE_KEY,
            FormSettings::OPTION_HCAPTCHA_SECRET_KEY,
            FormSettings::OPTION_RECAPTCHA_SITE_KEY,
            FormSettings::OPTION_RECAPTCHA_SECRET_KEY,
            FormSettings::OPTION_TURNSTILE_SITE_KEY,
            FormSettings::OPTION_TURNSTILE_SECRET_KEY,
            FormSettings::OPTION_MAILCHIMP_API_KEY,
            FormSettings::OPTION_BREVO_API_KEY,
            FormSettings::OPTION_GOOGLE_SHEETS_CLIENT_EMAIL,
            FormSettings::OPTION_GOOGLE_SHEETS_PRIVATE_KEY,
            FormSettings::OPTION_WEBHOOK_SECRETS,
            'superbaddons_form_role_permissions',
            'superbaddons_form_access_control_enabled',
            'superbaddons_form_default_email',
            'superbaddons_form_data_retention',
            // Registries (delete AFTER iterating above)
            FormRegistry::OPTION_KEY,
            PopupRegistry::OPTION_KEY,
        );
        foreach ($option_keys as $option_key) {
            delete_option($option_key);
        }

        // 5. Delete transients.
        $transient_keys = array(
            'superbaddons_activation_redirect',
            'superbaddons_wizard_recommender_transient',
            'superbaddons_wizard_woocommerce_transient',
            'superb_blocks_template_restoration',
            'superbaddons_template_part_preview',
            FormGoogleAuth::TRANSIENT_KEY,
        );
        foreach ($transient_keys as $transient_key) {
            delete_transient($transient_key);
        }

        // 5b. Purge namespaced commerce transients (product/stock/search caches).
        // Keys look like: _transient_superb_commerce_product_123_1700000000 etc.
        // Direct query: no WP API exists for bulk-deleting transients by prefix; iterating get_option() to find them would itself bypass caching and is far slower. Pattern is a constant string with literal underscores escaped so they aren't treated as LIKE wildcards. Caching does not apply to a DELETE; this runs once during plugin reset.
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE '_transient_superb_commerce\\_%'
                    OR option_name LIKE '_transient_timeout_superb_commerce\\_%'"
            );
        }

        // 6. Delete user meta (site-wide).
        AdminNoticeController::Cleanup();
        NewsletterSignupController::Cleanup();
        delete_metadata('user', 0, TourController::TOUR_DASHBOARD_WELCOME_META, false, true);
        delete_metadata('user', 0, TourController::TOUR_BLOCK_THEME_META, false, true);
        delete_metadata('user', 0, GutenbergEnhancementsController::ENHANCEMENTS_OPTION, false, true);
        delete_metadata('user', 0, FavoritesController::FAVORITES_META_KEY, false, true);

        // 7. Optionally wipe every stored form submission.
        if ($include_submissions) {
            $submission_ids = get_posts(array(
                'post_type' => FormSubmissionCPT::POST_TYPE,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ));
            if (!empty($submission_ids)) {
                FormSubmissionHandler::BulkDelete($submission_ids);
            }
        }

        return true;
    }
}
