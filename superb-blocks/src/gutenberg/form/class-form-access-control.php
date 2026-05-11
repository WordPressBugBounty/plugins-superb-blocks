<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormAccessControl
{
    const OPTION_ENABLED = 'superbaddons_form_access_control_enabled';

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
        // Webhook (secret stored separately in encrypted option, not as block attribute)
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

        // Skip autosave and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return $data;
        }
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

        $content = isset($data['post_content']) ? $data['post_content'] : '';

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
        $post_id = isset($postarr['ID']) ? intval($postarr['ID']) : 0;
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
        $enforced = self::EnforceFormBlocks($new_blocks, $old_form_blocks, $can_edit, $can_configure, $can_create);
        $data['post_content'] = serialize_blocks($enforced);

        return $data;
    }

    /**
     * Build a flat lookup of form blocks indexed by formId from a parsed block tree.
     *
     * @param array $blocks Parsed blocks.
     * @return array formId => block (full block array including innerBlocks/innerContent).
     */
    private static function IndexFormBlocksByFormId($blocks)
    {
        $index = array();
        foreach ($blocks as $block) {
            if (self::IsFormBlock($block) && !empty($block['attrs']['formId'])) {
                $index[sanitize_key($block['attrs']['formId'])] = $block;
            }
            if (!empty($block['innerBlocks'])) {
                $inner_index = self::IndexFormBlocksByFormId($block['innerBlocks']);
                foreach ($inner_index as $fid => $fblock) {
                    $index[$fid] = $fblock;
                }
            }
        }
        return $index;
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
     * @param bool  $can_edit         User has 'edit' capability.
     * @param bool  $can_configure    User has 'configure' capability.
     * @param bool  $can_create       User has 'create' capability.
     * @return array Enforced blocks.
     */
    private static function EnforceFormBlocks($blocks, $old_form_blocks, $can_edit, $can_configure, $can_create)
    {
        $result = array();

        foreach ($blocks as $block) {
            if (self::IsFormBlock($block)) {
                $form_id = isset($block['attrs']['formId']) ? sanitize_key($block['attrs']['formId']) : '';

                // Determine if this is an existing or new form
                $is_existing = !empty($form_id) && (
                    isset($old_form_blocks[$form_id]) || FormRegistry::Get($form_id) !== null
                );

                if ($is_existing) {
                    // Existing form — enforce based on edit/configure capabilities
                    $old_block = isset($old_form_blocks[$form_id]) ? $old_form_blocks[$form_id] : null;

                    // If no old block in content (e.g. form was moved from another post),
                    // try rebuilding reference from stored config
                    if ($old_block === null) {
                        $old_block = self::BuildReferenceBlockFromConfig($form_id);
                    }

                    if ($old_block !== null) {
                        $block = self::EnforceExistingFormBlock($block, $old_block, $can_edit, $can_configure);
                    }
                    // If we can't find old block data at all, allow through (graceful degradation)
                } else {
                    // New form — requires 'create' capability
                    if (!$can_create) {
                        // Strip this form block entirely
                        continue;
                    }
                    // User can create: allow the block through as-is
                }

                $result[] = $block;
            } else {
                // Not a form block — recurse into inner blocks
                if (!empty($block['innerBlocks'])) {
                    $block['innerBlocks'] = self::EnforceFormBlocks(
                        $block['innerBlocks'],
                        $old_form_blocks,
                        $can_edit,
                        $can_configure,
                        $can_create
                    );
                }
                $result[] = $block;
            }
        }

        return $result;
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
            $new_block['attrs'] = self::RestoreSensitiveAttrs($new_attrs, $old_attrs);
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
     * @param array $new_attrs Incoming attributes.
     * @param array $old_attrs Previous attributes.
     * @return array Merged attributes.
     */
    private static function RestoreSensitiveAttrs($new_attrs, $old_attrs)
    {
        foreach (self::$sensitive_attrs as $key) {
            if (array_key_exists($key, $old_attrs)) {
                $new_attrs[$key] = $old_attrs[$key];
            } elseif (array_key_exists($key, $new_attrs)) {
                // Sensitive attr was added that didn't exist before — remove it
                unset($new_attrs[$key]);
            }
        }
        return $new_attrs;
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
     * Falls back to the formFields config array when innerBlocks is empty
     * (e.g. when old_block comes from BuildReferenceBlockFromConfig).
     *
     * @param array $block The old form block.
     * @return array fieldId => bool (sensitive value).
     */
    private static function BuildFieldSensitiveMap($block)
    {
        $map = array();

        // Primary path: walk innerBlocks recursively
        if (!empty($block['innerBlocks'])) {
            self::CollectFieldSensitiveValues($block['innerBlocks'], $map);
            return $map;
        }

        // Fallback: use formFields from stored config (flat array of field attrs)
        $attrs = isset($block['attrs']) ? $block['attrs'] : array();
        if (!empty($attrs['formFields']) && is_array($attrs['formFields'])) {
            foreach ($attrs['formFields'] as $field_attrs) {
                if (!empty($field_attrs['fieldId'])) {
                    $map[$field_attrs['fieldId']] = !empty($field_attrs['sensitive']);
                }
            }
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
                if (isset($sensitive_map[$field_id])) {
                    $block['attrs']['sensitive'] = $sensitive_map[$field_id];
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

    /**
     * Build a minimal reference block from stored config when old post content isn't available.
     * This handles cases where a form exists in the registry but the block was moved from another post.
     *
     * @param string $form_id The form ID.
     * @return array|null A block-like array with attrs, or null if config not found.
     */
    private static function BuildReferenceBlockFromConfig($form_id)
    {
        $config = FormRegistry::GetConfig($form_id);
        if (empty($config) || !is_array($config)) {
            return null;
        }

        // We can restore attrs from config, but innerBlocks/innerContent cannot be
        // faithfully reconstructed from the flat config. Return a minimal structure
        // that allows attribute enforcement.
        return array(
            'blockName' => FormRegistry::BLOCK_NAME,
            'attrs' => $config,
            'innerBlocks' => array(),
            'innerContent' => array(),
            'innerHTML' => '',
        );
    }
}
