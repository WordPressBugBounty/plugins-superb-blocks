<?php

namespace SuperbAddons\Components\Admin;

defined('ABSPATH') || exit();

class Modal
{
    public function __construct()
    {
        $this->Render();
    }

    private function Render()
    {
?>
        <div class="superbaddons-admindashboard-modal-wrapper" style="display:none;">
            <div class="superbaddons-admindashboard-modal-overlay"></div>
            <div class="superbaddons-admindashboard-modal">
                <div class="superbaddons-admindashboard-modal-header">
                    <span class="superbaddons-admindashboard-modal-title superbaddons-element-text-sm superbaddons-element-text-800">Modal Title</span>
                    <img class="superbaddons-admindashboard-modal-header-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/blocks-spinner.svg"); ?>" />
                    <div class="superbaddons-admindashboard-modal-close-button" role="button" tabindex="0" aria-label="<?php echo esc_attr__("Close", "superb-blocks"); ?>"><svg width="16" height="16" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M13 1L1 13M1 1L13 13" stroke="#90A4AE" stroke-width="1.5" stroke-linecap="round"/></svg><span class="screen-reader-text"><?php echo esc_html__("Close", "superb-blocks"); ?></span></div>
                </div>
                <div class="superbaddons-element-separator"></div>
                <div class="superbaddons-admindashboard-modal-content superbaddons-element-text-xs">
                    Modal Content
                </div>
                <div class="superbaddons-admindashboard-modal-required-area" style="display:none;">
                    <label class="superbaddons-admindashboard-modal-checkbox-label">
                        <input type="checkbox" class="superbaddons-admindashboard-modal-required" />
                        <span class="superbaddons-admindashboard-modal-required-text"></span>
                    </label>
                </div>
                <div class="superbaddons-admindashboard-modal-checkbox-area" style="display:none;">
                    <label class="superbaddons-admindashboard-modal-checkbox-label">
                        <input type="checkbox" class="superbaddons-admindashboard-modal-checkbox" />
                        <span class="superbaddons-admindashboard-modal-checkbox-text"></span>
                    </label>
                </div>
                <div class="superbaddons-element-separator"></div>
                <div class="superbaddons-admindashboard-modal-footer">
                    <button type="button" class="superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-m0 superbaddons-admindashboard-modal-confirm-btn"><?php echo esc_html__("Confirm", "superb-blocks"); ?></button>
                    <button type="button" class="superbaddons-element-button superbaddons-element-m0 superbaddons-admindashboard-modal-cancel-btn"><?php echo esc_html__("Cancel", "superb-blocks"); ?></button>
                    <button type="button" class="superbaddons-element-button superbaddons-element-m0 superbaddons-admindashboard-modal-ok-btn"><?php echo esc_html__("OK", "superb-blocks"); ?></button>
                </div>
            </div>
        </div>


<?php
    }
}
