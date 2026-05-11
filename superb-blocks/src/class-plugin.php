<?php

namespace SuperbAddons;

defined('ABSPATH') || exit();

use Exception;
use SuperbAddons\Elementor\Controllers\ElementorController;
use SuperbAddons\Admin\Controllers\DashboardController;
use SuperbAddons\Admin\Controllers\Wizard\WizardController;
use SuperbAddons\Admin\Controllers\Wizard\WizardRestorationPointController;
use SuperbAddons\Data\Controllers\CSSController;
use SuperbAddons\Data\Controllers\LogController;
use SuperbAddons\Data\Controllers\RestController;
use SuperbAddons\Gutenberg\Controllers\GutenbergController;
use SuperbAddons\Library\Controllers\FavoritesController;
use SuperbAddons\Library\Controllers\LibraryRequestController;
use SuperbAddons\Admin\Controllers\LicenseResolveController;
use SuperbAddons\Admin\Controllers\RewriteCheckController;
use SuperbAddons\Tours\Controllers\TourController;

class SuperbAddonsPlugin
{
    private static $instance;

    public static function GetInstance()
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct()
    {
        register_activation_hook(SUPERBADDONS_BASE_PATH, array($this, 'ActivationHookFunction'));
        register_deactivation_hook(SUPERBADDONS_BASE_PATH, array($this, 'DeactivationHookFunction'));
        RewriteCheckController::Initialize();
        LicenseResolveController::Initialize();
        new DashboardController();
        new GutenbergController();
        new ElementorController();
        new LibraryRequestController();
        new FavoritesController();
        new TourController();
        new CSSController();
        LogController::AddCronAction();
        RestController::RegisterRoutes();
    }

    public function ActivationHookFunction()
    {
        try {
            add_option('superbaddons_pre_activation', time(), "", false);
            set_transient('superbaddons_activation_redirect', true, 30);
            WizardController::MaybeSetWizardRecommenderTransient();
            RewriteCheckController::ScheduleCheck();
        } catch (Exception $e) {
            LogController::HandleException($e);
        }
    }

    public function DeactivationHookFunction()
    {
        try {
            LogController::MaybeUnsubscribeCron();
            WizardRestorationPointController::MaybeUnsubscribeCron();
        } catch (Exception $e) {
            // Make sure deactivation succeeds
            LogController::HandleException($e);
        }
    }
}
