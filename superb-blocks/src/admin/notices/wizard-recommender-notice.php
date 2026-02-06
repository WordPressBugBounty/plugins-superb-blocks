<?php

use SuperbAddons\Admin\Controllers\DashboardController;

defined('ABSPATH') || exit;

$superb_blocks_recommend_url = add_query_arg(
    array(
        'page' => DashboardController::PAGE_WIZARD,
    ),
    admin_url("admin.php")
);
?>
<div class="notice notice-info superb-addons-wizard-notification is-dismissible <?php echo esc_attr($notice['unique_id']); ?>" style="background-image:url(<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/themedesigner-recommend.jpg'); ?>);">
    <span class="superb-addons-wizard-notification-inner">
        <div class="superbthemes-module-purple-badge"><?php echo esc_html__("Customize Your Theme", "superb-blocks"); ?></div>
        <br>
        <h2 class="notice-title"><?php echo esc_html__("Launch Theme Designer", "superb-blocks"); ?> </h2>
        <p><?php echo esc_html__("Quickly customize your website’s design. Choose layouts for your menu, footer, homepage, blog, and more. Launch the Theme Designer now to easily create a website that looks just the way you want!", "superb-blocks"); ?></p>
        <div>
            <a class='button button-large button-primary' href='<?php echo esc_url($superb_blocks_recommend_url); ?>'><?php echo esc_html__("Launch Theme Designer", "superb-blocks"); ?></a>
        </div>
        <style>
            .superb-addons-wizard-notification {
                background-position: top right 20px;
                background-repeat: no-repeat;
                background-size: contain;
            }

            .superb-addons-wizard-notification-inner {
                padding: 40px 400px 40px 30px;
                display: inline-block;
                width: 100%;
                -webkit-box-sizing: border-box;
                box-sizing: border-box;
                position: relative;
                background-size: contain;
            }

            .superb-addons-wizard-notification-inner .superbthemes-module-purple-badge {
                background: #fff8e1;
                color: #ff8f00;
                font-weight: bold;
                padding: 6px 10px;
                border-radius: 30px;
                display: inline-block;
            }

            .superb-addons-wizard-notification-inner .notice-title {
                width: 100%;
                display: inline-block;
                font-weight: 500;
                color: #263238;
                font-size: 32px;
                line-height: 130%;
                margin: 15px 0 20px;
            }

            .superb-addons-wizard-notification-inner p {
                display: inline-block;
                width: 100%;
                color: #546e7a;
                font-size: 18px;
                line-height: 144%;
                max-width: 600px;
            }

            .superb-addons-wizard-notification-inner a.button:active,
            .superb-addons-wizard-notification-inner a.button:hover,
            .superb-addons-wizard-notification-inner a.button {
                border: 1px solid #cfd8dc;
                padding: 10px 20px !important;
                -webkit-box-shadow: 0px 0px 0px 0px rgba(0, 0, 0, 0) !important;
                box-shadow: 0px 0px 0px 0px rgba(0, 0, 0, 0) !important;
                color: #fff !important;
                font-weight: 500 !important;
                font-size: 16px !important;
                margin-right: 15px !important;
                text-decoration: none !important;
                background: #00BC87 !important;
                border-radius: 6px !important;
                border: 2px solid #00d096 !important;
                margin: 20px 10px 0 0 !important;
            }


            @media screen and (max-width: 1200px) {
                .superb-addons-wizard-notification-inner {
                    padding: 30px 380px 30px 30px;
                }
            }

            @media screen and (max-width: 1100px) {
                .superb-addons-wizard-notification-inner {
                    padding: 20px 0px 20px 20px;
                }

                .superb-addons-wizard-notification {
                    background-image: none !important;
                }
            }
        </style>
    </span>
</div>