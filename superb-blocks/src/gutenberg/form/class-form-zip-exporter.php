<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

/**
 * Streams form submissions as a ZIP archive: the CSV export at the root plus
 * one folder per submission holding its uploaded files under their original
 * names, grouped by field label.
 *
 * The archive uses the ZIP "store" method and is written straight to the
 * response. Uploads are photos and videos that do not compress, and storing
 * means every file is copied with readfile(): no temp file on disk, nothing
 * held in memory, no dependency on the zip extension, and the exact
 * Content-Length is known before the first byte is sent. ZIP64 is not
 * implemented, so archives are capped below the 4 GiB / 65535-entry limits.
 */
class FormZipExporter
{
    /** Cap on archive size. Leaves headroom under 2^32 for the headers. */
    const MAX_TOTAL_BYTES = 4000000000;
    /** Cap on entries, CSV included. The classic ZIP limit is 65535. */
    const MAX_ENTRIES = 60000;
    const CSV_ENTRY_NAME = 'submissions.csv';
    /** Longest path segment kept in an entry name (ZIP name lengths are 16-bit). */
    const MAX_SEGMENT_BYTES = 200;

    /**
     * Work out which files the archive will contain and what they are called.
     *
     * Every stored path is resolved and confined to the plugin's upload
     * directory by FormFileHandler::ConfineUploadPath(); anything outside it,
     * missing, or unreadable is counted as missing and skipped.
     *
     * @param array $collected Result of FormExporter::Collect().
     * @return array {
     *   'entries'       => list of ['name', 'path', 'size', 'time'],
     *   'folders'       => submission ID => archive folder name,
     *   'file_count'    => int,
     *   'missing_count' => int,
     *   'total_bytes'   => int,
     * }
     */
    public static function Plan($collected)
    {
        $entries = array();
        $folders = array();
        $file_count = 0;
        $missing_count = 0;
        $total_bytes = 0;
        $field_order = isset($collected['field_order']) ? $collected['field_order'] : array();
        $field_labels = isset($collected['field_labels']) ? $collected['field_labels'] : array();

        foreach ($collected['submissions'] as $sub) {
            $sub_id = isset($sub['id']) ? intval($sub['id']) : 0;
            $local_time = self::LocalTimestamp(isset($sub['date']) ? $sub['date'] : '');
            // Sortable date prefix in the site's timezone; the ID keeps two
            // submissions from the same minute apart.
            $folder = gmdate('Y-m-d_H-i', $local_time) . '_submission-' . $sub_id;
            $folders[$sub_id] = $folder;
            $used_names = array();

            $fields = isset($sub['fields']) && is_array($sub['fields']) ? $sub['fields'] : array();
            foreach (self::OrderedFieldIds($fields, $field_order) as $fid) {
                $value = $fields[$fid];
                // File fields store a list of file metadata arrays.
                if (!is_array($value)) {
                    continue;
                }
                $label = isset($field_labels[$fid]) ? $field_labels[$fid] : $fid;
                foreach ($value as $file) {
                    if (!is_array($file) || !isset($file['path'])) {
                        continue;
                    }
                    $real_path = FormFileHandler::ConfineUploadPath($file['path']);
                    $size = $real_path !== '' ? filesize($real_path) : false;
                    if ($real_path === '' || $size === false) {
                        $missing_count++;
                        continue;
                    }
                    $original = isset($file['name']) ? $file['name'] : '';
                    $entries[] = array(
                        'name' => self::EntryName($folder, $label, $original, $real_path, $used_names),
                        'path' => $real_path,
                        'size' => $size,
                        'time' => $local_time,
                    );
                    $file_count++;
                    $total_bytes += $size;
                }
            }
        }

        return array(
            'entries' => $entries,
            'folders' => $folders,
            'file_count' => $file_count,
            'missing_count' => $missing_count,
            'total_bytes' => $total_bytes,
        );
    }

    /**
     * Counts and size for the confirmation step and the size cap.
     *
     * @param array $plan             Result of Plan().
     * @param int   $csv_size         Byte length of the CSV entry.
     * @param int   $submission_count
     * @return array
     */
    public static function Summary($plan, $csv_size, $submission_count)
    {
        $total = $plan['total_bytes'] + $csv_size;
        $too_large = $total > self::MAX_TOTAL_BYTES || (count($plan['entries']) + 1) > self::MAX_ENTRIES;
        $message = '';
        if ($too_large) {
            $message = sprintf(
                /* translators: 1: archive size, 2: size limit */
                __('This download would be %1$s, above the %2$s limit for a single archive. Narrow the selection or date range and try again.', 'superb-blocks'),
                size_format($total, 1),
                size_format(self::MAX_TOTAL_BYTES)
            );
        }
        return array(
            'submission_count' => intval($submission_count),
            'file_count' => $plan['file_count'],
            'missing_count' => $plan['missing_count'],
            'total_bytes' => $total,
            'total_human' => size_format($total, 1),
            'too_large' => $too_large,
            'message' => $message,
        );
    }

    /**
     * Stream the archive and exit. Callers must have checked Summary()['too_large'].
     *
     * @param string $filename Download filename (already sanitized).
     * @param array  $entries  From Plan().
     * @param string $csv      CSV contents for the root entry.
     */
    public static function Stream($filename, $entries, $csv)
    {
        $items = array(
            array(
                'name' => self::CSV_ENTRY_NAME,
                'size' => strlen($csv),
                'time' => self::LocalTimestamp(''),
                'data' => $csv,
            ),
        );
        foreach ($entries as $entry) {
            $items[] = $entry;
        }

        // Every size is known, so the exact length can be sent: browsers show
        // real progress and flag a download that was cut short. Per entry: a
        // 30-byte local header and a 46-byte central record, each carrying the
        // name, plus the data; then the 22-byte end record.
        $content_length = 22;
        foreach ($items as $item) {
            $content_length += 30 + 46 + 2 * strlen($item['name']) + $item['size'];
        }

        while (ob_get_level()) {
            ob_end_clean();
        }
        // A large export streams every uploaded file through PHP, so the default
        // max_execution_time could cut the download short. Before PHP 8 a function
        // listed in disable_functions still passes function_exists() and calling it
        // emits a warning, which would corrupt the ZIP, hence the ini check.
        if (function_exists('set_time_limit') && strpos((string) ini_get('disable_functions'), 'set_time_limit') === false) {
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Lifting the limit for a streamed download, see above.
            set_time_limit(0);
        }

        nocache_headers();
        header('Content-Type: application/zip');
        header('X-Content-Type-Options: nosniff');
        // Same filename hardening as ServeFile(): no quotes or line breaks in the header value.
        $safe_filename = str_replace(array('"', "\r", "\n"), '', $filename);
        header('Content-Disposition: attachment; filename="' . $safe_filename . '"');
        // With zlib output compression on, PHP recompresses the body and the
        // declared length would no longer match what the browser receives.
        if (!ini_get('zlib.output_compression')) {
            header('Content-Length: ' . $content_length);
        }

        $offset = 0;
        $central_directory = '';
        foreach ($items as $item) {
            $name = $item['name'];
            $name_length = strlen($name);
            $crc = isset($item['data']) ? hexdec(hash('crc32b', $item['data'])) : hexdec(hash_file('crc32b', $item['path']));
            list($dos_time, $dos_date) = self::DosDateTime($item['time']);

            // Local file header: signature, version needed, flags (bit 11:
            // UTF-8 names), method 0 (store), time, date, CRC-32, compressed
            // and uncompressed size, name length, extra length.
            self::Emit(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dos_time, $dos_date, $crc, $item['size'], $item['size'], $name_length, 0) . $name);

            if (isset($item['data'])) {
                self::Emit($item['data']);
            } else {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
                $sent = readfile($item['path']);
                if ($sent !== $item['size']) {
                    // The file changed underneath us. Stop rather than write a
                    // central directory that no longer matches; the short body
                    // makes the browser report a failed download.
                    exit;
                }
            }

            // Central directory record: signature, version made by, version
            // needed, flags, method, time, date, CRC-32, sizes, name length,
            // extra length, comment length, disk number, internal and external
            // attributes, offset of the local header.
            $central_directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dos_time, $dos_date, $crc, $item['size'], $item['size'], $name_length, 0, 0, 0, 0, 0, $offset) . $name;
            $offset += 30 + $name_length + $item['size'];
        }

        self::Emit($central_directory);
        // End of central directory: signature, disk numbers, entry counts,
        // central directory size and offset, comment length.
        self::Emit(pack('VvvvvVVv', 0x06054b50, 0, 0, count($items), count($items), strlen($central_directory), $offset, 0));
        // Plain exit, not wp_die(): the die handler would append HTML after the archive bytes.
        exit;
    }

    private static function Emit($bytes)
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw ZIP bytes; escaping would corrupt the archive.
        echo $bytes;
    }

    /**
     * Field IDs in form order, then any stored IDs the config no longer has.
     */
    private static function OrderedFieldIds($fields, $field_order)
    {
        $ids = array();
        foreach ($field_order as $fid) {
            if (array_key_exists($fid, $fields)) {
                $ids[] = $fid;
            }
        }
        foreach (array_keys($fields) as $fid) {
            if (!in_array($fid, $ids, true)) {
                $ids[] = $fid;
            }
        }
        return $ids;
    }

    /**
     * Archive path for one file: <submission folder>/<field label>/<original name>,
     * with " (2)", " (3)", ... appended when a name repeats within the folder.
     *
     * @param array $used Names already taken in this submission's folder (by reference).
     */
    private static function EntryName($folder, $label, $original_name, $real_path, &$used)
    {
        $label_segment = self::SafeSegment($label, 'files');
        $file_segment = self::SafeSegment($original_name, '');
        if ($file_segment === '') {
            $file_segment = self::SafeSegment(wp_basename($real_path), 'file');
        }
        $base = $folder . '/' . $label_segment . '/';
        $candidate = $base . $file_segment;
        if (isset($used[$candidate])) {
            $extension = pathinfo($file_segment, PATHINFO_EXTENSION);
            $stem = $extension !== '' ? substr($file_segment, 0, -(strlen($extension) + 1)) : $file_segment;
            $n = 2;
            do {
                $candidate = $base . $stem . ' (' . $n . ')' . ($extension !== '' ? '.' . $extension : '');
                $n++;
            } while (isset($used[$candidate]));
        }
        $used[$candidate] = true;
        return $candidate;
    }

    /**
     * One archive path segment. sanitize_file_name() strips slashes,
     * backslashes, control characters and leading/trailing dots, so neither a
     * field label nor a submitted filename can climb out of its folder when
     * the archive is extracted.
     */
    private static function SafeSegment($name, $fallback)
    {
        $segment = sanitize_file_name(wp_strip_all_tags((string) $name));
        if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
            $extension = pathinfo($segment, PATHINFO_EXTENSION);
            $keep_extension = $extension !== '' && strlen($extension) <= 16;
            $stem = $keep_extension ? substr($segment, 0, -(strlen($extension) + 1)) : $segment;
            $segment = mb_substr($stem, 0, 120) . ($keep_extension ? '.' . $extension : '');
        }
        return $segment === '' ? $fallback : $segment;
    }

    /**
     * Wall-clock timestamp in the site's timezone: ZIP timestamps and the
     * folder names are local time by convention. Empty input means "now".
     */
    private static function LocalTimestamp($gmt_date)
    {
        $gmt = '';
        if (is_string($gmt_date) && $gmt_date !== '') {
            $ts = strtotime($gmt_date);
            if ($ts !== false) {
                $gmt = gmdate('Y-m-d H:i:s', $ts);
            }
        }
        if ($gmt === '') {
            $gmt = gmdate('Y-m-d H:i:s');
        }
        $local = strtotime(get_date_from_gmt($gmt, 'Y-m-d H:i:s') . ' UTC');
        return $local !== false ? $local : time();
    }

    /**
     * MS-DOS time and date words used by ZIP headers (2-second resolution,
     * years counted from 1980).
     */
    private static function DosDateTime($timestamp)
    {
        $year = intval(gmdate('Y', $timestamp));
        if ($year < 1980) {
            return array(0, (1 << 5) | 1);
        }
        $time = (intval(gmdate('G', $timestamp)) << 11) | (intval(gmdate('i', $timestamp)) << 5) | (intval(gmdate('s', $timestamp)) >> 1);
        $date = (($year - 1980) << 9) | (intval(gmdate('n', $timestamp)) << 5) | intval(gmdate('j', $timestamp));
        return array($time, $date);
    }
}
