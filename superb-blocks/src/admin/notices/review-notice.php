<?php

use SuperbAddons\Admin\Controllers\ReviewController;

defined('ABSPATH') || exit;
?>
<div class="notice notice-info is-dismissible <?php echo esc_attr($notice['unique_id']); ?>">
    <p><strong><?php echo esc_html__("Enjoying Superb Addons?", "superb-blocks"); ?></strong></p>
    <p><?php echo esc_html__("If it's been useful, a quick 5-star review on WordPress.org means the world to us. We read every single one.", "superb-blocks"); ?></p>
    <p class="superbaddons-review-actions">
        <a class="button button-primary superbaddons-notice-dismiss" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url(ReviewController::GetReviewUrl()); ?>"><?php echo esc_html__("Sure, you've earned it", "superb-blocks"); ?></a>
        <a class="superbaddons-notice-dismiss superbaddons-review-text-link" href="#"><?php echo esc_html__("Maybe later", "superb-blocks"); ?></a>
        <a class="superbaddons-notice-dismiss superbaddons-review-text-link" href="#"><?php echo esc_html__("I already did", "superb-blocks"); ?></a>
    </p>
    <style>
        .superbaddons-review-actions a.superbaddons-review-text-link {
            margin-left: 14px;
            color: #646970;
            text-decoration: none;
            line-height: 30px;
        }

        .superbaddons-review-actions a.superbaddons-review-text-link:hover {
            color: #135e96;
        }
    </style>
</div>
