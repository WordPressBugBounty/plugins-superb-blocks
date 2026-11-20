<?php

namespace SuperbAddons\Data\Utils;

defined('ABSPATH') || exit();

/**
 * The address a request came from, for the form rate limit and the hashed address stored with a submission.
 *
 * REMOTE_ADDR is used as PHP received it. A site whose proxy, CDN or load balancer leaves its own address there
 * can name the header that carries the visitor's address with the SUPERBADDONS_CLIENT_ADDRESS_HEADER constant
 * (for example HTTP_X_FORWARDED_FOR or HTTP_CF_CONNECTING_IP) or the superbaddons_client_address_header filter.
 * The header is read only then, and only its first address is used, when it is a valid IP address.
 */
class ClientAddressUtil
{
    /**
     * @return string The client address, or an empty string when the request has none.
     */
    public static function Get()
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '';
        $address = $remote;

        $header = defined('SUPERBADDONS_CLIENT_ADDRESS_HEADER') ? SUPERBADDONS_CLIENT_ADDRESS_HEADER : '';
        $header = apply_filters('superbaddons_client_address_header', $header);
        if (is_string($header) && $header !== '') {
            $header = strtoupper(str_replace('-', '_', trim($header)));
            if (strpos($header, 'HTTP_') !== 0) {
                $header = 'HTTP_' . $header;
            }
            if (isset($_SERVER[$header])) {
                $forwarded = explode(',', sanitize_text_field(wp_unslash($_SERVER[$header])));
                $first = trim($forwarded[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    $address = $first;
                }
            }
        }

        $address = apply_filters('superbaddons_client_address', $address, $remote);
        return is_string($address) ? $address : $remote;
    }
}
