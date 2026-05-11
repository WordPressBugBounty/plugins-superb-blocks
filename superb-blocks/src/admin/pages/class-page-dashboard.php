<?php

namespace SuperbAddons\Admin\Pages;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Controllers\DashboardController;
use SuperbAddons\Admin\Controllers\Wizard\WizardController;
use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Components\Admin\NewsletterForm;
use SuperbAddons\Components\Admin\PremiumBox;
use SuperbAddons\Components\Admin\ReviewBox;
use SuperbAddons\Components\Admin\SupportBox;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Controllers\OptionController;
use SuperbAddons\Data\Utils\Wizard\WizardActionParameter;
use SuperbAddons\Gutenberg\Controllers\GutenbergController;
use SuperbAddons\Gutenberg\Controllers\GutenbergEnhancementsController;
use SuperbAddons\Components\Admin\Modal;
use SuperbAddons\Gutenberg\Form\FormSettings;

class DashboardPage
{
    private $theme_designer_url;
    private $add_new_pages_url;
    private $custom_css_url;
    private $woocommerce_header_url;
    private $header_footer_url;
    private $forms_url;
    private $settings_modules_url;
    private $EditorOptions;
    private $EditorTotalCount = 0;
    private $EditorEnabledCount = 0;
    private $IntegrationCount = 0;
    private $IntegrationTotalCount = 0;
    private $DisabledBlocks = array();
    private $ActiveBlockCount = 0;
    private $TotalBlockCount = 0;
    private $WizardCompleted = false;

    public function __construct()
    {
        $this->theme_designer_url = WizardController::GetWizardURL(WizardActionParameter::INTRO);
        $this->add_new_pages_url = WizardController::GetWizardURL(WizardActionParameter::ADD_NEW_PAGES);
        $this->woocommerce_header_url = WizardController::GetWizardURL(WizardActionParameter::WOOCOMMERCE_HEADER);
        $this->header_footer_url = WizardController::GetWizardURL(WizardActionParameter::HEADER_FOOTER);
        $this->custom_css_url = add_query_arg(array('page' => DashboardController::ADDITIONAL_CSS), admin_url("admin.php"));
        $this->forms_url = add_query_arg(array('page' => DashboardController::FORMS), admin_url("admin.php"));
        $this->settings_modules_url = add_query_arg(array('page' => DashboardController::SETTINGS), admin_url("admin.php")) . '#modules';

        // Editor enhancement status: merged view for card display and counts
        // Dashboard shows all enhancements (including per-user ones like outlines)
        $this->EditorOptions = GutenbergEnhancementsController::GetEnhancementsOptions(get_current_user_id());
        $enhancement_keys = array(
            GutenbergEnhancementsController::HIGHLIGHTS_KEY,
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
        $this->EditorTotalCount = count($enhancement_keys);
        foreach ($enhancement_keys as $key) {
            if (isset($this->EditorOptions[$key]) && $this->EditorOptions[$key]) {
                $this->EditorEnabledCount++;
            }
        }

        // Integration count
        $this->IntegrationTotalCount++;
        if (FormSettings::HasValue(FormSettings::OPTION_MAILCHIMP_API_KEY)) {
            $this->IntegrationCount++;
        }
        $this->IntegrationTotalCount++;
        if (FormSettings::HasValue(FormSettings::OPTION_BREVO_API_KEY)) {
            $this->IntegrationCount++;
        }
        $this->IntegrationTotalCount++;
        if (FormSettings::HasValue(FormSettings::OPTION_GOOGLE_SHEETS_CLIENT_EMAIL) && FormSettings::HasValue(FormSettings::OPTION_GOOGLE_SHEETS_PRIVATE_KEY)) {
            $this->IntegrationCount++;
        }
        // CAPTCHA settings
        $this->IntegrationTotalCount++;
        if (FormSettings::HasValue(FormSettings::OPTION_HCAPTCHA_SITE_KEY) && FormSettings::HasValue(FormSettings::OPTION_HCAPTCHA_SECRET_KEY)) {
            $this->IntegrationCount++;
        }
        $this->IntegrationTotalCount++;
        if (FormSettings::HasValue(FormSettings::OPTION_RECAPTCHA_SITE_KEY) && FormSettings::HasValue(FormSettings::OPTION_RECAPTCHA_SECRET_KEY)) {
            $this->IntegrationCount++;
        }
        $this->IntegrationTotalCount++;
        if (FormSettings::HasValue(FormSettings::OPTION_TURNSTILE_SITE_KEY) && FormSettings::HasValue(FormSettings::OPTION_TURNSTILE_SECRET_KEY)) {
            $this->IntegrationCount++;
        }

        // Block status
        $this->DisabledBlocks = OptionController::GetDisabledBlocks();
        $this->TotalBlockCount = GutenbergController::GetDiscoverableBlockTotal();
        $this->ActiveBlockCount = GutenbergController::GetDiscoverableBlockActiveCount($this->DisabledBlocks);

        // Wizard completion status
        $this->WizardCompleted = WizardController::ThemeHasCompletedWizard();

        $this->Render();
    }

    private function Render()
    {
        $enhancement_dot = 'superbaddons-status-dot--gray';
        if ($this->EditorEnabledCount >= 4) {
            $enhancement_dot = 'superbaddons-status-dot--green';
        } elseif ($this->EditorEnabledCount > 0) {
            $enhancement_dot = 'superbaddons-status-dot--yellow';
        }
        $integration_dot = 'superbaddons-status-dot--gray';
        if ($this->IntegrationCount >= 1) {
            $integration_dot = 'superbaddons-status-dot--green';
        }
?>
        <!-- Welcome + Stats Strip -->
        <div class="superbaddons-dashboard-welcome-strip">
            <span class="superbaddons-dashboard-welcome-title"><?php echo esc_html__("Welcome to Superb Addons", "superb-blocks"); ?></span>
            <div class="superbaddons-dashboard-stat-items">
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo $this->ActiveBlockCount >= $this->TotalBlockCount ? 'superbaddons-status-dot--green' : ($this->ActiveBlockCount > 0 ? 'superbaddons-status-dot--yellow' : 'superbaddons-status-dot--gray'); ?>"></span>
                    <?php
                    /* translators: 1: number of active blocks, 2: total number of blocks */
                    echo esc_html(sprintf(__('%1$d/%2$d Blocks', 'superb-blocks'), $this->ActiveBlockCount, $this->TotalBlockCount));
                    ?>
                </span>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo esc_attr($enhancement_dot); ?>"></span>
                    <?php
                    /* translators: 1: number of enabled editor enhancements, 2: total number of editor enhancements */
                    echo esc_html(sprintf(__('%1$d/%2$d Enhancements', 'superb-blocks'), $this->EditorEnabledCount, $this->EditorTotalCount));
                    ?>
                </span>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot superbaddons-status-dot--purple"></span>
                    <?php echo esc_html__("200+ Patterns", "superb-blocks"); ?>
                </span>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo esc_attr($integration_dot); ?>"></span>
                    <?php
                    /* translators: 1: number of connected integrations, 2: total number of integrations */
                    echo esc_html(sprintf(__('%1$d/%2$d Integrations', 'superb-blocks'), $this->IntegrationCount, $this->IntegrationTotalCount));
                    ?>
                </span>
            </div>
        </div>

        <div class="superbaddons-admindashboard-sidebarlayout">
            <div class="superbaddons-admindashboard-sidebarlayout-left">

                <!-- Quick Actions -->
                <div class="superbaddons-dashboard-quick-actions">
                    <div class="superbaddons-dashboard-quick-action-card<?php echo !$this->WizardCompleted ? ' superbaddons-dashboard-quick-action-card--recommended' : ''; ?>" id="superbaddons-dashboard-theme-designer-card">
                        <?php if (!$this->WizardCompleted) : ?>
                            <span class="superbaddons-dashboard-recommended-badge"><?php echo esc_html__("Recommended", "superb-blocks"); ?></span>
                        <?php endif; ?>
                        <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/theme-designer-icon.svg'); ?>" aria-hidden="true" width="48" height="48" />
                        <h5 class="superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800"><?php echo esc_html__("Theme Designer", "superb-blocks"); ?></h5>
                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Design your entire site in minutes. Pick styles for header, footer, and pages, and the wizard builds it all.", "superb-blocks"); ?></p>
                        <a class="superbaddons-element-button" href="<?php echo esc_url($this->theme_designer_url); ?>"><?php echo esc_html__("Launch Theme Designer", "superb-blocks"); ?></a>
                    </div>
                    <div class="superbaddons-dashboard-quick-action-card">
                        <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/template-page-icon.svg'); ?>" aria-hidden="true" width="48" height="48" />
                        <h5 class="superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800"><?php echo esc_html__("Page Templates", "superb-blocks"); ?></h5>
                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Launch a new page in seconds from a library of professional designs.", "superb-blocks"); ?></p>
                        <a class="superbaddons-element-button" href="<?php echo esc_url($this->add_new_pages_url); ?>"><?php echo esc_html__("Add New Template Page", "superb-blocks"); ?></a>
                    </div>
                    <div class="superbaddons-dashboard-quick-action-card" id="superbaddons-dashboard-forms-card">
                        <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/gutenberg-patterns-icon.svg'); ?>" aria-hidden="true" width="48" height="48" />
                        <h5 class="superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800"><?php echo esc_html__("Forms", "superb-blocks"); ?></h5>
                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("A full form builder with file uploads, conditional logic, spam protection, and more. All free.", "superb-blocks"); ?></p>
                        <a class="superbaddons-element-button" href="<?php echo esc_url($this->forms_url); ?>"><?php echo esc_html__("Manage Forms", "superb-blocks"); ?></a>
                    </div>
                </div>

                <!-- Gutenberg Blocks -->
                <div class="superbaddons-settings-section">
                    <div class="superbaddons-dashboard-section-header">
                        <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Gutenberg Blocks", "superb-blocks"); ?></h4>
                        <span class="superbaddons-dashboard-count-badge"><?php
                        /* translators: 1: number of active blocks, 2: total number of blocks */
                        echo esc_html(sprintf(__('%1$d/%2$d Active', 'superb-blocks'), $this->ActiveBlockCount, $this->TotalBlockCount));
                        ?></span>
                    </div>
                    <div class="superbaddons-dashboard-block-grid">
                        <?php
                        $blocks = array(
                            array('slug' => 'ratings', 'icon' => 'purple-star.svg', 'name' => __('Rating Block', 'superb-blocks'), 'desc' => __('Add star ratings with partial fills to posts and pages.', 'superb-blocks')),
                            array('slug' => 'author-box', 'icon' => 'purple-identification-badge.svg', 'name' => __('About the Author', 'superb-blocks'), 'desc' => __('Showcase post authors with avatar, bio, and social links.', 'superb-blocks')),
                            array('slug' => 'table-of-contents', 'icon' => 'purple-list-bullets.svg', 'name' => __('Table of Contents', 'superb-blocks'), 'desc' => __('Auto-generated navigation from your headings.', 'superb-blocks')),
                            array('slug' => 'recent-posts', 'icon' => 'purple-note.svg', 'name' => __('Recent Posts', 'superb-blocks'), 'desc' => __('Display your latest posts with thumbnails and excerpts.', 'superb-blocks')),
                            array('slug' => 'google-maps', 'icon' => 'purple-pin.svg', 'name' => __('Google Maps', 'superb-blocks'), 'desc' => __('Embed interactive maps with no API key required.', 'superb-blocks')),
                            array('slug' => 'cover-image', 'icon' => 'purple-image.svg', 'name' => __('Cover Image', 'superb-blocks'), 'desc' => __('Hero sections with focal point control and responsive height.', 'superb-blocks')),
                            array('slug' => 'reveal-buttons', 'icon' => 'purple-pointing.svg', 'name' => __('Reveal Buttons', 'superb-blocks'), 'desc' => __('Click-to-reveal buttons for coupons and hidden content.', 'superb-blocks')),
                            array('slug' => 'accordion', 'icon' => 'accordion-icon-purple.svg', 'name' => __('Toggle', 'superb-blocks'), 'desc' => __('Collapsible toggle section for FAQs and hiding content.', 'superb-blocks')),
                            array('slug' => 'carousel', 'icon' => 'icon-carousel.svg', 'name' => __('Carousel Slider', 'superb-blocks'), 'desc' => __('Content carousel with arrow and dot navigation.', 'superb-blocks')),
                            array('slug' => 'countdown', 'icon' => 'icon-countdown.svg', 'name' => __('Countdown', 'superb-blocks'), 'desc' => __('Countdown timer with 6 visual styles.', 'superb-blocks')),
                            array('slug' => 'progress-bar', 'icon' => 'icon-progressbar.svg', 'name' => __('Progress Bar', 'superb-blocks'), 'desc' => __('Progress indicators with bars, circles, and milestones.', 'superb-blocks')),
                            array('slug' => 'popup', 'icon' => 'icon-popup.svg', 'name' => __('Popup', 'superb-blocks'), 'desc' => __('Create popups with any content and smart trigger options.', 'superb-blocks')),
                            array('slug' => 'form', 'icon' => 'icon-form.svg', 'name' => __('Form', 'superb-blocks'), 'desc' => __('Form builder with validation, file uploads, and integrations.', 'superb-blocks')),
                            array('slug' => 'multistep-form', 'toggle_slug' => 'form', 'icon' => 'icon-multistepform.svg', 'name' => __('Multi-Step Form', 'superb-blocks'), 'desc' => __('Split forms into steps with progress tracking.', 'superb-blocks'), 'premium' => true),
                            array('slug' => 'add-to-cart', 'icon' => 'purple-shopping-cart.svg', 'name' => __('Add to Cart', 'superb-blocks'), 'desc' => __('AJAX button that adds WooCommerce products to the cart with optional popup trigger.', 'superb-blocks'), 'requires' => 'woocommerce'),
                            array('slug' => 'buy-now', 'toggle_slug' => 'add-to-cart', 'icon' => 'purple-shopping-cart-simple.svg', 'name' => __('Buy Now', 'superb-blocks'), 'desc' => __('Direct-checkout button that skips the cart and takes customers straight to checkout.', 'superb-blocks'), 'premium' => true, 'requires' => 'woocommerce'),
                        );
                        foreach ($blocks as $block) :
                            $toggle_slug = isset($block['toggle_slug']) ? $block['toggle_slug'] : $block['slug'];
                            $is_disabled = in_array($toggle_slug, $this->DisabledBlocks, true);
                        ?>
                            <div class="superbaddons-dashboard-block-card <?php echo $is_disabled ? 'superbaddons-dashboard-block-card--disabled' : ''; ?>">
                                <?php if (!empty($block['premium']) || $is_disabled || !empty($block['requires'])) : ?>
                                    <div class="superbaddons-dashboard-card-badges">
                                        <?php if (!empty($block['premium'])) : ?>
                                            <span class="superbaddons-dashboard-premium-badge"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-crown.svg'); ?>" aria-hidden="true" /><?php echo esc_html__("Premium", "superb-blocks"); ?></span>
                                        <?php elseif ($is_disabled) : ?>
                                            <span class="superbaddons-dashboard-disabled-badge"><?php echo esc_html__("Disabled", "superb-blocks"); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($block['requires'])) $this->RenderIntegrationBadge($block['requires']); ?>
                                    </div>
                                <?php endif; ?>
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/' . $block['icon']); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html($block['name']); ?></strong>
                                <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html($block['desc']); ?></p>
                            </div>
                        <?php endforeach; ?>
                        <?php $this->RenderSuggestCard(
                            __('Have a block idea?', 'superb-blocks'),
                            __('We\'re always looking for new block ideas. Let us know what you\'d like to see.', 'superb-blocks')
                        ); ?>
                    </div>
                    <a href="<?php echo esc_url($this->settings_modules_url); ?>" class="superbaddons-element-colorlink superbaddons-dashboard-configure-link"><?php echo esc_html__("Configure in Settings", "superb-blocks"); ?> &rarr;</a>
                </div>

                <!-- Editor Enhancements -->
                <div class="superbaddons-settings-section">
                    <div class="superbaddons-dashboard-section-header">
                        <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Editor Enhancements", "superb-blocks"); ?></h4>
                        <span class="superbaddons-dashboard-count-badge"><?php
                        /* translators: 1: number of enabled editor enhancements, 2: total number of editor enhancements */
                        echo esc_html(sprintf(__('%1$d/%2$d Active', 'superb-blocks'), $this->EditorEnabledCount, $this->EditorTotalCount));
                        ?></span>
                    </div>
                    <div class="superbaddons-dashboard-enhancement-grid">
                        <?php
                        $enhancements = array(
                            array('icon' => 'purple-selection-plus.svg', 'name' => __('Editor Outlines', 'superb-blocks'), 'desc' => __('Outlines around blocks and child blocks to easily visualize your layout structure.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::HIGHLIGHTS_KEY),
                            array('icon' => 'devices-duotone.svg', 'name' => __('Responsive Controls', 'superb-blocks'), 'desc' => __('Per-device visibility, spacing, font size, alignment, and layout overrides for any block.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::RESPONSIVE_KEY),
                            array('icon' => 'sneaker-move-duotone.svg', 'name' => __('Animations', 'superb-blocks'), 'desc' => __('40+ block animations, letter effects, and typing & counting animations.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::ANIMATIONS_KEY),
                            array('icon' => 'target-duotone.svg', 'name' => __('Block Conditions', 'superb-blocks'), 'desc' => __('Show or hide blocks based on user roles, schedules, content, WooCommerce data, and more.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::CONDITIONS_KEY),
                            array('icon' => 'purple-list-bullets.svg', 'name' => __('Navigation Enhancements', 'superb-blocks'), 'desc' => __('Mobile overlay and submenu improvements for the navigation block.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::NAVIGATION_KEY),
                            array('icon' => 'brackets-curly-duotone.svg', 'name' => __('Dynamic Content', 'superb-blocks'), 'desc' => __('Insert post data, author info, and custom fields dynamically into any text.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::DYNAMIC_CONTENT_KEY),
                            array('icon' => 'purple-aa.svg', 'name' => __('Rich Text', 'superb-blocks'), 'desc' => __('Rich text enhancements such as justify text alignment for text-based blocks.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::RICHTEXT_KEY),
                            array('icon' => 'purple-chat.svg', 'name' => __('Extra Social Icons', 'superb-blocks'), 'desc' => __('Adds 20+ additional icons to the core Social Icons block (Bilibili, Ko-fi, Signal, Slack, Steam, Substack, and more).', 'superb-blocks'), 'key' => GutenbergEnhancementsController::SOCIAL_ICONS_KEY),
                            array('icon' => 'purple-gauge.svg', 'name' => __('Admin Shortcuts', 'superb-blocks'), 'desc' => __('Adds handy block theme shortcuts such as Edit Front Page and Style Book.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::DASHBOARD_SHORTCUTS_KEY),
                            array('icon' => 'pushpin-duotone.svg', 'name' => __('Sticky Positioning', 'superb-blocks'), 'desc' => __('Pin any block to the top or bottom of the screen as visitors scroll, with scope and per-device control.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::STICKY_KEY),
                            array('icon' => 'stack-simple-duotone.svg', 'name' => __('Stacking Order', 'superb-blocks'), 'desc' => __('Adds a z-index control to the Advanced panel of any block, with quick presets for common stacking levels.', 'superb-blocks'), 'key' => GutenbergEnhancementsController::Z_INDEX_KEY),
                        );
                        foreach ($enhancements as $enh) :
                            $has_key = isset($enh['key']);
                            $is_enabled = $has_key && isset($this->EditorOptions[$enh['key']]) && $this->EditorOptions[$enh['key']];
                        ?>
                            <div class="superbaddons-dashboard-enhancement-card <?php echo $is_enabled ? 'superbaddons-dashboard-enhancement-card--enabled' : ''; ?>">
                                <div class="superbaddons-dashboard-enhancement-card-header">
                                    <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/' . $enh['icon']); ?>" aria-hidden="true" />
                                    <?php if ($is_enabled) : ?>
                                        <span class="superbaddons-integration-card-badge superbaddons-integration-card-badge--connected"><?php echo esc_html__("Enabled", "superb-blocks"); ?></span>
                                    <?php else : ?>
                                        <span class="superbaddons-integration-card-badge"><?php echo esc_html__("Disabled", "superb-blocks"); ?></span>
                                    <?php endif; ?>
                                </div>
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html($enh['name']); ?></strong>
                                <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html($enh['desc']); ?></p>
                            </div>
                        <?php endforeach; ?>
                        <?php $this->RenderSuggestCard(
                            __('Suggest an enhancement', 'superb-blocks'),
                            __('Have an idea for a new editor enhancement? We\'d love to hear from you.', 'superb-blocks')
                        ); ?>
                    </div>
                    <a href="<?php echo esc_url($this->settings_modules_url); ?>" class="superbaddons-element-colorlink superbaddons-dashboard-configure-link"><?php echo esc_html__("Configure in Settings", "superb-blocks"); ?> &rarr;</a>
                </div>

                <!-- More Features -->
                <div class="superbaddons-settings-section">
                    <div class="superbaddons-dashboard-section-header">
                        <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("More Features", "superb-blocks"); ?></h4>
                    </div>
                    <div class="superbaddons-dashboard-feature-grid">
                        <div class="superbaddons-dashboard-feature-card" id="superbaddons-dashboard-design-library-card">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/superb-blocks.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html__("Design Library", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__("200+ pre-built patterns you can insert with one click.", "superb-blocks"); ?></p>
                            <button type="button" class="superbaddons-element-button superbaddons-element-button-sm" id="gutenberg-lib-modal-btn"><?php echo esc_html__("Explore Design Library", "superb-blocks"); ?></button>
                        </div>
                        <div class="superbaddons-dashboard-feature-card" id="superbaddons-dashboard-custom-css-card">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/dashboard-custom-css.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html__("Custom CSS", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__("Target specific pages or templates with CSS blocks, auto-combined and loaded only where needed.", "superb-blocks"); ?></p>
                            <a href="<?php echo esc_url($this->custom_css_url); ?>" class="superbaddons-element-button superbaddons-element-button-sm"><?php echo esc_html__("Manage CSS", "superb-blocks"); ?></a>
                        </div>
                        <div class="superbaddons-dashboard-feature-card">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/dashboard-header-footer.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html__("Header & Footer Templates", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__("Select your website's header and footer templates.", "superb-blocks"); ?></p>
                            <a href="<?php echo esc_url($this->header_footer_url); ?>" class="superbaddons-element-button superbaddons-element-button-sm"><?php echo esc_html__("Select Header & Footer", "superb-blocks"); ?></a>
                        </div>
                        <div class="superbaddons-dashboard-feature-card <?php echo !is_plugin_active('woocommerce/woocommerce.php') ? 'superbaddons-dashboard-feature-card--inactive' : ''; ?>">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/dashboard-wc-icon.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html__("WooCommerce Headers", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__("WooCommerce compatible header templates and navigation.", "superb-blocks"); ?></p>
                            <?php if (is_plugin_active('woocommerce/woocommerce.php')) : ?>
                                <a href="<?php echo esc_url($this->woocommerce_header_url); ?>" class="superbaddons-element-button superbaddons-element-button-sm"><?php echo esc_html__("Select Header", "superb-blocks"); ?></a>
                            <?php else : ?>
                                <span class="superbaddons-integration-card-badge"><?php echo esc_html__("Requires WooCommerce", "superb-blocks"); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="superbaddons-dashboard-feature-card <?php echo !defined('ELEMENTOR_VERSION') ? 'superbaddons-dashboard-feature-card--inactive' : ''; ?>">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/elementor-sections.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html__("Elementor Sections", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__("300+ pre-built Elementor sections for fast site building.", "superb-blocks"); ?></p>
                            <?php if (defined('ELEMENTOR_VERSION')) : ?>
                                <button type="button" class="superbaddons-element-button superbaddons-element-button-sm" id="elementor-lib-modal-btn"><?php echo esc_html__("Explore Sections", "superb-blocks"); ?></button>
                            <?php else : ?>
                                <span class="superbaddons-integration-card-badge"><?php echo esc_html__("Requires Elementor", "superb-blocks"); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php $this->RenderSuggestCard(
                            __('Have a feature idea?', 'superb-blocks'),
                            __('Tell us what features would make your workflow better.', 'superb-blocks'),
                            true
                        ); ?>
                    </div>
                </div>
            </div>
            <div class="superbaddons-admindashboard-sidebarlayout-right">
                <?php new NewsletterForm(__("Stay updated with new features and freebies.", "superb-blocks"), true); ?>
                <?php new ReviewBox(); ?>
                <?php new SupportBox(); ?>
                <?php new PremiumBox(AdminLinkSource::DEFAULT); ?>
            </div>
        </div>
        <?php
        new Modal();
    }

    private function RenderIntegrationBadge($integration)
    {
        if ($integration === 'woocommerce') :
        ?>
            <span class="superbaddons-dashboard-integration-badge superbaddons-dashboard-integration-badge--woo" title="<?php echo esc_attr__('Requires WooCommerce', 'superb-blocks'); ?>">
                <svg role="img" viewBox="0 9.58 24 4.84" xmlns="http://www.w3.org/2000/svg" aria-label="<?php echo esc_attr__('Requires WooCommerce', 'superb-blocks'); ?>">
                    <path d="M.754 9.58a.754.754 0 00-.754.758v2.525c0 .42.339.758.758.758h3.135l1.431.799-.326-.799h2.373a.757.757 0 00.758-.758v-2.525a.757.757 0 00-.758-.758H.754zm2.709.445h.03c.065.001.124.023.179.067a.26.26 0 01.103.19.29.29 0 01-.033.16c-.13.239-.236.64-.322 1.199-.083.541-.114.965-.094 1.267a.392.392 0 01-.039.219.213.213 0 01-.176.12c-.086.006-.177-.034-.263-.124-.31-.316-.555-.788-.735-1.416-.216.425-.375.744-.478.957-.196.376-.363.568-.502.578-.09.007-.166-.069-.233-.228-.17-.436-.352-1.277-.548-2.524a.297.297 0 01.054-.222c.047-.064.116-.095.21-.102.169-.013.265.065.288.238.103.695.217 1.284.336 1.766l.727-1.387c.066-.126.15-.192.25-.199.146-.01.237.083.273.28.083.441.188.817.315 1.136.086-.844.233-1.453.44-1.828a.255.255 0 01.218-.147zm1.293.36c.056 0 .116.006.18.02.232.05.411.177.53.386.107.18.161.395.161.654 0 .343-.087.654-.26.94-.2.332-.459.5-.781.5a.88.88 0 01-.18-.022.763.763 0 01-.531-.384 1.287 1.287 0 01-.158-.659c0-.342.085-.655.258-.937.202-.333.462-.498.78-.498zm2.084 0c.056 0 .116.006.18.02.236.05.411.177.53.386.107.18.16.395.16.654 0 .343-.086.654-.259.94-.2.332-.459.5-.781.5a.88.88 0 01-.18-.022.763.763 0 01-.531-.384 1.287 1.287 0 01-.16-.659c0-.342.087-.655.26-.937.202-.333.462-.498.78-.498zm4.437.047c-.305 0-.546.102-.718.304-.173.203-.256.49-.256.856 0 .395.086.697.256.906.17.21.418.316.744.316.315 0 .559-.107.728-.316.17-.21.256-.504.256-.883s-.087-.673-.26-.879c-.176-.202-.424-.304-.75-.304zm-1.466.002a1.13 1.13 0 00-.84.326c-.223.22-.332.499-.332.838 0 .362.108.658.328.88.22.223.505.336.861.336.103 0 .22-.016.346-.052v-.54c-.117.034-.216.051-.303.051a.545.545 0 01-.422-.177c-.106-.12-.16-.278-.16-.48 0-.19.053-.348.156-.468a.498.498 0 01.397-.181c.103 0 .212.015.332.049v-.537a1.394 1.394 0 00-.363-.045zm12.414 0a1.135 1.135 0 00-.84.326c-.223.22-.332.499-.332.838 0 .362.108.658.328.88.22.223.506.336.861.336.103 0 .22-.016.346-.052v-.54c-.116.034-.216.051-.303.051a.545.545 0 01-.422-.177c-.106-.12-.16-.278-.16-.48 0-.19.053-.348.156-.468a.498.498 0 01.397-.181c.103 0 .212.015.332.049v-.537a1.394 1.394 0 00-.363-.045zm-9.598.06l-.29 2.264h.579l.156-1.559.395 1.559h.412l.379-1.555.164 1.555h.603l-.304-2.264h-.791l-.12.508c-.03.13-.06.264-.087.4l-.067.352a29.97 29.97 0 00-.258-1.26h-.771zm2.768 0l-.29 2.264h.579l.156-1.559.396 1.559h.412l.375-1.555.165 1.555h.603l-.305-2.264h-.789l-.119.508c-.03.13-.06.264-.086.4l-.066.352c-.063-.352-.15-.771-.26-1.26h-.771zm3.988 0v2.264h.611v-1.031h.012l.494 1.03h.645l-.489-1.019a.61.61 0 00.37-.552.598.598 0 00-.25-.506c-.167-.123-.394-.186-.68-.186h-.713zm3.377 0v2.264H24v-.483h-.63v-.414h.54v-.468h-.54v-.416h.626v-.483H22.76zm-4.793.004v2.264h1.24v-.483h-.627v-.416h.541v-.468h-.54v-.415h.622v-.482h-1.236zm2.025.432c.146.003.25.025.313.072.063.046.091.12.091.227 0 .156-.135.236-.404.24v-.54zm-15.22.011c-.104 0-.205.069-.301.211a1.078 1.078 0 00-.2.639c0 .096.02.2.06.303.049.13.117.198.196.215.083.016.173-.02.27-.106.123-.11.205-.273.252-.492.016-.077.023-.16.023-.246 0-.097-.02-.2-.06-.303-.05-.13-.116-.198-.196-.215a.246.246 0 00-.045-.006zm2.083 0c-.103 0-.204.069-.3.211a1.078 1.078 0 00-.2.639c0 .096.02.2.06.303.049.13.117.198.196.215.083.016.173-.02.27-.106.123-.11.205-.273.252-.492.013-.077.023-.16.023-.246 0-.097-.02-.2-.06-.303-.05-.13-.116-.198-.196-.215a.246.246 0 00-.045-.006zm4.428.006c.233 0 .354.218.354.66-.004.273-.038.46-.098.553a.293.293 0 01-.262.139.266.266 0 01-.242-.139c-.056-.093-.084-.28-.084-.562 0-.436.11-.65.332-.65Z" />
                </svg>
            </span>
        <?php
        endif;
    }

    private function RenderSuggestCard($title, $description, $stacked = false)
    {
        ?>
        <div class="superbaddons-dashboard-suggest-card">
            <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-bulb.svg'); ?>" aria-hidden="true" />
            <div class="superbaddons-dashboard-suggest-card-content<?php echo $stacked ? ' superbaddons-dashboard-suggest-card-content-stacked' : ''; ?>">
                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html($title); ?></strong>
                <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html($description); ?></p>
                <?php if ($stacked) : ?>
                    <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::DEFAULT, array("url" => "https://superbthemes.com/contact/", "anchor" => "create-ticket"))); ?>" target="_blank" class="superbaddons-element-colorlink superbaddons-element-text-xxs"><?php echo esc_html__("Request Feature", "superb-blocks"); ?> &rarr;</a>
                <?php endif; ?>
            </div>
            <?php if (!$stacked) : ?>
                <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::DEFAULT, array("url" => "https://superbthemes.com/contact/", "anchor" => "create-ticket"))); ?>" target="_blank" class="superbaddons-element-colorlink superbaddons-element-text-xxs"><?php echo esc_html__("Request Feature", "superb-blocks"); ?> &rarr;</a>
            <?php endif; ?>
        </div>
<?php
    }
}
