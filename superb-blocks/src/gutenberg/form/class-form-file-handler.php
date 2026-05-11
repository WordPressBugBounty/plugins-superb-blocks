<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormFileHandler
{
    const UPLOAD_SUBDIR = 'superb-addons-forms';

    /**
     * Validate uploaded files for a field against its config.
     * Called by FormFieldValidator before files are processed.
     *
     * @param array $field_config Field configuration from server-side config
     * @return string Error message, empty if valid
     */
    public static function ValidateFiles($field_config)
    {
        $field_id = isset($field_config['fieldId']) ? $field_config['fieldId'] : '';
        $required = !empty($field_config['required']);
        $fs = isset($field_config['fileSettings']) && is_array($field_config['fileSettings']) ? $field_config['fileSettings'] : array();
        $max_file_size = isset($fs['maxFileSize']) ? floatval($fs['maxFileSize']) : 5;
        $multiple = !empty($fs['multiple']);
        $max_files = isset($fs['maxFiles']) ? intval($fs['maxFiles']) : 5;
        $accept = isset($fs['accept']) && is_array($fs['accept']) ? $fs['accept'] : array();

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
                return __('This field is required.', 'superb-blocks');
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
        foreach ($files as $file) {
            // Check for upload errors
            if (!empty($file['error']) && intval($file['error']) !== UPLOAD_ERR_OK) {
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

            // Validate MIME type / extension
            if (!empty($accept) && !empty($file['name'])) {
                $ext = '.' . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
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

            // Additional MIME validation using WordPress
            if (!empty($file['tmp_name']) && !empty($file['name'])) {
                $wp_filetype = wp_check_filetype($file['name']);
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
                $dir_filter = function ($dirs) {
                    $subdir = '/' . self::UPLOAD_SUBDIR . $dirs['subdir'];
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
                if (is_array($file) && isset($file['path']) && file_exists($file['path'])) {
                    wp_delete_file($file['path']);
                }
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
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('File not found.', 'superb-blocks'),
            ), 404);
        }

        // Ensure file is within our upload directory
        $upload_dir = wp_upload_dir();
        $allowed_base = $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR;
        $real_path = realpath($file_path);
        $real_base = realpath($allowed_base);

        if ($real_path === false || $real_base === false || strpos($real_path, $real_base) !== 0) {
            return new \WP_REST_Response(array(
                'success' => false,
                'message' => __('Access denied.', 'superb-blocks'),
            ), 403);
        }

        $safe_name = str_replace(array('"', "\r", "\n"), '', sanitize_file_name($original_name));
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $safe_name . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: no-store, no-cache, must-revalidate');

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        readfile($file_path);
        wp_die();
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
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps',
            // Other server-side scripting
            'cgi', 'pl', 'py', 'rb', 'jsp', 'jspx', 'asp', 'aspx', 'cer', 'cfm', 'shtml',
            // Executables and shells
            'exe', 'msi', 'sh', 'bat', 'cmd', 'com', 'vb', 'vbs', 'wsh',
            // Server config
            'htaccess', 'htpasswd', 'ini', 'env',
            // Active web content (inline XSS if directly accessible)
            'html', 'htm', 'xhtml', 'svg', 'svgz', 'js', 'mjs', 'xml',
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
        $base_path = $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR;

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

        // Also protect the current year/month subdirectory
        $current_path = $upload_dir['path'];
        if (strpos($current_path, $base_path) === 0 && !is_dir($current_path)) {
            wp_mkdir_p($current_path);
        }
    }
}
