<?php
defined('ABSPATH') || exit();

use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Components\Admin\EnhancementSettingsComponent;
use SuperbAddons\Components\Badges\ExternalPluginRequiredBadge;
use SuperbAddons\Components\Badges\PremiumBadge;
use SuperbAddons\Components\Badges\UpdateRequiredBadge;
use SuperbAddons\Components\Buttons\InsertButton;
use SuperbAddons\Components\Buttons\PremiumButton;
use SuperbAddons\Components\Buttons\PreviewButton;
?>

<div class="superb-addons-template-library-wrapper-overlay"></div>
<div class="superb-addons-template-library-page-frame">
    <div class="superb-addons-template-library-page-header">
        <div class="superb-addons-template-library-page-header-logo-area">
            <div class="superb-addons-template-library-page-header-logo">
                <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/icon-superb.svg'); ?>" />
                <span class="superbaddons-element-text-md superbaddons-element-text-800 superbaddons-element-text-dark"><?php echo esc_html__("Design Library", "superb-blocks"); ?></span>
            </div>
        </div>
        <div class="superb-addons-template-library-page-header-search-area">
            <div id="superb-addons-template-library-page-search-wrapper">
                <label for="superb-addons-template-library-page-search-input"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/magnifying-glass.svg"); ?>" /></label>
                <input id="superb-addons-template-library-page-search-input" type="text" placeholder="<?php echo esc_attr__('Search anything...', "superb-blocks"); ?>" autocomplete="off" />
                <span class="superb-addons-template-library-search-shortcut"><?php echo esc_html__("Press /", "superb-blocks"); ?></span>
            </div>
            <div id="superb-addons-template-library-search-autocomplete" class="sba-library-autocomplete" style="display:none;"></div>
        </div>
        <div class="superb-addons-template-library-page-header-items-area">
            <div class="superb-addons-template-library-header-btn superb-addons-template-library-filters-btn" title="<?php echo esc_attr__('Filters', "superb-blocks"); ?>"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/magnifying-glass.svg"); ?>" alt="<?php echo esc_attr__('Filters', "superb-blocks"); ?>" /></div>
            <div class="superb-addons-template-library-header-btn superb-addons-template-library-settings-btn"><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="#000000" viewBox="0 0 256 256">
                    <path d="M128,80a48,48,0,1,0,48,48A48.05,48.05,0,0,0,128,80Zm0,80a32,32,0,1,1,32-32A32,32,0,0,1,128,160Zm88-29.84q.06-2.16,0-4.32l14.92-18.64a8,8,0,0,0,1.48-7.06,107.21,107.21,0,0,0-10.88-26.25,8,8,0,0,0-6-3.93l-23.72-2.64q-1.48-1.56-3-3L186,40.54a8,8,0,0,0-3.94-6,107.71,107.71,0,0,0-26.25-10.87,8,8,0,0,0-7.06,1.49L130.16,40Q128,40,125.84,40L107.2,25.11a8,8,0,0,0-7.06-1.48A107.6,107.6,0,0,0,73.89,34.51a8,8,0,0,0-3.93,6L67.32,64.27q-1.56,1.49-3,3L40.54,70a8,8,0,0,0-6,3.94,107.71,107.71,0,0,0-10.87,26.25,8,8,0,0,0,1.49,7.06L40,125.84Q40,128,40,130.16L25.11,148.8a8,8,0,0,0-1.48,7.06,107.21,107.21,0,0,0,10.88,26.25,8,8,0,0,0,6,3.93l23.72,2.64q1.49,1.56,3,3L70,215.46a8,8,0,0,0,3.94,6,107.71,107.71,0,0,0,26.25,10.87,8,8,0,0,0,7.06-1.49L125.84,216q2.16.06,4.32,0l18.64,14.92a8,8,0,0,0,7.06,1.48,107.21,107.21,0,0,0,26.25-10.88,8,8,0,0,0,3.93-6l2.64-23.72q1.56-1.48,3-3L215.46,186a8,8,0,0,0,6-3.94,107.71,107.71,0,0,0,10.87-26.25,8,8,0,0,0-1.49-7.06Zm-16.1-6.5a73.93,73.93,0,0,1,0,8.68,8,8,0,0,0,1.74,5.48l14.19,17.73a91.57,91.57,0,0,1-6.23,15L187,173.11a8,8,0,0,0-5.1,2.64,74.11,74.11,0,0,1-6.14,6.14,8,8,0,0,0-2.64,5.1l-2.51,22.58a91.32,91.32,0,0,1-15,6.23l-17.74-14.19a8,8,0,0,0-5-1.75h-.48a73.93,73.93,0,0,1-8.68,0,8,8,0,0,0-5.48,1.74L100.45,215.8a91.57,91.57,0,0,1-15-6.23L82.89,187a8,8,0,0,0-2.64-5.1,74.11,74.11,0,0,1-6.14-6.14,8,8,0,0,0-5.1-2.64L46.43,170.6a91.32,91.32,0,0,1-6.23-15l14.19-17.74a8,8,0,0,0,1.74-5.48,73.93,73.93,0,0,1,0-8.68,8,8,0,0,0-1.74-5.48L40.2,100.45a91.57,91.57,0,0,1,6.23-15L69,82.89a8,8,0,0,0,5.1-2.64,74.11,74.11,0,0,1,6.14-6.14A8,8,0,0,0,82.89,69L85.4,46.43a91.32,91.32,0,0,1,15-6.23l17.74,14.19a8,8,0,0,0,5.48,1.74,73.93,73.93,0,0,1,8.68,0,8,8,0,0,0,5.48-1.74L155.55,40.2a91.57,91.57,0,0,1,15,6.23L173.11,69a8,8,0,0,0,2.64,5.1,74.11,74.11,0,0,1,6.14,6.14,8,8,0,0,0,5.1,2.64l22.58,2.51a91.32,91.32,0,0,1,6.23,15l-14.19,17.74A8,8,0,0,0,199.87,123.66Z"></path>
                </svg></div>
            <div class="superb-addons-template-library-header-btn superb-addons-template-library-close-btn"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/x.svg"); ?>" alt="<?php echo esc_attr__("Close", "superb-blocks"); ?>" /></div>
        </div>
    </div>
    <div class="superb-addons-template-library-page-content">
        <div class="superb-addons-template-library-page-content-inner" style="display:none;">
            <div class="superb-addons-template-library-dashboard-banner" style="display:none;">
                <div class="superb-addons-template-library-dashboard-banner-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 256 256"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216Zm16-40a8,8,0,0,1-8,8,16,16,0,0,1-16-16V128a8,8,0,0,1,0-16,16,16,0,0,1,16,16v40A8,8,0,0,1,144,176ZM112,84a12,12,0,1,1,12,12A12,12,0,0,1,112,84Z"></path></svg>
                </div>
                <div class="superb-addons-template-library-dashboard-banner-text">
                    <strong><?php echo esc_html__("You are browsing the Design Library.", "superb-blocks"); ?></strong>
                    <span><?php echo esc_html__("To insert a pattern, open any page, post, or template and click \"Design Library\" in the editor toolbar.", "superb-blocks"); ?></span>
                </div>
                <a class="superb-addons-template-library-dashboard-banner-action" href="#" target="_blank" rel="noopener"><?php echo esc_html__("Watch Tutorial", "superb-blocks"); ?></a>
                <button type="button" class="superb-addons-template-library-dashboard-banner-dismiss" aria-label="<?php echo esc_attr__("Dismiss", "superb-blocks"); ?>"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/x.svg"); ?>" alt="<?php echo esc_attr__("Dismiss", "superb-blocks"); ?>" /></button>
            </div>
            <div class="superb-addons-template-library-body">
                <aside class="superb-addons-template-library-sidebar">
                    <div class="superb-addons-template-library-sidebar-section superb-addons-template-library-sidebar-browse">
                        <h4 class="superb-addons-template-library-sidebar-title"><?php echo esc_html__("Browse", "superb-blocks"); ?></h4>
                        <ul id="superb-addons-template-library-type-list" class="superb-addons-template-library-sidebar-list"></ul>
                    </div>
                    <div class="superb-addons-template-library-sidebar-section superb-addons-template-library-sidebar-style" style="display:none;">
                        <h4 class="superb-addons-template-library-sidebar-title"><?php echo esc_html__("Style", "superb-blocks"); ?></h4>
                        <ul id="superb-addons-template-library-style-list" class="superb-addons-template-library-sidebar-list"></ul>
                    </div>
                    <div class="superb-addons-template-library-sidebar-section superb-addons-template-library-sidebar-categories">
                        <h4 class="superb-addons-template-library-sidebar-title"><?php echo esc_html__("Categories", "superb-blocks"); ?></h4>
                        <ul id="superb-addons-template-library-category-list" class="superb-addons-template-library-sidebar-list"></ul>
                    </div>
                </aside>
                <div class="superb-addons-template-library-main">
                    <div class="superb-addons-template-library-active-filters">
                        <span class="superb-addons-template-library-result-count"></span>
                        <button type="button" class="superb-addons-template-library-favorites-filter-btn" aria-pressed="false" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 256 256"><path d="M178,32c-20.65,0-38.73,8.88-50,23.89C116.73,40.88,98.65,32,78,32A62.07,62.07,0,0,0,16,94c0,70,103.79,126.66,108.21,129a8,8,0,0,0,7.58,0C136.21,220.66,240,164,240,94A62.07,62.07,0,0,0,178,32ZM128,206.8C109.74,196.16,32,147.69,32,94A46.06,46.06,0,0,1,78,48c19.45,0,35.78,10.36,42.6,27a8,8,0,0,0,14.8,0c6.82-16.67,23.15-27,42.6-27a46.06,46.06,0,0,1,46,46C224,147.61,146.24,196.15,128,206.8Z"></path></svg>
                            <span class="superb-addons-template-library-favorites-filter-label"><?php echo esc_html__("Favorites", "superb-blocks"); ?></span>
                            <span class="superb-addons-template-library-favorites-filter-count">0</span>
                        </button>
                        <div class="superb-addons-template-library-chips"></div>
                        <button type="button" class="superb-addons-template-library-clear-all" style="display:none;"><?php echo esc_html__("Clear all", "superb-blocks"); ?></button>
                    </div>
                    <div class="superb-addons-template-library-page-content-list">
                        <div class="superb-addons-template-library-page-content-inner-list">
                            <!-- JS -->
                        </div>
                        <div class="superb-addons-template-library-empty-state" style="display:none;">
                            <div class="superb-addons-template-library-empty-state-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" fill="#CFD8DC" viewBox="0 0 256 256"><path d="M216,72H131.31L104,44.69A15.86,15.86,0,0,0,92.69,40H40A16,16,0,0,0,24,56V200.62A15.4,15.4,0,0,0,39.38,216H216.89A15.13,15.13,0,0,0,232,200.89V88A16,16,0,0,0,216,72ZM40,56H92.69l16,16H40ZM216,200H40V88H216Z"></path></svg>
                            </div>
                            <div class="superb-addons-template-library-empty-state-title"><?php echo esc_html__("No items match your filters.", "superb-blocks"); ?></div>
                            <div class="superb-addons-template-library-empty-state-hints"></div>
                            <div class="superb-addons-template-library-empty-state-actions">
                                <button type="button" class="superb-addons-template-library-button superb-addons-template-library-button-primary superb-addons-template-library-empty-clear-btn"><?php echo esc_html__("Clear filters", "superb-blocks"); ?></button>
                            </div>
                        </div>
                        <div class="superb-addons-template-library-page-content-inner-list-footer">
                            <div class="superb-addons-template-library-footer-suggest">
                                <span class="superb-addons-template-library-footer-suggest-text"><?php echo esc_html__("Have a pattern request?", "superb-blocks"); ?></span>
                                <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::DEFAULT, array("url" => "https://superbthemes.com/contact/", "anchor" => "create-ticket"))); ?>" target="_blank" rel="noopener" class="superb-addons-template-library-footer-suggest-link"><?php echo esc_html__("Suggest a design", "superb-blocks"); ?></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="superb-addons-template-library-page-content-settings" style="display:none;">
            <div class="superbaddons-library-page-settings-content-wrapper">
                <?php new EnhancementSettingsComponent(); ?>
            </div>
        </div>
        <div class="superb-addons-loading">
            <div class="superb-addons-loader-wrapper">
                <div class="superbaddons-spinner-wrapper">
                    <img class="spbaddons-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/blocks-spinner.svg"); ?>" />
                </div>
                <div class="superbaddons-loading-title superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-text-md"><?php echo esc_html__("Loading", "superb-blocks"); ?></div>
            </div>
        </div>
    </div>
    <div class="superb-addons-template-library-preview-wrapper" style="display:none;">
        <div class="superb-addons-template-library-preview-overlay"></div>
        <div class="superb-addons-template-library-preview-modal">
            <div class="superb-addons-template-library-preview-header">
                <span class="superb-addons-template-library-preview-title superbaddons-element-text-lg superbaddons-element-text-dark superbaddons-element-text-800"></span>
                <?php new PremiumBadge(); ?>
                <div id="superb-addons-template-library-preview-close-button" class="superb-addons-template-library-button superb-addons-template-library-button-secondary"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/x.svg"); ?>" alt="<?php echo esc_attr__("Close", "superb-blocks"); ?>" /></div>
            </div>
            <div class="superb-addons-template-library-preview-modal-content">
                <div class="superb-addons-template-library-preview-top">
                    <div class="superb-addons-template-library-preview-left">
                        <p><?php echo esc_html__('This preview is an image.', "superb-blocks"); ?> <span class="superb-addons-template-library-preview-left-livepreview-explain" style="display:none;"><?php echo esc_html__('To see the element live, click the "Live Preview" button.', "superb-blocks"); ?></span></p>
                        <span class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Please note that colors and other aspects may vary slightly depending on your current theme.", "superb-blocks") ?></span>
                    </div>
                    <div class="superb-addons-template-library-preview-right">
                        <?php
                        new PreviewButton(__("Live Preview", "superb-blocks"), '_blank');
                        new UpdateRequiredBadge();
                        new ExternalPluginRequiredBadge();
                        new PremiumButton(AdminLinkSource::LIBRARY_ITEM);
                        new InsertButton();
                        ?>
                        <button type="button" class="superb-addons-template-library-help-indicator superb-addons-template-library-help-indicator-preview superbaddons-item-dashboard-element" style="display:none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 256 256"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216Zm16-40a8,8,0,0,1-8,8,16,16,0,0,1-16-16V128a8,8,0,0,1,0-16,16,16,0,0,1,16,16v40A8,8,0,0,1,144,176ZM112,84a12,12,0,1,1,12,12A12,12,0,0,1,112,84Z"></path></svg>
                            <span><?php echo esc_html__("How to insert this", "superb-blocks"); ?></span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="superb-addons-template-library-preview-image-wrapper">
                <img id="superb-addons-template-library-preview" />
                <div class="superbaddons-spinner-wrapper" style="display:none;">
                    <img class="spbaddons-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/blocks-spinner.svg"); ?>" />
                </div>
            </div>
        </div>
    </div>
    <div class="superb-addons-template-library-help-popover" style="display:none;">
        <div class="superb-addons-template-library-help-popover-inner">
            <div class="superb-addons-template-library-help-popover-title"><?php echo esc_html__("To insert this pattern:", "superb-blocks"); ?></div>
            <ol class="superb-addons-template-library-help-popover-steps">
                <li><?php echo esc_html__("Open any page, post, or template.", "superb-blocks"); ?></li>
                <li><?php echo esc_html__("Click \"Design Library\" in the editor toolbar.", "superb-blocks"); ?></li>
            </ol>
            <div class="superb-addons-template-library-help-popover-actions">
                <a class="superb-addons-template-library-help-popover-tutorial" href="#" target="_blank" rel="noopener"><?php echo esc_html__("Watch Tutorial", "superb-blocks"); ?></a>
                <button type="button" class="superb-addons-template-library-help-popover-close"><?php echo esc_html__("Close", "superb-blocks"); ?></button>
            </div>
        </div>
    </div>
</div>
