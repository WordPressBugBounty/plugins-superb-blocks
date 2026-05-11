<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormFieldValidator
{
    /**
     * Validate submitted fields against server-side form config.
     *
     * @param array $submitted_fields field_id => value from submission
     * @param array $form_fields_config Array of field attribute arrays from form config
     * @return array ('fields' => cleaned fields, 'errors' => field_id => error message)
     */
    public static function Validate($submitted_fields, $form_fields_config)
    {
        // Build lookup: fieldId => config
        $config_lookup = array();
        foreach ($form_fields_config as $field_def) {
            if (isset($field_def['fieldId']) && $field_def['fieldId'] !== '') {
                $config_lookup[$field_def['fieldId']] = $field_def;
            }
        }

        $cleaned = array();
        $errors = array();

        // Only accept known field IDs
        foreach ($submitted_fields as $key => $value) {
            if (isset($config_lookup[$key])) {
                $cleaned[$key] = $value;
            }
        }

        // Validate each configured field
        foreach ($config_lookup as $field_id => $field_config) {
            $field_type = isset($field_config['fieldType']) ? $field_config['fieldType'] : 'text';

            // Hidden fields: override with server-side default, skip validation
            if ($field_type === 'hidden') {
                $cleaned[$field_id] = isset($field_config['defaultValue']) ? $field_config['defaultValue'] : '';
                continue;
            }

            // File fields: validate via FormFileHandler (separate from text validation)
            if ($field_type === 'file') {
                $file_error = FormFileHandler::ValidateFiles($field_config);
                if ($file_error !== '') {
                    $errors[$field_id] = $file_error;
                }
                // Remove file field from text fields — files are handled separately
                unset($cleaned[$field_id]);
                continue;
            }

            $value = isset($cleaned[$field_id]) ? $cleaned[$field_id] : '';
            $required = !empty($field_config['required']);

            // Conditional logic: if field has active rules and was not submitted, skip required check
            if ($required && $value === '' && self::HasConditionalLogic($field_config)) {
                continue;
            }

            $error = self::ValidateField($value, $field_config);

            /**
             * Filter the validation error for a field.
             * Allows premium plugin to add custom validation for new field types.
             *
             * @param string $error Error message, empty if valid.
             * @param string $value Submitted field value.
             * @param array $field_config Field configuration from server-side config.
             */
            $error = apply_filters('superbaddons_form_validate_field', $error, $value, $field_config);

            if ($error !== '') {
                $errors[$field_id] = $error;
            }
        }

        return array(
            'fields' => $cleaned,
            'errors' => $errors,
        );
    }

    /**
     * Validate a single field value against its config.
     *
     * @param string $value Submitted value
     * @param array $config Field config array
     * @return string Error message, empty if valid
     */
    private static function ValidateField($value, $config)
    {
        $field_type = isset($config['fieldType']) ? $config['fieldType'] : 'text';
        $required = !empty($config['required']);

        // Required check
        if ($required && ($value === '' || $value === null)) {
            return __('This field is required.', 'superb-blocks');
        }

        // Skip further validation if empty and not required
        if ($value === '' || $value === null) {
            return '';
        }

        // Dispatch by field type
        switch ($field_type) {
            case 'text':
            case 'phone':
                return self::ValidateTextLike($value, $config);

            case 'email':
                return self::ValidateEmail($value, $config);

            case 'url':
                return self::ValidateUrl($value, $config);

            case 'textarea':
                return self::ValidateTextarea($value, $config);

            case 'number':
                return self::ValidateNumber($value, $config);

            case 'select':
            case 'radio':
                return self::ValidateSingleOption($value, $config);

            case 'checkbox':
                return self::ValidateCheckbox($value, $config);

            case 'date':
                return self::ValidateDate($value, $config);

            case 'time':
                return self::ValidateTime($value, $config);

            case 'rating':
                return self::ValidateRating($value, $config);

            case 'calculated':
                // Calculated fields are validated by server-side recalculation (see FormController)
                return '';

            case 'slider':
                return self::ValidateSlider($value, $config);

            case 'signature':
                return self::ValidateSignature($value);

            case 'colorpicker':
                return self::ValidateColor($value);

            default:
                return '';
        }
    }

    /**
     * Validate text-like fields (text, phone): length + pattern.
     */
    private static function ValidateTextLike($value, $config)
    {
        $error = self::CheckLength($value, $config);
        if ($error !== '') {
            return $error;
        }

        return self::CheckPattern($value, $config);
    }

    /**
     * Validate email: length + pattern + is_email().
     */
    private static function ValidateEmail($value, $config)
    {
        $error = self::CheckLength($value, $config);
        if ($error !== '') {
            return $error;
        }

        $error = self::CheckPattern($value, $config);
        if ($error !== '') {
            return $error;
        }

        if (!is_email($value)) {
            return __('Please enter a valid email address.', 'superb-blocks');
        }

        return '';
    }

    /**
     * Validate URL: length + pattern + esc_url_raw check.
     */
    private static function ValidateUrl($value, $config)
    {
        $error = self::CheckLength($value, $config);
        if ($error !== '') {
            return $error;
        }

        $error = self::CheckPattern($value, $config);
        if ($error !== '') {
            return $error;
        }

        if (esc_url_raw($value) === '') {
            return __('Please enter a valid URL.', 'superb-blocks');
        }

        return '';
    }

    /**
     * Validate textarea: length only (no pattern per field type spec).
     */
    private static function ValidateTextarea($value, $config)
    {
        return self::CheckLength($value, $config);
    }

    /**
     * Validate number: is_numeric + min/max value.
     */
    private static function ValidateNumber($value, $config)
    {
        if (!is_numeric($value)) {
            return __('Please enter a valid number.', 'superb-blocks');
        }

        $num = floatval($value);

        $min = isset($config['minValue']) ? $config['minValue'] : null;
        $max = isset($config['maxValue']) ? $config['maxValue'] : null;

        if ($min !== null && $min !== '' && $num < floatval($min)) {
            /* translators: %s: minimum allowed numeric value */
            return sprintf(__('Minimum value is %s.', 'superb-blocks'), $min);
        }

        if ($max !== null && $max !== '' && $num > floatval($max)) {
            /* translators: %s: maximum allowed numeric value */
            return sprintf(__('Maximum value is %s.', 'superb-blocks'), $max);
        }

        return '';
    }

    /**
     * Validate select/radio: value must be in configured options.
     */
    private static function ValidateSingleOption($value, $config)
    {
        $options = isset($config['options']) && is_array($config['options']) ? $config['options'] : array();
        $allowed = array();
        foreach ($options as $opt) {
            if (isset($opt['value'])) {
                $allowed[] = $opt['value'];
            }
        }

        if (!in_array($value, $allowed, true)) {
            return __('Invalid selection.', 'superb-blocks');
        }

        return '';
    }

    /**
     * Validate checkbox: comma-separated values, each must be in configured options.
     */
    private static function ValidateCheckbox($value, $config)
    {
        $options = isset($config['options']) && is_array($config['options']) ? $config['options'] : array();
        $allowed = array();
        foreach ($options as $opt) {
            if (isset($opt['value'])) {
                $allowed[] = $opt['value'];
            }
        }

        // Client sends checkbox values as "Value A, Value B"
        $selected = array_map('trim', explode(',', $value));
        foreach ($selected as $sel) {
            if ($sel !== '' && !in_array($sel, $allowed, true)) {
                return __('Invalid selection.', 'superb-blocks');
            }
        }

        return '';
    }

    /**
     * Validate date: format + constraints (future/past/range) + excludeDays.
     */
    private static function ValidateDate($value, $config)
    {
        // Validate format YYYY-MM-DD
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return __('Invalid date format.', 'superb-blocks');
        }

        $parts = explode('-', $value);
        if (!checkdate(intval($parts[1]), intval($parts[2]), intval($parts[0]))) {
            return __('Invalid date.', 'superb-blocks');
        }

        $ds = isset($config['dateSettings']) && is_array($config['dateSettings']) ? $config['dateSettings'] : array();
        $constraint = isset($ds['dateConstraint']) ? $ds['dateConstraint'] : 'none';
        if ($constraint === 'none') {
            return '';
        }

        $custom_msg = isset($ds['dateTimeMessage']) && $ds['dateTimeMessage'] !== ''
            ? $ds['dateTimeMessage']
            : '';

        // Resolve min/max dates using site timezone (matches client behavior)
        $today = current_time('Y-m-d');
        $min_date = null;
        $max_date = null;

        if ($constraint === 'future') {
            $min_date = $today;
        } elseif ($constraint === 'past') {
            $max_date = $today;
        } elseif ($constraint === 'range') {
            $resolved = self::ResolveDateRange($ds, $today);
            $min_date = $resolved['min'];
            $max_date = $resolved['max'];
        }

        if ($min_date !== null && $value < $min_date) {
            if ($custom_msg !== '') {
                return $custom_msg;
            }
            /* translators: %s: earliest allowed date in Y-m-d format */
            return sprintf(__('Please select a date on or after %s.', 'superb-blocks'), $min_date);
        }

        if ($max_date !== null && $value > $max_date) {
            if ($custom_msg !== '') {
                return $custom_msg;
            }
            /* translators: %s: latest allowed date in Y-m-d format */
            return sprintf(__('Please select a date on or before %s.', 'superb-blocks'), $max_date);
        }

        // Exclude days
        $exclude_days = isset($ds['excludeDays']) && is_array($ds['excludeDays']) ? $ds['excludeDays'] : array();
        if (!empty($exclude_days)) {
            $timestamp = strtotime($value . ' UTC');
            if ($timestamp !== false) {
                $day_of_week = intval(gmdate('w', $timestamp)); // 0 = Sunday
                if (in_array($day_of_week, array_map('intval', $exclude_days), true)) {
                    if ($custom_msg !== '') {
                        return $custom_msg;
                    }
                    return __('The selected day of the week is not allowed.', 'superb-blocks');
                }
            }
        }

        return '';
    }

    /**
     * Resolve min/max date for range constraint.
     * Mirrors JS initDateConstraints() in form.js.
     *
     * @param array $config Field config
     * @param string $today Today's date in Y-m-d
     * @return array ('min' => string|null, 'max' => string|null)
     */
    private static function ResolveDateRange($config, $today)
    {
        $min_date_mode = isset($config['minDateMode']) ? $config['minDateMode'] : 'none';
        $max_date_mode = isset($config['maxDateMode']) ? $config['maxDateMode'] : 'none';
        $min_date = null;
        $max_date = null;

        // First pass: resolve independent dates (specific and relative)
        if ($min_date_mode === 'specific') {
            $min_date = isset($config['minDate']) && $config['minDate'] !== '' ? $config['minDate'] : null;
        } elseif ($min_date_mode === 'relative') {
            $offset = isset($config['minDateOffset']) ? intval($config['minDateOffset']) : 0;
            $min_date = self::OffsetDate($today, $offset);
        }

        if ($max_date_mode === 'specific') {
            $max_date = isset($config['maxDate']) && $config['maxDate'] !== '' ? $config['maxDate'] : null;
        } elseif ($max_date_mode === 'relative') {
            $offset = isset($config['maxDateOffset']) ? intval($config['maxDateOffset']) : 0;
            $max_date = self::OffsetDate($today, $offset);
        }

        // Second pass: resolve offsets that depend on the other date
        if ($max_date_mode === 'offset') {
            $offset = isset($config['maxDateOffset']) ? intval($config['maxDateOffset']) : 0;
            $base = $min_date !== null ? $min_date : $today;
            $max_date = self::OffsetDate($base, $offset);
        }

        if ($min_date_mode === 'offset') {
            $offset = isset($config['minDateOffset']) ? intval($config['minDateOffset']) : 0;
            $base = $max_date !== null ? $max_date : $today;
            $min_date = self::OffsetDate($base, -$offset);
        }

        return array('min' => $min_date, 'max' => $max_date);
    }

    /**
     * Add days offset to a date string.
     *
     * @param string $date Y-m-d format
     * @param int $days Number of days (can be negative)
     * @return string Y-m-d
     */
    private static function OffsetDate($date, $days)
    {
        $sign = $days >= 0 ? '+' : '';
        $ts = strtotime($date . ' UTC ' . $sign . $days . ' days');
        return $ts !== false ? gmdate('Y-m-d', $ts) : $date;
    }

    /**
     * Validate time: format + min/max constraints.
     */
    private static function ValidateTime($value, $config)
    {
        // Validate format HH:MM
        if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
            return __('Invalid time format.', 'superb-blocks');
        }

        $ts = isset($config['timeSettings']) && is_array($config['timeSettings']) ? $config['timeSettings'] : array();

        $custom_msg = isset($ts['dateTimeMessage']) && $ts['dateTimeMessage'] !== ''
            ? $ts['dateTimeMessage']
            : '';

        $min_time = isset($ts['minTime']) && $ts['minTime'] !== '' ? $ts['minTime'] : null;
        $max_time = isset($ts['maxTime']) && $ts['maxTime'] !== '' ? $ts['maxTime'] : null;

        // HH:MM string comparison is lexicographically correct
        if ($min_time !== null && $value < $min_time) {
            if ($custom_msg !== '') {
                return $custom_msg;
            }
            /* translators: %s: earliest allowed time in HH:MM format */
            return sprintf(__('Please select a time at or after %s.', 'superb-blocks'), $min_time);
        }

        if ($max_time !== null && $value > $max_time) {
            if ($custom_msg !== '') {
                return $custom_msg;
            }
            /* translators: %s: latest allowed time in HH:MM format */
            return sprintf(__('Please select a time at or before %s.', 'superb-blocks'), $max_time);
        }

        return '';
    }

    /**
     * Validate signature: must be a valid PNG data URL within size limit.
     */
    private static function ValidateSignature($value)
    {
        // Max 500KB
        if (strlen($value) > 500000) {
            return __('Signature data is too large.', 'superb-blocks');
        }

        // Must start with PNG data URL prefix
        $prefix = 'data:image/png;base64,';
        if (strpos($value, $prefix) !== 0) {
            return __('Invalid signature format.', 'superb-blocks');
        }

        // Validate base64 portion
        $base64 = substr($value, strlen($prefix));
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $base64)) {
            return __('Invalid signature data.', 'superb-blocks');
        }

        return '';
    }

    /**
     * Validate slider: single numeric value within bounds.
     */
    private static function ValidateSlider($value, $config)
    {
        $ss = isset($config['sliderSettings']) && is_array($config['sliderSettings']) ? $config['sliderSettings'] : array();
        $min = isset($ss['min']) ? floatval($ss['min']) : 0;
        $max = isset($ss['max']) ? floatval($ss['max']) : 100;

        if (!is_numeric($value)) {
            return __('Please enter a valid number.', 'superb-blocks');
        }

        $num = floatval($value);
        if ($num < $min || $num > $max) {
            /* translators: 1: minimum allowed value, 2: maximum allowed value */
            return sprintf(__('Value must be between %1$s and %2$s.', 'superb-blocks'), $min, $max);
        }

        return '';
    }

    /**
     * Validate color: must be a valid hex color.
     */
    private static function ValidateColor($value)
    {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return __('Please enter a valid hex color.', 'superb-blocks');
        }

        return '';
    }

    /**
     * Validate rating: must be an integer between 0 and maxRating.
     */
    private static function ValidateRating($value, $config)
    {
        if (!is_numeric($value)) {
            return __('Invalid rating.', 'superb-blocks');
        }

        $num = intval($value);
        if ($num != floatval($value)) {
            // Not an integer
            return __('Invalid rating.', 'superb-blocks');
        }

        $rs = isset($config['ratingSettings']) && is_array($config['ratingSettings']) ? $config['ratingSettings'] : array();
        $max_rating = isset($rs['maxRating']) ? intval($rs['maxRating']) : 5;

        if ($num < 0 || $num > $max_rating) {
            return __('Invalid rating.', 'superb-blocks');
        }

        return '';
    }

    // --- Helper methods ---

    /**
     * Check min/max length constraints.
     */
    private static function CheckLength($value, $config)
    {
        $len = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        $min = isset($config['minLength']) ? $config['minLength'] : null;
        $max = isset($config['maxLength']) ? $config['maxLength'] : null;

        if ($min !== null && $min !== '' && $len < intval($min)) {
            /* translators: %d: minimum required character count */
            return sprintf(__('Minimum length is %d characters.', 'superb-blocks'), $min);
        }

        if ($max !== null && $max !== '' && $len > intval($max)) {
            /* translators: %d: maximum allowed character count */
            return sprintf(__('Maximum length is %d characters.', 'superb-blocks'), $max);
        }

        return '';
    }

    /**
     * Check regex pattern constraint.
     */
    private static function CheckPattern($value, $config)
    {
        $pattern = isset($config['pattern']) && $config['pattern'] !== '' ? $config['pattern'] : null;
        if ($pattern === null) {
            return '';
        }

        // Wrap pattern in delimiters for PCRE, using '/' with escaping
        $regex = '/' . str_replace('/', '\\/', $pattern) . '/';

        // Guard against ReDoS from user-supplied patterns by tightening PCRE's backtrack limit around this single preg_match call. ini_set is intentional: there is no WP-API equivalent for runtime PCRE limits, the override is reverted immediately after, and the limit is per-process rather than persistent.
        $old_limit = ini_get('pcre.backtrack_limit');
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        ini_set('pcre.backtrack_limit', 10000);
        $result = @preg_match($regex, $value);
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
        ini_set('pcre.backtrack_limit', $old_limit);

        // If regex is invalid ($result === false) or doesn't match ($result === 0)
        if ($result === 0) {
            $msg = isset($config['patternMessage']) && $config['patternMessage'] !== ''
                ? $config['patternMessage']
                : __('Invalid format.', 'superb-blocks');
            return $msg;
        }

        // Invalid regex or match: pass
        return '';
    }

    /**
     * Check if a field has active conditional logic rules.
     */
    private static function HasConditionalLogic($config)
    {
        if (!isset($config['conditionalLogic']) || !is_array($config['conditionalLogic'])) {
            return false;
        }

        $logic = $config['conditionalLogic'];
        if (!isset($logic['ruleGroups']) || !is_array($logic['ruleGroups'])) {
            return false;
        }

        foreach ($logic['ruleGroups'] as $group) {
            if (isset($group['conditions']) && is_array($group['conditions'])) {
                foreach ($group['conditions'] as $cond) {
                    if (!empty($cond['field'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
