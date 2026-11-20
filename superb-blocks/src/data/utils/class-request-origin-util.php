<?php

namespace SuperbAddons\Data\Utils;

defined('ABSPATH') || exit();

/**
 * Checks that a browser request to a public route comes from the site's own pages.
 *
 * The Origin header decides when it is present (the "null" of an opaque origin counts as absent), the Referer when
 * there is no Origin. A request with neither header (browsers send Origin with every POST, so this is a non-browser
 * client) is accepted unless the superbaddons_request_origin_fail_open filter says otherwise. Accepted origins are
 * the home and site addresses in both schemes, the address the request was sent to, and whatever the
 * superbaddons_allowed_request_origins filter adds (a headless front end, another domain of the site).
 */
class RequestOriginUtil
{
    /**
     * @param \WP_REST_Request $request
     * @param string           $context 'form' or 'commerce': selects the error code and message.
     * @return true|\WP_Error
     */
    public static function Check($request, $context)
    {
        // Browsers label a request from another site; non-browser clients send no such header
        $sec_fetch_site = $request->get_header('sec_fetch_site');
        if (is_string($sec_fetch_site) && strtolower(trim($sec_fetch_site)) === 'cross-site') {
            return self::Rejection($context);
        }

        $origin = $request->get_header('origin');
        if (is_string($origin) && trim($origin) !== '' && strtolower(trim($origin)) !== 'null') {
            return self::IsAllowed($origin, $request, $context) ? true : self::Rejection($context);
        }

        $referer = $request->get_header('referer');
        if (is_string($referer) && trim($referer) !== '') {
            return self::IsAllowed($referer, $request, $context) ? true : self::Rejection($context);
        }

        if (apply_filters('superbaddons_request_origin_fail_open', true, $request, $context)) {
            return true;
        }
        return self::Rejection($context);
    }

    private static function IsAllowed($url, $request, $context)
    {
        $normalized = self::Normalize($url);
        return $normalized !== '' && in_array($normalized, self::AllowedOrigins($request, $context), true);
    }

    /**
     * The origins a request may come from, normalised (see Normalize()).
     *
     * @return array
     */
    public static function AllowedOrigins($request = null, $context = '')
    {
        $origins = array();
        foreach (array(home_url('/'), site_url('/')) as $url) {
            $normalized = self::Normalize($url);
            if ($normalized === '') {
                continue;
            }
            // A proxy that ends TLS can make the scheme PHP sees differ from the one the browser used
            $origins[] = $normalized;
            $origins[] = preg_replace('/^https:/', 'http:', $normalized);
            $origins[] = preg_replace('/^http:/', 'https:', $normalized);
        }
        // The address this request was sent to: a page of the site always matches it (alias hosts, previews)
        if (isset($_SERVER['HTTP_HOST'])) {
            $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
            $own = self::Normalize((is_ssl() ? 'https' : 'http') . '://' . $host);
            if ($own !== '') {
                $origins[] = $own;
            }
        }
        $origins = apply_filters('superbaddons_allowed_request_origins', array_values(array_unique($origins)), $request, $context);

        $normalized = array();
        foreach ((array) $origins as $origin) {
            $value = is_string($origin) ? self::Normalize($origin) : '';
            if ($value !== '') {
                $normalized[] = $value;
            }
        }
        return $normalized;
    }

    /**
     * scheme://host[:port] in lower case, without a default port, a trailing dot, path, query or fragment; an
     * empty string when the value is not an http(s) URL with a host.
     *
     * @param string $url
     * @return string
     */
    public static function Normalize($url)
    {
        $parts = wp_parse_url(trim((string) $url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        $host = rtrim(strtolower($parts['host']), '.');
        $port = isset($parts['port']) ? intval($parts['port']) : 0;
        if ($port === 0 || ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            return $scheme . '://' . $host;
        }
        return $scheme . '://' . $host . ':' . $port;
    }

    private static function Rejection($context)
    {
        // A page cache must not keep this answer for a route the same browser may use next from the site itself
        nocache_headers();
        if ($context === 'commerce') {
            return new \WP_Error('rest_forbidden_origin', __('Invalid request origin.', 'superb-blocks'), array('status' => 403));
        }
        return new \WP_Error('origin_not_allowed', __('This form can only be submitted from the website it belongs to.', 'superb-blocks'), array('status' => 403));
    }
}
