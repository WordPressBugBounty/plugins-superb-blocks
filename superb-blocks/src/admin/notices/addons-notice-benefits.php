<?php

use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;

defined('ABSPATH') || exit;
?>
<div class="notice notice-info is-dismissible <?php echo esc_attr($notice['unique_id']); ?>">
    <h2 class="notice-title"><?php echo esc_html__("Unlock every premium block and design tool in Superb Addons", "superb-blocks"); ?></h2>
    <p>
        <?php echo esc_html__("Superb Addons Premium adds advanced responsive controls, animations, and visibility rules to every block.", "superb-blocks"); ?>
        <?php echo esc_html__("You also get dynamic content from custom fields, multi-step forms, and 200+ pre-built patterns and full page designs.", "superb-blocks"); ?>
        <?php echo esc_html__("One upgrade unlocks everything. Trusted by over 1 million WordPress users.", "superb-blocks"); ?>
    </p>
    <p>
        <a style='margin-bottom:15px;' class='button button-large button-primary' target='_blank' href='<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::NOTICE, array("experiment" => "notice"))); ?>'><?php echo esc_html__("Unlock All Features", "superb-blocks"); ?></a>
    </p>
</div>
