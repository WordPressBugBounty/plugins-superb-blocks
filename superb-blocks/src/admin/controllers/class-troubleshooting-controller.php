<?php

namespace SuperbAddons\Admin\Controllers;

use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\CacheController;
use SuperbAddons\Data\Controllers\DomainShiftController;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Controllers\RestController;
use SuperbAddons\Data\Utils\CacheOptions;
use SuperbAddons\Data\Utils\ElementorCache;
use SuperbAddons\Data\Utils\KeyException;
use SuperbAddons\Data\Utils\KeyType;
use SuperbAddons\Tours\Controllers\TourController;

defined('ABSPATH') || exit();

class TroubleshootingController
{
    const TROUBLESHOOTING_ROUTE = '/troubleshooting';
    const TUTORIAL_ROUTE = '/tutorial';

    const ENDPOINT_BASE = 'addons-status/';

    public function __construct()
    {
        RestController::AddRoute(self::TROUBLESHOOTING_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'TroubleshootingCallbackPermissionCheck'),
            'callback' => array($this, 'TroubleshootingRouteCallback'),
        ));
        RestController::AddRoute(self::TUTORIAL_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'TutorialCallbackPermissionCheck'),
            'callback' => array($this, 'TutorialRouteCallback'),
        ));
    }

    public function TroubleshootingCallbackPermissionCheck()
    {
        // Restrict endpoint to only users who have the proper capability.
        if (!current_user_can(Capabilities::ADMIN)) {
            return new WP_Error('rest_forbidden', esc_html__('Unauthorized. Please check user permissions.', "superb-blocks"), array('status' => 401));
        }

        return true;
    }

    public function TutorialCallbackPermissionCheck()
    {
        // Restrict endpoint to only users who have the proper capability.
        if (!current_user_can(Capabilities::CONTRIBUTOR)) {
            return new WP_Error('rest_forbidden', esc_html__('Unauthorized. Please check user permissions.', "superb-blocks"), array('status' => 401));
        }

        return true;
    }

    public function TutorialRouteCallback($request)
    {
        if (!isset($request['action'])) {
            return new \WP_Error('bad_request_plugin', 'Bad Plugin Request', array('status' => 400));
        }
        try {
            switch ($request['action']) {
                case 'elementor-tour':
                    $url = TourController::GetElementorTourURL();
                    return rest_ensure_response(['success' => true, 'url' => esc_url_raw($url)]);
                case 'cleanup-elementor-tour-page':
                    $removed = TourController::CleanUpTourPage($request['tour-nonce']);
                    return rest_ensure_response(['success' => $removed]);
                case 'mark-tour-complete':
                    $tour = isset($request['tour']) ? sanitize_text_field($request['tour']) : '';
                    $allowed = array(TourController::TOUR_DASHBOARD_WELCOME_META, TourController::TOUR_BLOCK_THEME_META);
                    if (!in_array($tour, $allowed, true)) {
                        return new \WP_Error('bad_request_plugin', 'Invalid tour', array('status' => 400));
                    }
                    TourController::MarkTourCompleted($tour);
                    return rest_ensure_response(array('success' => true));
                default:
                    return new \WP_Error('bad_request_plugin', 'Bad Plugin Request', array('status' => 400));
            }
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    public function TroubleshootingRouteCallback($request)
    {
        if (!isset($request['action'])) {
            return new \WP_Error('bad_request_plugin', 'Bad Plugin Request', array('status' => 400));
        }
        switch ($request['action']) {
            case 'restcheck':
                return $this->RestCheckCallback();
            case 'restfix':
                return $this->RestFixCallback();
            case 'connection':
                return $this->ConnectionCheckCallback();
            case 'domainshift':
                return $this->DomainShiftCallback();
            case 'service':
                return $this->ServiceCheckCallback();
            case 'keycheck':
                return $this->KeyCheckCallback();
            case 'keyverify':
                return $this->KeyVerifyCallback();
            case 'cacheclear':
                return $this->CacheClearCallback();
            default:
                return new \WP_Error('bad_request_plugin', 'Bad Plugin Request', array('status' => 400));
        }
    }

    private function RestCheckCallback()
    {
        try {
            // If we reached this endpoint, the REST API is at least partially working.
            // Check if rewrite rules are properly configured.
            $permalink_structure = get_option('permalink_structure');
            if (empty($permalink_structure)) {
                // Plain permalinks — REST API uses ?rest_route= which always works.
                return rest_ensure_response(array('success' => true));
            }

            $rules = get_option('rewrite_rules');
            if (empty($rules) || !is_array($rules)) {
                return rest_ensure_response(array(
                    'success' => false,
                    'requiresConsent' => true,
                    'text' => esc_html__('WordPress rewrite rules are not configured', 'superb-blocks'),
                ));
            }

            $rest_prefix = rest_get_url_prefix();
            foreach ($rules as $pattern => $query) {
                if (strpos($pattern, $rest_prefix) !== false) {
                    return rest_ensure_response(array('success' => true));
                }
            }

            return rest_ensure_response(array(
                'success' => false,
                'requiresConsent' => true,
                'text' => esc_html__('REST API rewrite rules are missing', 'superb-blocks'),
            ));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function RestFixCallback()
    {
        try {
            flush_rewrite_rules();

            // Verify the fix worked
            $rules = get_option('rewrite_rules');
            if (!empty($rules) && is_array($rules)) {
                $rest_prefix = rest_get_url_prefix();
                foreach ($rules as $pattern => $query) {
                    if (strpos($pattern, $rest_prefix) !== false) {
                        RewriteCheckController::ClearIssue();
                        return rest_ensure_response(array('success' => true));
                    }
                }
            }

            return rest_ensure_response(array('success' => false));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function ConnectionCheckCallback()
    {
        try {
            $is_connected = DomainShiftController::GetCurrentConnectionSuccess();

            return rest_ensure_response(['success' => $is_connected]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function DomainShiftCallback()
    {
        try {
            $successful_shift = DomainShiftController::FindPreferredAPIDomain();

            return rest_ensure_response(['success' => $successful_shift]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function ServiceCheckCallback()
    {
        try {
            $status_arr = DomainShiftController::GetServiceStatus();
            if ($status_arr['online']) {
                return rest_ensure_response(['success' => true]);
            }

            return rest_ensure_response(['success' => false, "text" => esc_html($status_arr['message'])]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function KeyCheckCallback()
    {
        try {
            $keytype_label = KeyController::GetCurrentKeyTypeLabel();
            if (!KeyController::HasRegisteredKey()) {
                // No need to check for key status if no key is registered.
                return rest_ensure_response(['success' => true, "text" => esc_html($keytype_label)]);
            }

            $keyinfo = KeyController::GetKeyStatus();

            if ($keyinfo['expired']) {
                return rest_ensure_response(['success' => false, "ignoreResolver" => false, "text" => esc_html__('Subscription Expired', "superb-blocks")]);
            }

            if (!$keyinfo['active']) {
                return rest_ensure_response(['success' => false, "ignoreResolver" => false, "text" => esc_html__('License Key Disabled. Please contact our support team for assistance.', "superb-blocks")]);
            }

            if (!$keyinfo['verified']) {
                return rest_ensure_response(['success' => false, "text" => esc_html__('License Key Verification Invalid', "superb-blocks")]);
            }

            if ($keyinfo['exceeded']) {
                return rest_ensure_response(['success' => false, "ignoreResolver" => false, "text" => esc_html__('License Key active on too many domains. Please contact our support team for assistance.', "superb-blocks")]);
            }

            return rest_ensure_response(['success' => true, "text" => esc_html($keytype_label)]);
        } catch (KeyException $k_ex) {
            return rest_ensure_response(['success' => false, "text" => esc_html($k_ex->getMessage())]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }


    private function KeyVerifyCallback()
    {
        try {
            $keyinfo = KeyController::GetUpdatedLicenseKeyInformation();
            if (($keyinfo['expired'] && $keyinfo['type'] !== KeyType::STANDARD) || !$keyinfo['active'] || !$keyinfo['verified'] || $keyinfo['exceeded']) {
                if ($keyinfo['expired'] && $keyinfo['type'] !== KeyType::STANDARD) {
                    return rest_ensure_response(['success' => false, "text" => esc_html__('License Subscription Expired', "superb-blocks")]);
                }

                if ($keyinfo['exceeded']) {
                    if ($keyinfo['expired']) {
                        return rest_ensure_response(['success' => false, "text" => esc_html__('License Key active on too many domains. Please renew your subscription, deactivate your license key on some of your domains, or contact our support team for assistance.', "superb-blocks")]);
                    }
                    return rest_ensure_response(['success' => false, "text" => esc_html__('License Key active on too many domains. Please contact our support team for assistance.', "superb-blocks")]);
                }

                return rest_ensure_response(['success' => false]);
            }
            $keytype_label = KeyController::GetKeyTypeLabel($keyinfo['type']);
            return rest_ensure_response(['success' => true, "text" => esc_html($keytype_label . ' ' . __('Verified', "superb-blocks"))]);
        } catch (KeyException $k_ex) {
            return rest_ensure_response(['success' => false, "text" => esc_html($k_ex->getMessage())]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function CacheClearCallback()
    {
        try {
            $cleared = CacheController::ClearCache(CacheOptions::SERVICE_VERSION) && CacheController::ClearCache(ElementorCache::SECTIONS);

            return rest_ensure_response(['success' => $cleared]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }
}
