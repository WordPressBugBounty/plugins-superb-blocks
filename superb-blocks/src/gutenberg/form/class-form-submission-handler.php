<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

use SuperbAddons\Data\Utils\ClientAddressUtil;

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
        // Records carry no author: a logged-in submitter gets no core capabilities on their own record.
        $post_id = wp_insert_post(array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'post_author' => 0,
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
     * Reorder submitted fields to match the form's field configuration.
     *
     * Files are merged into the field array after the text fields, and
     * calculated fields may be appended, so stored order does not always
     * match the form. Fields missing from the config (removed fields, or a
     * form whose config is gone) keep their stored order at the end.
     *
     * @param array $fields             field_id => value
     * @param array $form_fields_config Field definitions from form config.
     * @return array
     */
    public static function OrderFieldsByConfig($fields, $form_fields_config)
    {
        if (!is_array($fields) || empty($form_fields_config) || !is_array($form_fields_config)) {
            return $fields;
        }
        $ordered = array();
        foreach ($form_fields_config as $field_def) {
            if (!isset($field_def['fieldId'])) {
                continue;
            }
            $fid = $field_def['fieldId'];
            if (array_key_exists($fid, $fields)) {
                $ordered[$fid] = $fields[$fid];
            }
        }
        foreach ($fields as $fid => $value) {
            if (!array_key_exists($fid, $ordered)) {
                $ordered[$fid] = $value;
            }
        }
        return $ordered;
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
     * @param array|null $ids Restrict to these submission IDs; null applies the filters only.
     * @param bool $visible_search True when the caller may not see sensitive values: the search term is then
     *                             matched in PHP against the values the caller can see, not with a LIKE query
     *                             over the stored data.
     * @return array
     */
    public static function GetSubmissions($form_id, $page = 1, $per_page = 20, $status = '', $starred = '', $search = '', $date_after = '', $date_before = '', $ids = null, $visible_search = false)
    {
        $search = sanitize_text_field((string) $search);
        $visible_search = $visible_search && $search !== '';

        $args = array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        );

        // Explicit selection (bulk actions, single-submission downloads). The
        // form ID meta filter below still applies, so an ID that belongs to
        // another form is dropped rather than exported.
        if (is_array($ids)) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (empty($ids)) {
                return array('submissions' => array(), 'total' => 0, 'total_pages' => 0);
            }
            $args['post__in'] = $ids;
        }

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

        // Server-side search: LIKE query on serialized field data. For a caller who may not see sensitive
        // values the match runs in PHP over visible values instead (below), so a stored value the caller
        // cannot read never decides whether a record is listed.
        if ($search !== '' && !$visible_search) {
            $meta_query[] = array(
                'key' => '_spb_form_fields',
                'value' => $search,
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

        if ($visible_search) {
            $args['posts_per_page'] = -1;
            $args['paged'] = 1;
            $args['no_found_rows'] = true;
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

        if ($visible_search) {
            $matched = array();
            foreach ($submissions as $sub) {
                if (self::MatchesVisibleValues($sub, $search)) {
                    $matched[] = $sub;
                }
            }
            $total = count($matched);
            $total_pages = $total > 0 ? 1 : 0;
            if ($per_page > 0) {
                $total_pages = (int) ceil($total / $per_page);
                $matched = array_slice($matched, max(0, intval($page) - 1) * $per_page, $per_page);
            }
            return array(
                'submissions' => $matched,
                'total' => $total,
                'total_pages' => $total_pages,
            );
        }

        return array(
            'submissions' => $submissions,
            'total' => $query->found_posts,
            'total_pages' => $query->max_num_pages,
        );
    }

    /**
     * Field IDs flagged sensitive in a form's stored config (cached per request).
     *
     * @param string $form_id
     * @return array
     */
    public static function SensitiveFieldIds($form_id)
    {
        static $cache = array();
        $form_id = (string) $form_id;
        if ($form_id === '') {
            return array();
        }
        if (!isset($cache[$form_id])) {
            $ids = array();
            $config = FormRegistry::GetConfig($form_id);
            if (is_array($config) && !empty($config['formFields']) && is_array($config['formFields'])) {
                foreach ($config['formFields'] as $field) {
                    if (!empty($field['sensitive']) && !empty($field['fieldId'])) {
                        $ids[] = (string) $field['fieldId'];
                    }
                }
            }
            $cache[$form_id] = $ids;
        }
        return $cache[$form_id];
    }

    /**
     * Whether a search term occurs in the values of a record that a caller without the sensitive grant can
     * see: fields flagged sensitive in the record's own form and encrypted values are left out.
     *
     * @param array $sub Record as built by GetSubmissions().
     * @param string $search
     * @return bool
     */
    private static function MatchesVisibleValues($sub, $search)
    {
        $hidden = self::SensitiveFieldIds(isset($sub['form_id']) ? $sub['form_id'] : '');
        $needle = self::LowerCase($search);
        if ($needle === '') {
            return false;
        }
        $fields = isset($sub['fields']) && is_array($sub['fields']) ? $sub['fields'] : array();
        foreach ($fields as $fid => $value) {
            if (in_array((string) $fid, $hidden, true)) {
                continue;
            }
            if (is_string($value)) {
                if (FormEncryption::IsEncrypted($value)) {
                    continue;
                }
                if (strpos(self::LowerCase($value), $needle) !== false) {
                    return true;
                }
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    $name = is_array($item) && isset($item['name']) ? $item['name'] : $item;
                    if (is_string($name) && strpos(self::LowerCase($name), $needle) !== false) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private static function LowerCase($text)
    {
        $text = (string) $text;
        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
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

        // File cleanup is handled by OnDeletePost() on the before_delete_post
        // hook, which wp_delete_post() fires below — and which also covers
        // deletions that bypass this method.
        return wp_delete_post($post_id, true) !== false;
    }

    /**
     * Hook: before_delete_post — delete a submission's uploaded files.
     *
     * Single source of truth for stored-submission file cleanup. Fires for
     * every permanent deletion of a submission, including those that bypass
     * Delete() (WP admin "Delete Permanently", WP-CLI, trash auto-empty, other
     * plugins). Meta is still readable at this point; wp_delete_post() removes
     * it afterward.
     *
     * @param int      $post_id
     * @param \WP_Post $post
     */
    public static function OnDeletePost($post_id, $post)
    {
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return;
        }
        $fields = get_post_meta($post_id, '_spb_form_fields', true);
        if (is_array($fields)) {
            FormFileHandler::DeleteSubmissionFiles($fields);
        }
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
                array(
                    'key' => '_spb_form_status',
                    'value' => 'spam',
                    'compare' => '!=',
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
     * Text fields only -- file field values are stored as empty arrays. The record holds the form's configured
     * fields only, sanitized by type like an accepted submission.
     *
     * @param string $form_id
     * @param array $fields
     * @param string $spam_reason 'honeypot', 'captcha', or 'bot_detection'
     * @param array $form_fields Field definitions from the form config
     * @return int|false Post ID on success, false on failure
     */
    public static function StoreSpam($form_id, $fields, $spam_reason, $form_fields = array())
    {
        $field_types = array();
        if (is_array($form_fields)) {
            foreach ($form_fields as $fc) {
                if (is_array($fc) && isset($fc['fieldId']) && is_string($fc['fieldId']) && $fc['fieldId'] !== '') {
                    $field_types[$fc['fieldId']] = isset($fc['fieldType']) && is_string($fc['fieldType']) ? $fc['fieldType'] : 'text';
                }
            }
        }

        $text_fields = array();
        if (is_array($fields)) {
            foreach ($fields as $key => $value) {
                $skey = sanitize_text_field((string) $key);
                if ($skey === '' || (!empty($field_types) && !isset($field_types[$skey]))) {
                    continue;
                }
                if (is_array($value)) {
                    // File fields and nested values are stored as empty arrays
                    $text_fields[$skey] = array();
                } elseif (is_scalar($value)) {
                    $value = substr((string) $value, 0, 10000);
                    $type = isset($field_types[$skey]) ? $field_types[$skey] : 'text';
                    $text_fields[$skey] = $type === 'textarea' ? sanitize_textarea_field($value) : sanitize_text_field($value);
                }
                if (count($text_fields) >= 200) {
                    break;
                }
            }
        }

        $post_id = wp_insert_post(array(
            'post_type' => FormSubmissionCPT::POST_TYPE,
            'post_status' => 'publish',
            'post_author' => 0,
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
    /** Records deleted per query in a purge, and the seconds one purge run may spend before the next daily run continues. */
    const PURGE_BATCH_SIZE = 100;
    const PURGE_TIME_BUDGET = 20;

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

        self::PurgeInBatches($args);
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

        self::PurgeInBatches($args);
    }

    /**
     * Delete the records a purge query selects, PURGE_BATCH_SIZE at a time, until none are left or the time
     * budget (PURGE_TIME_BUDGET seconds, filter superbaddons_form_purge_time_budget) is spent; the next daily
     * run continues where this one stopped.
     *
     * @param array $args get_posts() arguments selecting the records.
     * @return int Number of deleted records.
     */
    public static function PurgeInBatches($args)
    {
        $budget = max(1, intval(apply_filters('superbaddons_form_purge_time_budget', self::PURGE_TIME_BUDGET)));
        $deadline = microtime(true) + $budget;
        $args['posts_per_page'] = self::PURGE_BATCH_SIZE;
        $deleted = 0;
        do {
            $ids = get_posts($args);
            if (empty($ids)) {
                break;
            }
            $removed = self::BulkDelete($ids);
            $deleted += $removed;
            // A record the delete left in place would be selected again: stop instead of repeating the query
            if ($removed < count($ids)) {
                break;
            }
        } while (count($ids) === self::PURGE_BATCH_SIZE && microtime(true) < $deadline);
        return $deleted;
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
        // Notes belong to submissions: the same meta key on another post is not read
        if (get_post_type($post_id) !== FormSubmissionCPT::POST_TYPE) {
            return array();
        }
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
        return count(self::GetNotes($post_id));
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
        return wp_hash(ClientAddressUtil::Get());
    }
}
