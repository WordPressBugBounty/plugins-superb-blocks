<?php

namespace SuperbAddons\Gutenberg\Controllers;

defined('ABSPATH') || exit();

/**
 * Shared helpers for marking the current request uncacheable when rendered
 * output depends on the current visitor (visibility conditions, dynamic user
 * content, etc.).
 *
 * Scopes the opt-out to three layers so both in-process WP cache plugins and
 * upstream CDNs respect it:
 *   - DONOTCACHEPAGE: honored by WP Rocket, W3TC, WP Super Cache, LiteSpeed,
 *     Kinsta/WP Engine host caches.
 *   - wpfc_exclude_current_page(): WP Fastest Cache reads DONOTCACHEPAGE only
 *     for error pages and has this call of its own for everything else. It is
 *     made for page views only (see IsPageView()).
 *   - nocache_headers(): emits Cache-Control: no-cache, no-store, must-revalidate,
 *     which most well-behaved CDNs and reverse proxies respect.
 */
class GutenbergCacheUtil
{
    // Whether WP Fastest Cache has been told about the current request
    private static $wpfc_excluded = false;

    /**
     * Flag the current request as uncacheable. Safe to call multiple times.
     */
    public static function MarkAsUncacheable()
    {
        // WP Fastest Cache only listens once it has decided to cache the request, so the call does nothing elsewhere.
        // It adds an HTML comment to the output of an excluded request, so the call is kept to page views:
        // a REST response has to stay valid JSON, and WP Fastest Cache does not store JSON responses by default.
        if (!self::$wpfc_excluded && function_exists('wpfc_exclude_current_page') && self::IsPageView()) {
            self::$wpfc_excluded = true;
            wpfc_exclude_current_page();
        }

        // DONOTCACHEPAGE is the well-known unprefixed contract honored by WP Rocket, W3TC, WP Super Cache, LiteSpeed, and major managed hosts; prefixing it would defeat its purpose.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        if (!defined('DONOTCACHEPAGE')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
            define('DONOTCACHEPAGE', true);
        }

        // nocache_headers() only emits when headers haven't already been sent
        // (render_block runs during body output — fine for most setups, no-op
        // if output buffering was flushed early).
        if (!headers_sent()) {
            nocache_headers();
        }
    }

    /**
     * Whether the current request renders a page for the browser, as opposed
     * to a REST, AJAX, admin, feed or robots.txt request. The 'wp' action only
     * runs for requests that go on to load a template.
     *
     * @return bool
     */
    private static function IsPageView()
    {
        if ((defined('REST_REQUEST') && REST_REQUEST) || wp_doing_ajax() || is_admin() || !did_action('wp')) {
            return false;
        }

        return !is_feed() && !is_robots();
    }
}
