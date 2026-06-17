<?php

namespace SuperbAddons\Admin\Controllers;

defined('ABSPATH') || exit();

use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Utils\Engagement;

/**
 *  - A dismissible admin notice
 *  - A "rate us" star line in the admin footer of Superb Addons
 *    screens.
 */
class ReviewController
{
    const MIN_AGE_DAYS = 14;

    public function __construct()
    {
        add_filter('admin_footer_text', array($this, 'AdminFooterText'), 100);
    }

    /**
     * Direct link to leave a review on WordPress.org.
     *
     * @return string
     */
    public static function GetReviewUrl()
    {
        return add_query_arg(
            array('filter' => 5),
            'https://wordpress.org/support/plugin/superb-blocks/reviews/#new-post'
        );
    }

    /**
     * Whether the review notice is eligible to register for the current user.
     *
     * @return bool
     */
    public static function ShouldShowReviewNotice()
    {
        // Only ask real administrators (the person who would leave a review).
        if (!current_user_can(Capabilities::ADMIN)) {
            return false;
        }

        // Site must have been installed for at least MIN_AGE_DAYS days.
        if ((self::GetInstallTimestamp() + (self::MIN_AGE_DAYS * DAY_IN_SECONDS)) > time()) {
            return false;
        }

        // Site must have actually used a feature.
        if (!Engagement::HasEngaged()) {
            return false;
        }

        // Free users: wait until the notice has been dismissed so we
        // never stack two of our notices.
        if (!KeyController::HasValidPremiumKey() && !self::UpsellNoticeDismissed()) {
            return false;
        }

        return true;
    }

    private static function GetInstallTimestamp()
    {
        $pre_activation = get_option('superbaddons_pre_activation');
        if (!$pre_activation) {
            $pre_activation = time();
            add_option('superbaddons_pre_activation', $pre_activation, '', false);
        }
        return intval($pre_activation);
    }

    /**
     * Whether the current user has dismissed the free-user notice.
     *
     * @return bool
     */
    private static function UpsellNoticeDismissed()
    {
        return (bool) get_user_meta(
            get_current_user_id(),
            AdminNoticeController::PREFIX . DashboardController::NOTICE_ID_UPSELL,
            true
        );
    }

    /**
     * Persistent admin footer "rate us" line on Superb Addons screens.
     *
     * @param string|mixed $text
     * @return string
     */
    public function AdminFooterText($text)
    {
        if (!self::IsSuperbAdminScreen()) {
            return is_string($text) ? $text : '';
        }

        $url = esc_url(self::GetReviewUrl());

        return sprintf(
            wp_kses(
                /* translators: 1: plugin name, 2: review link (stars), 3: review link (WordPress.org). */
                __('Please rate %1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">&#9733;&#9733;&#9733;&#9733;&#9733;</a> on <a href="%3$s" target="_blank" rel="noopener noreferrer">WordPress.org</a> to help us spread the word.', 'superb-blocks'),
                array(
                    'a' => array(
                        'href' => array(),
                        'target' => array(),
                        'rel' => array(),
                    ),
                )
            ),
            '<strong>Superb Addons</strong>',
            $url,
            $url
        );
    }

    /**
     * Whether the current admin screen belongs to Superb Addons.
     *
     * @return bool
     */
    private static function IsSuperbAdminScreen()
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }
        $screen = get_current_screen();
        return $screen && !empty($screen->id) && strpos($screen->id, 'superbaddons') !== false;
    }
}
