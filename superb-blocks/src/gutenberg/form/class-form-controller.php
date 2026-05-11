<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\RestController;

class FormController
{
    const SUBMIT_ROUTE = '/form/submit';
    const NONCE_ROUTE = '/form/nonce';
    const SUBMISSIONS_ROUTE = '/form/submissions';
    const SUBMISSIONS_ITEM_ROUTE = '/form/submissions/(?P<id>\d+)';
    const SUBMISSIONS_BULK_DELETE_ROUTE = '/form/submissions/bulk';
    const SUBMISSIONS_COUNT_ROUTE = '/form/submissions/count';
    const SUBMISSIONS_MARK_READ_ROUTE = '/form/submissions/(?P<id>\d+)/read';
    const SUBMISSIONS_MARK_UNREAD_ROUTE = '/form/submissions/(?P<id>\d+)/unread';
    const SUBMISSIONS_BULK_STATUS_ROUTE = '/form/submissions/bulk-status';
    const SUBMISSIONS_FORMS_ROUTE = '/form/submissions/forms';
    const FORM_DELETE_ROUTE = '/form/(?P<form_id>[a-zA-Z0-9_-]+)';
    const MAILCHIMP_LISTS_ROUTE = '/form/integrations/mailchimp/lists';
    const BREVO_LISTS_ROUTE = '/form/integrations/brevo/lists';
    const CAPTCHA_STATUS_ROUTE = '/form/captcha/status';
    const SUBMISSIONS_STAR_ROUTE = '/form/submissions/(?P<id>\d+)/star';
    const SUBMISSIONS_UNSTAR_ROUTE = '/form/submissions/(?P<id>\d+)/unstar';
    const SUBMISSIONS_BULK_STAR_ROUTE = '/form/submissions/bulk-star';
    const SUBMISSIONS_RESEND_EMAIL_ROUTE = '/form/submissions/(?P<id>\d+)/resend-email';
    const EXPORT_ROUTE = '/form/(?P<form_id>[a-zA-Z0-9_-]+)/export';
    const FILE_DOWNLOAD_ROUTE = '/form/submissions/(?P<id>\d+)/file/(?P<field_id>[a-zA-Z0-9_-]+)/(?P<index>\d+)';
    const NONCE_ACTION = 'superb_form_submit';

    const SUBMISSIONS_NOT_SPAM_ROUTE = '/form/submissions/(?P<id>\d+)/not-spam';
    const SUBMISSIONS_SPAM_COUNT_ROUTE = '/form/(?P<form_id>[a-zA-Z0-9_-]+)/spam-count';
    const RETRY_INTEGRATION_ROUTE = '/form/submissions/(?P<id>\d+)/retry-integration';

    // Phase 3: Notes
    const SUBMISSIONS_NOTES_ROUTE = '/form/submissions/(?P<id>\d+)/notes';
    const SUBMISSIONS_NOTES_DELETE_ROUTE = '/form/submissions/(?P<id>\d+)/notes/(?P<index>\d+)';

    // Phase 3: Field preferences
    const FIELDS_SAVE_ROUTE = '/form/fields';
    const FIELDS_GET_ROUTE = '/form/fields/(?P<form_id>[a-zA-Z0-9_-]+)';

    // Integrations: Webhook, Google Sheets, Slack
    const WEBHOOK_TEST_ROUTE = '/form/webhook/test';
    const WEBHOOK_SECRET_ROUTE = '/form/(?P<form_id>[a-zA-Z0-9_-]+)/webhook-secret';
    const GOOGLE_SHEETS_STATUS_ROUTE = '/form/integrations/google-sheets/status';
    const GOOGLE_SHEETS_TEST_ROUTE = '/form/integrations/google-sheets/test';
    const SLACK_TEST_ROUTE = '/form/integrations/slack/test';

    public static function Initialize()
    {
        FormSubmissionCPT::Initialize();
        FormRegistry::Initialize();
        FormAccessControl::Initialize();

        // Schedule spam auto-purge cron
        FormSubmissionHandler::ScheduleSpamPurge();
        add_action(FormSubmissionHandler::SPAM_PURGE_HOOK, array('SuperbAddons\Gutenberg\Form\FormSubmissionHandler', 'PurgeOldSpam'));

        // Schedule data retention auto-purge cron
        FormSubmissionHandler::ScheduleRetentionPurge();
        add_action(FormSubmissionHandler::RETENTION_PURGE_HOOK, array('SuperbAddons\Gutenberg\Form\FormSubmissionHandler', 'PurgeOldSubmissions'));

        RestController::AddRoute(self::NONCE_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'NonceCallback'),
        ));

        RestController::AddRoute(self::SUBMIT_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'SubmitCallback'),
        ));

        // View permission: list submissions, view forms, counts, fields, file downloads
        RestController::AddRoute(self::SUBMISSIONS_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'GetSubmissionsCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_COUNT_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'GetSubmissionsCountCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_FORMS_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'GetSubmissionsFormsCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_MARK_READ_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'MarkSubmissionReadCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_MARK_UNREAD_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'MarkSubmissionUnreadCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_BULK_STATUS_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'BulkUpdateStatusCallback'),
        ));

        RestController::AddRoute(self::FILE_DOWNLOAD_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'ServeFileCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_RESEND_EMAIL_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'ResendEmailCallback'),
        ));

        // Star/unstar: anyone with view permission
        RestController::AddRoute(self::SUBMISSIONS_STAR_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'StarSubmissionCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_UNSTAR_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'UnstarSubmissionCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_BULK_STAR_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'BulkStarCallback'),
        ));

        // Delete permission
        RestController::AddRoute(self::SUBMISSIONS_ITEM_ROUTE, array(
            'methods' => 'DELETE',
            'permission_callback' => array(__CLASS__, 'DeletePermissionCheck'),
            'callback' => array(__CLASS__, 'DeleteSubmissionCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_BULK_DELETE_ROUTE, array(
            'methods' => 'DELETE',
            'permission_callback' => array(__CLASS__, 'DeletePermissionCheck'),
            'callback' => array(__CLASS__, 'BulkDeleteSubmissionsCallback'),
        ));

        // Export permission
        RestController::AddRoute(self::EXPORT_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'ExportPermissionCheck'),
            'callback' => array(__CLASS__, 'ExportCallback'),
        ));

        // Spam permission
        RestController::AddRoute(self::SUBMISSIONS_NOT_SPAM_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'SpamPermissionCheck'),
            'callback' => array(__CLASS__, 'NotSpamCallback'),
        ));

        RestController::AddRoute(self::SUBMISSIONS_SPAM_COUNT_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'SpamPermissionCheck'),
            'callback' => array(__CLASS__, 'GetSpamCountCallback'),
        ));

        // Notes permission
        RestController::AddRoute(self::SUBMISSIONS_NOTES_ROUTE, array(
            array(
                'methods' => 'GET',
                'permission_callback' => array(__CLASS__, 'NotesPermissionCheck'),
                'callback' => array(__CLASS__, 'GetNotesCallback'),
            ),
            array(
                'methods' => 'POST',
                'permission_callback' => array(__CLASS__, 'NotesPermissionCheck'),
                'callback' => array(__CLASS__, 'AddNoteCallback'),
            ),
        ));

        RestController::AddRoute(self::SUBMISSIONS_NOTES_DELETE_ROUTE, array(
            'methods' => 'DELETE',
            'permission_callback' => array(__CLASS__, 'NotesPermissionCheck'),
            'callback' => array(__CLASS__, 'DeleteNoteCallback'),
        ));

        // Admin-only: form deletion, integrations, captcha status, retry integration
        RestController::AddRoute(self::FORM_DELETE_ROUTE, array(
            'methods' => 'DELETE',
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'DeleteFormCallback'),
        ));

        RestController::AddRoute(self::MAILCHIMP_LISTS_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'GetMailchimpListsCallback'),
        ));

        RestController::AddRoute(self::BREVO_LISTS_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'GetBrevoListsCallback'),
        ));

        RestController::AddRoute(self::CAPTCHA_STATUS_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'GetCaptchaStatusCallback'),
        ));

        RestController::AddRoute(self::RETRY_INTEGRATION_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'RetryIntegrationCallback'),
        ));

        RestController::AddRoute(self::WEBHOOK_TEST_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'WebhookTestCallback'),
        ));

        RestController::AddRoute(self::GOOGLE_SHEETS_STATUS_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'GoogleSheetsStatusCallback'),
        ));

        RestController::AddRoute(self::GOOGLE_SHEETS_TEST_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'GoogleSheetsTestCallback'),
        ));

        RestController::AddRoute(self::WEBHOOK_SECRET_ROUTE, array(
            'methods' => array('GET', 'POST', 'DELETE'),
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'WebhookSecretCallback'),
        ));

        RestController::AddRoute(self::SLACK_TEST_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'AdminPermissionCheck'),
            'callback' => array(__CLASS__, 'SlackTestCallback'),
        ));

        // Field preferences: anyone with view permission
        RestController::AddRoute(self::FIELDS_SAVE_ROUTE, array(
            'methods' => 'POST',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'SaveFieldsCallback'),
        ));

        RestController::AddRoute(self::FIELDS_GET_ROUTE, array(
            'methods' => 'GET',
            'permission_callback' => array(__CLASS__, 'ViewPermissionCheck'),
            'callback' => array(__CLASS__, 'GetFieldsCallback'),
        ));
    }

    public static function AdminPermissionCheck()
    {
        return current_user_can('manage_options');
    }

    public static function ViewPermissionCheck()
    {
        return FormPermissions::Can('view');
    }

    public static function DeletePermissionCheck()
    {
        return FormPermissions::Can('delete');
    }

    public static function ExportPermissionCheck()
    {
        return FormPermissions::Can('export');
    }

    public static function SpamPermissionCheck()
    {
        return FormPermissions::Can('spam');
    }

    public static function NotesPermissionCheck()
    {
        return FormPermissions::Can('notes');
    }

    /**
     * Return a fresh nonce for form submission.
     * This solves cached pages where inline nonces expire.
     */
    public static function NonceCallback()
    {
        return rest_ensure_response(array(
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
        ));
    }

    /**
     * Handle form submission.
     */
    public static function SubmitCallback($request)
    {
        // Detect request format (multipart for file uploads, JSON for text-only)
        $content_type = $request->get_content_type();
        $is_multipart = $content_type && isset($content_type['value']) && strpos($content_type['value'], 'multipart/form-data') !== false;

        if ($is_multipart) {
            $params = $request->get_body_params();
        } else {
            $params = $request->get_json_params();
        }
        if (!is_array($params)) {
            $params = array();
        }

        $form_id = isset($params['form_id']) ? sanitize_text_field($params['form_id']) : '';
        $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : array();
        $captcha_token = isset($params['captcha_token']) ? sanitize_text_field($params['captcha_token']) : '';
        // Accept both new (field_ref) and legacy (guard_ts) timing parameter names
        $guard_ts = isset($params['field_ref']) ? sanitize_text_field($params['field_ref']) : '';
        if (empty($guard_ts)) {
            $guard_ts = isset($params['guard_ts']) ? sanitize_text_field($params['guard_ts']) : '';
        }
        $field_env = isset($params['field_env']) ? sanitize_text_field($params['field_env']) : '0';

        // Verify nonce
        $nonce = $request->get_header('X-Superb-Form-Nonce');
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Security verification failed. Please refresh and try again.', 'superb-blocks'),
            ), 403);
        }

        // Rate limiting — fixed 5-minute window per IP
        $ip_hash = wp_hash(isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '');
        $rate_key = 'spb_form_rate_' . $ip_hash;
        $rate_data = get_transient($rate_key);
        $rate_now = time();
        if (is_array($rate_data) && isset($rate_data['count'], $rate_data['expires']) && intval($rate_data['expires']) > $rate_now) {
            $rate_count = intval($rate_data['count']);
            $rate_expires = intval($rate_data['expires']);
        } else {
            $rate_count = 0;
            $rate_expires = $rate_now + 300;
        }
        if ($rate_count >= 10) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Too many submissions. Please try again later.', 'superb-blocks'),
            ), 429);
        }
        set_transient(
            $rate_key,
            array('count' => $rate_count + 1, 'expires' => $rate_expires),
            max(1, $rate_expires - $rate_now)
        );

        // Read captcha type from server-side config (not client-supplied)
        $form_data = self::GetFormConfig($form_id);
        if ($form_data === null) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid form.', 'superb-blocks'),
            ), 400);
        }
        $captcha_type = isset($form_data['captcha_type']) ? $form_data['captcha_type'] : 'honeypot';

        // Server-side honeypot + timing validation
        $store_spam = !empty($form_data['store_enabled']) && !empty($form_data['store_spam_enabled']);
        if ($captcha_type === 'honeypot') {
            $honeypot_key = isset($form_data['honeypot_key']) ? $form_data['honeypot_key'] : '';
            if (!empty($honeypot_key)) {
                $hp_value = isset($fields[$honeypot_key]) ? $fields[$honeypot_key] : '';
                $hp_filled = is_array($hp_value) ? count($hp_value) > 0 : trim((string) $hp_value) !== '';
                if ($hp_filled) {
                    FormSubmissionHandler::IncrementSpamCount($form_id);
                    if ($store_spam) {
                        FormSubmissionHandler::StoreSpam($form_id, $fields, 'honeypot');
                    }
                    return new \WP_REST_Response(array(
                        'success' => false,
                        'message' => __('Spam detected.', 'superb-blocks'),
                    ), 400);
                }
            }
            // Timing check — reject submissions faster than 3 seconds.
            // A non-numeric value means a malformed/forged guard; treat as spam.
            if (!empty($guard_ts)) {
                if (!ctype_digit($guard_ts)) {
                    FormSubmissionHandler::IncrementSpamCount($form_id);
                    if ($store_spam) {
                        FormSubmissionHandler::StoreSpam($form_id, $fields, 'bot_detection');
                    }
                    return new \WP_REST_Response(array(
                        'success' => false,
                        'message' => __('Spam detected.', 'superb-blocks'),
                    ), 400);
                }
                $elapsed = time() - intval($guard_ts);
                if ($elapsed < 3) {
                    FormSubmissionHandler::IncrementSpamCount($form_id);
                    if ($store_spam) {
                        FormSubmissionHandler::StoreSpam($form_id, $fields, 'bot_detection');
                    }
                    return new \WP_REST_Response(array(
                        'success' => false,
                        'message' => __('Please wait a moment before submitting.', 'superb-blocks'),
                    ), 400);
                }
            }
            // Reject automated/headless browsers (navigator.webdriver = true)
            if ($field_env === '1') {
                FormSubmissionHandler::IncrementSpamCount($form_id);
                if ($store_spam) {
                    FormSubmissionHandler::StoreSpam($form_id, $fields, 'bot_detection');
                }
                return new \WP_REST_Response(array(
                    'success' => false,
                    'message' => __('Spam detected.', 'superb-blocks'),
                ), 400);
            }
        }

        // Verify captcha (third-party providers)
        $captcha_result = FormCaptchaHandler::Verify($captcha_type, $captcha_token);
        if ($captcha_result !== true) {
            FormSubmissionHandler::IncrementSpamCount($form_id);
            if ($store_spam) {
                FormSubmissionHandler::StoreSpam($form_id, $fields, 'captcha');
            }
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => is_string($captcha_result) && $captcha_result !== ''
                    ? $captcha_result
                    : __('Captcha verification failed. Please try again.', 'superb-blocks'),
            ), 400);
        }

        // Validate submitted fields against server-side config
        $form_fields = isset($form_data['form_fields']) ? $form_data['form_fields'] : array();
        $validation_result = FormFieldValidator::Validate($fields, $form_fields);
        $fields = $validation_result['fields'];

        if (!empty($validation_result['errors'])) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Please correct the errors below.', 'superb-blocks'),
                'errors' => $validation_result['errors'],
            ), 400);
        }

        // Type-aware sanitization
        $sanitized_fields = array();
        $field_type_lookup = array();
        foreach ($form_fields as $fc) {
            if (isset($fc['fieldId'])) {
                $field_type_lookup[$fc['fieldId']] = isset($fc['fieldType']) ? $fc['fieldType'] : 'text';
            }
        }
        foreach ($fields as $key => $value) {
            $skey = sanitize_text_field($key);
            $ftype = isset($field_type_lookup[$skey]) ? $field_type_lookup[$skey] : 'text';

            if ($ftype === 'textarea') {
                $sanitized_fields[$skey] = sanitize_textarea_field($value);
            } elseif ($ftype === 'signature') {
                // Signature stores a PNG data URL. sanitize_text_field would mangle the base64.
                // Validation already ensures correct format and size in FormFieldValidator.
                $prefix = 'data:image/png;base64,';
                if (strpos($value, $prefix) === 0 && strlen($value) <= 500000) {
                    $sanitized_fields[$skey] = $value;
                } else {
                    $sanitized_fields[$skey] = '';
                }
            } else {
                $sanitized_fields[$skey] = sanitize_text_field($value);
            }
        }

        // Recalculate calculated fields server-side (don't trust client values)
        foreach ($form_fields as $fc) {
            if (isset($fc['fieldType']) && $fc['fieldType'] === 'calculated' && isset($fc['fieldId'])) {
                $calc_id = $fc['fieldId'];
                $cs = isset($fc['calculatedSettings']) && is_array($fc['calculatedSettings'])
                    ? $fc['calculatedSettings']
                    : array();
                $formula = isset($cs['formula']) ? $cs['formula'] : '';
                $round_result = isset($cs['roundResult']) ? intval($cs['roundResult']) : -1;

                if ($formula !== '') {
                    $result = FormMathParser::Evaluate($formula, $sanitized_fields, $round_result);
                    $sanitized_fields[$calc_id] = strval($result);
                }
            }
        }

        // Process file uploads
        $file_data = array();
        if (!empty($_FILES['files'])) {
            $file_data = FormFileHandler::ProcessUploads($form_fields);
            // Merge file metadata into sanitized fields for storage
            foreach ($file_data as $fid => $ffiles) {
                $sanitized_fields[$fid] = $ffiles;
            }
        }

        if (empty($sanitized_fields)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('No fields submitted.', 'superb-blocks'),
            ), 400);
        }

        // Store submission if enabled
        $submission_post_id = 0;
        if (!empty($form_data['store_enabled'])) {
            $storage_fields = $sanitized_fields;
            // Encrypt sensitive fields before storage
            foreach ($form_fields as $fc) {
                $fid = isset($fc['fieldId']) ? $fc['fieldId'] : '';
                if (!empty($fc['sensitive']) && $fid !== '' && isset($storage_fields[$fid]) && is_string($storage_fields[$fid])) {
                    $storage_fields[$fid] = FormEncryption::Encrypt($storage_fields[$fid]);
                }
            }
            $submission_post_id = FormSubmissionHandler::Store($form_id, $storage_fields);
            if ($submission_post_id === false) {
                $submission_post_id = 0;
            }
        }

        // Send admin notification email
        if (!empty($form_data['email_enabled'])) {
            $to = !empty($form_data['email_to']) ? $form_data['email_to'] : get_option('admin_email');
            $valid_emails = array_filter(array_map('trim', explode(',', $to)), 'is_email');
            if (!empty($valid_emails)) {
                $form_data['email_to'] = implode(',', array_map('sanitize_email', $valid_emails));
                FormEmailHandler::SendAdminNotification($form_data, $sanitized_fields, $submission_post_id);
            }
        }

        // Send user confirmation
        if (!empty($form_data['send_confirmation'])) {
            FormEmailHandler::SendConfirmation($form_data, $sanitized_fields, $submission_post_id);
        }

        // Send to integrations and track status
        self::ProcessIntegrations($form_data, $sanitized_fields, $submission_post_id);

        // Premium hook
        do_action('superbaddons_form_after_submit', $form_id, $sanitized_fields, $form_data);

        $response = array(
            'success' => true,
            'message' => __('Form submitted successfully.', 'superb-blocks'),
        );

        // Include redirect URL from server-side config (not client-supplied)
        $success_behavior = isset($form_data['success_behavior']) ? $form_data['success_behavior'] : 'message';
        $redirect_url = isset($form_data['redirect_url']) ? $form_data['redirect_url'] : '';
        if ($success_behavior === 'redirect' && !empty($redirect_url)) {
            $response['redirect_url'] = esc_url($redirect_url);
        }

        return rest_ensure_response($response);
    }

    /**
     * Get submissions for a form.
     */
    public static function GetSubmissionsCallback($request)
    {
        $form_id = isset($request['form_id']) ? sanitize_text_field($request['form_id']) : '';
        $page = isset($request['page']) ? intval($request['page']) : 1;
        $per_page = isset($request['per_page']) ? intval($request['per_page']) : 20;
        $status = isset($request['status']) ? sanitize_text_field($request['status']) : '';
        $starred = isset($request['starred']) ? sanitize_text_field($request['starred']) : '';
        $search = isset($request['search']) ? sanitize_text_field($request['search']) : '';
        $date_after = isset($request['date_after']) ? sanitize_text_field($request['date_after']) : '';
        $date_before = isset($request['date_before']) ? sanitize_text_field($request['date_before']) : '';

        // Cap per_page to prevent abuse
        if ($per_page < 1) {
            $per_page = 20;
        }
        if ($per_page > 100) {
            $per_page = 100;
        }

        $result = FormSubmissionHandler::GetSubmissions($form_id, $page, $per_page, $status, $starred, $search, $date_after, $date_before);

        // Include counts for filter tabs
        if (!empty($form_id)) {
            $counts = FormSubmissionHandler::GetCount($form_id);
            $result['count_total'] = $counts['total'];
            $result['count_new'] = $counts['new'];
            $result['count_read'] = $counts['total'] - $counts['new'];
        }

        // Load form config once (kept as array so downstream !empty()/is_array() checks stay safe)
        $attrs = array();
        if (!empty($form_id)) {
            $loaded = FormRegistry::GetConfig($form_id);
            if (is_array($loaded)) {
                $attrs = $loaded;
            }
        }

        // Include field labels from form config
        $field_labels = array();
        if (!empty($attrs['formFields']) && is_array($attrs['formFields'])) {
            foreach ($attrs['formFields'] as $field) {
                if (isset($field['fieldId']) && isset($field['label'])) {
                    $field_labels[$field['fieldId']] = $field['label'];
                }
            }
        }

        $result['field_labels'] = $field_labels;

        // Build sensitive field lookup and decrypt stored values
        $form_fields_config = (!empty($attrs['formFields']) && is_array($attrs['formFields'])) ? $attrs['formFields'] : array();
        $pending_delete = !empty($form_id) && empty($form_fields_config) && FormRegistry::IsPendingDelete($form_id);
        $sensitive_fields = array();
        foreach ($form_fields_config as $field) {
            if (!empty($field['sensitive']) && !empty($field['fieldId'])) {
                $sensitive_fields[] = $field['fieldId'];
            }
        }
        // Pass 1 (pending_delete only): discover sensitive fields across ALL submissions
        // by encryption prefix before we decrypt anything. Without this, a submission whose
        // plaintext predates encryption would leak unmasked while later encrypted rows mask correctly.
        if ($pending_delete) {
            foreach ($result['submissions'] as $sub) {
                if (empty($sub['fields']) || !is_array($sub['fields'])) {
                    continue;
                }
                foreach ($sub['fields'] as $fid => $value) {
                    if (FormEncryption::IsEncrypted($value) && !in_array($fid, $sensitive_fields, true)) {
                        $sensitive_fields[] = $fid;
                    }
                }
            }
        }
        // Pass 2: decrypt and mask using the complete sensitive_fields list
        $can_view_sensitive = FormPermissions::Can('sensitive');
        foreach ($result['submissions'] as &$sub) {
            $sub['fields'] = self::DecryptSubmissionFields($form_fields_config, $sub['fields'], $pending_delete);
            if (!$can_view_sensitive && !empty($sensitive_fields)) {
                foreach ($sensitive_fields as $sfid) {
                    if (isset($sub['fields'][$sfid]) && is_string($sub['fields'][$sfid]) && $sub['fields'][$sfid] !== '') {
                        $sub['fields'][$sfid] = str_repeat("\xE2\x80\xA2", 8);
                    }
                }
            }
        }
        unset($sub);
        $result['sensitive_fields'] = $sensitive_fields;
        $result['can_view_sensitive'] = $can_view_sensitive;

        // Include email notification flags for the panel UI
        $result['email_enabled'] = !empty($attrs['emailEnabled']);
        $result['send_confirmation'] = !empty($attrs['sendConfirmation']);

        // Include spam data
        if (!empty($form_id)) {
            $result['spam_count'] = FormSubmissionHandler::GetSpamCount($form_id);
            $result['spam_submission_count'] = FormSubmissionHandler::GetSpamSubmissionCount($form_id);
            $result['store_spam_enabled'] = !empty($attrs['storeSpamEnabled']);
        }

        // Include integration flags for retry buttons
        $result['mailchimp_enabled'] = !empty($attrs['mailchimpEnabled']);
        $result['brevo_enabled'] = !empty($attrs['brevoEnabled']);

        // Phase 3: Include field preferences for current user
        if (!empty($form_id)) {
            $user_id = get_current_user_id();
            $field_prefs = FormSubmissionHandler::GetFieldPreference($user_id, $form_id);
            $result['field_preferences'] = $field_prefs;
        }

        // Phase 3: Include current user ID for notes permission
        $result['current_user_id'] = get_current_user_id();

        // Phase 4: Include current user's form permissions
        $result['permissions'] = FormPermissions::GetCurrentUserPermissions();

        return rest_ensure_response($result);
    }

    /**
     * Resend an email notification for an existing submission.
     */
    public static function ResendEmailCallback($request)
    {
        $id = intval($request['id']);
        $params = $request->get_json_params();
        $type = isset($params['type']) ? sanitize_text_field($params['type']) : '';

        if (!in_array($type, array('admin', 'user'), true)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid email type.', 'superb-blocks'),
            ), 400);
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Submission not found.', 'superb-blocks'),
            ), 404);
        }

        $form_id = get_post_meta($id, '_spb_form_id', true);
        $form_data = self::GetFormConfig($form_id);
        if ($form_data === null) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Form configuration not found.', 'superb-blocks'),
            ), 404);
        }

        if ($type === 'admin' && empty($form_data['email_enabled'])) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Admin notification is not enabled for this form.', 'superb-blocks'),
            ), 400);
        }
        if ($type === 'user' && empty($form_data['send_confirmation'])) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('User notification is not enabled for this form.', 'superb-blocks'),
            ), 400);
        }

        $fields = get_post_meta($id, '_spb_form_fields', true);
        if (!is_array($fields)) {
            $fields = array();
        }

        $fields = self::DecryptSubmissionFields($form_data['form_fields'], $fields);

        if ($type === 'admin') {
            $result = FormEmailHandler::SendAdminNotification($form_data, $fields, $id);
        } else {
            $result = FormEmailHandler::SendConfirmation($form_data, $fields, $id);
        }

        if ($result) {
            // Return updated email status
            $email_status = get_post_meta($id, '_spb_form_email_status', true);
            return rest_ensure_response(array(
                'success' => true,
                'email_status' => is_array($email_status) ? $email_status : array(),
            ));
        }

        return new \WP_REST_Response(array(
            'success' => false,
            'message' => __('Failed to send email.', 'superb-blocks'),
        ), 500);
    }

    /**
     * Export submissions as CSV.
     */
    public static function ExportCallback($request)
    {
        $form_id = sanitize_key($request['form_id']);
        if (empty($form_id)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid form ID.', 'superb-blocks'),
            ), 400);
        }

        $attrs = FormRegistry::GetConfig($form_id);
        $form_fields = (!empty($attrs) && is_array($attrs) && !empty($attrs['formFields'])) ? $attrs['formFields'] : array();
        $pending_delete = empty($form_fields) && FormRegistry::IsPendingDelete($form_id);

        $include_sensitive = isset($request['include_sensitive']) && $request['include_sensitive'] === '1' && FormPermissions::Can('sensitive');
        $include_notes = isset($request['include_notes']) && $request['include_notes'] === '1' && FormPermissions::Can('notes');
        $status = isset($request['status']) ? sanitize_text_field($request['status']) : '';
        $starred = isset($request['starred']) ? sanitize_text_field($request['starred']) : '';
        $search = isset($request['search']) ? sanitize_text_field($request['search']) : '';
        $date_after = isset($request['date_after']) ? sanitize_text_field($request['date_after']) : '';
        $date_before = isset($request['date_before']) ? sanitize_text_field($request['date_before']) : '';

        // Phase 3: Field filtering for export
        $export_fields = null;
        $export_all = isset($request['export_all_fields']) && $request['export_all_fields'] === '1';
        if (!$export_all) {
            $user_id = get_current_user_id();
            $field_prefs = FormSubmissionHandler::GetFieldPreference($user_id, $form_id);
            if ($field_prefs !== null) {
                $export_fields = $field_prefs;
            }
        }

        FormExporter::Export($form_id, $form_fields, $include_sensitive, $status, $starred, $search, $date_after, $date_before, $include_notes, $export_fields, $pending_delete);
        // Export streams and exits, so this line is never reached.
        exit;
    }

    /**
     * Get submission count for a form.
     */
    public static function GetSubmissionsCountCallback($request)
    {
        $form_id = isset($request['form_id']) ? sanitize_text_field($request['form_id']) : '';
        $count = FormSubmissionHandler::GetCount($form_id);
        $count['form_exists'] = FormRegistry::Get($form_id) !== null;
        return rest_ensure_response($count);
    }

    /**
     * Get all forms (registered + with submissions), with counts and names.
     */
    public static function GetSubmissionsFormsCallback()
    {
        $registry = FormRegistry::GetAll();
        $form_ids_with_submissions = FormSubmissionHandler::GetDistinctFormIds();

        // Merge: all registry forms + any submission-only forms not in registry
        $all_form_ids = array_unique(array_merge(array_keys($registry), $form_ids_with_submissions));

        $forms = array();
        foreach ($all_form_ids as $form_id) {
            $count = FormSubmissionHandler::GetCount($form_id);
            $forms[] = array(
                'form_id' => $form_id,
                'form_name' => FormRegistry::GetName($form_id),
                'total' => $count['total'],
                'new' => $count['new'],
            );
        }

        return rest_ensure_response($forms);
    }

    /**
     * Bulk delete submissions.
     */
    public static function BulkDeleteSubmissionsCallback($request)
    {
        $params = $request->get_json_params();
        $ids = isset($params['ids']) && is_array($params['ids']) ? $params['ids'] : array();

        if (empty($ids)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('No submissions specified.', 'superb-blocks'),
            ), 400);
        }

        // Collect affected form IDs before deleting
        $affected_form_ids = array();
        foreach ($ids as $id) {
            $fid = get_post_meta(intval($id), '_spb_form_id', true);
            if ($fid) {
                $affected_form_ids[sanitize_key($fid)] = true;
            }
        }

        $deleted = FormSubmissionHandler::BulkDelete($ids);

        // Clean up pending_delete forms that may now have zero submissions
        foreach (array_keys($affected_form_ids) as $fid) {
            FormRegistry::CleanupAfterSubmissionDelete($fid);
        }

        return rest_ensure_response(array(
            'success' => true,
            'deleted' => $deleted,
        ));
    }

    /**
     * Bulk update submission status (read/unread).
     */
    public static function BulkUpdateStatusCallback($request)
    {
        $params = $request->get_json_params();
        $ids = isset($params['ids']) && is_array($params['ids']) ? $params['ids'] : array();
        $status = isset($params['status']) ? sanitize_text_field($params['status']) : '';

        if (empty($ids) || !in_array($status, array('read', 'new'), true)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid request.', 'superb-blocks'),
            ), 400);
        }

        $updated = FormSubmissionHandler::BulkUpdateStatus($ids, $status);
        return rest_ensure_response(array(
            'success' => true,
            'updated' => $updated,
        ));
    }

    /**
     * Star a submission.
     */
    public static function StarSubmissionCallback($request)
    {
        $id = intval($request['id']);
        $result = FormSubmissionHandler::Star($id);

        if ($result) {
            return rest_ensure_response(array('success' => true));
        }

        return new \WP_REST_Response(array(
            'success' => false,
            'message' => __('Submission not found.', 'superb-blocks'),
        ), 404);
    }

    /**
     * Unstar a submission.
     */
    public static function UnstarSubmissionCallback($request)
    {
        $id = intval($request['id']);
        $result = FormSubmissionHandler::Unstar($id);

        if ($result) {
            return rest_ensure_response(array('success' => true));
        }

        return new \WP_REST_Response(array(
            'success' => false,
            'message' => __('Submission not found.', 'superb-blocks'),
        ), 404);
    }

    /**
     * Bulk star/unstar submissions.
     */
    public static function BulkStarCallback($request)
    {
        $params = $request->get_json_params();
        $ids = isset($params['ids']) && is_array($params['ids']) ? $params['ids'] : array();
        $star = isset($params['star']) ? (bool) $params['star'] : true;

        if (empty($ids)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('No submissions specified.', 'superb-blocks'),
            ), 400);
        }

        $updated = FormSubmissionHandler::BulkStar($ids, $star);
        return rest_ensure_response(array(
            'success' => true,
            'updated' => $updated,
        ));
    }

    /**
     * Mark a submission as read.
     */
    public static function MarkSubmissionReadCallback($request)
    {
        $id = intval($request['id']);
        $result = FormSubmissionHandler::MarkAsRead($id);

        if ($result) {
            return rest_ensure_response(array('success' => true));
        }

        return new \WP_REST_Response(array(
            'success' => false,
            'message' => __('Submission not found.', 'superb-blocks'),
        ), 404);
    }

    /**
     * Mark a submission as unread.
     */
    public static function MarkSubmissionUnreadCallback($request)
    {
        $id = intval($request['id']);
        $result = FormSubmissionHandler::MarkAsUnread($id);

        if ($result) {
            return rest_ensure_response(array('success' => true));
        }

        return new \WP_REST_Response(array(
            'success' => false,
            'message' => __('Submission not found.', 'superb-blocks'),
        ), 404);
    }

    /**
     * Delete a submission.
     */
    public static function DeleteSubmissionCallback($request)
    {
        $id = intval($request['id']);
        $form_id = get_post_meta($id, '_spb_form_id', true);
        $deleted = FormSubmissionHandler::Delete($id);

        if ($deleted) {
            if ($form_id) {
                FormRegistry::CleanupAfterSubmissionDelete(sanitize_key($form_id));
            }
            return rest_ensure_response(array('success' => true));
        }

        return new \WP_REST_Response(array(
            'success' => false,
            'message' => __('Submission not found.', 'superb-blocks'),
        ), 404);
    }

    /**
     * Delete all data for a form (submissions, registry entry, config).
     */
    public static function DeleteFormCallback($request)
    {
        $form_id = sanitize_key($request['form_id']);

        if (empty($form_id)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid form ID.', 'superb-blocks'),
            ), 400);
        }

        // FORM_DELETE_ROUTE is '/form/(?P<form_id>[a-zA-Z0-9_-]+)' and overlaps with
        // sibling endpoints like '/form/submissions', '/form/fields', etc. Reject
        // reserved path segments so a DELETE to those never silently runs here.
        $reserved = array('submissions', 'fields', 'integrations', 'captcha', 'webhook');
        if (in_array($form_id, $reserved, true)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid form ID.', 'superb-blocks'),
            ), 400);
        }

        // Optionally remove the form block from its source post
        $params = $request->get_json_params();
        $block_removed = false;
        if (!empty($params['remove_block'])) {
            $block_removed = FormRegistry::RemoveFormBlock($form_id);
        }

        $deleted = FormSubmissionHandler::DeleteAllByFormId($form_id);
        FormRegistry::Remove($form_id);
        delete_option(FormRegistry::CONFIG_PREFIX . $form_id);

        return rest_ensure_response(array(
            'success' => true,
            'deleted_submissions' => $deleted,
            'block_removed' => $block_removed,
        ));
    }

    /**
     * Fetch Mailchimp lists/audiences.
     */
    public static function GetMailchimpListsCallback()
    {
        $result = FormIntegrationHandler::GetMailchimpLists();
        if (is_wp_error($result)) {
            $status = 400;
            $error_data = $result->get_error_data();
            if (isset($error_data['status'])) {
                $status = intval($error_data['status']);
            }
            return new \WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), $status);
        }
        return rest_ensure_response(array('lists' => $result));
    }

    /**
     * Fetch Brevo lists.
     */
    public static function GetBrevoListsCallback()
    {
        $result = FormIntegrationHandler::GetBrevoLists();
        if (is_wp_error($result)) {
            $status = 400;
            $error_data = $result->get_error_data();
            if (isset($error_data['status'])) {
                $status = intval($error_data['status']);
            }
            return new \WP_REST_Response(array(
                'success' => false,
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), $status);
        }
        return rest_ensure_response(array('lists' => $result));
    }

    /**
     * Check whether captcha API keys are configured.
     */
    public static function GetCaptchaStatusCallback($request)
    {
        $type = isset($request['type']) ? sanitize_text_field($request['type']) : '';

        $key_map = array(
            'hcaptcha'     => array(FormSettings::OPTION_HCAPTCHA_SITE_KEY, FormSettings::OPTION_HCAPTCHA_SECRET_KEY),
            'recaptcha_v2' => array(FormSettings::OPTION_RECAPTCHA_SITE_KEY, FormSettings::OPTION_RECAPTCHA_SECRET_KEY),
            'recaptcha_v3' => array(FormSettings::OPTION_RECAPTCHA_SITE_KEY, FormSettings::OPTION_RECAPTCHA_SECRET_KEY),
            'turnstile'    => array(FormSettings::OPTION_TURNSTILE_SITE_KEY, FormSettings::OPTION_TURNSTILE_SECRET_KEY),
        );

        if (!isset($key_map[$type])) {
            return new \WP_REST_Response(array(
                'success' => false,
                'code'    => 'invalid_type',
                'message' => __('Invalid captcha type.', 'superb-blocks'),
            ), 400);
        }

        $keys = $key_map[$type];
        $site_key = FormSettings::Get($keys[0]);
        $secret_key = FormSettings::Get($keys[1]);

        if (empty($site_key) || empty($secret_key)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'code'    => 'no_api_key',
                'message' => __('API keys are not configured for this method.', 'superb-blocks'),
            ), 400);
        }

        return rest_ensure_response(array('success' => true));
    }

    /**
     * Mark a spam submission as "Not Spam" (rescue to regular submissions).
     */
    public static function NotSpamCallback($request)
    {
        $id = intval($request['id']);
        $result = FormSubmissionHandler::MarkNotSpam($id);

        if ($result) {
            return rest_ensure_response(array('success' => true));
        }

        return new \WP_REST_Response(array(
            'success' => false,
            'message' => __('Submission not found or is not spam.', 'superb-blocks'),
        ), 404);
    }

    /**
     * Get the spam counter for a form.
     */
    public static function GetSpamCountCallback($request)
    {
        $form_id = sanitize_key($request['form_id']);
        return rest_ensure_response(array(
            'spam_count' => FormSubmissionHandler::GetSpamCount($form_id),
            'spam_submission_count' => FormSubmissionHandler::GetSpamSubmissionCount($form_id),
        ));
    }

    /**
     * Retry an integration (Mailchimp or Brevo) for an existing submission.
     */
    public static function RetryIntegrationCallback($request)
    {
        $id = intval($request['id']);
        $params = $request->get_json_params();
        $integration = isset($params['integration']) ? sanitize_text_field($params['integration']) : '';

        if (!in_array($integration, array('mailchimp', 'brevo'), true)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid integration.', 'superb-blocks'),
            ), 400);
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Submission not found.', 'superb-blocks'),
            ), 404);
        }

        $form_id = get_post_meta($id, '_spb_form_id', true);
        $form_data = self::GetFormConfig($form_id);
        if ($form_data === null) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Form configuration not found.', 'superb-blocks'),
            ), 404);
        }

        $fields = get_post_meta($id, '_spb_form_fields', true);
        if (!is_array($fields)) {
            $fields = array();
        }
        $fields = self::DecryptSubmissionFields($form_data['form_fields'], $fields);

        $email = self::FindSubmissionEmail($fields, $form_data['form_fields']);

        if (empty($email)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('No email address found in submission fields.', 'superb-blocks'),
            ), 400);
        }

        $result = false;
        $error_message = '';

        if ($integration === 'mailchimp') {
            if (empty($form_data['mailchimp_enabled']) || empty($form_data['mailchimp_list_ids'])) {
                return new \WP_REST_Response(array(
                    'success' => false,
                    'message' => __('Mailchimp is not enabled for this form.', 'superb-blocks'),
                ), 400);
            }
            $result = FormIntegrationHandler::SendToMailchimp($form_data['mailchimp_list_ids'], $email, $fields);
        } elseif ($integration === 'brevo') {
            if (empty($form_data['brevo_enabled']) || empty($form_data['brevo_list_ids'])) {
                return new \WP_REST_Response(array(
                    'success' => false,
                    'message' => __('Brevo is not enabled for this form.', 'superb-blocks'),
                ), 400);
            }
            $result = FormIntegrationHandler::SendToBrevo($form_data['brevo_list_ids'], $email, $fields);
        }

        // Store integration status meta
        $status_meta = get_post_meta($id, '_spb_form_integration_status', true);
        if (!is_array($status_meta)) {
            $status_meta = array();
        }
        $status_meta[$integration] = array(
            'sent' => (bool) $result,
            'time' => time(),
            'error' => $result ? null : __('Integration request failed.', 'superb-blocks'),
        );
        update_post_meta($id, '_spb_form_integration_status', $status_meta);

        if ($result) {
            return rest_ensure_response(array('success' => true));
        }

        return new \WP_REST_Response(array(
            'success' => false,
            'message' => __('Failed to send to integration. Please try again.', 'superb-blocks'),
        ), 500);
    }

    // ========================================
    // Phase 3: Notes
    // ========================================

    /**
     * Get notes for a submission.
     */
    public static function GetNotesCallback($request)
    {
        $id = intval($request['id']);
        $notes = FormSubmissionHandler::GetNotes($id);
        return rest_ensure_response(array(
            'notes' => $notes,
            'note_count' => count($notes),
        ));
    }

    /**
     * Add a note to a submission.
     */
    public static function AddNoteCallback($request)
    {
        $id = intval($request['id']);
        $params = $request->get_json_params();
        $text = isset($params['text']) ? $params['text'] : '';

        if (empty($text)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Note text is required.', 'superb-blocks'),
            ), 400);
        }

        if (mb_strlen($text) > 1000) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Note must be 1000 characters or fewer.', 'superb-blocks'),
            ), 400);
        }

        $current_user = wp_get_current_user();
        $note = FormSubmissionHandler::AddNote(
            $id,
            $current_user->ID,
            $current_user->display_name,
            $text
        );

        if ($note === false) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Failed to add note.', 'superb-blocks'),
            ), 400);
        }

        return rest_ensure_response(array(
            'success' => true,
            'note' => $note,
            'notes' => FormSubmissionHandler::GetNotes($id),
            'note_count' => FormSubmissionHandler::GetNoteCount($id),
        ));
    }

    /**
     * Delete a note from a submission.
     */
    public static function DeleteNoteCallback($request)
    {
        $id = intval($request['id']);
        $index = intval($request['index']);
        $current_user = wp_get_current_user();

        $result = FormSubmissionHandler::DeleteNote($id, $index, $current_user->ID);

        if (!$result) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Failed to delete note.', 'superb-blocks'),
            ), 400);
        }

        return rest_ensure_response(array(
            'success' => true,
            'notes' => FormSubmissionHandler::GetNotes($id),
            'note_count' => FormSubmissionHandler::GetNoteCount($id),
        ));
    }

    // ========================================
    // Phase 3: Field Preferences
    // ========================================

    /**
     * Save field preferences for the current user.
     */
    public static function SaveFieldsCallback($request)
    {
        $params = $request->get_json_params();
        $form_id = isset($params['form_id']) ? sanitize_key($params['form_id']) : '';
        $fields = isset($params['fields']) && is_array($params['fields']) ? $params['fields'] : array();

        if (empty($form_id)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Invalid form ID.', 'superb-blocks'),
            ), 400);
        }

        if (empty($fields)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('At least one field is required.', 'superb-blocks'),
            ), 400);
        }

        $user_id = get_current_user_id();
        $result = FormSubmissionHandler::SaveFieldPreference($user_id, $form_id, $fields);

        return rest_ensure_response(array(
            'success' => $result,
        ));
    }

    /**
     * Get field preferences for the current user.
     */
    public static function GetFieldsCallback($request)
    {
        $form_id = sanitize_key($request['form_id']);
        $user_id = get_current_user_id();
        $fields = FormSubmissionHandler::GetFieldPreference($user_id, $form_id);

        return rest_ensure_response(array(
            'fields' => $fields,
        ));
    }

    /**
     * Read form configuration from server-side storage.
     * The config is stored as an option during save_post (FormRegistry) and block render (EnqueueForm).
     * This ensures all config comes from the database, not from client-supplied data.
     */
    private static function GetFormConfig($form_id)
    {
        $attrs = FormRegistry::GetConfig($form_id);
        if (empty($attrs) || !is_array($attrs)) {
            return null;
        }

        return array(
            'form_id' => $form_id,
            'form_name' => isset($attrs['formName']) ? sanitize_text_field($attrs['formName']) : '',
            'captcha_type' => isset($attrs['captchaType']) ? sanitize_text_field($attrs['captchaType']) : 'honeypot',
            'honeypot_key' => isset($attrs['honeypotKey']) ? sanitize_text_field($attrs['honeypotKey']) : '',
            'email_enabled' => !empty($attrs['emailEnabled']),
            'store_enabled' => isset($attrs['storeEnabled']) ? (bool) $attrs['storeEnabled'] : false,
            'email_to' => isset($attrs['emailTo']) ? sanitize_text_field($attrs['emailTo']) : '',
            'email_subject' => isset($attrs['emailSubject']) ? sanitize_text_field($attrs['emailSubject']) : '',
            'email_reply_to' => isset($attrs['emailReplyTo']) ? sanitize_text_field($attrs['emailReplyTo']) : '',
            'email_cc' => isset($attrs['emailCC']) ? sanitize_text_field($attrs['emailCC']) : '',
            'email_bcc' => isset($attrs['emailBCC']) ? sanitize_text_field($attrs['emailBCC']) : '',
            'send_confirmation' => isset($attrs['sendConfirmation']) ? (bool) $attrs['sendConfirmation'] : false,
            'confirmation_subject' => isset($attrs['confirmationSubject']) ? sanitize_text_field($attrs['confirmationSubject']) : '',
            'confirmation_message' => isset($attrs['confirmationMessage']) ? sanitize_textarea_field($attrs['confirmationMessage']) : '',
            'confirmation_email_field' => isset($attrs['confirmationEmailField']) ? sanitize_text_field($attrs['confirmationEmailField']) : '',
            'success_behavior' => isset($attrs['successBehavior']) ? sanitize_text_field($attrs['successBehavior']) : 'message',
            'redirect_url' => isset($attrs['redirectUrl']) ? esc_url_raw($attrs['redirectUrl']) : '',
            'mailchimp_enabled' => isset($attrs['mailchimpEnabled']) ? (bool) $attrs['mailchimpEnabled'] : false,
            'mailchimp_list_ids' => isset($attrs['mailchimpListIds']) && is_array($attrs['mailchimpListIds'])
                ? array_map('sanitize_text_field', $attrs['mailchimpListIds'])
                : array(),
            'brevo_enabled' => isset($attrs['brevoEnabled']) ? (bool) $attrs['brevoEnabled'] : false,
            'brevo_list_ids' => isset($attrs['brevoListIds']) && is_array($attrs['brevoListIds'])
                ? array_map('intval', $attrs['brevoListIds'])
                : array(),
            'form_fields' => isset($attrs['formFields']) && is_array($attrs['formFields']) ? $attrs['formFields'] : array(),
            'store_spam_enabled' => isset($attrs['storeSpamEnabled']) ? (bool) $attrs['storeSpamEnabled'] : false,
            // Webhook
            'webhook_enabled' => isset($attrs['webhookEnabled']) ? (bool) $attrs['webhookEnabled'] : false,
            'webhook_url' => isset($attrs['webhookUrl']) ? esc_url_raw($attrs['webhookUrl']) : '',
            'webhook_method' => isset($attrs['webhookMethod']) ? sanitize_text_field($attrs['webhookMethod']) : 'POST',
            'webhook_secret' => FormSettings::GetWebhookSecret($form_id),
            'webhook_headers' => isset($attrs['webhookHeaders']) && is_array($attrs['webhookHeaders']) ? $attrs['webhookHeaders'] : array(),
            // Google Sheets
            'google_sheets_enabled' => isset($attrs['googleSheetsEnabled']) ? (bool) $attrs['googleSheetsEnabled'] : false,
            'google_sheets_spreadsheet_url' => isset($attrs['googleSheetsSpreadsheetUrl']) ? sanitize_text_field($attrs['googleSheetsSpreadsheetUrl']) : '',
            'google_sheets_sheet_name' => isset($attrs['googleSheetsSheetName']) ? sanitize_text_field($attrs['googleSheetsSheetName']) : '',
            // Slack
            'slack_enabled' => isset($attrs['slackEnabled']) ? (bool) $attrs['slackEnabled'] : false,
            'slack_webhook_url' => isset($attrs['slackWebhookUrl']) ? esc_url_raw($attrs['slackWebhookUrl']) : '',
        );
    }

    /**
     * Find the submission's primary email for integration delivery.
     * Prefers fields explicitly typed as 'email' in the form config; falls back to
     * the first string that passes is_email() so legacy forms without typing still work.
     */
    private static function FindSubmissionEmail($fields, $form_fields)
    {
        if (is_array($form_fields)) {
            foreach ($form_fields as $fc) {
                if (!isset($fc['fieldType'], $fc['fieldId'])) {
                    continue;
                }
                if ($fc['fieldType'] !== 'email') {
                    continue;
                }
                $fid = $fc['fieldId'];
                if (isset($fields[$fid]) && is_string($fields[$fid]) && is_email($fields[$fid])) {
                    return $fields[$fid];
                }
            }
        }
        foreach ($fields as $value) {
            if (is_string($value) && is_email($value)) {
                return $value;
            }
        }
        return '';
    }

    /**
     * Decrypt sensitive fields in a submission's field data.
     *
     * @param array $form_fields Array of field definitions (with fieldId/sensitive flags).
     * @param array $fields Submission field data (field_id => value).
     * @param bool  $pending_delete Whether the form is pending deletion (config unavailable).
     * @return array The fields array with sensitive values decrypted.
     */
    private static function DecryptSubmissionFields($form_fields, $fields, $pending_delete = false)
    {
        if (empty($form_fields) && $pending_delete) {
            // Config is gone — detect sensitive fields by encryption prefix
            foreach ($fields as $fid => $value) {
                if (FormEncryption::IsEncrypted($value)) {
                    $decrypted = FormEncryption::Decrypt($value);
                    if ($decrypted !== false) {
                        $fields[$fid] = $decrypted;
                    }
                }
            }
            return $fields;
        }

        foreach ($form_fields as $field_def) {
            if (!empty($field_def['sensitive']) && !empty($field_def['fieldId'])) {
                $sfid = $field_def['fieldId'];
                if (isset($fields[$sfid]) && is_string($fields[$sfid])) {
                    $decrypted = FormEncryption::Decrypt($fields[$sfid]);
                    if ($decrypted !== false) {
                        $fields[$sfid] = $decrypted;
                    }
                }
            }
        }
        return $fields;
    }

    /**
     * Serve a file from a submission (admin-only).
     */
    public static function ServeFileCallback($request)
    {
        $post_id = intval($request['id']);
        $field_id = sanitize_text_field($request['field_id']);
        $index = intval($request['index']);

        $post = get_post($post_id);
        if (!$post || $post->post_type !== FormSubmissionCPT::POST_TYPE) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Submission not found.', 'superb-blocks'),
            ), 404);
        }

        $fields = get_post_meta($post_id, '_spb_form_fields', true);
        if (!is_array($fields) || !isset($fields[$field_id]) || !is_array($fields[$field_id])) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('File not found.', 'superb-blocks'),
            ), 404);
        }

        $file_list = $fields[$field_id];
        if (!isset($file_list[$index]) || !is_array($file_list[$index])) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('File not found.', 'superb-blocks'),
            ), 404);
        }

        $file_meta = $file_list[$index];
        $file_path = isset($file_meta['path']) ? $file_meta['path'] : '';
        $original_name = isset($file_meta['name']) ? $file_meta['name'] : 'download';
        $mime_type = isset($file_meta['type']) && $file_meta['type'] !== '' ? $file_meta['type'] : 'application/octet-stream';

        return FormFileHandler::ServeFile($file_path, $original_name, $mime_type);
    }

    /**
     * Process email list integrations.
     *
     * @param array $form_data
     * @param array $fields
     * @param int $post_id Submission post ID for status tracking
     */
    private static function ProcessIntegrations($form_data, $fields, $post_id = 0)
    {
        $integration_status = array();
        $form_fields_config = isset($form_data['form_fields']) ? $form_data['form_fields'] : array();

        // Webhook (no email required)
        if (!empty($form_data['webhook_enabled']) && !empty($form_data['webhook_url'])) {
            $result = FormIntegrationHandler::SendWebhook(
                $form_data['webhook_url'],
                $form_data['webhook_method'],
                $form_data['form_id'],
                $form_data['form_name'],
                $fields,
                $form_fields_config,
                isset($form_data['webhook_secret']) ? $form_data['webhook_secret'] : '',
                isset($form_data['webhook_headers']) ? $form_data['webhook_headers'] : array()
            );
            $integration_status['webhook'] = array(
                'sent' => !empty($result['sent']),
                'time' => time(),
                'code' => isset($result['code']) ? $result['code'] : 0,
                'error' => isset($result['error']) ? $result['error'] : null,
            );
        }

        // Google Sheets (no email required)
        if (!empty($form_data['google_sheets_enabled']) && !empty($form_data['google_sheets_spreadsheet_url'])) {
            $result = FormIntegrationHandler::SendToGoogleSheets(
                $form_data['google_sheets_spreadsheet_url'],
                isset($form_data['google_sheets_sheet_name']) ? $form_data['google_sheets_sheet_name'] : '',
                $fields,
                $form_fields_config
            );
            $integration_status['google_sheets'] = array(
                'sent' => !empty($result['sent']),
                'time' => time(),
                'error' => isset($result['error']) ? $result['error'] : null,
            );
        }

        // Slack (no email required)
        if (!empty($form_data['slack_enabled']) && !empty($form_data['slack_webhook_url'])) {
            $result = FormIntegrationHandler::SendToSlack(
                $form_data['slack_webhook_url'],
                $form_data['form_name'],
                $fields,
                $form_fields_config
            );
            $integration_status['slack'] = array(
                'sent' => !empty($result['sent']),
                'time' => time(),
                'error' => isset($result['error']) ? $result['error'] : null,
            );
        }

        // Find email from submitted fields (required for Mailchimp/Brevo)
        $email = self::FindSubmissionEmail($fields, $form_fields_config);

        if (!empty($email)) {
            // Mailchimp
            if (!empty($form_data['mailchimp_enabled']) && !empty($form_data['mailchimp_list_ids'])) {
                $result = FormIntegrationHandler::SendToMailchimp(
                    $form_data['mailchimp_list_ids'],
                    $email,
                    $fields
                );
                $integration_status['mailchimp'] = array(
                    'sent' => (bool) $result,
                    'time' => time(),
                    'error' => $result ? null : __('Mailchimp request failed.', 'superb-blocks'),
                );
            }

            // Brevo
            if (!empty($form_data['brevo_enabled']) && !empty($form_data['brevo_list_ids'])) {
                $result = FormIntegrationHandler::SendToBrevo(
                    $form_data['brevo_list_ids'],
                    $email,
                    $fields
                );
                $integration_status['brevo'] = array(
                    'sent' => (bool) $result,
                    'time' => time(),
                    'error' => $result ? null : __('Brevo request failed.', 'superb-blocks'),
                );
            }
        }

        // Store integration status on submission
        if ($post_id > 0 && !empty($integration_status)) {
            update_post_meta($post_id, '_spb_form_integration_status', $integration_status);
        }
    }

    /**
     * Webhook test endpoint callback.
     */
    public static function WebhookTestCallback($request)
    {
        $url = isset($request['url']) ? esc_url_raw($request['url']) : '';
        $method = isset($request['method']) ? sanitize_text_field($request['method']) : 'POST';
        $secret = isset($request['secret']) ? sanitize_text_field($request['secret']) : '';
        $headers = isset($request['headers']) && is_array($request['headers']) ? $request['headers'] : array();

        if (empty($url)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'status_code' => 0,
                'error' => __('URL is required.', 'superb-blocks'),
            ), 400);
        }

        $validated_url = wp_http_validate_url($url);
        if (!$validated_url) {
            return new \WP_REST_Response(array(
                'success' => false,
                'status_code' => 0,
                'error' => __('Invalid or blocked URL.', 'superb-blocks'),
            ), 400);
        }
        $url = $validated_url;

        $test_fields = array(
            'test_field' => array(
                'label' => 'Name',
                'value' => 'Test Submission',
                'type' => 'text',
            ),
            'test_email' => array(
                'label' => 'Email',
                'value' => 'test@example.com',
                'type' => 'email',
            ),
        );

        $payload = array(
            'form_id' => 'test',
            'form_name' => 'Test Form',
            'submitted_at' => gmdate('c'),
            'test' => true,
            'fields' => $test_fields,
        );

        $json = wp_json_encode($payload);
        if ($json === false) {
            return new \WP_REST_Response(array(
                'success' => false,
                'status_code' => 0,
                'error' => __('Failed to encode test payload.', 'superb-blocks'),
            ), 500);
        }

        $request_headers = array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'SuperbAddons/' . SUPERBADDONS_VERSION,
        );

        if (!empty($secret)) {
            $request_headers['X-Superb-Signature'] = 'sha256=' . hash_hmac('sha256', $json, $secret);
        }

        if (is_array($headers)) {
            foreach ($headers as $h) {
                if (!empty($h['key'])) {
                    $request_headers[sanitize_text_field($h['key'])] = sanitize_text_field(isset($h['value']) ? $h['value'] : '');
                }
            }
        }

        $allowed_methods = array('POST', 'PUT', 'PATCH');
        if (!in_array(strtoupper($method), $allowed_methods, true)) {
            $method = 'POST';
        }

        $response = wp_remote_request($url, array(
            'method' => strtoupper($method),
            'headers' => $request_headers,
            'body' => $json,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return rest_ensure_response(array(
                'success' => false,
                'status_code' => 0,
                'error' => $response->get_error_message(),
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        return rest_ensure_response(array(
            'success' => $code >= 200 && $code < 300,
            'status_code' => $code,
            'error' => ($code >= 200 && $code < 300) ? null : sprintf('HTTP %d', $code),
        ));
    }

    /**
     * Google Sheets status endpoint callback.
     */
    public static function GoogleSheetsStatusCallback()
    {
        $client_email = FormSettings::Get(FormSettings::OPTION_GOOGLE_SHEETS_CLIENT_EMAIL);
        $configured = !empty($client_email) && FormSettings::HasValue(FormSettings::OPTION_GOOGLE_SHEETS_PRIVATE_KEY);

        return rest_ensure_response(array(
            'configured' => $configured,
            'client_email' => $configured ? $client_email : '',
        ));
    }

    /**
     * Google Sheets test connection endpoint callback.
     */
    public static function GoogleSheetsTestCallback($request)
    {
        $spreadsheet_url = isset($request['spreadsheet_url']) ? sanitize_text_field($request['spreadsheet_url']) : '';
        $sheet_name = isset($request['sheet_name']) ? sanitize_text_field($request['sheet_name']) : '';

        if (empty($spreadsheet_url)) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => __('Spreadsheet URL is required.', 'superb-blocks'),
            ));
        }

        // Extract spreadsheet ID from URL
        $spreadsheet_id = $spreadsheet_url;
        if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9_-]+)/', $spreadsheet_url, $matches)) {
            $spreadsheet_id = $matches[1];
        }

        $token = FormGoogleAuth::GetAccessToken();
        if (is_wp_error($token)) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => $token->get_error_message(),
            ));
        }

        $range = !empty($sheet_name) ? $sheet_name : 'Sheet1';
        $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . urlencode($spreadsheet_id) . '/values/' . urlencode($range . '!A1');

        $response = wp_remote_get($url, array(
            'headers' => array('Authorization' => 'Bearer ' . $token),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return rest_ensure_response(array(
                'success' => false,
                'error' => $response->get_error_message(),
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return rest_ensure_response(array('success' => true, 'error' => null));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $msg = isset($body['error']['message']) ? $body['error']['message'] : sprintf('HTTP %d', $code);
        return rest_ensure_response(array('success' => false, 'error' => $msg));
    }

    /**
     * Webhook secret endpoint: GET (status), POST (save), DELETE (remove).
     */
    public static function WebhookSecretCallback($request)
    {
        $form_id = isset($request['form_id']) ? sanitize_key($request['form_id']) : '';
        if (empty($form_id)) {
            return new \WP_REST_Response(array('success' => false, 'error' => 'Missing form_id.'), 400);
        }

        $method = $request->get_method();

        if ($method === 'GET') {
            return rest_ensure_response(array(
                'has_secret' => FormSettings::HasWebhookSecret($form_id),
            ));
        }

        if ($method === 'DELETE') {
            FormSettings::RemoveWebhookSecret($form_id);
            return rest_ensure_response(array('success' => true));
        }

        // POST: save secret
        $secret = isset($request['secret']) ? sanitize_text_field($request['secret']) : '';
        if (empty($secret)) {
            return new \WP_REST_Response(array('success' => false, 'error' => __('Secret cannot be empty.', 'superb-blocks')), 400);
        }

        FormSettings::SetWebhookSecret($form_id, $secret);
        return rest_ensure_response(array('success' => true));
    }

    /**
     * Slack test endpoint callback.
     */
    public static function SlackTestCallback($request)
    {
        $webhook_url = isset($request['url']) ? esc_url_raw($request['url']) : '';

        if (empty($webhook_url)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'error' => __('Webhook URL is required.', 'superb-blocks'),
            ), 400);
        }

        $validated_url = wp_http_validate_url($webhook_url);
        if (!$validated_url) {
            return new \WP_REST_Response(array(
                'success' => false,
                'error' => __('Invalid or blocked URL.', 'superb-blocks'),
            ), 400);
        }

        $test_fields = array(
            'test_name' => 'Jane Smith',
            'test_email' => 'test@example.com',
        );
        $test_config = array(
            array('fieldId' => 'test_name', 'label' => 'Name', 'fieldType' => 'text'),
            array('fieldId' => 'test_email', 'label' => 'Email', 'fieldType' => 'email'),
        );

        $result = FormIntegrationHandler::SendToSlack(
            $validated_url,
            __('Test Form', 'superb-blocks'),
            $test_fields,
            $test_config
        );

        return rest_ensure_response(array(
            'success' => !empty($result['sent']),
            'error' => isset($result['error']) ? $result['error'] : null,
        ));
    }
}
