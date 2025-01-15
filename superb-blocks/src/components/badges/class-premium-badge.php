<?php

namespace SuperbAddons\Components\Badges;

defined('ABSPATH') || exit();

class PremiumBadge
{
    public function __construct()
    {
?>
        <span class="superbaddons-element-crown-badge superbaddons-item-premium-element-badge"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/color-light-crown.svg"); ?>" alt="<?php echo esc_html__("Premium", "superb-blocks"); ?>" title="<?php echo esc_html__("Premium", "superb-blocks"); ?>" /></span>
<?php
    }
}
