<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormSubmissionHandler
{
    /**
     * Store a form submission.
     *
     * @param string $form_id
     * @param array $fields
     * @return int|false Post ID on success, false on failure
     */
    public static function Store($form_id, $fields)
    {
        $post_id = wp_insert_post(array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => sanitize_text_field($form_id) . ' - ' . current_time('mysql'),
        ));

        if (is_wp_error($post_id)) {
            return false;
        }

        update_post_meta($post_id, '_spb_form_id', sanitize_text_field($form_id));
        update_post_meta($post_id, '_spb_form_fields', $fields);
        update_post_meta($post_id, '_spb_form_ip', self::HashIP());
        update_post_meta($post_id, '_spb_form_status', 'new');

        return $post_id;
    }

    /**
     * Get submissions for a form.
     *
     * @param string $form_id
     * @param int $page
     * @param int $per_page
     * @param string $status
     * @param string $starred '1' to filter starred only, '' for all
     * @param string $search Search term to match against field data
     * @param string $date_after ISO date string for date range start
     * @param string $date_before ISO date string for date range end
     * @return array
     */
    public static function GetSubmissions($form_id, $page = 1, $per_page = 20, $status = '', $starred = '', $search = '', $date_after = '', $date_before = '')
    {
        $args = array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        $meta_query = array();

        if (!empty($form_id)) {
            $meta_query[] = array(
                'key' => '_spb_form_id',
                'value' => sanitize_text_field($form_id),
            );
        }

        if (!empty($status) && in_array($status, array('new', 'read', 'spam'), true)) {
            $meta_query[] = array(
                'key' => '_spb_form_status',
                'value' => $status,
            );
        }

        if ($starred === '1') {
            $meta_query[] = array(
                'key' => '_spb_form_starred',
                'value' => '1',
            );
        }

        // Server-side search: LIKE query on serialized field data
        if (!empty($search)) {
            $meta_query[] = array(
                'key' => '_spb_form_fields',
                'value' => sanitize_text_field($search),
                'compare' => 'LIKE',
            );
        }

        if (!empty($meta_query)) {
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _spb_form_id/_spb_form_status are the primary filter keys for the submission CPT; meta_query is the only way to query by them.
            $args['meta_query'] = $meta_query;
        }

        // Date range filtering
        if (!empty($date_after) || !empty($date_before)) {
            $date_query = array();
            if (!empty($date_after)) {
                $date_query['after'] = sanitize_text_field($date_after);
                $date_query['inclusive'] = true;
            }
            if (!empty($date_before)) {
                $date_query['before'] = sanitize_text_field($date_before);
                $date_query['inclusive'] = true;
            }
            $args['date_query'] = array($date_query);
        }

        $query = new \WP_Query($args);
        $submissions = array();

        foreach ($query->posts as $post) {
            $fields = get_post_meta($post->ID, '_spb_form_fields', true);
            $post_status = get_post_meta($post->ID, '_spb_form_status', true);
            $is_starred = get_post_meta($post->ID, '_spb_form_starred', true);
            $note_count = self::GetNoteCount($post->ID);
            $sub = array(
                'id' => $post->ID,
                'form_id' => get_post_meta($post->ID, '_spb_form_id', true),
                'fields' => is_array($fields) ? $fields : array(),
                'date' => $post->post_date_gmt . 'Z',
                'status' => !empty($post_status) ? $post_status : 'new',
                'starred' => $is_starred === '1',
                'note_count' => $note_count,
            );
            if ($post_status === 'spam') {
                $sub['spam_reason'] = get_post_meta($post->ID, '_spb_form_spam_reason', true);
            }
            // Include email and integration status meta if present
            $email_status = get_post_meta($post->ID, '_spb_form_email_status', true);
            if (!empty($email_status) && is_array($email_status)) {
                $sub['email_status'] = $email_status;
            }
            $integration_status = get_post_meta($post->ID, '_spb_form_integration_status', true);
            if (!empty($integration_status) && is_array($integration_status)) {
                $sub['integration_status'] = $integration_status;
            }
            $submissions[] = $sub;
        }

        return array(
            'submissions' => $submissions,
            'total' => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        );
    }

    /**
     * Get submission count for a form.
     *
     * @param string $form_id
     * @return array
     */
    public static function GetCount($form_id)
    {
        $base_args = array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        $meta_query = array();

        if (!empty($form_id)) {
            $meta_query[] = array(
                'key' => '_spb_form_id',
                'value' => sanitize_text_field($form_id),
            );
        }

        // Exclude spam from regular counts
        $meta_query[] = array(
            'key' => '_spb_form_status',
            'value' => 'spam',
            'compare' => '!=',
        );

        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _spb_form_id/_spb_form_status are the primary filter keys for the submission CPT; meta_query is the only way to query by them.
        $base_args['meta_query'] = $meta_query;

        $total_query = new \WP_Query($base_args);
        $total = $total_query->found_posts;

        $new_args = $base_args;
        $new_args['meta_query'][] = array(
            'key' => '_spb_form_status',
            'value' => 'new',
        );

        $new_query = new \WP_Query($new_args);

        return array(
            'total' => $total,
            'new' => $new_query->found_posts,
        );
    }

    /**
     * Delete a submission.
     *
     * @param int $post_id
     * @return bool
     */
    public static function Delete($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return false;
        }

        // Clean up associated uploaded files before deleting the post
        $fields = get_post_meta($post_id, '_spb_form_fields', true);
        if (is_array($fields)) {
            FormFileHandler::DeleteSubmissionFiles($fields);
        }

        return wp_delete_post($post_id, true) !== false;
    }

    /**
     * Bulk delete submissions.
     *
     * @param array $ids
     * @return int Number of deleted submissions
     */
    public static function BulkDelete($ids)
    {
        $deleted = 0;
        foreach ($ids as $id) {
            if (self::Delete(intval($id))) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Delete all submissions for a specific form.
     *
     * @param string $form_id
     * @return int Number of deleted submissions
     */
    public static function DeleteAllByFormId($form_id)
    {
        $ids = get_posts(array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _spb_form_id is the primary filter for the submission CPT; meta_query is the only way to query by it.
            'meta_query' => array(
                array(
                    'key' => '_spb_form_id',
                    'value' => sanitize_text_field($form_id),
                ),
            ),
        ));

        return self::BulkDelete($ids);
    }

    /**
     * Star a submission.
     *
     * @param int $post_id
     * @return bool
     */
    public static function Star($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return false;
        }
        update_post_meta($post_id, '_spb_form_starred', '1');
        return true;
    }

    /**
     * Unstar a submission.
     *
     * @param int $post_id
     * @return bool
     */
    public static function Unstar($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return false;
        }
        delete_post_meta($post_id, '_spb_form_starred');
        return true;
    }

    /**
     * Bulk star/unstar submissions.
     *
     * @param array $ids
     * @param bool $star true to star, false to unstar
     * @return int Number of updated submissions
     */
    public static function BulkStar($ids, $star)
    {
        $updated = 0;
        foreach ($ids as $id) {
            $result = $star ? self::Star(intval($id)) : self::Unstar(intval($id));
            if ($result) {
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * Mark a submission as read.
     *
     * @param int $post_id
     * @return bool
     */
    public static function MarkAsRead($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return false;
        }
        update_post_meta($post_id, '_spb_form_status', 'read');
        return true;
    }

    /**
     * Mark a submission as unread (new).
     *
     * @param int $post_id
     * @return bool
     */
    public static function MarkAsUnread($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return false;
        }
        update_post_meta($post_id, '_spb_form_status', 'new');
        return true;
    }

    /**
     * Bulk update status for multiple submissions.
     *
     * @param array $ids
     * @param string $status 'read' or 'new'
     * @return int Number of updated submissions
     */
    public static function BulkUpdateStatus($ids, $status)
    {
        $updated = 0;
        $method = $status === 'new' ? 'MarkAsUnread' : 'MarkAsRead';
        foreach ($ids as $id) {
            if (self::$method(intval($id))) {
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * Get all distinct form IDs that have submissions.
     *
     * @return array Array of form_id strings
     */
    public static function GetDistinctFormIds()
    {
        global $wpdb;
        $post_type = FormSubmissionCPT::POST_TYPE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT pm.meta_value
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_spb_form_id'
                AND p.post_type = %s
                AND p.post_status = 'publish'
                ORDER BY pm.meta_value ASC",
                $post_type
            )
        );

        return is_array($results) ? $results : array();
    }

    /**
     * Get the date of the last submission for a form.
     *
     * @param string $form_id
     * @return string Date string or empty
     */
    public static function GetLastSubmissionDate($form_id)
    {
        $args = array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _spb_form_id is the primary filter for the submission CPT; meta_query is the only way to query by it.
            'meta_query' => array(
                array(
                    'key' => '_spb_form_id',
                    'value' => sanitize_text_field($form_id),
                ),
            ),
        );

        $query = new \WP_Query($args);
        if ($query->have_posts()) {
            return $query->posts[0]->post_date;
        }

        return '';
    }

    /**
     * Get detailed stats for a form (total, new, today, this week).
     *
     * @param string $form_id
     * @return array
     */
    public static function GetFormStats($form_id)
    {
        $count = self::GetCount($form_id);
        $today = current_time('Y-m-d');
        $week_ago = gmdate('Y-m-d', strtotime('-7 days', strtotime($today)));

        $base_args = array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _spb_form_id is the primary filter for the submission CPT; meta_query is the only way to query by it.
            'meta_query' => array(
                array(
                    'key' => '_spb_form_id',
                    'value' => sanitize_text_field($form_id),
                ),
            ),
        );

        $today_args = $base_args;
        $today_args['date_query'] = array(
            array(
                'after' => $today . ' 00:00:00',
                'inclusive' => true,
            ),
        );
        $today_query = new \WP_Query($today_args);

        $week_args = $base_args;
        $week_args['date_query'] = array(
            array(
                'after' => $week_ago . ' 00:00:00',
                'inclusive' => true,
            ),
        );
        $week_query = new \WP_Query($week_args);

        return array(
            'total' => $count['total'],
            'new' => $count['new'],
            'today' => $today_query->found_posts,
            'this_week' => $week_query->found_posts,
        );
    }

    /**
     * Check if recent submissions for a form all have admin email failures.
     *
     * Only returns true when there are at least $min_count submissions with
     * email status tracked and ALL of them have admin email failures.
     *
     * @param string $form_id
     * @param int $count Number of recent submissions to check
     * @param int $min_count Minimum submissions required to trigger
     * @return bool
     */
    public static function HasRecentEmailFailures($form_id, $count = 5, $min_count = 3)
    {
        $ids = get_posts(array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $count,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _spb_form_id is the primary filter for the submission CPT; meta_query is the only way to query by it.
            'meta_query' => array(
                array(
                    'key' => '_spb_form_id',
                    'value' => sanitize_text_field($form_id),
                ),
                array(
                    'key' => '_spb_form_email_status',
                    'compare' => 'EXISTS',
                ),
                array(
                    'key' => '_spb_form_status',
                    'value' => 'spam',
                    'compare' => '!=',
                ),
            ),
        ));

        if (count($ids) < $min_count) {
            return false;
        }

        foreach ($ids as $id) {
            $status = get_post_meta($id, '_spb_form_email_status', true);
            if (!is_array($status) || !isset($status['admin'])) {
                continue;
            }
            if (!empty($status['admin']['sent'])) {
                // At least one recent email succeeded — no systemic issue
                return false;
            }
        }

        return true;
    }

    // ========================================
    // Spam Protection
    // ========================================

    /**
     * Increment the spam counter for a form.
     *
     * @param string $form_id
     * @return int New count
     */
    public static function IncrementSpamCount($form_id)
    {
        $option_key = '_spb_form_spam_count_' . sanitize_key($form_id);
        $current = intval(get_option($option_key, 0));
        $new_count = $current + 1;
        update_option($option_key, $new_count, false);
        return $new_count;
    }

    /**
     * Get the spam counter for a form.
     *
     * @param string $form_id
     * @return int
     */
    public static function GetSpamCount($form_id)
    {
        return intval(get_option('_spb_form_spam_count_' . sanitize_key($form_id), 0));
    }

    /**
     * Store a spam submission.
     * Text fields only -- file field values are stored as empty arrays.
     *
     * @param string $form_id
     * @param array $fields
     * @param string $spam_reason 'honeypot', 'captcha', or 'bot_detection'
     * @return int|false Post ID on success, false on failure
     */
    public static function StoreSpam($form_id, $fields, $spam_reason)
    {
        // Strip file upload data -- store empty arrays for file fields
        $text_fields = array();
        foreach ($fields as $key => $value) {
            if (is_array($value)) {
                $text_fields[sanitize_text_field($key)] = array();
            } else {
                $text_fields[sanitize_text_field($key)] = $value;
            }
        }

        $post_id = wp_insert_post(array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => sanitize_text_field($form_id) . ' - spam - ' . current_time('mysql'),
        ));

        if (is_wp_error($post_id)) {
            return false;
        }

        update_post_meta($post_id, '_spb_form_id', sanitize_text_field($form_id));
        update_post_meta($post_id, '_spb_form_fields', $text_fields);
        update_post_meta($post_id, '_spb_form_ip', self::HashIP());
        update_post_meta($post_id, '_spb_form_status', 'spam');
        update_post_meta($post_id, '_spb_form_spam_reason', sanitize_text_field($spam_reason));

        return $post_id;
    }

    /**
     * Move a spam submission to regular submissions ("Not Spam" rescue).
     * Does NOT trigger emails or integrations.
     *
     * @param int $post_id
     * @return bool
     */
    public static function MarkNotSpam($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return false;
        }
        $current_status = get_post_meta($post_id, '_spb_form_status', true);
        if ($current_status !== 'spam') {
            return false;
        }
        update_post_meta($post_id, '_spb_form_status', 'new');
        delete_post_meta($post_id, '_spb_form_spam_reason');
        return true;
    }

    /**
     * Get count of spam submissions for a form.
     *
     * @param string $form_id
     * @return int
     */
    public static function GetSpamSubmissionCount($form_id)
    {
        $args = array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _spb_form_id/_spb_form_status are the primary filter keys for the submission CPT; meta_query is the only way to query by them.
            'meta_query' => array(
                array(
                    'key' => '_spb_form_id',
                    'value' => sanitize_text_field($form_id),
                ),
                array(
                    'key' => '_spb_form_status',
                    'value' => 'spam',
                ),
            ),
        );
        $query = new \WP_Query($args);
        return $query->found_posts;
    }

    // ========================================
    // Spam Auto-Purge Cron
    // ========================================

    const SPAM_PURGE_HOOK = 'spb_form_spam_purge';

    /**
     * Schedule the daily spam purge cron if not already scheduled.
     */
    public static function ScheduleSpamPurge()
    {
        if (!wp_next_scheduled(self::SPAM_PURGE_HOOK)) {
            wp_schedule_event(time(), 'daily', self::SPAM_PURGE_HOOK);
        }
    }

    /**
     * Unschedule the spam purge cron.
     */
    public static function UnscheduleSpamPurge()
    {
        $timestamp = wp_next_scheduled(self::SPAM_PURGE_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::SPAM_PURGE_HOOK);
        }
    }

    /**
     * Purge spam submissions older than 30 days.
     */
    public static function PurgeOldSpam()
    {
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
        $args = array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'no_found_rows' => true,
            'fields' => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _spb_form_status is the primary filter for the submission CPT; meta_query is the only way to query by it.
            'meta_query' => array(
                array(
                    'key' => '_spb_form_status',
                    'value' => 'spam',
                ),
            ),
            'date_query' => array(
                array(
                    'before' => $cutoff,
                    'inclusive' => false,
                ),
            ),
        );

        $ids = get_posts($args);
        if (!empty($ids)) {
            self::BulkDelete($ids);
        }
    }

    // ========================================
    // Data Retention Auto-Purge Cron
    // ========================================

    const RETENTION_PURGE_HOOK = 'spb_form_retention_purge';

    /**
     * Schedule the daily data retention purge cron if not already scheduled.
     */
    public static function ScheduleRetentionPurge()
    {
        if (!wp_next_scheduled(self::RETENTION_PURGE_HOOK)) {
            wp_schedule_event(time(), 'daily', self::RETENTION_PURGE_HOOK);
        }
    }

    /**
     * Unschedule the data retention purge cron.
     */
    public static function UnscheduleRetentionPurge()
    {
        $timestamp = wp_next_scheduled(self::RETENTION_PURGE_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::RETENTION_PURGE_HOOK);
        }
    }

    /**
     * Purge non-spam submissions older than the configured retention period.
     */
    public static function PurgeOldSubmissions()
    {
        $days = intval(get_option('superbaddons_form_data_retention', 0));
        if ($days <= 0) {
            return;
        }

        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-' . $days . ' days'));
        $args = array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'no_found_rows' => true,
            'fields' => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _spb_form_status is the primary filter for the submission CPT; meta_query is the only way to query by it.
            'meta_query' => array(
                array(
                    'key' => '_spb_form_status',
                    'value' => 'spam',
                    'compare' => '!=',
                ),
            ),
            'date_query' => array(
                array(
                    'before' => $cutoff,
                    'inclusive' => false,
                ),
            ),
        );

        $ids = get_posts($args);
        if (!empty($ids)) {
            self::BulkDelete($ids);
        }
    }

    // ========================================
    // Submission Notes
    // ========================================

    /**
     * Add a note to a submission.
     *
     * @param int $post_id
     * @param int $author_id
     * @param string $author_name
     * @param string $text
     * @return array|false The new note on success, false on failure
     */
    public static function AddNote($post_id, $author_id, $author_name, $text)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return false;
        }

        $text = sanitize_textarea_field($text);
        if (empty($text) || mb_strlen($text) > 1000) {
            return false;
        }

        $notes = get_post_meta($post_id, '_spb_form_notes', true);
        if (!is_array($notes)) {
            $notes = array();
        }

        $note = array(
            'author_id' => intval($author_id),
            'author_name' => sanitize_text_field($author_name),
            'date' => current_time('mysql', true),
            'text' => $text,
        );

        $notes[] = $note;
        update_post_meta($post_id, '_spb_form_notes', $notes);

        return $note;
    }

    /**
     * Delete a note from a submission.
     *
     * @param int $post_id
     * @param int $index
     * @param int $current_user_id
     * @return bool
     */
    public static function DeleteNote($post_id, $index, $current_user_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return false;
        }

        $notes = get_post_meta($post_id, '_spb_form_notes', true);
        if (!is_array($notes) || !isset($notes[$index])) {
            return false;
        }

        // Only the note's author or users with manage_options can delete
        $note = $notes[$index];
        if (intval($note['author_id']) !== intval($current_user_id) && !current_user_can('manage_options')) {
            return false;
        }

        array_splice($notes, $index, 1);
        update_post_meta($post_id, '_spb_form_notes', $notes);

        return true;
    }

    /**
     * Get notes for a submission.
     *
     * @param int $post_id
     * @return array
     */
    public static function GetNotes($post_id)
    {
        $notes = get_post_meta($post_id, '_spb_form_notes', true);
        return is_array($notes) ? $notes : array();
    }

    /**
     * Get the note count for a submission.
     *
     * @param int $post_id
     * @return int
     */
    public static function GetNoteCount($post_id)
    {
        $notes = get_post_meta($post_id, '_spb_form_notes', true);
        return is_array($notes) ? count($notes) : 0;
    }

    // ========================================
    // Field Preferences
    // ========================================

    /**
     * Save field preferences for a user/form combination.
     *
     * @param int $user_id
     * @param string $form_id
     * @param array $fields Array of field IDs
     * @return bool
     */
    public static function SaveFieldPreference($user_id, $form_id, $fields)
    {
        $meta_key = '_spb_form_field_prefs_' . sanitize_key($form_id);
        return update_user_meta(intval($user_id), $meta_key, array_map('sanitize_text_field', $fields)) !== false;
    }

    /**
     * Get field preferences for a user/form combination.
     *
     * @param int $user_id
     * @param string $form_id
     * @return array|null Null if no preference saved
     */
    public static function GetFieldPreference($user_id, $form_id)
    {
        $meta_key = '_spb_form_field_prefs_' . sanitize_key($form_id);
        $fields = get_user_meta(intval($user_id), $meta_key, true);
        if (!is_array($fields) || empty($fields)) {
            return null;
        }
        return $fields;
    }

    /**
     * Hash IP for privacy-safe storage.
     *
     * @return string
     */
    private static function HashIP()
    {
        $ip = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        return wp_hash($ip);
    }
}
