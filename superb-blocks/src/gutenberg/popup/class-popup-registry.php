<?php

namespace SuperbAddons\Gutenberg\Popup;

defined('ABSPATH') || exit();

class PopupRegistry
{
    const OPTION_KEY = 'spb_popup_registry';
    const BLOCK_NAME = 'superb-addons/popup';

    private static $supported_post_types = array('post', 'page', 'wp_template', 'wp_template_part', 'wp_block');

    /**
     * Get the list of post types that can contain popup blocks.
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
     * Hook: save_post -- scan content for popup blocks and update registry.
     */
    public static function OnSavePost($post_id, $post)
    {
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

        $found_popup_ids = array();
        $parsed_blocks = parse_blocks($post->post_content);

        foreach ($parsed_blocks as $block) {
            self::CollectPopupBlocks($block, $found_popup_ids, $post_id, $post->post_type, $registry, $changed);
        }

        // Cleanup: remove popups that were previously on this post but are no longer
        foreach ($registry as $pid => $data) {
            if (isset($data['source_post_id']) && intval($data['source_post_id']) === intval($post_id) && !in_array($pid, $found_popup_ids, true)) {
                unset($registry[$pid]);
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::OPTION_KEY, $registry, false);
        }
    }

    /**
     * Recursively collect popup blocks and update registry entries.
     */
    private static function CollectPopupBlocks($block, &$found_popup_ids, $post_id, $post_type, &$registry, &$changed)
    {
        if (isset($block['blockName']) && $block['blockName'] === self::BLOCK_NAME && !empty($block['attrs']['popupId'])) {
            $popup_id = sanitize_key($block['attrs']['popupId']);
            $popup_name = isset($block['attrs']['popupName']) ? sanitize_text_field($block['attrs']['popupName']) : '';
            $found_popup_ids[] = $popup_id;

            $template_slug = '';
            if ($post_type === 'wp_template' || $post_type === 'wp_template_part') {
                $post_obj = get_post($post_id);
                if ($post_obj) {
                    $template_slug = $post_obj->post_name;
                }
            }

            $entry = isset($registry[$popup_id]) ? $registry[$popup_id] : null;
            if (
                !$entry
                || $entry['name'] !== $popup_name
                || $entry['source_post_id'] !== $post_id
                || $entry['source_post_type'] !== $post_type
                || $entry['template_slug'] !== $template_slug
            ) {
                $registry[$popup_id] = array(
                    'name' => $popup_name,
                    'source_post_id' => $post_id,
                    'source_post_type' => $post_type,
                    'template_slug' => $template_slug,
                    'updated' => time(),
                );
                $changed = true;
            }
        }

        // Recurse into inner blocks
        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner) {
                self::CollectPopupBlocks($inner, $found_popup_ids, $post_id, $post_type, $registry, $changed);
            }
        }
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
    public static function Get($popup_id)
    {
        $registry = self::GetAll();
        return isset($registry[$popup_id]) ? $registry[$popup_id] : null;
    }

    /**
     * Hook: before_delete_post -- remove all registry entries for that post.
     */
    public static function OnDeletePost($post_id, $post)
    {
        if (!in_array($post->post_type, self::$supported_post_types, true)) {
            return;
        }

        $registry = self::GetAll();
        $changed = false;

        foreach ($registry as $pid => $data) {
            if (isset($data['source_post_id']) && intval($data['source_post_id']) === intval($post_id)) {
                unset($registry[$pid]);
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::OPTION_KEY, $registry, false);
        }
    }
}
