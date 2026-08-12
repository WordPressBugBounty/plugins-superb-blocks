<?php

namespace SuperbAddons\Data\Controllers;

use SuperbAddons\Admin\Utils\AdminLinkSource;

defined('ABSPATH') || exit();

class LinkController
{
    // Upsell modal presentation buckets: two independent toggles on the rich
    // modal layout, reported together as one variant string.
    // - bonus: a discount line rendered under the modal footer.
    // - cta: the modal CTA label wording.
    const VARIANT_GROUP = 'modal-v3';
    const BONUS_SALT = 'modal-v3-bonus';
    const CTA_SALT = 'modal-v3-cta';

    const SEED_OPTION = 'superbaddons_pre_activation';

    // Delayed admin notice content buckets
    // Bucketed independently
    const NOTICE_GROUP = 'notice-v2';
    const NOTICE_SALT = 'notice-v2';
    const NOTICE_VARIANT_DISCOUNT = 'discount';
    const NOTICE_VARIANT_BENEFITS = 'benefits';

    const NOTICE_FILE_DISCOUNT = 'addons-notice.php';
    const NOTICE_FILE_BENEFITS = 'addons-notice-benefits.php';

    // Admin navigation CTA buckets: direct link vs opening the upsell modal
    // Bucketed independently
    const NAV_GROUP = 'nav-v1';
    const NAV_SALT = 'nav-v1';
    const NAV_VARIANT_DIRECT = 'nav-direct';
    const NAV_VARIANT_MODAL = 'nav-modal';

    private static $cached = null;
    private static $notice_variant = null;
    private static $nav_variant = null;

    /**
     * @return array { active: bool, group: string, variant: string, bonusLine: bool, ctaAll: bool }
     */
    public static function GetState()
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $bonus_line = self::Bucket(self::BONUS_SALT) === 1;
        $cta_all = self::Bucket(self::CTA_SALT) === 1;

        // active is unconditionally true for every install. The JS link builder
        // reads it to decide whether to append the su_exp/su_var params.
        self::$cached = array(
            'active' => true,
            'group' => self::VARIANT_GROUP,
            'variant' => ($bonus_line ? 'bonus' : 'plain') . '-' . ($cta_all ? 'all' : 'prem'),
            'bonusLine' => $bonus_line,
            'ctaAll' => $cta_all,
        );
        return self::$cached;
    }

    public static function GetVariant()
    {
        $state = self::GetState();
        return $state['variant'];
    }

    public static function GetLinkExpArgs($experiment = 'upsell')
    {
        if ($experiment === 'notice') {
            return self::GetNoticeExpArgs();
        }
        if ($experiment === 'nav') {
            return self::GetNavExpArgs();
        }

        $state = self::GetState();
        if (empty($state['active'])) {
            return array();
        }
        return array(
            'su_exp' => $state['group'],
            'su_var' => $state['variant'],
        );
    }

    /**
     * @return array { active, group, variant, bonusLine, ctaAll, sourceExperiments }
     */
    public static function GetJsConfig()
    {
        $state = self::GetState();
        // Links built for these sources report their own group/variant instead
        // of the modal group, so each surface's clicks stay attributable to the
        // surface they came from.
        $state['sourceExperiments'] = array(
            AdminLinkSource::NAVIGATION_CTA => array(
                'group' => self::NAV_GROUP,
                'variant' => self::GetNavVariant(),
            ),
        );
        return $state;
    }

    public static function Localize($handle)
    {
        wp_localize_script($handle, 'superbAddonsUpsell', self::GetJsConfig());
    }

    public static function GetNoticeVariant()
    {
        if (self::$notice_variant !== null) {
            return self::$notice_variant;
        }

        self::$notice_variant = self::Bucket(self::NOTICE_SALT) === 1
            ? self::NOTICE_VARIANT_BENEFITS
            : self::NOTICE_VARIANT_DISCOUNT;
        return self::$notice_variant;
    }

    public static function GetNoticeContentFile()
    {
        return self::GetNoticeVariant() === self::NOTICE_VARIANT_BENEFITS
            ? self::NOTICE_FILE_BENEFITS
            : self::NOTICE_FILE_DISCOUNT;
    }

    public static function GetNoticeExpArgs()
    {
        return array(
            'su_exp' => self::NOTICE_GROUP,
            'su_var' => self::GetNoticeVariant(),
        );
    }

    public static function GetNavVariant()
    {
        if (self::$nav_variant !== null) {
            return self::$nav_variant;
        }

        self::$nav_variant = self::Bucket(self::NAV_SALT) === 1
            ? self::NAV_VARIANT_MODAL
            : self::NAV_VARIANT_DIRECT;
        return self::$nav_variant;
    }

    public static function NavOpensModal()
    {
        return self::GetNavVariant() === self::NAV_VARIANT_MODAL;
    }

    public static function GetNavExpArgs()
    {
        return array(
            'su_exp' => self::NAV_GROUP,
            'su_var' => self::GetNavVariant(),
        );
    }

    // Salted per bucket so each assignment is independent of the other
    // seed-derived groupings (including earlier salts on the same seed).
    // abs() is required: crc32() returns a negative int on 32-bit PHP.
    private static function Bucket($salt)
    {
        return abs(crc32($salt . '|' . self::SeedValue())) % 2;
    }

    private static function SeedValue()
    {
        $seed = get_option(self::SEED_OPTION, '');
        // The seed option can be missing (e.g. removed by a full plugin reset).
        // Fall back to a stable per-site value rather than writing on a read
        // path or lumping every seedless install into one bucket.
        if ($seed === '' || $seed === false) {
            $seed = home_url();
        }
        return (string) $seed;
    }
}
