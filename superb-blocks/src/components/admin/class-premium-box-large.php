<?php

namespace SuperbAddons\Components\Admin;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Data\Controllers\KeyController;

class PremiumBoxLarge
{
    private $source;
    private $options;

    public function __construct($source, $options = false)
    {
        $this->source = $source;
        $this->options = $options;
        if (KeyController::HasValidPremiumKey()) {
            $this->RenderHasPremium();
            return;
        }

        $this->Render();
    }

    private function Render()
    {
        $has_standard_plan = KeyController::HasValidStandardKey();
?>
        <div class="superbaddons-admindashboard-content-box-large superbaddons-admindashboard-settings-premium-image-box-large superbaddons-admindashboard-content-box-large-connected-bottom" style="background-image:url(<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/asset-medium-pro.jpg'); ?>);">
            <div class="superbaddons-admindashboard-content-box-large-inner">
                <h3 class="superbaddons-element-text-md superbaddons-element-text-800 superbaddons-element-text-dark"><?php echo esc_html__("Premium Subscription", "superb-blocks"); ?></h3>
                <p class="superbaddons-element-text-xs superbaddons-element-text-gray">
                    <?php echo esc_html(sprintf(/* translators: 1: a name of a plan */__('You\'re currently on our %1$s plan. Upgrade to premium and access all of the benefits for building top class websites.', "superb-blocks"), $has_standard_plan ? __("theme license", "superb-blocks") : __("free", "superb-blocks"))); ?>
                </p>
                <span class="superbaddons-element-text-xs superbaddons-element-text-800 superbaddons-element-text-dark"><?php echo esc_html__("All this included", "superb-blocks"); ?><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/pointing_arrow.png'); ?>" /></span>
                <?php new PremiumFeatureList(); ?>
                <a class="superbaddons-element-button-pro" target="_blank" href="<?php echo esc_url(AdminLinkUtil::GetLink($this->source, $this->options)); ?>"><?php echo esc_html__("Upgrade to Premium", "superb-blocks"); ?></a>
            </div>
        </div>
<?php
    }

    private function RenderHasPremium()
    {
        new ContentBoxLarge(
            array(
                "title" => __("Superb Addons Premium", "superb-blocks"),
                "description" => __("You're currently on our premium plan. Please contact us if you're in need of assistance. Thank you for your support!", "superb-blocks"),
                "image" => "asset-medium-pro.jpg",
                "connected_bottom" => true,
                "class" => 'superbaddons-admindashboard-settings-premium-image-box-large'
            )
        );
    }
}
