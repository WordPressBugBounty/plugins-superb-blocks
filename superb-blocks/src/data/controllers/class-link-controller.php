<?php

namespace SuperbAddons\Data\Controllers;

defined('ABSPATH') || exit();

class LinkController
{
    // Presentation buckets: premium touchpoints either open the modal or
    // link straight to the landing page.
    const VARIANT_GROUP = 'modal-v5';
    const VARIANT_SALT = 'modal-v5';
    const VARIANT_MODAL = 'modal';
    const VARIANT_LINKS = 'links';

    const SEED_OPTION = 'superbaddons_pre_activation';

    private static $cached = null;

    /**
     * @return array { active: bool, group: string, variant: string, showModal: bool }
     */
    public static function GetState()
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $show_modal = self::Bucket(self::VARIANT_SALT) === 1;

        // active is unconditionally true for every install. The JS link builder
        // reads it to decide whether to append the su_exp/su_var params.
        self::$cached = array(
            'active' => true,
            'group' => self::VARIANT_GROUP,
            'variant' => $show_modal ? self::VARIANT_MODAL : self::VARIANT_LINKS,
            'showModal' => $show_modal,
        );
        return self::$cached;
    }

    public static function GetVariant()
    {
        $state = self::GetState();
        return $state['variant'];
    }

    public static function GetLinkExpArgs()
    {
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
     * @return array { active, group, variant, showModal }
     */
    public static function GetJsConfig()
    {
        return self::GetState();
    }

    public static function Localize($handle)
    {
        wp_localize_script($handle, 'superbAddonsUpsell', self::GetJsConfig());
    }

    // Salted per bucket so each result is independent of the others
    // (including earlier salts on the same seed).
    // md5 rather than crc32: crc32 is linear, so with a fixed-length seed (the
    // install timestamp) the parities of two prefix-salted crc32 values differ
    // by a constant and every bucket ends up identical.
    // Four hex chars keep hexdec() inside int range on 32-bit PHP.
    private static function Bucket($salt)
    {
        return hexdec(substr(md5($salt . '|' . self::SeedValue()), 0, 4)) % 2;
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
