<?php

use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;

defined('ABSPATH') || exit;
?>
<div class="notice notice-info is-dismissible <?php echo esc_attr($notice['unique_id']); ?>">
    <h2 class="notice-title"><?php echo esc_html__("Lock in up to 40% off Superb Addons Premium", "superb-blocks"); ?></h2>
    <p>
        <?php echo esc_html__("Save up to 40% on the full Premium toolkit.", "superb-blocks"); ?>
        <?php echo esc_html__("Pick a subscription or lifetime plan, and upgrade today to lock in the discount.", "superb-blocks"); ?>
        <?php echo esc_html__("Subscribers also keep the discount on every renewal.", "superb-blocks"); ?>
    </p>
    <p>
        <a style='margin-bottom:15px;' class='button button-large button-primary' target='_blank' href='<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::NOTICE_LOCK, array("anchor" => 'pricingplans'))); ?>'><?php echo esc_html__("Upgrade and save", "superb-blocks"); ?></a>
    </p>
</div>