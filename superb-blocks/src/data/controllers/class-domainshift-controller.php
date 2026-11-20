<?php

namespace SuperbAddons\Data\Controllers;

defined('ABSPATH') || exit();

use Exception;
use SuperbAddons\Config\Config;
use SuperbAddons\Data\Controllers\OptionController;
use SuperbAddons\Data\Utils\CacheTypes;

class DomainShiftController
{
    const STATUS_ENDPOINT = 'addons-status/status';
    // The status answer is small and is asked for while an admin screen is built, so it gets a short wait
    const STATUS_TIMEOUT = 10;
    const LICENSE_KEY_HEADER = 'X-Superb-Key';
    const LICENSE_STAMP_HEADER = 'X-Superb-Stamp';
    const LICENSE_DOMAIN_HEADER = 'X-Superb-Domain';

    public static function FindPreferredAPIDomain()
    {
        try {
            $options_controller = new OptionController();
            $idx = 0;
            $success = false;
            foreach (Config::API_DOMAINS as $available_domain) {
                $response = wp_remote_get($available_domain, array('method' => 'HEAD'));
                if (RestController::IsAcceptableConnection($response)) {
                    $success = $options_controller->UpdateAPIDomain($idx);
                    break;
                }
                $idx++;
            }

            return $success;
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return false;
        }
    }

    public static function GetCurrentConnectionSuccess()
    {
        $options_controller = new OptionController();
        $preferred_domain = $options_controller->GetPreferredDomain();
        $response = wp_remote_get($preferred_domain, array('method' => 'HEAD'));
        return RestController::IsAcceptableConnection($response);
    }

    public static function GetServiceStatus()
    {
        $response = self::RemoteGet(self::STATUS_ENDPOINT, array('timeout' => self::STATUS_TIMEOUT));
        ///
        $status_code = wp_remote_retrieve_response_code($response);

        if (!is_array($response) || is_wp_error($response) || $status_code !== 200) {
            if ($status_code === 401) {
                return array("online" => false, "message" => __("Unauthorized. Please make sure that you are using the latest version of this plugin.", "superb-blocks"));
            }
            // "unavailable" marks a connection failure or an error response, which is usually temporary
            return array("online" => false, "unavailable" => true, "message" => __("Service Unavailable. Please contact our support team.", "superb-blocks"));
        }

        $data = json_decode($response['body']);
        if (!isset($data->elementor) || !isset($data->gutenberg)) {
            return array("online" => false, "message" => __("Service Data Unavailable. Please contact our support team.", "superb-blocks"));
        }


        return array("online" => true, CacheTypes::ELEMENTOR => $data->elementor, CacheTypes::GUTENBERG => $data->gutenberg);
    }

    public static function RemoteGet($path, $args = array())
    {
        return self::RemoteRequest('wp_remote_get', $path, $args);
    }

    public static function RemotePost($path, $args = array())
    {
        return self::RemoteRequest('wp_remote_get', $path, $args);
    }

    private static function RemoteRequest($method, $path, $args = array())
    {
        $options_controller = new OptionController();
        $preferred_domain = $options_controller->GetPreferredDomain();
        $response = $method($preferred_domain . $path, RestController::GetArgsHeadersArray($args));
        if (!RestController::IsAcceptableConnection($response)) {
            // Connection failed or blocked -> Find new preferred domain and send the request again. No recursion to avoid loop.
            if (self::FindPreferredAPIDomain()) {
                $preferred_domain = $options_controller->GetPreferredDomain(true);
                $response = $method($preferred_domain . $path, RestController::GetArgsHeadersArray($args));
            }
        }

        return $response;
    }

    /**
     * Request arguments that carry the licence fields of a service request as headers. Addresses are written
     * to access logs, stored in the update transients and shown by the upgrader ("Downloading update from"), so
     * the licence key, the activation stamp and the site address are not part of them.
     *
     * @param string               $key    Licence key.
     * @param int|string|bool|null $stamp  Activation stamp as stored; a site without one sends 0, as it always
     *                                     did. Null when the request has no stamp.
     * @param bool                 $domain Whether the request needs the site address.
     * @return array Arguments for RemoteGet; empty without a key (see LicenseQuery).
     */
    public static function LicenseArgs($key, $stamp = null, $domain = false)
    {
        $key = self::HeaderSafeKey($key);
        if ($key === '') {
            return array();
        }

        $headers = array(self::LICENSE_KEY_HEADER => $key);
        if ($stamp !== null) {
            $headers[self::LICENSE_STAMP_HEADER] = (string) absint($stamp);
        }
        if ($domain) {
            $headers[self::LICENSE_DOMAIN_HEADER] = rawurlencode(home_url());
        }

        return array('headers' => $headers);
    }

    /**
     * The address part that goes with LicenseArgs. With a key it is a short digest, so each licence and site
     * keeps an address of its own, as it had while the fields were part of the address. Without a key there is
     * nothing to keep out of the address and the request stays as it always was.
     *
     * @return string Starts with "&".
     */
    public static function LicenseQuery($key, $stamp = null, $domain = false)
    {
        $digest = self::LicenseDigest($key, $stamp);
        if ($digest !== '') {
            return '&kd=' . $digest;
        }

        return '&key=' . ($domain ? '&dm=' . urlencode(home_url()) : '') . ($stamp !== null ? '&stamp=' . absint($stamp) : '');
    }

    /**
     * @return string Sixteen hexadecimal characters, or an empty string without a key.
     */
    public static function LicenseDigest($key, $stamp = null)
    {
        $key = self::HeaderSafeKey($key);
        if ($key === '') {
            return '';
        }

        return substr(hash('sha256', $key . '|' . absint($stamp) . '|' . home_url()), 0, 16);
    }

    private static function HeaderSafeKey($key)
    {
        return is_string($key) ? (string) preg_replace('/[^\x21-\x7E]/', '', $key) : '';
    }

    public static function RemoteArgs($path)
    {
        $options_controller = new OptionController();
        $preferred_domain = $options_controller->GetPreferredDomain();
        return
            array(
                'url' => $preferred_domain . $path,
                'headers' => RestController::GetArgsHeadersArray()['headers'],
            );
    }
}
