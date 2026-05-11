<?php

namespace SuperbAddons\Components\Admin;

defined('ABSPATH') || exit();

use SuperbAddons\Gutenberg\Controllers\GutenbergEnhancementsController;

class EnhancementSettingsComponent
{
    private $Settings;

    public function __construct()
    {
        $this->Settings = GutenbergEnhancementsController::GetEnhancementsOptions(get_current_user_id());
        $this->Render();
    }

    private function Render()
    {
?>
        <div class="superbaddons-dashboard-section-header">
            <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html__("Editor Preferences", "superb-blocks"); ?></h4>
        </div>
        <p class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-help-text-inline"><?php echo esc_html__("These settings apply only to your account.", "superb-blocks"); ?></p>

        <div class="superbaddons-editor-settings-highlights-wrapper">
            <h5 class="superbaddons-element-flex-center superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0 superbaddons-element-mb1"><img class="superbaddons-admindashboard-content-icon superbaddons-element-mr1" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-selection-plus.svg'); ?>" /><?php echo esc_html__("Editor Outlines", "superb-blocks"); ?></h5>
            <?php new InputCheckbox('superbaddons-enhancement-highlights-input', GutenbergEnhancementsController::HIGHLIGHTS_KEY, __("Enable Outlines", "superb-blocks"), __("When this setting is enabled, related block and editable text will be outlined whenever you hover over a block with your mouse, making it easy to visualize your layout structure.", "superb-blocks"), $this->Settings[GutenbergEnhancementsController::HIGHLIGHTS_KEY], '/img/selection-plus.svg'); ?>
            <div class="superbaddons-enhancement-children" data-parent="superbaddons-enhancement-highlights-input" <?php echo !$this->Settings[GutenbergEnhancementsController::HIGHLIGHTS_KEY] ? ' style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                <?php new InputCheckbox('superbaddons-enhancement-highlights-quickoptions-input', GutenbergEnhancementsController::HIGHLIGHTS_QUICKOPTIONS_KEY, __("Quick Options", "superb-blocks"), __("Enables a quick options panel at the top of the highlighted or selected block.", "superb-blocks"), $this->Settings[GutenbergEnhancementsController::HIGHLIGHTS_QUICKOPTIONS_KEY]); ?>
                <div class="superbaddons-enhancement-children" data-parent="superbaddons-enhancement-highlights-quickoptions-input" <?php echo !$this->Settings[GutenbergEnhancementsController::HIGHLIGHTS_QUICKOPTIONS_KEY] ? ' style="opacity:0.5;pointer-events:none;"' : ''; ?>>
                    <?php new InputCheckbox('superbaddons-enhancement-highlights-quickoptions-bottom-input', GutenbergEnhancementsController::HIGHLIGHTS_QUICKOPTIONS_BOTTOM_KEY, __("Position Quick Options at Bottom", "superb-blocks"), __("Moves the quick options panel to the bottom of the highlighted or selected block instead of the top.", "superb-blocks"), $this->Settings[GutenbergEnhancementsController::HIGHLIGHTS_QUICKOPTIONS_BOTTOM_KEY]); ?>
                </div>
            </div>
        </div>
        <div class="superbaddons-editor-settings-panel-state-wrapper">
            <div class="superbaddons-element-separator"></div>
            <div class="superb-addons-checkbox-input-wrapper" style="margin-top: 16px; margin-bottom: 0;">
                <div class="superbaddons-setting-row" style="border: none; padding-bottom: 0;">
                    <div class="superbaddons-setting-row-label">
                        <label class="superbaddons-element-text-xs superbaddons-element-text-gray" for="superbaddons-enhancement-panel-state-select">
                            <?php echo esc_html__("Enhancement Panel Default State", "superb-blocks"); ?>
                            <button type="button" class="superbaddons-help-toggle" aria-label="<?php echo esc_attr__('Toggle help text', 'superb-blocks'); ?>">i</button>
                        </label>
                    </div>
                    <div class="superbaddons-setting-row-control">
                        <select id="superbaddons-enhancement-panel-state-select" data-action="<?php echo esc_attr(GutenbergEnhancementsController::PANEL_DEFAULT_STATE_KEY); ?>" class="superbaddons-enhancement-select">
                            <option value="open" <?php echo $this->Settings[GutenbergEnhancementsController::PANEL_DEFAULT_STATE_KEY] === 'open' ? ' selected' : ''; ?>><?php echo esc_html__("Open", "superb-blocks"); ?></option>
                            <option value="closed" <?php echo $this->Settings[GutenbergEnhancementsController::PANEL_DEFAULT_STATE_KEY] === 'closed' ? ' selected' : ''; ?>><?php echo esc_html__("Closed", "superb-blocks"); ?></option>
                            <option value="dynamic" <?php echo $this->Settings[GutenbergEnhancementsController::PANEL_DEFAULT_STATE_KEY] === 'dynamic' ? ' selected' : ''; ?>><?php echo esc_html__("Dynamic", "superb-blocks"); ?></option>
                        </select>
                    </div>
                </div>
                <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-help-text"><?php echo esc_html__("Controls how enhancement panels behave in the block settings sidebar when a block is selected.", "superb-blocks"); ?></p>
                <ul class="superbaddons-help-text" style="padding-left: 16px;">
                    <li class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Open: always expanded.", "superb-blocks"); ?></li>
                    <li class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Closed: always collapsed.", "superb-blocks"); ?></li>
                    <li class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__("Dynamic: collapsed by default, but expanded when the panel's features are in use on the selected block.", "superb-blocks"); ?></li>
                </ul>
            </div>
        </div>
<?php
    }
}
