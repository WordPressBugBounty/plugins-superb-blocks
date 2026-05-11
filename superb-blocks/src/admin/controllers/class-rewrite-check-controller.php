<?php

namespace SuperbAddons\Admin\Controllers;

defined('ABSPATH') || exit();

class RewriteCheckController
{
    const CHECK_NEEDED_TRANSIENT = 'spbaddons_rewrite_check_needed';
    const ISSUE_FOUND_TRANSIENT = 'spbaddons_rewrite_issue';

    /**
     * Schedule a rewrite rules check on the next admin page load.
     * Called on plugin activation.
     */
    public static function ScheduleCheck()
    {
        set_transient(self::CHECK_NEEDED_TRANSIENT, 1, HOUR_IN_SECONDS);
    }

    /**
     * Run the actual check if the short-lived transient exists.
     * Called on admin_init. Consumes the trigger transient and sets
     * the long-lived issue transient if a problem is found.
     */
    public static function MaybeRunCheck()
    {
        if (!get_transient(self::CHECK_NEEDED_TRANSIENT)) {
            return;
        }

        delete_transient(self::CHECK_NEEDED_TRANSIENT);

        if (self::HasRewriteIssue()) {
            set_transient(self::ISSUE_FOUND_TRANSIENT, 1, MONTH_IN_SECONDS);
        } else {
            delete_transient(self::ISSUE_FOUND_TRANSIENT);
        }
    }

    /**
     * Check whether the current WP install has a permalink/rewrite issue
     * that would break the REST API.
     */
    public static function HasRewriteIssue()
    {
        $permalink_structure = get_option('permalink_structure');
        if (empty($permalink_structure)) {
            // Plain permalinks — REST API uses ?rest_route= which always works.
            return false;
        }

        $rules = get_option('rewrite_rules');
        if (empty($rules) || !is_array($rules)) {
            return true;
        }

        $rest_prefix = rest_get_url_prefix();
        foreach ($rules as $pattern => $query) {
            if (strpos($pattern, $rest_prefix) !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an issue has been detected (long-lived transient).
     */
    public static function HasDetectedIssue()
    {
        return (bool) get_transient(self::ISSUE_FOUND_TRANSIENT);
    }

    /**
     * Clear the issue transient. Called after a successful fix
     * or when the user saves permalink settings.
     */
    public static function ClearIssue()
    {
        delete_transient(self::ISSUE_FOUND_TRANSIENT);
    }

    /**
     * Hook into permalink settings save to clear the issue flag.
     */
    public static function Initialize()
    {
        add_action('admin_init', array(__CLASS__, 'MaybeRunCheck'));
        add_action('update_option_permalink_structure', array(__CLASS__, 'ClearIssue'));
    }
}
