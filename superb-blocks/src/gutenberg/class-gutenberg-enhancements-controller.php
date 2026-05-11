<?php

namespace SuperbAddons\Gutenberg\Controllers;

defined('ABSPATH') || exit();

use Exception;
use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\LogController;
use SuperbAddons\Data\Controllers\Option;
use SuperbAddons\Data\Controllers\RestController;

use WP_Block_Type_Registry;
use WP_Error;
use WP_REST_Server;
use WP_HTML_Tag_Processor;

class GutenbergEnhancementsController
{
    const ENHANCEMENTS_OPTION = 'superb-blocks-enhancements';

    const HIGHLIGHTS_KEY = 'superb-blocks-highlights';
    const HIGHLIGHTS_QUICKOPTIONS_KEY = 'superb-blocks-highlights-qo';
    const HIGHLIGHTS_QUICKOPTIONS_BOTTOM_KEY = 'superb-blocks-highlights-qo-b';
    const RESPONSIVE_KEY = 'superb-blocks-responsive';
    const ANIMATIONS_KEY = 'superb-blocks-animations';
    const CONDITIONS_KEY = 'superb-blocks-conditions';
    const DYNAMIC_CONTENT_KEY = 'superb-blocks-dynamic-content';
    const NAVIGATION_KEY = 'superb-blocks-navigation';
    const RICHTEXT_KEY = 'superb-blocks-richtext';
    const SOCIAL_ICONS_KEY = 'superb-blocks-social-icons';
    const DASHBOARD_SHORTCUTS_KEY = 'superb-blocks-dashboard-shortcuts';
    const STICKY_KEY = 'superb-blocks-sticky';
    const Z_INDEX_KEY = 'superb-blocks-z-index';
    const PANEL_DEFAULT_STATE_KEY = 'superb-blocks-panel-default-state';

    private static $global_keys = array(
        self::RESPONSIVE_KEY,
        self::ANIMATIONS_KEY,
        self::CONDITIONS_KEY,
        self::DYNAMIC_CONTENT_KEY,
        self::NAVIGATION_KEY,
        self::RICHTEXT_KEY,
        self::SOCIAL_ICONS_KEY,
        self::DASHBOARD_SHORTCUTS_KEY,
        self::STICKY_KEY,
        self::Z_INDEX_KEY,
    );

    private static $entrance_animations = array(
        'fadeIn',
        'fadeInUp',
        'fadeInDown',
        'fadeInLeft',
        'fadeInRight',
        'slideInUp',
        'slideInDown',
        'slideInLeft',
        'slideInRight',
        'zoomIn',
        'zoomInUp',
        'zoomInDown',
        'zoomInLeft',
        'zoomInRight',
        'bounceIn',
        'bounceInUp',
        'bounceInDown',
        'bounceInLeft',
        'bounceInRight',
        'flipInX',
        'flipInY',
        'rotateIn',
        'rotateInUpLeft',
        'rotateInUpRight',
        'rotateInDownLeft',
        'rotateInDownRight',
        'revealltr',
        'revealrtl',
        'revealbtt',
        'revealttb'
    );

    private static $letter_animations = array(
        'letterZoomIn',
        'letterFadeIn',
        'letterSlideUp',
        'letterSpiralUp',
        'letterBounceIn',
        'letterFlipIn',
        'letterSlideInRight',
        'letterRiseUp',
        'letterDropDown',
        'letterFlyInRight',
        'letterBounce',
        'letterGentleFade'
    );

    public static function Initialize()
    {
        self::InitializeEnhancementEndpoints();
        self::InitializeEditorEnhancements();
        GutenbergBlockStyles::Initialize();
    }

    private static function InitializeEnhancementEndpoints()
    {
        RestController::AddRoute('/options/enhancements', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array(self::class, 'OptionsCallbackPermissionCheck'),
            'callback' => array(self::class, 'OptionsCallback'),
        ));
        RestController::AddRoute('/options/enhancements', array(
            'methods' => WP_REST_Server::EDITABLE,
            'permission_callback' => array(self::class, 'OptionsCallbackPermissionCheck'),
            'callback' => array(self::class, 'OptionsSaveCallback'),
        ));
    }

    private static function InitializeEditorEnhancements()
    {
        // Use global (site-wide) settings for frontend rendering, not per-user.
        // This ensures consistent output regardless of who is logged in.
        $options = self::GetGlobalEnhancementsOptions();

        if (isset($options[self::CONDITIONS_KEY]) && $options[self::CONDITIONS_KEY]) {
            GutenbergConditionsController::Initialize();
        }
        if (isset($options[self::DYNAMIC_CONTENT_KEY]) && $options[self::DYNAMIC_CONTENT_KEY]) {
            GutenbergDynamicContentController::Initialize();
        }
        if (isset($options[self::SOCIAL_ICONS_KEY]) && $options[self::SOCIAL_ICONS_KEY]) {
            GutenbergSocialIconsController::Initialize();
        }
        if (isset($options[self::STICKY_KEY]) && $options[self::STICKY_KEY]) {
            GutenbergStickyController::Initialize();
        }
        if (isset($options[self::Z_INDEX_KEY]) && $options[self::Z_INDEX_KEY]) {
            GutenbergZIndexController::Initialize();
        }
        add_filter('render_block', [__CLASS__, 'FilterEnhancementsRender'], 10, 2);
        if (isset($options[self::NAVIGATION_KEY]) && $options[self::NAVIGATION_KEY]) {
            add_filter('render_block', [__CLASS__, 'NavigationEnhancementsRender'], 10, 2);
        }
        add_filter('wp_enqueue_scripts', [__CLASS__, 'EnhancementsEnqueue']);

        add_filter('rest_pre_dispatch', array(__CLASS__, 'rest_pre_dispatch'), 10, 3);
    }

    /*
        * This function is used to remove the attributes that are not allowed for the block during the server side rendering.
        * Solves the issue of editor server side rendering not working if blocks have custom attributes registered during runtime.
    */
    public static function rest_pre_dispatch($result, $server, $request)
    {
        if (strpos($request->get_route(), '/wp/v2/block-renderer') === false || !isset($request['context']) || $request['context'] !== 'edit') {
            return $result;
        }

        if (!isset($request['attributes']) || !class_exists('WP_Block_Type_Registry')) {
            return $result;
        }

        $block_type = str_replace('/wp/v2/block-renderer/', '', $request->get_route());
        $registry = WP_Block_Type_Registry::get_instance();
        $block = $registry->get_registered($block_type);
        if (!$block) {
            return $result;
        }

        $allowed_attributes = $block->get_attributes();
        $attributes = $request['attributes'];

        foreach ($attributes as $key => $value) {
            if (!isset($allowed_attributes[$key])) {
                unset($attributes[$key]);
            }
        }

        $request['attributes'] = $attributes;

        return $result;
    }

    public static function FilterEnhancementsRender($block_content, $block)
    {
        $block_content = self::MaybeAddBlockTagModifications($block_content, $block, array(
            'spbaddHideOnMobile' => array(
                'class' => 'superb-addons-hide-on-mobile',
            ),
            'spbaddHideOnTablet' => array(
                'class' => 'superb-addons-hide-on-tablet',
            ),
            'spbaddHideOnDesktop' => array(
                'class' => 'superb-addons-hide-on-desktop',
            )
        ));

        // Process spbaddResponsive object attribute for per-feature CSS classes + custom properties
        if (isset($block['attrs']['spbaddResponsive']) && is_array($block['attrs']['spbaddResponsive'])) {
            $responsive = $block['attrs']['spbaddResponsive'];
            $classes = array();
            $style_parts = array();

            self::BuildResponsiveStyles($responsive, $classes, $style_parts);

            if (!empty($classes) || !empty($style_parts)) {
                $processor = new WP_HTML_Tag_Processor($block_content);
                if ($processor->next_tag()) {
                    foreach ($classes as $cls) {
                        $processor->add_class($cls);
                    }
                    if (!empty($style_parts)) {
                        $styles = $processor->get_attribute("style");
                        $styles = isset($styles) && $styles !== null ? $styles : "";
                        if (!empty($styles) && substr($styles, -1) !== ";") {
                            $styles .= ";";
                        }
                        $processor->set_attribute("style", $styles . implode(" ", $style_parts));
                    }
                    $block_content = $processor->get_updated_html();
                }
            }
        }

        $isPopupBlock = isset($block['blockName']) && $block['blockName'] === 'superb-addons/popup';

        if (isset($block['attrs']['spbaddAnimationsEnabled']) && $block['attrs']['spbaddAnimationsEnabled']) {
            $animation_id = isset($block['attrs']['spbaddAnimationId']) ? $block['attrs']['spbaddAnimationId'] : '';
            wp_enqueue_script('superb-addons-animations', SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/block-animations.js', array(), SUPERBADDONS_VERSION, true);
            $is_letter_animation = in_array($animation_id, self::$letter_animations, true);

            // Letter animations don't apply to popup blocks
            if ($is_letter_animation && $isPopupBlock) {
                // Skip — letter animations don't make sense on a popup dialog
            } elseif ($is_letter_animation) {
                // Letter animation — output letter-specific data attribute
                $animation_modifications = array(
                    'spbaddAnimationId' => array(
                        'data-spbadd-anim-letter' => array("attribute" => "spbaddAnimationId"),
                    )
                );
                // All letter animations except letterBounce are entrance animations
                if ($animation_id !== 'letterBounce') {
                    $animation_modifications['spbaddAnimationId']['data-spbadd-entrance'] = '';
                }

                $block_content = self::MaybeAddBlockTagModifications($block_content, $block, $animation_modifications);
            } elseif ($isPopupBlock) {
                // Popup block — add animation attributes to the dialog element (not the wrapper)
                // so the MutationObserver in block-animations.js detects visibility changes via the overlay parent
                $animation_attrs = array(
                    'superb-addons-animation' => $animation_id,
                );
                if (in_array($animation_id, self::$entrance_animations, true)) {
                    $animation_attrs['data-spbadd-entrance'] = '';
                }
                if (isset($block['attrs']['spbaddAnimationSpeed']) && $block['attrs']['spbaddAnimationSpeed'] != 1) {
                    $animation_attrs['data-spbadd-animation-speed'] = strval($block['attrs']['spbaddAnimationSpeed']);
                }
                if (isset($block['attrs']['spbaddAnimationDelay']) && $block['attrs']['spbaddAnimationDelay'] > 0) {
                    $animation_attrs['data-spbadd-animation-delay'] = strval($block['attrs']['spbaddAnimationDelay']);
                }
                if (isset($block['attrs']['spbaddAnimationLoop']) && $block['attrs']['spbaddAnimationLoop']) {
                    $animation_attrs['data-spbadd-animation-loop'] = 'true';
                }
                $block_content = self::AddPopupDialogAnimationAttributes($block_content, $animation_attrs);
            } else {
                // Block animation — output block-specific data attributes
                $animation_modifications = array(
                    'spbaddAnimationId' => array(
                        'superb-addons-animation' => array("attribute" => "spbaddAnimationId"),
                    )
                );

                // Entrance animations get a data attribute that CSS uses to set opacity:0 with a safety fallback
                if (in_array($animation_id, self::$entrance_animations, true)) {
                    $animation_modifications['spbaddAnimationId']['data-spbadd-entrance'] = '';
                }

                // Shared: speed/delay/loop output for both block and letter animations
                if (isset($block['attrs']['spbaddAnimationSpeed']) && $block['attrs']['spbaddAnimationSpeed'] != 1) {
                    $animation_modifications['spbaddAnimationSpeed'] = array(
                        'data-spbadd-animation-speed' => array("attribute" => "spbaddAnimationSpeed"),
                    );
                }
                if (isset($block['attrs']['spbaddAnimationDelay']) && $block['attrs']['spbaddAnimationDelay'] > 0) {
                    $animation_modifications['spbaddAnimationDelay'] = array(
                        'data-spbadd-animation-delay' => array("attribute" => "spbaddAnimationDelay"),
                    );
                }
                if (isset($block['attrs']['spbaddAnimationLoop']) && $block['attrs']['spbaddAnimationLoop']) {
                    $animation_modifications['spbaddAnimationLoop'] = array(
                        'data-spbadd-animation-loop' => 'true',
                    );
                }

                $block_content = self::MaybeAddBlockTagModifications($block_content, $block, $animation_modifications);
            }
        }

        // Typing Animation (inline RichText format - detected in content)
        if (strpos($block_content, 'spbadd-anim-typing') !== false) {
            wp_enqueue_script('superb-addons-typing-animations', SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/typing-animations.js', array(), SUPERBADDONS_VERSION, true);
        }

        // Count Animation (inline RichText format - detected in content)
        if (strpos($block_content, 'spbadd-anim-count') !== false) {
            wp_enqueue_script('superb-addons-count-animations', SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/count-animations.js', array(), SUPERBADDONS_VERSION, true);
        }

        return $block_content;
    }

    public static function NavigationEnhancementsRender($block_content, $block)
    {
        if (!isset($block['blockName']) || $block['blockName'] !== 'core/navigation') {
            return $block_content;
        }

        if (
            isset($block['attrs']['spbaddMobileMenuJustification']) &&
            !empty($block['attrs']['spbaddMobileMenuJustification']) &&
            $block['attrs']['spbaddMobileMenuJustification'] !== 'default'
        ) {
            $block_content = self::MaybeAddBlockTagModifications($block_content, $block, array(
                'spbaddMobileMenuJustification' => array(
                    'class' => 'has-superb-addons-overlay-menu-justification',
                    'required-values' => array(
                        'left' => array(
                            'class' => 'superb-addons-overlay-menu-justification-left'
                        ),
                        'center' => array(
                            'class' => 'superb-addons-overlay-menu-justification-center'
                        ),
                        'right' => array(
                            'class' => 'superb-addons-overlay-menu-justification-right'
                        ),
                        'stretch' => array(
                            'class' => 'superb-addons-overlay-menu-justification-stretch'
                        ),
                    )
                )
            ));
        }
        if (
            isset($block['attrs']['spbaddSubmenuLayout']) &&
            !empty($block['attrs']['spbaddSubmenuLayout']) &&
            $block['attrs']['spbaddSubmenuLayout'] !== 'default'
        ) {
            $block_content = self::MaybeAddBlockTagModifications($block_content, $block, array(
                'spbaddSubmenuLayout' => array(
                    'required-values' => array(
                        'card' => array(
                            'class' => 'is-superb-addons-submenu-layout-card'
                        ),
                    )
                )
            ));
        }


        return $block_content;
    }

    public static function EnhancementsEnqueue()
    {
        wp_enqueue_style('superb-addons-enhancements', SUPERBADDONS_ASSETS_PATH . '/css/enhancements.min.css', array(), SUPERBADDONS_VERSION);
    }

    public static function MaybeAddBlockTagModifications($block_content, $block, $classes)
    {
        if (!is_array($classes) || empty($classes)) {
            return $block_content;
        }

        $added_html_classes = array();
        $added_html_styles = array();
        $added_html_attributes = array();
        foreach ($classes as $required_attribute => $modification) {
            if (!isset($block['attrs'][$required_attribute]) || !$block['attrs'][$required_attribute]) {
                continue;
            }
            foreach ($modification as $modification_key => $value) {
                if ($modification_key === 'required-values') {
                    foreach ($value as $required_value => $conditional_modifications) {
                        if ($block['attrs'][$required_attribute] !== $required_value) {
                            continue;
                        }
                        foreach ($conditional_modifications as $conditional_modification_key => $conditional_value) {
                            self::AppendModificationArrays($block, $conditional_modification_key, $conditional_value, $added_html_classes, $added_html_styles, $added_html_attributes);
                        }
                    }
                    continue;
                }

                self::AppendModificationArrays($block, $modification_key, $value, $added_html_classes, $added_html_styles, $added_html_attributes);
            }
        }

        if (empty($added_html_classes) && empty($added_html_styles) && empty($added_html_attributes)) {
            return $block_content;
        }

        $processor = new WP_HTML_Tag_Processor($block_content);
        if (!$processor->next_tag()) {
            return $block_content;
        }
        if (!empty($added_html_attributes)) {
            foreach ($added_html_attributes as $attribute => $value) {
                $processor->set_attribute($attribute, $value);
            }
        }
        if (!empty($added_html_styles)) {
            $styles = $processor->get_attribute("style");
            $styles = isset($styles) ? $styles : "";
            if (!empty($styles) && substr($styles, -1) !== ";") {
                $styles .= ";";
            }
            $processor->set_attribute("style", $styles . join(" ", $added_html_styles));
        }
        if (!empty($added_html_classes)) {
            $processor->add_class(join(" ", $added_html_classes));
        }
        return $processor->get_updated_html();
    }

    /**
     * Add animation attributes to the .superb-popup-dialog element instead of the block wrapper.
     * This allows the block-animations.js MutationObserver to detect visibility changes
     * via the overlay parent when the popup opens.
     */
    private static function AddPopupDialogAnimationAttributes($block_content, $animation_attrs)
    {
        $processor = new WP_HTML_Tag_Processor($block_content);
        while ($processor->next_tag('div')) {
            $class = $processor->get_attribute('class');
            if ($class !== null && strpos($class, 'superb-popup-dialog') !== false) {
                foreach ($animation_attrs as $attr => $val) {
                    $processor->set_attribute($attr, $val);
                }
                return $processor->get_updated_html();
            }
        }
        return $block_content;
    }

    private static function AppendModificationArrays($block, $modification_key, $value, &$added_html_classes, &$added_html_styles, &$added_html_attributes)
    {
        if (is_array($value)) {
            $key = key($value);
            $dynamic_value = $value[$key];
            switch ($key) {
                case 'attribute':
                    $value = isset($block['attrs'][$dynamic_value]) ? $block['attrs'][$dynamic_value] : (isset($value['default']) ? $value['default'] : '');
                    break;
                case 'json-attribute':
                    $raw = isset($block['attrs'][$dynamic_value]) ? $block['attrs'][$dynamic_value] : (isset($value['default']) ? $value['default'] : array());
                    $value = wp_json_encode($raw);
                    break;
                default:
                    $value = "";
                    break;
            }
        }
        switch ($modification_key) {
            case 'class':
                $added_html_classes[] = $value;
                break;
            case 'style':
                $added_html_styles[] = $value;
                break;
            default:
                $added_html_attributes[$modification_key] = $value;
                break;
        }
    }

    /**
     * Map of feature keys to their CSS class and CSS var prefix.
     * Box properties (padding, margin) use per-side vars.
     * Simple properties use a single var.
     */
    private static $responsive_feature_map = array(
        'padding' => array(
            'class' => 'superb-addons-has-padding',
            'type' => 'box',
            'prefix' => '--spbadd-padding',
        ),
        'margin' => array(
            'class' => 'superb-addons-has-margin',
            'type' => 'box',
            'prefix' => '--spbadd-margin',
        ),
        'fontSize' => array(
            'class' => 'superb-addons-has-font-size',
            'type' => 'simple',
            'prefix' => '--spbadd-font-size',
        ),
        'lineHeight' => array(
            'class' => 'superb-addons-has-line-height',
            'type' => 'simple',
            'prefix' => '--spbadd-line-height',
        ),
        'textAlign' => array(
            'class' => 'superb-addons-has-text-align',
            'type' => 'simple',
            'prefix' => '--spbadd-text-align',
        ),
        'maxWidth' => array(
            'class' => 'superb-addons-has-max-width',
            'type' => 'simple',
            'prefix' => '--spbadd-max-width',
        ),
        'gap' => array(
            'class' => 'superb-addons-has-gap',
            'type' => 'simple',
            'prefix' => '--spbadd-gap',
        ),
        'flexDir' => array(
            'class' => 'superb-addons-has-flex-dir',
            'type' => 'simple',
            'prefix' => '--spbadd-flex-dir',
        ),
        'justifyContent' => array(
            'class' => 'superb-addons-has-justify-content',
            'type' => 'simple',
            'prefix' => '--spbadd-justify-content',
        ),
        'alignItems' => array(
            'class' => 'superb-addons-has-align-items',
            'type' => 'simple',
            'prefix' => '--spbadd-align-items',
        ),
        'order' => array(
            'class' => 'superb-addons-has-order',
            'type' => 'simple',
            'prefix' => '--spbadd-order',
        ),
        'colCount' => array(
            'class' => 'superb-addons-has-col-count',
            'type' => 'simple',
            'prefix' => '--spbadd-col-count',
        ),
    );

    private static $responsive_devices = array('desktop', 'tablet', 'mobile');
    private static $responsive_box_sides = array('top', 'right', 'bottom', 'left');

    /**
     * Build per-feature CSS classes and inline CSS custom property style strings.
     *
     * @param array $responsive  The spbaddResponsive object from block attributes.
     * @param array &$classes    Array to append CSS class strings to.
     * @param array &$styles     Array to append CSS style strings to.
     */
    /**
     * Convert WP spacing preset format to CSS var.
     * e.g. "var:preset|spacing|30" → "var(--wp--preset--spacing--30)"
     */
    private static function ToCssValue($value)
    {
        if (!is_string($value) || strpos($value, 'var:') !== 0) {
            return $value;
        }
        return 'var(--wp--' . str_replace('|', '--', substr($value, 4)) . ')';
    }

    private static function BuildResponsiveStyles($responsive, &$classes, &$styles)
    {
        foreach (self::$responsive_feature_map as $feature_key => $config) {
            if (!isset($responsive[$feature_key]) || !is_array($responsive[$feature_key])) {
                continue;
            }

            $feature_data = $responsive[$feature_key];
            $has_values = false;

            if ($config['type'] === 'box') {
                // Box properties: per-side + per-device classes and CSS vars.
                // Each device that has a value gets its own class to avoid IACVT
                // when only some devices are set (e.g. mobile but not desktop).
                foreach (self::$responsive_box_sides as $side) {
                    foreach (self::$responsive_devices as $device) {
                        if (isset($feature_data[$device]) && is_array($feature_data[$device]) && isset($feature_data[$device][$side]) && $feature_data[$device][$side] !== '') {
                            $val = self::ToCssValue(sanitize_text_field($feature_data[$device][$side]));
                            $suffix = $device === 'desktop' ? '' : '-' . $device;
                            $styles[] = $config['prefix'] . '-' . $side . $suffix . ':' . $val . ';';
                            $classes[] = $config['class'] . '-' . $side . '-' . $device;
                            $has_values = true;
                        }
                    }
                }
            } else {
                // Simple properties: per-device classes + CSS vars to avoid IACVT.
                foreach (self::$responsive_devices as $device) {
                    if (!isset($feature_data[$device]) || $feature_data[$device] === '') {
                        continue;
                    }
                    $suffix = $device === 'desktop' ? '' : '-' . $device;
                    $val = self::ToCssValue(sanitize_text_field($feature_data[$device]));
                    $styles[] = $config['prefix'] . $suffix . ':' . $val . ';';
                    $classes[] = $config['class'] . '-' . $device;
                    $has_values = true;
                }
            }
        }
    }

    public static function OptionsCallbackPermissionCheck()
    {
        // Restrict endpoint to only users who have the proper capability.
        if (!current_user_can(Capabilities::CONTRIBUTOR)) {
            return new WP_Error('rest_forbidden', esc_html__('Unauthorized. Please check user permissions.', "superb-blocks"), array('status' => 401));
        }

        return true;
    }

    public static function OptionsCallback()
    {
        try {
            return rest_ensure_response(self::GetEnhancementsOptions(get_current_user_id()));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    public static function OptionsSaveCallback($request)
    {
        try {
            $key = sanitize_title($request['action']);
            $is_global = self::IsGlobalKey($key);

            // Global settings require admin capability
            if ($is_global && !current_user_can(Capabilities::ADMIN)) {
                return new \WP_Error('rest_forbidden', esc_html__('Unauthorized. Only administrators can change site-wide enhancement settings.', "superb-blocks"), array('status' => 403));
            }

            switch ($key) {
                // Per-user boolean settings
                case self::HIGHLIGHTS_KEY:
                case self::HIGHLIGHTS_QUICKOPTIONS_KEY:
                case self::HIGHLIGHTS_QUICKOPTIONS_BOTTOM_KEY:
                    $userOptions = self::GetUserOnlyOptions(get_current_user_id());
                    $userOptions[$key] = isset($request['value']) && $request['value'] === 'true';
                    if (update_user_meta(get_current_user_id(), self::ENHANCEMENTS_OPTION, $userOptions) === false) {
                        throw new Exception('Failed to update user meta');
                    }
                    break;
                // Per-user string setting
                case self::PANEL_DEFAULT_STATE_KEY:
                    $allowed = array('open', 'closed', 'dynamic');
                    $value = isset($request['value']) ? sanitize_text_field($request['value']) : '';
                    if (!in_array($value, $allowed, true)) {
                        return new \WP_Error('invalid_request', 'Invalid value', array('status' => 400));
                    }
                    $userOptions = self::GetUserOnlyOptions(get_current_user_id());
                    $userOptions[$key] = $value;
                    if (update_user_meta(get_current_user_id(), self::ENHANCEMENTS_OPTION, $userOptions) === false) {
                        throw new Exception('Failed to update user meta');
                    }
                    break;
                // Global boolean settings
                case self::RESPONSIVE_KEY:
                case self::ANIMATIONS_KEY:
                case self::CONDITIONS_KEY:
                case self::DYNAMIC_CONTENT_KEY:
                case self::NAVIGATION_KEY:
                case self::RICHTEXT_KEY:
                case self::SOCIAL_ICONS_KEY:
                case self::DASHBOARD_SHORTCUTS_KEY:
                case self::STICKY_KEY:
                case self::Z_INDEX_KEY:
                    $value = isset($request['value']) && $request['value'] === 'true';
                    if (self::SaveGlobalEnhancementOption($key, $value) === false) {
                        throw new Exception('Failed to update global enhancement option');
                    }
                    break;
                default:
                    return new \WP_Error('invalid_request', 'Invalid Request', array('status' => 400));
            }

            return rest_ensure_response(array('success' => true));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    public static function IsGlobalKey($key)
    {
        return in_array($key, self::$global_keys, true);
    }

    public static function GetGlobalEnhancementsOptions()
    {
        $defaults = array(
            self::RESPONSIVE_KEY => true,
            self::ANIMATIONS_KEY => true,
            self::CONDITIONS_KEY => true,
            self::DYNAMIC_CONTENT_KEY => true,
            self::NAVIGATION_KEY => true,
            self::RICHTEXT_KEY => true,
            self::SOCIAL_ICONS_KEY => true,
            self::DASHBOARD_SHORTCUTS_KEY => true,
            self::STICKY_KEY => true,
            self::Z_INDEX_KEY => true,
        );
        $options = get_option(Option::GLOBAL_ENHANCEMENTS, array());
        if (!is_array($options)) {
            return $defaults;
        }
        return wp_parse_args($options, $defaults);
    }

    private static function SaveGlobalEnhancementOption($key, $value)
    {
        $options = self::GetGlobalEnhancementsOptions();
        $options[$key] = $value;
        return update_option(Option::GLOBAL_ENHANCEMENTS, $options);
    }

    private static function GetUserOnlyOptions($user_id)
    {
        $defaults = array(
            self::HIGHLIGHTS_KEY => true,
            self::HIGHLIGHTS_QUICKOPTIONS_KEY => false,
            self::HIGHLIGHTS_QUICKOPTIONS_BOTTOM_KEY => false,
            self::PANEL_DEFAULT_STATE_KEY => 'open',
        );
        $options = get_user_meta($user_id, self::ENHANCEMENTS_OPTION, true);
        if (!is_array($options)) {
            return $defaults;
        }
        // Only return per-user keys, ignore any stale global keys in user_meta
        $result = array();
        foreach ($defaults as $k => $default_val) {
            $result[$k] = isset($options[$k]) ? $options[$k] : $default_val;
        }
        return $result;
    }

    public static function GetEnhancementsOptions($user_id)
    {
        $global = self::GetGlobalEnhancementsOptions();
        $per_user = self::GetUserOnlyOptions($user_id);
        return array_merge($global, $per_user);
    }
}
