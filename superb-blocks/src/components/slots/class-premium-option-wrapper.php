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
        // data-superb-upsell-source lives on the wrapper (not the inner <a>) so
        // clicks anywhere inside — including from inner content that has its
        // own pointer events — bubble up and open the upsell modal. The badge
        // anchor is the graceful no-JS / middle-click target. Children that
        // need their own click behavior (e.g. .sba-wizard-preview-btn) just
        // stopPropagation and the document delegation never fires.
        $passthrough_class = $allow_pointer_events ? ' superbaddons-premium-only-option-wrapper-passthrough' : '';
?>
        <div class="superbaddons-element-inlineflex-center superbaddons-premium-only-option-wrapper<?php echo esc_attr($passthrough_class); ?> <?php echo esc_attr(join(" ", $classes)); ?>"<?php echo $source ? ' data-superb-upsell-source="' . esc_attr($source) . '"' : ''; ?> title="<?php echo esc_attr__("Premium Feature", "superb-blocks"); ?>">
            <a href="<?php echo esc_url(AdminLinkUtil::GetLink($source, $link_options)); ?>" target="_blank" class="superbaddons-premium-only-option" aria-label="<?php echo esc_attr__("Premium Feature", "superb-blocks"); ?>">
                <div class="superbaddons-premium-only-option-icon">
                    <img width="16" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-crown.svg'); ?>" />
                    <span><?php echo esc_html__("Premium", "superb-blocks"); ?></span>
                </div>
            </a>
            <div class="superbaddons-premium-only-content" style="<?php echo $allow_pointer_events ? '' : 'pointer-events: none;'; ?> opacity:0.5;">
                <?php SlotRenderUtility::Render($contentCallback); ?>
            </div>
        </div>
<?php
    }
}
