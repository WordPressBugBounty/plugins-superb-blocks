<?php

namespace SuperbAddons\Library\Controllers;

defined('ABSPATH') || exit();

use Exception;
use WP_Error;
use WP_REST_Server;
use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\LogController;
use SuperbAddons\Data\Controllers\RestController;

class FavoritesController
{
    const FAVORITES_META_KEY = 'superb-addons-library-favorites';
    const MAX_PER_TAB = 500;

    const FAVORITES_ROUTE = '/library/favorites';

    private static $allowed_tabs = array('patterns', 'pages', 'sections');
    private static $allowed_states = array('add', 'remove');

    public function __construct()
    {
        RestController::AddRoute(self::FAVORITES_ROUTE, array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array($this, 'PermissionCheck'),
            'callback' => array($this, 'GetCallback'),
        ));
        RestController::AddRoute(self::FAVORITES_ROUTE, array(
            'methods' => WP_REST_Server::EDITABLE,
            'permission_callback' => array($this, 'PermissionCheck'),
            'callback' => array($this, 'ToggleCallback'),
        ));
    }

    public function PermissionCheck()
    {
        if (!current_user_can(Capabilities::CONTRIBUTOR)) {
            return new WP_Error('rest_forbidden', esc_html__('Unauthorized. Please check user permissions.', "superb-blocks"), array('status' => 401));
        }
        return true;
    }

    public function GetCallback()
    {
        try {
            return rest_ensure_response(self::GetFavorites(get_current_user_id()));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    public function ToggleCallback($request)
    {
        try {
            $tab = isset($request['tab']) ? sanitize_title($request['tab']) : '';
            $id = isset($request['id']) ? sanitize_text_field($request['id']) : '';
            $state = isset($request['state']) ? sanitize_text_field($request['state']) : '';

            if (!in_array($tab, self::$allowed_tabs, true)) {
                return new WP_Error('invalid_request', 'Invalid tab', array('status' => 400));
            }
            if ($id === '' || strlen($id) > 191) {
                return new WP_Error('invalid_request', 'Invalid id', array('status' => 400));
            }
            if (!in_array($state, self::$allowed_states, true)) {
                return new WP_Error('invalid_request', 'Invalid state', array('status' => 400));
            }

            $user_id = get_current_user_id();
            $favorites = self::GetFavorites($user_id);
            $bucket = isset($favorites[$tab]) && is_array($favorites[$tab]) ? $favorites[$tab] : array();

            $index = array_search($id, $bucket, true);
            if ($state === 'add') {
                if ($index === false) {
                    $bucket[] = $id;
                }
            } else {
                if ($index !== false) {
                    array_splice($bucket, $index, 1);
                }
            }

            // Soft cap per tab — drop oldest entries if we blow past the limit.
            if (count($bucket) > self::MAX_PER_TAB) {
                $bucket = array_slice($bucket, -self::MAX_PER_TAB);
            }

            $favorites[$tab] = array_values($bucket);

            if (update_user_meta($user_id, self::FAVORITES_META_KEY, $favorites) === false) {
                throw new Exception('Failed to update user meta');
            }

            return rest_ensure_response($favorites);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    /**
     * Return the current user's favorites as an associative array keyed by tab id.
     * Missing/invalid meta is normalized to an empty array.
     */
    public static function GetFavorites($user_id)
    {
        $stored = get_user_meta($user_id, self::FAVORITES_META_KEY, true);
        if (!is_array($stored)) {
            return array();
        }
        $normalized = array();
        foreach (self::$allowed_tabs as $tab) {
            if (isset($stored[$tab]) && is_array($stored[$tab])) {
                // Coerce every id to a string for stable JS comparison.
                $normalized[$tab] = array_values(array_map('strval', $stored[$tab]));
            } else {
                $normalized[$tab] = array();
            }
        }
        return $normalized;
    }
}
