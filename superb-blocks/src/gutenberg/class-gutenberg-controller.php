<?php

namespace SuperbAddons\Gutenberg\Controllers;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Controllers\SettingsController;
use SuperbAddons\Admin\Controllers\Wizard\WizardController;
use SuperbAddons\Admin\Controllers\Wizard\WizardRestorationPointController;
use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Commerce\CommerceController;
use SuperbAddons\Data\Controllers\CompatibilitySettingsOptionKey;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Controllers\RestController;
use SuperbAddons\Data\Utils\AllowedTemplateHTMLUtil;
use SuperbAddons\Data\Utils\ScriptTranslations;
use SuperbAddons\Gutenberg\BlocksAPI\Controllers\AuthorBoxController;
use SuperbAddons\Gutenberg\BlocksAPI\Controllers\DynamicBlockAssets;
use SuperbAddons\Gutenberg\BlocksAPI\Controllers\RecentPostsController;
use SuperbAddons\Gutenberg\BlocksAPI\Controllers\TableOfContentsController;
use SuperbAddons\Library\Controllers\FavoritesController;
use SuperbAddons\Library\Controllers\LibraryController;
use SuperbAddons\Library\Controllers\LibraryRequestController;
use SuperbAddons\Data\Controllers\OptionController;
use SuperbAddons\Gutenberg\Form\FormAccessControl;
use SuperbAddons\Gutenberg\Form\FormController;
use SuperbAddons\Gutenberg\Form\FormEmailConfigCheck;
use SuperbAddons\Gutenberg\Form\FormEncryption;
use SuperbAddons\Gutenberg\Form\FormPermissions;
use SuperbAddons\Gutenberg\Form\FormRegistry;
use SuperbAddons\Gutenberg\Import\GutenbergBlockIdRegenerator;
use SuperbAddons\Gutenberg\Popup\PopupButtonRender;
use SuperbAddons\Gutenberg\Popup\PopupRegistry;

class GutenbergController
{
    const MINIMUM_WORDPRESS_VERSION = '6.2';
    const MINIMUM_PHP_VERSION = '5.6';

    const VARIABLE_FALLBACKS_HANDLE = 'superb-addons-variable-fallbacks';

    const BLOCK_PREFIX = 'superb-addons/';

    // Parent blocks that users can toggle on/off
    const TOGGLEABLE_BLOCKS = array(
        'author-box',
        'ratings',
        'table-of-contents',
        'recent-posts',
        'cover-image',
        'google-maps',
        'reveal-buttons',
        'accordion',
        'carousel',
        'countdown',
        'progress-bar',
        'popup',
        'form',
        'add-to-cart',
    );

    // Child blocks that should be hidden when their parent is disabled
    const CHILD_BLOCK_MAP = array(
        'carousel-slide' => 'carousel',
        'reveal-button' => 'reveal-buttons',
        'form-field' => 'form',
        'form-step' => 'form',
        'multistep-form' => 'form',
    );

    // Block variations shown as separate discoverable blocks in admin UI,
    // sharing the enable/disable state of their parent toggleable block.
    // Map: variation slug => parent slug (must be in TOGGLEABLE_BLOCKS).
    const DISCOVERABLE_VARIATIONS = array(
        'buy-now' => 'add-to-cart',
        'multistep-form' => 'form',
    );

    // Blocks that should not be available in the widget editor
    const WIDGET_EDITOR_EXCLUDED_BLOCKS = array(
        'superb-addons/form',
        'superb-addons/multistep-form',
        'superb-addons/form-field',
        'superb-addons/form-step',
        'superb-addons/popup',
    );

    const PATTERN_BLOCK_ARG = 'is_pattern_block';
    const BLOCKS = array(
        ['path' => "animated-heading", "args" => ['render_callback' => array(DynamicBlockAssets::class, 'EnqueueAnimatedHeader')]],
        ['path' => "author-box", "args" => ['render_callback' => array(AuthorBoxController::class, 'Render')]],
        ['path' => "ratings", "args" => []],
        ['path' => "table-of-contents", "args" => ['render_callback' => array(TableOfContentsController::class, 'DynamicRender')]],
        ['path' => "recent-posts", "args" => ['render_callback' => array(RecentPostsController::class, 'DynamicRender')]],
        ['path' => "cover-image", "args" => []],
        ['path' => "google-maps", "args" => []],
        ['path' => "reveal-buttons", "args" => []],
        ['path' => "reveal-button", "args" => ['render_callback' => array(DynamicBlockAssets::class, 'EnqueueRevealButton')]],
        ['path' => "accordion", "args" => ['render_callback' => array(DynamicBlockAssets::class, 'EnqueueAccordion')]],
        ['path' => "carousel", "args" => ['render_callback' => array(DynamicBlockAssets::class, 'EnqueueCarousel')]],
        ['path' => "carousel-slide", "args" => []],
        ['path' => "countdown", "args" => ['render_callback' => array(DynamicBlockAssets::class, 'EnqueueCountdown')]],
        ['path' => "progress-bar", "args" => ['render_callback' => array(DynamicBlockAssets::class, 'EnqueueProgressBar')]],
        ['path' => "popup", "args" => ['render_callback' => array(DynamicBlockAssets::class, 'EnqueuePopup')]],
        ['path' => "form", "args" => ['render_callback' => array(DynamicBlockAssets::class, 'EnqueueForm')]],
        ['path' => "form-field", "args" => []],
        ['path' => "multistep-form", "args" => ['render_callback' => array(DynamicBlockAssets::class, 'EnqueueForm')]],
        ['path' => "form-step", "args" => []],
        ['path' => "add-to-cart", "args" => ['render_callback' => array(CommerceController::class, 'RenderBlock')]],
    );

    public function __construct()
    {
        WizardRestorationPointController::Initialize();

        if (!self::is_compatible()) {
            return;
        }

        add_action('block_categories_all', array($this, 'RegisterBlockCategory'), defined('PHP_INT_MAX') ? PHP_INT_MAX : 999, 2);
        add_action('init', array($this, 'RegisterBlocksAndStyles'), 0);
        add_action('enqueue_block_editor_assets', array($this, 'EnqueueBlockEditorAssets'));
        add_filter('register_block_type_args', array($this, 'MaybeHideDisabledBlock'), 10, 2);
        add_filter('allowed_block_types_all', array($this, 'MaybeExcludeBlocksFromWidgetEditor'), 10, 2);

        // enqueue_block_assets fires on the frontend AND inside the editor iframe.
        // Patterns CSS is registered through here so it reaches the iframe correctly.
        add_action("enqueue_block_assets", array($this, 'EnqueueEditorIframeAssets'));

        add_action('enqueue_block_editor_assets', array($this, 'EnqueueVariableFallbacks'), PHP_INT_MIN);
        add_action("wp_enqueue_scripts", array($this, 'EnqueueVariableFallbacks'), PHP_INT_MIN);
        add_action('wp_print_styles', array($this, 'ReorderVariableFallbacks'), PHP_INT_MAX);

        GutenbergEnhancementsController::Initialize();
        TableOfContentsController::Initialize();
        FormController::Initialize();
        CommerceController::Initialize();
        PopupRegistry::Initialize();
        PopupButtonRender::Initialize();
        WizardController::Initialize();
    }

    /**
     * Check if WooCommerce plugin files exist in wp-content/plugins (installed but possibly inactive).
     */
    public static function IsWooCommerceInstalled()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = get_plugins();
        return isset($plugins['woocommerce/woocommerce.php']);
    }

    /**
     * Activation URL for WooCommerce with the proper nonce, or '' if not installed.
     */
    public static function GetWooCommerceActivateUrl()
    {
        if (!self::IsWooCommerceInstalled()) {
            return '';
        }
        $plugin = 'woocommerce/woocommerce.php';
        return wp_nonce_url(
            self_admin_url('plugins.php?action=activate&plugin=' . urlencode($plugin) . '&plugin_status=all'),
            'activate-plugin_' . $plugin
        );
    }

    /**
     * Total number of blocks shown in the admin UI, counting each discoverable variation separately.
     */
    public static function GetDiscoverableBlockTotal()
    {
        return count(self::TOGGLEABLE_BLOCKS) + count(self::DISCOVERABLE_VARIATIONS);
    }

    /**
     * Number of currently-enabled blocks shown in the admin UI, counting each discoverable variation
     * separately. A variation is enabled when its parent toggleable block is not disabled.
     */
    public static function GetDiscoverableBlockActiveCount($disabled_blocks)
    {
        $active = count(self::TOGGLEABLE_BLOCKS) - count($disabled_blocks);
        foreach (self::DISCOVERABLE_VARIATIONS as $parent_slug) {
            if (!in_array($parent_slug, $disabled_blocks, true)) {
                $active++;
            }
        }
        return $active;
    }

    public static function is_compatible()
    {
        // Check for required WP version
        if (version_compare(get_bloginfo('version'), self::MINIMUM_WORDPRESS_VERSION, '<')) {
            return false;
        }

        // Check for required PHP version
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP_VERSION, '<')) {
            return false;
        }

        return true;
    }

    public static function is_block_theme()
    {
        if (!function_exists('wp_is_block_theme')) {
            return false;
        }

        if (!wp_is_block_theme()) {
            return false;
        }

        return true;
    }

    public static function GetGutenbergLibraryMenuItems()
    {
        return array(
            array(
                "id" => "patterns",
                "premium_url" => AdminLinkUtil::GetLink(AdminLinkSource::LIBRARY_ITEM),
                "title" => esc_html__('Patterns', "superb-blocks"),
                "routes" => array(
                    "list" => LibraryRequestController::GUTENBERG_V2_LIST_ROUTE,
                    "insert" => LibraryRequestController::GUTENBERG_V2_INSERT_ROUTE
                ),
                "type" => LibraryRequestController::GUTENBERG_TYPE_PATTERN,
                "hidden" => false
            ),
            array(
                "id" => "pages",
                "premium_url" => AdminLinkUtil::GetLink(AdminLinkSource::LIBRARY_PAGE_ITEM),
                "title" => esc_html__('Pages', "superb-blocks"),
                "routes" => array(
                    "list" => LibraryRequestController::GUTENBERG_V2_LIST_ROUTE,
                    "insert" => LibraryRequestController::GUTENBERG_V2_INSERT_ROUTE
                ),
                "type" => LibraryRequestController::GUTENBERG_TYPE_PAGE,
                "hidden" => false
            )
        );
    }

    public function EnqueuePatternAssets()
    {
        wp_enqueue_style(
            'superb-addons-patterns'
        );
    }

    public function EnqueueVariableFallbacks()
    {
        $fallbacks = ":root{--wp--preset--color--primary:#1f7cec;--wp--preset--color--primary-hover:#3993ff;--wp--preset--color--base:#fff;--wp--preset--color--featured:#0a284b;--wp--preset--color--contrast-light:#fff;--wp--preset--color--contrast-dark:#000;--wp--preset--color--mono-1:#0d3c74;--wp--preset--color--mono-2:#64748b;--wp--preset--color--mono-3:#e2e8f0;--wp--preset--color--mono-4:#f8fafc;--wp--preset--spacing--superbspacing-xxsmall:clamp(5px,1vw,10px);--wp--preset--spacing--superbspacing-xsmall:clamp(10px,2vw,20px);--wp--preset--spacing--superbspacing-small:clamp(20px,4vw,40px);--wp--preset--spacing--superbspacing-medium:clamp(30px,6vw,60px);--wp--preset--spacing--superbspacing-large:clamp(40px,8vw,80px);--wp--preset--spacing--superbspacing-xlarge:clamp(50px,10vw,100px);--wp--preset--spacing--superbspacing-xxlarge:clamp(60px,12vw,120px);--wp--preset--font-size--superbfont-tiny:clamp(10px,0.625rem + ((1vw - 3.2px) * 0.227),12px);--wp--preset--font-size--superbfont-xxsmall:clamp(12px,0.75rem + ((1vw - 3.2px) * 0.227),14px);--wp--preset--font-size--superbfont-xsmall:clamp(16px,1rem + ((1vw - 3.2px) * 1),16px);--wp--preset--font-size--superbfont-small:clamp(16px,1rem + ((1vw - 3.2px) * 0.227),18px);--wp--preset--font-size--superbfont-medium:clamp(18px,1.125rem + ((1vw - 3.2px) * 0.227),20px);--wp--preset--font-size--superbfont-large:clamp(24px,1.5rem + ((1vw - 3.2px) * 0.909),32px);--wp--preset--font-size--superbfont-xlarge:clamp(32px,2rem + ((1vw - 3.2px) * 1.818),48px);--wp--preset--font-size--superbfont-xxlarge:clamp(40px,2.5rem + ((1vw - 3.2px) * 2.727),64px)}.has-primary-color{color:var(--wp--preset--color--primary)!important}.has-primary-hover-color{color:var(--wp--preset--color--primary-hover)!important}.has-base-color{color:var(--wp--preset--color--base)!important}.has-featured-color{color:var(--wp--preset--color--featured)!important}.has-contrast-light-color{color:var(--wp--preset--color--contrast-light)!important}.has-contrast-dark-color{color:var(--wp--preset--color--contrast-dark)!important}.has-mono-1-color{color:var(--wp--preset--color--mono-1)!important}.has-mono-2-color{color:var(--wp--preset--color--mono-2)!important}.has-mono-3-color{color:var(--wp--preset--color--mono-3)!important}.has-mono-4-color{color:var(--wp--preset--color--mono-4)!important}.has-primary-background-color{background-color:var(--wp--preset--color--primary)!important}.has-primary-hover-background-color{background-color:var(--wp--preset--color--primary-hover)!important}.has-base-background-color{background-color:var(--wp--preset--color--base)!important}.has-featured-background-color{background-color:var(--wp--preset--color--featured)!important}.has-contrast-light-background-color{background-color:var(--wp--preset--color--contrast-light)!important}.has-contrast-dark-background-color{background-color:var(--wp--preset--color--contrast-dark)!important}.has-mono-1-background-color{background-color:var(--wp--preset--color--mono-1)!important}.has-mono-2-background-color{background-color:var(--wp--preset--color--mono-2)!important}.has-mono-3-background-color{background-color:var(--wp--preset--color--mono-3)!important}.has-mono-4-background-color{background-color:var(--wp--preset--color--mono-4)!important}.has-superbfont-tiny-font-size{font-size:var(--wp--preset--font-size--superbfont-tiny)!important}.has-superbfont-xxsmall-font-size{font-size:var(--wp--preset--font-size--superbfont-xxsmall)!important}.has-superbfont-xsmall-font-size{font-size:var(--wp--preset--font-size--superbfont-xsmall)!important}.has-superbfont-small-font-size{font-size:var(--wp--preset--font-size--superbfont-small)!important}.has-superbfont-medium-font-size{font-size:var(--wp--preset--font-size--superbfont-medium)!important}.has-superbfont-large-font-size{font-size:var(--wp--preset--font-size--superbfont-large)!important}.has-superbfont-xlarge-font-size{font-size:var(--wp--preset--font-size--superbfont-xlarge)!important}.has-superbfont-xxlarge-font-size{font-size:var(--wp--preset--font-size--superbfont-xxlarge)!important}";
        wp_add_inline_style(self::VARIABLE_FALLBACKS_HANDLE, $fallbacks);
        wp_enqueue_style(self::VARIABLE_FALLBACKS_HANDLE);
    }

    public function ReorderVariableFallbacks()
    {
        // Some themes/plugins may incorrectly call wp_enqueue_global_styles outside of the enqueue phase, causing our fallbacks to be enqueued in the wrong order.
        // To fix this, we will move our fallbacks to the front of the queue
        global $wp_styles;

        if (!isset($wp_styles) || !is_object($wp_styles)) {
            return;
        }
        if (!isset($wp_styles->queue) || !is_array($wp_styles->queue) || empty($wp_styles->queue)) {
            return;
        }
        if (!isset($wp_styles->registered[self::VARIABLE_FALLBACKS_HANDLE])) {
            return;
        }
        if (!in_array(self::VARIABLE_FALLBACKS_HANDLE, $wp_styles->queue)) {
            return;
        }
        if ($wp_styles->queue[0] === self::VARIABLE_FALLBACKS_HANDLE) {
            return;
        }

        $wp_styles->queue = array_diff($wp_styles->queue, array(self::VARIABLE_FALLBACKS_HANDLE));
        array_unshift($wp_styles->queue, self::VARIABLE_FALLBACKS_HANDLE);
    }

    public function EnqueueEditorIframeAssets()
    {
        global $pagenow;

        // Patterns CSS — needed on the frontend and inside the editor iframe.
        // This hook is the only path that reaches the iframe correctly.
        $this->EnqueuePatternAssets();

        // Enhancements CSS must load inside the editor iframe so that
        // media queries respond to the iframe width (device preview).
        if (is_admin()) {
            wp_enqueue_style(
                'superb-addons-editor-enhancements',
                SUPERBADDONS_ASSETS_PATH . '/css/editor-enhancements.min.css',
                array(),
                SUPERBADDONS_VERSION
            );
        }

        // Site-editor-only assets
        if ('site-editor.php' === $pagenow) {
            wp_enqueue_style(
                'superb-gutenberg-layout-library',
                SUPERBADDONS_ASSETS_PATH . '/css/layout-library-preview.min.css',
                array(),
                SUPERBADDONS_VERSION
            );
            $this->EnqueueVariableFallbacks();
        }
    }

    public function EnqueueBlockEditorAssets()
    {
        self::AddonsLibrary();
        self::EditorEnhancements();
        wp_enqueue_script(
            'superb-addons-gutenberg-library',
            SUPERBADDONS_ASSETS_PATH . '/js/gutenberg/pattern-library.js',
            array("jquery", "wp-plugins", "wp-hooks", "wp-data", "wp-element", "wp-i18n", "wp-components", "wp-compose", "wp-blocks", "wp-editor", "wp-block-editor"),
            SUPERBADDONS_VERSION,
            true
        );
        ScriptTranslations::Set('superb-addons-gutenberg-library');
        wp_enqueue_script(
            'superb-addons-upsell-modal',
            SUPERBADDONS_ASSETS_PATH . '/js/gutenberg/upsell-modal.js',
            array("wp-plugins", "wp-data", "wp-element", "wp-i18n", "wp-components", "wp-url", "wp-escape-html"),
            SUPERBADDONS_VERSION,
            true
        );
        ScriptTranslations::Set('superb-addons-upsell-modal');
        // Vanilla upsell modal + its CSS: intercepts clicks on PHP-rendered
        // `data-superb-upsell-source` elements (emitted by PremiumButton /
        // PremiumOptionWrapper inside the editor's design library templates).
        // The React editor modal above handles block-triggered upsells via
        // the Redux store; the two coexist, each handling its own triggers.
        wp_enqueue_script(
            'superb-addons-upsell-modal-admin',
            SUPERBADDONS_ASSETS_PATH . '/js/admin/upsell-modal.js',
            array('wp-i18n', 'wp-url', 'wp-escape-html'),
            SUPERBADDONS_VERSION,
            true
        );
        ScriptTranslations::Set('superb-addons-upsell-modal-admin');
        wp_enqueue_style(
            'superb-addons-admin-modal',
            SUPERBADDONS_ASSETS_PATH . '/css/admin-modal.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_localize_script('superb-addons-gutenberg-library', 'superblayoutlibrary_g', array(
            "style_placeholder" => esc_html__('All themes', "superb-blocks"),
            "category_placeholder" => esc_html__('All categories', "superb-blocks"),
            "snacks" => array(
                "settings_save_message" => esc_html__("Settings saved successfully.", "superb-blocks"),
                "settings_save_error" => esc_html__("Something went wrong while attempting to save your settings. Please try again or contact support if the problem persists.", "superb-blocks"),
                "insert_error" => esc_html__('Something went wrong while attempting to insert this element. Please try again or contact support if the problem persists.', "superb-blocks"),
                "list_error" => esc_html__('Something went wrong while attempting to list elements. Please try again or contact support if the problem persists.', "superb-blocks")
            ),
            "menu_items" => self::GetGutenbergLibraryMenuItems(),
            "chunk_route" => LibraryRequestController::GUTENBERG_V2_LIST_CHUNK_ROUTE,
            "favorites" => FavoritesController::GetFavorites(get_current_user_id()),
            "rest" => array(
                "base" => \get_rest_url(),
                "namespace" => RestController::NAMESPACE,
                "nonce" => wp_create_nonce("wp_rest")
            ),
            "addons_link_id" => AdminLinkUtil::GetLinkID(),
            "visibility" => array(
                "roles" => GutenbergConditionsController::GetConditionsRoles(),
            ),
        ));
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
            'superb-gutenberg-editor-layout-library',
            SUPERBADDONS_ASSETS_PATH . '/css/layout-library-editor.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_style(
            'superb-gutenberg-layout-library',
            SUPERBADDONS_ASSETS_PATH . '/css/layout-library-preview.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_style(
            'superbaddons-toast',
            SUPERBADDONS_ASSETS_PATH . '/css/toast.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
        wp_enqueue_script('superb-addons-select2', SUPERBADDONS_ASSETS_PATH . '/lib/select2.min.js', array('jquery'), SUPERBADDONS_VERSION, true);
        wp_enqueue_style(
            'superbaddons-select2',
            SUPERBADDONS_ASSETS_PATH . '/lib/select2.min.css',
            array(),
            SUPERBADDONS_VERSION
        );

        wp_enqueue_script(
            'superbaddons-animated-heading',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/animated-heading.js',
            [],
            SUPERBADDONS_VERSION,
            true
        );

        // Form editor config — pass settings URL, REST namespace, and registry (API key status checked dynamically)
        $ac_enabled = FormAccessControl::IsEnabled();
        wp_add_inline_script('superb-addons-gutenberg-library', 'window.superbFormsEditorConfig = ' . wp_json_encode(array(
            'registry' => FormRegistry::GetAll(),
            'settingsUrl' => admin_url('admin.php?page=superbaddons-settings#integrations'),
            'restNamespace' => RestController::NAMESPACE,
            'encryptionAvailable' => FormEncryption::IsAvailable(),
            'emailConfigured' => FormEmailConfigCheck::IsConfigured(),
            'formAccessControl' => array(
                'enabled' => $ac_enabled,
                'canEdit' => $ac_enabled ? FormPermissions::Can('edit') : true,
                'canConfigure' => $ac_enabled ? FormPermissions::Can('configure') : true,
                'canCreate' => $ac_enabled ? FormPermissions::Can('create') : true,
                'sensitiveAttrs' => FormAccessControl::GetSensitiveAttrs(),
            ),
            // Picker for the file-field accept whitelist. Must stay in sync with
            // FormFileHandler::HasDangerousExtension server-side deny-list:
            // anything denied there must be omitted here so editors cannot pick
            // an extension whose uploads will be rejected. SVG is excluded
            // because it is XML and can host inline <script>.
            'allowedFileTypes' => array(
                // Images
                array('ext' => '.jpg',  'label' => 'JPG',  'mime' => 'image/jpeg'),
                array('ext' => '.jpeg', 'label' => 'JPEG', 'mime' => 'image/jpeg'),
                array('ext' => '.png',  'label' => 'PNG',  'mime' => 'image/png'),
                array('ext' => '.gif',  'label' => 'GIF',  'mime' => 'image/gif'),
                array('ext' => '.webp', 'label' => 'WebP', 'mime' => 'image/webp'),
                // Documents
                array('ext' => '.pdf',  'label' => 'PDF',  'mime' => 'application/pdf'),
                array('ext' => '.doc',  'label' => 'DOC',  'mime' => 'application/msword'),
                array('ext' => '.docx', 'label' => 'DOCX', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                array('ext' => '.txt',  'label' => 'TXT',  'mime' => 'text/plain'),
                // Spreadsheets
                array('ext' => '.xls',  'label' => 'XLS',  'mime' => 'application/vnd.ms-excel'),
                array('ext' => '.xlsx', 'label' => 'XLSX', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
                array('ext' => '.csv',  'label' => 'CSV',  'mime' => 'text/csv'),
                // Archives
                array('ext' => '.zip',  'label' => 'ZIP',  'mime' => 'application/zip'),
            ),
        ), JSON_HEX_TAG) . ';', 'before');

        // Popup registry — pass registered popups to the block editor
        wp_add_inline_script('superb-addons-gutenberg-library', 'window.superbPopupsEditorConfig = ' . wp_json_encode(array(
            'registry' => PopupRegistry::GetAll(),
        ), JSON_HEX_TAG) . ';', 'before');

        // Commerce (Add to Cart) editor config: WC active/installed state, REST namespace, recent picks.
        wp_add_inline_script('superb-addons-gutenberg-library', 'window.superbAddToCartEditorConfig = ' . wp_json_encode(array(
            'wcActive'       => CommerceController::IsWcActive(),
            'wcInstalled'    => self::IsWooCommerceInstalled(),
            'wcSupported'    => CommerceController::IsWcActive() && CommerceController::IsWcVersionSupported(),
            'wcActivateUrl'  => self::GetWooCommerceActivateUrl(),
            'wcInstallUrl'   => admin_url('plugin-install.php?tab=plugin-information&plugin=woocommerce'),
            'restNamespace'  => RestController::NAMESPACE,
            'recentPicks'    => CommerceController::GetRecentPicks(get_current_user_id()),
            'currencySymbol' => CommerceController::IsWcActive() ? html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8') : '$',
            'currencyPosition' => CommerceController::IsWcActive() ? (string) get_option('woocommerce_currency_pos', 'left') : 'left',
            'canEditCoupons' => current_user_can('edit_shop_coupons'),
        ), JSON_HEX_TAG) . ';', 'before');

        // Enhancements
        wp_enqueue_style(
            'superb-addons-editor-enhancements',
            SUPERBADDONS_ASSETS_PATH . '/css/editor-enhancements.min.css',
            array(),
            SUPERBADDONS_VERSION
        );

        /// Compatibility

        if (SettingsController::IsCompatibilitySettingRelevantAndEnabled(CompatibilitySettingsOptionKey::SPECTRA_BLOCK_SPACING)) {
            wp_enqueue_script(
                'superb-addons-block-spacing-compatibility-fix',
                SUPERBADDONS_ASSETS_PATH . '/js/compatibility/block-spacing.js',
                array('jquery'),
                SUPERBADDONS_VERSION,
                true
            );
        }
    }

    public function RegisterBlockCategory($block_categories, $block_editor_context)
    {
        return array_merge(
            array(
                array(
                    'slug'  => 'superb-addons-blocks',
                    'title' => __('Superb Addons', "superb-blocks"),
                ),
            ),
            $block_categories
        );
    }

    /**
     * Hide disabled blocks from the inserter while keeping them registered
     * so existing content still renders.
     */
    public function MaybeHideDisabledBlock($args, $block_type)
    {
        if (strpos($block_type, self::BLOCK_PREFIX) !== 0) {
            return $args;
        }

        $slug = str_replace(self::BLOCK_PREFIX, '', $block_type);
        $disabled = OptionController::GetDisabledBlocks();

        // Check if this block is directly disabled
        $is_disabled = in_array($slug, $disabled, true);

        // Check if this is a child block whose parent is disabled
        if (!$is_disabled && isset(self::CHILD_BLOCK_MAP[$slug])) {
            $parent = self::CHILD_BLOCK_MAP[$slug];
            $is_disabled = in_array($parent, $disabled, true);
        }

        // Hide form blocks from inserter when access control is enabled and user lacks 'create'
        if (!$is_disabled && FormAccessControl::IsEnabled() && !FormPermissions::Can('create')) {
            $form_slugs = array('form', 'multistep-form');
            if (in_array($slug, $form_slugs, true)) {
                $is_disabled = true;
            }
            // Also hide child blocks (form-field, form-step) via CHILD_BLOCK_MAP
            if (!$is_disabled && isset(self::CHILD_BLOCK_MAP[$slug])) {
                $parent = self::CHILD_BLOCK_MAP[$slug];
                if (in_array($parent, $form_slugs, true)) {
                    $is_disabled = true;
                }
            }
        }

        if ($is_disabled) {
            if (!isset($args['supports'])) {
                $args['supports'] = array();
            }
            $args['supports']['inserter'] = false;
        }

        return $args;
    }

    /**
     * Remove blocks that are not supported in the widget editor context.
     * Widget areas store blocks in wp_options, not post content, so blocks
     * that rely on save_post hooks (like forms) cannot track their state.
     */
    public function MaybeExcludeBlocksFromWidgetEditor($allowed_block_types, $editor_context)
    {
        if (!isset($editor_context->name)) {
            return $allowed_block_types;
        }

        if ($editor_context->name !== 'core/edit-widgets' && $editor_context->name !== 'core/customize-widgets') {
            return $allowed_block_types;
        }

        // If all blocks are allowed (default), build the full list minus excluded blocks
        if ($allowed_block_types === true) {
            $registry = \WP_Block_Type_Registry::get_instance();
            $allowed_block_types = array_keys($registry->get_all_registered());
        }

        return array_values(array_diff($allowed_block_types, self::WIDGET_EDITOR_EXCLUDED_BLOCKS));
    }

    public function RegisterBlocksAndStyles()
    {
        foreach (self::BLOCKS as $block) {
            if (isset($block['args'][self::PATTERN_BLOCK_ARG])) {
                $block['args']['category'] = 'superb-addons-blocks-patterns';
                unset($block['args'][self::PATTERN_BLOCK_ARG]);
            }
            register_block_type(SUPERBADDONS_PLUGIN_DIR . 'blocks/' . $block['path'], $block['args']);
        }

        wp_register_style(self::VARIABLE_FALLBACKS_HANDLE, false, array(), SUPERBADDONS_VERSION);
        wp_register_style(
            'superb-addons-patterns',
            SUPERBADDONS_ASSETS_PATH . '/css/patterns.min.css',
            array(),
            SUPERBADDONS_VERSION
        );
    }

    private static function EditorEnhancements()
    {
        add_action('admin_footer', function () {
            AllowedTemplateHTMLUtil::enable_safe_styles();
            ob_start();
            include(SUPERBADDONS_PLUGIN_DIR . 'src/gutenberg/templates/block-quick-options.php');
            $template = ob_get_clean();
            echo '<script type="text/template" id="tmpl-gutenberg-superb-block-quick-options">' . wp_kses($template, AllowedTemplateHTMLUtil::get_allowed_html()) . '</script>';
            AllowedTemplateHTMLUtil::disable_safe_styles();
        });
    }

    public static function AddonsLibrary()
    {
        add_action('admin_footer', function () {
            AllowedTemplateHTMLUtil::enable_safe_styles();
            ///// Buttons
            ob_start();
            include(SUPERBADDONS_PLUGIN_DIR . 'src/gutenberg/templates/library-button.php');
            $template = ob_get_clean();
            echo '<script type="text/template" id="tmpl-gutenberg-superb-library-button">' . wp_kses($template, "post") . '</script>';
            //
            ob_start();
            include(SUPERBADDONS_PLUGIN_DIR . 'src/gutenberg/templates/pattern-tab-library-button.php');
            $template = ob_get_clean();
            echo '<script type="text/template" id="tmpl-gutenberg-superb-library-button-patternstab">' . wp_kses($template, "post") . '</script>';
            //
            ob_start();
            include(SUPERBADDONS_PLUGIN_DIR . 'src/gutenberg/templates/appender-button.php');
            $template = ob_get_clean();
            echo '<script type="text/template" id="tmpl-gutenberg-superb-library-appender-button">' . wp_kses($template, "post") . '</script>';
            AllowedTemplateHTMLUtil::disable_safe_styles();
            ////// Library
            LibraryController::InsertTemplatesWithWrapper();
        });
    }

    public static function GutenbergDataImportAction($data)
    {
        if (!isset($data['content'])) {
            return $data;
        }

        $options_controller = new OptionController();
        $preferred_domain = $options_controller->GetPreferredDomain();
        // Proxy is preferred if domain starts with https://superbthemes.com
        $is_proxy_preferred = strpos($preferred_domain, 'https://superbthemes.com') === 0;

        $content = $data['content'];
        $content = preg_replace_callback("/(http)?s?:?(\/\/[^\"']*\.(?:png|jpg|jpeg|gif|png|webp))/", function ($matches) use ($preferred_domain, $is_proxy_preferred) {
            // Get the URL
            $url = $matches[0];
            if ($is_proxy_preferred) {
                $url = $preferred_domain . "image-library/theme-designer-images?path=" . $url;
            }
            $basename = pathinfo($url, PATHINFO_BASENAME);
            $title = sanitize_title($basename);

            // Check if attachment exists based on the file slug
            $posts = get_posts(
                array(
                    'post_type'              => 'attachment',
                    'title'                  => $title,
                    'numberposts'            => 1,
                )
            );
            if (!empty($posts)) {
                // Return existing attachment URL
                $attachment = $posts[0];
                return wp_get_attachment_image_url($attachment->ID, 'full');
            }

            if (!function_exists('media_sideload_image')) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');
            }

            // Create new attachment
            $attachment_id = \media_sideload_image($url, 0, $title, 'id');
            if (is_wp_error($attachment_id) || !is_numeric($attachment_id)) {
                // Return original URL if any error occurred
                return $url;
            }

            // Return new attachment URL
            return wp_get_attachment_image_url($attachment_id, 'full');
        }, $content);

        // Regenerate owner block IDs (popupId, formId, accordionId, etc.) and
        // rewrite matching references (e.g. core/button spbaddPopupTarget) so
        // that every library import produces fresh, collision-free IDs.
        $content = GutenbergBlockIdRegenerator::RegenerateIds($content);

        $data['content'] = $content;

        return $data;
    }
}
