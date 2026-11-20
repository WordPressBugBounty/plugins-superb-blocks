<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormExporter
{
    /** Records read per query while an export runs: one page is in memory at a time. */
    const PAGE_SIZE = 100;

    /**
     * Column metadata the export formats need, from a form's field definitions.
     *
     * @param array $form_fields Field definitions from form config.
     * @return array {
     *   'field_order'         => field IDs in form order,
     *   'field_labels'        => field ID => label,
     *   'sensitive_field_ids' => field IDs flagged sensitive,
     * }
     */
    public static function FieldMeta($form_fields)
    {
        $field_labels = array();
        $sensitive_field_ids = array();
        $field_order = array();
        foreach ((array) $form_fields as $field) {
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
        return array(
            'field_order' => $field_order,
            'field_labels' => $field_labels,
            'sensitive_field_ids' => $sensitive_field_ids,
        );
    }

    /**
     * Fetch every submission matching the filters (or the given IDs) together
     * with the field metadata the export formats need.
     *
     * Everything is held in memory. The export routes use this only for a form whose config is gone (the columns
     * are then known only once every record was read) and for a single record; otherwise they read the records
     * one page at a time with ForEachPage().
     *
     * @param string     $form_id
     * @param array      $form_fields       Field definitions from form config.
     * @param bool       $include_sensitive Whether to decrypt sensitive fields.
     * @param string     $status            Filter by status ('new', 'read', 'spam', or '').
     * @param string     $starred           Filter by starred ('1' or '').
     * @param string     $search            Search term to filter submissions.
     * @param string     $date_after        ISO date string for date range start.
     * @param string     $date_before       ISO date string for date range end.
     * @param bool       $pending_delete    Whether the form is pending deletion (config unavailable).
     * @param array|null $ids               Restrict to these submission IDs; null applies the filters only.
     * @return array {
     *   'submissions'         => list of submissions (sensitive fields decrypted when requested),
     *   'field_order'         => field IDs in form order,
     *   'field_labels'        => field ID => label,
     *   'sensitive_field_ids' => field IDs flagged sensitive,
     * }
     */
    public static function Collect($form_id, $form_fields, $include_sensitive, $status = '', $starred = '', $search = '', $date_after = '', $date_before = '', $pending_delete = false, $ids = null)
    {
        $meta = self::FieldMeta($form_fields);
        $field_order = $meta['field_order'];
        $field_labels = $meta['field_labels'];
        $sensitive_field_ids = $meta['sensitive_field_ids'];
        $derive = $pending_delete && empty($form_fields);

        $all_submissions = array();
        self::ForEachPage($form_id, $include_sensitive, $status, $starred, $search, $date_after, $date_before, $ids, function ($submissions) use (&$all_submissions, &$field_order, &$field_labels, &$sensitive_field_ids, $derive, $include_sensitive, $form_fields, $pending_delete) {
            // When config is gone, derive field order and sensitive IDs from the stored data
            if ($derive) {
                foreach ($submissions as $sub) {
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
            }
            if ($include_sensitive) {
                $submissions = self::DecryptSubmissions($submissions, $form_fields, $pending_delete);
            }
            foreach ($submissions as $sub) {
                $all_submissions[] = $sub;
            }
        });

        return array(
            'submissions' => $all_submissions,
            'field_order' => $field_order,
            'field_labels' => $field_labels,
            'sensitive_field_ids' => $sensitive_field_ids,
        );
    }

    /**
     * Read the submissions matching the filters (or the given IDs) one page at a time and hand each page to
     * $callback, field values as stored (see DecryptSubmissions()). A caller who may not see sensitive values
     * gets the visible-value search in one pass (it is matched in PHP, so it is not paged).
     *
     * @param callable $callback Receives the list of submissions of one page.
     * @return int Number of submissions handed to the callback.
     */
    public static function ForEachPage($form_id, $include_sensitive, $status, $starred, $search, $date_after, $date_before, $ids, $callback)
    {
        $visible_search = !$include_sensitive && trim((string) $search) !== '';
        $batch_size = $visible_search ? -1 : self::PAGE_SIZE;
        $page = 1;
        $count = 0;
        do {
            $result = FormSubmissionHandler::GetSubmissions($form_id, $page, $batch_size, $status, $starred, $search, $date_after, $date_before, $ids, $visible_search);
            if (empty($result['submissions'])) {
                break;
            }
            $count += count($result['submissions']);
            call_user_func($callback, $result['submissions']);
            $page++;
        } while (!$visible_search && count($result['submissions']) === $batch_size);
        return $count;
    }

    /**
     * Decrypt the sensitive fields of a page of submissions, for a caller with the sensitive grant.
     *
     * @param array $submissions
     * @param array $form_fields
     * @param bool  $pending_delete Whether the form is pending deletion (config unavailable).
     * @return array
     */
    public static function DecryptSubmissions($submissions, $form_fields, $pending_delete = false)
    {
        foreach ($submissions as $index => $sub) {
            $submissions[$index]['fields'] = self::DecryptFields($form_fields, isset($sub['fields']) && is_array($sub['fields']) ? $sub['fields'] : array(), $pending_delete);
        }
        return $submissions;
    }

    /**
     * Field IDs to export as columns: the user's saved preference when given,
     * otherwise every field, always minus sensitive fields the caller may not see.
     *
     * @param array      $meta              Result of FieldMeta() or Collect().
     * @param bool       $include_sensitive
     * @param array|null $export_fields     Saved field preference, or null for all fields.
     * @return array
     */
    public static function VisibleFields($meta, $include_sensitive, $export_fields = null)
    {
        $field_order = $meta['field_order'];
        $sensitive_field_ids = $meta['sensitive_field_ids'];

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
        return $visible_fields;
    }

    /**
     * Download filename: <form name>-<date>.<extension>.
     *
     * @param string $form_id
     * @param string $extension Without the dot.
     * @return string
     */
    public static function ExportFilename($form_id, $extension)
    {
        $form_name = FormRegistry::GetName($form_id);
        $safe_name = sanitize_file_name($form_name);
        if (empty($safe_name)) {
            $safe_name = 'form-export';
        }
        return $safe_name . '-' . gmdate('Y-m-d') . '.' . $extension;
    }

    /**
     * Write the header row: Date, Status, [Files folder,] then field labels, optionally Notes.
     *
     * @param resource $output
     * @param array    $meta           Result of FieldMeta() or Collect().
     * @param array    $visible_fields Result of VisibleFields().
     * @param bool     $include_notes
     * @param bool     $with_folders   Whether rows carry the "Files folder" column (ZIP export).
     * @param bool     $bom            Lead the first cell with the UTF-8 byte order mark (a CSV written into a
     *                                 buffer; Export() echoes it before its stream opens).
     */
    public static function WriteCsvHeader($output, $meta, $visible_fields, $include_notes, $with_folders, $bom = false)
    {
        $field_labels = $meta['field_labels'];
        $header = array(($bom ? "\xEF\xBB\xBF" : '') . 'Date', 'Status');
        if ($with_folders) {
            $header[] = 'Files folder';
        }
        foreach ($visible_fields as $fid) {
            $header[] = self::EscapeCell(isset($field_labels[$fid]) ? $field_labels[$fid] : $fid);
        }
        if ($include_notes) {
            $header[] = 'Notes';
        }
        // The escape parameter is passed explicitly (its current default) because PHP 8.4 deprecates omitting it
        fputcsv($output, $header, ',', '"', '\\');
    }

    /**
     * Write one row per submission.
     *
     * @param resource   $output
     * @param array      $visible_fields Result of VisibleFields().
     * @param bool       $include_notes
     * @param array      $submissions    One page of submissions.
     * @param array|null $folders        Submission ID => folder name. When given (ZIP export),
     *                                   the "Files folder" column maps each row to its files.
     */
    public static function WriteCsvRows($output, $visible_fields, $include_notes, $submissions, $folders = null)
    {
        foreach ($submissions as $sub) {
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

            // Files folder
            if (is_array($folders)) {
                $sub_id = isset($sub['id']) ? intval($sub['id']) : 0;
                $row[] = self::EscapeCell(isset($folders[$sub_id]) ? $folders[$sub_id] : '');
            }

            // Fields
            $fields = isset($sub['fields']) ? $sub['fields'] : array();
            foreach ($visible_fields as $fid) {
                $value = isset($fields[$fid]) ? $fields[$fid] : '';
                $row[] = self::EscapeCell(self::FormatFieldValue($value));
            }

            // Notes
            if ($include_notes) {
                $sub_id = isset($sub['id']) ? intval($sub['id']) : 0;
                $notes = $sub_id > 0 ? FormSubmissionHandler::GetNotes($sub_id) : array();
                $note_texts = array();
                foreach ($notes as $note) {
                    $note_texts[] = $note['author_name'] . ' (' . $note['date'] . '): ' . $note['text'];
                }
                $row[] = self::EscapeCell(implode("\n", $note_texts));
            }

            fputcsv($output, $row, ',', '"', '\\');
        }
    }

    /**
     * Write the header row and one row per submission to an open stream.
     *
     * @param resource   $output
     * @param array      $collected      Result of Collect().
     * @param array      $visible_fields Result of VisibleFields().
     * @param bool       $include_notes
     * @param array|null $folders        See WriteCsvRows().
     */
    public static function WriteCsv($output, $collected, $visible_fields, $include_notes, $folders = null)
    {
        self::WriteCsvHeader($output, $collected, $visible_fields, $include_notes, is_array($folders));
        self::WriteCsvRows($output, $visible_fields, $include_notes, $collected['submissions'], $folders);
    }

    /**
     * The full CSV as a string (with UTF-8 BOM), for embedding in an archive.
     *
     * @param array      $collected
     * @param array      $visible_fields
     * @param bool       $include_notes
     * @param array|null $folders See WriteCsvRows().
     * @return string
     */
    public static function BuildCsvString($collected, $visible_fields, $include_notes, $folders = null)
    {
        $handle = self::OpenCsvBuffer();
        self::WriteCsv($handle, $collected, $visible_fields, $include_notes, $folders);
        rewind($handle);
        $csv = stream_get_contents($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);
        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * A temporary stream for a CSV built page by page: in memory up to 2 MB, a temporary file beyond that.
     *
     * @return resource
     */
    public static function OpenCsvBuffer()
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- temporary stream for fputcsv(); no site file is touched.
        return fopen('php://temp/maxmemory:2097152', 'w+');
    }

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
     * @param bool $include_notes Whether to add a Notes column.
     * @param array|null $export_fields Saved field preference, or null for all fields.
     * @param bool $pending_delete Whether the form is pending deletion (config unavailable).
     * @return void Streams CSV directly and exits.
     */
    public static function Export($form_id, $form_fields, $include_sensitive, $status = '', $starred = '', $search = '', $date_after = '', $date_before = '', $include_notes = false, $export_fields = null, $pending_delete = false)
    {
        // With the form's config at hand the rows are written one page of records at a time; without it (a form
        // pending deletion) the columns are known only once every record was read
        $paged = !($pending_delete && empty($form_fields));
        $collected = $paged ? null : self::Collect($form_id, $form_fields, $include_sensitive, $status, $starred, $search, $date_after, $date_before, $pending_delete);
        $meta = $paged ? self::FieldMeta($form_fields) : $collected;
        $visible_fields = self::VisibleFields($meta, $include_sensitive, $export_fields);
        $filename = self::ExportFilename($form_id, 'csv');

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
        self::WriteCsvHeader($output, $meta, $visible_fields, $include_notes, false);
        if ($paged) {
            self::ForEachPage($form_id, $include_sensitive, $status, $starred, $search, $date_after, $date_before, null, function ($submissions) use ($output, $visible_fields, $include_notes, $include_sensitive, $form_fields) {
                if ($include_sensitive) {
                    $submissions = self::DecryptSubmissions($submissions, $form_fields);
                }
                self::WriteCsvRows($output, $visible_fields, $include_notes, $submissions);
            });
        } else {
            self::WriteCsvRows($output, $visible_fields, $include_notes, $collected['submissions']);
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
     * Keep a cell as text when the export is opened in a spreadsheet program: a value starting with =, +, -, @,
     * a tab or a carriage return would otherwise be read as a formula. The rule WooCommerce's exporter applies.
     *
     * @param string $value
     * @return string
     */
    private static function EscapeCell($value)
    {
        $value = (string) $value;
        if ($value !== '' && in_array(substr($value, 0, 1), array('=', '+', '-', '@', "\t", "\r"), true)) {
            return "'" . $value;
        }
        return $value;
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
