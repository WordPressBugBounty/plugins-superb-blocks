<?php

namespace SuperbAddons\Admin\Controllers;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Controllers\PluginResetController;
use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\CacheController;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Controllers\OptionController;
use SuperbAddons\Data\Controllers\RestController;
use SuperbAddons\Data\Controllers\SettingsOptionKey;
use Exception;
use SuperbAddons\Admin\Controllers\Wizard\WizardRestorationPointController;
use SuperbAddons\Data\Controllers\CompatibilitySettingsOptionKey;
use SuperbAddons\Data\Controllers\LogController;
use SuperbAddons\Data\Utils\KeyException;
use SuperbAddons\Data\Utils\SettingsException;
use SuperbAddons\Gutenberg\Controllers\GutenbergController;
use SuperbAddons\Gutenberg\Controllers\GutenbergEnhancementsController;
use SuperbAddons\Gutenberg\Form\FormAccessControl;
use SuperbAddons\Gutenberg\Form\FormIntegrationHandler;
use SuperbAddons\Gutenberg\Form\FormPermissions;
use SuperbAddons\Gutenberg\Form\FormSettings;

class SettingsController
{
    const SETTINGS_ROUTE = '/settings';

    public function __construct()
    {
        RestController::AddRoute(self::SETTINGS_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array($this, 'SettingsCallbackPermissionCheck'),
            'callback' => array($this, 'SettingsRouteCallback'),
        ));
    }

    public function SettingsCallbackPermissionCheck()
    {
        // Restrict endpoint to only users who have the proper capability.
        if (!current_user_can(Capabilities::ADMIN)) {
            return new \WP_Error('rest_forbidden', esc_html__('Unauthorized. Please check user permissions.', "superb-blocks"), array('status' => 401));
        }

        return true;
    }

    public function SettingsRouteCallback($request)
    {
        if (!isset($request['action'])) {
            return new \WP_Error('bad_request_plugin', 'Bad Plugin Request', array('status' => 400));
        }
        switch ($request['action']) {
            case 'submit_feedback':
                return $this->SubmitFeedbackCallback($request);
            case 'addkey':
                return $this->RegisterKeyCallback($request);
            case 'removekey':
                return $this->RemoveKeyCallback();
            case SettingsOptionKey::LOGS_ENABLED:
            case SettingsOptionKey::LOG_SHARE_ENABLED:
            case 'clear_cache':
            case 'clear_logs':
            case 'view_logs':
            case 'clear_restoration_points':
                return $this->SaveSettingsCallback($request['action']);
            case GutenbergEnhancementsController::HIGHLIGHTS_KEY:
            case GutenbergEnhancementsController::HIGHLIGHTS_QUICKOPTIONS_KEY:
            case GutenbergEnhancementsController::HIGHLIGHTS_QUICKOPTIONS_BOTTOM_KEY:
            case GutenbergEnhancementsController::RESPONSIVE_KEY:
            case GutenbergEnhancementsController::ANIMATIONS_KEY:
            case GutenbergEnhancementsController::CONDITIONS_KEY:
            case GutenbergEnhancementsController::DYNAMIC_CONTENT_KEY:
            case GutenbergEnhancementsController::NAVIGATION_KEY:
            case GutenbergEnhancementsController::RICHTEXT_KEY:
            case GutenbergEnhancementsController::SOCIAL_ICONS_KEY:
            case GutenbergEnhancementsController::DASHBOARD_SHORTCUTS_KEY:
            case GutenbergEnhancementsController::STICKY_KEY:
            case GutenbergEnhancementsController::Z_INDEX_KEY:
            case GutenbergEnhancementsController::PANEL_DEFAULT_STATE_KEY:
                return GutenbergEnhancementsController::OptionsSaveCallback($request);
            case CompatibilitySettingsOptionKey::SPECTRA_BLOCK_SPACING:
                return $this->SaveCompatibilitySettingsCallback($request['action']);
            case 'save_integration_key':
                return $this->SaveIntegrationKeyCallback($request);
            case 'remove_integration_key':
                return $this->RemoveIntegrationKeyCallback($request);
            case 'toggle_block':
                return $this->ToggleBlockCallback($request);
            case 'save_form_permissions':
                return $this->SaveFormPermissionsCallback($request);
            case 'save_default_email':
                return $this->SaveDefaultEmailCallback($request);
            case 'save_data_retention':
                return $this->SaveDataRetentionCallback($request);
            case 'save_captcha_key':
                return $this->SaveCaptchaKeyCallback($request);
            case 'remove_captcha_key':
                return $this->RemoveCaptchaKeyCallback($request);
            case 'get_integration_usage':
                return $this->GetIntegrationUsageCallback($request);
            case 'remove_all_data':
                return $this->RemoveAllDataCallback($request);
            default:
                return new \WP_Error('bad_request_plugin', 'Bad Plugin Request', array('status' => 400));
        }
    }

    private function SubmitFeedbackCallback($request)
    {
        try {
            if (!isset($request['spbaddons_reason']) || empty($request['spbaddons_reason'])) throw new SettingsException(__('Unable to send feedback. No feedback provided.', "superb-blocks"));

            if ($request['spbaddons_reason'] === 'other' && isset($request['spbaddons_other'])) {
                $message = sanitize_text_field(wp_unslash($request['spbaddons_other']));
            } else {
                $message = sanitize_text_field(wp_unslash($request['spbaddons_reason']));
            }
            LogController::SendFeedback($message);

            return rest_ensure_response(['success' => true]);
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(['success' => false, "text" => esc_html($s_ex->getMessage())]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function RegisterKeyCallback($request)
    {
        try {
            KeyController::RegisterKey($request['key'], true);
            return rest_ensure_response(['success' => true]);
        } catch (KeyException $k_ex) {
            return rest_ensure_response(['success' => false, "text" => esc_html($k_ex->getMessage())]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function RemoveKeyCallback()
    {
        try {
            $removed = KeyController::RemoveKey();
            return rest_ensure_response(['success' => $removed]);
        } catch (KeyException $k_ex) {
            return rest_ensure_response(['success' => false, "text" => esc_html($k_ex->getMessage())]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    public static function GetSettings()
    {
        return OptionController::GetSettings();
    }

    public static function GetCompatibilitySettings()
    {
        return OptionController::GetCompatibilitySettings();
    }

    private function SaveSettingsCallback($action)
    {
        try {
            $option_controller = new OptionController();
            $current_settings = OptionController::GetSettings();

            switch ($action) {
                case SettingsOptionKey::LOGS_ENABLED:
                    $current_settings[SettingsOptionKey::LOGS_ENABLED] = !$current_settings[SettingsOptionKey::LOGS_ENABLED];
                    $option_controller->SaveSettings($current_settings);
                    break;
                case SettingsOptionKey::LOG_SHARE_ENABLED:
                    $current_settings[SettingsOptionKey::LOG_SHARE_ENABLED] = !$current_settings[SettingsOptionKey::LOG_SHARE_ENABLED];
                    $saved = $option_controller->SaveSettings($current_settings);
                    if ($saved) {
                        $current_settings[SettingsOptionKey::LOG_SHARE_ENABLED] ? LogController::MaybeSubscribeCron() : LogController::MaybeUnsubscribeCron();
                    }
                    break;
                case 'clear_cache':
                    $cleared = CacheController::ClearCacheAll();
                    if (!$cleared) throw new SettingsException(__('Cache could not be cleared.', "superb-blocks"));
                    break;
                case 'clear_logs':
                    $cleared = LogController::ClearLogs();
                    if (!$cleared) throw new SettingsException(__('Logs could not be cleared.', "superb-blocks"));
                    break;
                case 'view_logs':
                    return rest_ensure_response(['success' => true, 'content' => LogController::GetLogs()]);
                case 'clear_restoration_points':
                    $cleared = WizardRestorationPointController::FullRestorationCleanup();
                    if (!$cleared) throw new SettingsException(__('Restoration points could not be cleared.', "superb-blocks"));
                    break;
            }

            return rest_ensure_response(['success' => true]);
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(['success' => false, "text" => esc_html($s_ex->getMessage())]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function SaveCompatibilitySettingsCallback($action)
    {
        try {
            $option_controller = new OptionController();
            $current_settings = OptionController::GetCompatibilitySettings();

            switch ($action) {
                case CompatibilitySettingsOptionKey::SPECTRA_BLOCK_SPACING:
                    $current_settings[CompatibilitySettingsOptionKey::SPECTRA_BLOCK_SPACING] = !$current_settings[CompatibilitySettingsOptionKey::SPECTRA_BLOCK_SPACING];
                    $option_controller->SaveCompatibilitySettings($current_settings);
                    break;
            }

            return rest_ensure_response(['success' => true]);
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(['success' => false, "text" => esc_html($s_ex->getMessage())]);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private static function GetIntegrationOptionKey($integration)
    {
        $map = array(
            'mailchimp' => FormSettings::OPTION_MAILCHIMP_API_KEY,
            'brevo' => FormSettings::OPTION_BREVO_API_KEY,
        );
        return isset($map[$integration]) ? $map[$integration] : false;
    }

    private function SaveIntegrationKeyCallback($request)
    {
        try {
            $integration = isset($request['integration']) ? sanitize_text_field(wp_unslash($request['integration'])) : '';
            $api_key = isset($request['api_key']) ? wp_unslash($request['api_key']) : '';

            // Google Sheets uses a JSON Service Account key
            if ($integration === 'google_sheets') {
                return $this->SaveGoogleSheetsKeyCallback($api_key);
            }

            $api_key = sanitize_text_field($api_key);
            $option_key = self::GetIntegrationOptionKey($integration);
            if (!$option_key) {
                throw new SettingsException(__('Invalid integration.', "superb-blocks"));
            }
            if (empty($api_key)) {
                throw new SettingsException(__('API key cannot be empty.', "superb-blocks"));
            }

            // Save the key so the integration handler can use it for validation.
            FormSettings::Set($option_key, $api_key);

            // Validate the key by attempting to fetch lists from the service.
            if ($integration === 'mailchimp') {
                $result = FormIntegrationHandler::GetMailchimpLists();
            } else {
                $result = FormIntegrationHandler::GetBrevoLists();
            }

            if (is_wp_error($result)) {
                // Validation failed — remove the key and return the error.
                FormSettings::Remove($option_key);
                throw new SettingsException($result->get_error_message());
            }

            return rest_ensure_response(array(
                'success' => true,
                'masked_key' => FormSettings::GetMasked($option_key),
            ));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function SaveGoogleSheetsKeyCallback($json_string)
    {
        try {
            if (empty($json_string)) {
                throw new SettingsException(__('Service Account JSON key cannot be empty.', 'superb-blocks'));
            }

            $json = json_decode($json_string, true);
            if (!is_array($json)) {
                throw new SettingsException(__('Invalid JSON format. Please paste the entire contents of your Service Account JSON key.', 'superb-blocks'));
            }

            $client_email = isset($json['client_email']) ? sanitize_email($json['client_email']) : '';
            $private_key = isset($json['private_key']) ? $json['private_key'] : '';

            if (empty($client_email) || empty($private_key)) {
                throw new SettingsException(__('The JSON key must contain "client_email" and "private_key" fields.', 'superb-blocks'));
            }

            // Validate email format
            if (!is_email($client_email)) {
                throw new SettingsException(__('Invalid client_email in the Service Account key.', 'superb-blocks'));
            }

            // Store the extracted values
            FormSettings::Set(FormSettings::OPTION_GOOGLE_SHEETS_CLIENT_EMAIL, $client_email);
            FormSettings::Set(FormSettings::OPTION_GOOGLE_SHEETS_PRIVATE_KEY, $private_key);

            return rest_ensure_response(array(
                'success' => true,
                'masked_key' => $client_email,
            ));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function RemoveIntegrationKeyCallback($request)
    {
        try {
            $integration = isset($request['integration']) ? sanitize_text_field(wp_unslash($request['integration'])) : '';

            // Google Sheets has two option keys
            if ($integration === 'google_sheets') {
                FormSettings::Remove(FormSettings::OPTION_GOOGLE_SHEETS_CLIENT_EMAIL);
                FormSettings::Remove(FormSettings::OPTION_GOOGLE_SHEETS_PRIVATE_KEY);
                delete_transient('spb_google_token');
                return rest_ensure_response(array('success' => true));
            }

            $option_key = self::GetIntegrationOptionKey($integration);
            if (!$option_key) {
                throw new SettingsException(__('Invalid integration.', "superb-blocks"));
            }

            FormSettings::Remove($option_key);

            return rest_ensure_response(array('success' => true));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    public static function GetRelevantCompatibilitySettings()
    {
        $relevant_settings = array();
        // Check if Spectra is active
        if (class_exists('UAGB_Loader')) {
            $relevant_settings[CompatibilitySettingsOptionKey::SPECTRA_BLOCK_SPACING] = true;
        }

        return $relevant_settings;
    }

    public static function IsCompatibilitySettingRelevantAndEnabled($compatibility_setting)
    {
        $relevant_settings = self::GetRelevantCompatibilitySettings();
        if (!isset($relevant_settings[$compatibility_setting])) return false;

        $compatibility_settings = self::GetCompatibilitySettings();
        return $compatibility_settings[$compatibility_setting];
    }

    private function ToggleBlockCallback($request)
    {
        try {
            if (!isset($request['block'])) {
                throw new SettingsException(__('No block specified.', 'superb-blocks'));
            }

            $block_slug = sanitize_text_field($request['block']);

            // Validate the block is in the toggleable list
            if (!in_array($block_slug, GutenbergController::TOGGLEABLE_BLOCKS, true)) {
                throw new SettingsException(__('Invalid block.', 'superb-blocks'));
            }

            $disabled = OptionController::GetDisabledBlocks();
            $key = array_search($block_slug, $disabled, true);
            if ($key !== false) {
                // Currently disabled, re-enable it
                unset($disabled[$key]);
                $disabled = array_values($disabled);
            } else {
                // Currently enabled, disable it
                $disabled[] = $block_slug;
            }

            OptionController::SaveDisabledBlocks($disabled);

            return rest_ensure_response(array('success' => true));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function SaveFormPermissionsCallback($request)
    {
        try {
            $raw = isset($request['permissions']) ? $request['permissions'] : '';
            $permissions = is_string($raw) ? json_decode(wp_unslash($raw), true) : $raw;
            if (!is_array($permissions)) {
                throw new SettingsException(__('Invalid permissions data.', 'superb-blocks'));
            }

            FormPermissions::SaveAll($permissions);

            // Save access control toggle
            $access_control_enabled = isset($request['access_control_enabled']) && $request['access_control_enabled'] === '1';
            update_option(FormAccessControl::OPTION_ENABLED, $access_control_enabled ? '1' : '', false);

            return rest_ensure_response(array('success' => true));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function SaveDefaultEmailCallback($request)
    {
        try {
            $from_name = isset($request['from_name']) ? sanitize_text_field(wp_unslash($request['from_name'])) : '';
            $from_email = isset($request['from_email']) ? sanitize_email(wp_unslash($request['from_email'])) : '';

            // Validate email if provided
            if ($from_email !== '' && !is_email($from_email)) {
                throw new SettingsException(__('Please enter a valid email address.', 'superb-blocks'));
            }

            update_option('superbaddons_form_default_email', array(
                'from_name' => $from_name,
                'from_email' => $from_email,
            ));

            return rest_ensure_response(array('success' => true));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function SaveDataRetentionCallback($request)
    {
        try {
            $days = isset($request['days']) ? intval($request['days']) : 0;

            // Validate allowed values
            $allowed = array(0, 30, 60, 90, 180, 365);
            if (!in_array($days, $allowed, true)) {
                throw new SettingsException(__('Invalid retention period.', 'superb-blocks'));
            }

            update_option('superbaddons_form_data_retention', $days);

            return rest_ensure_response(array('success' => true));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private static function GetCaptchaOptionKeys($provider)
    {
        $map = array(
            'hcaptcha' => array(
                'site_key' => FormSettings::OPTION_HCAPTCHA_SITE_KEY,
                'secret_key' => FormSettings::OPTION_HCAPTCHA_SECRET_KEY,
            ),
            'recaptcha' => array(
                'site_key' => FormSettings::OPTION_RECAPTCHA_SITE_KEY,
                'secret_key' => FormSettings::OPTION_RECAPTCHA_SECRET_KEY,
            ),
            'turnstile' => array(
                'site_key' => FormSettings::OPTION_TURNSTILE_SITE_KEY,
                'secret_key' => FormSettings::OPTION_TURNSTILE_SECRET_KEY,
            ),
        );
        return isset($map[$provider]) ? $map[$provider] : false;
    }

    private function SaveCaptchaKeyCallback($request)
    {
        try {
            $provider = isset($request['provider']) ? sanitize_text_field(wp_unslash($request['provider'])) : '';
            $site_key = isset($request['site_key']) ? sanitize_text_field(wp_unslash($request['site_key'])) : '';
            $secret_key = isset($request['secret_key']) ? sanitize_text_field(wp_unslash($request['secret_key'])) : '';

            $keys = self::GetCaptchaOptionKeys($provider);
            if (!$keys) {
                throw new SettingsException(__('Invalid CAPTCHA provider.', 'superb-blocks'));
            }
            if (empty($site_key) || empty($secret_key)) {
                throw new SettingsException(__('Both site key and secret key are required.', 'superb-blocks'));
            }

            FormSettings::Set($keys['site_key'], $site_key);
            FormSettings::Set($keys['secret_key'], $secret_key);

            return rest_ensure_response(array(
                'success' => true,
                'masked_secret' => FormSettings::GetMasked($keys['secret_key']),
            ));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function RemoveCaptchaKeyCallback($request)
    {
        try {
            $provider = isset($request['provider']) ? sanitize_text_field(wp_unslash($request['provider'])) : '';
            $keys = self::GetCaptchaOptionKeys($provider);
            if (!$keys) {
                throw new SettingsException(__('Invalid CAPTCHA provider.', 'superb-blocks'));
            }

            FormSettings::Remove($keys['site_key']);
            FormSettings::Remove($keys['secret_key']);

            return rest_ensure_response(array('success' => true));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function GetIntegrationUsageCallback($request)
    {
        try {
            return rest_ensure_response(array(
                'success' => true,
                'mailchimp' => FormSettings::CountFormsUsingIntegration('mailchimp'),
                'brevo' => FormSettings::CountFormsUsingIntegration('brevo'),
                'hcaptcha' => FormSettings::CountFormsUsingCaptchaProvider('hcaptcha'),
                'recaptcha' => FormSettings::CountFormsUsingCaptchaProvider('recaptcha'),
                'turnstile' => FormSettings::CountFormsUsingCaptchaProvider('turnstile'),
            ));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function RemoveAllDataCallback($request)
    {
        try {
            $include_submissions = isset($request['include_submissions']) && $request['include_submissions'] === '1';
            PluginResetController::RemoveAllPluginData($include_submissions);
            return rest_ensure_response(array('success' => true));
        } catch (SettingsException $s_ex) {
            return rest_ensure_response(array('success' => false, 'text' => esc_html($s_ex->getMessage())));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }
}
