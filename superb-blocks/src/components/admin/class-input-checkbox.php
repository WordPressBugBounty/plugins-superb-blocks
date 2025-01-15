<?php

namespace SuperbAddons\Components\Admin;

defined('ABSPATH') || exit();

class InputCheckbox
{
    private $Id;
    private $Action;
    private $Title;
    private $Description;
    private $Checked;
    private $Icon;

    public function __construct($id, $action, $title, $description = false, $checked = false, $icon = false)
    {
        $this->Id = $id;
        $this->Action = $action;
        $this->Title = $title;
        $this->Description = $description;
        $this->Checked = $checked;
        $this->Icon = $icon;
        $this->Render();
    }

    private function Render()
    {
?>
        <div class="superb-addons-checkbox-input-wrapper">
            <label class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-element-inlineflex-center superbaddons-element-relative">
                <input id="<?php echo esc_attr($this->Id); ?>" name="<?php echo esc_attr($this->Id); ?>" class="superbaddons-inputcheckbox-input" data-action="<?php echo esc_attr($this->Action); ?>" type="checkbox" <?php echo $this->Checked ? 'checked="checked"' : '' ?>>
                <span class="superb-addons-checkbox-checkmark"><img class="superbaddons-admindashboard-content-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" /></span>
                <span><?php echo esc_html($this->Title); ?></span>
                <?php if ($this->Icon) : ?>
                    <img class="superbaddons-admindashboard-checkbox-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . $this->Icon); ?>" />
                <?php endif; ?>
            </label>
            <?php if ($this->Description) : ?>
                <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html($this->Description); ?></p>
            <?php endif; ?>
        </div>
<?php
    }
}
