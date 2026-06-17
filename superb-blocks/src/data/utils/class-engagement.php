<?php

namespace SuperbAddons\Data\Utils;

defined('ABSPATH') || exit();

/**
 * Centralized, per-site engagement marker.
 *
 * Records the first time each meaningful "used the product" action occurs so
 * features like the review prompt can gate on whether the site has actually
 * engaged with the plugin. Each feature is written at most once (the first time
 * it is used), so repeat calls cost a single cached option read and never write.
 */
class Engagement
{
    const OPTION_KEY = 'superbaddons_engagement';

    const FEATURE_PATTERN = 'pattern';
    const FEATURE_FORM = 'form';
    const FEATURE_POPUP = 'popup';
    const FEATURE_DESIGNER = 'designer';
    const FEATURE_CSS = 'css';
    const FEATURE_ENHANCEMENT = 'enhancement';

    /**
     * Record that a feature has been used. Writes only on the first use of each
     * feature, so subsequent calls are a no-op after a single option read.
     *
     * @param string $feature One of the FEATURE_* constants.
     * @return void
     */
    public static function MarkUsed($feature)
    {
        if (empty($feature)) {
            return;
        }

        $engagement = get_option(self::OPTION_KEY, array());
        if (!is_array($engagement)) {
            $engagement = array();
        }

        // Already recorded — nothing to write.
        if (isset($engagement[$feature])) {
            return;
        }

        $engagement[$feature] = time();
        update_option(self::OPTION_KEY, $engagement, false);
    }

    /**
     * Whether the site has engaged with at least one plugin feature.
     *
     * @return bool
     */
    public static function HasEngaged()
    {
        $engagement = get_option(self::OPTION_KEY, array());
        return is_array($engagement) && !empty($engagement);
    }
}
