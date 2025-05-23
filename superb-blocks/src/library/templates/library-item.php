<?php

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Components\Badges\AvailableBadge;
use SuperbAddons\Components\Badges\ExternalPluginRequiredBadge;
use SuperbAddons\Components\Badges\PremiumBadge;
use SuperbAddons\Components\Badges\UpdateRequiredBadge;
use SuperbAddons\Components\Buttons\InsertButton;
use SuperbAddons\Components\Buttons\PremiumButton;
use SuperbAddons\Components\Buttons\PreviewButton;
?>
<div class="superb-addons-template-library-template-item">
    <div class="superb-addons-template-library-template-item-body">
        <img class="superb-addons-template-library-preview-image-img superb-addons-template-library-preview-image-img-placeholder" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/icon-superb.svg"); ?>" style="display:none;" />
        <img class="superb-addons-template-library-preview-image-img superb-addons-template-library-preview-image-img-actual" loading="lazy">
        <?php new PremiumBadge(); ?>
    </div>
    <div class="superb-addons-template-library-template-item-footer">
        <div class="superb-addons-template-library-template-item-footer-top">
            <div class="superb-addons-template-library-template-item-name"></div>
        </div>
        <div class="superb-addons-template-library-template-item-footer-bottom">
            <?php
            new AvailableBadge();
            new UpdateRequiredBadge();
            new ExternalPluginRequiredBadge();
            new PremiumButton(AdminLinkSource::LIBRARY_ITEM);
            new InsertButton();
            new PreviewButton();
            ?>
        </div>
    </div>
</div>