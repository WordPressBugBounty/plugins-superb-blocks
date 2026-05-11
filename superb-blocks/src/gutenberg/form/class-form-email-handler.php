<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormEmailHandler
{
    /**
     * Track the last wp_mail_failed error for status capture.
     */
    private static $last_mail_error = null;

    /**
     * Send admin notification email.
     *
     * @param array $form_data Form configuration
     * @param array $fields Submitted field data (field_id => value)
     * @param int $post_id Optional submission post ID for status tracking
     * @return bool
     */
    public static function SendAdminNotification($form_data, $fields, $post_id = 0)
    {
        $to_raw = !empty($form_data['email_to']) ? $form_data['email_to'] : get_option('admin_email');
        $to = array_filter(array_map(function ($email) {
            return sanitize_email(trim($email));
        }, explode(',', $to_raw)), 'is_email');
        if (empty($to)) {
            return false;
        }
        $subject = self::ProcessMergeTags(
            !empty($form_data['email_subject']) ? $form_data['email_subject'] : __('New form submission', 'superb-blocks'),
            $form_data,
            $fields
        );

        $body = self::BuildEmailBody($form_data, $fields);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        // From name/email: form-level -> global default -> WordPress default
        $from_name = self::ResolveFromName($form_data);
        $from_email = self::ResolveFromEmail($form_data);
        if (!empty($from_email) && is_email($from_email)) {
            $from_header = !empty($from_name) ? ($from_name . ' <' . $from_email . '>') : $from_email;
            $headers[] = 'From: ' . $from_header;
        }

        $form_fields = isset($form_data['form_fields']) ? $form_data['form_fields'] : array();

        if (!empty($form_data['email_reply_to'])) {
            $reply_to_value = self::FindFieldValue($fields, $form_data['email_reply_to'], $form_fields);
            if (!empty($reply_to_value) && is_email($reply_to_value)) {
                $headers[] = 'Reply-To: ' . sanitize_email($reply_to_value);
            }
        }

        if (!empty($form_data['email_cc'])) {
            $cc_emails = array_map('trim', explode(',', $form_data['email_cc']));
            foreach ($cc_emails as $cc) {
                if (is_email($cc)) {
                    $headers[] = 'Cc: ' . sanitize_email($cc);
                }
            }
        }

        if (!empty($form_data['email_bcc'])) {
            $bcc_emails = array_map('trim', explode(',', $form_data['email_bcc']));
            foreach ($bcc_emails as $bcc) {
                if (is_email($bcc)) {
                    $headers[] = 'Bcc: ' . sanitize_email($bcc);
                }
            }
        }

        self::$last_mail_error = null;
        add_action('wp_mail_failed', array(__CLASS__, 'CaptureMailError'));
        $result = wp_mail($to, $subject, $body, $headers);
        remove_action('wp_mail_failed', array(__CLASS__, 'CaptureMailError'));

        if ($post_id > 0) {
            self::UpdateEmailStatus($post_id, 'admin', $result, self::$last_mail_error);
        }

        return $result;
    }

    /**
     * Send confirmation email to the form submitter.
     *
     * @param array $form_data Form configuration
     * @param array $fields Submitted field data
     * @param int $post_id Optional submission post ID for status tracking
     * @return bool
     */
    public static function SendConfirmation($form_data, $fields, $post_id = 0)
    {
        $form_fields = isset($form_data['form_fields']) ? $form_data['form_fields'] : array();

        // Find the email field value — use configured field if set, otherwise auto-detect
        $user_email = '';
        if (!empty($form_data['confirmation_email_field'])) {
            $found = self::FindFieldValue($fields, $form_data['confirmation_email_field'], $form_fields);
            if (!empty($found) && is_email($found)) {
                $user_email = $found;
            }
        }
        // Fallback: auto-detect first email value
        if (empty($user_email)) {
            foreach ($fields as $field_id => $value) {
                if (is_email($value)) {
                    $user_email = $value;
                    break;
                }
            }
        }

        if (empty($user_email)) {
            return false;
        }

        $subject = self::ProcessMergeTags(
            !empty($form_data['confirmation_subject']) ? $form_data['confirmation_subject'] : __('We received your submission', 'superb-blocks'),
            $form_data,
            $fields
        );

        $message = self::ProcessMergeTags(
            !empty($form_data['confirmation_message']) ? $form_data['confirmation_message'] : __('Thank you for your submission.', 'superb-blocks'),
            $form_data,
            $fields,
            true // Escape for HTML email body
        );

        $body = self::WrapInTemplate($message);
        $headers = array('Content-Type: text/html; charset=UTF-8');

        // From name/email: form-level -> global default -> WordPress default
        $from_name = self::ResolveFromName($form_data);
        $from_email = self::ResolveFromEmail($form_data);
        if (!empty($from_email) && is_email($from_email)) {
            $from_header = !empty($from_name) ? ($from_name . ' <' . $from_email . '>') : $from_email;
            $headers[] = 'From: ' . $from_header;
        }

        self::$last_mail_error = null;
        add_action('wp_mail_failed', array(__CLASS__, 'CaptureMailError'));
        $result = wp_mail(sanitize_email($user_email), $subject, $body, $headers);
        remove_action('wp_mail_failed', array(__CLASS__, 'CaptureMailError'));

        if ($post_id > 0) {
            self::UpdateEmailStatus($post_id, 'user', $result, self::$last_mail_error);
        }

        return $result;
    }

    /**
     * Capture wp_mail_failed error for status tracking.
     *
     * @param \WP_Error $error
     */
    public static function CaptureMailError($error)
    {
        if (is_wp_error($error)) {
            self::$last_mail_error = $error->get_error_message();
        }
    }

    /**
     * Update email delivery status meta on a submission.
     *
     * @param int $post_id
     * @param string $type 'admin' or 'user'
     * @param bool $sent
     * @param string|null $error
     */
    public static function UpdateEmailStatus($post_id, $type, $sent, $error = null)
    {
        $status = get_post_meta($post_id, '_spb_form_email_status', true);
        if (!is_array($status)) {
            $status = array();
        }
        $status[$type] = array(
            'sent' => (bool) $sent,
            'time' => time(),
            'error' => $error,
        );
        update_post_meta($post_id, '_spb_form_email_status', $status);
    }

    private static function BuildEmailBody($form_data, $fields)
    {
        $form_name = !empty($form_data['form_name']) ? esc_html($form_data['form_name']) : __('Form Submission', 'superb-blocks');

        // Build fieldId => deduplicated label lookup
        $form_fields = isset($form_data['form_fields']) ? $form_data['form_fields'] : array();
        $label_map = array();
        foreach (self::BuildFieldTagMap($form_fields) as $entry) {
            $label_map[$entry['fieldId']] = $entry['label'];
        }

        // Build sensitive field lookup to skip in email body
        $sensitive_ids = array();
        foreach ($form_fields as $field_def) {
            if (!empty($field_def['sensitive']) && !empty($field_def['fieldId'])) {
                $sensitive_ids[$field_def['fieldId']] = true;
            }
        }

        // Build signature field lookup to skip in email body
        $signature_ids = array();
        foreach ($form_fields as $field_def) {
            if (isset($field_def['fieldType']) && $field_def['fieldType'] === 'signature' && !empty($field_def['fieldId'])) {
                $signature_ids[$field_def['fieldId']] = true;
            }
        }

        $rows = '';
        foreach ($fields as $field_id => $value) {
            // Skip sensitive fields
            if (isset($sensitive_ids[$field_id])) {
                continue;
            }
            // Skip signature fields (PNG data URLs are too large for email)
            if (isset($signature_ids[$field_id])) {
                continue;
            }
            $label = esc_html(isset($label_map[$field_id]) ? $label_map[$field_id] : $field_id);

            // File fields store an array of file metadata
            if (is_array($value)) {
                $names = array();
                foreach ($value as $file) {
                    if (is_array($file) && isset($file['name'])) {
                        $names[] = esc_html($file['name']);
                    }
                }
                $val = implode(', ', array_filter($names));
                if (empty($val)) {
                    continue;
                }
            } else {
                $val = nl2br(esc_html($value));
            }

            $rows .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #eee;font-weight:500;vertical-align:top;width:30%;">' . $label . '</td>';
            $rows .= '<td style="padding:8px 12px;border-bottom:1px solid #eee;">' . $val . '</td></tr>';
        }

        $content = '<h2 style="margin:0 0 16px;font-size:18px;">' . $form_name . '</h2>';
        $content .= '<table style="width:100%;border-collapse:collapse;font-size:14px;">' . $rows . '</table>';

        return self::WrapInTemplate($content);
    }

    private static function WrapInTemplate($content)
    {
        $site_name = esc_html(get_bloginfo('name'));
        /* translators: %s: site name */
        $footer_text = sprintf(__('Sent from %s', 'superb-blocks'), $site_name);
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:20px;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">'
            . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:4px;padding:24px;border:1px solid #e0e0e0;">'
            . $content
            . '<p style="margin:24px 0 0;font-size:12px;color:#999;">' . $footer_text . '</p>'
            . '</div></body></html>';
    }

    /**
     * Build a deduplicated tag map from form field definitions.
     * Mirrors the JS buildFieldTagMap — duplicate labels get numeric suffixes.
     *
     * @param array $form_fields Array of field attribute arrays.
     * @return array Array of [ 'fieldId' => string, 'label' => string ]
     */
    private static function BuildFieldTagMap($form_fields)
    {
        $counts = array();
        $result = array();
        foreach ($form_fields as $field_def) {
            $fid = isset($field_def['fieldId']) ? $field_def['fieldId'] : '';
            if ($fid === '') {
                continue;
            }
            $field_type = isset($field_def['fieldType']) ? $field_def['fieldType'] : '';
            if ($field_type === 'hidden' || $field_type === 'signature') {
                continue;
            }
            if (!empty($field_def['sensitive'])) {
                continue;
            }
            $base_label = isset($field_def['label']) && $field_def['label'] !== ''
                ? $field_def['label']
                : ($field_type !== '' ? 'Unlabeled ' . ucfirst($field_type) . ' Field' : 'Unlabeled Field');
            $counts[$base_label] = isset($counts[$base_label]) ? $counts[$base_label] + 1 : 1;
            $n = $counts[$base_label];
            $label = $n > 1 ? $base_label . ' ' . $n : $base_label;
            $result[] = array('fieldId' => $fid, 'label' => $label);
        }
        return $result;
    }

    /**
     * @param string $text Text with merge tags
     * @param array $form_data Form configuration
     * @param array $fields Submitted field data
     * @param bool $escape_html Whether to escape values for HTML context
     */
    private static function ProcessMergeTags($text, $form_data, $fields, $escape_html = false)
    {
        $form_name = isset($form_data['form_name']) ? $form_data['form_name'] : '';
        $site_name = get_bloginfo('name');
        if ($escape_html) {
            $form_name = esc_html($form_name);
            $site_name = esc_html($site_name);
        }
        $text = str_replace('{form_name}', $form_name, $text);
        $text = str_replace('{site_name}', $site_name, $text);

        $form_fields = isset($form_data['form_fields']) ? $form_data['form_fields'] : array();
        $tag_map = self::BuildFieldTagMap($form_fields);
        foreach ($tag_map as $entry) {
            if (isset($fields[$entry['fieldId']])) {
                $val = $fields[$entry['fieldId']];
                // Skip non-string values (e.g. file metadata arrays)
                if (!is_string($val)) {
                    continue;
                }
                if ($escape_html) {
                    $val = esc_html($val);
                }
                $text = str_replace('{' . $entry['label'] . '}', $val, $text);
            }
        }

        return $text;
    }

    /**
     * Resolve the "From" name: form-level -> global default -> site name.
     *
     * @param array $form_data
     * @return string
     */
    private static function ResolveFromName($form_data)
    {
        // 1. Form-level setting
        if (!empty($form_data['email_from_name'])) {
            return sanitize_text_field($form_data['email_from_name']);
        }
        // 2. Global default setting
        $defaults = get_option('superbaddons_form_default_email', array());
        if (is_array($defaults) && !empty($defaults['from_name'])) {
            return sanitize_text_field($defaults['from_name']);
        }
        // 3. WordPress default
        return get_bloginfo('name');
    }

    /**
     * Resolve the "From" email: form-level -> global default -> admin email.
     *
     * @param array $form_data
     * @return string
     */
    private static function ResolveFromEmail($form_data)
    {
        // 1. Form-level setting
        if (!empty($form_data['email_from_address'])) {
            return sanitize_email($form_data['email_from_address']);
        }
        // 2. Global default setting
        $defaults = get_option('superbaddons_form_default_email', array());
        if (is_array($defaults) && !empty($defaults['from_email'])) {
            return sanitize_email($defaults['from_email']);
        }
        // 3. WordPress default
        return get_option('admin_email');
    }

    /**
     * Resolve a deduplicated label to its submitted value.
     */
    private static function FindFieldValue($fields, $field_name, $form_fields = array())
    {
        // First try direct match (fieldId as key)
        if (isset($fields[$field_name])) {
            return $fields[$field_name];
        }
        // Resolve deduplicated label to fieldId via tag map
        $tag_map = self::BuildFieldTagMap($form_fields);
        foreach ($tag_map as $entry) {
            if ($entry['label'] === $field_name && isset($fields[$entry['fieldId']])) {
                return $fields[$entry['fieldId']];
            }
        }
        return '';
    }
}
