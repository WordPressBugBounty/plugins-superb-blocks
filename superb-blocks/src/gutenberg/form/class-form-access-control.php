<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormAccessControl
{
    const OPTION_ENABLED = 'superbaddons_form_access_control_enabled';

    // Index key for form blocks without a formId (sanitize_key() never returns a space)
    const UNNAMED_FORMS = ' unnamed';

    // The stored form blocks without a formId not yet matched in the save in progress, and how many form
    // blocks without a formId in the saved content are still to be matched, by block name
    private static $unnamed_forms = array();
    private static $unnamed_wanted = array();

    /**
     * Sensitive form-level attributes that require the 'configure' capability.
     * Everything else is considered "safe" and requires the 'edit' capability.
     */
    private static $sensitive_attrs = array(
        // Email routing
        'emailEnabled',
        'emailTo',
        'emailSubject',
        'emailReplyTo',
        'emailCC',
        'emailBCC',
        // User confirmation
        'sendConfirmation',
        'confirmationSubject',
        'confirmationMessage',
        'confirmationEmailField',
        // Anti-spam
        'captchaType',
        'honeypotKey',
        // Data storage
        'storeEnabled',
        'storeSpamEnabled',
        // Integrations
        'mailchimpEnabled',
        'mailchimpListIds',
        'brevoEnabled',
        'brevoListIds',
        // Webhook (secret stored separately in encrypted option, not as block attribute; a webhookSecret
        // attribute is still moved there on save, see FormRegistry::StoreConfig)
        'webhookSecret',
        'webhookEnabled',
        'webhookUrl',
        'webhookMethod',
        'webhookHeaders',
        // Google Sheets
        'googleSheetsEnabled',
        'googleSheetsSpreadsheetUrl',
        'googleSheetsSheetName',
        // Slack
        'slackEnabled',
        'slackWebhookUrl',
        // Redirects
        'redirectUrl',
        'successBehavior',
    );

    /**
     * Block names that represent form containers.
     */
    private static $form_block_names = array(
        'superb-addons/form',
        'superb-addons/multistep-form',
    );

    public static function Initialize()
    {
        add_filter('wp_insert_post_data', array(__CLASS__, 'OnInsertPostData'), 10, 2);
    }

    /**
     * Whether form access control restrictions are enabled.
     */
    public static function IsEnabled()
    {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    /**
     * Get the list of sensitive attribute keys.
     */
    public static function GetSensitiveAttrs()
    {
        return self::$sensitive_attrs;
    }

    /**
     * Filter: wp_insert_post_data — enforce form access control before content is saved.
     *
     * @param array $data    Sanitized post data about to be inserted.
     * @param array $postarr Raw post data array (includes ID for updates).
     * @return array Possibly corrected $data.
     */
    public static function OnInsertPostData($data, $postarr)
    {
        // Feature must be enabled
        if (!self::IsEnabled()) {
            return $data;
        }

        // Cron or WP-CLI updating a post with no one logged in (a scheduled publish, or republishing a scheduled
        // Rewrite & Republish copy whose content was checked when the copy was saved) isn't a role's edit.
        // Any other save without a user is checked as one with no form permissions.
        $post_id = isset($postarr['ID']) ? intval($postarr['ID']) : 0;
        if (!is_user_logged_in() && FormRegistry::IsSystemUpdate($post_id > 0)) {
            return $data;
        }

        // Skip revisions. Autosaves are checked too: an autosave of the user's own draft writes the post itself.
        $post_type = isset($data['post_type']) ? $data['post_type'] : '';
        if ($post_type === 'revision') {
            return $data;
        }

        // Only process supported post types
        if (!in_array($post_type, FormRegistry::GetSupportedPostTypes(), true)) {
            return $data;
        }

        // Admins always bypass
        if (current_user_can('manage_options')) {
            return $data;
        }

        // wp_insert_post_data passes slashed data and unslashes it after the filter. Parsing the slashed
        // content would fail to decode every block's JSON attributes, so unslash here and re-slash below.
        $content = isset($data['post_content']) ? wp_unslash($data['post_content']) : '';

        // Fast bail: no form blocks in the content
        if (strpos($content, 'superb-addons/form') === false && strpos($content, 'superb-addons/multistep-form') === false) {
            return $data;
        }

        // Resolve capabilities
        $can_edit = FormPermissions::Can('edit');
        $can_configure = FormPermissions::Can('configure');
        $can_create = FormPermissions::Can('create');

        // If user has all capabilities, nothing to enforce
        if ($can_edit && $can_configure && $can_create) {
            return $data;
        }

        // Load old content for comparison (updates only)
        $old_blocks = array();
        if ($post_id > 0) {
            $old_post = get_post($post_id);
            if ($old_post && !empty($old_post->post_content)) {
                $old_blocks = parse_blocks($old_post->post_content);
            }
        }

        // Build lookup of old form blocks by formId for fast access
        $old_form_blocks = self::IndexFormBlocksByFormId($old_blocks);

        // Parse and enforce new content
        $new_blocks = parse_blocks($content);
        self::$unnamed_forms = isset($old_form_blocks[self::UNNAMED_FORMS]) ? $old_form_blocks[self::UNNAMED_FORMS] : array();
        self::$unnamed_wanted = array();
        $new_form_blocks = self::IndexFormBlocksByFormId($new_blocks);
        if (!empty($new_form_blocks[self::UNNAMED_FORMS])) {
            foreach ($new_form_blocks[self::UNNAMED_FORMS] as $unnamed) {
                $type = $unnamed['blockName'];
                self::$unnamed_wanted[$type] = (isset(self::$unnamed_wanted[$type]) ? self::$unnamed_wanted[$type] : 0) + 1;
            }
        }
        $enforced = self::EnforceFormBlocks($new_blocks, $old_form_blocks, $post_id, $can_edit, $can_configure, $can_create);

        // Leave the content untouched unless a form block had to change, so other blocks are never rewritten
        if ($enforced === $new_blocks) {
            return $data;
        }
        $data['post_content'] = wp_slash(serialize_blocks($enforced));

        return $data;
    }

    /**
     * Build a flat lookup of form blocks indexed by formId from a parsed block tree.
     * Form blocks without a formId are listed under UNNAMED_FORMS.
     *
     * @param array $blocks Parsed blocks.
     * @return array formId => block (full block array including innerBlocks/innerContent).
     */
    private static function IndexFormBlocksByFormId($blocks)
    {
        $index = array();
        foreach ($blocks as $block) {
            if (self::IsFormBlock($block)) {
                $form_id = self::GetFormId($block);
                if ($form_id !== '') {
                    $index[$form_id] = $block;
                } else {
                    $index[self::UNNAMED_FORMS][] = $block;
                }
            }
            if (!empty($block['innerBlocks'])) {
                $inner_index = self::IndexFormBlocksByFormId($block['innerBlocks']);
                foreach ($inner_index as $fid => $fblock) {
                    if ($fid === self::UNNAMED_FORMS) {
                        $index[$fid] = array_merge(isset($index[$fid]) ? $index[$fid] : array(), $fblock);
                    } else {
                        $index[$fid] = $fblock;
                    }
                }
            }
        }
        return $index;
    }

    /**
     * A form block's formId, sanitized; '' when it has none.
     */
    private static function GetFormId($block)
    {
        return isset($block['attrs']['formId']) && is_string($block['attrs']['formId']) ? sanitize_key($block['attrs']['formId']) : '';
    }

    /**
     * Check if a parsed block is one of our form container blocks.
     */
    private static function IsFormBlock($block)
    {
        return isset($block['blockName']) && in_array($block['blockName'], self::$form_block_names, true);
    }

    /**
     * Recursively walk blocks, enforcing access control on form blocks.
     *
     * @param array $blocks           Parsed new blocks.
     * @param array $old_form_blocks  Indexed old form blocks (formId => block).
     * @param int   $post_id          The post being saved (0 for a new post).
     * @param bool  $can_edit         User has 'edit' capability.
     * @param bool  $can_configure    User has 'configure' capability.
     * @param bool  $can_create       User has 'create' capability.
     * @param array $removed          Indexes of the blocks removed at this level (by reference).
     * @return array Enforced blocks.
     */
    private static function EnforceFormBlocks($blocks, $old_form_blocks, $post_id, $can_edit, $can_configure, $can_create, &$removed = array())
    {
        $result = array();

        foreach ($blocks as $index => $block) {
            if (self::IsFormBlock($block)) {
                $form_id = self::GetFormId($block);

                if ($form_id === '') {
                    // A form without a formId (a multistep form saved while Premium is inactive) takes no
                    // submissions: check it against the post's own form without a formId; a new one needs 'create'
                    $old_block = self::TakeUnnamedForm($block);
                    if ($old_block !== null) {
                        $block = self::EnforceExistingFormBlock($block, $old_block, $can_edit, $can_configure);
                    } elseif (!$can_create) {
                        $removed[] = $index;
                        continue;
                    }
                } else {
                    $old_block = isset($old_form_blocks[$form_id]) ? $old_form_blocks[$form_id] : null;

                    // If no old block in content (e.g. form was moved from another post), check against the
                    // block in the post that holds it. Never rebuild it from another post's stored config:
                    // that would write that post's form settings into this one.
                    if ($old_block === null) {
                        $old_block = FormRegistry::GetMovedFormBlock($form_id, $post_id);
                    }

                    if ($old_block !== null) {
                        // Existing form — enforce based on edit/configure capabilities
                        $block = self::EnforceExistingFormBlock($block, $old_block, $can_edit, $can_configure);
                    } elseif ($can_edit && self::IsOwnForm($form_id, $post_id)) {
                        // This post's own form, missing from the stored content (an autosave removed it):
                        // check it against the post's own stored config
                        if (!$can_configure) {
                            $block = self::EnforceAgainstOwnConfig($block, $form_id);
                        }
                    } elseif (!FormRegistry::Get($form_id) && ($unnamed = self::TakeUnnamedForm($block, true)) !== null) {
                        // The post's form without a formId, now given a new one (as the Premium editor does on load).
                        // A formId the registry knows (one waiting for deletion or that no post holds) is a new form.
                        $block = self::EnforceExistingFormBlock($block, $unnamed, $can_edit, $can_configure);
                    } elseif (!$can_create) {
                        // New form, or one no post holds any more — requires 'create'. Strip it entirely
                        $removed[] = $index;
                        continue;
                    }
                    // User can create: allow the block through as-is
                }

                $block = self::SyncFormIdMarkup($block);
            }

            // Recurse into inner blocks, including a form's: a form nested inside another is checked too
            $result[] = self::EnforceInnerBlocks($block, $old_form_blocks, $post_id, $can_edit, $can_configure, $can_create);
        }

        return $result;
    }

    /**
     * Take the post's stored form block without a formId that matches this block: one with equal attributes,
     * else one with the same form name, else the next one of the same block type. Each is matched once.
     *
     * @param array $block      The saved form block.
     * @param bool  $spare_only For a block that has a formId: take one only when more are stored than the
     *                          saved content's form blocks without a formId still need.
     * @return array|null The stored block, or null when none is left.
     */
    private static function TakeUnnamedForm($block, $spare_only = false)
    {
        $type = $block['blockName'];
        $wanted = isset(self::$unnamed_wanted[$type]) ? self::$unnamed_wanted[$type] : 0;
        if ($spare_only) {
            $stored = 0;
            foreach (self::$unnamed_forms as $old_block) {
                $stored += $old_block['blockName'] === $type ? 1 : 0;
            }
            if ($stored <= $wanted) {
                return null;
            }
        } elseif ($wanted > 0) {
            self::$unnamed_wanted[$type] = $wanted - 1;
        }

        $name = isset($block['attrs']['formName']) ? $block['attrs']['formName'] : null;
        foreach (array('attrs', 'formName', 'type') as $match) {
            foreach (self::$unnamed_forms as $i => $old_block) {
                if ($old_block['blockName'] !== $block['blockName']) {
                    continue;
                }
                $old_name = isset($old_block['attrs']['formName']) ? $old_block['attrs']['formName'] : null;
                if (
                    $match === 'type'
                    || ($match === 'formName' && $old_name === $name)
                    || ($match === 'attrs' && $old_block['attrs'] == $block['attrs'])
                ) {
                    unset(self::$unnamed_forms[$i]);
                    return $old_block;
                }
            }
        }
        return null;
    }

    /**
     * Whether the registry has this post as the form's source.
     */
    private static function IsOwnForm($form_id, $post_id)
    {
        $entry = FormRegistry::Get($form_id);
        return $post_id > 0 && $entry && empty($entry['pending_delete']) && isset($entry['source_post_id'])
            && intval($entry['source_post_id']) === intval($post_id);
    }

    /**
     * Restore the sensitive attributes of this post's own form from its stored config, for a user who may
     * edit but not configure forms.
     */
    private static function EnforceAgainstOwnConfig($block, $form_id)
    {
        $config = get_option(FormRegistry::CONFIG_PREFIX . $form_id);
        if (empty($config) || !is_array($config)) {
            // Nothing stored to check against: keep none of the block's sensitive settings
            $config = array();
        }

        $fields = isset($config['formFields']) && is_array($config['formFields']) ? $config['formFields'] : array();
        unset($config['formFields']);
        $block['attrs'] = self::RestoreSensitiveAttrs(
            isset($block['attrs']) ? $block['attrs'] : array(),
            $config,
            $block['blockName'],
            isset($block['innerBlocks']) ? $block['innerBlocks'] : array()
        );

        $sensitive_map = array();
        foreach ($fields as $field_attrs) {
            if (!empty($field_attrs['fieldId'])) {
                $sensitive_map[$field_attrs['fieldId']] = !empty($field_attrs['sensitive']);
            }
        }
        if (!empty($sensitive_map) && !empty($block['innerBlocks'])) {
            $block['innerBlocks'] = self::EnforceFieldSensitiveAttr($block['innerBlocks'], $sensitive_map);
        }

        return $block;
    }

    /**
     * Make the form's markup carry its formId: visitors submit the markup's data-form-id, so a different one
     * would send this form's submissions to another form's settings.
     */
    private static function SyncFormIdMarkup($block)
    {
        $form_id = self::GetFormId($block);
        foreach ($block['innerContent'] as $i => $chunk) {
            if (is_string($chunk)) {
                $block['innerContent'][$i] = self::SyncMarkupFormId($chunk, $form_id);
            }
        }
        $block['innerHTML'] = self::SyncMarkupFormId($block['innerHTML'], $form_id);

        return $block;
    }

    /**
     * Set every data-form-id in a block's own markup to its formId ('' for a form without one), however the
     * attribute is written. Values that sanitize to the formId are left as they are.
     */
    private static function SyncMarkupFormId($html, $form_id)
    {
        if (!is_string($html) || stripos($html, 'data-form-id') === false || !class_exists('WP_HTML_Tag_Processor')) {
            return $html;
        }
        try {
            $processor = new \WP_HTML_Tag_Processor($html);
            $changed = false;
            while ($processor->next_tag()) {
                $value = $processor->get_attribute('data-form-id');
                // Compared the way a submission's id is looked up (FormRegistry::GetConfig sanitizes it)
                if ($value !== null && (!is_string($value) || sanitize_key($value) !== $form_id)) {
                    $processor->set_attribute('data-form-id', $form_id);
                    $changed = true;
                }
            }
            return $changed ? $processor->get_updated_html() : $html;
        } catch (\Exception $e) {
            return $html;
        } catch (\Throwable $e) {
            // The form's render sets data-form-id from the formId as well (DynamicBlockAssets::EnqueueForm)
            return $html;
        }
    }

    /**
     * Enforce access control on a block's inner blocks, dropping the innerContent slots of removed ones.
     *
     * @param array $block           The block whose inner blocks to enforce.
     * @param array $old_form_blocks Indexed old form blocks (formId => block).
     * @param int   $post_id         The post being saved (0 for a new post).
     * @param bool  $can_edit        User has 'edit' capability.
     * @param bool  $can_configure   User has 'configure' capability.
     * @param bool  $can_create      User has 'create' capability.
     * @return array The block with enforced inner blocks.
     */
    private static function EnforceInnerBlocks($block, $old_form_blocks, $post_id, $can_edit, $can_configure, $can_create)
    {
        if (empty($block['innerBlocks'])) {
            return $block;
        }

        $removed = array();
        $block['innerBlocks'] = self::EnforceFormBlocks(
            $block['innerBlocks'],
            $old_form_blocks,
            $post_id,
            $can_edit,
            $can_configure,
            $can_create,
            $removed
        );
        if (!empty($removed) && isset($block['innerContent']) && is_array($block['innerContent'])) {
            $block['innerContent'] = self::RemoveInnerBlockSlots($block['innerContent'], $removed);
        }

        return $block;
    }

    /**
     * Drop the innerContent placeholders (null entries) of removed inner blocks, so serialize_block()
     * keeps pairing each remaining placeholder with its own inner block.
     *
     * @param array $inner_content The parent block's innerContent.
     * @param array $removed       Indexes of the removed inner blocks.
     * @return array
     */
    private static function RemoveInnerBlockSlots($inner_content, $removed)
    {
        $kept = array();
        $slot = 0;
        foreach ($inner_content as $chunk) {
            if (is_string($chunk)) {
                $kept[] = $chunk;
                continue;
            }
            if (!in_array($slot, $removed, true)) {
                $kept[] = $chunk;
            }
            $slot++;
        }
        return $kept;
    }

    /**
     * Enforce access control on an existing form block.
     *
     * @param array $new_block  The incoming (new) form block.
     * @param array $old_block  The previous (old) form block.
     * @param bool  $can_edit   User has 'edit' capability.
     * @param bool  $can_configure User has 'configure' capability.
     * @return array The enforced block.
     */
    private static function EnforceExistingFormBlock($new_block, $old_block, $can_edit, $can_configure)
    {
        if (!$can_edit && !$can_configure) {
            // Fully read-only: restore entire block from old content
            return $old_block;
        }

        $new_attrs = isset($new_block['attrs']) ? $new_block['attrs'] : array();
        $old_attrs = isset($old_block['attrs']) ? $old_block['attrs'] : array();

        if ($can_edit && !$can_configure) {
            // Can edit safe attrs + inner blocks, but sensitive attrs must be restored from old
            $new_inner = isset($new_block['innerBlocks']) ? $new_block['innerBlocks'] : array();
            $new_block['attrs'] = self::RestoreSensitiveAttrs($new_attrs, $old_attrs, $new_block['blockName'], $new_inner);
            // Protect the 'sensitive' attribute on inner form-field blocks
            $sensitive_map = self::BuildFieldSensitiveMap($old_block);
            if (!empty($sensitive_map)) {
                $new_inner = isset($new_block['innerBlocks']) ? $new_block['innerBlocks'] : array();
                $new_block['innerBlocks'] = self::EnforceFieldSensitiveAttr($new_inner, $sensitive_map);
            }
        } elseif ($can_configure && !$can_edit) {
            // Can change sensitive attrs, but safe attrs + inner blocks must be restored from old
            $new_block['attrs'] = self::RestoreSafeAttrs($new_attrs, $old_attrs);
            // Restore inner blocks (field structure)
            $new_block['innerBlocks'] = isset($old_block['innerBlocks']) ? $old_block['innerBlocks'] : array();
            $new_block['innerContent'] = isset($old_block['innerContent']) ? $old_block['innerContent'] : array();
            $new_block['innerHTML'] = isset($old_block['innerHTML']) ? $old_block['innerHTML'] : '';
        }
        // If both can_edit and can_configure: allow everything (shouldn't reach here due to early bail)

        // When user has 'edit' but not 'configure', inner blocks can change freely except
        // the 'sensitive' attribute on form-field blocks (protected above via BuildFieldSensitiveMap).
        // When user lacks 'edit', inner blocks are restored above.

        return $new_block;
    }

    /**
     * Restore sensitive attrs from old block, keeping safe attrs from new block.
     *
     * @param array  $new_attrs  Incoming attributes.
     * @param array  $old_attrs  Previous attributes.
     * @param string $block_name The form block's name, for its attribute defaults.
     * @param array  $new_inner  The incoming block's inner blocks.
     * @return array Merged attributes.
     */
    private static function RestoreSensitiveAttrs($new_attrs, $old_attrs, $block_name = '', $new_inner = array())
    {
        foreach (self::$sensitive_attrs as $key) {
            if (array_key_exists($key, $old_attrs)) {
                // The editor leaves out attributes at their default, so one dropped at its default stays dropped
                if (!array_key_exists($key, $new_attrs) && self::IsDefaultValue($block_name, $key, $old_attrs[$key])) {
                    continue;
                }
                $new_attrs[$key] = $old_attrs[$key];
            } elseif (array_key_exists($key, $new_attrs)) {
                // The editor gives a form without a honeypot key a new one: it is kept
                if ($key === 'honeypotKey' && self::IsNewHoneypotKey($new_attrs[$key], $new_inner)) {
                    continue;
                }
                // Sensitive attr was added that didn't exist before — remove it
                unset($new_attrs[$key]);
            }
        }
        return $new_attrs;
    }

    /**
     * Whether a honeypotKey added to a form that had none looks like one the editor generates and names none of
     * the form's fields. Submissions only use it to find the hidden spam-check field, which must stay empty.
     *
     * @param mixed $value        The added honeypotKey.
     * @param array $inner_blocks The form's inner blocks.
     * @return bool
     */
    private static function IsNewHoneypotKey($value, $inner_blocks)
    {
        if (!is_string($value) || !preg_match('/^field_[a-z0-9]+$/', $value)) {
            return false;
        }
        $fields = array();
        self::CollectFieldSensitiveValues($inner_blocks, $fields);
        foreach (array_keys($fields) as $field_id) {
            if (strtolower((string) $field_id) === $value) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a value is the registered default of a block attribute.
     *
     * @param string $block_name Block name.
     * @param string $key        Attribute name.
     * @param mixed  $value      Attribute value.
     * @return bool
     */
    private static function IsDefaultValue($block_name, $key, $value)
    {
        if ($block_name === '') {
            return false;
        }
        $block_type = \WP_Block_Type_Registry::get_instance()->get_registered($block_name);
        if (!$block_type || !isset($block_type->attributes[$key]) || !array_key_exists('default', $block_type->attributes[$key])) {
            return false;
        }
        return $block_type->attributes[$key]['default'] === $value;
    }

    /**
     * Restore safe (non-sensitive) attrs from old block, keeping sensitive attrs from new block.
     *
     * @param array $new_attrs Incoming attributes.
     * @param array $old_attrs Previous attributes.
     * @return array Merged attributes.
     */
    private static function RestoreSafeAttrs($new_attrs, $old_attrs)
    {
        // Start with old attrs (all safe attrs preserved), then overlay sensitive attrs from new
        $merged = $old_attrs;
        foreach (self::$sensitive_attrs as $key) {
            if (array_key_exists($key, $new_attrs)) {
                $merged[$key] = $new_attrs[$key];
            } elseif (isset($merged[$key])) {
                // If new doesn't have it but old does, keep old (no removal of sensitive attrs)
            }
        }
        return $merged;
    }

    /**
     * Build a flat map of fieldId => sensitive value from a form block's inner blocks.
     * Walks recursively to handle both direct form-field children (form block)
     * and form-field blocks nested inside form-step blocks (multistep-form block).
     *
     * @param array $block The old form block.
     * @return array fieldId => bool (sensitive value).
     */
    private static function BuildFieldSensitiveMap($block)
    {
        $map = array();
        if (!empty($block['innerBlocks'])) {
            self::CollectFieldSensitiveValues($block['innerBlocks'], $map);
        }
        return $map;
    }

    /**
     * Recursively collect fieldId => sensitive values from a list of inner blocks.
     *
     * @param array $blocks Inner blocks to walk.
     * @param array &$map   Map to populate (fieldId => bool).
     */
    private static function CollectFieldSensitiveValues($blocks, &$map)
    {
        foreach ($blocks as $block) {
            if (
                isset($block['blockName']) && $block['blockName'] === 'superb-addons/form-field'
                && !empty($block['attrs']['fieldId'])
            ) {
                $map[$block['attrs']['fieldId']] = !empty($block['attrs']['sensitive']);
            }
            // Recurse into form-step or any other container
            if (!empty($block['innerBlocks'])) {
                self::CollectFieldSensitiveValues($block['innerBlocks'], $map);
            }
        }
    }

    /**
     * Recursively walk inner blocks and restore the 'sensitive' attribute on
     * form-field blocks from the old sensitive map. New fields (no match in map)
     * pass through as-is.
     *
     * @param array $blocks        New inner blocks.
     * @param array $sensitive_map Old fieldId => bool map.
     * @return array Enforced inner blocks.
     */
    private static function EnforceFieldSensitiveAttr($blocks, $sensitive_map)
    {
        $result = array();
        foreach ($blocks as $block) {
            if (
                isset($block['blockName']) && $block['blockName'] === 'superb-addons/form-field'
                && !empty($block['attrs']['fieldId'])
            ) {
                $field_id = $block['attrs']['fieldId'];
                // Only touch the attribute when the value differs, so an unchanged field stays byte-identical
                // (the attribute defaults to false and is usually absent)
                if (isset($sensitive_map[$field_id]) && $sensitive_map[$field_id] !== !empty($block['attrs']['sensitive'])) {
                    if ($sensitive_map[$field_id]) {
                        $block['attrs']['sensitive'] = true;
                    } else {
                        unset($block['attrs']['sensitive']);
                    }
                }
                // New fields (not in map) pass through unchanged
            }
            // Recurse into form-step or any other container
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = self::EnforceFieldSensitiveAttr($block['innerBlocks'], $sensitive_map);
            }
            $result[] = $block;
        }
        return $result;
    }
}
