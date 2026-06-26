<?php

namespace SuperbAddons\Data\Utils;

use SuperbAddons\Data\Controllers\LogController;

defined('ABSPATH') || exit();

/**
 * Compatibility helpers for coexisting with third-party plugins inside our own
 * synthetic rendering contexts (e.g. the wizard template preview canvas), where
 * the usual front-end page-load state is not fully set up.
 *
 * Two layers:
 *  - guard_preview_render(): registers shims for specific, known conflicts that
 *    we can fully fix so the preview renders completely.
 *  - run_guarded(): a generic safety net so that an uncaught error from ANY
 *    third-party hook degrades gracefully instead of white-screening the
 *    preview.
 *
 * Everything here only affects the short-lived, admin-only preview request, so
 * normal site pages are never touched.
 */
class ThirdPartyCompatibilityUtil
{
    /**
     * Register shims for known, fully-fixable third-party conflicts.
     *
     * Call this immediately before wp_head() in a synthetic render context.
     */
    public static function guard_preview_render()
    {
        self::guard_gutenverse_conditional_handles();
    }

    /**
     * Run a synthetic-render callback (e.g. wp_head / wp_body_open / wp_footer)
     * with a safety net around any uncaught error a third-party hook may throw.
     *
     * In our preview we call wp_head()/wp_footer() ourselves, so a TypeError or
     * other Error thrown by some plugin's enqueue/print callback propagates back
     * up to our call site. Without this it would abort the whole preview (a
     * "critical error" white screen). Catching it lets the rest of the canvas
     * render and records the offender so a targeted shim can be added later.
     *
     * Errors originating from our own plugin are re-thrown: this is strictly a
     * third-party safety net, not a blanket error suppressor for our own bugs.
     *
     * @param callable $callback Output-producing callback to execute.
     */
    public static function run_guarded($callback)
    {
        try {
            call_user_func($callback);
        } catch (\Throwable $e) {
            // PHP 7+. On PHP 5.6 this class of failure is a warning, not a
            // throwable, so there is nothing to catch and \Throwable never matches.
            self::handle_render_throwable($e);
        }
    }

    /**
     * Decide what to do with an error caught during a synthetic render.
     *
     * @param \Throwable $e
     */
    private static function handle_render_throwable($e)
    {
        // Never hide our own bugs: if the failure originates inside our plugin, re-throw.
        if (defined('SUPERBADDONS_PLUGIN_DIR')) {
            $file = wp_normalize_path($e->getFile());
            $plugin_dir = wp_normalize_path(SUPERBADDONS_PLUGIN_DIR);
            if ($plugin_dir !== '' && strpos($file, $plugin_dir) === 0) {
                throw $e;
            }
        }

        // Third-party fatal during our preview: record it and let the render continue.
        LogController::HandleException($e);
    }

    /**
     * Gutenverse compatibility.
     *
     * Frontend_Generator::load_conditional_scripts() and ::load_conditional_styles()
     * run array_unique() on the result of the gutenverse_conditional_script_handles /
     * gutenverse_conditional_style_handles filters. Gutenverse's own Frontend_Cache
     * hooks those filters (at priority 0) and, in its default 'file' render mechanism,
     * returns json_decode() of cached content, which is null when there is no cache for
     * our synthetic preview. array_unique(null) throws a TypeError on PHP 8 and aborts
     * the whole preview.
     *
     * We hook the same filters at PHP_INT_MAX (the highest priority, so our callback
     * runs LAST, after Gutenverse's own cache callback) and coerce any non-array value
     * to an empty array. On a healthy render the value is already an array and passes
     * through unchanged, so this only ever intervenes in the broken case.
     */
    private static function guard_gutenverse_conditional_handles()
    {
        add_filter('gutenverse_conditional_script_handles', array(__CLASS__, '_ensure_array'), PHP_INT_MAX);
        add_filter('gutenverse_conditional_style_handles', array(__CLASS__, '_ensure_array'), PHP_INT_MAX);
    }

    public static function _ensure_array($value)
    {
        return is_array($value) ? $value : array();
    }
}
