<?php

namespace SuperbAddons\Admin\Pages;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Controllers\DashboardController;
use SuperbAddons\Admin\Controllers\RewriteCheckController;
use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Components\Admin\Modal;
use SuperbAddons\Components\Admin\PremiumBox;
use SuperbAddons\Components\Admin\ReviewBox;
use SuperbAddons\Components\Admin\SupportBox;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Elementor\Controllers\ElementorController;
use SuperbAddons\Gutenberg\Controllers\GutenbergController;
use SuperbAddons\Tours\Controllers\TourController;

class SupportPage
{
    public function __construct()
    {
        $this->Render();
    }

    private function Render()
    {
        $wp_compatible = GutenbergController::is_compatible();
        $wp_dot = $wp_compatible ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--red';
        $wp_label = $wp_compatible ? __('WordPress Compatible', 'superb-blocks') : sprintf(/* translators: %s: WordPress version number */__('WordPress %s+ Recommended', 'superb-blocks'), GutenbergController::MINIMUM_WORDPRESS_VERSION);
        $elementor_installed = ElementorController::is_installed_and_activated();
        $elementor_compatible = ElementorController::is_compatible();
        $elementor_dot = $elementor_compatible ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--red';
        $elementor_label = $elementor_compatible ? __('Elementor Compatible', 'superb-blocks') : sprintf(/* translators: %s: Elementor version number */__('Elementor %s+ Required', 'superb-blocks'), ElementorController::MINIMUM_ELEMENTOR_VERSION);
        $has_valid_license = KeyController::HasValidPremiumKey();
        $has_registered_key = KeyController::HasRegisteredKey();
        $has_key_issue = false;
        if ($has_registered_key) {
            $key_status = KeyController::GetKeyStatus();
            $has_key_issue = $key_status['expired'] || !$key_status['active'] || !$key_status['verified'] || $key_status['exceeded'];
        }
        $license_dot = $has_key_issue ? 'superbaddons-status-dot--red' : ($has_valid_license ? 'superbaddons-status-dot--green' : ($has_registered_key ? 'superbaddons-status-dot--yellow' : 'superbaddons-status-dot--gray'));
        $license_label = $has_key_issue ? __('License Issue Detected', 'superb-blocks') : ($has_valid_license ? __('Premium Active', 'superb-blocks') : ($has_registered_key ? __('License Active', 'superb-blocks') : __('Free Plan', 'superb-blocks')));
        $contact_url = AdminLinkUtil::GetLink(AdminLinkSource::SUPPORT, array("url" => "https://superbthemes.com/contact/", "anchor" => "create-ticket"));
        $support_cta = array('text' => __('Contact Support', 'superb-blocks'), 'link' => $contact_url);
?>
        <!-- Status Strip -->
        <div class="superbaddons-dashboard-welcome-strip">
            <span class="superbaddons-dashboard-welcome-title"><?php esc_html_e("Get Help", "superb-blocks"); ?></span>
            <div class="superbaddons-dashboard-stat-items">
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo esc_attr($wp_dot); ?>"></span>
                    <?php echo esc_html($wp_label); ?>
                </span>
                <?php if ($elementor_installed) : ?>
                    <span class="superbaddons-dashboard-stat-item">
                        <span class="superbaddons-status-dot <?php echo esc_attr($elementor_dot); ?>"></span>
                        <?php echo esc_html($elementor_label); ?>
                    </span>
                <?php endif; ?>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo esc_attr($license_dot); ?>"></span>
                    <?php echo esc_html($license_label); ?>
                </span>
            </div>
        </div>

        <div class="superbaddons-admindashboard-sidebarlayout">
            <div class="superbaddons-admindashboard-sidebarlayout-left">

                <!-- Quick Actions -->
                <div class="superbaddons-dashboard-quick-actions">
                    <div class="superbaddons-dashboard-quick-action-card">
                        <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/arrows-left-right-duotone.svg'); ?>" aria-hidden="true" width="48" height="48" />
                        <h5 class="superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800"><?php esc_html_e("Automated Diagnostics", "superb-blocks"); ?></h5>
                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php esc_html_e("Scan and automatically resolve the most common issues with Superb Addons.", "superb-blocks"); ?></p>
                        <button id="spbaddons-quick-action-scan-btn" type="button" class="superbaddons-element-button"><?php esc_html_e("Run Automatic Scan", "superb-blocks"); ?></button>
                    </div>
                    <div class="superbaddons-dashboard-quick-action-card">
                        <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/graduation-cap-duotone.svg'); ?>" aria-hidden="true" width="48" height="48" />
                        <h5 class="superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800"><?php esc_html_e("Knowledge Base", "superb-blocks"); ?></h5>
                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php esc_html_e("Browse tutorials, guides, and step-by-step troubleshooting articles.", "superb-blocks"); ?></p>
                        <a href="#spbaddons-knowledge-base-section" class="superbaddons-element-button"><?php esc_html_e("Browse Articles", "superb-blocks"); ?></a>
                    </div>
                    <div class="superbaddons-dashboard-quick-action-card">
                        <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-subtract-square.svg'); ?>" aria-hidden="true" width="48" height="48" />
                        <h5 class="superbaddons-element-text-xs superbaddons-element-text-dark superbaddons-element-text-800"><?php esc_html_e("Learn the Basics", "superb-blocks"); ?></h5>
                        <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php esc_html_e("Interactive tutorials to get you up and running with Superb Addons.", "superb-blocks"); ?></p>
                        <a href="#spbaddons-learn-section" class="superbaddons-element-button"><?php esc_html_e("Start a Tutorial", "superb-blocks"); ?></a>
                    </div>
                </div>

                <?php if ($has_key_issue && isset($key_status)) : ?>
                    <!-- License Issue Notice -->
                    <div class="spbaddons-license-issue-wrapper spbaddons-support-license-notice">
                        <?php printf('<img src="%s" alt="%s"/>', esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-warning-octagon.svg'), esc_attr__("Issue Detected", "superb-blocks")); ?>
                        <p>
                            <?php
                            if ($key_status['expired']) {
                                esc_html_e('Your subscription has expired. Please renew your subscription or contact support for assistance.', "superb-blocks");
                            } elseif (!$key_status['active']) {
                                esc_html_e('Your license key has been disabled. Please contact support for assistance.', "superb-blocks");
                            } elseif (!$key_status['verified']) {
                                esc_html_e('Your license key verification for this website is no longer valid. Run the automatic scan to resolve this automatically.', "superb-blocks");
                            } elseif ($key_status['exceeded']) {
                                esc_html_e('Your license key has been activated on too many domains. Please renew your subscription, deactivate your license key on some of your domains, or contact support for assistance.', "superb-blocks");
                            }
                            ?>
                            <?php if ($key_status['expired'] || $key_status['exceeded']) : ?>
                                <br />
                                <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::SUPPORT, array("url" => "https://superbthemes.com/renew-subscription/"))); ?>" target="_blank" class="superbaddons-element-colorlink">
                                    <?php esc_html_e("Renew License", "superb-blocks"); ?>
                                </a>
                                <br />
                                <small><?php esc_html_e("If you have already renewed your subscription, run the automatic scan to re-verify your license.", "superb-blocks"); ?></small>
                            <?php endif; ?>
                        </p>
                        <?php if (!$key_status['active']) : ?>
                            <a href="<?php echo esc_url($contact_url); ?>" target="_blank" class="superbaddons-element-button">
                                <?php esc_html_e("Contact Support", "superb-blocks"); ?>
                            </a>
                        <?php else : ?>
                            <button type="button" class="superbaddons-element-button spbaddons-trigger-scan">
                                <?php esc_html_e("Run Automatic Scan", "superb-blocks"); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if (RewriteCheckController::HasDetectedIssue()) : ?>
                    <!-- Rewrite Issue Notice -->
                    <div class="spbaddons-license-issue-wrapper spbaddons-support-rewrite-notice">
                        <?php printf('<img src="%s" alt="%s"/>', esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-warning-octagon.svg'), esc_attr__("Issue Detected", "superb-blocks")); ?>
                        <p>
                            <?php esc_html_e('A permalink configuration issue has been detected that may prevent features like license key activation from working correctly. Run the automatic scan below to resolve this automatically.', "superb-blocks"); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Automated Diagnostics -->
                <div class="superbaddons-settings-section" id="spbaddons-diagnostics-section">
                    <div class="superbaddons-dashboard-section-header">
                        <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php esc_html_e("Automated Diagnostics", "superb-blocks"); ?></h4>
                    </div>
                    <p class="superbaddons-element-text-xs superbaddons-element-text-gray"><?php esc_html_e("Checks WordPress compatibility, API connection, license status, and more — resolves most common issues automatically without any manual steps.", "superb-blocks"); ?></p>
                    <div class="spbaddons-troubleshooting-status-header" id="spbaddons-troubleshooting-status-header">
                        <span id="spbaddons-troubleshooting-status-text"></span>
                    </div>
                    <div class="spbaddons-troubleshooting-progressbar" id="spbaddons-troubleshooting-progressbar"></div>
                    <div class="spbaddons-troubleshooting-steps">
                        <!-- Populated by JS on page load (pending) and during scan (live) -->
                    </div>
                    <div id="spbaddons-troubleshooting-progress" style="display:none;"></div>
                    <button id="spbaddons-troubleshooting-submit-btn" class="superbaddons-element-button" type="button"><span class="spbaddons-btn-text"><?php esc_html_e("Run Automatic Scan", "superb-blocks"); ?></span></button>
                    <p class="spbaddons-troubleshooting-reassurance"><?php esc_html_e("Automatically resolves the most common issues — no manual steps required.", "superb-blocks"); ?></p>
                    <div class="spbaddons-troubleshooting-result-wrapper" style="display:none;">
                        <?php
                        $this->AddResultBox(
                            "error",
                            "color-warning-octagon.svg",
                            __('Troubleshooting failed', "superb-blocks"),
                            array(
                                __('The troubleshooting process was able to identify errors, but was unable to resolve them.', "superb-blocks"),
                                __('If the errors can not be resolved manually, please contact our support team for further assistance.', "superb-blocks")
                            ),
                            $support_cta
                        );

                        $this->AddResultBox(
                            "permalink",
                            "color-warning-octagon.svg",
                            __('Permalink Configuration Issue', "superb-blocks"),
                            array(
                                __('The WordPress REST API could not be reached due to a permalink configuration issue.', "superb-blocks"),
                                "",
                                __('To resolve this manually, go to Settings → Permalinks in your WordPress dashboard and click "Save Changes" without making any changes. This refreshes the internal URL routing rules.', "superb-blocks"),
                                "",
                                __('If the issue persists after refreshing permalinks, please contact our support team or your hosting provider for further assistance.', "superb-blocks")
                            ),
                            array('text' => __('Go to Permalinks Settings', 'superb-blocks'), 'link' => admin_url('options-permalink.php'))
                        );

                        $this->AddResultBox(
                            "network",
                            "cloud-slash.svg",
                            __('Network error', "superb-blocks"),
                            array(
                                __('Your current WordPress and/or webserver configuration is causing issues with the WordPress REST API.', "superb-blocks"),
                                "",
                                __('This issue can be caused by CORS restrictions on your website.', "superb-blocks"),
                                "",
                                __('This issue can also be caused by a misconfigured server, or a security plugin blocking the REST API. You can check if REST API is running correctly on your website by heading to "Tools -> Site Health" from the WordPress dashboard.', "superb-blocks"),
                                "",
                                __('If the issue can not be resolved manually, please contact our support team or your hosting provider for further assistance.', "superb-blocks")
                            ),
                            $support_cta
                        );

                        $this->AddResultBox(
                            "success",
                            "checkmark.svg",
                            __('No issues found', "superb-blocks"),
                            array(
                                __('All troubleshooting steps completed succesfully and no issues were found.', "superb-blocks"),
                                __('If you\'re still experiencing issues, please contact our support team for further assistance.', "superb-blocks")
                            )
                        );

                        $this->AddResultBox(
                            "resolved",
                            "checkmark.svg",
                            __('Issues resolved', "superb-blocks"),
                            array(
                                __('All found issues have been successfully resolved in the troubleshooting process.', "superb-blocks"),
                                __('If you\'re still experiencing issues, please contact our support team for further assistance.', "superb-blocks")
                            )
                        );
                        ?>
                    </div>
                    <div class="spbaddons-troubleshooting-run-again" id="spbaddons-troubleshooting-run-again">
                        <button type="button" class="superbaddons-element-button" id="spbaddons-troubleshooting-run-again-btn"><?php esc_html_e("Run Again", "superb-blocks"); ?></button>
                    </div>
                </div>

                <!-- Learn the Basics -->
                <div class="superbaddons-settings-section" id="spbaddons-learn-section">
                    <div class="superbaddons-dashboard-section-header">
                        <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php esc_html_e("Learn the Basics", "superb-blocks"); ?></h4>
                    </div>
                    <p class="superbaddons-element-text-xs superbaddons-element-text-gray"><?php esc_html_e("Follow along with hands-on tutorials that walk you through the core features — from inserting blocks to browsing the Design Library in the Gutenberg editor.", "superb-blocks"); ?></p>
                    <div class="superbaddons-dashboard-feature-grid">
                        <div class="superbaddons-dashboard-feature-card" id="superbaddons-tour-dashboard-welcome">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/icon-superb.svg'); ?>" aria-hidden="true" style="width:30px;" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("Dashboard Tour", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("A quick walkthrough of the Superb Addons dashboard.", "superb-blocks"); ?></p>
                            <a class="superbaddons-element-button superbaddons-element-button-sm" href="<?php echo esc_url(add_query_arg(array('page' => DashboardController::MENU_SLUG, 'superb-tour' => 'dashboard'), admin_url('admin.php'))); ?>"><?php esc_html_e("Start Tutorial", "superb-blocks"); ?></a>
                        </div>
                        <div class="superbaddons-dashboard-feature-card superbaddons-start-tutorial-link-gutenberg" id="superbaddons-tour-gutenberg-patterns">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-subtract-square.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("Design Library", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("Let's show you where and how to browse and insert patterns from the Design Library.", "superb-blocks"); ?></p>
                            <a class="superbaddons-element-button superbaddons-element-button-sm" href="<?php echo esc_url(add_query_arg(array(TourController::TOUR_GUTENBERG => TourController::GUTENBERG_TOUR_PATTERNS, TourController::TOUR_NONCE_PARAM => wp_create_nonce(TourController::TOUR_NONCE_ACTION)), admin_url('post-new.php'))); ?>"><?php esc_html_e("Start Tutorial", "superb-blocks"); ?></a>
                        </div>
                        <div class="superbaddons-dashboard-feature-card superbaddons-start-tutorial-link-gutenberg" id="superbaddons-tour-gutenberg-blocks">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-cube.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("Gutenberg Blocks", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("How do you insert the included blocks? This tutorial will show you.", "superb-blocks"); ?></p>
                            <a class="superbaddons-element-button superbaddons-element-button-sm" href="<?php echo esc_url(add_query_arg(array(TourController::TOUR_GUTENBERG => TourController::GUTENBERG_TOUR_BLOCKS, TourController::TOUR_NONCE_PARAM => wp_create_nonce(TourController::TOUR_NONCE_ACTION)), admin_url('post-new.php'))); ?>"><?php esc_html_e("Start Tutorial", "superb-blocks"); ?></a>
                        </div>
                        <?php if (ElementorController::is_compatible()) : ?>
                            <div class="superbaddons-dashboard-feature-card superbaddons-start-tutorial-link-elementor" id="superbaddons-tour-elementor">
                                <div class="superbaddons-dashboard-feature-card-header">
                                    <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/logo-elementor.svg'); ?>" aria-hidden="true" />
                                    <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("Elementor Addons", "superb-blocks"); ?></strong>
                                </div>
                                <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("See how you can use our Elementor addons to build beautiful pages.", "superb-blocks"); ?></p>
                                <a class="superbaddons-element-button superbaddons-element-button-sm" href="<?php echo esc_url('#' . TourController::TOUR_ELEMENTOR); ?>"><?php esc_html_e("Start Tutorial", "superb-blocks"); ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Knowledge Base -->
                <div class="superbaddons-settings-section" id="spbaddons-knowledge-base-section">
                    <div class="superbaddons-dashboard-section-header">
                        <h4 class="superbaddons-element-text-sm superbaddons-element-text-dark superbaddons-element-text-800 superbaddons-element-m0"><?php esc_html_e("Knowledge Base", "superb-blocks"); ?></h4>
                    </div>
                    <p class="superbaddons-element-text-xs superbaddons-element-text-gray"><?php esc_html_e("In-depth articles and reference guides covering themes, plugins, WordPress administration, and common troubleshooting scenarios.", "superb-blocks"); ?></p>
                    <div class="superbaddons-dashboard-feature-grid">
                        <div class="superbaddons-dashboard-feature-card">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-list-bullets.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("All Tutorials", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("Browse our complete collection of tutorials and guides.", "superb-blocks"); ?></p>
                            <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::SUPPORT, array("url" => "https://superbthemes.com/documentation/"))); ?>" target="_blank" class="superbaddons-element-button superbaddons-element-button-sm"><?php esc_html_e("View All", "superb-blocks"); ?></a>
                        </div>
                        <div class="superbaddons-dashboard-feature-card">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/graduation-cap-duotone.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("Getting Started", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("Learn the basics of setting up your plugins, themes, and website.", "superb-blocks"); ?></p>
                            <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::SUPPORT, array("url" => "https://superbthemes.com/documentation/category/getting-started/"))); ?>" target="_blank" class="superbaddons-element-button superbaddons-element-button-sm"><?php esc_html_e("View Tutorials", "superb-blocks"); ?></a>
                        </div>
                        <div class="superbaddons-dashboard-feature-card">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/cube-duotone.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("Themes & Design", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("Guides on layouts, typography, pages, and navigation.", "superb-blocks"); ?></p>
                            <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::SUPPORT, array("url" => "https://superbthemes.com/documentation/category/themes-design/"))); ?>" target="_blank" class="superbaddons-element-button superbaddons-element-button-sm"><?php esc_html_e("View Tutorials", "superb-blocks"); ?></a>
                        </div>
                        <div class="superbaddons-dashboard-feature-card">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-features-blocks.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("Plugins & Superb Addons", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("Guides on features, settings, and best practices.", "superb-blocks"); ?></p>
                            <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::SUPPORT, array("url" => "https://superbthemes.com/documentation/category/plugins-superb-addons/"))); ?>" target="_blank" class="superbaddons-element-button superbaddons-element-button-sm"><?php esc_html_e("View Tutorials", "superb-blocks"); ?></a>
                        </div>
                        <div class="superbaddons-dashboard-feature-card">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-wp.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("WordPress Basics", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("Site configuration, content management, and general administration.", "superb-blocks"); ?></p>
                            <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::SUPPORT, array("url" => "https://superbthemes.com/documentation/category/wordpress-basics/"))); ?>" target="_blank" class="superbaddons-element-button superbaddons-element-button-sm"><?php esc_html_e("View Tutorials", "superb-blocks"); ?></a>
                        </div>
                        <div class="superbaddons-dashboard-feature-card">
                            <div class="superbaddons-dashboard-feature-card-header">
                                <img class="superbaddons-dashboard-block-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/arrows-left-right-duotone.svg'); ?>" aria-hidden="true" />
                                <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("Troubleshooting", "superb-blocks"); ?></strong>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("Step-by-step solutions to common problems and site issues.", "superb-blocks"); ?></p>
                            <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::SUPPORT, array("url" => "https://superbthemes.com/documentation/category/troubleshooting/"))); ?>" target="_blank" class="superbaddons-element-button superbaddons-element-button-sm"><?php esc_html_e("View Tutorials", "superb-blocks"); ?></a>
                        </div>
                    </div>
                    <div class="superbaddons-dashboard-suggest-card superbaddons-support-suggest-card">
                        <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-bulb.svg'); ?>" aria-hidden="true" />
                        <div class="superbaddons-dashboard-suggest-card-content">
                            <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php esc_html_e("Missing Something?", "superb-blocks"); ?></strong>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php esc_html_e("Help shape the future of Superb Addons by sharing your ideas and feature requests.", "superb-blocks"); ?></p>
                        </div>
                        <a href="<?php echo esc_url($contact_url); ?>" target="_blank" class="superbaddons-element-colorlink superbaddons-element-text-xxs"><?php esc_html_e("Request Feature", "superb-blocks"); ?> &rarr;</a>
                    </div>
                </div>

            </div>
            <div class="superbaddons-admindashboard-sidebarlayout-right">
                <?php
                new SupportBox();
                new ReviewBox();
                new PremiumBox(AdminLinkSource::SUPPORT);
                ?>
            </div>
        </div>
    <?php
        new Modal();
    }

    private function AddResultBox($identity, $icon, $title, $text_arr, $cta = false)
    {
    ?>
        <div class="spbaddons-troubleshooting-result-item spbaddons-troubleshooting-result-<?php echo esc_attr($identity); ?>" style="display:none;">
            <div class="spbaddons-troubleshooting-result-item-header">
                <img class="spbaddons-troubleshooting-result-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/' . $icon); ?>" />
                <h5>
                    <?php echo esc_html($title); ?>
                </h5>
            </div>
            <div class="spbaddons-troubleshooting-result-item-body">
                <?php
                foreach ($text_arr as $text) {
                    if (empty($text)) {
                        echo "<br>";
                    }
                ?>
                    <p>
                        <?php echo esc_html($text); ?>
                    </p>
                <?php
                }
                ?>
            </div>
            <?php if ($cta && isset($cta['text']) && isset($cta['link'])) : ?>
                <div class="spbaddons-troubleshooting-result-item-cta">
                    <a class="superbaddons-element-button" href="<?php echo esc_url($cta['link']); ?>" target="_blank"><?php echo esc_html($cta['text']); ?></a>
                </div>
            <?php endif; ?>
        </div>
<?php
    }
}
