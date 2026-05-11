<?php

namespace SuperbAddons\Admin\Pages;

defined('ABSPATH') || exit();

use SuperbAddons\Components\Admin\LinkBox;
use SuperbAddons\Components\Admin\ReviewBox;
use SuperbAddons\Admin\Controllers\DashboardController;
use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Components\Admin\FeatureRequestBox;
use SuperbAddons\Components\Admin\InputCheckbox;
use SuperbAddons\Components\Admin\Modal;
use SuperbAddons\Components\Admin\PremiumBox;
use SuperbAddons\Components\Admin\SupportBox;
use SuperbAddons\Components\Slots\CssBlocksBaseSlot;
use SuperbAddons\Components\Slots\CssBlocksExportSelectedSlot;
use SuperbAddons\Components\Slots\CssBlocksExportSingleSlot;
use SuperbAddons\Components\Slots\CssBlocksTargetSlot;
use SuperbAddons\Data\Controllers\CSSController;

class AdditionalCSSPage
{
    private $Blocks = array();
    private $CurrentCssBlock = false;
    private $IsCreating = false;

    public function __construct()
    {
        $this->Blocks = CSSController::GetBlocks();
        // Determine which type of page to display
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $view_type = isset($_GET['css-edit']) ? 'edit' : (isset($_GET['css-create']) ? 'create' : 'default');
        if ($view_type !== 'default') {
            if ($view_type === 'edit') {
                // No need for nonce verification here, as the user is not submitting any data
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $css_block_id = sanitize_text_field(wp_unslash($_GET['css-edit']));
                $this->CurrentCssBlock = isset($this->Blocks[$css_block_id]) ? $this->Blocks[$css_block_id] : false;
                if (!$this->CurrentCssBlock) {
                    $this->RenderMainPage();
                    return;
                }
                $this->CurrentCssBlock->id = $css_block_id;
            } else {
                $this->IsCreating = true;
            }

            add_action("admin_footer", array($this, 'RenderLivePreview'));

            $this->RenderEditPage();
        } else {
            $this->RenderMainPage();
        }
    }

    private function GetBlockStats()
    {
        $total = count($this->Blocks);
        $active = 0;
        $targets = array();
        foreach ($this->Blocks as $block) {
            if (isset($block->active) && $block->active) {
                $active++;
            }
            if (isset($block->selectors)) {
                foreach ($block->selectors as $selector) {
                    if (isset($selector->type)) {
                        $targets[$selector->type] = true;
                    }
                }
            }
        }
        $has_optimized = get_option('superb_addons_optimized_css', false);
        return array(
            'total' => $total,
            'active' => $active,
            'target_count' => count($targets),
            'is_optimized' => !empty($has_optimized),
        );
    }

    private function RenderEditPage()
    {
        $edit_or_create = $this->IsCreating ? __("Create CSS Block", "superb-blocks") : __("Edit CSS Block", "superb-blocks");
        $main_page_url = admin_url("admin.php?page=" . DashboardController::ADDITIONAL_CSS);
        $is_active = $this->IsCreating || ($this->CurrentCssBlock && $this->CurrentCssBlock->active);
?>
        <div class="superbaddons-admindashboard-content-box-large superbaddons-admindashboard-css-blocks">
            <!-- Editor Header -->
            <div class="superbaddons-css-editor-header">
                <div class="superbaddons-css-editor-header-left">
                    <a href="<?php echo esc_url($main_page_url); ?>" class="superbaddons-css-editor-back" aria-label="<?php echo esc_attr__("Back to CSS Blocks", "superb-blocks"); ?>">
                        <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-arrow-left.svg'); ?>" alt="" />
                    </a>
                    <h3 class="superbaddons-element-text-md superbaddons-element-text-800 superbaddons-element-m0 superbaddons-element-text-dark"><?php echo esc_html($edit_or_create); ?></h3>
                    <?php if (!$this->IsCreating) : ?>
                        <?php if ($is_active) : ?>
                            <span class="superbaddons-integration-card-badge superbaddons-integration-card-badge--connected"><?php echo esc_html__("Active", "superb-blocks"); ?></span>
                        <?php else : ?>
                            <span class="superbaddons-integration-card-badge"><?php echo esc_html__("Deactivated", "superb-blocks"); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="superbaddons-css-editor-header-right">
                    <button type="button" class="superbaddons-css-block-preview-btn superbaddons-element-button superbaddons-element-m0">
                        <img width="20" class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/preview.svg'); ?>" />
                        <?php echo esc_html__("Preview", "superb-blocks"); ?>
                    </button>
                    <button type="button" class="superbaddons-css-block-save-btn superbaddons-element-button spbaddons-admin-btn-success superbaddons-element-m0">
                        <svg class="superbaddons-element-button-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 256 256">
                            <path d="M216,91.31V208a8,8,0,0,1-8,8H176V152a8,8,0,0,0-8-8H88a8,8,0,0,0-8,8v64H48a8,8,0,0,1-8-8V48a8,8,0,0,1,8-8H164.69a8,8,0,0,1,5.65,2.34l43.32,43.31A8,8,0,0,1,216,91.31Z" opacity="0.2"></path>
                            <path d="M219.31,80,176,36.69A15.86,15.86,0,0,0,164.69,32H48A16,16,0,0,0,32,48V208a16,16,0,0,0,16,16H208a16,16,0,0,0,16-16V91.31A15.86,15.86,0,0,0,219.31,80ZM168,208H88V152h80Zm40,0H184V152a16,16,0,0,0-16-16H88a16,16,0,0,0-16,16v56H48V48H164.69L208,91.31ZM160,72a8,8,0,0,1-8,8H96a8,8,0,0,1,0-16h56A8,8,0,0,1,160,72Z"></path>
                        </svg>
                        <?php echo esc_html__("Save", "superb-blocks"); ?>
                    </button>
                </div>
            </div>

            <!-- Two-Panel Layout -->
            <div class="superbaddons-css-editor-panels">
                <!-- Left Panel: Settings -->
                <div class="superbaddons-css-editor-settings-panel">
                    <!-- Block Name -->
                    <div class="superbaddons-css-editor-section">
                        <label for="superbaddons-css-block-name-input" class="superbaddons-css-editor-section-label">
                            <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-file.svg'); ?>" alt="" />
                            <?php echo esc_html__("CSS Block Name", "superb-blocks"); ?>
                        </label>
                        <input type="text" id="superbaddons-css-block-name-input" class="superbaddons-element-input" placeholder="<?php echo esc_attr__("My CSS Block", "superb-blocks"); ?>" value="<?php echo $this->CurrentCssBlock ? esc_attr($this->CurrentCssBlock->name) : ""; ?>" maxlength="<?php echo esc_attr(CSSController::BLOCK_NAME_MAX_LENGTH); ?>" />
                    </div>

                    <!-- Target -->
                    <div class="superbaddons-css-editor-section superbaddons-css-block-input-options">
                        <label class="superbaddons-css-editor-section-label">
                            <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/target-duotone.svg'); ?>" alt="" />
                            <?php echo esc_html__("Target", "superb-blocks"); ?>
                        </label>
                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0 superbaddons-element-mb1"><?php echo esc_html__("Choose where this CSS will be applied. Separate CSS files are automatically generated and loaded only where needed.", "superb-blocks"); ?></p>
                        <div class="superbaddons-css-target-option superbaddons-css-target-option--active">
                            <?php new InputCheckbox("superbaddons-css-block-target-input-website", "full", __("Entire website", "superb-blocks"), __("When enabled, your CSS block will be applied to the entire frontend of your website.", "superb-blocks"), true); ?>
                        </div>
                        <div class="superbaddons-element-specific-select-wrapper superbaddons-css-block-specific-target-inputs" style="display: none;">
                            <div class="superbaddons-css-target-option">
                                <?php new InputCheckbox("superbaddons-css-block-target-input-frontpage", "front", __("Front page", "superb-blocks"), __("Applies CSS block to the front page.", "superb-blocks")); ?>
                            </div>
                            <?php new CssBlocksTargetSlot(); ?>
                        </div>
                    </div>

                    <!-- Block Actions (existing blocks only) -->
                    <div class="superbaddons-css-editor-section superbaddons-css-editor-danger-section superbaddons-created-css-block-options" <?php echo $this->IsCreating ? 'style="display:none;"' : ''; ?>>
                        <h5 class="superbaddons-danger-zone-title">
                            <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-warning-octagon.svg'); ?>" width="16" alt="" />
                            <?php echo esc_html__("Block Actions", "superb-blocks"); ?>
                        </h5>
                        <div class="superbaddons-danger-zone-item">
                            <div class="superbaddons-danger-zone-item-info">
                                <strong><?php echo $is_active ? esc_html__("Deactivate", "superb-blocks") : esc_html__("Activate", "superb-blocks"); ?></strong>
                                <p><?php echo esc_html__("Toggle this CSS block on or off.", "superb-blocks"); ?></p>
                            </div>
                            <?php if ($this->CurrentCssBlock && !$this->CurrentCssBlock->active) : ?>
                                <button class="superbaddons-activate-blocks-btn superbaddons-toggle-activate-blocks-btn superbaddons-element-button superbaddons-element-button-sm superbaddons-element-m0" disabled>
                                    <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/check-circle.svg'); ?>" />
                                    <?php echo esc_html__("Activate", "superb-blocks"); ?>
                                </button>
                            <?php else : ?>
                                <button class="superbaddons-deactivate-blocks-btn superbaddons-toggle-activate-blocks-btn superbaddons-element-button superbaddons-element-button-sm superbaddons-element-m0" disabled>
                                    <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/x-circle.svg'); ?>" />
                                    <?php echo esc_html__("Deactivate", "superb-blocks"); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <div class="superbaddons-danger-zone-item">
                            <div class="superbaddons-danger-zone-item-info">
                                <strong><?php echo esc_html__("Export", "superb-blocks"); ?></strong>
                                <p><?php echo esc_html__("Download this CSS block.", "superb-blocks"); ?></p>
                            </div>
                            <?php new CssBlocksExportSingleSlot(); ?>
                        </div>
                        <div class="superbaddons-danger-zone-item">
                            <div class="superbaddons-danger-zone-item-info">
                                <strong><?php echo esc_html__("Delete", "superb-blocks"); ?></strong>
                                <p><?php echo esc_html__("Permanently delete this CSS block.", "superb-blocks"); ?></p>
                            </div>
                            <button class="superbaddons-css-block-delete-btn superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-button-sm superbaddons-element-m0">
                                <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" />
                                <?php echo esc_html__("Delete", "superb-blocks"); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Right Panel: Code Editor -->
                <div class="superbaddons-css-editor-code-panel">
                    <label for="superbaddons-css-block-css-input" class="superbaddons-css-editor-code-label">
                        <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-paint-brush.svg'); ?>" alt="" />
                        <?php echo esc_html__("CSS", "superb-blocks"); ?>
                    </label>
                    <div class="superbaddons-css-block-errors-wrapper superbaddons-element-text-xs spbaddons-admin-text-danger" style="display:none;">
                        <?php echo esc_html__("Your CSS Block cannot be saved while errors are present in your CSS.", "superb-blocks"); ?>
                    </div>
                    <div class="superbaddons-css-block-allow-unsafe-css-wrapper" style="display:none;">
                        <?php new InputCheckbox("superbaddons-css-block-allow-unsafe-css", "allow-unsafe-css-action", __("Allow Unsafe CSS", "superb-blocks"), __("Your CSS contains unsafe CSS rules. Please double check that the links and URLs used in imports etc. are trusted. In order to save your CSS block containing unsafe CSS, this setting must be enabled. ", "superb-blocks"), false, '/img/color-warning-octagon.svg'); ?>
                    </div>
                    <div class="superbaddons-css-block-css-input-wrapper-outer">
                        <div class="superbaddons-css-block-css-input-wrapper">
                            <textarea id="superbaddons-css-block-css-input" class="superbaddons-element-textarea" rows="10"><?php echo $this->CurrentCssBlock ? esc_html($this->CurrentCssBlock->css) : ""; ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hidden cancel link for JS reference -->
            <a href="<?php echo esc_url($main_page_url); ?>" class="superbaddons-css-block-cancel-btn" style="display:none;"></a>
        </div>
        <?php new Modal(); ?>
        <?php if ($this->CurrentCssBlock) : ?>
            <script>
                const superbaddons_init_css_block_selectors = <?php echo wp_json_encode($this->CurrentCssBlock->selectors, JSON_HEX_TAG); ?>;
                const superbaddons_init_css_block_id = <?php echo wp_json_encode($this->CurrentCssBlock->id, JSON_HEX_TAG); ?>;
            </script>
        <?php endif; ?>
    <?php
    }

    public function RenderLivePreview()
    {
    ?>
        <div id="superbaddons-css-block-live-preview" style="display:none;">
            <div class="superbaddons-css-block-live-preview-container">
                <div class="superbaddons-css-live-preview-menu">
                    <div class="superbaddons-css-live-preview-menu-left">
                        <span class="superbaddons-element-text-sm superbaddons-element-text-800"><?php echo esc_html__("LIVE PREVIEW", "superb-blocks"); ?></span>
                    </div>
                    <div class="superbaddons-css-live-preview-menu-center">
                        <button type="button" class="superbaddons-preview-device-btn superbaddons-preview-device-btn--active" data-device="desktop" aria-label="<?php echo esc_attr__("Desktop preview", "superb-blocks"); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="2" y="3" width="20" height="14" rx="2" stroke="#546E7A" stroke-width="2" />
                                <path d="M8 21h8M12 17v4" stroke="#546E7A" stroke-width="2" stroke-linecap="round" />
                            </svg>
                        </button>
                        <button type="button" class="superbaddons-preview-device-btn" data-device="tablet" aria-label="<?php echo esc_attr__("Tablet preview", "superb-blocks"); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="4" y="2" width="16" height="20" rx="2" stroke="#546E7A" stroke-width="2" />
                                <circle cx="12" cy="18" r="1" fill="#546E7A" />
                            </svg>
                        </button>
                        <button type="button" class="superbaddons-preview-device-btn" data-device="mobile" aria-label="<?php echo esc_attr__("Mobile preview", "superb-blocks"); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="6" y="2" width="12" height="20" rx="2" stroke="#546E7A" stroke-width="2" />
                                <circle cx="12" cy="18" r="1" fill="#546E7A" />
                            </svg>
                        </button>
                    </div>
                    <div class="superbaddons-css-live-preview-menu-right">
                        <button id="superbaddons-preview-reload-button" type="button" class="superbaddons-element-button superbaddons-element-button-small superbaddons-element-m0">
                            <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/arrow-clockwise-duotone.svg'); ?>" />
                            <?php echo esc_html__("Reload", "superb-blocks"); ?>
                        </button>
                        <div class="superbaddons-live-preview-close-btn"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/x.svg"); ?>" alt="<?php echo esc_attr__("Close", "superb-blocks"); ?>" /></div>
                    </div>
                </div>
                <p class="superbaddons-css-live-preview-tip superbaddons-element-text-xxs superbaddons-element-text-gray"><strong><?php echo esc_html__("Tip:", "superb-blocks"); ?></strong> <?php echo esc_html__("CSS changes are only visible inside this preview. Target settings are not active in preview mode.", "superb-blocks"); ?></p>
                <p class="superbaddons-css-live-preview-tip superbaddons-element-text-xxs superbaddons-element-text-gray"><strong><?php echo esc_html__("Note:", "superb-blocks"); ?></strong> <?php echo esc_html__("Previously saved CSS is already applied to the page via a generated stylesheet. The preview adds your current editor changes on top. To see the effect of removing CSS, save the block first and then reload the preview.", "superb-blocks"); ?></p>
                <div class="superbaddons-spinner-wrapper">
                    <img class="spbaddons-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/blocks-spinner.svg"); ?>" />
                </div>
                <div class="superbaddons-css-block-live-preview-content">
                    <div class="superbaddons-css-block-preview-input">
                    </div>
                    <div class="superbaddons-css-live-preview-frame-wrapper">
                        <iframe frameborder="0" src="" data-src="<?php echo esc_url(wp_customize_url()); ?>" sandbox="allow-forms allow-modals allow-orientation-lock allow-pointer-lock allow-popups allow-popups-to-escape-sandbox allow-presentation allow-same-origin allow-scripts"></iframe>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    private function RenderMainPage()
    {
        $stats = $this->GetBlockStats();
        $active_dot = 'superbaddons-status-dot--gray';
        if ($stats['active'] > 0 && $stats['active'] >= $stats['total']) {
            $active_dot = 'superbaddons-status-dot--green';
        } elseif ($stats['active'] > 0) {
            $active_dot = 'superbaddons-status-dot--yellow';
        }
        $optimized_dot = $stats['is_optimized'] ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--gray';
    ?>
        <!-- Status Strip -->
        <div class="superbaddons-dashboard-welcome-strip">
            <span class="superbaddons-dashboard-welcome-title"><?php echo esc_html__("Custom CSS", "superb-blocks"); ?></span>
            <div class="superbaddons-dashboard-stat-items">
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo esc_attr($active_dot); ?>"></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %1$d: active count, %2$d: total count */
                        __('%1$d/%2$d Active', 'superb-blocks'),
                        $stats['active'],
                        $stats['total']
                    )); ?>
                </span>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot superbaddons-status-dot--purple"></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of unique target types */
                        _n('%d Target', '%d Targets', $stats['target_count'], 'superb-blocks'),
                        $stats['target_count']
                    )); ?>
                </span>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo esc_attr($optimized_dot); ?>"></span>
                    <?php echo esc_html__("Auto-Optimized", "superb-blocks"); ?>
                </span>
            </div>
        </div>

        <div class="superbaddons-admindashboard-sidebarlayout">
            <div class="superbaddons-admindashboard-sidebarlayout-left">

                <div class="superbaddons-additional-content-wrapper superbaddons-admindashboard-css-blocks">
                    <!-- Section Header -->
                    <div class="superbaddons-css-section-header">
                        <div class="superbaddons-dashboard-section-header">
                            <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("CSS Blocks", "superb-blocks"); ?></h4>
                            <?php if ($stats['total'] > 0) : ?>
                                <span class="superbaddons-dashboard-count-badge"><?php echo esc_html(sprintf(
                                                                                        /* translators: %1$d: active, %2$d: total */
                                                                                        __('%1$d/%2$d Active', 'superb-blocks'),
                                                                                        $stats['active'],
                                                                                        $stats['total']
                                                                                    )); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="superbaddons-css-section-actions">
                            <a href="<?php echo esc_url(admin_url("admin.php?page=" . DashboardController::ADDITIONAL_CSS . '&css-create')); ?>" class="superbaddons-element-button superbaddons-element-m0">
                                <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/plus-circle.svg'); ?>" />
                                <?php echo esc_html__("Create New", "superb-blocks"); ?>
                            </a>
                            <span class="superbaddons-vertical-separator"></span>
                            <button class="superbaddons-import-blocks-btn superbaddons-element-button superbaddons-element-m0 superbaddons-element-mr1">
                                <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/upload-simple-duotone.svg'); ?>" />
                                <?php echo esc_html__("Import", "superb-blocks"); ?>
                            </button>
                            <input type="file" id="superbaddons-import-blocks-file" style="display:none;" accept=".superbaddons" multiple />
                            <?php new CssBlocksBaseSlot(); ?>
                        </div>
                    </div>

                    <?php if (empty($this->Blocks)) : ?>
                        <!-- Empty State -->
                        <div class="superbaddons-css-empty-state">
                            <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/dashboard-custom-css.svg'); ?>" aria-hidden="true" />
                            <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("No CSS Blocks Yet", "superb-blocks"); ?></h4>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__("Create your first CSS block to start customizing your website with targeted, performance-optimized CSS.", "superb-blocks"); ?></p>
                            <div class="superbaddons-css-empty-state-actions">
                                <a href="<?php echo esc_url(admin_url("admin.php?page=" . DashboardController::ADDITIONAL_CSS . '&css-create')); ?>" class="superbaddons-element-button">
                                    <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/plus-circle.svg'); ?>" />
                                    <?php echo esc_html__("Create Your First CSS Block", "superb-blocks"); ?>
                                </a>
                                <span class="superbaddons-vertical-separator"></span>
                                <button class="superbaddons-import-blocks-btn superbaddons-element-button">
                                    <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/upload-simple-duotone.svg'); ?>" />
                                    <?php echo esc_html__("Import CSS Blocks", "superb-blocks"); ?>
                                </button>
                            </div>
                            <!-- Feature Highlights -->
                            <div class="superbaddons-css-feature-highlights">
                                <div class="superbaddons-css-feature-highlight-card">
                                    <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/target-duotone.svg'); ?>" aria-hidden="true" />
                                    <h5 class="superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Smart Targeting", "superb-blocks"); ?></h5>
                                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__("Load CSS only where it's needed. Target specific pages, posts, templates, or the entire site.", "superb-blocks"); ?></p>
                                </div>
                                <div class="superbaddons-css-feature-highlight-card">
                                    <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-gauge.svg'); ?>" aria-hidden="true" />
                                    <h5 class="superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Auto-Optimized", "superb-blocks"); ?></h5>
                                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__("CSS is automatically minified and compiled into separate files per target for maximum performance.", "superb-blocks"); ?></p>
                                </div>
                                <div class="superbaddons-css-feature-highlight-card">
                                    <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-eye.svg'); ?>" aria-hidden="true" />
                                    <h5 class="superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Live Preview", "superb-blocks"); ?></h5>
                                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__("See your CSS changes in real-time with the built-in live previewer before publishing.", "superb-blocks"); ?></p>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <!-- Block Card Grid -->
                        <div class="superbaddons-css-block-grid">
                            <?php foreach ($this->Blocks as $block_id => $block) : ?>
                                <div class="superbaddons-css-block-card <?php echo $block->active ? 'superbaddons-css-block-card--active' : 'superbaddons-css-block-card--deactivated'; ?>" data-id="<?php echo esc_attr($block_id); ?>">
                                    <div class="superbaddons-css-block-card-checkbox">
                                        <?php new InputCheckbox("superbaddons-css-block-select-" . sanitize_title($block->name), "select-single", false, false, false); ?>
                                    </div>
                                    <a href="<?php echo esc_url(admin_url("admin.php?page=" . DashboardController::ADDITIONAL_CSS . '&css-edit=' . $block_id)); ?>" class="superbaddons-css-block-card-body">
                                        <img class="superbaddons-css-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-file.svg'); ?>" aria-hidden="true" />
                                        <div class="superbaddons-css-block-card-info">
                                            <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html($block->name); ?></strong>
                                            <div class="superbaddons-css-block-card-targets">
                                                <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/target-duotone.svg'); ?>" aria-hidden="true" />
                                                <span class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html($this->GetTargetLabels($block)); ?></span>
                                            </div>
                                        </div>
                                        <?php if ($block->active) : ?>
                                            <span class="superbaddons-integration-card-badge superbaddons-integration-card-badge--connected"><?php echo esc_html__("Active", "superb-blocks"); ?></span>
                                        <?php else : ?>
                                            <span class="superbaddons-integration-card-badge"><?php echo esc_html__("Deactivated", "superb-blocks"); ?></span>
                                        <?php endif; ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Bulk Actions Bar -->
                        <div class="superbaddons-css-bulk-actions" style="display:none;">
                            <div class="superbaddons-css-bulk-actions-inner">
                                <img class="superbaddons-css-bulk-actions-arrow" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/arrow-elbow-left-up.svg'); ?>" aria-hidden="true" />
                                <?php new InputCheckbox("superbaddons-css-block-select-all", "select-all", __("Select All", "superb-blocks"), false, false); ?>
                                <span class="superbaddons-vertical-separator"></span>
                                <button class="superbaddons-activate-blocks-btn superbaddons-element-button superbaddons-element-m0" disabled>
                                    <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/check-circle.svg'); ?>" />
                                    <?php echo esc_html__("Activate", "superb-blocks"); ?>
                                </button>
                                <span class="superbaddons-vertical-separator"></span>
                                <button class="superbaddons-deactivate-blocks-btn superbaddons-element-button superbaddons-element-m0" disabled>
                                    <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/x-circle.svg'); ?>" />
                                    <?php echo esc_html__("Deactivate", "superb-blocks"); ?>
                                </button>
                                <span class="superbaddons-vertical-separator"></span>
                                <?php new CssBlocksExportSelectedSlot(); ?>
                                <span class="superbaddons-vertical-separator"></span>
                                <button class="superbaddons-delete-blocks-btn superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-m0" disabled>
                                    <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" />
                                    <?php echo esc_html__("Delete", "superb-blocks"); ?>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Link Boxes -->
                <div class="superbaddons-admindashboard-linkbox-wrapper">
                    <?php
                    new LinkBox(
                        array(
                            "icon" => "question.svg",
                            "title" => __("Help & Tutorials", "superb-blocks"),
                            "description" => __("We have put together detailed documentation that walks you through every step of the process, from installation to customization.", "superb-blocks"),
                            "cta" => __("View tutorials", "superb-blocks"),
                            "link" => admin_url('admin.php?page=' . DashboardController::SUPPORT),
                            "same_window" => true,
                        )
                    );
                    new FeatureRequestBox();
                    ?>
                </div>
            </div>
            <div class="superbaddons-admindashboard-sidebarlayout-right">
                <?php
                new PremiumBox(AdminLinkSource::CSS);
                new SupportBox();
                new ReviewBox();
                ?>
            </div>
        </div>
        <?php new Modal(); ?>
<?php
    }

    private function GetTargetLabels($block)
    {
        $valid_labels = apply_filters(
            'superbaddons_css_block_target_valid_labels',
            array(
                "front" => __("Front Page", "superb-blocks"),
                "full" => __("Entire Website", "superb-blocks")
            )
        );

        $labels = array();
        foreach ($block->selectors as $target) {
            if (!isset($valid_labels[$target->type]) || !$valid_labels[$target->type])
                continue;

            $labels[] = !empty($target->value) ? sprintf(
                /* translators: %s: Target post type (page, post, template etc.) */
                __("Specific %s", "superb-blocks"),
                $valid_labels[$target->type]
            )  : $valid_labels[$target->type];
        }

        if (empty($labels)) {
            return __("Not Applied", "superb-blocks");
        }

        return rtrim(join(", ", $labels), ", ");
    }
}
