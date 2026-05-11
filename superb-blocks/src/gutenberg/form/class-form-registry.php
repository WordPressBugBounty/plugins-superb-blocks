<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormRegistry
{
    const OPTION_KEY = 'spb_form_registry';
    const CONFIG_PREFIX = 'spb_form_cfg_';
    const BLOCK_NAME = 'superb-addons/form';
    const MULTISTEP_BLOCK_NAME = 'superb-addons/multistep-form';
    const STEP_BLOCK_NAME = 'superb-addons/form-step';
    const FIELD_BLOCK_NAME = 'superb-addons/form-field';

    private static $supported_post_types = array('post', 'page', 'wp_template', 'wp_template_part', 'wp_block');

    /**
     * Get the list of post types that can contain form blocks.
     */
    public static function GetSupportedPostTypes()
    {
        return self::$supported_post_types;
    }

    public static function Initialize()
    {
        add_action('save_post', array(__CLASS__, 'OnSavePost'), 10, 2);
        add_action('before_delete_post', array(__CLASS__, 'OnDeletePost'), 10, 2);
    }

    /**
     * Hook: save_post — scan content for form blocks and update registry + configs.
     */
    public static function OnSavePost($post_id, $post)
    {
        // Bail on autosave, revisions, or unsupported post types
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!in_array($post->post_type, self::$supported_post_types, true)) {
            return;
        }

        $registry = self::GetAll();
        $changed = false;

        // Find all form blocks in the saved content
        $found_form_ids = array();
        $parsed_blocks = parse_blocks($post->post_content);

        foreach ($parsed_blocks as $block) {
            self::CollectFormBlocks($block, $found_form_ids, $post_id, $post->post_type, $registry, $changed);
        }

        // Cleanup: remove forms that were previously on this post but are no longer
        $forms_with_submissions = null; // lazy-loaded
        foreach ($registry as $fid => $data) {
            if (isset($data['source_post_id']) && intval($data['source_post_id']) === intval($post_id) && !in_array($fid, $found_form_ids, true)) {
                // Form was on this post but no longer — check for submissions before removing
                if ($forms_with_submissions === null) {
                    $forms_with_submissions = FormSubmissionHandler::GetDistinctFormIds();
                }
                if (in_array($fid, $forms_with_submissions, true)) {
                    // Has submissions: flag for deletion once submissions are cleared
                    $registry[$fid]['source_post_id'] = null;
                    $registry[$fid]['source_post_type'] = null;
                    $registry[$fid]['pending_delete'] = true;
                    $registry[$fid]['updated'] = time();
                } else {
                    unset($registry[$fid]);
                }
                $changed = true;
                // Delete the stored config for removed forms
                delete_option(self::CONFIG_PREFIX . sanitize_key($fid));
            }
        }

        if ($changed) {
            update_option(self::OPTION_KEY, $registry, false);
        }
    }

    /**
     * Recursively collect form blocks, update registry entries, and store configs.
     */
    private static function CollectFormBlocks($block, &$found_form_ids, $post_id, $post_type, &$registry, &$changed)
    {
        $is_form = isset($block['blockName']) && $block['blockName'] === self::BLOCK_NAME && !empty($block['attrs']['formId']);
        $is_multistep = isset($block['blockName']) && $block['blockName'] === self::MULTISTEP_BLOCK_NAME && !empty($block['attrs']['formId']);

        if ($is_form || $is_multistep) {
            $form_id = sanitize_key($block['attrs']['formId']);
            $form_name = isset($block['attrs']['formName']) ? sanitize_text_field($block['attrs']['formName']) : '';
            $found_form_ids[] = $form_id;

            $entry = isset($registry[$form_id]) ? $registry[$form_id] : null;
            if (
                !$entry
                || $entry['name'] !== $form_name
                || $entry['source_post_id'] !== $post_id
                || $entry['source_post_type'] !== $post_type
                || !empty($entry['pending_delete'])
            ) {
                $registry[$form_id] = array(
                    'name' => $form_name,
                    'source_post_id' => $post_id,
                    'source_post_type' => $post_type,
                    'updated' => time(),
                );
                $changed = true;
            }

            // Store form config (attributes + inner field blocks)
            $attrs = isset($block['attrs']) ? $block['attrs'] : array();
            $form_fields = self::ExtractFieldBlocks($block, $is_multistep);
            $attrs['formFields'] = $form_fields;

            // Extract webhook secret to encrypted storage and remove from config
            if (isset($attrs['webhookSecret']) && $attrs['webhookSecret'] !== '') {
                FormSettings::SetWebhookSecret($form_id, $attrs['webhookSecret']);
            }
            unset($attrs['webhookSecret']);

            update_option(self::CONFIG_PREFIX . $form_id, $attrs, false);
        }

        // Recurse into inner blocks
        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner) {
                self::CollectFormBlocks($inner, $found_form_ids, $post_id, $post_type, $registry, $changed);
            }
        }
    }

    /**
     * Extract form-field blocks from a form or multistep-form block.
     * For multistep forms, recurses through form-step inner blocks.
     */
    private static function ExtractFieldBlocks($block, $is_multistep = false)
    {
        $form_fields = array();
        if (empty($block['innerBlocks'])) {
            return $form_fields;
        }

        foreach ($block['innerBlocks'] as $inner) {
            if ($is_multistep && isset($inner['blockName']) && $inner['blockName'] === self::STEP_BLOCK_NAME) {
                // Recurse into form-step to find form-field blocks
                if (!empty($inner['innerBlocks'])) {
                    foreach ($inner['innerBlocks'] as $step_inner) {
                        if (
                            isset($step_inner['blockName']) && $step_inner['blockName'] === self::FIELD_BLOCK_NAME
                            && !empty($step_inner['attrs']['fieldId'])
                        ) {
                            $form_fields[] = $step_inner['attrs'];
                        }
                    }
                }
            } elseif (
                isset($inner['blockName']) && $inner['blockName'] === self::FIELD_BLOCK_NAME
                && !empty($inner['attrs']['fieldId'])
            ) {
                $form_fields[] = $inner['attrs'];
            }
        }

        return $form_fields;
    }

    /**
     * Get the full registry array.
     */
    public static function GetAll()
    {
        $registry = get_option(self::OPTION_KEY, array());
        return is_array($registry) ? $registry : array();
    }

    /**
     * Get a single registry entry.
     */
    public static function Get($form_id)
    {
        $registry = self::GetAll();
        return isset($registry[$form_id]) ? $registry[$form_id] : null;
    }

    /**
     * Check if a form is flagged as pending deletion.
     */
    public static function IsPendingDelete($form_id)
    {
        $entry = self::Get($form_id);
        return $entry && !empty($entry['pending_delete']);
    }

    /**
     * Get display name for a form, with fallback.
     */
    public static function GetName($form_id)
    {
        $entry = self::Get($form_id);
        if ($entry && !empty($entry['name'])) {
            return $entry['name'];
        }
        /* translators: %s: form ID */
        return sprintf(__('Unnamed Form (%s)', 'superb-blocks'), $form_id);
    }

    /**
     * Remove a form from the registry.
     */
    public static function Remove($form_id)
    {
        $registry = self::GetAll();
        if (isset($registry[$form_id])) {
            unset($registry[$form_id]);
            update_option(self::OPTION_KEY, $registry, false);
        }
    }

    /**
     * Remove a form block from its source post content.
     * Returns true if the block was found and removed, false otherwise.
     */
    public static function RemoveFormBlock($form_id)
    {
        $entry = self::Get($form_id);
        if (!$entry || empty($entry['source_post_id'])) {
            return false;
        }

        $post = get_post($entry['source_post_id']);
        if (!$post || empty($post->post_content)) {
            return false;
        }

        $blocks = parse_blocks($post->post_content);
        $filtered = self::FilterOutFormBlock($form_id, $blocks);

        if ($filtered === null) {
            return false;
        }

        $new_content = serialize_blocks($filtered);

        // Use wp_update_post to trigger save_post hooks (which will update the registry)
        return wp_update_post(array(
            'ID' => $post->ID,
            'post_content' => $new_content,
        )) !== 0;
    }

    /**
     * Recursively filter out a form block with a specific formId.
     * Returns the filtered blocks array, or null if the block was not found.
     */
    private static function FilterOutFormBlock($form_id, $blocks)
    {
        $found = false;
        $result = array();

        foreach ($blocks as $block) {
            if (
                isset($block['blockName']) && ($block['blockName'] === self::BLOCK_NAME || $block['blockName'] === self::MULTISTEP_BLOCK_NAME)
                && isset($block['attrs']['formId']) && sanitize_key($block['attrs']['formId']) === $form_id
            ) {
                $found = true;
                continue;
            }

            // Recurse into inner blocks
            if (!empty($block['innerBlocks'])) {
                $inner_result = self::FilterOutFormBlock($form_id, $block['innerBlocks']);
                if ($inner_result !== null) {
                    $found = true;
                    $block['innerBlocks'] = $inner_result;
                }
            }

            $result[] = $block;
        }

        return $found ? $result : null;
    }

    /**
     * Hook: before_delete_post — handles permanent post deletion (trash emptying).
     * save_post does NOT fire when a trashed post is permanently deleted.
     */
    public static function OnDeletePost($post_id, $post)
    {
        if (!in_array($post->post_type, self::$supported_post_types, true)) {
            return;
        }

        $registry = self::GetAll();
        $changed = false;
        $forms_with_submissions = null;

        foreach ($registry as $fid => $data) {
            if (isset($data['source_post_id']) && intval($data['source_post_id']) === intval($post_id)) {
                if ($forms_with_submissions === null) {
                    $forms_with_submissions = FormSubmissionHandler::GetDistinctFormIds();
                }
                if (in_array($fid, $forms_with_submissions, true)) {
                    $registry[$fid]['source_post_id'] = null;
                    $registry[$fid]['source_post_type'] = null;
                    $registry[$fid]['pending_delete'] = true;
                    $registry[$fid]['updated'] = time();
                } else {
                    unset($registry[$fid]);
                    delete_option(self::CONFIG_PREFIX . sanitize_key($fid));
                }
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::OPTION_KEY, $registry, false);
        }
    }

    /**
     * Clean up registry entries flagged for deletion once their submissions are gone.
     * Call this after deleting submissions for a specific form.
     */
    public static function CleanupAfterSubmissionDelete($form_id)
    {
        $registry = self::GetAll();
        $entry = isset($registry[$form_id]) ? $registry[$form_id] : null;

        if (!$entry || empty($entry['pending_delete'])) {
            return;
        }

        // Check if this form still has any submissions
        $count = FormSubmissionHandler::GetCount($form_id);
        if (intval($count['total']) === 0) {
            unset($registry[$form_id]);
            update_option(self::OPTION_KEY, $registry, false);
            delete_option(self::CONFIG_PREFIX . sanitize_key($form_id));
        }
    }

    /**
     * Get stored form config, rebuilding from source post if missing.
     * Returns the config array or null if form cannot be found.
     */
    public static function GetConfig($form_id)
    {
        $config = get_option(self::CONFIG_PREFIX . sanitize_key($form_id));
        if (!empty($config) && is_array($config)) {
            return $config;
        }

        return self::RebuildConfig($form_id);
    }

    /**
     * Rebuild the form config from source post content and store as option.
     * Returns the config array or null if form cannot be found.
     */
    public static function RebuildConfig($form_id)
    {
        $entry = self::Get($form_id);
        $source_post_id = ($entry && isset($entry['source_post_id'])) ? $entry['source_post_id'] : null;

        $post = null;
        if ($source_post_id !== null) {
            $post = get_post($source_post_id);
        }

        // If source post is gone or unknown, try a broad search
        if (!$post || !self::PostContainsForm($form_id, $post)) {
            $found = self::FindFormInPosts($form_id);
            if (!$found) {
                return null;
            }
            $post = get_post($found['post_id']);
            if (!$post) {
                return null;
            }
            // Update registry with discovered source
            $registry = self::GetAll();
            if (isset($registry[$form_id])) {
                $registry[$form_id]['source_post_id'] = $found['post_id'];
                $registry[$form_id]['source_post_type'] = $found['post_type'];
                $registry[$form_id]['updated'] = time();
                update_option(self::OPTION_KEY, $registry, false);
            }
        }

        $form_data = self::ExtractFormFromPost($form_id, $post);
        if (!$form_data) {
            return null;
        }

        update_option(self::CONFIG_PREFIX . sanitize_key($form_id), $form_data, false);

        return $form_data;
    }

    /**
     * Check if a post contains a form block with a specific formId.
     */
    private static function PostContainsForm($form_id, $post)
    {
        if (!has_block(self::BLOCK_NAME, $post) && !has_block(self::MULTISTEP_BLOCK_NAME, $post)) {
            return false;
        }

        $parsed_blocks = parse_blocks($post->post_content);
        $flattened = function_exists('_flatten_blocks') ? _flatten_blocks($parsed_blocks) : self::FlattenBlocks($parsed_blocks);

        foreach ($flattened as $block) {
            $is_match = ($block['blockName'] === self::BLOCK_NAME || $block['blockName'] === self::MULTISTEP_BLOCK_NAME)
                && isset($block['attrs']['formId']) && sanitize_key($block['attrs']['formId']) === $form_id;
            if ($is_match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Search across post types for a form block with a specific formId.
     * Returns array('post_id' => int, 'post_type' => string) or null.
     */
    private static function FindFormInPosts($form_id)
    {
        $posts = get_posts(array(
            'post_type' => self::$supported_post_types,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));

        foreach ($posts as $pid) {
            $post = get_post($pid);
            if (!$post || empty($post->post_content)) {
                continue;
            }
            // Fast check: does the content even mention our block?
            if (!has_block(self::BLOCK_NAME, $post) && !has_block(self::MULTISTEP_BLOCK_NAME, $post)) {
                continue;
            }
            if (self::PostContainsForm($form_id, $post)) {
                return array(
                    'post_id' => $post->ID,
                    'post_type' => $post->post_type,
                );
            }
        }

        return null;
    }

    /**
     * Extract a form block's full attributes (including inner field blocks) from a post.
     * Returns the attributes array or null if not found.
     */
    private static function ExtractFormFromPost($form_id, $post)
    {
        $parsed_blocks = parse_blocks($post->post_content);
        $form_block = self::FindFormBlock($form_id, $parsed_blocks);

        if (!$form_block) {
            return null;
        }

        $attrs = isset($form_block['attrs']) ? $form_block['attrs'] : array();

        // Extract inner form-field blocks (handles both flat and multistep nesting)
        $is_multistep = isset($form_block['blockName']) && $form_block['blockName'] === self::MULTISTEP_BLOCK_NAME;
        $attrs['formFields'] = self::ExtractFieldBlocks($form_block, $is_multistep);

        return $attrs;
    }

    /**
     * Recursively find a form block with a specific formId in parsed blocks.
     */
    private static function FindFormBlock($form_id, $blocks)
    {
        foreach ($blocks as $block) {
            if (
                isset($block['blockName']) && ($block['blockName'] === self::BLOCK_NAME || $block['blockName'] === self::MULTISTEP_BLOCK_NAME)
                && isset($block['attrs']['formId']) && sanitize_key($block['attrs']['formId']) === $form_id
            ) {
                return $block;
            }
            if (!empty($block['innerBlocks'])) {
                $found = self::FindFormBlock($form_id, $block['innerBlocks']);
                if ($found) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Fallback flatten for WP versions without _flatten_blocks().
     */
    private static function FlattenBlocks($blocks)
    {
        $result = array();
        foreach ($blocks as $block) {
            $result[] = $block;
            if (!empty($block['innerBlocks'])) {
                $result = array_merge($result, self::FlattenBlocks($block['innerBlocks']));
            }
        }
        return $result;
    }
}
