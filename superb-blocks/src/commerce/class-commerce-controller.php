<?php

namespace SuperbAddons\Commerce;

defined('ABSPATH') || exit();

use SuperbAddons\Data\Controllers\RestController;

/**
 * Commerce (WooCommerce) REST + server logic for the Add to Cart block.
 *
 * Routes: superbaddons/commerce/*
 *   GET    /products/search          (edit_posts, 60s transient)
 *   GET    /product/{id}             (edit_posts, 60s transient)
 *   GET    /nonce                    (public, no session — lazy refresh on stale nonce)
 *   POST   /add                      (public, nonce, inits session)
 */
class CommerceController
{
    const MINIMUM_WC_VERSION = '5.0';

    const SEARCH_ROUTE              = '/commerce/products/search';
    const PRODUCT_ROUTE             = '/commerce/product/(?P<id>\d+)';
    const COUPON_SEARCH_ROUTE       = '/commerce/coupon-search';
    const NONCE_ROUTE               = '/commerce/nonce';
    const ADD_ROUTE                 = '/commerce/add';

    const USER_META_RECENT_PICKS    = 'superb_commerce_recent_product_picks';

    public static function Initialize()
    {
        // WooCommerce typically loads after this plugin, so defer the WC check
        // and route registration to `init` (runs before `rest_api_init`).
        add_action('init', array(__CLASS__, 'RegisterRoutes'), 5);
    }

    public static function RegisterRoutes()
    {
        if (!self::IsWcActive()) {
            return;
        }
        if (!self::IsWcVersionSupported()) {
            return;
        }

        RestController::AddRoute(self::SEARCH_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'EditorPermissionCheck'),
            'callback' => array(__CLASS__, 'SearchProductsCallback'),
        ));

        RestController::AddRoute(self::PRODUCT_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'EditorPermissionCheck'),
            'callback' => array(__CLASS__, 'GetProductCallback'),
        ));

        RestController::AddRoute(self::COUPON_SEARCH_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'CouponPermissionCheck'),
            'callback' => array(__CLASS__, 'SearchCouponsCallback'),
        ));

        RestController::AddRoute(self::NONCE_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'NonceCallback'),
        ));

        RestController::AddRoute(self::ADD_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'PublicNonceCheck'),
            'callback' => array(__CLASS__, 'AddCallback'),
        ));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Environment helpers
    // ─────────────────────────────────────────────────────────────────────

    public static function IsWcActive()
    {
        return function_exists('WC');
    }

    public static function IsWcVersionSupported()
    {
        if (!defined('WC_VERSION')) {
            return false;
        }
        return version_compare(WC_VERSION, self::MINIMUM_WC_VERSION, '>=');
    }

    public static function EditorPermissionCheck()
    {
        return current_user_can('edit_posts');
    }

    public static function CouponPermissionCheck()
    {
        if (!current_user_can('edit_shop_coupons')) {
            return new \WP_Error(
                'rest_forbidden',
                __('You do not have permission to manage coupons.', 'superb-blocks'),
                array('status' => 403)
            );
        }
        return true;
    }

    /**
     * Permission callback for "public" endpoints: anyone may call, but the
     * standard WP REST nonce must verify. For logged-in users the cookie-auth
     * pipeline already checks this; we re-check here so anonymous visitors
     * can't bypass the check.
     */
    public static function PublicNonceCheck($request)
    {
        $nonce = $request->get_header('x-wp-nonce');
        if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_Error(
                'rest_forbidden',
                __('Invalid or missing security token.', 'superb-blocks'),
                array('status' => 403)
            );
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────
    // /commerce/products/search
    // ─────────────────────────────────────────────────────────────────────

    public static function SearchProductsCallback($request)
    {
        $term = isset($request['term']) ? sanitize_text_field((string) $request['term']) : '';
        $limit = isset($request['limit']) ? intval($request['limit']) : 20;
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $digest = md5($term . '|' . $limit);
        $bucket = (int) floor(time() / DAY_IN_SECONDS);
        $cache_key = 'superb_commerce_search_' . $digest . '_' . $bucket;

        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $args = array(
            'status'   => 'publish',
            'limit'    => $limit,
            'orderby'  => 'date',
            'order'    => 'DESC',
            'return'   => 'objects',
            'visibility' => array('catalog', 'search', 'visible'),
        );
        if ($term !== '') {
            if (ctype_digit($term)) {
                $args['include'] = array(intval($term));
            } else {
                $args['s'] = $term;
            }
        }

        $products = wc_get_products($args);
        $out = array();
        if (is_array($products)) {
            foreach ($products as $product) {
                if (!$product) {
                    continue;
                }
                $row = self::SummarizeProductForSearch($product);
                if ($row !== null) {
                    $out[] = $row;
                }
            }
        }

        $response = array('products' => $out);
        set_transient($cache_key, $response, 60);
        return rest_ensure_response($response);
    }

    private static function SummarizeProductForSearch($product)
    {
        $id = intval($product->get_id());
        if ($id <= 0) {
            return null;
        }
        $type = $product->get_type();
        $supported = !in_array($type, array('grouped', 'external'), true);

        $thumb_id = intval($product->get_image_id());
        $thumb_url = '';
        if ($thumb_id > 0) {
            $thumb_url = wp_get_attachment_image_url($thumb_id, 'thumbnail');
            if (!$thumb_url) {
                $thumb_url = '';
            }
        }

        return array(
            'id'             => $id,
            'name'           => wp_strip_all_tags($product->get_name()),
            'sku'            => (string) $product->get_sku(),
            'type'           => $type,
            'supported'      => $supported,
            'thumbnail_url'  => esc_url_raw($thumb_url),
            'price'          => self::StructuredPrice($product),
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // /commerce/coupon-search  (admin picker)
    // ─────────────────────────────────────────────────────────────────────

    public static function SearchCouponsCallback($request)
    {
        nocache_headers();

        $search = isset($request['search']) ? sanitize_text_field((string) $request['search']) : '';
        $per_page = isset($request['per_page']) ? intval($request['per_page']) : 30;
        if ($per_page < 1) {
            $per_page = 1;
        }
        if ($per_page > 100) {
            $per_page = 100;
        }

        $digest = md5($search . '|' . $per_page);
        $bucket = (int) floor(time() / DAY_IN_SECONDS);
        $cache_key = 'superb_commerce_coupon_search_' . $digest . '_' . $bucket;

        $cached = get_transient($cache_key);
        if (!is_array($cached)) {
            $args = array(
                'post_type'      => 'shop_coupon',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page,
                'orderby'        => 'title',
                'order'          => 'ASC',
            );
            if ($search !== '') {
                $args['s'] = $search;
            }

            $query = new \WP_Query($args);
            $out = array();
            if (!empty($query->posts)) {
                foreach ($query->posts as $post) {
                    if (!$post || !isset($post->post_title)) {
                        continue;
                    }
                    $row = self::SummarizeCouponForSearch($post);
                    if ($row !== null) {
                        $out[] = $row;
                    }
                }
            }
            $cached = $out;
            set_transient($cache_key, $cached, 60);
        }

        $resp = rest_ensure_response($cached);
        $resp->header('Cache-Control', 'no-store, private');
        return $resp;
    }

    private static function SummarizeCouponForSearch($post)
    {
        $code = wp_strip_all_tags((string) $post->post_title);
        if ($code === '') {
            return null;
        }

        $coupon = function_exists('new_WC_Coupon') ? null : null;
        if (class_exists('\\WC_Coupon')) {
            $coupon = new \WC_Coupon($code);
        }

        $discount_type = '';
        $amount = '';
        $expires_ts = 0;
        $description = wp_strip_all_tags((string) $post->post_excerpt);

        if ($coupon) {
            $discount_type = (string) $coupon->get_discount_type();
            $amount = (string) $coupon->get_amount();
            $expiry = $coupon->get_date_expires();
            if ($expiry && method_exists($expiry, 'getTimestamp')) {
                $expires_ts = intval($expiry->getTimestamp());
            }
            if ($description === '') {
                $description = wp_strip_all_tags((string) $coupon->get_description());
            }
        }

        $is_expired = $expires_ts > 0 && $expires_ts < time();

        return array(
            'code'          => $code,
            'description'   => $description,
            'discount_type' => $discount_type,
            'amount'        => $amount,
            'expires'       => $expires_ts > 0 ? $expires_ts : null,
            'is_expired'    => (bool) $is_expired,
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // /commerce/product/{id}
    // ─────────────────────────────────────────────────────────────────────

    public static function GetProductCallback($request)
    {
        $id = intval($request['id']);
        if ($id <= 0) {
            return new \WP_Error('invalid_id', __('Invalid product ID.', 'superb-blocks'), array('status' => 400));
        }

        $product = wc_get_product($id);
        if (!$product) {
            return new \WP_Error('not_found', __('Product not found.', 'superb-blocks'), array('status' => 404));
        }
        if ($product->get_status() !== 'publish') {
            return new \WP_Error('not_available', __('Product is not available.', 'superb-blocks'), array('status' => 404));
        }
        $visibility = $product->get_catalog_visibility();
        if (!in_array($visibility, array('catalog', 'search', 'visible'), true)) {
            return new \WP_Error('not_available', __('Product is not available.', 'superb-blocks'), array('status' => 404));
        }

        $mtime = self::ProductMtime($product);
        $cache_key = 'superb_commerce_product_' . $id . '_' . $mtime;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return rest_ensure_response($cached);
        }

        $response = self::HydrateProduct($product);
        set_transient($cache_key, $response, 60);
        return rest_ensure_response($response);
    }

    private static function HydrateProduct($product)
    {
        $id = intval($product->get_id());
        $type = $product->get_type();
        $supported = !in_array($type, array('grouped', 'external'), true);

        $image_id = intval($product->get_image_id());
        $image = array(
            'full'      => '',
            'medium'    => '',
            'thumbnail' => '',
        );
        if ($image_id > 0) {
            $image['full'] = esc_url_raw((string) wp_get_attachment_image_url($image_id, 'full'));
            $image['medium'] = esc_url_raw((string) wp_get_attachment_image_url($image_id, 'medium'));
            $image['thumbnail'] = esc_url_raw((string) wp_get_attachment_image_url($image_id, 'thumbnail'));
        }

        $variations = array();
        $variation_attributes = array();
        if ($type === 'variable' && method_exists($product, 'get_available_variations') && method_exists($product, 'get_variation_attributes')) {
            $raw_variations = $product->get_available_variations();
            if (is_array($raw_variations)) {
                foreach ($raw_variations as $v) {
                    if (!is_array($v) || empty($v['variation_id'])) {
                        continue;
                    }
                    $vid = intval($v['variation_id']);
                    $variation_product = wc_get_product($vid);
                    if (!$variation_product) {
                        continue;
                    }

                    $v_image = array();
                    $v_image_id = intval($variation_product->get_image_id());
                    if ($v_image_id > 0) {
                        $v_image['full'] = esc_url_raw((string) wp_get_attachment_image_url($v_image_id, 'full'));
                        $v_image['medium'] = esc_url_raw((string) wp_get_attachment_image_url($v_image_id, 'medium'));
                        $v_image['thumbnail'] = esc_url_raw((string) wp_get_attachment_image_url($v_image_id, 'thumbnail'));
                    }

                    // Filter out subscription synthetic attrs (_subscription_period etc.)
                    $raw_attrs = isset($v['attributes']) && is_array($v['attributes']) ? $v['attributes'] : array();
                    $clean_attrs = array();
                    foreach ($raw_attrs as $ak => $av) {
                        $lower = strtolower($ak);
                        if (strpos($lower, '_subscription_') !== false) {
                            continue;
                        }
                        $clean_attrs[$ak] = (string) $av;
                    }

                    $variations[] = array(
                        'variation_id' => $vid,
                        'attributes'   => $clean_attrs,
                        'price'        => self::StructuredPrice($variation_product),
                        'stock'        => self::StructuredStock($variation_product),
                        'image'        => $v_image,
                        'is_purchasable' => (bool) $variation_product->is_purchasable(),
                    );
                }
            }

            $raw_attrs = $product->get_variation_attributes();
            if (is_array($raw_attrs)) {
                foreach ($raw_attrs as $attr_name => $options) {
                    if (strpos(strtolower($attr_name), '_subscription_') !== false) {
                        continue;
                    }
                    $attribute_key = sanitize_title($attr_name);
                    $label = wc_attribute_label($attr_name, $product);
                    $normalized_options = array();
                    if (is_array($options)) {
                        foreach ($options as $opt) {
                            $normalized_options[] = array(
                                'value' => (string) $opt,
                                'label' => self::AttributeOptionLabel($attr_name, $opt),
                            );
                        }
                    }
                    $variation_attributes[] = array(
                        'name'    => $attr_name,
                        'key'     => 'attribute_' . $attribute_key,
                        'label'   => wp_strip_all_tags((string) $label),
                        'options' => $normalized_options,
                    );
                }
            }
        }

        return array(
            'id'                   => $id,
            'name'                 => wp_strip_all_tags($product->get_name()),
            'permalink'            => esc_url_raw((string) $product->get_permalink()),
            'type'                 => $type,
            'supported'            => $supported,
            'sku'                  => (string) $product->get_sku(),
            'is_purchasable'       => (bool) $product->is_purchasable(),
            'price'                => self::StructuredPrice($product),
            'stock'                => self::StructuredStock($product),
            'image'                => $image,
            'variations'           => $variations,
            'variation_attributes' => $variation_attributes,
        );
    }

    private static function AttributeOptionLabel($attr_name, $option_value)
    {
        if (taxonomy_exists($attr_name)) {
            $term = get_term_by('slug', $option_value, $attr_name);
            if ($term && !is_wp_error($term)) {
                return wp_strip_all_tags((string) $term->name);
            }
        }
        return wp_strip_all_tags((string) $option_value);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Structured price + stock payloads (no HTML)
    // ─────────────────────────────────────────────────────────────────────

    public static function StructuredPrice($product)
    {
        if (!$product) {
            return null;
        }
        $regular = $product->get_regular_price();
        $sale = $product->get_sale_price();
        $current = $product->get_price();

        $on_sale = $sale !== '' && $sale !== null && $product->is_on_sale();

        $suffix_html = '';
        if (function_exists('wc_get_price_suffix')) {
            $suffix_html = (string) wc_get_price_suffix($product);
        }

        return array(
            'regular'           => $regular === '' ? null : (string) $regular,
            'sale'              => $on_sale && $sale !== '' ? (string) $sale : null,
            'current'           => $current === '' ? null : (string) $current,
            'on_sale'           => $on_sale,
            'currency_symbol'   => html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8'),
            'currency_position' => (string) get_option('woocommerce_currency_pos', 'left'),
            'decimal_separator' => function_exists('wc_get_price_decimal_separator') ? (string) wc_get_price_decimal_separator() : '.',
            'thousand_separator' => function_exists('wc_get_price_thousand_separator') ? (string) wc_get_price_thousand_separator() : ',',
            'decimals'          => function_exists('wc_get_price_decimals') ? intval(wc_get_price_decimals()) : 2,
            'price_suffix'      => wp_strip_all_tags($suffix_html),
        );
    }

    public static function StructuredStock($product)
    {
        if (!$product) {
            return null;
        }
        $status = (string) $product->get_stock_status();
        $qty = $product->get_stock_quantity();
        $managing = (bool) $product->managing_stock();
        $backorders_allowed = (bool) $product->backorders_allowed();

        $low_threshold = intval(get_option('woocommerce_notify_low_stock_amount', 2));

        return array(
            'status'             => $status,
            'quantity'           => $qty === null ? null : intval($qty),
            'manages_stock'      => $managing,
            'backorders_allowed' => $backorders_allowed,
            'low_threshold'      => $low_threshold,
            'is_in_stock'        => (bool) $product->is_in_stock(),
        );
    }

    private static function ProductMtime($product)
    {
        $id = intval($product->get_id());
        $post = get_post($id);
        if (!$post) {
            return 0;
        }
        return strtotime($post->post_modified_gmt . ' UTC');
    }

    // ─────────────────────────────────────────────────────────────────────
    // /commerce/nonce  (lazy refresh for stale localized nonces — full-page cache
    // straddling a tick boundary, or user session rotated since render)
    // ─────────────────────────────────────────────────────────────────────

    public static function NonceCallback()
    {
        nocache_headers();

        // rest_cookie_check_errors() resets the current user to 0 on cookie-authed
        // REST requests that carry no X-WP-Nonce (this endpoint, by design). If we
        // call wp_create_nonce() in that state, the nonce hashes against user 0 and
        // later fails wp_verify_nonce() when the real user hits /add etc. — producing
        // rest_cookie_invalid_nonce. Re-resolve the user from the auth cookie first.
        if (!is_user_logged_in()) {
            $user_id = wp_validate_auth_cookie('', 'logged_in');
            if ($user_id) {
                wp_set_current_user($user_id);
            }
        }

        $response = rest_ensure_response(array(
            'nonce' => wp_create_nonce('wp_rest'),
        ));
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────
    // /commerce/add  (AJAX mode)
    // ─────────────────────────────────────────────────────────────────────

    public static function AddCallback($request)
    {
        nocache_headers();
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }

        $result = self::AddItemsToCart($params);
        if (is_wp_error($result)) {
            return $result;
        }

        $cart = WC()->cart;
        // WooCommerce-defined filter; we apply it here to return the standard mini-cart fragments WC themes expect.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        $fragments = apply_filters('woocommerce_add_to_cart_fragments', array());
        $cart_hash = $cart && method_exists($cart, 'get_cart_hash') ? (string) $cart->get_cart_hash() : '';

        $resp = rest_ensure_response(array(
            'ok'        => true,
            'fragments' => $fragments,
            'cartHash'  => $cart_hash,
        ));
        $resp->header('Cache-Control', 'no-store, private');
        return $resp;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Shared add logic
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Adds the requested product(s) to the cart, applies coupon inline if present,
     * and returns a summary array suitable for the AJAX response, or WP_Error.
     *
     * Public so premium controllers (e.g. direct-checkout) can reuse the same
     * validation + add pipeline without re-implementing it.
     */
    public static function AddItemsToCart($params)
    {
        self::BootstrapWcCart();
        $cart = WC()->cart;
        if (!$cart) {
            return self::ErrorResponse('cart_unavailable', __('Could not access cart.', 'superb-blocks'), 500);
        }

        $product_id = isset($params['product_id']) ? intval($params['product_id']) : 0;
        $variation_id = isset($params['variation_id']) ? intval($params['variation_id']) : 0;
        $qty = isset($params['qty']) ? max(1, intval($params['qty'])) : 1;
        $variation_attrs = array();
        if (isset($params['variation_attributes']) && is_array($params['variation_attributes'])) {
            foreach ($params['variation_attributes'] as $k => $v) {
                $variation_attrs[sanitize_text_field((string) $k)] = sanitize_text_field((string) $v);
            }
        }
        $extra_cart_item_data = array();
        if (isset($params['cart_item_data']) && is_array($params['cart_item_data'])) {
            foreach ($params['cart_item_data'] as $k => $v) {
                $extra_cart_item_data[sanitize_key((string) $k)] = is_scalar($v) ? sanitize_text_field((string) $v) : $v;
            }
        }

        if ($product_id <= 0) {
            return self::ErrorResponse('invalid_product', __('Invalid product.', 'superb-blocks'), 400);
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            return self::ErrorResponse('not_found', __('Product not found.', 'superb-blocks'), 404);
        }
        $type = $product->get_type();
        if (in_array($type, array('grouped', 'external'), true)) {
            return self::ErrorResponse('unsupported_type', __('This product type is not supported.', 'superb-blocks'), 400);
        }
        if (!$product->is_purchasable()) {
            return self::ErrorResponse('not_purchasable', __('This product cannot be purchased.', 'superb-blocks'), 400);
        }

        $resolve = $variation_id > 0 ? wc_get_product($variation_id) : $product;
        if (!$resolve) {
            return self::ErrorResponse('invalid_variation', __('Invalid variation.', 'superb-blocks'), 400);
        }
        if (!$resolve->is_in_stock()) {
            return self::ErrorResponse('out_of_stock', __('This product is out of stock.', 'superb-blocks'), 400);
        }
        if (!$resolve->has_enough_stock($qty)) {
            $stock_qty = $resolve->get_stock_quantity();
            return self::ErrorResponse(
                'insufficient_stock',
                sprintf(
                    /* translators: %d: number of units available */
                    __('Only %d left in stock.', 'superb-blocks'),
                    $stock_qty === null ? 0 : intval($stock_qty)
                ),
                400,
                array('stock_remaining' => $stock_qty === null ? 0 : intval($stock_qty))
            );
        }

        $cart_item_key = $cart->add_to_cart($product_id, $qty, $variation_id, $variation_attrs, $extra_cart_item_data);
        if (!$cart_item_key) {
            // Collect WC notices if any, otherwise generic
            $notices_msg = '';
            if (function_exists('wc_get_notices')) {
                $notices = wc_get_notices('error');
                if (is_array($notices) && !empty($notices)) {
                    foreach ($notices as $n) {
                        $notices_msg .= ' ' . (is_array($n) && isset($n['notice']) ? wp_strip_all_tags((string) $n['notice']) : wp_strip_all_tags((string) $n));
                    }
                    wc_clear_notices();
                }
            }
            return self::ErrorResponse(
                'add_failed',
                trim($notices_msg) !== '' ? trim($notices_msg) : __('Could not add the item to the cart.', 'superb-blocks'),
                400
            );
        }

        // Apply coupon inline (never blocks the add).
        // Coupon outcome is not surfaced — admins diagnose via their WC cart view.
        $coupon_code = isset($params['coupon']) ? sanitize_text_field((string) $params['coupon']) : '';
        if ($coupon_code !== '') {
            $cart->apply_coupon($coupon_code);
            if (function_exists('wc_get_notices')) {
                wc_clear_notices();
            }
        }

        return array(
            'cart_item_key' => $cart_item_key,
        );
    }

    /**
     * WC bootstrap: session + cart may not yet be ready at rest_api_init time.
     * Call before any WC()->cart access.
     */
    public static function BootstrapWcCart()
    {
        if (!function_exists('WC')) {
            return;
        }
        $wc = WC();
        if (!$wc) {
            return;
        }
        if (method_exists($wc, 'initialize_session') && (!isset($wc->session) || !$wc->session)) {
            $wc->initialize_session();
        }
        if (method_exists($wc, 'initialize_cart') && (!isset($wc->cart) || !$wc->cart)) {
            $wc->initialize_cart();
        }
        if (function_exists('wc_load_cart')) {
            // Extra guard for edge cases where cart object is null post-init.
            if (!$wc->cart) {
                wc_load_cart();
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Render callback
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Server render for the Add to Cart block. Resolves product (specific or context-driven),
     * enqueues the frontend script, and rewrites data-product-id when needed.
     */
    public static function RenderBlock($attributes, $content, $block = null)
    {
        if (!self::IsWcActive()) {
            $is_editor = self::IsEditorRequest();
            return $is_editor ? '<!-- Superb Add to Cart: WooCommerce not active -->' : '';
        }

        $attrs = is_array($attributes) ? $attributes : array();
        $product_source = isset($attrs['productSource']) ? sanitize_key((string) $attrs['productSource']) : 'specific';

        $resolved_id = 0;
        if ($product_source === 'current') {
            $resolved_id = self::ResolveContextProductId($block);
        } else {
            $resolved_id = isset($attrs['productId']) ? intval($attrs['productId']) : 0;
        }

        if ($resolved_id <= 0) {
            $is_editor = self::IsEditorRequest();
            return $is_editor ? '<!-- Superb Add to Cart: no product resolved -->' : '';
        }

        $product = wc_get_product($resolved_id);
        if (!$product || $product->get_status() !== 'publish') {
            $is_editor = self::IsEditorRequest();
            return $is_editor ? '<!-- Superb Add to Cart: product unavailable -->' : '';
        }
        $type = $product->get_type();
        if (in_array($type, array('grouped', 'external'), true)) {
            $is_editor = self::IsEditorRequest();
            return $is_editor ? '<!-- Superb Add to Cart: unsupported product type -->' : '';
        }

        \SuperbAddons\Gutenberg\BlocksAPI\Controllers\DynamicBlockAssets::EnqueueAddToCart($attrs, $content);

        $unique_id = wp_unique_id('atc-');

        // Rewrite data-product-id in the saved HTML so the frontend JS uses the resolved ID.
        $processor = new \WP_HTML_Tag_Processor($content);
        if ($processor->next_tag()) {
            $processor->set_attribute('data-product-id', (string) $resolved_id);
            if ($product_source === 'current') {
                $processor->set_attribute('data-product-source', 'current');
            }
            $existing_id = $processor->get_attribute('id');
            if (empty($existing_id)) {
                $processor->set_attribute('id', $unique_id);
            }
            $content = $processor->get_updated_html();
        }

        return $content;
    }

    /**
     * Resolve product ID from block context (postId/postType) with queried-object fallback.
     */
    public static function ResolveContextProductId($block)
    {
        if ($block && isset($block->context) && is_array($block->context)) {
            $ctx_type = isset($block->context['postType']) ? (string) $block->context['postType'] : '';
            $ctx_id = isset($block->context['postId']) ? intval($block->context['postId']) : 0;
            if ($ctx_type === 'product' && $ctx_id > 0) {
                return $ctx_id;
            }
        }

        // Queried-object fallback for Single Product templates.
        $obj = function_exists('get_queried_object') ? get_queried_object() : null;
        if ($obj instanceof \WP_Post && isset($obj->post_type) && $obj->post_type === 'product') {
            return intval($obj->ID);
        }

        return 0;
    }

    private static function IsEditorRequest()
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        if (is_admin()) {
            return true;
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    public static function ErrorResponse($code, $message, $status, $extra = array())
    {
        $data = array('status' => intval($status));
        if (is_array($extra) && !empty($extra)) {
            $data = array_merge($data, $extra);
        }
        return new \WP_Error($code, $message, $data);
    }

    /**
     * Return a per-user list of recent product picks (ids).
     */
    public static function GetRecentPicks($user_id)
    {
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return array();
        }
        $raw = get_user_meta($user_id, self::USER_META_RECENT_PICKS, true);
        if (!is_array($raw)) {
            return array();
        }
        return array_values(array_filter(array_map('intval', $raw)));
    }
}
