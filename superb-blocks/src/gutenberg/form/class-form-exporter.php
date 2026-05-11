<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormExporter
{
    /**
     * Generate and stream a CSV export for a form's submissions.
     *
     * @param string $form_id
     * @param array $form_fields Field definitions from form config.
     * @param bool $include_sensitive Whether to include decrypted sensitive fields.
     * @param string $status Filter by status ('new', 'read', 'spam', or '').
     * @param string $starred Filter by starred ('1' or '').
     * @param string $search Search term to filter submissions.
     * @param string $date_after ISO date string for date range start.
     * @param string $date_before ISO date string for date range end.
     * @param bool $pending_delete Whether the form is pending deletion (config unavailable).
     * @return void Streams CSV directly and exits.
     */
    public static function Export($form_id, $form_fields, $include_sensitive, $status = '', $starred = '', $search = '', $date_after = '', $date_before = '', $include_notes = false, $export_fields = null, $pending_delete = false)
    {
        // Build field label map and sensitive field list
        $field_labels = array();
        $sensitive_field_ids = array();
        $field_order = array();
        foreach ($form_fields as $field) {
            if (!isset($field['fieldId'])) {
                continue;
            }
            $fid = $field['fieldId'];
            $field_order[] = $fid;
            $field_labels[$fid] = isset($field['label']) ? $field['label'] : $fid;
            if (!empty($field['sensitive'])) {
                $sensitive_field_ids[] = $fid;
            }
        }

        // Fetch all matching submissions (no pagination)
        $all_submissions = array();
        $page = 1;
        $batch_size = 100;
        do {
            $result = FormSubmissionHandler::GetSubmissions($form_id, $page, $batch_size, $status, $starred, $search, $date_after, $date_before);
            if (empty($result['submissions'])) {
                break;
            }
            foreach ($result['submissions'] as $sub) {
                // When config is gone, derive field order and sensitive IDs from submission data
                if ($pending_delete && empty($form_fields)) {
                    foreach ($sub['fields'] as $fid => $value) {
                        if (!in_array($fid, $field_order, true)) {
                            $field_order[] = $fid;
                            $field_labels[$fid] = $fid;
                        }
                        if (FormEncryption::IsEncrypted($value) && !in_array($fid, $sensitive_field_ids, true)) {
                            $sensitive_field_ids[] = $fid;
                        }
                    }
                }
                // Decrypt sensitive fields if included
                if ($include_sensitive) {
                    $sub['fields'] = self::DecryptFields($form_fields, $sub['fields'], $pending_delete);
                }
                $all_submissions[] = $sub;
            }
            $page++;
        } while (count($result['submissions']) === $batch_size);

        // Determine which fields to include
        $visible_fields = array();
        if ($export_fields !== null) {
            // Use user's field preference, respecting sensitive field visibility
            foreach ($export_fields as $fid) {
                if (!$include_sensitive && in_array($fid, $sensitive_field_ids, true)) {
                    continue;
                }
                if (in_array($fid, $field_order, true)) {
                    $visible_fields[] = $fid;
                }
            }
        }
        if (empty($visible_fields)) {
            foreach ($field_order as $fid) {
                if (!$include_sensitive && in_array($fid, $sensitive_field_ids, true)) {
                    continue;
                }
                $visible_fields[] = $fid;
            }
        }

        // Build the form name for the filename
        $form_name = FormRegistry::GetName($form_id);
        $safe_name = sanitize_file_name($form_name);
        if (empty($safe_name)) {
            $safe_name = 'form-export';
        }
        $filename = $safe_name . '-' . gmdate('Y-m-d') . '.csv';

        // Stream CSV
        // Disable output buffering to stream directly
        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // UTF-8 BOM for Excel compatibility. Emitted via echo (same destination as the stream below) so we do not need fwrite, which the plugin checker flags.
        echo "\xEF\xBB\xBF";

        $output = fopen('php://output', 'w');

        // Header row: Date, Status, then field labels, optionally Notes
        $header = array('Date', 'Status');
        foreach ($visible_fields as $fid) {
            $header[] = isset($field_labels[$fid]) ? $field_labels[$fid] : $fid;
        }
        if ($include_notes) {
            $header[] = 'Notes';
        }
        fputcsv($output, $header);

        // Data rows
        foreach ($all_submissions as $sub) {
            $row = array();

            // Date
            $date = isset($sub['date']) ? $sub['date'] : '';
            if (!empty($date)) {
                $timestamp = strtotime($date);
                $row[] = $timestamp !== false ? gmdate('Y-m-d H:i:s', $timestamp) : $date;
            } else {
                $row[] = '';
            }

            // Status
            $row[] = isset($sub['status']) && $sub['status'] === 'new' ? 'Unread' : 'Read';

            // Fields
            $fields = isset($sub['fields']) ? $sub['fields'] : array();
            foreach ($visible_fields as $fid) {
                $value = isset($fields[$fid]) ? $fields[$fid] : '';
                $row[] = self::FormatFieldValue($value);
            }

            // Notes
            if ($include_notes) {
                $sub_id = isset($sub['id']) ? intval($sub['id']) : 0;
                $notes = $sub_id > 0 ? FormSubmissionHandler::GetNotes($sub_id) : array();
                $note_texts = array();
                foreach ($notes as $note) {
                    $note_texts[] = $note['author_name'] . ' (' . $note['date'] . '): ' . $note['text'];
                }
                $row[] = implode("\n", $note_texts);
            }

            fputcsv($output, $row);
        }

        exit;
    }

    /**
     * Format a field value for CSV output.
     * Arrays (file uploads) are formatted as comma-separated filenames.
     *
     * @param mixed $value
     * @return string
     */
    private static function FormatFieldValue($value)
    {
        if (is_array($value)) {
            // File upload field: array of {name, path, type}
            $names = array();
            foreach ($value as $file) {
                if (is_array($file) && isset($file['name'])) {
                    $names[] = $file['name'];
                }
            }
            return implode(', ', $names);
        }
        return is_string($value) ? $value : '';
    }

    /**
     * Decrypt sensitive fields in submission data.
     *
     * @param array $form_fields
     * @param array $fields
     * @param bool  $pending_delete Whether the form is pending deletion (config unavailable).
     * @return array
     */
    private static function DecryptFields($form_fields, $fields, $pending_delete = false)
    {
        if (empty($form_fields) && $pending_delete) {
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
}
