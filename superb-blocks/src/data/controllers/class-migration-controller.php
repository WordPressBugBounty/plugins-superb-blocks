<?php

namespace SuperbAddons\Data\Controllers;

defined('ABSPATH') || exit();

use Exception;
use SuperbAddons\Gutenberg\Form\FormRegistry;

/**
 * Runs each data migration once per site after the plugin's code changes, whatever route the new code arrived by
 * (updater, auto-update, WP-CLI, FTP, git deploy). Visitor requests never run a migration: while one is pending they
 * only make sure a WP-Cron event exists. The event runs migrations in short slices under a lock and resumes where the
 * last slice stopped. When cron is disabled or broken, an administrator's admin page view runs one short slice at the
 * end of its request, after the page has been sent where the server lets PHP end the response early.
 *
 * Adding a migration: write a static step on the class that owns the data (see MIGRATIONS), append its name, run
 * composer dump-autoload, and add any option or table it creates to PluginResetController.
 */
class MigrationController
{
    // Autoloaded. Equals GetCodeKey() once every migration of this code is done or has given up: the only read a
    // normal request makes.
    const KEY_OPTION = 'superbaddons_migrations_key';
    // Not autoloaded. Name => record: status (missing = pending, 'running', 'done', 'failed'), cursor, attempts,
    // retry_at, version, time, error, counts, rows.
    const STATE_OPTION = 'superbaddons_migrations';
    // Not autoloaded: "<expires>|<token>". Always read past the options cache (see ReadLock).
    const LOCK_OPTION = 'superbaddons_migrations_lock';
    const CRON_HOOK = 'superbaddons_run_migrations';

    const CRON_BUDGET = 20;
    const ADMIN_BUDGET = 3;
    // An admin page whose response can't be ended early waits for its slice
    const ADMIN_WAITING_BUDGET = 1;
    // An event this many seconds overdue means cron is disabled or broken
    const CRON_OVERDUE = 900;
    const NEXT_SLICE_DELAY = 30;
    const LOCK_TTL = 300;
    const MAX_ATTEMPTS = 5;
    const MAX_ROWS = 50;

    /**
     * Every migration, in run order: a permanent name => its step, a public static method of the class that owns
     * the data. A step does one short slice of work (about a second): it receives its cursor (null the first time)
     * and returns the next cursor (never null, never the one it was given), or true when finished.
     * - Append only: never rename, reuse or reorder a name. A new cursor format, or running a migration again on
     *   every site, needs a new name.
     * - A step is idempotent, treats missing data as nothing to do (fresh installs and reset sites run it too),
     *   leaves data the previous release can still read, works without a current user, makes no remote calls,
     *   and throws on errors (the runner logs them and retries later).
     * - Note() counts what a step found and keeps short report rows (IDs and statuses only) in its record.
     */
    const MIGRATIONS = array(
        'form-registry-sync' => array(FormRegistry::class, 'SyncRegistryStep'),
    );

    // This request's lock value while it holds the lock
    private static $lock_value = null;

    // Counts and report rows of the migration running in this request
    private static $notes = null;

    public static function Initialize()
    {
        // Before core's wp_cron (init, 10), so an event scheduled here is spawned by this same request
        add_action('init', array(__CLASS__, 'MaybeSchedule'), 9);
        add_action(self::CRON_HOOK, array(__CLASS__, 'RunFromCron'));
    }

    /**
     * Hook: init. One autoloaded option read. While a migration of this code is pending, make sure the cron event
     * exists, and when cron looks broken let an administrator's admin page view run one slice after its output.
     * Never runs a migration itself.
     */
    public static function MaybeSchedule()
    {
        try {
            if (self::IsDisabled() || wp_installing() || get_option(self::KEY_OPTION) === self::GetCodeKey()) {
                return;
            }

            $next = wp_next_scheduled(self::CRON_HOOK);
            if ($next === false) {
                wp_schedule_single_event(time(), self::CRON_HOOK);
                return;
            }

            // After core's output flush (shutdown, priority 1)
            if ($next < time() - self::CRON_OVERDUE && is_admin() && !wp_doing_ajax() && !wp_doing_cron() && current_user_can('manage_options')) {
                add_action('shutdown', array(__CLASS__, 'RunFromAdmin'), 1000);
            }
        } catch (Exception $e) {
            LogController::HandleException($e);
        } catch (\Throwable $e) {
            // PHP 7+ errors; PHP 5.6 parses this and never matches it
            LogController::HandleException($e);
        }
    }

    /**
     * Hook: superbaddons_run_migrations (a single event, rescheduled by each run while work remains).
     */
    public static function RunFromCron()
    {
        self::RunScheduled(self::CRON_BUDGET);
    }

    /**
     * Hook: shutdown, added by MaybeSchedule() only while cron is stalled. Core's flush alone doesn't send the
     * page, so end the response first where the server allows it, as wp-cron.php does; elsewhere (mod_php) the
     * page waits for a shorter slice.
     */
    public static function RunFromAdmin()
    {
        $budget = self::ADMIN_WAITING_BUDGET;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            $budget = self::ADMIN_BUDGET;
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            $budget = self::ADMIN_BUDGET;
        }
        self::RunScheduled($budget);
    }

    /**
     * Deactivation: drop the event. The state is kept, so a reactivation resumes.
     */
    public static function Unschedule()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * The plugin version plus the list of migration names: a new release checks once more (a migration that gave
     * up gets another try), and a build that adds a migration without a version bump (git deploy, QA build) runs it.
     */
    public static function GetCodeKey()
    {
        return SUPERBADDONS_VERSION . '|' . substr(md5(implode(',', array_keys(self::MIGRATIONS))), 0, 12);
    }

    /**
     * The stored migration records, name => record.
     */
    public static function GetState()
    {
        $state = get_option(self::STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    /**
     * For a running step: count an outcome, and keep up to MAX_ROWS short report rows (IDs and statuses only) in
     * the migration's record. Does nothing outside a run, e.g. when a step is called by hand.
     *
     * @param string     $outcome A short name for what the step found, e.g. 'moved'.
     * @param array|null $row     Details to keep for review.
     */
    public static function Note($outcome, $row = null)
    {
        if (self::$notes === null) {
            return;
        }
        $outcome = (string) $outcome;
        self::$notes['counts'][$outcome] = isset(self::$notes['counts'][$outcome]) ? self::$notes['counts'][$outcome] + 1 : 1;
        if (is_array($row) && count(self::$notes['rows']) < self::MAX_ROWS) {
            self::$notes['rows'][] = array_merge(array('outcome' => $outcome), $row);
        }
    }

    /**
     * The counts and rows noted so far by the migration running in this request, including earlier slices.
     *
     * @return array array('counts' => outcome => n, 'rows' => list of rows); empty outside a run.
     */
    public static function GetNotes()
    {
        return self::$notes === null ? array('counts' => array(), 'rows' => array()) : self::$notes;
    }

    /**
     * get_option() past this request's caches, for an option another request may have written since this one read it.
     */
    public static function GetFreshOption($name, $default = false)
    {
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
        $alloptions = wp_load_alloptions();
        if (isset($alloptions[$name])) {
            wp_cache_delete('alloptions', 'options');
        }
        return get_option($name, $default);
    }

    /**
     * Run due migrations for about $budget seconds under the lock, then schedule the next slice or, when nothing is
     * left to do, store this code's key so requests stop checking. Never throws. False when another request holds
     * the lock. By hand: wp eval 'SuperbAddons\Data\Controllers\MigrationController::RunPending(600);'
     *
     * @param float      $budget     Seconds of work. The first due migration always gets one slice.
     * @param array|null $migrations Name => step. Tests pass their own; defaults to MIGRATIONS.
     * @return bool
     */
    public static function RunPending($budget, $migrations = null)
    {
        $is_code_list = $migrations === null;
        $migrations = $is_code_list ? self::MIGRATIONS : $migrations;
        if (!self::AcquireLock($budget)) {
            return false;
        }

        try {
            $state = self::GetFreshOption(self::STATE_OPTION, array());
            $state = is_array($state) ? $state : array();
            $deadline = microtime(true) + $budget;
            $sliced = false;
            $lock_held = true;
            foreach ($migrations as $name => $step) {
                $record = isset($state[$name]) && is_array($state[$name]) ? $state[$name] : array();
                if (!self::IsDue($record)) {
                    continue;
                }
                if ($sliced && !self::HasTime($deadline)) {
                    break;
                }
                $sliced = true;
                if (!self::RunMigration($name, $step, $record, $state, $deadline)) {
                    // The lock expired and was taken, or a plugin reset removed it: stop without writing
                    $lock_held = false;
                    break;
                }
            }
            if ($lock_held) {
                self::Settle($state, $migrations, $is_code_list);
            }
        } catch (Exception $e) {
            LogController::HandleException($e);
        } catch (\Throwable $e) {
            LogController::HandleException($e);
        } finally {
            self::ReleaseLock();
        }

        return true;
    }

    private static function RunScheduled($max_budget)
    {
        try {
            if (self::IsDisabled()) {
                return;
            }
            $budget = self::GetBudget($max_budget);
            if ($budget <= 0) {
                // wp-cron.php already spent this request's time on other events
                self::ScheduleNext(time() + self::NEXT_SLICE_DELAY);
                return;
            }
            if (!self::RunPending($budget) && wp_next_scheduled(self::CRON_HOOK) === false) {
                // Another request holds the lock, or held it when it stopped: look again when it expires at the latest
                self::ScheduleNext(max(self::GetLockExpiry(), time() + self::NEXT_SLICE_DELAY));
            }
        } catch (Exception $e) {
            LogController::HandleException($e);
        } catch (\Throwable $e) {
            LogController::HandleException($e);
        }
    }

    /**
     * Run one migration's slices until it finishes, fails or the deadline passes, saving its record after each.
     * False when the lock was lost.
     */
    private static function RunMigration($name, $step, $record, &$state, $deadline)
    {
        // Count the attempt before the step runs, so a fatal error, timeout or out-of-memory inside it counts too
        $status = isset($record['status']) ? $record['status'] : '';
        $attempts = (!empty($record['attempts']) && $status !== 'failed') ? intval($record['attempts']) + 1 : 1;
        if ($attempts > self::MAX_ATTEMPTS) {
            return self::SaveRecord($state, $name, self::GiveUp($name, $record, 'The request stopped during the migration (fatal error, time limit or memory limit).'));
        }
        $record['status'] = 'running';
        $record['attempts'] = $attempts;
        $record['retry_at'] = time() + self::Backoff($attempts);
        $record['version'] = SUPERBADDONS_VERSION;
        if (!self::SaveRecord($state, $name, $record)) {
            return false;
        }

        self::$notes = array(
            'counts' => isset($record['counts']) && is_array($record['counts']) ? $record['counts'] : array(),
            'rows' => isset($record['rows']) && is_array($record['rows']) ? $record['rows'] : array(),
        );
        try {
            do {
                $cursor = isset($record['cursor']) ? $record['cursor'] : null;
                $next = call_user_func($step, $cursor);
                $record['counts'] = self::$notes['counts'];
                $record['rows'] = self::$notes['rows'];
                if ($next === true) {
                    return self::SaveRecord($state, $name, array(
                        'status' => 'done',
                        'version' => SUPERBADDONS_VERSION,
                        'time' => time(),
                        'counts' => $record['counts'],
                        'rows' => $record['rows'],
                    ));
                }
                if ($next === null || $next === $cursor) {
                    throw new Exception(sprintf('Data migration %s made no progress.', $name));
                }
                // Progress: a later failure starts counting attempts again
                $record['cursor'] = $next;
                $record['attempts'] = 0;
                if (!self::SaveRecord($state, $name, $record)) {
                    return false;
                }
            } while (self::HasTime($deadline));

            // Out of time, not failed: the next slice continues at once
            $record['retry_at'] = 0;
        } catch (Exception $e) {
            $record = self::Failed($name, $record, $e);
        } catch (\Throwable $e) {
            $record = self::Failed($name, $record, $e);
        } finally {
            self::$notes = null;
        }

        return self::SaveRecord($state, $name, $record);
    }

    /**
     * Record a failed attempt: logged, retried with backoff, given up after MAX_ATTEMPTS until the next release.
     */
    private static function Failed($name, $record, $e)
    {
        LogController::HandleException($e);
        $record['attempts'] = max(1, intval($record['attempts']));
        $record['retry_at'] = time() + self::Backoff($record['attempts']);
        $record['error'] = substr(sanitize_text_field($e->getMessage()), 0, 200);
        if ($record['attempts'] >= self::MAX_ATTEMPTS) {
            return self::GiveUp($name, $record, $record['error']);
        }
        return $record;
    }

    private static function GiveUp($name, $record, $error)
    {
        $record['status'] = 'failed';
        $record['error'] = $error;
        $record['version'] = SUPERBADDONS_VERSION;
        LogController::LogInfo(sprintf('Data migration %1$s stopped after %2$d failed attempts.', $name, self::MAX_ATTEMPTS), 'It runs again after the next plugin update. ' . $error);
        return $record;
    }

    /**
     * Seconds before attempt $attempts + 1: 5 minutes, doubling, at most a day.
     */
    private static function Backoff($attempts)
    {
        return (int) min(DAY_IN_SECONDS, 300 * pow(2, max(0, $attempts - 1)));
    }

    private static function IsDue($record)
    {
        $status = isset($record['status']) ? $record['status'] : '';
        if ($status === 'done') {
            return false;
        }
        if ($status === 'failed') {
            // Given up for this version; a new release tries again
            return !isset($record['version']) || $record['version'] !== SUPERBADDONS_VERSION;
        }
        return empty($record['retry_at']) || intval($record['retry_at']) <= time();
    }

    /**
     * After a run: schedule the next slice or retry, or, when every migration is done or has given up, store the
     * code key so normal requests stop checking.
     */
    private static function Settle($state, $migrations, $is_code_list)
    {
        $next = 0;
        foreach ($migrations as $name => $step) {
            $record = isset($state[$name]) && is_array($state[$name]) ? $state[$name] : array();
            $status = isset($record['status']) ? $record['status'] : '';
            if ($status === 'done' || ($status === 'failed' && !self::IsDue($record))) {
                continue;
            }
            $retry_at = empty($record['retry_at']) ? 0 : intval($record['retry_at']);
            $due = max($retry_at, time() + self::NEXT_SLICE_DELAY);
            $next = $next === 0 ? $due : min($next, $due);
        }

        if ($next > 0) {
            self::ScheduleNext($next);
            return;
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
        if ($is_code_list) {
            update_option(self::KEY_OPTION, self::GetCodeKey(), true);
        }
    }

    private static function ScheduleNext($timestamp)
    {
        // A stalled cron leaves the old event behind, and a new one within 10 minutes of it would be dropped as a
        // duplicate: replace it, so the stall check measures the new one
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_single_event(intval($timestamp), self::CRON_HOOK);
    }

    private static function SaveRecord(&$state, $name, $record)
    {
        if (!self::RenewLock()) {
            return false;
        }
        $state[$name] = $record;
        update_option(self::STATE_OPTION, $state, false);
        return true;
    }

    /**
     * Seconds of work this request can still afford: wp-cron.php shares its time limit with other plugins' events.
     */
    private static function GetBudget($max)
    {
        $limit = intval(ini_get('max_execution_time'));
        if ($limit <= 0) {
            return $max;
        }
        $start = isset($_SERVER['REQUEST_TIME_FLOAT']) ? floatval($_SERVER['REQUEST_TIME_FLOAT']) : microtime(true);
        $left = $limit - (microtime(true) - $start) - 5;
        return $left < 2 ? 0 : min($max, $left);
    }

    /**
     * Whether another slice may start: before the deadline and below 80% of the memory limit.
     */
    private static function HasTime($deadline)
    {
        if (microtime(true) >= $deadline) {
            return false;
        }
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        return $limit <= 0 || memory_get_usage(true) < $limit * 0.8;
    }

    private static function IsDisabled()
    {
        return defined('SUPERBADDONS_DISABLE_MIGRATIONS') && SUPERBADDONS_DISABLE_MIGRATIONS;
    }

    /**
     * Take the lock, or replace an expired one whose request stopped. A free lock is taken with one INSERT IGNORE:
     * add_option() can't make a lock (two requests can both pass its existence check, and its INSERT ... ON
     * DUPLICATE KEY UPDATE succeeds for both); core's WP_Upgrader::create_lock() uses the same INSERT IGNORE.
     * Replacing an expired lock and renewing use update_option() after reading the row past the options cache. When
     * two requests replace the same expired lock at once, both may go on for a moment: the one whose value was
     * overwritten stops at its next RenewLock(), before its next write, and steps are idempotent.
     */
    private static function AcquireLock($budget)
    {
        global $wpdb;
        $value = (time() + intval(ceil($budget)) + self::LOCK_TTL) . '|' . wp_generate_password(12, false);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock; see the method comment.
        if ($wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK_OPTION, $value)) === 1) {
            self::$lock_value = $value;
            return true;
        }

        $current = self::ReadLock();
        if ($current === null || intval($current) > time()) {
            return false;
        }
        // Expired: replace it, then read it back, as another request may be replacing it too
        if (!update_option(self::LOCK_OPTION, $value, false) || self::ReadLock() !== $value) {
            return false;
        }
        self::$lock_value = $value;
        return true;
    }

    /**
     * Before each write: check that this request still holds the lock, and extend it. False when another request
     * has taken it since, or a plugin reset deleted it.
     */
    private static function RenewLock()
    {
        if (self::$lock_value === null) {
            return false;
        }
        $current = self::ReadLock();
        if ($current !== self::$lock_value) {
            self::$lock_value = null;
            return false;
        }
        $value = (time() + self::LOCK_TTL) . substr($current, strpos($current, '|'));
        if ($value === $current) {
            return true;
        }
        if (!update_option(self::LOCK_OPTION, $value, false)) {
            self::$lock_value = null;
            return false;
        }
        self::$lock_value = $value;
        return true;
    }

    private static function ReleaseLock()
    {
        if (self::$lock_value === null) {
            return;
        }
        // Only this request's own lock: a lock that another request has taken since stays
        if (self::ReadLock() === self::$lock_value) {
            delete_option(self::LOCK_OPTION);
        }
        self::$lock_value = null;
    }

    /**
     * The lock row's value past the options cache, as another request may have changed it (null when there is none).
     */
    private static function ReadLock()
    {
        $value = self::GetFreshOption(self::LOCK_OPTION, null);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * When the lock row says it expires (0 when there is none).
     */
    private static function GetLockExpiry()
    {
        $current = self::ReadLock();
        return $current === null ? 0 : intval($current);
    }
}
