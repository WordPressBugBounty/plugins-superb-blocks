<?php

namespace SuperbAddons\Admin\Pages;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Controllers\DashboardController;
use SuperbAddons\Admin\Controllers\RewriteCheckController;
use SuperbAddons\Admin\Controllers\SettingsController;
use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Components\Admin\EnhancementSettingsComponent;
use SuperbAddons\Components\Admin\InputCheckbox;
use SuperbAddons\Components\Admin\Modal;
use SuperbAddons\Components\Admin\PremiumBox;
use SuperbAddons\Data\Controllers\KeyController;

use SuperbAddons\Components\Admin\EncryptionNotice;
use SuperbAddons\Components\Admin\InputApiKey;
use SuperbAddons\Components\Admin\ReviewBox;
use SuperbAddons\Components\Admin\SupportBox;
use SuperbAddons\Components\Admin\SupportLinkBoxes;
use SuperbAddons\Data\Controllers\CompatibilitySettingsOptionKey;
use SuperbAddons\Data\Controllers\OptionController;
use SuperbAddons\Data\Controllers\SettingsOptionKey;
use SuperbAddons\Data\Utils\KeyType;
use SuperbAddons\Gutenberg\Controllers\GutenbergController;
use SuperbAddons\Gutenberg\Controllers\GutenbergEnhancementsController;
use SuperbAddons\Gutenberg\Form\FormAccessControl;
use SuperbAddons\Gutenberg\Form\FormPermissions;
use SuperbAddons\Gutenberg\Form\FormSettings;

class SettingsPage
{
    private $HasRegisteredKey = false;
    private $KeyTypeLabel = false;
    private $KeyStatus = false;
    private $KeyHasIssue = false;
    private $Settings = false;
    private $Incompatibilities = false;
    private $CompatibilitySettings = false;
    private $MailchimpConfigured = false;
    private $MailchimpMaskedKey = '';
    private $BrevoConfigured = false;
    private $BrevoMaskedKey = '';
    private $GoogleSheetsConfigured = false;
    private $GoogleSheetsClientEmail = '';
    private $EditorEnabledCount = 0;
    private $EditorTotalCount = 0;
    private $IntegrationCount = 0;
    private $IntegrationTotalCount = 0;
    private $GlobalEnhancementOptions = array();
    private $GlobalEnabledCount = 0;
    private $GlobalTotalCount = 0;
    private $DisabledBlocks = array();
    private $DisabledBlockCount = 0;
    private $ActiveBlockCount = 0;
    private $TotalBlockCount = 0;

    // CAPTCHA config
    private $HcaptchaConfigured = false;
    private $HcaptchaMaskedSecret = '';
    private $HcaptchaSiteKey = '';
    private $RecaptchaConfigured = false;
    private $RecaptchaMaskedSecret = '';
    private $RecaptchaSiteKey = '';
    private $TurnstileConfigured = false;
    private $TurnstileMaskedSecret = '';
    private $TurnstileSiteKey = '';

    // Forms settings
    private $DataRetentionDays = 0;
    private $DefaultEmail = array();
    private $FormPermissions = array();

    public function __construct()
    {
        $this->HasRegisteredKey = KeyController::HasRegisteredKey();
        if ($this->HasRegisteredKey) {
            $this->KeyTypeLabel = KeyController::GetCurrentKeyTypeLabel();
            $this->KeyStatus = KeyController::GetKeyStatus();
            $this->KeyHasIssue = $this->KeyStatus['expired'] || !$this->KeyStatus['active'] || !$this->KeyStatus['verified'] || $this->KeyStatus['exceeded'];
        }

        $this->Settings = SettingsController::GetSettings();

        $this->Incompatibilities = SettingsController::GetRelevantCompatibilitySettings();
        if (count($this->Incompatibilities) > 0) {
            $this->CompatibilitySettings = SettingsController::GetCompatibilitySettings();
        }

        // Enhancement counts: global keys for the card badge, all keys for the status bar
        $this->GlobalEnhancementOptions = GutenbergEnhancementsController::GetGlobalEnhancementsOptions();
        $user_options = GutenbergEnhancementsController::GetEnhancementsOptions(get_current_user_id());
        $global_keys = array(
            GutenbergEnhancementsController::RESPONSIVE_KEY,
            GutenbergEnhancementsController::ANIMATIONS_KEY,
            GutenbergEnhancementsController::CONDITIONS_KEY,
            GutenbergEnhancementsController::DYNAMIC_CONTENT_KEY,
            GutenbergEnhancementsController::NAVIGATION_KEY,
            GutenbergEnhancementsController::RICHTEXT_KEY,
            GutenbergEnhancementsController::SOCIAL_ICONS_KEY,
            GutenbergEnhancementsController::DASHBOARD_SHORTCUTS_KEY,
            GutenbergEnhancementsController::STICKY_KEY,
            GutenbergEnhancementsController::Z_INDEX_KEY,
        );
        $user_keys = array(
            GutenbergEnhancementsController::HIGHLIGHTS_KEY,
        );
        $this->GlobalTotalCount = count($global_keys);
        foreach ($global_keys as $key) {
            if (isset($this->GlobalEnhancementOptions[$key]) && $this->GlobalEnhancementOptions[$key]) {
                $this->GlobalEnabledCount++;
            }
        }
        // Status bar total = global + per-user
        $this->EditorTotalCount = $this->GlobalTotalCount + count($user_keys);
        $this->EditorEnabledCount = $this->GlobalEnabledCount;
        foreach ($user_keys as $key) {
            if (isset($user_options[$key]) && $user_options[$key]) {
                $this->EditorEnabledCount++;
            }
        }

        // Disabled blocks
        $this->DisabledBlocks = OptionController::GetDisabledBlocks();
        $this->DisabledBlockCount = count($this->DisabledBlocks);
        $this->TotalBlockCount = GutenbergController::GetDiscoverableBlockTotal();
        $this->ActiveBlockCount = GutenbergController::GetDiscoverableBlockActiveCount($this->DisabledBlocks);

        // Integrations
        $this->IntegrationTotalCount++;
        $this->MailchimpConfigured = FormSettings::HasValue(FormSettings::OPTION_MAILCHIMP_API_KEY);
        if ($this->MailchimpConfigured) {
            $this->MailchimpMaskedKey = FormSettings::GetMasked(FormSettings::OPTION_MAILCHIMP_API_KEY);
            $this->IntegrationCount++;
        }
        $this->IntegrationTotalCount++;
        $this->BrevoConfigured = FormSettings::HasValue(FormSettings::OPTION_BREVO_API_KEY);
        if ($this->BrevoConfigured) {
            $this->BrevoMaskedKey = FormSettings::GetMasked(FormSettings::OPTION_BREVO_API_KEY);
            $this->IntegrationCount++;
        }
        $this->IntegrationTotalCount++;
        $this->GoogleSheetsConfigured = FormSettings::HasValue(FormSettings::OPTION_GOOGLE_SHEETS_CLIENT_EMAIL) && FormSettings::HasValue(FormSettings::OPTION_GOOGLE_SHEETS_PRIVATE_KEY);
        if ($this->GoogleSheetsConfigured) {
            $this->GoogleSheetsClientEmail = FormSettings::Get(FormSettings::OPTION_GOOGLE_SHEETS_CLIENT_EMAIL);
            $this->IntegrationCount++;
        }

        // CAPTCHA settings
        $this->IntegrationTotalCount++;
        $this->HcaptchaConfigured = FormSettings::HasValue(FormSettings::OPTION_HCAPTCHA_SITE_KEY) && FormSettings::HasValue(FormSettings::OPTION_HCAPTCHA_SECRET_KEY);
        if ($this->HcaptchaConfigured) {
            $this->HcaptchaMaskedSecret = FormSettings::GetMasked(FormSettings::OPTION_HCAPTCHA_SECRET_KEY);
            $this->HcaptchaSiteKey = FormSettings::Get(FormSettings::OPTION_HCAPTCHA_SITE_KEY);
            $this->IntegrationCount++;
        }
        $this->IntegrationTotalCount++;
        $this->RecaptchaConfigured = FormSettings::HasValue(FormSettings::OPTION_RECAPTCHA_SITE_KEY) && FormSettings::HasValue(FormSettings::OPTION_RECAPTCHA_SECRET_KEY);
        if ($this->RecaptchaConfigured) {
            $this->RecaptchaMaskedSecret = FormSettings::GetMasked(FormSettings::OPTION_RECAPTCHA_SECRET_KEY);
            $this->RecaptchaSiteKey = FormSettings::Get(FormSettings::OPTION_RECAPTCHA_SITE_KEY);
            $this->IntegrationCount++;
        }
        $this->IntegrationTotalCount++;
        $this->TurnstileConfigured = FormSettings::HasValue(FormSettings::OPTION_TURNSTILE_SITE_KEY) && FormSettings::HasValue(FormSettings::OPTION_TURNSTILE_SECRET_KEY);
        if ($this->TurnstileConfigured) {
            $this->TurnstileMaskedSecret = FormSettings::GetMasked(FormSettings::OPTION_TURNSTILE_SECRET_KEY);
            $this->TurnstileSiteKey = FormSettings::Get(FormSettings::OPTION_TURNSTILE_SITE_KEY);
            $this->IntegrationCount++;
        }

        // Forms settings
        $this->DataRetentionDays = intval(get_option('superbaddons_form_data_retention', 0));
        $this->DefaultEmail = get_option('superbaddons_form_default_email', array());
        if (!is_array($this->DefaultEmail)) {
            $this->DefaultEmail = array();
        }
        $this->FormPermissions = FormPermissions::GetAll();

        $this->Render();
    }

    private function Render()
    {
        $license_status_class = 'superbaddons-status-dot--gray';
        $license_status_label = __('No License', 'superb-blocks');
        if ($this->HasRegisteredKey) {
            if ($this->KeyHasIssue) {
                $license_status_class = 'superbaddons-status-dot--red';
                $license_status_label = __('Issue Detected', 'superb-blocks');
            } else {
                $license_status_class = 'superbaddons-status-dot--green';
                $license_status_label = $this->KeyTypeLabel;
            }
        }

        $enhancement_dot = 'superbaddons-status-dot--gray';
        if ($this->EditorEnabledCount >= 4) {
            $enhancement_dot = 'superbaddons-status-dot--green';
        } elseif ($this->EditorEnabledCount > 0) {
            $enhancement_dot = 'superbaddons-status-dot--yellow';
        }
?>
        <!-- Status Dashboard Header -->
        <div class="superbaddons-settings-status-bar superbaddons-dashboard-welcome-strip">
            <span class="superbaddons-dashboard-welcome-title"><?php esc_html_e("Settings", "superb-blocks"); ?></span>
            <div class="superbaddons-dashboard-stat-items">
                <a href="#license" class="superbaddons-settings-status-item" data-tab-link="license">
                    <span class="superbaddons-status-dot <?php echo esc_attr($license_status_class); ?>"></span>
                    <?php echo esc_html__('License:', 'superb-blocks'); ?> <?php echo esc_html($license_status_label); ?>
                </a>
                <a href="#modules" class="superbaddons-settings-status-item" data-tab-link="modules">
                    <span class="superbaddons-status-dot <?php echo $this->DisabledBlockCount === 0 ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--yellow'; ?>"></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %1$d: active block count, %2$d: total block count */
                        __('Blocks: %1$d/%2$d', 'superb-blocks'),
                        $this->ActiveBlockCount,
                        $this->TotalBlockCount
                    )); ?>
                </a>
                <a href="#modules" class="superbaddons-settings-status-item" data-tab-link="modules">
                    <span class="superbaddons-status-dot <?php echo esc_attr($enhancement_dot); ?>"></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %1$d: enabled enhancement count, %2$d: total enhancement count */
                        __('Enhancements: %1$d/%2$d', 'superb-blocks'),
                        $this->EditorEnabledCount,
                        $this->EditorTotalCount
                    )); ?>
                </a>
                <a href="#integrations" class="superbaddons-settings-status-item" data-tab-link="integrations">
                    <span class="superbaddons-status-dot <?php echo $this->IntegrationCount >= 1 ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--gray'; ?>"></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %1$d: number of connected integrations, %2$d: total integrations */
                        __('Integrations: %1$d/%2$d', 'superb-blocks'),
                        $this->IntegrationCount,
                        $this->IntegrationTotalCount
                    )); ?>
                </a>
                <a href="#advanced" class="superbaddons-settings-status-item" data-tab-link="advanced">
                    <span class="superbaddons-status-dot <?php echo $this->Settings[SettingsOptionKey::LOGS_ENABLED] ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--gray'; ?>"></span>
                    <?php echo esc_html__('Logs:', 'superb-blocks'); ?> <?php echo $this->Settings[SettingsOptionKey::LOGS_ENABLED] ? esc_html__('Enabled', 'superb-blocks') : esc_html__('Disabled', 'superb-blocks'); ?>
                </a>
            </div>
        </div>

        <!-- Settings Search -->
        <div class="superbaddons-settings-search-wrapper">
            <input type="text" class="superbaddons-settings-search" placeholder="<?php echo esc_attr__('Search settings...', 'superb-blocks'); ?>" />
        </div>

        <!-- Settings Tabs -->
        <div class="superbaddons-settings-tabs">
            <button class="superbaddons-settings-tab superbaddons-settings-tab--active" data-tab="license" type="button"><?php echo esc_html__('License & Account', 'superb-blocks'); ?></button>
            <button class="superbaddons-settings-tab" data-tab="modules" type="button"><?php echo esc_html__('Blocks & Enhancements', 'superb-blocks'); ?></button>
            <button class="superbaddons-settings-tab" data-tab="integrations" type="button"><?php echo esc_html__('Integrations', 'superb-blocks'); ?></button>
            <button class="superbaddons-settings-tab" data-tab="forms" type="button"><?php echo esc_html__('Forms', 'superb-blocks'); ?></button>
            <button class="superbaddons-settings-tab" data-tab="advanced" type="button"><?php echo esc_html__('Advanced', 'superb-blocks'); ?></button>
        </div>

        <div class="superbaddons-admindashboard-sidebarlayout">
            <div class="superbaddons-admindashboard-sidebarlayout-left">

                <!-- Tab: License & Account -->
                <div class="superbaddons-settings-tab-content superbaddons-settings-tab-content--active" data-tab-content="license">
                    <div class="superbaddons-license-key-wrapper superbaddons-settings-section">
                        <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("License Key", "superb-blocks"); ?></h4>
                        <p class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-help-text-inline"><?php echo esc_html__("Manage your Superb Addons license key.", "superb-blocks"); ?></p>
                        <?php $this->MaybeDisplayRewriteIssue(); ?>
                        <?php if ($this->HasRegisteredKey) : ?>
                            <div class="superbaddons-license-key-body">
                                <?php $this->MaybeDisplayKeyIssue(); ?>
                                <?php $this->MaybeDisplayPremiumPluginMissingNotice(); ?>
                                <p class="superbaddons-element-text-sm"><?php echo esc_html__('Current License: ', "superb-blocks"); ?><span class="superbaddons-element-text-800"><?php echo esc_html($this->KeyTypeLabel); ?></span></p>
                                <button id="spbaddons-license-remove-btn" class="superbaddons-element-button spbaddons-admin-btn-danger" type="button"><?php echo esc_html__('Remove License Key', "superb-blocks"); ?></button>
                            </div>
                        <?php else : ?>
                            <div class="superbaddons-license-key-body superbaddons-license-key-body-flex">
                                <div class="superbaddons-license-key-input-wrapper">
                                    <img class="spbaddons-license-result-icon spbaddons-license-error" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/color-warning-octagon.svg"); ?>" style="display:none;" />
                                    <input id="superbaddons-license-key-input" type="text" placeholder="XXXXX-XXXXX-XXXXX-XXXXX" maxlength="23" />
                                </div>
                                <button id="spbaddons-license-submit-btn" class="superbaddons-element-button" type="button" disabled><?php echo esc_html__('Add License Key', "superb-blocks"); ?></button>
                            </div>
                        <?php endif; ?>
                        <div class="superbaddons-spinner-wrapper" style="display:none;">
                            <img class="spbaddons-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/blocks-spinner.svg"); ?>" />
                        </div>
                    </div>
                    <div class="superbaddons-admindashboard-linkbox-wrapper">
                        <?php new SupportLinkBoxes(); ?>
                    </div>
                </div>

                <!-- Tab: Modules -->
                <div class="superbaddons-settings-tab-content" data-tab-content="modules">
                    <div class="superbaddons-settings-section">
                        <div class="superbaddons-dashboard-section-header">
                            <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Gutenberg Blocks", "superb-blocks"); ?></h4>
                            <span class="superbaddons-dashboard-count-badge"><?php echo esc_html(sprintf(
                                                                                    /* translators: %1$d: active, %2$d: total */
                                                                                    __('%1$d/%2$d Active', 'superb-blocks'),
                                                                                    $this->ActiveBlockCount,
                                                                                    $this->TotalBlockCount
                                                                                )); ?></span>
                        </div>
                        <p class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-help-text-inline"><?php echo esc_html__("Toggle blocks on or off. Disabled blocks can no longer be inserted, but existing content will continue to render.", "superb-blocks"); ?></p>
                        <div class="superbaddons-modules-block-grid">
                            <?php
                            $block_data = array(
                                'author-box' => array('label' => __('About the Author', 'superb-blocks'), 'icon' => 'purple-identification-badge.svg'),
                                'ratings' => array('label' => __('Rating Block', 'superb-blocks'), 'icon' => 'purple-star.svg'),
                                'table-of-contents' => array('label' => __('Table of Contents', 'superb-blocks'), 'icon' => 'purple-list-bullets.svg'),
                                'recent-posts' => array('label' => __('Recent Posts', 'superb-blocks'), 'icon' => 'purple-note.svg'),
                                'cover-image' => array('label' => __('Cover Image', 'superb-blocks'), 'icon' => 'purple-image.svg'),
                                'google-maps' => array('label' => __('Google Maps', 'superb-blocks'), 'icon' => 'purple-pin.svg'),
                                'reveal-buttons' => array('label' => __('Reveal Buttons', 'superb-blocks'), 'icon' => 'purple-pointing.svg'),
                                'accordion' => array('label' => __('Toggle', 'superb-blocks'), 'icon' => 'accordion-icon-purple.svg'),
                                'carousel' => array('label' => __('Carousel Slider', 'superb-blocks'), 'icon' => 'icon-carousel.svg'),
                                'countdown' => array('label' => __('Countdown', 'superb-blocks'), 'icon' => 'icon-countdown.svg'),
                                'progress-bar' => array('label' => __('Progress Bar', 'superb-blocks'), 'icon' => 'icon-progressbar.svg'),
                                'popup' => array('label' => __('Popup', 'superb-blocks'), 'icon' => 'icon-popup.svg'),
                                'form' => array('label' => __('Form & Multi-Step Form', 'superb-blocks'), 'icon' => 'icon-form.svg'),
                                'add-to-cart' => array('label' => __('Add to Cart & Buy Now', 'superb-blocks'), 'icon' => 'purple-shopping-cart.svg'),
                            );
                            foreach (GutenbergController::TOGGLEABLE_BLOCKS as $block_slug) :
                                $is_enabled = !in_array($block_slug, $this->DisabledBlocks, true);
                                $data = isset($block_data[$block_slug]) ? $block_data[$block_slug] : array('label' => $block_slug, 'icon' => 'purple-cube.svg');
                            ?>
                                <div class="superb-addons-checkbox-input-wrapper superbaddons-modules-block-item">
                                    <label class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-element-inlineflex-center superbaddons-element-relative">
                                        <input name="superbaddons-block-<?php echo esc_attr($block_slug); ?>" class="superbaddons-inputcheckbox-input superbaddons-block-toggle-input" data-block="<?php echo esc_attr($block_slug); ?>" type="checkbox" <?php echo $is_enabled ? 'checked="checked"' : ''; ?> />
                                        <span class="superb-addons-checkbox-checkmark"><img class="superbaddons-admindashboard-content-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" /></span>
                                        <img class="superbaddons-modules-block-item-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/' . $data['icon']); ?>" aria-hidden="true" />
                                        <span><?php echo esc_html($data['label']); ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="superbaddons-settings-section">
                        <div class="superbaddons-dashboard-section-header">
                            <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Editor Enhancements", "superb-blocks"); ?></h4>
                            <span class="superbaddons-dashboard-count-badge"><?php echo esc_html(sprintf(
                                                                                    /* translators: %1$d: active, %2$d: total */
                                                                                    __('%1$d/%2$d Active', 'superb-blocks'),
                                                                                    $this->GlobalEnabledCount,
                                                                                    $this->GlobalTotalCount
                                                                                )); ?></span>
                        </div>
                        <p class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-help-text-inline"><?php echo esc_html__("Toggle editor enhancements on or off. These settings apply to all users on the site.", "superb-blocks"); ?></p>
                        <div class="superbaddons-modules-block-grid superbaddons-modules-block-grid--enhancements">
                            <?php
                            $enhancement_data = array(
                                array('id' => 'superbaddons-enhancement-responsive-input', 'key' => GutenbergEnhancementsController::RESPONSIVE_KEY, 'label' => __('Responsive Controls', 'superb-blocks'), 'icon' => 'devices-duotone.svg', 'desc' => __('Per-device visibility toggles, spacing, font size, alignment overrides, and more for all blocks.', 'superb-blocks')),
                                array('id' => 'superbaddons-enhancement-animations-input', 'key' => GutenbergEnhancementsController::ANIMATIONS_KEY, 'label' => __('Animations', 'superb-blocks'), 'icon' => 'sneaker-move-duotone.svg', 'desc' => __('40+ block animations, letter effects, and typing & counting animations for all blocks.', 'superb-blocks')),
                                array('id' => 'superbaddons-enhancement-conditions-input', 'key' => GutenbergEnhancementsController::CONDITIONS_KEY, 'label' => __('Block Conditions', 'superb-blocks'), 'icon' => 'target-duotone.svg', 'desc' => __('Show or hide blocks based on user status, roles, post type, categories, and more.', 'superb-blocks')),
                                array('id' => 'superbaddons-enhancement-dynamiccontent-input', 'key' => GutenbergEnhancementsController::DYNAMIC_CONTENT_KEY, 'label' => __('Dynamic Content', 'superb-blocks'), 'icon' => 'brackets-curly-duotone.svg', 'desc' => __('Insert post data, author info, dates, and custom fields dynamically into any text block.', 'superb-blocks')),
                                array('id' => 'superbaddons-enhancement-navigation-input', 'key' => GutenbergEnhancementsController::NAVIGATION_KEY, 'label' => __('Navigation Enhancements', 'superb-blocks'), 'icon' => 'purple-list-bullets.svg', 'desc' => __('Mobile overlay justification options and submenu layout improvements for the navigation block.', 'superb-blocks')),
                                array('id' => 'superbaddons-enhancement-richtext-input', 'key' => GutenbergEnhancementsController::RICHTEXT_KEY, 'label' => __('Rich Text', 'superb-blocks'), 'icon' => 'purple-aa.svg', 'desc' => __('Rich text enhancements such as justify text alignment for text-based blocks.', 'superb-blocks')),
                                array('id' => 'superbaddons-enhancement-socialicons-input', 'key' => GutenbergEnhancementsController::SOCIAL_ICONS_KEY, 'label' => __('Extra Social Icons', 'superb-blocks'), 'icon' => 'purple-chat.svg', 'desc' => __('Adds 20+ additional icons to the core Social Icons block (Bilibili, Ko-fi, Signal, Slack, Steam, Substack, and more).', 'superb-blocks')),
                                array('id' => 'superbaddons-enhancement-dashboardshortcuts-input', 'key' => GutenbergEnhancementsController::DASHBOARD_SHORTCUTS_KEY, 'label' => __('Admin Shortcuts', 'superb-blocks'), 'icon' => 'purple-gauge.svg', 'desc' => __('Adds handy block theme shortcuts such as Edit Front Page and Style Book.', 'superb-blocks')),
                                array('id' => 'superbaddons-enhancement-sticky-input', 'key' => GutenbergEnhancementsController::STICKY_KEY, 'label' => __('Sticky Positioning', 'superb-blocks'), 'icon' => 'pushpin-duotone.svg', 'desc' => __('Pin any block to the top or bottom of the screen as visitors scroll, with scope and per-device control.', 'superb-blocks')),
                                array('id' => 'superbaddons-enhancement-zindex-input', 'key' => GutenbergEnhancementsController::Z_INDEX_KEY, 'label' => __('Stacking Order', 'superb-blocks'), 'icon' => 'stack-simple-duotone.svg', 'desc' => __('Adds a z-index control to the Advanced panel of any block, with quick presets for common stacking levels.', 'superb-blocks')),
                            );
                            foreach ($enhancement_data as $enh) :
                                $is_enabled = isset($this->GlobalEnhancementOptions[$enh['key']]) && $this->GlobalEnhancementOptions[$enh['key']];
                            ?>
                                <div class="superb-addons-checkbox-input-wrapper superbaddons-modules-block-item">
                                    <label class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-element-inlineflex-center superbaddons-element-relative">
                                        <input id="<?php echo esc_attr($enh['id']); ?>" name="<?php echo esc_attr($enh['id']); ?>" class="superbaddons-inputcheckbox-input" data-action="<?php echo esc_attr($enh['key']); ?>" type="checkbox" <?php echo $is_enabled ? 'checked="checked"' : ''; ?> />
                                        <span class="superb-addons-checkbox-checkmark"><img class="superbaddons-admindashboard-content-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" /></span>
                                        <img class="superbaddons-modules-block-item-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/' . $enh['icon']); ?>" aria-hidden="true" />
                                        <span><?php echo esc_html($enh['label']); ?></span>
                                        <button type="button" class="superbaddons-help-toggle" aria-label="<?php echo esc_attr__('Toggle help text', 'superb-blocks'); ?>">i</button>
                                    </label>
                                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-help-text"><?php echo esc_html($enh['desc']); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="superbaddons-editor-settings-wrapper superbaddons-settings-section">
                        <?php new EnhancementSettingsComponent(); ?>
                    </div>
                </div>

                <!-- Tab: Integrations -->
                <div class="superbaddons-settings-tab-content" data-tab-content="integrations">
                    <div class="superbaddons-form-settings-wrapper superbaddons-settings-section" id="form-settings">
                        <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Integrations", "superb-blocks"); ?></h4>
                        <p class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-help-text-inline"><?php echo esc_html__("Connect third-party services to your website.", "superb-blocks"); ?></p>
                        <?php new EncryptionNotice(); ?>
                        <div class="superbaddons-integration-cards">
                            <?php
                            new InputApiKey(
                                'superbaddons-mailchimp-key',
                                'mailchimp',
                                __("Mailchimp", "superb-blocks"),
                                __("Connect form submissions to your Mailchimp audiences.", "superb-blocks"),
                                'https://admin.mailchimp.com/account/api/',
                                __("Get API key from Mailchimp", "superb-blocks"),
                                $this->MailchimpConfigured,
                                $this->MailchimpMaskedKey,
                                __("Enter Mailchimp API key", "superb-blocks")
                            );
                            new InputApiKey(
                                'superbaddons-brevo-key',
                                'brevo',
                                __("Brevo (Sendinblue)", "superb-blocks"),
                                __("Connect form submissions to your Brevo contact lists.", "superb-blocks"),
                                'https://app.brevo.com/settings/keys/api',
                                __("Get API key from Brevo", "superb-blocks"),
                                $this->BrevoConfigured,
                                $this->BrevoMaskedKey,
                                __("Enter Brevo API key", "superb-blocks")
                            );
                            $this->RenderGoogleSheetsCard();
                            $this->RenderCaptchaCard('hcaptcha', __('hCaptcha', 'superb-blocks'), __('Privacy-focused challenge widget for your forms.', 'superb-blocks'), $this->HcaptchaConfigured, $this->HcaptchaSiteKey, $this->HcaptchaMaskedSecret, 'https://dashboard.hcaptcha.com/signup', __('Get keys from hCaptcha Dashboard', 'superb-blocks'));
                            $this->RenderCaptchaCard('recaptcha', __('reCAPTCHA (Google)', 'superb-blocks'), __('Works with both reCAPTCHA v2 and v3 for your forms.', 'superb-blocks'), $this->RecaptchaConfigured, $this->RecaptchaSiteKey, $this->RecaptchaMaskedSecret, 'https://www.google.com/recaptcha/admin', __('Get keys from Google reCAPTCHA', 'superb-blocks'));
                            $this->RenderCaptchaCard('turnstile', __('Cloudflare Turnstile', 'superb-blocks'), __('Non-intrusive Cloudflare challenge for your forms.', 'superb-blocks'), $this->TurnstileConfigured, $this->TurnstileSiteKey, $this->TurnstileMaskedSecret, 'https://dash.cloudflare.com/?to=:/turnstile', __('Get keys from Cloudflare Dashboard', 'superb-blocks'));
                            ?>
                        </div>
                    </div>
                </div>

                <!-- Tab: Forms -->
                <div class="superbaddons-settings-tab-content" data-tab-content="forms">
                    <?php $this->RenderFormsTabContent(); ?>
                </div>

                <!-- Tab: Advanced -->
                <div class="superbaddons-settings-tab-content" data-tab-content="advanced">
                    <div class="superbaddons-additional-content-wrapper superbaddons-settings-section">
                        <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Advanced Settings", "superb-blocks"); ?></h4>
                        <p class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-help-text-inline"><?php echo esc_html__("Manage your advanced settings for Superb Addons.", "superb-blocks"); ?></p>

                        <div class="superbaddons-error-logs-settings-wrapper">
                            <!-- Error Logs Settings -->
                            <h5 class="superbaddons-element-flex-center superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-mb1"><img class="superbaddons-admindashboard-content-icon superbaddons-element-mr1" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-bug.svg'); ?>" /><?php echo esc_html__("Error Logs", "superb-blocks"); ?></h5>
                            <?php new InputCheckbox('superbaddons-enable-logs-input', SettingsOptionKey::LOGS_ENABLED, __("Enable Error Logs", "superb-blocks"), __("If issues or errors occur in the plugin when this setting is enabled, the error messages will be logged and can be viewed and shared with our support team and developers.", "superb-blocks"), $this->Settings[SettingsOptionKey::LOGS_ENABLED]); ?>
                            <div class="superbaddons-maybe-hide-element" <?php echo $this->Settings[SettingsOptionKey::LOGS_ENABLED] ? '' : 'style="display:none;"'; ?>>
                                <?php new InputCheckbox('superbaddons-share-logs-input', SettingsOptionKey::LOG_SHARE_ENABLED, __("Share Error Logs", "superb-blocks"), __("When this setting is enabled, error logs will be shared anonymously with our support team and developers to help improve the plugin. Only the error messages shown in the error logs will be shared.", "superb-blocks"), $this->Settings[SettingsOptionKey::LOG_SHARE_ENABLED], '/img/cloud-arrow-up.svg'); ?>
                            </div>
                            <button type="button" class="superbaddons-element-button superbaddons-element-mr1" id="superbaddons-view-logs-btn"><img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/list-magnifying-glass.svg'); ?>" /><?php echo esc_html__("View Logs", "superb-blocks"); ?></button>
                        </div>

                        <div class="superbaddons-compatibility-settings-wrapper">
                            <!-- Compatibility Settings -->
                            <?php if (count($this->Incompatibilities) > 0) : ?>
                                <div class="superbaddons-element-separator"></div>
                                <h5 class="superbaddons-element-flex-center superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-mb1"><img class="superbaddons-admindashboard-content-icon superbaddons-element-mr1" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-plugs.svg'); ?>" /><?php echo esc_html__("Compatibility", "superb-blocks"); ?></h5>
                                <?php if (isset($this->Incompatibilities[CompatibilitySettingsOptionKey::SPECTRA_BLOCK_SPACING])) :
                                    new InputCheckbox('superbaddons-spectra-compat', CompatibilitySettingsOptionKey::SPECTRA_BLOCK_SPACING, __("Fix Block Spacing", "superb-blocks"), __("The Spectra plugin features an option to apply a fixed block spacing between all blocks while in the editor. Unfortunately this option overrides custom block spacing and can result in blocks and patterns appearing strange in the editor. When this setting is enabled, custom block spacing will appear correctly in the editor.", "superb-blocks"), $this->CompatibilitySettings[CompatibilitySettingsOptionKey::SPECTRA_BLOCK_SPACING]);
                                endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Danger Zone -->
                    <div class="superbaddons-danger-zone">
                        <h5 class="superbaddons-danger-zone-title">
                            <img class="superbaddons-admindashboard-content-icon superbaddons-element-mr1" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-warning-octagon.svg'); ?>" />
                            <?php echo esc_html__("Danger Zone", "superb-blocks"); ?>
                        </h5>
                        <div class="superbaddons-danger-zone-item">
                            <div class="superbaddons-danger-zone-item-info">
                                <strong><?php echo esc_html__("Clear Cache", "superb-blocks"); ?></strong>
                                <p><?php echo esc_html__("Removes all cached plugin data. Only clear if experiencing issues.", "superb-blocks"); ?></p>
                            </div>
                            <button type="button" class="superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-button-sm" id="superbaddons-clear-cache-btn"><img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" /><?php echo esc_html__("Clear", "superb-blocks"); ?></button>
                        </div>
                        <div class="superbaddons-danger-zone-item">
                            <div class="superbaddons-danger-zone-item-info">
                                <strong><?php echo esc_html__("Clear Restoration Points", "superb-blocks"); ?></strong>
                                <p><?php echo esc_html__("Removes all saved theme designer template restore points. Auto-deleted after 2 months.", "superb-blocks"); ?></p>
                            </div>
                            <button type="button" class="superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-button-sm" id="superbaddons-clear-restoration-points-btn"><img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" /><?php echo esc_html__("Clear", "superb-blocks"); ?></button>
                        </div>
                        <div class="superbaddons-danger-zone-item">
                            <div class="superbaddons-danger-zone-item-info">
                                <strong><?php echo esc_html__("Clear Error Logs", "superb-blocks"); ?></strong>
                                <p><?php echo esc_html__("Permanently deletes all recorded error logs.", "superb-blocks"); ?></p>
                            </div>
                            <button type="button" class="superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-button-sm" id="superbaddons-clear-logs-btn"><img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" /><?php echo esc_html__("Clear", "superb-blocks"); ?></button>
                        </div>
                        <div class="superbaddons-danger-zone-item">
                            <div class="superbaddons-danger-zone-item-info">
                                <strong><?php echo esc_html__("Remove All Plugin Data", "superb-blocks"); ?></strong>
                                <p><?php echo esc_html__("Permanently removes every plugin option, setting, user preference, integration key, license, and scheduled task. Use this before uninstalling for a clean removal.", "superb-blocks"); ?></p>
                            </div>
                            <button type="button" class="superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-button-sm" id="superbaddons-remove-all-data-btn"><img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" /><?php echo esc_html__("Remove", "superb-blocks"); ?></button>
                        </div>
                    </div>
                </div>

            </div>
            <div class="superbaddons-admindashboard-sidebarlayout-right">
                <?php new PremiumBox(AdminLinkSource::SETTINGS); ?>
                <!-- Sidebar: License tab -->
                <div class="superbaddons-settings-tab-sidebar superbaddons-settings-tab-sidebar--active" data-tab-sidebar="license">
                    <?php new ReviewBox(); ?>
                </div>
                <!-- Sidebar: Editor tab -->
                <div class="superbaddons-settings-tab-sidebar" data-tab-sidebar="modules">
                    <?php new ReviewBox(); ?>
                </div>
                <!-- Sidebar: Integrations tab -->
                <div class="superbaddons-settings-tab-sidebar" data-tab-sidebar="integrations">
                    <?php new SupportBox(); ?>
                </div>
                <!-- Sidebar: Forms tab -->
                <div class="superbaddons-settings-tab-sidebar" data-tab-sidebar="forms">
                    <?php new SupportBox(); ?>
                </div>
                <!-- Sidebar: Advanced tab -->
                <div class="superbaddons-settings-tab-sidebar" data-tab-sidebar="advanced">
                    <?php new SupportBox(); ?>
                </div>
            </div>
        </div>
        <?php new Modal(); ?>
    <?php
    }

    private function RenderGoogleSheetsCard()
    {
    ?>
        <div class="superbaddons-integration-card <?php echo $this->GoogleSheetsConfigured ? 'superbaddons-integration-card--connected' : ''; ?>" data-integration="google_sheets">
            <div class="superbaddons-integration-card-header">
                <img class="superbaddons-integration-card-logo" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/google-sheets-icon.svg'); ?>" />
                <h5 class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Google Sheets", "superb-blocks"); ?></h5>
                <?php if ($this->GoogleSheetsConfigured) : ?>
                    <span class="superbaddons-integration-card-badge superbaddons-integration-card-badge--connected"><?php echo esc_html__("Connected", "superb-blocks"); ?></span>
                <?php else : ?>
                    <span class="superbaddons-integration-card-badge"><?php echo esc_html__("Not Connected", "superb-blocks"); ?></span>
                <?php endif; ?>
            </div>
            <div class="superbaddons-integration-card-body">
                <?php if ($this->GoogleSheetsConfigured) : ?>
                    <div class="superbaddons-integration-card-connected-info">
                        <code class="superbaddons-input-api-key-masked"><?php echo esc_html($this->GoogleSheetsClientEmail); ?></code>
                        <button type="button" class="superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-button-sm superbaddons-input-api-key-remove-btn" id="superbaddons-google-sheets-key-remove-btn"><img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" /><?php echo esc_html__("Disconnect", "superb-blocks"); ?></button>
                    </div>
                <?php else : ?>
                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-integration-card-description" style="flex:0;"><?php echo esc_html__("Append form submissions as rows to a Google spreadsheet.", "superb-blocks"); ?></p>
                    <div class="superbaddons-input-api-key-input-row" style="flex:1;">
                        <textarea id="superbaddons-google-sheets-key-input" class="superbaddons-input-api-key-input" rows="3" placeholder="<?php echo esc_attr__("Paste Service Account JSON key", "superb-blocks"); ?>" autocomplete="off"></textarea>
                        <button type="button" class="superbaddons-element-button superbaddons-element-button-sm superbaddons-input-api-key-save-btn" id="superbaddons-google-sheets-key-save-btn" disabled><?php echo esc_html__("Connect", "superb-blocks"); ?></button>
                    </div>

                    <div class="superbaddons-spinner-wrapper superbaddons-input-api-key-spinner" id="superbaddons-google-sheets-key-spinner" style="display:none;">
                        <img class="spbaddons-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/blocks-spinner.svg"); ?>" />
                    </div>
                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener noreferrer"><?php echo esc_html__("Create a Service Account in Google Cloud Console", "superb-blocks"); ?></a></p>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }

    private function RenderCaptchaCard($provider, $title, $description, $configured, $site_key, $masked_secret, $dashboard_url, $dashboard_label)
    {
        $usage_count = FormSettings::CountFormsUsingCaptchaProvider($provider);
        $icon_path = SUPERBADDONS_ASSETS_PATH . '/img/' . $provider . '-icon.svg';
    ?>
        <div class="superbaddons-captcha-card <?php echo $configured ? 'superbaddons-captcha-card--connected' : ''; ?>" data-captcha-provider="<?php echo esc_attr($provider); ?>">
            <div class="superbaddons-captcha-card-header">
                <img class="superbaddons-integration-card-logo" src="<?php echo esc_url($icon_path); ?>" />
                <h5 class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html($title); ?></h5>
                <?php if ($configured) : ?>
                    <span class="superbaddons-integration-card-badge superbaddons-integration-card-badge--connected"><?php echo esc_html__("Connected", "superb-blocks"); ?></span>
                <?php else : ?>
                    <span class="superbaddons-integration-card-badge"><?php echo esc_html__("Not Connected", "superb-blocks"); ?></span>
                <?php endif; ?>
                <?php if ($usage_count > 0) : ?>
                    <span class="superbaddons-integration-usage-badge" data-usage-count="<?php echo intval($usage_count); ?>"><?php echo esc_html(sprintf(
                                                                                                                                    /* translators: %d: number of forms */
                                                                                                                                    __('In use on %d forms', 'superb-blocks'),
                                                                                                                                    $usage_count
                                                                                                                                )); ?></span>
                <?php endif; ?>
            </div>
            <div class="superbaddons-captcha-card-body">
                <?php if ($configured) : ?>
                    <div class="superbaddons-captcha-card-connected-info">
                        <div class="superbaddons-captcha-card-field">
                            <label class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Site Key", "superb-blocks"); ?></label>
                            <code class="superbaddons-input-api-key-masked"><?php echo esc_html($site_key); ?></code>
                        </div>
                        <div class="superbaddons-captcha-card-field">
                            <label class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Secret Key", "superb-blocks"); ?></label>
                            <code class="superbaddons-input-api-key-masked"><?php echo esc_html($masked_secret); ?></code>
                        </div>
                        <button type="button" class="superbaddons-element-button spbaddons-admin-btn-danger superbaddons-input-api-key-save-btn superbaddons-captcha-remove-btn"
                            data-provider="<?php echo esc_attr($provider); ?>"
                            data-usage-count="<?php echo intval($usage_count); ?>">
                            <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" /><?php echo esc_html__("Remove Keys", "superb-blocks"); ?>
                        </button>
                    </div>
                <?php else : ?>
                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-captcha-card-description"><?php echo esc_html($description); ?></p>
                    <div class="superbaddons-captcha-key-inputs">
                        <div class="superbaddons-captcha-input-row">
                            <input id="superbaddons-captcha-<?php echo esc_attr($provider); ?>-site-key" type="text" class="superbaddons-input-api-key-input superbaddons-captcha-site-key-input" placeholder="<?php echo esc_attr__("Enter site key", "superb-blocks"); ?>" autocomplete="off" />
                        </div>
                        <div class="superbaddons-captcha-input-row">
                            <input id="superbaddons-captcha-<?php echo esc_attr($provider); ?>-secret-key" type="text" class="superbaddons-input-api-key-input superbaddons-captcha-secret-key-input superbaddons-input-masked" placeholder="<?php echo esc_attr__("Enter secret key", "superb-blocks"); ?>" autocomplete="off" />
                        </div>
                        <button type="button" class="superbaddons-element-button superbaddons-input-api-key-save-btn superbaddons-captcha-save-btn" data-provider="<?php echo esc_attr($provider); ?>" disabled>
                            <?php echo esc_html__("Save Keys", "superb-blocks"); ?>
                        </button>
                    </div>
                    <div class="superbaddons-spinner-wrapper superbaddons-captcha-spinner" style="display:none;">
                        <img class="spbaddons-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/blocks-spinner.svg"); ?>" />
                    </div>
                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><a href="<?php echo esc_url($dashboard_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($dashboard_label); ?></a></p>
                <?php endif; ?>
            </div>
        </div>
    <?php
    }

    private function RenderFormsTabContent()
    {
        $configurable_roles = FormPermissions::GetConfigurableRoles();
        $capabilities = FormPermissions::GetCapabilities();
        $access_control_enabled = FormAccessControl::IsEnabled();

        $cap_labels = array(
            'view' => __('View Forms & Submissions', 'superb-blocks'),
            'delete' => __('Delete Submissions', 'superb-blocks'),
            'export' => __('Export Submissions', 'superb-blocks'),
            'sensitive' => __('View Sensitive Fields', 'superb-blocks'),
            'notes' => __('Manage Notes', 'superb-blocks'),
            'spam' => __('Manage Spam', 'superb-blocks'),
            'create' => __('Create Forms', 'superb-blocks'),
            'edit' => __('Edit Forms', 'superb-blocks'),
            'configure' => __('Configure Forms', 'superb-blocks'),
        );
        $cap_descriptions = array(
            'view' => __('Access the Forms admin page, view forms and their submissions lists, and read individual form entries.', 'superb-blocks'),
            'delete' => __('Permanently remove individual or multiple submissions.', 'superb-blocks'),
            'export' => __('Export & download submissions.', 'superb-blocks'),
            'sensitive' => __('View the full value of fields marked as sensitive. If disabled, see masked values.', 'superb-blocks'),
            'notes' => __('Add, view, and delete internal notes on submissions.', 'superb-blocks'),
            'spam' => __('View spam-filtered submissions and mark them as valid.', 'superb-blocks'),
            'create' => __('Insert new form blocks into posts and pages. If disabled, form blocks are hidden from the block inserter.', 'superb-blocks'),
            'edit' => __('Allow editing of form field structure, labels, display settings, colors, and validation rules on existing forms.', 'superb-blocks'),
            'configure' => __('Allow editing of sensitive form settings: email recipients, anti-spam, integrations, data storage, redirect URLs, and the sensitive field attribute.', 'superb-blocks'),
        );

        // Split capabilities into groups
        $submission_caps = array('view', 'delete', 'export', 'sensitive', 'notes', 'spam');
        $editor_caps = array('create', 'edit', 'configure');
    ?>
        <!-- Section A: Access Control -->
        <div class="superbaddons-settings-section">
            <div class="superbaddons-section-header superbaddons-section-header--sticky">
                <h4 class="superbaddons-element-flex-center superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0">
                    <img class="superbaddons-admindashboard-content-icon superbaddons-element-mr1" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-shield-check.svg'); ?>" aria-hidden="true" /><?php echo esc_html__("Access Control", "superb-blocks"); ?>
                </h4>
                <div class="superbaddons-save-wrap" id="superbaddons-permissions-save-wrap">
                    <span class="superbaddons-unsaved-label" style="display:none;"><?php echo esc_html__("You have unsaved changes", "superb-blocks"); ?></span>
                    <button type="button" class="superbaddons-element-button superbaddons-element-m0" id="superbaddons-save-permissions-btn"><?php echo esc_html__("Save Access Settings", "superb-blocks"); ?></button>
                </div>
            </div>
            <p class="superbaddons-element-text-xs superbaddons-element-text-gray"><?php echo esc_html__("Control which roles can access forms, form submissions, and related features. By default, all roles with editor access have full permissions.", "superb-blocks"); ?></p>

            <!-- Editor Restrictions Toggle -->
            <div class="superb-addons-checkbox-input-wrapper" style="margin-bottom: 16px;">
                <label class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-element-inlineflex-center superbaddons-element-relative">
                    <input type="checkbox" id="superbaddons-form-access-control-toggle" <?php echo $access_control_enabled ? 'checked' : ''; ?> />
                    <span class="superb-addons-checkbox-checkmark"><img class="superbaddons-admindashboard-content-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" /></span>
                    <span><?php echo esc_html__("Restrict form access by role", "superb-blocks"); ?></span>
                    <button type="button" class="superbaddons-help-toggle" aria-label="<?php echo esc_attr__('Toggle help text', 'superb-blocks'); ?>">i</button>
                </label>
                <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-help-text superbaddons-help-text--expanded">
                    <?php echo esc_html__("When disabled, all roles with editor access have full form and forms page permissions by default.", "superb-blocks"); ?>
                    <span style="display: block; margin-top: 4px;"><?php echo esc_html__("When enabled, each role must be granted permissions explicitly. Only administrators and roles with explicit permissions can access forms, create new form blocks, or modify settings on existing form blocks.", "superb-blocks"); ?></span>
                </p>
            </div>

            <div class="superbaddons-role-cards-actions">
                <button type="button" class="superbaddons-element-colorlink" id="superbaddons-roles-expand-all"><?php echo esc_html__("Expand All", "superb-blocks"); ?></button>
                <span class="superbaddons-role-cards-actions-separator"></span>
                <button type="button" class="superbaddons-element-colorlink" id="superbaddons-roles-collapse-all"><?php echo esc_html__("Collapse All", "superb-blocks"); ?></button>
            </div>

            <div class="superbaddons-role-cards">
                <!-- Administrator card (locked) -->
                <details class="superbaddons-role-card superbaddons-role-card--locked">
                    <summary class="superbaddons-role-card-header">
                        <span class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-element-text-800"><?php echo esc_html__("Administrator", "superb-blocks"); ?></span>
                        <div class="superbaddons-role-badges">
                            <span class="superbaddons-role-badge superbaddons-role-badge--green"><?php echo esc_html__("Full Forms Page Access", "superb-blocks"); ?></span>
                            <span class="superbaddons-role-badge superbaddons-role-badge--green"><?php echo esc_html__("Full Block Access", "superb-blocks"); ?></span>
                        </div>
                    </summary>
                    <div class="superbaddons-role-card-caps">
                        <p class="superbaddons-default-access-notice superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Administrators always have full access to all form features.", "superb-blocks"); ?></p>
                        <span class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-text-700"><?php echo esc_html__("Forms Page Permissions", "superb-blocks"); ?></span>
                        <?php foreach ($submission_caps as $cap) : ?>
                            <div class="superb-addons-checkbox-input-wrapper">
                                <label class="spbaddons-checkbox-wrap superbaddons-element-text-gray">
                                    <input type="checkbox" checked disabled />
                                    <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                    <?php echo esc_html(isset($cap_labels[$cap]) ? $cap_labels[$cap] : $cap); ?>
                                    <button type="button" class="superbaddons-help-toggle" aria-label="<?php echo esc_attr__('Toggle help text', 'superb-blocks'); ?>">i</button>
                                </label>
                                <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-help-text"><?php echo esc_html(isset($cap_descriptions[$cap]) ? $cap_descriptions[$cap] : ''); ?></p>
                            </div>
                        <?php endforeach; ?>
                        <div class="superbaddons-editor-caps-group">
                            <span class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-text-700"><?php echo esc_html__("Form Block Permissions", "superb-blocks"); ?></span>
                            <?php foreach ($editor_caps as $cap) : ?>
                                <div class="superb-addons-checkbox-input-wrapper">
                                    <label class="spbaddons-checkbox-wrap superbaddons-element-text-gray">
                                        <input type="checkbox" checked disabled />
                                        <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                        <?php echo esc_html(isset($cap_labels[$cap]) ? $cap_labels[$cap] : $cap); ?>
                                        <button type="button" class="superbaddons-help-toggle" aria-label="<?php echo esc_attr__('Toggle help text', 'superb-blocks'); ?>">i</button>
                                    </label>
                                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-help-text"><?php echo esc_html(isset($cap_descriptions[$cap]) ? $cap_descriptions[$cap] : ''); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
                <!-- Configurable role cards -->
                <?php foreach ($configurable_roles as $role_slug => $role_name) :
                    $role_obj = get_role($role_slug);
                    $has_edit_posts = $role_obj && $role_obj->has_cap('edit_posts');
                    $has_default_access = !$access_control_enabled && $has_edit_posts;
                ?>
                    <details class="superbaddons-role-card" data-role="<?php echo esc_attr($role_slug); ?>" data-has-editor="<?php echo $has_edit_posts ? '1' : '0'; ?>">
                        <summary class="superbaddons-role-card-header">
                            <span class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-element-text-800"><?php echo esc_html($role_name); ?></span>
                            <div class="superbaddons-role-badges"></div>
                        </summary>
                        <div class="superbaddons-role-card-caps">
                            <?php if (!$has_edit_posts) : ?>
                                <p class="superbaddons-default-access-notice superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("This role does not have editor access. Form block permissions are not available.", "superb-blocks"); ?></p>
                            <?php elseif ($has_default_access) : ?>
                                <p class="superbaddons-default-access-notice superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("This role has full access by default. Enable access control above to customize permissions.", "superb-blocks"); ?></p>
                            <?php else : ?>
                                <p class="superbaddons-default-access-notice superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Configure which form features this role can access.", "superb-blocks"); ?></p>
                            <?php endif; ?>
                            <span class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-text-700"><?php echo esc_html__("Forms Page Permissions", "superb-blocks"); ?></span>
                            <?php foreach ($submission_caps as $cap) :
                                $checked = isset($this->FormPermissions[$role_slug][$cap]) && $this->FormPermissions[$role_slug][$cap];
                            ?>
                                <div class="superb-addons-checkbox-input-wrapper">
                                    <label class="spbaddons-checkbox-wrap superbaddons-element-text-gray">
                                        <input type="checkbox" class="superbaddons-permission-checkbox"
                                            data-role="<?php echo esc_attr($role_slug); ?>"
                                            data-cap="<?php echo esc_attr($cap); ?>"
                                            data-saved="<?php echo $checked ? '1' : '0'; ?>"
                                            <?php echo ($has_default_access || $checked) ? 'checked' : ''; ?>
                                            <?php echo $has_default_access ? 'disabled' : ''; ?> />
                                        <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                        <?php echo esc_html(isset($cap_labels[$cap]) ? $cap_labels[$cap] : $cap); ?>
                                        <button type="button" class="superbaddons-help-toggle" aria-label="<?php echo esc_attr__('Toggle help text', 'superb-blocks'); ?>">i</button>
                                    </label>
                                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-help-text"><?php echo esc_html(isset($cap_descriptions[$cap]) ? $cap_descriptions[$cap] : ''); ?></p>
                                </div>
                            <?php endforeach; ?>
                            <div class="superbaddons-editor-caps-group" style="<?php echo ($access_control_enabled || $has_default_access || !$has_edit_posts) ? '' : 'display:none;'; ?>">
                                <span class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-text-700"><?php echo esc_html__("Form Block Permissions", "superb-blocks"); ?></span>
                                <?php foreach ($editor_caps as $cap) :
                                    $checked = isset($this->FormPermissions[$role_slug][$cap]) && $this->FormPermissions[$role_slug][$cap];
                                    $force_disabled = $has_default_access || !$has_edit_posts;
                                ?>
                                    <div class="superb-addons-checkbox-input-wrapper<?php echo !$has_edit_posts ? ' superbaddons-cap-disabled' : ''; ?>">
                                        <label class="spbaddons-checkbox-wrap superbaddons-element-text-gray">
                                            <input type="checkbox" class="superbaddons-permission-checkbox"
                                                data-role="<?php echo esc_attr($role_slug); ?>"
                                                data-cap="<?php echo esc_attr($cap); ?>"
                                                data-saved="<?php echo $checked ? '1' : '0'; ?>"
                                                <?php echo ($has_default_access || $checked) && $has_edit_posts ? 'checked' : ''; ?>
                                                <?php echo $force_disabled ? 'disabled' : ''; ?> />
                                            <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                            <?php echo esc_html(isset($cap_labels[$cap]) ? $cap_labels[$cap] : $cap); ?>
                                            <button type="button" class="superbaddons-help-toggle" aria-label="<?php echo esc_attr__('Toggle help text', 'superb-blocks'); ?>">i</button>
                                        </label>
                                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-help-text"><?php echo esc_html(isset($cap_descriptions[$cap]) ? $cap_descriptions[$cap] : ''); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Section B: Outgoing Email -->
        <div class="superbaddons-settings-section">
            <div class="superbaddons-section-header">
                <h4 class="superbaddons-element-flex-center superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0">
                    <img class="superbaddons-admindashboard-content-icon superbaddons-element-mr1" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-envelope-simple.svg'); ?>" aria-hidden="true" /><?php echo esc_html__("Outgoing Email", "superb-blocks"); ?>
                </h4>
                <div class="superbaddons-save-wrap" id="superbaddons-email-save-wrap">
                    <span class="superbaddons-unsaved-label" style="display:none;"><?php echo esc_html__("You have unsaved changes", "superb-blocks"); ?></span>
                    <button type="button" class="superbaddons-element-button superbaddons-element-m0" id="superbaddons-save-default-email-btn"><?php echo esc_html__("Save Email Settings", "superb-blocks"); ?></button>
                </div>
            </div>
            <p class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-help-text-inline"><?php echo esc_html__("Configure sender details for all form notification emails.", "superb-blocks"); ?></p>

            <div class="superbaddons-general-settings">
                <div class="superbaddons-setting-row">
                    <div class="superbaddons-setting-row-label">
                        <label class="superbaddons-element-text-xs superbaddons-element-text-gray" for="superbaddons-default-from-name"><?php echo esc_html__('"From" Name', "superb-blocks"); ?></label>
                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("The sender name used in form notification emails.", "superb-blocks"); ?></p>
                    </div>
                    <div class="superbaddons-setting-row-control">
                        <input id="superbaddons-default-from-name" type="text" class="superbaddons-input-api-key-input"
                            value="<?php echo esc_attr(isset($this->DefaultEmail['from_name']) ? $this->DefaultEmail['from_name'] : ''); ?>"
                            placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>"
                            maxlength="100" />
                        <p class="superbaddons-field-error" id="superbaddons-from-name-error"></p>
                    </div>
                </div>

                <div class="superbaddons-setting-row">
                    <div class="superbaddons-setting-row-label">
                        <label class="superbaddons-element-text-xs superbaddons-element-text-gray" for="superbaddons-default-from-email"><?php echo esc_html__('"From" Email', "superb-blocks"); ?></label>
                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("The sender address used in form notification emails.", "superb-blocks"); ?></p>
                    </div>
                    <div class="superbaddons-setting-row-control">
                        <input id="superbaddons-default-from-email" type="email" class="superbaddons-input-api-key-input"
                            value="<?php echo esc_attr(isset($this->DefaultEmail['from_email']) ? $this->DefaultEmail['from_email'] : ''); ?>"
                            placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" />
                        <p class="superbaddons-field-error" id="superbaddons-from-email-error"></p>
                    </div>
                </div>

            </div>
        </div>

        <!-- Section C: Data Retention -->
        <div class="superbaddons-settings-section">
            <h4 class="superbaddons-element-flex-center superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0">
                <img class="superbaddons-admindashboard-content-icon superbaddons-element-mr1" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-clock-countdown.svg'); ?>" aria-hidden="true" /><?php echo esc_html__("Data Retention", "superb-blocks"); ?>
            </h4>
            <p class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-help-text-inline"><?php echo esc_html__("Automatically delete old submissions for GDPR compliance. Spam entries have their own 30-day purge cycle.", "superb-blocks"); ?></p>

            <div class="superbaddons-general-settings">
                <div class="superbaddons-setting-row">
                    <div class="superbaddons-setting-row-label">
                        <label class="superbaddons-element-text-xs superbaddons-element-text-gray" for="superbaddons-data-retention-select"><?php echo esc_html__("Delete submissions", "superb-blocks"); ?></label>
                    </div>
                    <div class="superbaddons-setting-row-control">
                        <div class="superbaddons-data-retention-wrapper">
                            <select id="superbaddons-data-retention-select" class="superbaddons-data-retention-select">
                                <option value="0" <?php selected($this->DataRetentionDays, 0); ?>><?php echo esc_html__("Never", "superb-blocks"); ?></option>
                                <option value="30" <?php selected($this->DataRetentionDays, 30); ?>><?php echo esc_html__("After 30 days", "superb-blocks"); ?></option>
                                <option value="60" <?php selected($this->DataRetentionDays, 60); ?>><?php echo esc_html__("After 60 days", "superb-blocks"); ?></option>
                                <option value="90" <?php selected($this->DataRetentionDays, 90); ?>><?php echo esc_html__("After 90 days", "superb-blocks"); ?></option>
                                <option value="180" <?php selected($this->DataRetentionDays, 180); ?>><?php echo esc_html__("After 180 days", "superb-blocks"); ?></option>
                                <option value="365" <?php selected($this->DataRetentionDays, 365); ?>><?php echo esc_html__("After 365 days", "superb-blocks"); ?></option>
                            </select>
                            <?php if ($this->DataRetentionDays > 0) : ?>
                                <div class="superbaddons-data-retention-warning" id="superbaddons-data-retention-warning">
                                    <img class="superbaddons-admindashboard-content-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-warning-octagon.svg'); ?>" />
                                    <span class="superbaddons-element-text-xxs"><?php echo esc_html(sprintf(
                                                                                    /* translators: %d: number of days */
                                                                                    __('Submissions older than %d days are automatically deleted.', 'superb-blocks'),
                                                                                    $this->DataRetentionDays
                                                                                )); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    private function MaybeDisplayKeyIssue()
    {
        if (!$this->KeyHasIssue) {
            return;
        }
    ?>
        <div class="spbaddons-license-issue-wrapper">
            <?php printf('<img src="%s" alt="%s"/>', esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-warning-octagon.svg'), esc_attr__("Issue Detected", "superb-blocks")); ?>
            <p>
                <?php
                if (
                    $this->KeyStatus['expired']
                ) {
                    esc_html_e('It looks like your subscription has expired. Please renew your subscription or contact support for assistance.', "superb-blocks");
                } elseif (
                    !$this->KeyStatus['active']
                ) {
                    esc_html_e('It looks like your license key has been disabled. Please contact support for assistance.', "superb-blocks");
                } elseif (
                    !$this->KeyStatus['verified']
                ) {
                    esc_html_e('It seems that your license key verification for this website is no longer valid. Run the automatic scan on the Get Help page to resolve this automatically.', "superb-blocks");
                } elseif (
                    $this->KeyStatus['exceeded']
                ) {
                    esc_html_e('It looks like your license key has been activated on too many domains. Please renew your subscription, deactivate your license key on some of your domains, or contact support for assistance.', "superb-blocks");
                }
                ?>
                <?php if ($this->KeyStatus['expired'] || $this->KeyStatus['exceeded']): ?>
                    <br />
                    <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::DEFAULT, array("url" => "https://superbthemes.com/renew-subscription/"))); ?>" target="_blank" class="superbaddons-element-colorlink">
                        <?php echo esc_html__("Renew License", "superb-blocks"); ?>
                    </a>
                    <br />
                    <small>
                        <?php echo esc_html__("If you have already renewed your subscription, run the automatic scan to re-verify your license.", "superb-blocks"); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . DashboardController::SUPPORT)); ?>" class="superbaddons-element-colorlink">
                            <?php echo esc_html__("Run Automatic Scan", "superb-blocks"); ?>
                        </a>
                    </small>
                <?php endif; ?>
            </p>
            <?php
            if (!$this->KeyStatus['verified']) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . DashboardController::SUPPORT)); ?>" class="superbaddons-element-button">
                    <?php echo esc_html__("Run Automatic Scan", "superb-blocks"); ?>
                </a>
            <?php endif; ?>
        </div>
    <?php
    }

    private function MaybeDisplayPremiumPluginMissingNotice()
    {
        if (!KeyController::HasValidKey()) {
            return;
        }
    ?>
        <div id="spbaddons-premium-plugin-missing-notice" class="spbaddons-license-issue-wrapper" style="display:none;">
            <?php printf('<img src="%s" alt="%s"/>', esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-warning-octagon.svg'), esc_attr__("Premium Plugin Required", "superb-blocks")); ?>
            <p>
                <?php esc_html_e('Your license key is active, but the required premium plugin is not installed on this site. Some premium features will not be available until it is installed and activated.', "superb-blocks"); ?>
            </p>
            <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::DEFAULT, array("url" => "https://superbthemes.com/license-key/"))); ?>" target="_blank" rel="noopener" class="superbaddons-element-button">
                <?php echo esc_html__("View Installation Guide", "superb-blocks"); ?>
            </a>
        </div>
    <?php
    }

    private function MaybeDisplayRewriteIssue()
    {
        if (!RewriteCheckController::HasDetectedIssue()) {
            return;
        }
    ?>
        <div class="spbaddons-license-issue-wrapper">
            <?php printf('<img src="%s" alt="%s"/>', esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-warning-octagon.svg'), esc_attr__("Issue Detected", "superb-blocks")); ?>
            <p>
                <?php esc_html_e('A permalink configuration issue has been detected that may prevent license key activation and other features from working correctly. This can be resolved automatically on the Get Help page.', "superb-blocks"); ?>
            </p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . DashboardController::SUPPORT)); ?>" class="superbaddons-element-button">
                <?php echo esc_html__("Go to Get Help", "superb-blocks"); ?>
            </a>
        </div>
<?php
    }
}
