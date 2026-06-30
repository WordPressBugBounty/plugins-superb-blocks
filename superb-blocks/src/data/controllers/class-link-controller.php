<?php

namespace SuperbAddons\Data\Controllers;

defined('ABSPATH') || exit();

class LinkController
{
    // Two upsell modal presentations: modal-direct (the standard layout) and
    // modal-bonus (a compact layout with a lock icon and a discount callout).
    const VARIANT_GROUP = 'modal-v2';

    const VARIANT_MODAL_DIRECT = 'modal-direct';
    const VARIANT_MODAL_BONUS = 'modal-bonus';

    const SEED_OPTION = 'superbaddons_pre_activation';

    // Delayed admin notice
    // Bucketed independently
    const NOTICE_GROUP = 'notice-v1';
    const NOTICE_VARIANT_CONTROL = 'notice';
    const NOTICE_VARIANT_ADV = 'notice-adv';
    const NOTICE_SALT = 'notice';

    const NOTICE_FILE_CONTROL = 'addons-notice.php';
    const NOTICE_FILE_ADV = 'addons-notice-adv.php';

    private static $cached = null;
    private static $notice_variant = null;

    /**
     * @return array { active: bool, group: string, variant: string }
     */
    public static function GetState()
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        // active is unconditionally true for every install. The JS link builder
        // reads it to decide whether to append the su_exp/su_var params.
        self::$cached = array(
            'active' => true,
            'group' => self::VARIANT_GROUP,
            'variant' => self::ComputeBucket(),
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
     * @return array { active: bool, group: string, variant: string, settingsLicenseUrl: string }
     */
    public static function GetJsConfig()
    {
        $state = self::GetState();
        // Destination for the bonus modal's "Already purchased?" link: the
        // settings License & Account tab where users activate their key. Both
        // modal shells read it off this localized global.
        $state['settingsLicenseUrl'] = admin_url('admin.php?page=superbaddons-settings#license');
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

        // Salt the seed so the buckets are independent
        $bucket = abs(crc32(self::NOTICE_SALT . '|' . self::SeedValue())) % 2;
        self::$notice_variant = $bucket === 1 ? self::NOTICE_VARIANT_ADV : self::NOTICE_VARIANT_CONTROL;
        return self::$notice_variant;
    }

    public static function GetNoticeContentFile()
    {
        return self::GetNoticeVariant() === self::NOTICE_VARIANT_ADV
            ? self::NOTICE_FILE_ADV
            : self::NOTICE_FILE_CONTROL;
    }

    public static function GetNoticeExpArgs()
    {
        return array(
            'su_exp' => self::NOTICE_GROUP,
            'su_var' => self::GetNoticeVariant(),
        );
    }

    private static function ComputeBucket()
    {
        // Salted with the group name so this assignment is independent of the
        // other seed-derived groupings (the earlier unsalted hash and the
        // 'notice'-salted one).
        // abs() is required: crc32() returns a negative int on 32-bit PHP.
        $bucket = abs(crc32(self::VARIANT_GROUP . '|' . self::SeedValue())) % 2;
        return $bucket === 1 ? self::VARIANT_MODAL_BONUS : self::VARIANT_MODAL_DIRECT;
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
