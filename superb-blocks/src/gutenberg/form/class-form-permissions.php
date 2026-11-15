<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormPermissions
{
    const OPTION_KEY = 'superbaddons_form_role_permissions';

    /**
     * All available permission capabilities.
     */
    private static $capabilities = array('view', 'delete', 'sensitive', 'export', 'spam', 'notes', 'edit', 'configure', 'create');

    /**
     * The permissions for building forms (as opposed to reading submission data). With access control off these
     * stay with every user who can edit posts, so Authors keep forms in their own posts working.
     */
    private static $authoring_capabilities = array('edit', 'configure', 'create');

    /**
     * Check if the current user has a specific form permission.
     *
     * @param string $capability One of: view, delete, sensitive, export, spam, notes
     * @return bool
     */
    public static function Can($capability)
    {
        // Admins always have full access
        if (current_user_can('manage_options')) {
            return true;
        }

        // Access control off: users who edit others' posts (Editors) have every permission; other users who edit
        // posts keep the authoring permissions. Submission data needs edit_others_posts, or the role matrix with
        // access control on.
        if (!FormAccessControl::IsEnabled()) {
            if (current_user_can('edit_others_posts')) {
                return true;
            }
            return current_user_can('edit_posts') && in_array($capability, self::$authoring_capabilities, true);
        }

        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }

        $permissions = self::GetAll();
        $user_roles = (array) $user->roles;

        foreach ($user_roles as $role) {
            if (isset($permissions[$role]) && isset($permissions[$role][$capability]) && $permissions[$role][$capability]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current user has at least one form permission (for menu visibility).
     *
     * @return bool
     */
    public static function HasAnyPermission()
    {
        if (current_user_can('manage_options')) {
            return true;
        }

        // Access control off: see Can()
        if (!FormAccessControl::IsEnabled()) {
            return current_user_can('edit_posts');
        }

        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return false;
        }

        $permissions = self::GetAll();
        $user_roles = (array) $user->roles;

        foreach ($user_roles as $role) {
            if (isset($permissions[$role])) {
                foreach (self::$capabilities as $cap) {
                    if (isset($permissions[$role][$cap]) && $permissions[$role][$cap]) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get all role permissions.
     *
     * @return array Associative array: role_slug => array(capability => bool)
     */
    public static function GetAll()
    {
        $permissions = get_option(self::OPTION_KEY, array());
        return is_array($permissions) ? $permissions : array();
    }

    /**
     * Save all role permissions.
     *
     * @param array $permissions
     * @return bool
     */
    public static function SaveAll($permissions)
    {
        $sanitized = array();

        if (!is_array($permissions)) {
            return false;
        }

        // Only allow known roles
        $wp_roles = wp_roles();
        $valid_roles = array_keys($wp_roles->roles);

        foreach ($permissions as $role => $caps) {
            $role = sanitize_key($role);
            // Skip administrator and subscriber
            if ($role === 'administrator' || $role === 'subscriber' || !in_array($role, $valid_roles, true)) {
                continue;
            }
            if (!is_array($caps)) {
                continue;
            }
            $sanitized[$role] = array();
            foreach (self::$capabilities as $cap) {
                $sanitized[$role][$cap] = !empty($caps[$cap]);
            }
        }

        return update_option(self::OPTION_KEY, $sanitized);
    }

    /**
     * Get the list of configurable roles (excludes administrator and subscriber).
     *
     * @return array Array of role_slug => role_name
     */
    public static function GetConfigurableRoles()
    {
        $wp_roles = wp_roles();
        $roles = array();
        foreach ($wp_roles->roles as $slug => $role) {
            if ($slug === 'administrator' || $slug === 'subscriber') {
                continue;
            }
            $roles[$slug] = isset($role['name']) ? translate_user_role($role['name']) : $slug;
        }
        return $roles;
    }

    /**
     * Get the current user's permissions as an associative array.
     * Used for JS localization.
     *
     * @return array
     */
    public static function GetCurrentUserPermissions()
    {
        $result = array();
        foreach (self::$capabilities as $cap) {
            $result[$cap] = self::Can($cap);
        }
        return $result;
    }
}
