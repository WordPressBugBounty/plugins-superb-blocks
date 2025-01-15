<?php

namespace SuperbAddons\Components\Slots;

use SuperbAddons\Admin\Utils\AdminLinkSource;

defined('ABSPATH') || exit();

class CssBlocksBaseSlot extends PremiumSlot
{
    protected static $RenderFill;
    protected function RenderSlot()
    {
        new PremiumOptionWrapper(
            function () {
?>
            <button class="superbaddons-element-button superbaddons-element-m0">
                <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/download-simple-duotone.svg'); ?>" />
                <?php echo esc_html__("Export CSS Blocks", "superb-blocks"); ?>
            </button>
<?php
            },
            array("superbaddons-element-ml1"),
            AdminLinkSource::CSS_EXPORT
        );
    }
}
