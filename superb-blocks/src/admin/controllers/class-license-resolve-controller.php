<?php

namespace SuperbAddons\Admin\Controllers;

defined('ABSPATH') || exit();

use Exception;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Controllers\LogController;

class LicenseResolveController
{
    const COOLDOWN_TRANSIENT = 'spbaddons_license_resolve_cooldown';
    const COOLDOWN_DURATION = DAY_IN_SECONDS;

    private static $attempted = false;

    /**
     * Hook into admin_init. Called once during plugin bootstrap.
     */
    public static function Initialize()
    {
        add_action('admin_init', array(__CLASS__, 'MaybeAutoResolve'));
    }

    /**
     * Attempt automatic license resolution if a registered key has an issue
     * and the cooldown period has elapsed.
     */
    public static function MaybeAutoResolve()
    {
        if (self::$attempted) {
            return;
        }
        self::$attempted = true;

        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        if (!KeyController::HasRegisteredKey()) {
            return;
        }
        if (KeyController::HasValidKey()) {
            return;
        }

        if (get_transient(self::COOLDOWN_TRANSIENT)) {
            return;
        }

        set_transient(self::COOLDOWN_TRANSIENT, 1, self::COOLDOWN_DURATION);

        try {
            KeyController::GetUpdatedLicenseKeyInformation();
            if (KeyController::HasValidKey()) {
                LogController::LogInfo('License issue resolved automatically');
            }
        } catch (Exception $ex) {
            LogController::HandleException($ex);
        }
    }

    /**
     * Clear the cooldown transient.
     * Called when a key is manually registered or removed.
     */
    public static function ClearCooldown()
    {
        delete_transient(self::COOLDOWN_TRANSIENT);
    }
}
