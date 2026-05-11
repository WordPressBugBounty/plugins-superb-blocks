<?php

namespace SuperbAddons\Gutenberg\Controllers;

defined('ABSPATH') || exit();

/**
 * Shared helpers for marking the current request uncacheable when rendered
 * output depends on the current visitor (visibility conditions, dynamic user
 * content, etc.).
 *
 * Scopes the opt-out to two layers so both in-process WP cache plugins and
 * upstream CDNs respect it:
 *   - DONOTCACHEPAGE: honored by WP Rocket, W3TC, WP Super Cache, LiteSpeed,
 *     Kinsta/WP Engine host caches.
 *   - nocache_headers(): emits Cache-Control: no-cache, no-store, must-revalidate,
 *     which most well-behaved CDNs and reverse proxies respect.
 */
class GutenbergCacheUtil
{
    /**
     * Flag the current request as uncacheable. Safe to call multiple times.
     */
    public static function MarkAsUncacheable()
    {
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
}
