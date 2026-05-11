<?php

namespace SuperbAddons\Admin\Pages\Wizard;

use SuperbAddons\Admin\Controllers\Wizard\WizardController;
use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Components\Admin\InputCheckbox;
use SuperbAddons\Components\Admin\Modal;
use SuperbAddons\Components\Admin\OutdatedBrowserWarning;
use SuperbAddons\Components\Slots\PremiumOptionWrapper;
use SuperbAddons\Data\Controllers\CacheController;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Utils\CacheTypes;
use SuperbAddons\Data\Utils\GutenbergCache;
use SuperbAddons\Data\Utils\Wizard\WizardActionParameter;
use SuperbAddons\Data\Utils\Wizard\WizardItemTypes;
use SuperbAddons\Data\Utils\Wizard\WizardNavigationMenuOptions;
use SuperbAddons\Data\Utils\Wizard\WizardStageTypes;
use SuperbAddons\Data\Utils\Wizard\WizardStageUtil;

defined('ABSPATH') || exit();

class PageWizardStagesPage
{
    private $stageUtil;

    private $cancel_url;
    private $complete_url;

    private $userIsPremium;

    public function __construct()
    {
        $this->stageUtil = new WizardStageUtil();
        $this->cancel_url = WizardController::GetWizardURL(WizardActionParameter::CANCEL);

        // Cold-cache gate: stages need the unified library cache fully assembled before they can
        // render a complete picker. If the cache is not warm, render an interstitial that drives
        // the warm-cache endpoint until ready, then reloads into the normal flow.
        if ($this->NeedsLibrary() && !self::IsLibraryCacheWarm()) {
            $this->RenderInterstitial();
            return;
        }

        $this->stageUtil->InitializeTemplates();

        $this->complete_url = WizardController::GetWizardCompleteURL($this->stageUtil->GetType());

        $this->userIsPremium = KeyController::HasValidPremiumKey();

        $this->Render();
    }

    private function NeedsLibrary()
    {
        return $this->stageUtil->HasPatterns() || $this->stageUtil->HasPages();
    }

    private static function IsLibraryCacheWarm()
    {
        try {
            $cache = CacheController::GetCache(GutenbergCache::LIBRARY, CacheTypes::GUTENBERG);
            return !!$cache;
        } catch (\Exception $e) {
            // If the cache check itself fails (e.g. service offline), don't trap the user behind
            // an interstitial that can't complete — let the wizard render with whatever it has.
            return true;
        }
    }

    private function RenderInterstitial()
    {
?>
        <div class="superbaddons-wizard-interstitial" id="superbaddons-wizard-interstitial" data-wizard="<?php echo esc_attr($this->stageUtil->GetType()); ?>">
            <div class="superbaddons-wizard-interstitial-card">
                <img class="superbaddons-wizard-interstitial-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/blocks-spinner.svg'); ?>" width="48" height="48" alt="" aria-hidden="true" />
                <h2 class="superbaddons-wizard-interstitial-title"><?php echo esc_html__('Preparing the theme designer', 'superb-blocks'); ?></h2>
                <p class="superbaddons-wizard-interstitial-text"><?php echo esc_html__('Loading patterns and pages. This only takes a moment.', 'superb-blocks'); ?></p>
                <div class="superbaddons-wizard-interstitial-bar" data-status="initializing" role="progressbar" aria-label="<?php echo esc_attr__('Library load progress', 'superb-blocks'); ?>">
                    <div class="superbaddons-wizard-interstitial-bar-track"></div>
                </div>
                <div class="superbaddons-wizard-interstitial-error" style="display:none;" role="alert">
                    <p class="superbaddons-wizard-interstitial-error-text"><?php echo esc_html__('Something went wrong while preparing your library. Please try again.', 'superb-blocks'); ?></p>
                    <button type="button" class="superbaddons-wizard-interstitial-retry superbthemes-module-cta"><?php echo esc_html__('Retry', 'superb-blocks'); ?></button>
                </div>
            </div>
        </div>
        <div class="superbaddons-theme-template-menu" data-wizard="<?php echo esc_attr($this->stageUtil->GetType()); ?>">
            <div class="superbaddons-theme-template-menu-inner">
                <div class="superbaddons-template-menu-buttons superbaddons-template-menu-buttons-left">
                    <a href="<?php echo esc_url($this->cancel_url); ?>" id="superbaddons-template-cancel-button" class="superbthemes-module-cta"><?php echo esc_html__("Cancel", "superb-blocks"); ?></a>
                </div>
            </div>
        </div>
    <?php
    }

    private function Render()
    {
        $stages = $this->stageUtil->GetAvailableConfiguredStages();

        foreach ($stages as $stage_type => $stage_config) {
            $this->RenderStage(
                $stage_type,
                $stage_config,
                isset($stage_config['args']) ? $stage_config['args'] : array()
            );
        }
    ?>
        <div class="superbaddons-theme-template-menu" data-wizard="<?php echo esc_attr($this->stageUtil->GetType()); ?>">
            <div class="superbaddons-theme-template-menu-inner">
                <div class="superbaddons-template-menu-buttons superbaddons-template-menu-buttons-left">
                    <a href="<?php echo esc_url($this->cancel_url); ?>" id="superbaddons-template-cancel-button" class="superbthemes-module-cta"><?php echo esc_html__("Cancel", "superb-blocks"); ?></a>
                </div>
                <div class="superbaddons-theme-template-steps">
                </div>
                <div class="superbaddons-template-menu-buttons superbaddons-template-menu-buttons-right">
                    <button id="superbaddons-template-next-button" data-complete-url="<?php echo esc_url($this->complete_url); ?>" class="superbthemes-module-cta superbthemes-module-cta-green"><?php echo esc_html__("Continue", "superb-blocks"); ?></button>
                </div>
            </div>
        </div>
        <script type="text/template" id="superbaddons-theme-template-step">
            <div class="superbaddons-theme-template-step"><div class="superbaddons-theme-template-step-inner"></div></div>
        </script>
        <script type="text/template" id="superbaddons-stage-overview-item">
            <div class="superbaddons-stage-overview-item superbaddons-stage-selection-item sba-completion-section">
                <div class="sba-completion-section-header">
                    <div class="sba-completion-section-header-left">
                        <span class="sba-completion-section-icon"></span>
                        <h2 class="superbaddons-element-m0 sba-completion-section-title"></h2>
                    </div>
                    <button type="button" class="sba-completion-change-btn"></button>
                </div>
                <div class="superbaddons-stage-selection-list superbaddons-element-m0 sba-completion-items"></div>
            </div>
        </script>
    <?php
        new OutdatedBrowserWarning();
        new Modal();
    }

    private function RenderStage($stage_id, $properties, $args)
    {
        $index = 0;
        $total = count($properties['templates']);
        $firstSelectionIndex = 0;
        if ($total > 1 && $properties['templates'][0]->datatype === "ignore") {
            $firstSelectionIndex = 1;
        }

        $is_unique_render = isset($properties['unique_render']) && $properties['unique_render'];
        $is_restore = $this->stageUtil->GetType() === WizardActionParameter::RESTORE;
        // Library chrome (sidebar, search, filter bar, heart buttons, tag pills) applies only to
        // selection stages that are not unique renders and not restore-mode stages.
        $show_library_chrome = !$is_unique_render && !$is_restore;
        $is_part_stage = ($stage_id === WizardStageTypes::HEADER_STAGE || $stage_id === WizardStageTypes::FOOTER_STAGE);
        $grid_modifier_class = $is_part_stage ? 'sba-wizard-grid-parts' : 'sba-wizard-grid-pages';
    ?>
        <div class="superbaddons-template-stage" data-stageid="<?php echo esc_attr($stage_id); ?>" data-type="<?php echo esc_attr($properties['type']); ?>" data-required="<?php echo esc_attr(boolval($properties['required'])); ?>" data-hastitleinput="<?php echo esc_attr(isset($properties['has-title-input']) ? $properties['has-title-input'] : ""); ?>" data-hasmultipleparts="<?php echo esc_attr(isset($properties['has-multiple-parts']) ? boolval($properties['has-multiple-parts']) : ""); ?>" data-wizardmode="<?php echo esc_attr($this->stageUtil->GetType()); ?>" style="display:none;">
            <div class="superbaddons-wizard-wrapper-small sba-wizard-stage-heading" data-stage-label="<?php echo esc_attr($this->stageUtil->GetStageLabel($stage_id)); ?>" data-title-input-suggestion="<?php echo esc_attr(isset($properties['input-suggestion']) ? $properties['input-suggestion'] : ''); ?>">
                <h1 class="sba-wizard-stage-heading-title">
                    <span class="sba-wizard-stage-heading-number" aria-hidden="true"></span>
                    <span class="sba-wizard-stage-heading-label"><?php echo esc_html($this->stageUtil->GetStageTitle($stage_id)); ?></span>
                </h1>
                <p class="superbaddons-element-text-sm superbaddons-wizard-tagline">
                    <?php
                    $description_lines = $this->stageUtil->GetStageDescription($stage_id);
                    foreach ($description_lines as $i => $description_line) :
                        if ($i > 0) echo '<br />';
                        echo esc_html($description_line);
                    endforeach;
                    ?>
                </p>

                <?php if ($show_library_chrome) : ?>
                    <p class="sba-wizard-stage-theme-hint"><?php echo esc_html__('Colors will match your theme style after the setup is complete.', 'superb-blocks'); ?></p>
                <?php endif; ?>

                <?php if ($is_unique_render):
                    if ($stage_id === WizardStageTypes::NAVIGATION_MENU_STAGE):
                        $this->RenderNavigationUpdateStage(...$args);
                    elseif ($stage_id === WizardStageTypes::COMPLETION_STAGE) :
                        $this->RenderCompletionStage();
                    endif;
                endif; ?>
            </div>

            <?php if (!$is_unique_render) : ?>
                <?php if ($show_library_chrome) : ?>
                    <div class="sba-wizard-stage-body">
                        <button type="button" class="sba-wizard-stage-filters-toggle" aria-expanded="false" aria-controls="sba-wizard-stage-sidebar-<?php echo esc_attr($stage_id); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 256 256">
                                <path d="M230.6,70.6a8,8,0,0,1-8,8H167.6a32,32,0,0,1-62.4,0H33.4a8,8,0,0,1,0-16h71.8a32,32,0,0,1,62.4,0h55A8,8,0,0,1,230.6,70.6Zm0,56H196.8a32,32,0,0,0-62.4,0H33.4a8,8,0,0,0,0,16h101a32,32,0,0,0,62.4,0h25.8A8,8,0,0,0,230.6,126.6Zm0,56H119.6a32,32,0,0,0-62.4,0H33.4a8,8,0,0,0,0,16H57.2a32,32,0,0,0,62.4,0H222.6A8,8,0,0,0,230.6,182.6Z"></path>
                            </svg>
                            <span><?php echo esc_html__('Filters', 'superb-blocks'); ?></span>
                        </button>
                        <aside id="sba-wizard-stage-sidebar-<?php echo esc_attr($stage_id); ?>" class="sba-wizard-stage-sidebar" data-stageid="<?php echo esc_attr($stage_id); ?>">
                            <!-- Sidebar populated by wizard-library-ui.js -->
                        </aside>
                        <div class="sba-wizard-stage-main">
                            <div class="sba-wizard-stage-search">
                                <label class="sba-wizard-stage-search-label" for="sba-wizard-stage-search-<?php echo esc_attr($stage_id); ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 256 256" aria-hidden="true">
                                        <path d="M229.66,218.34l-50.07-50.06a88.11,88.11,0,1,0-11.31,11.31l50.06,50.07a8,8,0,0,0,11.32-11.32ZM40,112a72,72,0,1,1,72,72A72.08,72.08,0,0,1,40,112Z"></path>
                                    </svg>
                                </label>
                                <input id="sba-wizard-stage-search-<?php echo esc_attr($stage_id); ?>" type="text" class="sba-wizard-stage-search-input" placeholder="<?php echo esc_attr__('Search...', 'superb-blocks'); ?>" autocomplete="off" />
                                <span class="sba-wizard-stage-search-shortcut"><?php echo esc_html__('Press /', 'superb-blocks'); ?></span>
                                <div class="sba-wizard-stage-autocomplete sba-library-autocomplete" style="display:none;"></div>
                            </div>
                            <div class="sba-wizard-stage-active-filters" aria-live="polite">
                                <span class="sba-wizard-stage-result-count"></span>
                                <button type="button" class="sba-wizard-favorites-filter-btn" aria-pressed="false" style="display:none;">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 256 256">
                                        <path d="M178,32c-20.65,0-38.73,8.88-50,23.89C116.73,40.88,98.65,32,78,32A62.07,62.07,0,0,0,16,94c0,70,103.79,126.66,108.21,129a8,8,0,0,0,7.58,0C136.21,220.66,240,164,240,94A62.07,62.07,0,0,0,178,32ZM128,206.8C109.74,196.16,32,147.69,32,94A46.06,46.06,0,0,1,78,48c19.45,0,35.78,10.36,42.6,27a8,8,0,0,0,14.8,0c6.82-16.67,23.15-27,42.6-27a46.06,46.06,0,0,1,46,46C224,147.61,146.24,196.15,128,206.8Z"></path>
                                    </svg>
                                    <span class="sba-wizard-favorites-filter-label"><?php echo esc_html__('Favorites', 'superb-blocks'); ?></span>
                                    <span class="sba-wizard-favorites-filter-count">0</span>
                                </button>
                                <div class="sba-wizard-chips"></div>
                                <button type="button" class="sba-wizard-clear-all" style="display:none;"><?php echo esc_html__('Clear all', 'superb-blocks'); ?></button>
                            </div>
                            <div class="superbaddons-theme-template-container superbaddons-wizard-list-grid sba-wizard-grid <?php echo esc_attr($grid_modifier_class); ?>">
                                <?php
                                foreach ($properties['templates'] as $template) :
                                    $is_selected = $properties['type'] === 'single-selection' && $firstSelectionIndex === $index++;
                                    $this->RenderTemplate($template, $is_selected);
                                endforeach;
                                ?>
                            </div>
                            <div class="sba-wizard-empty-state" style="display:none;">
                                <div class="sba-wizard-empty-state-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="#CFD8DC" viewBox="0 0 256 256">
                                        <path d="M216,72H131.31L104,44.69A15.86,15.86,0,0,0,92.69,40H40A16,16,0,0,0,24,56V200.62A15.4,15.4,0,0,0,39.38,216H216.89A15.13,15.13,0,0,0,232,200.89V88A16,16,0,0,0,216,72ZM40,56H92.69l16,16H40ZM216,200H40V88H216Z"></path>
                                    </svg>
                                </div>
                                <div class="sba-wizard-empty-state-title"><?php echo esc_html__('No items match your filters.', 'superb-blocks'); ?></div>
                                <div class="sba-wizard-empty-state-hints"></div>
                                <div class="sba-wizard-empty-state-actions">
                                    <button type="button" class="sba-wizard-empty-clear-btn"><?php echo esc_html__('Clear filters', 'superb-blocks'); ?></button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <div class="superbaddons-theme-template-container superbaddons-wizard-list-grid sba-wizard-grid <?php echo esc_attr($grid_modifier_class); ?>">
                        <?php
                        foreach ($properties['templates'] as $template) :
                            $is_selected = $properties['type'] === 'single-selection' && $firstSelectionIndex === $index++;
                            $this->RenderTemplate($template, $is_selected);
                        endforeach;
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php
    }

    private function RenderTemplate($template, $is_selected = false, $rendering_with_premium_wrapper = false)
    {
        $premium = isset($template->is_premium) && $template->is_premium;
        $premium_available = $premium && $this->userIsPremium;
        $update_required = isset($template->plugin_update_required) && $template->plugin_update_required;
        $external_plugin_required = isset($template->external_plugin_required) && $template->external_plugin_required;
        $external_plugin_required_text = __("Additional Plugin Required", "superb-blocks");
        $external_plugin_tooltip_text = $external_plugin_required_text;
        if ($external_plugin_required) {
            if (isset($template->required_plugin_names) && !empty($template->required_plugin_names)) {
                $external_plugin_required_text = $template->required_plugin_names[0] . (count($template->required_plugin_names) > 1 ? " (+" . (count($template->required_plugin_names) - 1) . ")" : '') . " " . __(" Required", "superb-blocks");
                if (count($template->required_plugin_names) > 1) {
                    $external_plugin_tooltip_text = implode(", ", $template->required_plugin_names);
                }
            }
        }
        $is_missing_navigation_block = isset($template->is_missing_navigation_block) && $template->is_missing_navigation_block;

        if (!$rendering_with_premium_wrapper && $premium && !$premium_available) {
            new PremiumOptionWrapper(function () use ($template, $is_selected) {
                $this->RenderTemplate($template, $is_selected, true);
            }, array("sba-wizard-card-wrap"), AdminLinkSource::DESIGNER, false, true);
            return;
        }

        // Favorites bucket: only service patterns and service pages with a remote id are favoritable.
        // Theme-local items (file templates, wp_template), restoration points, static, ignore have no stable remote id.
        $favorites_bucket = false;
        if ($template->id && !$template->is_file_template && !$template->is_restoration_point) {
            if ($template->type === WizardItemTypes::PATTERN) {
                $favorites_bucket = 'patterns';
            } elseif ($template->type === WizardItemTypes::PAGE) {
                $favorites_bucket = 'pages';
            }
        }

        $categories_json = isset($template->categories) ? wp_json_encode($template->categories) : '[]';
        $tags_json = isset($template->tags) ? wp_json_encode($template->tags) : '[]';
        $industries_json = isset($template->industries) ? wp_json_encode($template->industries) : '[]';
        $style_json = isset($template->style) ? wp_json_encode($template->style) : 'null';
    ?>
        <div class="superbaddons-theme-page-template superbaddons-element-text-dark <?php echo $update_required ? "superbaddons-theme-page-template-update-required" : ""; ?> <?php echo $external_plugin_required ? "superbaddons-theme-page-template-external-plugin-required" : ""; ?> <?php echo $premium_available ? "superbaddons-premium-element-option" : ($premium ? "superbaddons-theme-page-template-unavailable-premium" : ""); ?> <?php echo $template->IsPattern() ? "superbaddons-theme-page-template-part" : ""; ?> <?php echo $is_selected ? 'superbaddons-theme-page-template-selected' : ''; ?>" data-slug="<?php echo esc_attr($template->GetSlug()); ?>" data-title="<?php echo esc_attr($template->title); ?>" data-type="<?php echo esc_attr($template->datatype); ?>" data-package="<?php echo $premium ? 'premium' : 'free'; ?>" data-navigation-issue="<?php echo $is_missing_navigation_block ? 'true' : 'false'; ?>" data-item-id="<?php echo esc_attr($template->GetId()); ?>" data-item-remote-id="<?php echo $template->id ? esc_attr($template->id) : ''; ?>" data-favorites-bucket="<?php echo $favorites_bucket ? esc_attr($favorites_bucket) : ''; ?>" data-categories="<?php echo esc_attr($categories_json); ?>" data-tags="<?php echo esc_attr($tags_json); ?>" data-industries="<?php echo esc_attr($industries_json); ?>" data-style="<?php echo esc_attr($style_json); ?>" data-description="<?php echo esc_attr(isset($template->description) ? $template->description : ''); ?>">
            <div class="superbaddons-template-content-wrapper">
                <?php if ($template->id) : ?>
                    <div class="superbaddons-template-preview-container">
                        <iframe data-id="<?php echo esc_attr($template->id . "//" . $template->GetSlug()); ?>" aria-hidden="true" class="superbaddons-template-preview-content" data-status="loading" data-noreload="<?php echo $template->no_reload ? "noreload" : ""; ?>" loading="lazy" src="" data-src="<?php echo esc_url($template->GetPreviewURL()); ?>" style="display:none;"></iframe>
                    </div>
                    <div class=" superbaddons-preview-spinner-container">
                        <img class="superbaddons-preview-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/blocks-spinner.svg"); ?>" width="30px" height="auto" style="user-select:none;margin:auto;" />
                    </div>
                <?php endif; ?>
            </div>
            <?php
            // Overlay controls live OUTSIDE the scrolling content wrapper so the hover
            // scroll-on-preview animation doesn't shift them along with the iframe content.
            // Favorite is suppressed inside the premium upsell wrapper because the user
            // can't favorite a locked item. The preview button stays rendered: its click
            // handler stopPropagation()'s before the document-level upsell delegation can
            // fire, so previewing works without triggering the upsell modal.
            if ($favorites_bucket && !$rendering_with_premium_wrapper) : ?>
                <button type="button" class="sba-wizard-favorite-btn" aria-pressed="false" aria-label="<?php echo esc_attr__('Favorite', 'superb-blocks'); ?>" title="<?php echo esc_attr__('Favorite', 'superb-blocks'); ?>">
                    <svg class="sba-wizard-favorite-icon sba-wizard-favorite-icon-outline" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 256 256">
                        <path d="M178,32c-20.65,0-38.73,8.88-50,23.89C116.73,40.88,98.65,32,78,32A62.07,62.07,0,0,0,16,94c0,70,103.79,126.66,108.21,129a8,8,0,0,0,7.58,0C136.21,220.66,240,164,240,94A62.07,62.07,0,0,0,178,32ZM128,206.8C109.74,196.16,32,147.69,32,94A46.06,46.06,0,0,1,78,48c19.45,0,35.78,10.36,42.6,27a8,8,0,0,0,14.8,0c6.82-16.67,23.15-27,42.6-27a46.06,46.06,0,0,1,46,46C224,147.61,146.24,196.15,128,206.8Z"></path>
                    </svg>
                    <svg class="sba-wizard-favorite-icon sba-wizard-favorite-icon-filled" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 256 256">
                        <path d="M240,94c0,70-103.79,126.66-108.21,129a8,8,0,0,1-7.58,0C119.79,220.66,16,164,16,94A62.07,62.07,0,0,1,78,32c20.65,0,38.73,8.94,50,24.05C139.27,40.94,157.35,32,178,32A62.07,62.07,0,0,1,240,94Z"></path>
                    </svg>
                </button>
            <?php endif; ?>
            <?php if ($template->id) : ?>
                <button type="button" class="sba-wizard-preview-btn" aria-label="<?php echo esc_attr__('Preview', 'superb-blocks'); ?>" title="<?php echo esc_attr__('Preview', 'superb-blocks'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 256 256">
                        <path d="M247.31,124.76c-.35-.79-8.82-19.58-27.65-38.41C194.57,61.26,162.88,48,128,48S61.43,61.26,36.34,86.35C17.51,105.18,9,124,8.69,124.76a8,8,0,0,0,0,6.5c.35.79,8.82,19.57,27.65,38.4C61.43,194.74,93.12,208,128,208s66.57-13.26,91.66-38.34c18.83-18.83,27.3-37.61,27.65-38.4A8,8,0,0,0,247.31,124.76ZM128,192c-30.78,0-57.67-11.19-79.93-33.25A133.47,133.47,0,0,1,25,128,133.33,133.33,0,0,1,48.07,97.25C70.33,75.19,97.22,64,128,64s57.67,11.19,79.93,33.25A133.46,133.46,0,0,1,231.05,128C223.84,141.46,192.43,192,128,192Zm0-112a48,48,0,1,0,48,48A48.05,48.05,0,0,0,128,80Zm0,80a32,32,0,1,1,32-32A32,32,0,0,1,128,160Z"></path>
                    </svg>
                </button>
                <div class="sba-wizard-selected-overlay" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 256 256" fill="currentColor">
                        <path d="M229.66,77.66l-128,128a8,8,0,0,1-11.32,0l-56-56a8,8,0,0,1,11.32-11.32L96,188.69,218.34,66.34a8,8,0,0,1,11.32,11.32Z"></path>
                    </svg>
                </div>
            <?php endif; ?>
            <div class="superbaddons-template-information">
                <div class="superbaddons-template-information-inner superbaddons-element-flex-center">
                    <div class="superbaddons-template-information-title superbaddons-element-text-sm">
                        <?php echo esc_html($template->title); ?>
                    </div>
                    <div class="superbaddons-template-information-package superbaddons-element-text-xs superbaddons-element-text-gray">
                        <?php if ($update_required): ?>
                            <span class="superbaddons-template-update-required"><?php echo esc_html__("Plugin Update Required", "superb-blocks"); ?></span>
                        <?php elseif ($external_plugin_required): ?>
                            <span class="superbaddons-template-external-plugin-required" title="<?php echo esc_attr($external_plugin_tooltip_text); ?>"><?php echo esc_html($external_plugin_required_text); ?></span>
                        <?php else: ?>
                            <?php echo $premium ? ($premium_available ? esc_html__("Premium", "superb-blocks") : esc_html__("Unlock With Premium", "superb-blocks")) : esc_html__("Free", "superb-blocks"); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /* Unique renders */
    private function RenderNavigationUpdateStage($displayReplaceMenu, $displayAppendMenu, $hasNavigationTemplatePart)
    {
    ?>
        <div class="superbaddons-checkbox-large-wrapper <?php echo $displayReplaceMenu && $displayAppendMenu ? 'superbaddons-checkbox-large-wrapper-three' : ''; ?>">
            <?php if ($displayReplaceMenu) : ?>
                <div class="superbaddons-checkbox-large-item">
                    <?php new InputCheckbox(WizardNavigationMenuOptions::CREATE_NEW_MENU, __('Replace Menu Items', 'superb-blocks'), __('Replace Menu Items', 'superb-blocks'), __('Updates the navigation block with menu items for the front page, blog page and the selected pages.', 'superb-blocks'), $hasNavigationTemplatePart); ?>
                    <p id="superbaddons-navigation-block-warning" class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-mt1" style="font-style:italic;"><?php echo esc_html__('No navigation block was found in the selected header template part. Navigation menu cannot be updated automatically.', 'superb-blocks'); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($displayAppendMenu): ?>
                <div class="superbaddons-checkbox-large-item">
                    <?php new InputCheckbox(WizardNavigationMenuOptions::APPEND_EXISTING_MENU, __('Append Menu Items', 'superb-blocks'), __('Append Menu Items', 'superb-blocks'), __('The navigation block menu items will be updated to include all your selected pages. Any menu items already present in the navigation block will remain in addition to the newly created menu items.', 'superb-blocks'), $hasNavigationTemplatePart && $this->stageUtil->GetType() === WizardActionParameter::ADD_NEW_PAGES); ?>
                </div>
            <?php endif; ?>
            <div class="superbaddons-checkbox-large-item">
                <?php new InputCheckbox(WizardNavigationMenuOptions::SKIP_MENU, __('Don\'t Update Menu Items', 'superb-blocks'), __('Don\'t Update Menu Items', 'superb-blocks'), __('If you do not want the navigation menu items to be changed, select this option.', 'superb-blocks'), !$hasNavigationTemplatePart); ?>
            </div>
        </div>
    <?php
    }

    private function RenderCompletionStage()
    {
    ?>
        <div class="superbaddons-stage-selection-overview superbaddons-element-mb2 superbaddons-theme-template-container superbaddons-wizard-list-grid">
        </div>
        <div class="sba-completion-stage-icons" style="display:none;">
            <img class="sba-completion-icon" data-stage="header-stage" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/wizard-menu-layout.svg'); ?>" width="24" height="24" alt="" aria-hidden="true" />
            <img class="sba-completion-icon" data-stage="footer-stage" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/wizard-footer-layout.svg'); ?>" width="24" height="24" alt="" aria-hidden="true" />
            <img class="sba-completion-icon" data-stage="front-page-stage" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/wizard-front-page-design.svg'); ?>" width="24" height="24" alt="" aria-hidden="true" />
            <img class="sba-completion-icon" data-stage="blog-page-stage" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/wizard-blog-setup.svg'); ?>" width="24" height="24" alt="" aria-hidden="true" />
            <img class="sba-completion-icon" data-stage="template-page-stage" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/wizard-additional-pages.svg'); ?>" width="24" height="24" alt="" aria-hidden="true" />
            <img class="sba-completion-icon" data-stage="navigation-menu-stage" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/wizard-menu-layout.svg'); ?>" width="24" height="24" alt="" aria-hidden="true" />
        </div>
<?php
    }
    /* */
}
