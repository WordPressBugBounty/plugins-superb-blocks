<?php

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Components\Badges\PremiumBadge;
use SuperbAddons\Components\Buttons\InsertButton;
use SuperbAddons\Components\Buttons\PremiumButton;
use SuperbAddons\Components\Buttons\PreviewButton;
?>
<div class="superb-addons-template-library-template-item">
    <div class="superb-addons-template-library-template-item-body">
        <img class="superb-addons-template-library-preview-image-img superb-addons-template-library-preview-image-img-placeholder" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/icon-superb.svg"); ?>" style="display:none;" />
        <img class="superb-addons-template-library-preview-image-img superb-addons-template-library-preview-image-img-actual" loading="lazy">
        <button type="button" class="superb-addons-template-library-favorite-btn" aria-pressed="false" aria-label="<?php echo esc_attr__('Favorite', "superb-blocks"); ?>" title="<?php echo esc_attr__('Favorite', "superb-blocks"); ?>">
            <svg class="superbaddons-favorite-icon superbaddons-favorite-icon-outline" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 256 256"><path d="M178,32c-20.65,0-38.73,8.88-50,23.89C116.73,40.88,98.65,32,78,32A62.07,62.07,0,0,0,16,94c0,70,103.79,126.66,108.21,129a8,8,0,0,0,7.58,0C136.21,220.66,240,164,240,94A62.07,62.07,0,0,0,178,32ZM128,206.8C109.74,196.16,32,147.69,32,94A46.06,46.06,0,0,1,78,48c19.45,0,35.78,10.36,42.6,27a8,8,0,0,0,14.8,0c6.82-16.67,23.15-27,42.6-27a46.06,46.06,0,0,1,46,46C224,147.61,146.24,196.15,128,206.8Z"></path></svg>
            <svg class="superbaddons-favorite-icon superbaddons-favorite-icon-filled" xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 256 256"><path d="M240,94c0,70-103.79,126.66-108.21,129a8,8,0,0,1-7.58,0C119.79,220.66,16,164,16,94A62.07,62.07,0,0,1,78,32c20.65,0,38.73,8.94,50,24.05C139.27,40.94,157.35,32,178,32A62.07,62.07,0,0,1,240,94Z"></path></svg>
        </button>
        <?php new PremiumBadge(); ?>
        <div class="superb-addons-template-library-template-item-ribbon superbaddons-library-item-update-required-badge" style="display:none;"><?php echo esc_html__("Plugin Update Required", "superb-blocks"); ?></div>
        <div class="superb-addons-template-library-template-item-ribbon superbaddons-library-item-external-plugin-required-badge" style="display:none;"></div>
        <div class="superb-addons-template-library-template-item-overlay">
            <div class="superb-addons-template-library-template-item-overlay-actions">
                <?php
                new PremiumButton(AdminLinkSource::LIBRARY_ITEM);
                new InsertButton();
                ?>
                <button type="button" class="superb-addons-template-library-help-indicator superbaddons-item-dashboard-element" style="display:none;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 256 256"><path d="M128,24A104,104,0,1,0,232,128,104.11,104.11,0,0,0,128,24Zm0,192a88,88,0,1,1,88-88A88.1,88.1,0,0,1,128,216Zm16-40a8,8,0,0,1-8,8,16,16,0,0,1-16-16V128a8,8,0,0,1,0-16,16,16,0,0,1,16,16v40A8,8,0,0,1,144,176ZM112,84a12,12,0,1,1,12,12A12,12,0,0,1,112,84Z"></path></svg>
                    <span><?php echo esc_html__("How to insert this", "superb-blocks"); ?></span>
                </button>
                <?php new PreviewButton(__("Preview", "superb-blocks")); ?>
            </div>
        </div>
    </div>
    <div class="superb-addons-template-library-template-item-footer">
        <div class="superb-addons-template-library-template-item-name"></div>
        <div class="superb-addons-template-library-template-item-meta"></div>
        <div class="superb-addons-template-library-template-item-tags"></div>
    </div>
</div>
