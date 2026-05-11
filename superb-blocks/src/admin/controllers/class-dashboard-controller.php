<?php

namespace SuperbAddons\Admin\Controllers;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Controllers\Wizard\WizardController;
use SuperbAddons\Admin\Pages\AdditionalCSSPage;
use SuperbAddons\Admin\Pages\DashboardPage;
use SuperbAddons\Admin\Pages\FormsPage;
use SuperbAddons\Admin\Pages\SettingsPage;
use SuperbAddons\Admin\Pages\SupportPage;
use SuperbAddons\Admin\Pages\Wizard\PageWizardMainPage;
use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Components\Admin\FeedbackModal;
use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\RestController;

use SuperbAddons\Components\Admin\Navigation;
use SuperbAddons\Data\Controllers\CacheController;
use SuperbAddons\Data\Controllers\CSSController;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Utils\CacheTypes;
use SuperbAddons\Data\Utils\GutenbergCache;
use SuperbAddons\Data\Utils\AllowedTemplateHTMLUtil;
use SuperbAddons\Data\Utils\ScriptTranslations;
use SuperbAddons\Data\Utils\Wizard\WizardActionParameter;
use SuperbAddons\Elementor\Controllers\ElementorController;
use SuperbAddons\Gutenberg\Controllers\GutenbergController;
use SuperbAddons\Gutenberg\Controllers\GutenbergEnhancementsController;
use SuperbAddons\Library\Controllers\FavoritesController;
use SuperbAddons\Library\Controllers\LibraryRequestController;
use SuperbAddons\Gutenberg\Form\FormPermissions;
use SuperbAddons\Tours\Controllers\TourController;

class DashboardController
{
    const MENU_SLUG = 'superbaddons';
    const DASHBOARD = 'dashboard';
    const ADDITIONAL_CSS = 'superbaddons-additional-css';
    const SETTINGS = 'superbaddons-settings';
    const SUPPORT = 'superbaddons-support';
    const FORMS = 'superbaddons-forms';

    const PAGE_WIZARD = 'superbaddons-page-wizard';

    const THEME_DESIGNER_REDIRECT_SLUG = 'superbaddons-theme-designer';
    const STYLEBOOK_REDIRECT_SLUG = 'superbaddons-stylebook';

    const PREMIUM_CLASS = 'superbaddons-get-premium';

    private $hooks;

    public function __construct()
    {
        new SettingsController();
        new TroubleshootingController();
        NewsletterSignupController::Initialize();
        $this->hooks = array();
        add_action("admin_menu", array($this, 'SuperbAddonsAdminMenu'));
        add_action("admin_menu", array($this, 'AdminMenuAdditions'));
        add_action('admin_init', array($this, 'MaybeActivationRedirect'));
        add_action('admin_init', array($this, 'ConditionalThemePageRedirect'));
        add_filter('plugin_action_links_' . SUPERBADDONS_BASE, array($this, 'PluginActions'));
        add_action('admin_enqueue_scripts', array($this, 'AdminMenuEnqueues'), 1000);
        if (!KeyController::HasValidPremiumKey()) {
            add_action("admin_head", array($this, 'AdminMenuHighlightScripts'));
        }
        $this->HandleNotices();
    }


    public function PluginActions($actions)
    {
        $added_actions = array(
            "<a href='" . esc_url(admin_url("admin.php?page=" . self::MENU_SLUG)) . "'>" . esc_html__('Dashboard', "superb-blocks") . "</a>",
            "<a href='" . esc_url(admin_url("admin.php?page=" . self::SETTINGS)) . "'>" . esc_html__('Settings', "superb-blocks") . "</a>",
            "<a href='" . esc_url(admin_url("admin.php?page=" . self::SUPPORT)) . "'>" . esc_html__('Get Help', "superb-blocks") . "</a>"
        );
        $actions = array_merge($added_actions, $actions);
        if (!KeyController::HasValidPremiumKey()) {
            $actions[] = "<a href='" . esc_url(AdminLinkUtil::GetLink(AdminLinkSource::WP_PLUGIN_PAGE)) . "' class='" . self::PREMIUM_CLASS . "' target='_blank'>" . esc_html__('Get Premium', "superb-blocks") . "</a>";
        }
        return $actions;
    }

    public function SuperbAddonsAdminMenu()
    {
        add_menu_page(__('Superb Addons', "superb-blocks"), __('Superb Addons', "superb-blocks") . $this->GetAdminMenuNotification(), Capabilities::CONTRIBUTOR, self::MENU_SLUG, array($this, 'SuperbDashboard'), SUPERBADDONS_ASSETS_PATH . '/img/icon-superb-dashboard-menu.png', '58.6');
        $this->hooks[self::DASHBOARD] = add_submenu_page(self::MENU_SLUG, __('Superb Addons - Dashboard', "superb-blocks"), __('Dashboard', "superb-blocks"), Capabilities::CONTRIBUTOR, self::MENU_SLUG);
        $this->hooks[self::PAGE_WIZARD] = add_submenu_page(self::MENU_SLUG, __('Superb Addons - Theme Designer', "superb-blocks"), __('Theme Designer', "superb-blocks"), Capabilities::ADMIN, self::PAGE_WIZARD, array($this, 'PageWizard'));
        // Forms menu: visible to admins and roles with 'view' form permission
        $forms_capability = FormPermissions::Can('view') ? 'read' : Capabilities::ADMIN;
        $this->hooks[self::FORMS] = add_submenu_page(self::MENU_SLUG, __('Superb Addons - Forms', "superb-blocks"), __('Forms', "superb-blocks"), $forms_capability, self::FORMS, array($this, 'Forms'));
        $this->hooks[self::ADDITIONAL_CSS] = add_submenu_page(self::MENU_SLUG, __('Superb Addons - Custom CSS', "superb-blocks"), __('Custom CSS', "superb-blocks"), Capabilities::ADMIN, self::ADDITIONAL_CSS, array($this, 'AdditionalCSS'));
        $this->hooks[self::SETTINGS] = add_submenu_page(self::MENU_SLUG, __('Superb Addons - Settings', "superb-blocks"), __('Settings', "superb-blocks"), Capabilities::ADMIN, self::SETTINGS, array($this, 'Settings'));
        $this->hooks[self::SUPPORT] = add_submenu_page(self::MENU_SLUG, __('Superb Addons - Get Help', "superb-blocks"), __('Get Help', "superb-blocks") . $this->GetAdminMenuNotification(), Capabilities::CONTRIBUTOR, self::SUPPORT, array($this, 'Support'));
    }

    public function AdminMenuAdditions()
    {
        // Block theme related admin menu additions
        if (!function_exists('wp_is_block_theme') || !wp_is_block_theme()) return;

        // Gated by the Admin Shortcuts module toggle (Settings > Modules).
        if (!self::IsDashboardShortcutsEnabled()) return;

        $front_page_template = get_block_template(get_stylesheet() . "//front-page");
        if ($front_page_template && isset($front_page_template->id)) {
            add_pages_page(
                __('Edit Front Page', "superb-blocks"),
                __('Edit Front Page', "superb-blocks"),
                Capabilities::ADMIN,
                add_query_arg(
                    array(
                        'postType' => 'wp_template',
                        'postId'   => urlencode($front_page_template->id),
                        'canvas'   => 'edit',
                    ),
                    admin_url('site-editor.php')
                )
            );
        }

        add_pages_page(
            __('Add Template Page', "superb-blocks"),
            __('Add Template Page', "superb-blocks"),
            Capabilities::ADMIN,
            WizardController::GetWizardURL(WizardActionParameter::ADD_NEW_PAGES)
        );

        add_theme_page(
            __('Theme Designer', "superb-blocks"),
            __('Theme Designer', "superb-blocks"),
            Capabilities::ADMIN,
            self::THEME_DESIGNER_REDIRECT_SLUG,
            array($this, 'ThemeDesignerRedirectFallbackPage')
        );
        add_theme_page(
            __('Style Book', "superb-blocks"),
            __('Style Book', "superb-blocks"),
            Capabilities::ADMIN,
            self::STYLEBOOK_REDIRECT_SLUG,
            array($this, 'StylesRedirectFallbackPage')
        );
    }

    private static function IsDashboardShortcutsEnabled()
    {
        $options = GutenbergEnhancementsController::GetGlobalEnhancementsOptions();
        return !empty($options[GutenbergEnhancementsController::DASHBOARD_SHORTCUTS_KEY]);
    }

    public function MaybeActivationRedirect()
    {
        if (!get_transient('superbaddons_activation_redirect')) {
            return;
        }

        delete_transient('superbaddons_activation_redirect');

        // Nonce not required: only checking presence of WP core's activate-multi flag to bail out of our own redirect, no data processed.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (is_network_admin() || isset($_GET['activate-multi'])) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    public function ConditionalThemePageRedirect()
    {
        // Check if we are heading to a theme page. Ensure the user has the required capability.
        // Nonce not required as this is a simple redirect.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['page'])) {
            return;
        }

        // Gated by the Admin Shortcuts module toggle (Settings > Modules).
        if (!self::IsDashboardShortcutsEnabled()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = sanitize_text_field(wp_unslash($_GET['page']));
        if (!in_array($page, array(self::THEME_DESIGNER_REDIRECT_SLUG, self::STYLEBOOK_REDIRECT_SLUG)) || !current_user_can(Capabilities::ADMIN)) {
            return;
        }

        $target_url = false;
        switch ($page) {
            case self::THEME_DESIGNER_REDIRECT_SLUG:
                $target_url = WizardController::GetWizardURL(WizardActionParameter::INTRO);
                break;
            case self::STYLEBOOK_REDIRECT_SLUG:
                $target_url = $this->GetStylebookURL();
                break;
        }

        if ($target_url) {
            wp_safe_redirect($target_url);
            exit;
        }

        // If the target URL is not set, redirect to the default plugin page.
        wp_safe_redirect(admin_url('admin.php?page=' . self::MENU_SLUG));
        exit;
    }

    private function GetStylebookURL()
    {
        $stylebook_url = add_query_arg(
            array(
                'p' => urlencode('/styles'),
                'preview' => 'stylebook'
            ),
            admin_url('site-editor.php')
        );
        return $stylebook_url;
    }

    public function StylesRedirectFallbackPage()
    {
        $target_url = $this->GetStylebookURL();
        $target_page_label = __('Stylebook', "superb-blocks");
        $this->GenericRedirectFallbackPage($target_page_label, $target_url);
    }

    public function ThemeDesignerRedirectFallbackPage()
    {
        $target_url = WizardController::GetWizardURL(WizardActionParameter::INTRO);
        $target_page_label = __('Theme Designer', "superb-blocks");
        $this->GenericRedirectFallbackPage($target_page_label, $target_url);
    }

    private function GenericRedirectFallbackPage($target_page_label = false, $target_url = false)
    {
        if (!$target_page_label) {
            $target_page_label = __('Superb Addons', "superb-blocks");
        }
        if (!$target_url) {
            $target_url = admin_url('admin.php?page=' . self::MENU_SLUG); // Fallback URL
        }
        // This content will be shown if the ConditionalThemeDesignerRedirect redirect fails or is bypassed.
        echo '<div class="wrap">';
        echo '</div>';

        echo '<div class="superbaddons-theme-designer-redirect">';
        echo '<div class="superbaddons-theme-designer-redirect-card">';
        echo '<div class="superbaddons-theme-designer-redirect-header">';
        echo '<img src="' . esc_url(SUPERBADDONS_ASSETS_PATH . '/img/icon-superb-dashboard-menu.png') . '" alt="' . esc_attr__('Superb Addons', 'superb-blocks') . '">';
        echo '<h1>' . esc_html__('Theme Designer', 'superb-blocks') . '</h1>';
        echo '</div>';

        echo '<p>' . esc_html__('Oops. Looks like you were not correctly redirected. Please click the link below.', 'superb-blocks') . '</p>';
        echo '<p><a href="' . esc_url($target_url) . '">' . esc_html(sprintf(/* translators: %s: title of a page*/__('Go to %s', 'superb-blocks'), $target_page_label)) . '</a></p>';

        echo '<style>';
        echo '.superbaddons-theme-designer-redirect { display: flex; justify-content: baseline; align-items: center; }';
        echo '.superbaddons-theme-designer-redirect-card { display: flex; flex-direction: column; align-items: center; background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1); }';
        echo '.superbaddons-theme-designer-redirect-header { display: flex; align-items: center; }';
        echo '.superbaddons-theme-designer-redirect-header img { width: 20px; height: 20px; margin-right: 10px; }';
        echo '.superbaddons-theme-designer-redirect-header h1 { font-size: 24px; }';
        echo '.superbaddons-theme-designer-redirect p { font-size: 16px; }';
        echo '.superbaddons-theme-designer-redirect a { color: #0073aa; text-decoration: none; }';
        echo '.superbaddons-theme-designer-redirect a:hover { text-decoration: underline; }';
        echo '</style>';
        echo '<div class="superbaddons-theme-designer-redirect-footer">';
        echo '<p>' . esc_html__('If you continue to experience issues, please contact support.', 'superb-blocks') . '</p>';
        echo '<p><a href="' . esc_url(AdminLinkUtil::GetLink(AdminLinkSource::DEFAULT, array('url' => 'https://superbthemes.com/contact/'))) . '" target="_blank" rel="noopener">' . esc_html__('Contact Support', 'superb-blocks') . '</a></p>';

        echo '</div>';
        echo '</div>';
    }

    private function GetAdminMenuNotification()
    {
        $HasRegisteredKey = KeyController::HasRegisteredKey();
        if ($HasRegisteredKey) {
            $KeyStatus = KeyController::GetKeyStatus();
            if (!$KeyStatus['active'] || $KeyStatus['expired'] || !$KeyStatus['verified'] || $KeyStatus['exceeded']) {
                return sprintf('<span class="update-plugins count-1"><span class="plugin-count" aria-hidden="true">!</span><span class="screen-reader-text">%s</span></span>', esc_html__("Issue Detected", "superb-blocks"));
            }
        }

        if (RewriteCheckController::HasDetectedIssue()) {
            return sprintf('<span class="update-plugins count-1"><span class="plugin-count" aria-hidden="true">!</span><span class="screen-reader-text">%s</span></span>', esc_html__("Issue Detected", "superb-blocks"));
        }

        return;
    }

    public function AdminMenuHighlightScripts()
    {
?>
        <style>
            tbody#the-list .<?php echo esc_html(self::PREMIUM_CLASS); ?> {
                color: #4312E2;
                font-weight: 900;
            }
        </style>
    <?php
    }

    public function HandleNotices()
    {
        add_action('wp_loaded', function () {
            $options = array("notices" => array());
            if (!KeyController::HasValidPremiumKey()) {
                $options["notices"][] = array(
                    'unique_id' => 'addons_delayed',
                    'content' => "addons-notice.php",
                    'delay' => '+6 days'
                );
            }
            if (WizardController::GetWizardRecommenderTransient()) {
                $options["notices"][] = array(
                    'unique_id' => 'wizard_recommender',
                    'content' => "wizard-recommender-notice.php"
                );
            } elseif (WizardController::GetWizardWoocommerceTransient()) {
                $options["notices"][] = array(
                    'unique_id' => 'wizard_woocommerce',
                    'content' => "wizard-woocommerce-notice.php"
                );
            }
            AdminNoticeController::init($options);
        });
    }

    public function AdminMenuEnqueues($page_hook)
    {
        if ($page_hook === 'plugins.php') {
            $this->enqueueCommonStyles();
            $this->enqueueFeedback();
            return;
        }

        if (!in_array($page_hook, array_values($this->hooks))) {
            return;
        }

        $this->enqueueCommonStyles();
        $this->enqueueUpsellModal();
        wp_enqueue_style(
            'superb-addons-admin-dashboard',
            SUPERBADDONS_ASSETS_PATH . '/css/admin-dashboard.min.css',
            array(),
            SUPERBADDONS_VERSION
        );

        switch ($page_hook) {
            case $this->hooks[self::DASHBOARD]:
                $this->enqueuePatternLibraryBase();
                $this->enqueueDashboard();
                break;

            case $this->hooks[self::SUPPORT]:
                $this->enqueueSupport();
                break;

            case $this->hooks[self::SETTINGS]:
                $this->enqueueSettings();
                break;

            case $this->hooks[self::ADDITIONAL_CSS]:
                $this->enqueueAdditionalCSS();
                break;

            case $this->hooks[self::PAGE_WIZARD]:
                $this->enqueuePageWizard();
                break;

            case $this->hooks[self::FORMS]:
                $this->enqueueForms();
                break;
        }
    }

    private function enqueueCommonStyles()
    {
        wp_enqueue_style(
            'superb-addons-elements',
            SUPERBADDONS_ASSETS_PATH . '/css/framework.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_style(
            'superb-addons-font-manrope',
            SUPERBADDONS_ASSETS_PATH . '/fonts/manrope/manrope.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_style(
            'superb-addons-admin-modal',
            SUPERBADDONS_ASSETS_PATH . '/css/admin-modal.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_style(
            'superbaddons-toast',
            SUPERBADDONS_ASSETS_PATH . '/css/toast.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
    }

    // Vanilla upsell modal bundle. Delegates clicks on any element with
    // data-superb-upsell-source to open the modal before the underlying
    // anchor navigates (PremiumButton / PremiumOptionWrapper both emit
    // that attribute). wp-url and wp-escape-html are required because
    // premium-link-source.js destructures wp.url and wp.escapeHtml at
    // module load.
    private function enqueueUpsellModal()
    {
        wp_enqueue_script(
            'superb-addons-upsell-modal-admin',
            SUPERBADDONS_ASSETS_PATH . '/js/admin/upsell-modal.js',
            array('wp-i18n', 'wp-url', 'wp-escape-html'),
            SUPERBADDONS_VERSION,
            true
        );
        ScriptTranslations::Set('superb-addons-upsell-modal-admin');
    }

    private function enqueueFeedback()
    {
        wp_enqueue_script('superb-addons-feedback', SUPERBADDONS_ASSETS_PATH . '/js/admin/deactivate-feedback.js', array('jquery'), SUPERBADDONS_VERSION, true);
        wp_localize_script('superb-addons-feedback', 'superbaddonssettings_g', array(
            "plugin" => plugin_basename(SUPERBADDONS_BASE),
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "routes" => array(
                    "settings" => SettingsController::SETTINGS_ROUTE,
                )
            )
        ));
        add_action('admin_footer', function () {
            new FeedbackModal();
        });
    }

    private function enqueuePatternLibraryBase()
    {
        GutenbergController::AddonsLibrary();
        wp_enqueue_script('superb-addons-select2', SUPERBADDONS_ASSETS_PATH . '/lib/select2.min.js', array('jquery'), SUPERBADDONS_VERSION, true);
        wp_enqueue_style(
            'superb-dashboard-layout-library',
            SUPERBADDONS_ASSETS_PATH . '/css/layout-library-editor.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_style(
            'superbaddons-select2',
            SUPERBADDONS_ASSETS_PATH . '/lib/select2.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
    }

    private function enqueueDashboard()
    {
        wp_enqueue_script('superb-addons-library-dashboard', SUPERBADDONS_ASSETS_PATH . '/js/admin/dashboard.js', array('jquery', "wp-i18n"), SUPERBADDONS_VERSION, true);
        ScriptTranslations::Set('superb-addons-library-dashboard');
        wp_localize_script('superb-addons-library-dashboard', 'superblayoutlibrary_g', array(
            "style_placeholder" => esc_html__('All themes', "superb-blocks"),
            "category_placeholder" => esc_html__('All categories', "superb-blocks"),
            "snacks" => array(
                "list_error" => esc_html__('Something went wrong while attempting to list elements. Please try again or contact support if the problem persists.', "superb-blocks")
            ),
            "gutenberg_menu_items" => GutenbergController::GetGutenbergLibraryMenuItems(),
            "elementor_menu_items" => ElementorController::GetElementorLibraryMenuItems(),
            "chunk_route" => LibraryRequestController::GUTENBERG_V2_LIST_CHUNK_ROUTE,
            "favorites" => FavoritesController::GetFavorites(get_current_user_id()),
            "tutorial_urls" => array(
                "gutenberg" => esc_url_raw(add_query_arg(
                    array(
                        TourController::TOUR_GUTENBERG => TourController::GUTENBERG_TOUR_PATTERNS,
                        TourController::TOUR_NONCE_PARAM => wp_create_nonce(TourController::TOUR_NONCE_ACTION),
                    ),
                    admin_url('post-new.php')
                )),
                "elementor" => esc_url_raw(add_query_arg(
                    array(
                        TourController::TOUR_GUTENBERG => TourController::GUTENBERG_TOUR_PATTERNS,
                        TourController::TOUR_NONCE_PARAM => wp_create_nonce(TourController::TOUR_NONCE_ACTION),
                    ),
                    admin_url('post-new.php')
                )),
            ),
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "routes" => array(
                    "settings" => SettingsController::SETTINGS_ROUTE,
                )
            )
        ));

        // Dashboard Welcome Tour
        wp_enqueue_style(
            'superbaddons-driver',
            SUPERBADDONS_ASSETS_PATH . '/lib/driver.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_script('superb-addons-tour-dashboard', SUPERBADDONS_ASSETS_PATH . '/js/guided-tours/dashboard-welcome.js', array('wp-i18n', 'jquery', 'superb-addons-library-dashboard'), SUPERBADDONS_VERSION, true);
        ScriptTranslations::Set('superb-addons-tour-dashboard');
        wp_localize_script('superb-addons-tour-dashboard', 'superbaddonstour_g', array(
            "auto_start" => !TourController::IsTourCompleted(TourController::TOUR_DASHBOARD_WELCOME_META),
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "routes" => array(
                    "tutorial" => TroubleshootingController::TUTORIAL_ROUTE,
                )
            )
        ));
    }

    private function enqueueSupport()
    {
        wp_enqueue_script('superb-addons-troubleshooting', SUPERBADDONS_ASSETS_PATH . '/js/admin/troubleshooting.js', array('jquery', 'wp-i18n'), SUPERBADDONS_VERSION, true);
        ScriptTranslations::Set('superb-addons-troubleshooting');
        wp_localize_script('superb-addons-troubleshooting', 'superbaddonstroubleshooting_g', array(
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "fallback_url" => add_query_arg(
                    'rest_route',
                    '/' . RestController::NAMESPACE . TroubleshootingController::TROUBLESHOOTING_ROUTE,
                    trailingslashit(home_url()) . 'index.php'
                ),
                "routes" => array(
                    "troubleshooting" => TroubleshootingController::TROUBLESHOOTING_ROUTE,
                    "tutorial" => TroubleshootingController::TUTORIAL_ROUTE,
                )
            ),
            "steps" => array(
                "restcheck" => array(
                    "title" => esc_html__("REST API Status", "superb-blocks"),
                    "text" => esc_html__("Checking REST API", "superb-blocks"),
                    "errorText" => esc_html__("REST API Unavailable", "superb-blocks"),
                    "successText" => esc_html__("REST API Available", "superb-blocks"),
                ),
                "restfix" => array(
                    "title" => esc_html__("Permalink Configuration", "superb-blocks"),
                    "text" => esc_html__("Refreshing Permalinks", "superb-blocks"),
                    "errorText" => esc_html__("Could not refresh permalinks", "superb-blocks"),
                    "successText" => esc_html__("Permalinks Refreshed", "superb-blocks"),
                ),
                "connection" => array(
                    "title" => esc_html__("Connection Status", "superb-blocks"),
                    "text" => esc_html__("Checking Connection", "superb-blocks"),
                    "errorText" => esc_html__("No Connection", "superb-blocks"),
                    "successText" => esc_html__("Connected", "superb-blocks"),
                ),
                "domainshift" => array(
                    "title" => esc_html__("Connection Update", "superb-blocks"),
                    "text" => esc_html__("Trying New Connection", "superb-blocks"),
                    "errorText" => esc_html__("Connection Blocked", "superb-blocks"),
                    "successText" => esc_html__("Connected", "superb-blocks"),
                ),
                "service" => array(
                    "title" => esc_html__("Service Status", "superb-blocks"),
                    "text" => esc_html__("Checking Service", "superb-blocks"),
                    "errorText" => esc_html__("Service Unavailable", "superb-blocks"),
                    "successText" => esc_html__("Service Online", "superb-blocks"),
                ),
                "keycheck" => array(
                    "title" => esc_html__("License Key Status", "superb-blocks"),
                    "text" => esc_html__("Checking License Key", "superb-blocks"),
                    "errorText" => esc_html__("Invalid License Key", "superb-blocks"),
                    "successText" => esc_html__("Valid License Key", "superb-blocks"),
                ),
                "keyverify" => array(
                    "title" => esc_html__("License Key Verification", "superb-blocks"),
                    "text" => esc_html__("Re-verifying License Key", "superb-blocks"),
                    "errorText" => esc_html__("License could not be verified", "superb-blocks"),
                    "successText" => esc_html__("License Key Verified", "superb-blocks"),
                ),
                "cacheclear" => array(
                    "title" => esc_html__("Cache Status", "superb-blocks"),
                    "text" => esc_html__("Clearing Cache", "superb-blocks"),
                    "errorText" => esc_html__("Cache could not be cleared", "superb-blocks"),
                    "successText" => esc_html__("Cache Cleared", "superb-blocks"),
                )
            )
        ));
        add_action("admin_footer", array($this, 'TroubleshootingTemplates'));
    }

    private function enqueueSettings()
    {
        wp_enqueue_script('superb-addons-settings', SUPERBADDONS_ASSETS_PATH . '/js/admin/settings.js', array('jquery'), SUPERBADDONS_VERSION, true);
        wp_localize_script('superb-addons-settings', 'superbaddonssettings_g', array(
            "save_message" => esc_html__("Settings saved successfully.", "superb-blocks"),
            "modal" => array(
                "cache" => array(
                    "title" => esc_html__("Clear Cache", "superb-blocks"),
                    "content" => esc_html__("All element data and images will need to be loaded again if the cache is removed. This should only be done if you are experiencing issues with the design library or theme designer. Are you sure you want to clear the cache?", "superb-blocks"),
                    "success" => esc_html__("Cache cleared successfully.", "superb-blocks")
                ),
                "view_logs" => array(
                    "title" => esc_html__("Error Log", "superb-blocks"),
                    "no_logs" => esc_html__("No errors have been logged.", "superb-blocks"),
                    "icon_unshared" => esc_url(SUPERBADDONS_ASSETS_PATH . "/img/cloud-slash.svg"),
                    "unshared_title" => esc_html__("Error Log Not Shared", "superb-blocks"),
                    "icon_shared" => esc_url(SUPERBADDONS_ASSETS_PATH . "/img/cloud-check.svg"),
                    "shared_title" => esc_html__("Error Log Shared", "superb-blocks"),
                ),
                "clear_logs" => array(
                    "title" => esc_html__("Clear Logs", "superb-blocks"),
                    "content" => esc_html__("Error Logs are used for debugging purposes and help improve the plugin when shared with our support team and developers. Are you sure you want to clear the error logs?", "superb-blocks"),
                    "success" => esc_html__("Error logs cleared successfully.", "superb-blocks")
                ),
                "remove_key" => array(
                    "title" => esc_html__("Remove License Key", "superb-blocks"),
                    "content" => esc_html__("Are you sure you want to remove your license key from this website?", "superb-blocks"),
                ),
                "clear_restoration_points" => array(
                    "title" => esc_html__("Clear Restoration Points", "superb-blocks"),
                    "content" => esc_html__("Restoration points can not be recovered after being cleared. Are you sure you want to clear all restoration points?", "superb-blocks"),
                    "success" => esc_html__("Restoration points cleared successfully.", "superb-blocks")
                ),
                "remove_mailchimp_key" => array(
                    "title" => esc_html__("Remove Mailchimp API Key", "superb-blocks"),
                    "content" => esc_html__("Are you sure you want to remove your Mailchimp API key? Forms using the Mailchimp integration will stop sending subscribers until a new key is added.", "superb-blocks"),
                ),
                "remove_brevo_key" => array(
                    "title" => esc_html__("Remove Brevo API Key", "superb-blocks"),
                    "content" => esc_html__("Are you sure you want to remove your Brevo API key? Forms using the Brevo integration will stop sending contacts until a new key is added.", "superb-blocks"),
                ),
                "remove_captcha_key" => array(
                    "title" => esc_html__("Remove CAPTCHA Keys", "superb-blocks"),
                    "content" => esc_html__("Are you sure you want to remove these CAPTCHA keys? Forms using this provider will fall back to basic spam protection.", "superb-blocks"),
                ),
                "remove_captcha_key_in_use" => array(
                    "title" => esc_html__("Remove CAPTCHA Keys", "superb-blocks"),
                    /* translators: %d: number of forms using this CAPTCHA provider */
                    "content" => esc_html__("This CAPTCHA provider is currently in use on %d forms. Removing the keys will disable CAPTCHA protection on those forms. Continue?", "superb-blocks"),
                ),
                "data_retention_confirm" => array(
                    "title" => esc_html__("Enable Data Retention", "superb-blocks"),
                    /* translators: %d: number of days */
                    "content" => esc_html__("This will permanently delete all submissions older than %d days. This affects all forms and cannot be undone. Continue?", "superb-blocks"),
                ),
                "remove_integration_in_use" => array(
                    "title" => esc_html__("Remove API Key", "superb-blocks"),
                    /* translators: %1$s: integration name, %2$d: number of forms */
                    "content" => esc_html__("This integration is currently in use on %d forms. Removing the API key will prevent those forms from syncing. Continue?", "superb-blocks"),
                ),
                "remove_all_data" => array(
                    "title" => esc_html__("Remove All Plugin Data", "superb-blocks"),
                    "content" => esc_html__("This permanently deletes every Superb Addons option, setting, integration key, user preference, and scheduled task. Your license key will also be deactivated and removed from this site. This action cannot be undone.", "superb-blocks"),
                    "ack_label" => esc_html__("I understand that this will permanently delete my license key, plugin settings, integration keys, user preferences, and all related data from this site.", "superb-blocks"),
                    "submissions_label" => esc_html__("Also permanently delete all form submissions stored on this site", "superb-blocks"),
                    "success" => esc_html__("All plugin data removed. Reloading...", "superb-blocks"),
                ),
            ),
            "integration_key_saved" => esc_html__("API key saved and validated successfully.", "superb-blocks"),
            "integration_key_removed" => esc_html__("API key removed successfully.", "superb-blocks"),
            "integration_key_error" => esc_html__("An error occurred. Please try again.", "superb-blocks"),
            "connected_label" => esc_html__("Connected", "superb-blocks"),
            "not_connected_label" => esc_html__("Not Connected", "superb-blocks"),
            "disconnect_label" => esc_html__("Disconnect", "superb-blocks"),
            "connect_label" => esc_html__("Connect", "superb-blocks"),
            "placeholder_mailchimp" => esc_attr__("Enter Mailchimp API key", "superb-blocks"),
            "placeholder_brevo" => esc_attr__("Enter Brevo API key", "superb-blocks"),
            "trash_icon_url" => esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'),
            "spinner_url" => esc_url(SUPERBADDONS_ASSETS_PATH . '/img/blocks-spinner.svg'),
            "captcha_saved" => esc_html__("CAPTCHA keys saved.", "superb-blocks"),
            "captcha_removed" => esc_html__("CAPTCHA keys removed.", "superb-blocks"),
            "captcha_error" => esc_html__("An error occurred. Please try again.", "superb-blocks"),
            "permissions_saved" => esc_html__("Permissions saved.", "superb-blocks"),
            "notice_default_access" => esc_html__("This role has full access by default. Enable access control above to customize permissions.", "superb-blocks"),
            "notice_explicit_access" => esc_html__("Configure which form features this role can access.", "superb-blocks"),
            "default_email_saved" => esc_html__("Email settings saved.", "superb-blocks"),
            "data_retention_saved" => esc_html__("Data retention settings saved.", "superb-blocks"),
            /* translators: %d: number of days */
            "data_retention_warning" => esc_html__("Submissions older than %d days are automatically deleted.", "superb-blocks"),
            /* translators: %d: number of forms using this integration */
            "in_use_badge" => esc_html__("In use on %d forms", "superb-blocks"),
            "search_placeholder" => esc_attr__("Search settings...", "superb-blocks"),
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "routes" => array(
                    "settings" => SettingsController::SETTINGS_ROUTE,
                )
            )
        ));
    }

    private function enqueueAdditionalCSS()
    {
        wp_enqueue_style(
            'superbaddons-select2',
            SUPERBADDONS_ASSETS_PATH . '/lib/select2.min.css',
            array(),
            SUPERBADDONS_VERSION
        );

        do_action('superbaddons/admin/css-blocks/enqueue');

        wp_enqueue_script('superb-addons-select2', SUPERBADDONS_ASSETS_PATH . '/lib/select2.min.js', array('jquery'), SUPERBADDONS_VERSION, true);
        $code_editor_settings = wp_enqueue_code_editor(array('type' => 'text/css', 'codemirror' => array('lint' => true)));
        wp_enqueue_script('superb-addons-css-blocks', SUPERBADDONS_ASSETS_PATH . '/js/admin/cssblocks.js', array('jquery', 'wp-i18n'), SUPERBADDONS_VERSION, true);
        ScriptTranslations::Set('superb-addons-css-blocks');
        wp_localize_script('superb-addons-css-blocks', 'superbaddonscssblocks_g', array(
            "codeEditorSettings" => $code_editor_settings,
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "routes" => array(
                    "css" => CSSController::CSS_ROUTE,
                ),
                "error_message" => esc_html__("An error occurred while updating the CSS block. Please try again.", "superb-blocks"),
            ),
        ));
    }

    private function enqueuePageWizard()
    {
        wp_enqueue_style(
            'superb-addons-page-wizard',
            SUPERBADDONS_ASSETS_PATH . '/css/page-wizard.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_script('superb-addons-page-wizard', SUPERBADDONS_ASSETS_PATH . '/js/admin/page-wizard.js', array('jquery', 'wp-i18n'), SUPERBADDONS_VERSION, true);
        ScriptTranslations::Set('superb-addons-page-wizard');
        wp_localize_script('superb-addons-page-wizard', 'superbaddonswizard_g', array(
            "favorites" => FavoritesController::GetFavorites(get_current_user_id()),
            "industries" => self::GetLibraryIndustries(),
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "routes" => array(
                    "wizard" => WizardController::WIZARD_ROUTE,
                    "favorites" => FavoritesController::FAVORITES_ROUTE,
                    "warm_cache" => LibraryRequestController::GUTENBERG_V2_WARM_CACHE_ROUTE,
                )
            )
        ));

        // Block Theme Explainer Tour
        wp_enqueue_style(
            'superbaddons-driver',
            SUPERBADDONS_ASSETS_PATH . '/lib/driver.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_script('superb-addons-tour-block-theme', SUPERBADDONS_ASSETS_PATH . '/js/guided-tours/block-theme-explainer.js', array('wp-i18n', 'jquery', 'superb-addons-page-wizard'), SUPERBADDONS_VERSION, true);
        ScriptTranslations::Set('superb-addons-tour-block-theme');
        wp_localize_script('superb-addons-tour-block-theme', 'superbaddonstour_g', array(
            "auto_start" => !TourController::IsTourCompleted(TourController::TOUR_BLOCK_THEME_META),
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "routes" => array(
                    "tutorial" => TroubleshootingController::TUTORIAL_ROUTE,
                )
            )
        ));
    }

    /**
     * Read the industries list from the unified library cache. Returns [] when the
     * cache is cold, so the wizard falls back gracefully (the sidebar just omits
     * the Industries section).
     */
    private static function GetLibraryIndustries()
    {
        try {
            $cache = CacheController::GetCache(GutenbergCache::LIBRARY, CacheTypes::GUTENBERG);
            if ($cache && isset($cache->industries) && is_array($cache->industries)) {
                return $cache->industries;
            }
        } catch (\Exception $e) {
            // Fall through to empty list.
        }
        return array();
    }

    private function enqueueForms()
    {
        wp_enqueue_style(
            'superb-addons-admin-forms',
            SUPERBADDONS_ASSETS_PATH . '/css/admin-forms.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_script('superb-addons-admin-forms', SUPERBADDONS_ASSETS_PATH . '/js/admin/forms.js', array('jquery'), SUPERBADDONS_VERSION, true);
        wp_localize_script('superb-addons-admin-forms', 'superbaddonsadminforms_g', array(
            "forms_url" => esc_url(admin_url('admin.php?page=' . self::FORMS)),
            "permissions" => FormPermissions::GetCurrentUserPermissions(),
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest"),
                "routes" => array(
                    "submissions" => \SuperbAddons\Gutenberg\Form\FormController::SUBMISSIONS_ROUTE,
                    "submissions_forms" => \SuperbAddons\Gutenberg\Form\FormController::SUBMISSIONS_FORMS_ROUTE,
                    "submissions_bulk_delete" => \SuperbAddons\Gutenberg\Form\FormController::SUBMISSIONS_BULK_DELETE_ROUTE,
                    "submissions_bulk_status" => \SuperbAddons\Gutenberg\Form\FormController::SUBMISSIONS_BULK_STATUS_ROUTE,
                    "submissions_mark_read" => '/form/submissions/{id}/read',
                    "submissions_mark_unread" => '/form/submissions/{id}/unread',
                    "submissions_delete" => '/form/submissions/{id}',
                    "form_delete" => '/form/{form_id}',
                    "submissions_resend_email" => '/form/submissions/{id}/resend-email',
                    "submissions_star" => '/form/submissions/{id}/star',
                    "submissions_unstar" => '/form/submissions/{id}/unstar',
                    "submissions_bulk_star" => \SuperbAddons\Gutenberg\Form\FormController::SUBMISSIONS_BULK_STAR_ROUTE,
                    "export" => '/form/{form_id}/export',
                    "submissions_not_spam" => '/form/submissions/{id}/not-spam',
                    "spam_count" => '/form/{form_id}/spam-count',
                    "retry_integration" => '/form/submissions/{id}/retry-integration',
                    "submissions_notes" => '/form/submissions/{id}/notes',
                    "submissions_notes_delete" => '/form/submissions/{id}/notes/{index}',
                    "fields_save" => \SuperbAddons\Gutenberg\Form\FormController::FIELDS_SAVE_ROUTE,
                    "fields_get" => '/form/fields/{form_id}',
                    "submissions_count" => \SuperbAddons\Gutenberg\Form\FormController::SUBMISSIONS_COUNT_ROUTE,
                )
            ),
            "icons" => array(
                "trash" => esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'),
                "eye" => esc_url(SUPERBADDONS_ASSETS_PATH . '/img/eye.svg'),
                "eye_slash" => esc_url(SUPERBADDONS_ASSETS_PATH . '/img/eye-slash.svg'),
                "copy" => esc_url(SUPERBADDONS_ASSETS_PATH . '/img/copy.svg'),
                "star_regular" => esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-star-regular.svg'),
                "star_fill" => esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-star-fill.svg'),
                "checkmark" => esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'),
            ),
            "i18n" => array(
                "delete_confirm_title" => esc_html__("Delete Submission", "superb-blocks"),
                "delete_confirm" => esc_html__("Are you sure? This action cannot be undone.", "superb-blocks"),
                "bulk_delete_confirm_title" => esc_html__("Delete Selected Submissions", "superb-blocks"),
                "bulk_delete_confirm" => esc_html__("Are you sure? This action cannot be undone.", "superb-blocks"),
                "deleted" => esc_html__("Submission deleted.", "superb-blocks"),
                "bulk_deleted" => esc_html__("Submissions deleted.", "superb-blocks"),
                "error" => esc_html__("An error occurred. Please try again.", "superb-blocks"),
                "no_submissions" => esc_html__("No submissions found.", "superb-blocks"),
                "no_filter_results" => esc_html__("No submissions match your filters.", "superb-blocks"),
                "new_status" => esc_html__("New", "superb-blocks"),
                "read_status" => esc_html__("Read", "superb-blocks"),
                "mark_read" => esc_html__("Mark as Read", "superb-blocks"),
                "mark_unread" => esc_html__("Mark as Unread", "superb-blocks"),
                "bulk_marked_read" => esc_html__("Submissions marked as read.", "superb-blocks"),
                "bulk_marked_unread" => esc_html__("Submissions marked as unread.", "superb-blocks"),
                "copied" => esc_html__("Copied!", "superb-blocks"),
                "filter_all" => esc_html__("All", "superb-blocks"),
                "filter_unread" => esc_html__("Unread", "superb-blocks"),
                "filter_read" => esc_html__("Read", "superb-blocks"),
                "clear_filters" => esc_html__("Clear filters", "superb-blocks"),
                /* translators: 1: current submission number, 2: total submissions */
                "submission_counter" => esc_html__('Submission %1$s of %2$s', "superb-blocks"),
                "prev_submission" => esc_html__("Previous submission", "superb-blocks"),
                "next_submission" => esc_html__("Next submission", "superb-blocks"),
                "total_label" => esc_html__("Total", "superb-blocks"),
                "unread_label" => esc_html__("Unread", "superb-blocks"),
                "today_label" => esc_html__("Today", "superb-blocks"),
                "this_week_label" => esc_html__("This Week", "superb-blocks"),
                "date_all_time" => esc_html__("All Time", "superb-blocks"),
                "date_this_month" => esc_html__("This Month", "superb-blocks"),
                "date_last_30" => esc_html__("Last 30 Days", "superb-blocks"),
                "date_custom_range" => esc_html__("Custom Range", "superb-blocks"),
                "date_from" => esc_html__("From", "superb-blocks"),
                "date_to" => esc_html__("To", "superb-blocks"),
                "date_apply" => esc_html__("Apply", "superb-blocks"),
                "date_clear" => esc_html__("Clear", "superb-blocks"),
                "delete_form_confirm_title" => esc_html__("Delete Form", "superb-blocks"),
                "delete_form_confirm" => esc_html__("This will permanently delete the form and all stored submissions. The form block will stop working if it remains on your site. This action cannot be undone.", "superb-blocks"),
                /* translators: %s: post type name (e.g. "page", "post", "template") */
                "delete_form_remove_block" => esc_html__("Also remove the form block from its %s (recommended)", "superb-blocks"),
                "source_type_labels" => array(
                    "page" => esc_html__("page", "superb-blocks"),
                    "post" => esc_html__("post", "superb-blocks"),
                    "wp_template" => esc_html__("template", "superb-blocks"),
                    "wp_template_part" => esc_html__("template part", "superb-blocks"),
                    "wp_block" => esc_html__("pattern", "superb-blocks"),
                ),
                "form_deleted" => esc_html__("Form deleted.", "superb-blocks"),
                "resend_admin" => esc_html__("Resend Admin Email", "superb-blocks"),
                "resend_user" => esc_html__("Resend User Email", "superb-blocks"),
                "resend_user_confirm_title" => esc_html__("Resend User Email", "superb-blocks"),
                "resend_user_confirm" => esc_html__("This will send the confirmation email to the user again. Are you sure you want to continue?", "superb-blocks"),
                "resend_admin_success" => esc_html__("Admin email sent.", "superb-blocks"),
                "resend_user_success" => esc_html__("User email sent.", "superb-blocks"),
                "resend_error" => esc_html__("Failed to send email. Please try again.", "superb-blocks"),
                "starred" => esc_html__("Starred", "superb-blocks"),
                "star" => esc_html__("Star", "superb-blocks"),
                "unstar" => esc_html__("Unstar", "superb-blocks"),
                "bulk_starred" => esc_html__("Submissions starred.", "superb-blocks"),
                "bulk_unstarred" => esc_html__("Submissions unstarred.", "superb-blocks"),
                "export" => esc_html__("Export", "superb-blocks"),
                /* translators: %d: number of filtered submissions */
                "export_filtered" => esc_html__("Filtered results (%d)", "superb-blocks"),
                "print" => esc_html__("Print", "superb-blocks"),
                "print_status_new" => esc_html__("Unread", "superb-blocks"),
                "print_status_read" => esc_html__("Read", "superb-blocks"),
                "selected_single" => esc_html__("1 selected", "superb-blocks"),
                /* translators: %d: number of selected submissions */
                "selected_multiple" => esc_html__("%d selected", "superb-blocks"),
                "spam_tab" => esc_html__("Spam", "superb-blocks"),
                "not_spam" => esc_html__("Not Spam", "superb-blocks"),
                "not_spam_success" => esc_html__("Submission moved to inbox.", "superb-blocks"),
                "spam_reason_label" => esc_html__("Spam Reason", "superb-blocks"),
                "spam_reason_honeypot" => esc_html__("Honeypot", "superb-blocks"),
                "spam_reason_captcha" => esc_html__("CAPTCHA failure", "superb-blocks"),
                "spam_reason_bot_detection" => esc_html__("Bot detection", "superb-blocks"),
                /* translators: %d: number of filtered spam submissions */
                "export_spam_filtered" => esc_html__("Filtered spam (%d)", "superb-blocks"),
                "retry_mailchimp" => esc_html__("Retry Mailchimp", "superb-blocks"),
                "retry_brevo" => esc_html__("Retry Brevo", "superb-blocks"),
                "retry_success" => esc_html__("Integration sent successfully.", "superb-blocks"),
                "retry_error" => esc_html__("Failed to send to integration.", "superb-blocks"),
                "email_status_sent" => esc_html__("Sent", "superb-blocks"),
                "email_status_failed" => esc_html__("Failed", "superb-blocks"),
                "email_status_not_sent" => esc_html__("Not sent", "superb-blocks"),
                // Phase 3: Notes
                "add_note" => esc_html__("Add Note", "superb-blocks"),
                "note_placeholder" => esc_html__("Add a note...", "superb-blocks"),
                "note_added" => esc_html__("Note added.", "superb-blocks"),
                "note_deleted" => esc_html__("Note deleted.", "superb-blocks"),
                "note_error" => esc_html__("Failed to save note.", "superb-blocks"),
                "note_delete_error" => esc_html__("Failed to delete note.", "superb-blocks"),
                "note_delete_confirm_title" => esc_html__("Delete Note", "superb-blocks"),
                "note_delete_confirm" => esc_html__("Are you sure you want to delete this note?", "superb-blocks"),
                "notes_label" => esc_html__("Notes", "superb-blocks"),
                "include_notes" => esc_html__("Include notes", "superb-blocks"),
                /* translators: %d: number of remaining characters */
                "chars_remaining" => esc_html__("%d characters remaining", "superb-blocks"),
                // Phase 3: Visible Fields
                "fields_btn" => esc_html__("Fields", "superb-blocks"),
                "fields_saved" => esc_html__("Field preferences saved.", "superb-blocks"),
                "fields_error" => esc_html__("Failed to save field preferences.", "superb-blocks"),
                "fields_reset" => esc_html__("Reset", "superb-blocks"),
                "export_all_fields" => esc_html__("Export all fields", "superb-blocks"),
                // Phase 3: Real-time
                "new_submission_received" => esc_html__("New submission received", "superb-blocks"),
            )
        ));
    }

    public function TroubleshootingTemplates()
    {
        AllowedTemplateHTMLUtil::enable_safe_styles();
        ob_start();
        include(SUPERBADDONS_PLUGIN_DIR . 'src/admin/templates/troubleshooting-step.php');
        $template = ob_get_clean();
        echo '<script type="text/template" id="tmpl-superb-addons-troubleshooting-step">' . wp_kses($template, "post") . '</script>';
        AllowedTemplateHTMLUtil::disable_safe_styles();
    }

    public function SuperbDashboard()
    {
        $this->DashboardPageSetup(DashboardPage::class);
    }

    public function AdditionalCSS()
    {
        $this->DashboardPageSetup(AdditionalCSSPage::class);
    }

    public function Support()
    {
        $this->DashboardPageSetup(SupportPage::class);
    }

    public function Settings()
    {
        $this->DashboardPageSetup(SettingsPage::class);
    }

    public function PageWizard()
    {
        $this->DashboardPageSetup(PageWizardMainPage::class, true, __("Theme Designer", "superb-blocks"));
    }

    public function Forms()
    {
        $this->DashboardPageSetup(FormsPage::class);
    }

    private function DashboardPageSetup($page_class, $hide_navigation_items = false, $theme_designer = false)
    {
    ?>
        <div class="superbaddons-wrap">
            <?php new Navigation($hide_navigation_items, $theme_designer); ?>
            <div class="superbaddons-wrap-inner">
                <?php new $page_class(); ?>
            </div>
        </div>
<?php
    }
}
