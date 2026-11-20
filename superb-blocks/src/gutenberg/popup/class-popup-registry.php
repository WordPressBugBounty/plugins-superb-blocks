<?php

namespace SuperbAddons\Gutenberg\Popup;

defined('ABSPATH') || exit();

use SuperbAddons\Data\Utils\Engagement;
use SuperbAddons\Gutenberg\Form\FormRegistry;

class PopupRegistry
{
    const OPTION_KEY = 'spb_popup_registry';
    const BLOCK_NAME = 'superb-addons/popup';

    private static $supported_post_types = array('post', 'page', 'wp_template', 'wp_template_part', 'wp_block');

    // Statuses in which visitors can see a post's popups
    private static $live_statuses = array('publish', 'private');

    // Statuses of the posts that count as holding a popup: live, scheduled, or still being written
    private static $holder_statuses = array('publish', 'private', 'future', 'draft', 'pending');

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
            self::CollectPopupBlocks($block, $found_popup_ids, $post_id, $post, $registry, $changed);
        }

        if (!empty($found_popup_ids)) {
            Engagement::MarkUsed(Engagement::FEATURE_POPUP);
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
     * Recursively collect popup blocks and update registry entries. A popup that the registry lists under
     * another post which still holds it stays there, unless the post being saved takes it (see TakesPopupFrom).
     */
    private static function CollectPopupBlocks($block, &$found_popup_ids, $post_id, $post, &$registry, &$changed)
    {
        $post_type = $post->post_type;
        if (isset($block['blockName']) && $block['blockName'] === self::BLOCK_NAME && !empty($block['attrs']['popupId'])) {
            $popup_id = self::SanitizePopupId($block['attrs']['popupId']);
            if ($popup_id !== '') {
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
                // A copy of another post's popup: that post stays the popup's source
                $stays_with_source = $entry && self::StaysWithSource($popup_id, $entry, $post);
                if (
                    !$stays_with_source
                    && (!$entry
                        || $entry['name'] !== $popup_name
                        || $entry['source_post_id'] !== $post_id
                        || $entry['source_post_type'] !== $post_type
                        || $entry['template_slug'] !== $template_slug)
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
        }

        // Recurse into inner blocks
        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner) {
                self::CollectPopupBlocks($inner, $found_popup_ids, $post_id, $post, $registry, $changed);
            }
        }
    }

    private static function SanitizePopupId($value)
    {
        if (!is_string($value)) {
            return '';
        }
        return preg_replace('/[^A-Za-z0-9_-]/', '', $value);
    }

    /**
     * The popupIds of the popup blocks in a parsed block tree.
     */
    private static function CollectPopupIds($blocks, &$popup_ids = array())
    {
        foreach ($blocks as $block) {
            if (isset($block['blockName']) && $block['blockName'] === self::BLOCK_NAME && !empty($block['attrs']['popupId'])) {
                $popup_id = self::SanitizePopupId($block['attrs']['popupId']);
                if ($popup_id !== '') {
                    $popup_ids[] = $popup_id;
                }
            }
            if (!empty($block['innerBlocks'])) {
                self::CollectPopupIds($block['innerBlocks'], $popup_ids);
            }
        }
        return $popup_ids;
    }

    /**
     * Whether a post holds a popup block with this popupId. A post in the trash holds none.
     */
    private static function PostHoldsPopup($popup_id, $post)
    {
        if (!$post || !in_array($post->post_type, self::$supported_post_types, true) || !in_array($post->post_status, self::$holder_statuses, true)) {
            return false;
        }
        if (strpos($post->post_content, self::BLOCK_NAME) === false) {
            return false;
        }
        return in_array($popup_id, self::CollectPopupIds(parse_blocks($post->post_content)), true);
    }

    /**
     * How soon visitors see a post's popups: 2 live, 1 scheduled, 0 not at all (draft, pending, trash...).
     */
    private static function StatusRank($status)
    {
        if (in_array($status, self::$live_statuses, true)) {
            return 2;
        }
        return $status === 'future' ? 1 : 0;
    }

    /**
     * Whether a post becomes the source of a popup that another post, the registry's source for it, still
     * holds: when visitors see the post sooner than that source, or as soon and the post is the older of the
     * two. So a page keeps its popup when a copy of the page is saved (a duplicate, a translation, a staging
     * copy), whether the copy is a draft or is published later.
     */
    private static function TakesPopupFrom($post, $source)
    {
        $rank = self::StatusRank($post->post_status);
        $source_rank = self::StatusRank($source->post_status);
        return $rank > $source_rank || ($rank === $source_rank && intval($post->ID) < intval($source->ID));
    }

    /**
     * Whether a popup found in the post being saved stays with the registry's source for it: another post
     * that still holds the popup and that the saved post doesn't take it from.
     */
    private static function StaysWithSource($popup_id, $entry, $post)
    {
        $source_id = isset($entry['source_post_id']) ? intval($entry['source_post_id']) : 0;
        if ($source_id <= 0 || $source_id === intval($post->ID)) {
            return false;
        }
        $source = get_post($source_id);
        return self::PostHoldsPopup($popup_id, $source) && !self::TakesPopupFrom($post, $source);
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
     * The registry as the block editor sees it while editing a post. The editor gives a popup a new popupId
     * when the registry names another post as its source; the popup keeps its popupId when:
     * - that source no longer holds the popup (it was deleted or moved to the trash, or the popup was removed);
     * - the post is a staging copy of that source (see FormRegistry::GetCopyOriginalId), whose popups replace
     *   the original's;
     * - the post takes the popup from that source when it is saved (see TakesPopupFrom).
     */
    public static function GetEditorRegistry($post_id)
    {
        $registry = self::GetAll();
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post || !in_array($post->post_type, self::$supported_post_types, true) || strpos($post->post_content, self::BLOCK_NAME) === false) {
            return $registry;
        }

        $original = FormRegistry::GetCopyOriginalId($post);
        foreach (self::CollectPopupIds(parse_blocks($post->post_content)) as $popup_id) {
            if (empty($registry[$popup_id]['source_post_id']) || intval($registry[$popup_id]['source_post_id']) === intval($post->ID)) {
                continue;
            }
            $source_id = intval($registry[$popup_id]['source_post_id']);
            $source = get_post($source_id);
            if (!self::PostHoldsPopup($popup_id, $source) || ($original > 0 && $original === $source_id) || self::TakesPopupFrom($post, $source)) {
                $registry[$popup_id]['source_post_id'] = intval($post->ID);
                $registry[$popup_id]['source_post_type'] = $post->post_type;
                $registry[$popup_id]['template_slug'] = in_array($post->post_type, array('wp_template', 'wp_template_part'), true) ? $post->post_name : '';
            }
        }

        return $registry;
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
