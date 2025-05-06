<?php

namespace SuperbAddons\Components\Slots;

use SuperbAddons\Admin\Utils\AdminLinkUtil;

defined('ABSPATH') || exit();

class PremiumOptionWrapper
{
    public function __construct($contentCallback, $classes = array(), $source = false, $link_options = false, $allow_pointer_events = false)
    {
        $this->Render($contentCallback, $classes, $source, $link_options, $allow_pointer_events);
    }

    private function Render($contentCallback, $classes, $source, $link_options, $allow_pointer_events)
    {
?>
        <div class="superbaddons-element-inlineflex-center <?php echo esc_attr(join(" ", $classes)); ?>">
            <a href="<?php echo esc_url(AdminLinkUtil::GetLink($source, $link_options)); ?>" target="_blank" class="superbaddons-premium-only-option-wrapper" title="<?php echo esc_attr__("Premium Feature", "superb-blocks"); ?>">
                <div class="superbaddons-premium-only-option">
                    <div class="superbaddons-premium-only-option-icon">
                        <img width="16" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-crown.svg'); ?>" />
                        <span><?php echo esc_html__("Premium", "superb-blocks"); ?></span>
                    </div>

                </div>
                <div style="<?php echo $allow_pointer_events ? '' : 'pointer-events: none;'; ?> opacity:0.5;">
                    <?php SlotRenderUtility::Render($contentCallback); ?>
                </div>
            </a>
        </div>
<?php
    }
}
