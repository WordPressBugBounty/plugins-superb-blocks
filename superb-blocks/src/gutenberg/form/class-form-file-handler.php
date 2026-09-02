<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormFileHandler
{
    const UPLOAD_SUBDIR = 'superb-addons-forms';
    const UPLOAD_DIR_TOKEN_OPTION = 'superbaddons_form_upload_dir_token';

    /**
     * Name of the upload directory for form files: UPLOAD_SUBDIR plus a
     * random per-site token, e.g. "superb-addons-forms-k2m9q4p1n8x7c3v5".
     * 
     * @return string
     */
    public static function GetUploadSubdir()
    {
        $token = get_option(self::UPLOAD_DIR_TOKEN_OPTION);
        // Regenerate when missing or not a plain token, so a tampered option
        // value can never become a path segment.
        if (!is_string($token) || !preg_match('/^[a-z0-9]{8,64}$/', $token)) {
            $token = self::MintUploadDirToken();
            self::MigrateLegacyUploadDir(self::UPLOAD_SUBDIR . '-' . $token);
        }
        return self::UPLOAD_SUBDIR . '-' . $token;
    }

    /**
     * Store a fresh upload directory token, or adopt the one a concurrent
     * request stored first.
     *
     * @return string Valid token, stored except in the pathological case
     *                where an invalid value is being re-written concurrently.
     */
    private static function MintUploadDirToken()
    {
        $token = strtolower(wp_generate_password(16, false, false));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (add_option(self::UPLOAD_DIR_TOKEN_OPTION, $token, '', 'yes')) {
                return $token;
            }
            $stored = get_option(self::UPLOAD_DIR_TOKEN_OPTION);
            if (is_string($stored) && preg_match('/^[a-z0-9]{8,64}$/', $stored)) {
                return $stored;
            }
            delete_option(self::UPLOAD_DIR_TOKEN_OPTION);
        }
        return $token;
    }

    private static function MigrateLegacyUploadDir($target_subdir)
    {
        $upload_dir = wp_upload_dir();
        $legacy_base = $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR;
        $target_base = $upload_dir['basedir'] . '/' . $target_subdir;
        // A freshly minted random directory cannot pre-exist; if it somehow
        // does (concurrent mint already moved the tree), there is nothing to do
        if (!is_dir($legacy_base) || is_dir($target_base)) {
            return;
        }

        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            WP_Filesystem();
        }
        if (!empty($wp_filesystem)) {
            $wp_filesystem->move($legacy_base, $target_base);
        }
    }

    /**
     * Resolve a stored absolute file path
     *
     * @param string $path Stored absolute path
     * @return string Resolved path, '' when the input is not a usable string
     */
    public static function ResolveStoredPath($path)
    {
        if (!is_string($path) || $path === '') {
            return '';
        }
        if (file_exists($path)) {
            return $path;
        }
        $upload_dir = wp_upload_dir();
        $legacy_base = $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR . '/';
        if (strpos($path, $legacy_base) === 0) {
            $candidate = $upload_dir['basedir'] . '/' . self::GetUploadSubdir() . '/' . substr($path, strlen($legacy_base));
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return $path;
    }

    /**
     * Master list of file types available to form file upload fields.
     * Feeds both the editor's Accepted File Types picker and server-side
     * upload validation, so the two cannot drift apart.
     *
     * Extensions on the HasDangerousExtension deny-list are stripped from
     * the filtered result unconditionally, so the filter cannot be used to
     * allow executable or server-interpreted uploads.
     *
     * @return array Entries of array('ext' => '.mp4', 'label' => 'MP4', 'mime' => 'video/mp4')
     */
    public static function GetAllowedFileTypes()
    {
        $types = array(
            // Images. SVG is excluded because it is XML and can host inline scripts.
            array('ext' => '.jpg',  'label' => 'JPG',  'mime' => 'image/jpeg'),
            array('ext' => '.jpeg', 'label' => 'JPEG', 'mime' => 'image/jpeg'),
            array('ext' => '.png',  'label' => 'PNG',  'mime' => 'image/png'),
            array('ext' => '.gif',  'label' => 'GIF',  'mime' => 'image/gif'),
            array('ext' => '.webp', 'label' => 'WebP', 'mime' => 'image/webp'),
            // Documents
            array('ext' => '.pdf',  'label' => 'PDF',  'mime' => 'application/pdf'),
            array('ext' => '.doc',  'label' => 'DOC',  'mime' => 'application/msword'),
            array('ext' => '.docx', 'label' => 'DOCX', 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            array('ext' => '.txt',  'label' => 'TXT',  'mime' => 'text/plain'),
            // Spreadsheets
            array('ext' => '.xls',  'label' => 'XLS',  'mime' => 'application/vnd.ms-excel'),
            array('ext' => '.xlsx', 'label' => 'XLSX', 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            array('ext' => '.csv',  'label' => 'CSV',  'mime' => 'text/csv'),
            // Archives
            array('ext' => '.zip',  'label' => 'ZIP',  'mime' => 'application/zip'),
            // Video
            array('ext' => '.mp4',  'label' => 'MP4',  'mime' => 'video/mp4'),
            array('ext' => '.m4v',  'label' => 'M4V',  'mime' => 'video/mp4'),
            array('ext' => '.mov',  'label' => 'MOV',  'mime' => 'video/quicktime'),
            array('ext' => '.webm', 'label' => 'WebM', 'mime' => 'video/webm'),
            array('ext' => '.avi',  'label' => 'AVI',  'mime' => 'video/avi'),
            array('ext' => '.mkv',  'label' => 'MKV',  'mime' => 'video/x-matroska'),
            array('ext' => '.mpg',  'label' => 'MPG',  'mime' => 'video/mpeg'),
            array('ext' => '.wmv',  'label' => 'WMV',  'mime' => 'video/x-ms-wmv'),
            // Audio
            array('ext' => '.mp3',  'label' => 'MP3',  'mime' => 'audio/mpeg'),
            array('ext' => '.m4a',  'label' => 'M4A',  'mime' => 'audio/mpeg'),
            array('ext' => '.wav',  'label' => 'WAV',  'mime' => 'audio/wav'),
            array('ext' => '.ogg',  'label' => 'OGG',  'mime' => 'audio/ogg'),
            array('ext' => '.flac', 'label' => 'FLAC', 'mime' => 'audio/flac'),
        );

        /**
         * Filters the file types visitors can upload through form file upload fields.
         *
         * Each entry needs an 'ext' (extension with leading dot), 'label' (shown in
         * the editor's Accepted File Types picker), and 'mime' (enforced during
         * upload validation). Entries whose extension is on the dangerous-extension
         * deny-list (php, html, svg, exe, ...) are discarded. Types WordPress does
         * not allow by default do not need a separate upload_mimes filter; this
         * list is authoritative for form uploads.
         *
         * @param array $types Entries of array('ext' => ..., 'label' => ..., 'mime' => ...).
         */
        $types = apply_filters('superbaddons_form_allowed_file_types', $types);

        if (!is_array($types)) {
            return array();
        }

        $sanitized = array();
        $seen = array();
        foreach ($types as $type) {
            if (!is_array($type) || empty($type['ext']) || !is_string($type['ext']) || empty($type['mime']) || !is_string($type['mime'])) {
                continue;
            }
            $ext = strtolower(trim($type['ext']));
            if (strpos($ext, '.') !== 0) {
                $ext = '.' . $ext;
            }
            // Single alphanumeric segment only — multi-segment entries like
            // .tar.gz can never match pathinfo(PATHINFO_EXTENSION) in validation
            if (!preg_match('/^\.[a-z0-9]+$/', $ext)) {
                continue;
            }
            // Deny-list wins over the filter
            if (self::HasDangerousExtension('file' . $ext)) {
                continue;
            }
            if (isset($seen[$ext])) {
                continue;
            }
            $seen[$ext] = true;
            $sanitized[] = array(
                'ext' => $ext,
                'label' => isset($type['label']) && is_string($type['label']) && $type['label'] !== '' ? $type['label'] : strtoupper(substr($ext, 1)),
                'mime' => $type['mime'],
            );
        }
        return $sanitized;
    }

    /**
     * Default accepted extensions for file fields whose server-side config
     * carries no explicit accept list. Gutenberg omits attributes that still
     * equal their block.json defaults when serializing, so a file field whose
     * settings were never touched reaches the server without fileSettings at
     * all; this list makes validation enforce exactly the types the editor UI
     * displays for that state. Must be kept in sync with the fileSettings.accept
     * default in /form-field/block.json.
     *
     * @return array Extensions with leading dot
     */
    public static function GetDefaultAccept()
    {
        return array('.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf', '.doc', '.docx', '.txt', '.xls', '.xlsx', '.csv');
    }

    /**
     * Extension => MIME map derived from GetAllowedFileTypes, in the format
     * wp_check_filetype() and wp_handle_upload() expect.
     *
     * @return array e.g. array('mp4' => 'video/mp4', ...)
     */
    public static function GetAllowedMimes()
    {
        $mimes = array();
        foreach (self::GetAllowedFileTypes() as $type) {
            $mimes[substr($type['ext'], 1)] = $type['mime'];
        }
        return $mimes;
    }

    /**
     * Validate uploaded files for a field against its config.
     * Called by FormFieldValidator before files are processed.
     *
     * @param array $field_config Field configuration from server-side config
     * @param string $default_required_message Form-wide message for empty required fields, '' for the localized default
     * @return string Error message, empty if valid
     */
    public static function ValidateFiles($field_config, $default_required_message = '')
    {
        $field_id = isset($field_config['fieldId']) ? $field_config['fieldId'] : '';
        $required = !empty($field_config['required']);
        $fs = isset($field_config['fileSettings']) && is_array($field_config['fileSettings']) ? $field_config['fileSettings'] : array();
        $max_file_size = isset($fs['maxFileSize']) ? floatval($fs['maxFileSize']) : 5;
        $multiple = !empty($fs['multiple']);
        $max_files = isset($fs['maxFiles']) ? intval($fs['maxFiles']) : 5;
        // Like the other fileSettings keys above, a missing accept list means
        // the field was left at its block.json defaults (default-valued
        // attributes are omitted from serialized markup), so enforce the
        // default list the editor UI shows rather than the wider master list.
        // A present-but-empty list (legacy content saved before the editor
        // refused to empty the picker) gets the same defaults.
        $accept = isset($fs['accept']) && is_array($fs['accept']) && !empty($fs['accept']) ? $fs['accept'] : self::GetDefaultAccept();

        // Check if files were submitted for this field
        $files = self::GetUploadedFiles($field_id);

        if (empty($files)) {
            // Conditional logic: if field has active rules, skip required check
            // (handled by FormFieldValidator before calling us, but guard here too)
            if ($required) {
                $logic = isset($field_config['conditionalLogic']) ? $field_config['conditionalLogic'] : null;
                if ($logic && isset($logic['ruleGroups']) && is_array($logic['ruleGroups'])) {
                    foreach ($logic['ruleGroups'] as $group) {
                        if (isset($group['conditions']) && is_array($group['conditions'])) {
                            foreach ($group['conditions'] as $cond) {
                                if (!empty($cond['field'])) {
                                    return '';
                                }
                            }
                        }
                    }
                }
                return FormFieldValidator::GetRequiredMessage($field_config, $default_required_message);
            }
            return '';
        }

        // Validate file count
        if (!$multiple && count($files) > 1) {
            return __('Only one file is allowed.', 'superb-blocks');
        }
        if ($multiple && count($files) > $max_files) {
            /* translators: %d: maximum number of files allowed for this field */
            return sprintf(__('Maximum %d files allowed.', 'superb-blocks'), $max_files);
        }

        // Validate each file
        $max_bytes = $max_file_size * 1024 * 1024;
        $allowed_mimes = self::GetAllowedMimes();
        foreach ($files as $file) {
            // Check for upload errors
            if (!empty($file['error']) && intval($file['error']) !== UPLOAD_ERR_OK) {
                $upload_error = intval($file['error']);
                if ($upload_error === UPLOAD_ERR_INI_SIZE || $upload_error === UPLOAD_ERR_FORM_SIZE) {
                    return __('File exceeds the maximum upload size for this site.', 'superb-blocks');
                }
                return __('File upload failed.', 'superb-blocks');
            }

            // Unconditional deny-list: reject dangerous extensions regardless of the
            // per-field accept whitelist. Catches misconfigured accept[] entries and
            // double-extension filenames (e.g. shell.php.jpg) before any further check.
            if (!empty($file['name']) && self::HasDangerousExtension($file['name'])) {
                return __('File type is not allowed.', 'superb-blocks');
            }

            // Validate size
            if (isset($file['size']) && $file['size'] > $max_bytes) {
                /* translators: %s: maximum file size in megabytes */
                return sprintf(__('File size exceeds %sMB.', 'superb-blocks'), $max_file_size);
            }

            // Validate extension against the master list and the field whitelist
            if (!empty($file['name'])) {
                $ext = '.' . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                // Master list: extension must be a known allowed type
                // (filterable via superbaddons_form_allowed_file_types)
                if (!isset($allowed_mimes[substr($ext, 1)])) {
                    return __('File type is not allowed.', 'superb-blocks');
                }

                if (!empty($accept)) {
                    $allowed = false;
                    foreach ($accept as $accepted) {
                        // Accept list entries are like ".jpg", ".pdf", etc.
                        if (strtolower(trim($accepted)) === $ext) {
                            $allowed = true;
                            break;
                        }
                    }
                    if (!$allowed) {
                        return __('File type is not allowed.', 'superb-blocks');
                    }
                }
            }

            // Additional MIME validation using WordPress, restricted to the master list
            if (!empty($file['tmp_name']) && !empty($file['name'])) {
                $wp_filetype = wp_check_filetype($file['name'], $allowed_mimes);
                if (empty($wp_filetype['ext']) || empty($wp_filetype['type'])) {
                    return __('File type is not allowed.', 'superb-blocks');
                }
            }
        }

        return '';
    }

    /**
     * Process and store uploaded files for a submission.
     *
     * @param array $form_fields_config Array of field config arrays
     * @return array fieldId => array of file metadata arrays
     */
    public static function ProcessUploads($form_fields_config)
    {
        // Nonce verified upstream by FormController::SubmitCallback before this method runs; presence check only, no value processed here.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (empty($_FILES['files'])) {
            return array();
        }

        require_once(ABSPATH . 'wp-admin/includes/file.php');

        $result = array();

        // Build lookup for file field configs
        $file_field_configs = array();
        foreach ($form_fields_config as $fc) {
            $ftype = isset($fc['fieldType']) ? $fc['fieldType'] : '';
            $fid = isset($fc['fieldId']) ? $fc['fieldId'] : '';
            if ($ftype === 'file' && $fid !== '') {
                $file_field_configs[$fid] = $fc;
            }
        }

        $allowed_mimes = self::GetAllowedMimes();
        $upload_subdir = self::GetUploadSubdir();

        foreach ($file_field_configs as $field_id => $config) {
            $files = self::GetUploadedFiles($field_id);
            if (empty($files)) {
                continue;
            }

            $field_files = array();
            foreach ($files as $file) {
                if (empty($file['tmp_name']) || intval($file['error']) !== UPLOAD_ERR_OK) {
                    continue;
                }

                // Hook into upload_dir to redirect to our protected directory
                $dir_filter = function ($dirs) use ($upload_subdir) {
                    $subdir = '/' . $upload_subdir . $dirs['subdir'];
                    $dirs['subdir'] = $subdir;
                    $dirs['path'] = $dirs['basedir'] . $subdir;
                    $dirs['url'] = $dirs['baseurl'] . $subdir;
                    return $dirs;
                };
                add_filter('upload_dir', $dir_filter);

                // Ensure protected directory exists
                self::EnsureUploadDir();

                $uploaded = wp_handle_upload($file, array(
                    'test_form' => false,
                    'action' => 'superb_form_upload',
                    'mimes' => $allowed_mimes,
                    // Random per-file suffix: makes stored URLs unguessable on
                    // servers where the directory deny rules do not apply
                    // (Nginx), and removes wp_unique_filename()'s
                    // check-then-move race where two concurrent submissions
                    // uploading the same filename could silently overwrite
                    // each other. The original name is kept in the submission
                    // metadata and restored on download via Content-Disposition.
                    'unique_filename_callback' => array(__CLASS__, 'GenerateStoredFilename'),
                ));

                remove_filter('upload_dir', $dir_filter);

                if (!empty($uploaded['file'])) {
                    $field_files[] = array(
                        'name' => sanitize_file_name($file['name']),
                        'path' => $uploaded['file'],
                        'url' => isset($uploaded['url']) ? $uploaded['url'] : '',
                        'type' => isset($uploaded['type']) ? $uploaded['type'] : '',
                        'size' => $file['size'],
                    );
                }
            }

            if (!empty($field_files)) {
                $result[$field_id] = $field_files;

                /**
                 * Fires after files are uploaded for a form field.
                 *
                 * @param string $field_id The field ID.
                 * @param array $field_files Array of file metadata.
                 * @param array $config Field configuration.
                 */
                do_action('superbaddons_form_after_upload', $field_id, $field_files, $config);
            }
        }

        return $result;
    }

    /**
     * unique_filename_callback for wp_handle_upload: append a random suffix
     * to the stored filename. wp_unique_filename passes $name as the full
     * sanitized basename including the extension, and $ext as '.pdf' style.
     *
     * @param string $dir Target directory
     * @param string $name Sanitized basename including extension
     * @param string $ext Extension with leading dot, '' when none
     * @return string
     */
    public static function GenerateStoredFilename($dir, $name, $ext)
    {
        $base = $name;
        if ($ext !== '' && substr($name, strlen($name) - strlen($ext)) === $ext) {
            $base = substr($name, 0, strlen($name) - strlen($ext));
        }
        if ($base === '') {
            $base = 'file';
        }

        // Keep the stored name inside every real-world limit: 255 bytes per
        // path component on ext4/XFS/NTFS, ~143 bytes on eCryptfs, and
        // Windows' 260-character full-path cap. Neither sanitize_file_name()
        // nor wp_unique_filename() truncates, and an overlong name would make
        // the move inside wp_handle_upload fail after validation has already
        // passed. 48 chars is at most 192 bytes of UTF-8;
        $base = mb_substr($base, 0, 48);

        $attempts = 0;
        do {
            $suffix = strtolower(wp_generate_password(12, false, false));
            $filename = $base . '-' . $suffix . $ext;
            $attempts++;
        } while ($attempts < 3 && file_exists(trailingslashit($dir) . $filename));

        return $filename;
    }

    /**
     * Resolve a stored upload path and confirm it lives inside the plugin's
     * form upload directory (legacy or tokenized): the same confinement
     * ServeFile() applies before streaming a single file, for callers that
     * read many files in one request (ZIP downloads).
     *
     * @param string $path Absolute path from submission file metadata.
     * @return string Real path when the file exists, is a readable regular
     *                file and is confined; '' otherwise.
     */
    public static function ConfineUploadPath($path)
    {
        // Resolve the current directory first: on the very first touch this
        // mints the token and migrates the legacy directory, which would
        // otherwise move the file out from under a path resolved earlier.
        self::GetUploadSubdir();
        $upload_dir = wp_upload_dir();

        $path = self::ResolveStoredPath($path);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return '';
        }
        $real_path = realpath($path);
        $real_uploads = realpath($upload_dir['basedir']);
        if ($real_path === false || $real_uploads === false || strpos($real_path, $real_uploads . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }
        $segments = explode(DIRECTORY_SEPARATOR, substr($real_path, strlen($real_uploads) + 1));
        if (count($segments) < 2 || !preg_match('/^' . preg_quote(self::UPLOAD_SUBDIR, '/') . '(-[a-z0-9]{8,64})?$/', $segments[0])) {
            return '';
        }
        return $real_path;
    }

    /**
     * Delete all files associated with a submission's field data.
     *
     * @param array $fields Submission fields (field_id => value)
     */
    public static function DeleteSubmissionFiles($fields)
    {
        if (!is_array($fields)) {
            return;
        }

        foreach ($fields as $value) {
            // File fields store an array of file metadata
            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $file) {
                if (!is_array($file) || !isset($file['path'])) {
                    continue;
                }
                $path = self::ResolveStoredPath($file['path']);
                if ($path !== '' && file_exists($path)) {
                    wp_delete_file($path);
                }
            }
        }
    }

    /**
     * Delete the plugin's form upload directories entirely: all remaining
     * files (including any orphaned by crashed requests), the
     * .htaccess/web.config/index.php scaffolding, and the empty year/month
     * subdirectories. Called from plugin reset after every stored submission
     * has been deleted; both the current tokenized directory and the legacy
     * unhashed directory are removed. Reads the token option directly so no
     * new token is minted when none exists.
     */
    public static function DeleteUploadDirectories()
    {
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            WP_Filesystem();
        }
        if (empty($wp_filesystem)) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $bases = array($upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR);
        $token = get_option(self::UPLOAD_DIR_TOKEN_OPTION);
        if (is_string($token) && preg_match('/^[a-z0-9]{8,64}$/', $token)) {
            $bases[] = $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR . '-' . $token;
        }

        foreach ($bases as $base) {
            if (is_dir($base)) {
                $wp_filesystem->delete($base, true);
            }
        }
    }

    /**
     * Serve a file from the protected upload directory.
     * Streams the file with appropriate headers and exits.
     *
     * @param string $file_path Absolute path to the file
     * @param string $original_name Original file name for download
     * @param string $mime_type MIME type
     */
    public static function ServeFile($file_path, $original_name, $mime_type)
    {
        // Resolve the current directory before touching the stored path: on
        // the very first touch this mints the token and migrates the legacy
        // directory, which would otherwise move the file out from under a
        // path resolved earlier in this request.
        $upload_dir = wp_upload_dir();
        $current_base = $upload_dir['basedir'] . '/' . self::GetUploadSubdir();

        // Stored paths may predate the randomized upload directory
        $file_path = self::ResolveStoredPath($file_path);
        if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('File not found.', 'superb-blocks'),
            ), 404);
        }

        // Ensure file is within one of our upload directories directly under
        // the uploads basedir: the unhashed directory for files stored before
        // the randomized directory token was introduced, or any tokenized
        // variant. Accepting the token pattern instead of only the current
        // token keeps files servable even if the token option is ever lost
        // and re-minted, which would otherwise strand every earlier upload
        // under the retired directory. Paths come exclusively from submission
        // meta, so this does not widen what a request can reach. Segment-wise
        // comparison keeps the check exact, so a sibling directory that
        // merely starts with the base name can never pass.
        $allowed = false;
        $real_path = realpath($file_path);
        $real_uploads = realpath($upload_dir['basedir']);
        if ($real_path !== false && $real_uploads !== false && strpos($real_path, $real_uploads . DIRECTORY_SEPARATOR) === 0) {
            $segments = explode(DIRECTORY_SEPARATOR, substr($real_path, strlen($real_uploads) + 1));
            if (count($segments) > 1 && preg_match('/^' . preg_quote(self::UPLOAD_SUBDIR, '/') . '(-[a-z0-9]{8,64})?$/', $segments[0])) {
                $allowed = true;
            }
        }

        if (!$allowed) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Access denied.', 'superb-blocks'),
            ), 403);
        }

        // A concurrent request performing the one-time legacy-directory
        // migration can move the file between the checks above and the
        // stream below. Re-resolve and re-confine (the migrated location can
        // only be the current randomized directory) instead of streaming a
        // dead path.
        if (!file_exists($file_path)) {
            $file_path = self::ResolveStoredPath($file_path);
            $real_path = $file_path !== '' ? realpath($file_path) : false;
            $real_base = realpath($current_base);
            if ($real_path === false || $real_base === false || strpos($real_path, $real_base . DIRECTORY_SEPARATOR) !== 0) {
                return new \WP_REST_Response(array(
                    'success' => false,
                    'message' => __('File not found.', 'superb-blocks'),
                ), 404);
            }
        }

        $safe_name = str_replace(array('"', "\r", "\n"), '', sanitize_file_name($original_name));
        header('Content-Type: ' . $mime_type);
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $safe_name . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: no-store, no-cache, must-revalidate');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile($file_path);
        // Plain exit, not wp_die(): the die handler appends an HTML error
        // page skeleton after the streamed bytes.
        exit;
    }

    /**
     * Check if a filename has a dangerous extension in any dot-separated segment.
     * Catches single (shell.php), double-extension (shell.php.jpg), and dotfile
     * (.htaccess) cases. Server-config and active-content extensions are denied
     * because the upload subdir is web-reachable on IIS/Nginx where .htaccess is
     * ignored, so a stored .html or .svg could host an XSS payload even though
     * wp_handle_upload would not save a .php file under an unauthenticated request.
     *
     * @param string $filename
     * @return bool
     */
    private static function HasDangerousExtension($filename)
    {
        static $deny = array(
            // PHP and PHP-handler variants
            'php',
            'php3',
            'php4',
            'php5',
            'php7',
            'php8',
            'phtml',
            'pht',
            'phar',
            'phps',
            // Other server-side scripting
            'cgi',
            'pl',
            'py',
            'rb',
            'jsp',
            'jspx',
            'asp',
            'aspx',
            'cer',
            'cfm',
            'shtml',
            // Executables and shells
            'exe',
            'msi',
            'sh',
            'bat',
            'cmd',
            'com',
            'vb',
            'vbs',
            'wsh',
            // Server config
            'htaccess',
            'htpasswd',
            'ini',
            'env',
            // Active web content (inline XSS if directly accessible)
            'html',
            'htm',
            'xhtml',
            'svg',
            'svgz',
            'js',
            'mjs',
            'xml',
        );

        $parts = explode('.', strtolower($filename));
        array_shift($parts); // skip basename, only inspect dot-segments
        foreach ($parts as $part) {
            if ($part !== '' && in_array($part, $deny, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get uploaded files for a specific field ID from $_FILES.
     * Normalizes the PHP $_FILES array for multiple files.
     *
     * @param string $field_id
     * @return array Array of file arrays (name, type, tmp_name, error, size)
     */
    private static function GetUploadedFiles($field_id)
    {
        // Nonce verified upstream by FormController::SubmitCallback before any caller of this method runs.
        // PHP guarantees the parallel name/type/tmp_name/error/size keys exist together when $_FILES['files']['name'][$field_id] is set, so checking the others would be redundant.
        // Raw $_FILES values are consumed downstream by wp_handle_upload() (which applies WP's standard upload sanitization) and sanitize_file_name() at storage time; running sanitize_text_field on tmp_name/size/error here would corrupt the values wp_handle_upload expects.
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (empty($_FILES['files']) || !isset($_FILES['files']['name'][$field_id])) {
            return array();
        }

        $files = array();
        $names = $_FILES['files']['name'][$field_id];
        $types = $_FILES['files']['type'][$field_id];
        $tmp_names = $_FILES['files']['tmp_name'][$field_id];
        $errors = $_FILES['files']['error'][$field_id];
        $sizes = $_FILES['files']['size'][$field_id];
        // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // Normalize: could be a single file or array of files
        if (is_array($names)) {
            for ($i = 0; $i < count($names); $i++) {
                if (empty($names[$i])) {
                    continue;
                }
                $files[] = array(
                    'name' => $names[$i],
                    'type' => $types[$i],
                    'tmp_name' => $tmp_names[$i],
                    'error' => $errors[$i],
                    'size' => $sizes[$i],
                );
            }
        } else {
            if (!empty($names)) {
                $files[] = array(
                    'name' => $names,
                    'type' => $types,
                    'tmp_name' => $tmp_names,
                    'error' => $errors,
                    'size' => $sizes,
                );
            }
        }

        return $files;
    }

    /**
     * Ensure the protected upload directory exists with .htaccess and index.php.
     */
    private static function EnsureUploadDir()
    {
        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'] . '/' . self::GetUploadSubdir();

        // Create base directory if needed
        if (!is_dir($base_path)) {
            wp_mkdir_p($base_path);
        }

        // Use WP_Filesystem for file writes (required by plugin review guidelines)
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            WP_Filesystem();
        }

        // WP_Filesystem() can fail to initialize (e.g. a non-direct method
        // needing credentials that are not stored); this runs during visitor
        // form submissions, so skip the scaffolding instead of fataling on a
        // null object. FS_CHMOD_FILE is also only defined after successful
        // initialization. The deny rules are defense-in-depth on top of the
        // randomized directory name and filenames.
        if (!empty($wp_filesystem)) {
            // Create .htaccess to deny direct access (Apache)
            $htaccess = $base_path . '/.htaccess';
            if (!file_exists($htaccess)) {
                $wp_filesystem->put_contents($htaccess, "# Deny direct access to uploaded form files\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n", FS_CHMOD_FILE);
            }

            // Create web.config to deny direct access (IIS). accessPolicy="None"
            // strips Read/Script/Execute so any request to this dir returns 403,
            // mirroring the Apache "Require all denied" posture above.
            $webconfig = $base_path . '/web.config';
            if (!file_exists($webconfig)) {
                $wp_filesystem->put_contents($webconfig, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <handlers accessPolicy=\"None\" />\n  </system.webServer>\n</configuration>\n", FS_CHMOD_FILE);
            }

            // Create index.php to prevent directory listing
            $index = $base_path . '/index.php';
            if (!file_exists($index)) {
                $wp_filesystem->put_contents($index, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE);
            }
        }

        // Also protect the current year/month subdirectory
        $current_path = $upload_dir['path'];
        if (strpos($current_path, $base_path) === 0 && !is_dir($current_path)) {
            wp_mkdir_p($current_path);
        }
    }
}
