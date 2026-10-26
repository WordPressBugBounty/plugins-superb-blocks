<?php

namespace SuperbAddons\Gutenberg\BlocksAPI\Controllers;

use SuperbAddons\Data\Utils\ScriptTranslations;
use SuperbAddons\Gutenberg\Form\FormSettings;

defined('ABSPATH') || exit();

class DynamicBlockAssets
{
    public static function EnqueueAnimatedHeader($attr, $content)
    {
        wp_enqueue_script(
            'superbaddons-animated-heading',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/animated-heading.js',
            [],
            SUPERBADDONS_VERSION,
            true
        );
        return $content;
    }

    public static function EnqueueRevealButton($attr, $content)
    {
        wp_enqueue_script(
            'superbaddons-reveal-button',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/reveal-button.js',
            [],
            SUPERBADDONS_VERSION,
            true
        );
        return $content;
    }

    public static function EnqueueAccordion($attr, $content)
    {
        wp_enqueue_script(
            'superbaddons-accordion',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/accordion.js',
            [],
            SUPERBADDONS_VERSION,
            true
        );
        return $content;
    }

    public static function EnqueueCountdown($attr, $content)
    {
        wp_enqueue_script(
            'superbaddons-countdown',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/countdown.js',
            [],
            SUPERBADDONS_VERSION,
            true
        );

        // Hide countdown until JS initializes to prevent flash of uninitialized state
        $processor = new \WP_HTML_Tag_Processor($content);
        if ($processor->next_tag()) {
            $existing = $processor->get_attribute('style');
            $existing = isset($existing) ? $existing : '';
            if (!empty($existing) && substr($existing, -1) !== ';') {
                $existing .= ';';
            }
            $processor->set_attribute('style', $existing . 'visibility:hidden;');
            $content = $processor->get_updated_html();
        }

        return $content;
    }

    public static function EnqueueProgressBar($attr, $content)
    {
        wp_enqueue_script(
            'superbaddons-progress-bar',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/progress-bar.js',
            [],
            SUPERBADDONS_VERSION,
            true
        );

        // Hide block until JS initializes to prevent flash of un-animated state
        $processor = new \WP_HTML_Tag_Processor($content);
        if ($processor->next_tag()) {
            $existing = $processor->get_attribute('style');
            if (!is_string($existing)) {
                $existing = '';
            }
            if (!empty($existing) && substr($existing, -1) !== ';') {
                $existing .= ';';
            }
            $processor->set_attribute('style', $existing . 'visibility:hidden;');
            $content = $processor->get_updated_html();
        }

        return $content;
    }

    public static function EnqueueCarousel($attr, $content)
    {
        wp_enqueue_script(
            'superbaddons-carousel',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/carousel.js',
            ['wp-i18n'],
            SUPERBADDONS_VERSION,
            true
        );
        ScriptTranslations::Set('superbaddons-carousel');
        return $content;
    }

    public static function EnqueuePopup($attr, $content)
    {
        wp_enqueue_script(
            'superbaddons-popup',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/popup.js',
            [],
            SUPERBADDONS_VERSION,
            true
        );
        return $content;
    }

    public static function EnqueueForm($attr, $content, $block = null)
    {
        if (!$block || empty($block->inner_blocks)) {
            $isMultistep = $block && isset($block->name) && $block->name === 'superb-addons/multistep-form';
            return $isMultistep
                ? '<!-- Superb Multi-Step Form: not rendered because the form has no steps. Please add steps in the block editor. -->'
                : '<!-- Superb Form: not rendered because the form has no fields. Please add fields in the block editor. -->';
        }

        wp_enqueue_script(
            'superbaddons-form',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/form.js',
            array('wp-i18n'),
            SUPERBADDONS_VERSION,
            true
        );
        ScriptTranslations::Set('superbaddons-form');

        $content = self::SyncFormMarkup($content, $attr, isset($block->name) ? $block->name : '');

        static $localized = false;
        if (!$localized) {
            $localized = true;
            $config = array('restUrl' => \get_rest_url(null, 'superbaddons/'));
            $siteKeys = array(
                'hcaptchaSiteKey' => FormSettings::Get(FormSettings::OPTION_HCAPTCHA_SITE_KEY),
                'recaptchaSiteKey' => FormSettings::Get(FormSettings::OPTION_RECAPTCHA_SITE_KEY),
                'turnstileSiteKey' => FormSettings::Get(FormSettings::OPTION_TURNSTILE_SITE_KEY),
            );
            foreach ($siteKeys as $key => $value) {
                if (!empty($value)) {
                    $config[$key] = $value;
                }
            }
            wp_localize_script('superbaddons-form', 'superbFormsConfig', $config);
        }

        return $content;
    }

    /**
     * Visitors submit the data-form-id of the element with the block's class (form.js), so the block's form tag
     * and every other tag that carries a data-form-id get the block's formId. The block's form tag also gets the
     * form-wide default message for empty required fields. Markup the tag processor can't read is left as it is.
     */
    private static function SyncFormMarkup($content, $attr, $block_name)
    {
        if (!class_exists('WP_HTML_Tag_Processor')) {
            return $content;
        }
        $form_id = isset($attr['formId']) && is_string($attr['formId']) ? sanitize_key($attr['formId']) : '';
        $form_class = $block_name === 'superb-addons/multistep-form' ? 'wp-block-superb-addons-multistep-form' : 'wp-block-superb-addons-form';
        // Past the block's form tag, the markup is only read while it may hold another data-form-id
        $unseen_ids = substr_count(strtolower($content), 'data-form-id');
        try {
            $processor = new \WP_HTML_Tag_Processor($content);
            $changed = false;
            $found_form = false;
            while ($processor->next_tag()) {
                // The block's form tag: the first form tag with the block's class
                $is_form = !$found_form && $processor->get_tag() === 'FORM' && self::HasClass($processor->get_attribute('class'), $form_class);
                $markup_id = $processor->get_attribute('data-form-id');
                if (($is_form || $markup_id !== null) && (!is_string($markup_id) || sanitize_key($markup_id) !== $form_id)) {
                    $processor->set_attribute('data-form-id', $form_id);
                    $changed = true;
                }
                if ($markup_id !== null) {
                    $unseen_ids--;
                }
                if ($is_form) {
                    $found_form = true;
                    if (!empty($attr['requiredMessage'])) {
                        $processor->set_attribute('data-required-message', sanitize_text_field($attr['requiredMessage']));
                        $changed = true;
                    }
                }
                if ($found_form && $unseen_ids < 1) {
                    break;
                }
            }
            return $changed ? $processor->get_updated_html() : $content;
        } catch (\Exception $e) {
            return $content;
        } catch (\Throwable $e) {
            // WordPress 6.2's tag processor throws a ValueError on PHP 8 for some malformed markup
            return $content;
        }
    }

    /**
     * Whether a class attribute value lists the class (the tag processor's has_class() only exists from WordPress 6.4).
     */
    private static function HasClass($class_attr, $class_name)
    {
        return is_string($class_attr) && in_array($class_name, preg_split('/[ \t\n\f\r]+/', $class_attr, -1, PREG_SPLIT_NO_EMPTY), true);
    }

    public static function EnqueueAddToCart($attr, $content = '')
    {
        if (!function_exists('WC')) {
            return $content;
        }

        wp_enqueue_script(
            'superbaddons-add-to-cart',
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/add-to-cart.js',
            array('wp-i18n'),
            SUPERBADDONS_VERSION,
            true
        );
        ScriptTranslations::Set('superbaddons-add-to-cart');

        static $localized = false;
        if (!$localized) {
            $localized = true;
            wp_localize_script('superbaddons-add-to-cart', 'superbAddToCartConfig', array(
                'restUrl'          => \get_rest_url(null, 'superbaddons/'),
                'nonce'            => wp_create_nonce('wp_rest'),
                'cartUrl'          => esc_url_raw((string) wc_get_cart_url()),
                'checkoutUrl'      => esc_url_raw((string) wc_get_checkout_url()),
                'currencySymbol'   => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
                'currencyPosition' => (string) get_option('woocommerce_currency_pos', 'left'),
                'decimalSeparator' => function_exists('wc_get_price_decimal_separator') ? (string) wc_get_price_decimal_separator() : '.',
                'thousandSeparator' => function_exists('wc_get_price_thousand_separator') ? (string) wc_get_price_thousand_separator() : ',',
                'decimals'         => function_exists('wc_get_price_decimals') ? intval(wc_get_price_decimals()) : 2,
            ));
        }

        return $content;
    }
}
