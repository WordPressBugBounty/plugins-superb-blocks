<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

use SuperbAddons\Data\Controllers\LogController;
use SuperbAddons\Data\Controllers\MigrationController;
use SuperbAddons\Data\Utils\Engagement;

class FormRegistry
{
    const OPTION_KEY = 'spb_form_registry';
    const CONFIG_PREFIX = 'spb_form_cfg_';
    const BLOCK_NAME = 'superb-addons/form';
    const MULTISTEP_BLOCK_NAME = 'superb-addons/multistep-form';
    const STEP_BLOCK_NAME = 'superb-addons/form-step';
    const FIELD_BLOCK_NAME = 'superb-addons/form-field';
    // Transient prefix for what a recent holder search found (see GetScanResult)
    const SCAN_RESULT_PREFIX = 'spb_form_scan_';
    // Post meta set on a post whose content was last saved under a type the registry doesn't scan
    const UNSCANNED_META = '_spb_form_unscanned';

    private static $supported_post_types = array('post', 'page', 'wp_template', 'wp_template_part', 'wp_block');

    // Statuses in which visitors can see a post's forms
    private static $live_statuses = array('publish', 'private');

    // Statuses of the posts that count as holding a form: live, scheduled, or still being written
    private static $holder_statuses = array('publish', 'private', 'future', 'draft', 'pending');

    // The formIds each post held before the save in progress, by post ID (set by OnInsertPostData)
    private static $held_before = array();

    // GetOtherHolders results, kept for the save in progress
    private static $holders_cache = array();

    // Forms given a new formId in this request: new formId => "copied formId|occurrence"
    private static $copied_from = array();

    // Forms FollowLiveHolder already checked in this request
    private static $followed = array();

    // FindPostsHoldingForm results in this request, until a save or delete changes posts
    private static $scans = array();

    // Posts being deleted in this request (set by OnDeletePost, cleared by OnDeletedPost)
    private static $deleting = array();

    // Saves whose post type OnInsertPostData skipped, for OnInsertPostDataLate
    private static $type_skipped = array();

    /**
     * Get the list of post types that can contain form blocks.
     */
    public static function GetSupportedPostTypes()
    {
        return self::$supported_post_types;
    }

    public static function Initialize()
    {
        add_action('save_post', array(__CLASS__, 'OnSavePost'), 10, 3);
        add_action('before_delete_post', array(__CLASS__, 'OnDeletePost'), 10, 2);
        add_action('deleted_post', array(__CLASS__, 'OnDeletedPost'), 10, 1);
        // Before FormAccessControl (priority 10), so access control sees a renamed copy as the new form it is
        add_filter('wp_insert_post_data', array(__CLASS__, 'OnInsertPostData'), 9, 2);
        add_filter('wp_insert_post_data', array(__CLASS__, 'OnInsertPostDataLate'), PHP_INT_MAX, 2);
        // PublishPress Revisions writes an approved revision to the post without the save hooks by default
        add_action('revisionary_revision_published', array(__CLASS__, 'OnRevisionPublished'), 10, 1);
    }

    /**
     * The key of a post or form in this request's lookups: post IDs and formIds repeat across the sites of a
     * network, and one request can switch between them.
     */
    private static function RequestKey($id)
    {
        return get_current_blog_id() . ':' . $id;
    }

    /**
     * Filter: wp_insert_post_data — give a copied form its own formId when another post holds the form and
     * the acting user can't edit that post, as the editor does for duplicates (the form keeps its name), so
     * each form keeps its own settings. Forms the post already held keep their formIds; MayClaimForm
     * checks those. A staging copy keeps the forms of the post it stands in for (see GetCopyOriginalId).
     *
     * @param array $data    Slashed post data about to be inserted.
     * @param array $postarr Raw post data array (includes ID for updates).
     * @return array Possibly corrected $data.
     */
    public static function OnInsertPostData($data, $postarr)
    {
        $post_type = isset($data['post_type']) ? $data['post_type'] : '';
        $post_id = isset($postarr['ID']) ? intval($postarr['ID']) : 0;
        if (!in_array($post_type, self::$supported_post_types, true)) {
            // Content saved under a type the registry doesn't scan isn't checked, so it holds no forms. A later
            // filter may still switch the post to a type the registry scans (see OnInsertPostDataLate).
            if ($post_id > 0) {
                self::$held_before[$post_id] = array();
            }
            self::$type_skipped[self::RequestKey($post_id)] = true;
            return $data;
        }

        if ($post_id > 0 && isset(self::$deleting[self::RequestKey($post_id)])) {
            return $data;
        }

        $held = array();
        $old_post = null;
        if ($post_id > 0) {
            // Still the stored version at this point. Content stored or last saved under a type the registry
            // doesn't scan was never checked here, so its formIds don't count as held.
            $old_post = get_post($post_id);
            if ($old_post && in_array($old_post->post_type, self::$supported_post_types, true) && self::MentionsForm($old_post->post_content) && !self::WasSavedUnscanned($post_id)) {
                $held = self::CollectFormIds(parse_blocks($old_post->post_content));
            }
            // As strings: PHP turns an all-digit formId key into an integer
            self::$held_before[$post_id] = array_map('strval', array_keys($held));
        }

        // Slashed data, as in FormAccessControl::OnInsertPostData
        $content = isset($data['post_content']) ? wp_unslash($data['post_content']) : '';
        if (!self::MentionsForm($content)) {
            return $data;
        }

        // A new save starts with fresh lookups
        self::$holders_cache = array();
        self::$scans = array();

        $user_id = self::GetActingUserId(isset($data['post_author']) ? $data['post_author'] : 0, $post_id > 0);
        // The post a staging copy stands in for; a new copy's own meta isn't stored yet
        $original_id = self::GetCopyOriginalId($old_post, array(
            'post_mime_type' => isset($data['post_mime_type']) ? $data['post_mime_type'] : '',
            'comment_count' => isset($postarr['comment_count']) ? $postarr['comment_count'] : 0,
        ));
        $renamed = $content;
        foreach (array_keys(self::CollectFormIds(parse_blocks($content))) as $form_id) {
            $form_id = (string) $form_id;
            // A held form keeps its formId, unless it waits to be deleted and belongs to a post the user can't edit
            if (isset($held[$form_id]) && self::MayTakePendingForm(self::Get($form_id), $user_id)) {
                continue;
            }
            // A form whose source visitors can't see follows the live page that shows it before the checks
            self::FollowLiveHolder($form_id);
            if (!self::MayHoldForm($form_id, $post_id, $user_id, $original_id)) {
                $renamed = self::RenameForm($renamed, $form_id, $post_id);
            }
        }

        if ($renamed === $content) {
            return $data;
        }
        $data['post_content'] = wp_slash($renamed);

        return $data;
    }

    /**
     * Filter: wp_insert_post_data, last — when a filter after OnInsertPostData switched the post to a type the
     * registry scans (as Post Type Switcher does), check its forms as OnInsertPostData would have.
     */
    public static function OnInsertPostDataLate($data, $postarr)
    {
        $key = self::RequestKey(isset($postarr['ID']) ? intval($postarr['ID']) : 0);
        if (!isset(self::$type_skipped[$key])) {
            return $data;
        }
        unset(self::$type_skipped[$key]);

        $post_type = isset($data['post_type']) ? $data['post_type'] : '';
        return in_array($post_type, self::$supported_post_types, true) ? self::OnInsertPostData($data, $postarr) : $data;
    }

    /**
     * Whether a post's content was last saved under a type the registry doesn't scan (set_post_type() switches
     * a post's type without a save).
     */
    private static function WasSavedUnscanned($post_id)
    {
        return (bool) get_post_meta($post_id, self::UNSCANNED_META, true);
    }

    /**
     * Whether content may contain a form block (a fast check before parsing).
     */
    private static function MentionsForm($content)
    {
        return strpos($content, self::BLOCK_NAME) !== false || strpos($content, self::MULTISTEP_BLOCK_NAME) !== false;
    }

    /**
     * The formIds of the form blocks in a parsed block tree.
     *
     * @return array formId => true. An all-digit formId becomes an integer key; cast keys back with strval.
     */
    private static function CollectFormIds($blocks, &$form_ids = array())
    {
        foreach ($blocks as $block) {
            $form_id = self::GetBlockFormId($block);
            if ($form_id !== '') {
                $form_ids[$form_id] = true;
            }
            if (!empty($block['innerBlocks'])) {
                self::CollectFormIds($block['innerBlocks'], $form_ids);
            }
        }
        return $form_ids;
    }

    /**
     * A form block's formId, sanitized; '' for other blocks and for a form without one.
     */
    private static function GetBlockFormId($block)
    {
        $is_form = isset($block['blockName']) && ($block['blockName'] === self::BLOCK_NAME || $block['blockName'] === self::MULTISTEP_BLOCK_NAME);
        return $is_form && isset($block['attrs']['formId']) && is_string($block['attrs']['formId']) ? sanitize_key($block['attrs']['formId']) : '';
    }

    /**
     * Give each copy of a form in the content its own new formId: rewrite the formId in the form's block
     * comment and the data-form-id in its markup, leaving everything else in the content as it was.
     */
    private static function RenameForm($content, $form_id, $post_id)
    {
        // Block delimiters, as WP_Block_Parser reads them
        $pattern = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $content;
        }

        // The form openers in document order, each with its decoded attributes and the index of its delimiter
        $openers = array();
        foreach ($matches as $index => $match) {
            $name = (isset($match['namespace'][0]) && $match['namespace'][0] !== '' ? $match['namespace'][0] : 'core/') . $match['name'][0];
            $is_opener = !(isset($match['closer'][0]) && $match['closer'][0] === '/');
            if ($is_opener && ($name === self::BLOCK_NAME || $name === self::MULTISTEP_BLOCK_NAME)) {
                $json = isset($match['attrs'][0]) ? trim($match['attrs'][0]) : '';
                $attrs = $json !== '' ? json_decode($json, true) : null;
                $openers[] = array(
                    'match' => $match,
                    'attrs' => is_array($attrs) ? $attrs : array(),
                    'index' => $index,
                );
            }
        }

        // Splice from the end, so earlier offsets stay valid
        $occurrence = 0;
        $edits = array();
        foreach ($openers as $i => $opener) {
            if (self::GetBlockFormId(array('blockName' => self::BLOCK_NAME, 'attrs' => $opener['attrs'])) !== $form_id) {
                continue;
            }
            $new_id = self::NewFormId($form_id, $post_id, $occurrence++);
            $match = $opener['match'];

            // The comment: replace the formId as serialize_block_attributes() writes it, else re-encode the attributes
            $json = $match['attrs'][0];
            $raw = $opener['attrs']['formId'];
            $new_json = str_replace(substr(serialize_block_attributes(array('formId' => $raw)), 1, -1), '"formId":"' . $new_id . '"', $json);
            $check = json_decode(trim($new_json), true);
            if (!is_array($check) || !isset($check['formId']) || $check['formId'] !== $new_id) {
                $attrs = $opener['attrs'];
                $attrs['formId'] = $new_id;
                $new_json = serialize_block_attributes($attrs) . ' ';
            }
            $edits[] = array($match['attrs'][1], strlen($json), $new_json);

            // The markup: the first data-form-id in this form's own markup, which ends at its closer (as
            // WP_Block_Parser pairs them) and before the next form
            if (isset($match['void'][0]) && $match['void'][0] === '/') {
                continue;
            }
            $start = $match[0][1] + strlen($match[0][0]);
            $end = isset($openers[$i + 1]) ? $openers[$i + 1]['match'][0][1] : strlen($content);
            $depth = 0;
            for ($k = $opener['index'] + 1, $count = count($matches); $k < $count; $k++) {
                $delimiter = $matches[$k];
                if (isset($delimiter['closer'][0]) && $delimiter['closer'][0] === '/') {
                    if ($depth === 0) {
                        $end = min($end, $delimiter[0][1]);
                        break;
                    }
                    $depth--;
                } elseif (!(isset($delimiter['void'][0]) && $delimiter['void'][0] === '/')) {
                    $depth++;
                }
            }
            $segment = substr($content, $start, $end - $start);
            $new_segment = self::SetMarkupFormId($segment, $new_id, $raw);
            if ($new_segment !== $segment) {
                $edits[] = array($start, strlen($segment), $new_segment);
            }
        }

        usort($edits, function ($a, $b) {
            return $b[0] - $a[0];
        });
        foreach ($edits as $edit) {
            $content = substr_replace($content, $edit[2], $edit[0], $edit[1]);
        }

        return $content;
    }

    /**
     * Set the first data-form-id in a piece of markup, however it is quoted. Falls back to the attribute as
     * the editor writes it when the HTML API can't read the markup.
     */
    private static function SetMarkupFormId($markup, $new_id, $raw_id)
    {
        if (stripos($markup, 'data-form-id') === false) {
            return $markup;
        }
        if (class_exists('WP_HTML_Tag_Processor')) {
            try {
                $processor = new \WP_HTML_Tag_Processor($markup);
                while ($processor->next_tag()) {
                    if ($processor->get_attribute('data-form-id') !== null) {
                        $processor->set_attribute('data-form-id', $new_id);
                        return $processor->get_updated_html();
                    }
                }
                return $markup;
            } catch (\Exception $e) {
            } catch (\Throwable $e) {
            }
        }
        $attr = 'data-form-id="' . esc_attr(is_string($raw_id) ? $raw_id : '') . '"';
        $pos = strpos($markup, $attr);
        return $pos === false ? $markup : substr_replace($markup, 'data-form-id="' . $new_id . '"', $pos, strlen($attr));
    }

    /**
     * A new formId for a copy of a form. The same post, formId and occurrence (the copy's place among the
     * post's copies of that form) give the same new formId, so a client that still has the old formId and
     * saves again gets the same one, also after a first save that created the post.
     */
    private static function NewFormId($form_id, $post_id, $occurrence = 0)
    {
        $copy = $form_id . '|' . $occurrence;
        if ($post_id > 0) {
            foreach (self::GetAll() as $fid => $entry) {
                if (
                    isset($entry['source_post_id'], $entry['copied_from']) && intval($entry['source_post_id']) === $post_id
                    && $entry['copied_from'] === $copy
                ) {
                    self::$copied_from[(string) $fid] = $copy;
                    return (string) $fid;
                }
            }
        }

        $seed = $post_id > 0 ? $post_id . '|' . $copy : wp_generate_uuid4();
        $new_id = '';
        for ($i = 0; $i < 10 && $new_id === ''; $i++) {
            $candidate = 'form_' . substr(wp_hash($seed . '|' . $i), 0, 8);
            $entry = self::Get($candidate);
            $is_free = !$entry || ($post_id > 0 && isset($entry['source_post_id']) && intval($entry['source_post_id']) === $post_id);
            // Not one already given to another copy in this request
            if ($is_free && (!isset(self::$copied_from[$candidate]) || self::$copied_from[$candidate] === $copy)) {
                $new_id = $candidate;
            }
        }
        if ($new_id === '') {
            $new_id = 'form_' . substr(wp_hash(wp_generate_uuid4()), 0, 8);
        }
        self::$copied_from[$new_id] = $copy;

        return $new_id;
    }

    /**
     * Hook: save_post — scan content for form blocks and update registry + configs.
     */
    public static function OnSavePost($post_id, $post, $update = true)
    {
        // The formIds this post held before the save. A save that skipped OnInsertPostData (wp_publish_post)
        // didn't change the content, so it held the forms it has now (null).
        $held_before = $update ? null : array();
        $checked = isset(self::$held_before[$post_id]);
        if ($checked) {
            $held_before = self::$held_before[$post_id];
            unset(self::$held_before[$post_id]);
        }

        // Bail on autosave, revisions, or unsupported post types
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        // A post being deleted holds no forms
        if (isset(self::$deleting[self::RequestKey(intval($post_id))])) {
            return;
        }
        if (!in_array($post->post_type, self::$supported_post_types, true)) {
            // Its forms aren't checked under this type, so they don't count as held if the post is later switched
            // to a type the registry scans without a save
            if (self::MentionsForm($post->post_content) && get_post($post_id) && !self::WasSavedUnscanned($post_id)) {
                update_post_meta($post_id, self::UNSCANNED_META, 1);
            }
            return;
        }
        // The post as stored: a plugin may pass an object that differs from it (PublishPress Revisions passes a
        // cached copy). A post deleted while its own save ran (Duplicate Post deletes a scheduled Rewrite &
        // Republish copy before core fires save_post for it) holds no forms.
        wp_cache_delete(intval($post_id), 'posts');
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, self::$supported_post_types, true)) {
            return;
        }
        $unscanned = self::MentionsForm($post->post_content) && self::WasSavedUnscanned($post_id);
        if ($unscanned && $held_before === null) {
            $held_before = array();
        }

        // The post's new content is stored now
        self::$scans = array();

        $before = self::GetAll();
        $registry = $before;
        $changed = false;

        // Find all form blocks in the saved content
        $found_form_ids = array();
        $parsed_blocks = parse_blocks($post->post_content);
        $user_id = self::GetActingUserId($post->post_author, $update);
        // Forms whose holders this save may change: those in the post now and those it held before
        $touched = array_merge(array_keys(self::CollectFormIds($parsed_blocks)), $held_before === null ? array() : $held_before);

        foreach ($parsed_blocks as $block) {
            self::CollectFormBlocks($block, $found_form_ids, $post, $user_id, $registry, $changed, $held_before);
        }

        if (!empty($found_form_ids)) {
            Engagement::MarkUsed(Engagement::FEATURE_FORM);
        }

        // Cleanup: remove forms that were previously on this post but are no longer
        $forms_with_submissions = null; // lazy-loaded
        foreach ($registry as $fid => $data) {
            $fid = (string) $fid;
            if (isset($data['source_post_id']) && intval($data['source_post_id']) === intval($post_id) && !in_array($fid, $found_form_ids, true)) {
                $touched[] = $fid;
                // The form moves to another post that shows it
                if (self::MoveToOtherHolder($registry, $fid, $post_id)) {
                    $changed = true;
                    continue;
                }
                // Form was on this post but no longer — check for submissions before removing
                if ($forms_with_submissions === null) {
                    $forms_with_submissions = array_map('strval', FormSubmissionHandler::GetDistinctFormIds());
                }
                if (in_array($fid, $forms_with_submissions, true)) {
                    // Has submissions: flag for deletion once submissions are cleared
                    $registry[$fid] = self::BuildPendingEntry($registry[$fid], $post);
                } else {
                    unset($registry[$fid]);
                }
                $changed = true;
                // Delete the stored config for removed forms
                delete_option(self::CONFIG_PREFIX . sanitize_key($fid));
            }
        }

        if ($changed) {
            self::SaveRegistryChanges($before, $registry);
        }
        // The save checked every formId the post holds (see OnInsertPostData)
        if ($unscanned && $checked) {
            delete_post_meta($post_id, self::UNSCANNED_META);
        }

        // Other posts' holders may have changed with this save, and so may what a holder search finds
        self::$holders_cache = array();
        self::$scans = array();
        self::$followed = array();
        self::ForgetScanResults($touched);
    }

    /**
     * Recursively collect form blocks, update registry entries, and store configs.
     *
     * @param \WP_Post   $post        The post being saved.
     * @param int        $user_id     The user the save acts for (see GetActingUserId).
     * @param array|null $held_before The formIds the post held before this save (null: the forms it has now).
     */
    private static function CollectFormBlocks($block, &$found_form_ids, $post, $user_id, &$registry, &$changed, $held_before = null)
    {
        $post_id = intval($post->ID);
        $form_id = self::GetBlockFormId($block);
        $entry = $form_id !== '' && isset($registry[$form_id]) ? $registry[$form_id] : null;

        $was_held = $held_before === null || in_array($form_id, $held_before, true);
        if ($form_id !== '' && self::MayClaimForm($form_id, $entry, $post, $user_id, $was_held)) {
            $found_form_ids[] = $form_id;

            $new_entry = self::BuildEntry($block, $post);
            if (isset(self::$copied_from[$form_id])) {
                $new_entry['copied_from'] = self::$copied_from[$form_id];
            } elseif ($entry && isset($entry['copied_from'])) {
                $new_entry['copied_from'] = $entry['copied_from'];
            }
            $unchanged = $entry && empty($entry['pending_delete']);
            foreach (array('name', 'source_post_id', 'source_post_type', 'copied_from') as $key) {
                $unchanged = $unchanged && (isset($entry[$key]) ? $entry[$key] : null) === (isset($new_entry[$key]) ? $new_entry[$key] : null);
            }
            if (!$unchanged) {
                $registry[$form_id] = $new_entry;
                $changed = true;
            }

            self::StoreConfig($form_id, $block);
        }

        // Recurse into inner blocks
        if (!empty($block['innerBlocks'])) {
            foreach ($block['innerBlocks'] as $inner) {
                self::CollectFormBlocks($inner, $found_form_ids, $post, $user_id, $registry, $changed, $held_before);
            }
        }
    }

    /**
     * A registry entry naming a post as a form block's source.
     */
    private static function BuildEntry($block, $post)
    {
        return array(
            'name' => isset($block['attrs']['formName']) && is_string($block['attrs']['formName']) ? sanitize_text_field($block['attrs']['formName']) : '',
            'source_post_id' => intval($post->ID),
            'source_post_type' => $post->post_type,
            'updated' => time(),
        );
    }

    /**
     * A registry entry for a form its source no longer holds, kept until its submissions are deleted. It names
     * the post the form left, whose author and editors may still take it (see MayTakePendingForm).
     */
    private static function BuildPendingEntry($entry, $post)
    {
        $entry['source_post_id'] = null;
        $entry['source_post_type'] = null;
        $entry['pending_delete'] = true;
        $entry['last_source_post_id'] = intval($post->ID);
        $entry['last_source_author'] = intval($post->post_author);
        $entry['updated'] = time();
        return $entry;
    }

    /**
     * A form block's config: its attributes plus its fields, without a webhook secret.
     */
    private static function BuildConfig($block)
    {
        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        $is_multistep = isset($block['blockName']) && $block['blockName'] === self::MULTISTEP_BLOCK_NAME;
        $attrs['formFields'] = self::ExtractFieldBlocks($block, $is_multistep);
        unset($attrs['webhookSecret']);
        return $attrs;
    }

    /**
     * Store a form block's config, moving a webhookSecret attribute to its encrypted storage.
     */
    private static function StoreConfig($form_id, $block)
    {
        if (isset($block['attrs']['webhookSecret']) && is_string($block['attrs']['webhookSecret']) && $block['attrs']['webhookSecret'] !== '') {
            FormSettings::SetWebhookSecret($form_id, $block['attrs']['webhookSecret']);
        }
        $config = self::BuildConfig($block);
        update_option(self::CONFIG_PREFIX . $form_id, $config, false);
        return $config;
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
                        if (self::IsConfigField($step_inner)) {
                            $form_fields[] = $step_inner['attrs'];
                        }
                    }
                }
            } elseif (self::IsConfigField($inner)) {
                $form_fields[] = $inner['attrs'];
            }
        }

        return $form_fields;
    }

    /**
     * Whether an inner block is a form-field that belongs in the form config.
     * Content fields are static text — they render no input and submit no value,
     * so validation, emails, submissions, exports, and integrations must never
     * see them. Excluding them here covers all of those in one place.
     */
    private static function IsConfigField($block)
    {
        if (!isset($block['blockName']) || $block['blockName'] !== self::FIELD_BLOCK_NAME) {
            return false;
        }
        if (empty($block['attrs']['fieldId'])) {
            return false;
        }
        $type = isset($block['attrs']['fieldType']) ? $block['attrs']['fieldType'] : 'text';
        return $type !== 'content';
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
            // wp_update_post calls wp_unslash internally; slash so real
            // backslashes in the other blocks' markup survive intact.
            'post_content' => wp_slash($new_content),
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
        // Saves that run while the post is deleted leave the registry alone (see OnSavePost)
        self::$deleting[self::RequestKey(intval($post_id))] = true;

        $before = self::GetAll();
        $registry = $before;
        $changed = false;
        $forms_with_submissions = null;
        $touched = self::MentionsForm($post->post_content) ? array_keys(self::CollectFormIds(parse_blocks($post->post_content))) : array();

        foreach ($registry as $fid => $data) {
            $fid = (string) $fid;
            if (isset($data['source_post_id']) && intval($data['source_post_id']) === intval($post_id)) {
                $touched[] = $fid;
                // The form moves to another post that shows it
                if (self::MoveToOtherHolder($registry, $fid, $post_id)) {
                    $changed = true;
                    continue;
                }
                if ($forms_with_submissions === null) {
                    $forms_with_submissions = array_map('strval', FormSubmissionHandler::GetDistinctFormIds());
                }
                if (in_array($fid, $forms_with_submissions, true)) {
                    $registry[$fid] = self::BuildPendingEntry($registry[$fid], $post);
                } else {
                    unset($registry[$fid]);
                    delete_option(self::CONFIG_PREFIX . sanitize_key($fid));
                }
                $changed = true;
            }
        }

        if ($changed) {
            self::SaveRegistryChanges($before, $registry);
        }

        // The deleted post no longer holds its forms
        self::$holders_cache = array();
        self::$scans = array();
        self::$followed = array();
        self::ForgetScanResults($touched);
    }

    /**
     * Hook: deleted_post — the post is gone, so later saves of its ID (on this site or another) are handled as
     * usual.
     */
    public static function OnDeletedPost($post_id)
    {
        unset(self::$deleting[self::RequestKey(intval($post_id))]);
    }

    /**
     * Hook: revisionary_revision_published (PublishPress Revisions) — the approved revision's content is now the
     * published post's, written without the save hooks unless the plugin is set to run them, so scan the post
     * as a save does.
     *
     * @param \WP_Post $published The published post.
     */
    public static function OnRevisionPublished($published)
    {
        $post_id = is_object($published) && isset($published->ID) ? intval($published->ID) : 0;
        $post = $post_id > 0 ? get_post($post_id) : null;
        if ($post) {
            self::OnSavePost($post_id, $post, true);
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
        $form_id = sanitize_key($form_id);
        self::FollowLiveHolder($form_id);

        $config = get_option(self::CONFIG_PREFIX . $form_id);
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
        $form_id = sanitize_key($form_id);
        $entry = self::Get($form_id);
        $source_post_id = ($entry && isset($entry['source_post_id'])) ? $entry['source_post_id'] : null;

        $post = null;
        if ($source_post_id !== null) {
            $post = get_post($source_post_id);
        }

        // If source post is gone or unknown, try a broad search
        if (!$post || !self::PostContainsForm($form_id, $post)) {
            // A recent search found no post with the form, and none with it changed since
            if (self::GetScanResult($form_id, $entry) === 'none') {
                return null;
            }
            $found = self::FindFormInPosts($form_id, $entry);
            if (!$found) {
                // Kept for forms the registry lists and for logged-in views (the Forms screens); a visitor's
                // submission with a formId nothing lists stores nothing
                if ($entry || is_user_logged_in()) {
                    self::SetScanResult($form_id, $entry, 'none');
                }
                return null;
            }
            $post = get_post($found['post_id']);
            if (!$post) {
                return null;
            }
            // Update registry with discovered source. A form missing from the registry (deleted under Forms
            // while its block stays on a page) is listed again when the post that holds it is saved.
            if (is_array($entry)) {
                $new_entry = $entry;
                $new_entry['source_post_id'] = $found['post_id'];
                $new_entry['source_post_type'] = $found['post_type'];
                $new_entry['updated'] = time();
                // A post holds the form again, so it isn't waiting to be deleted
                unset($new_entry['pending_delete'], $new_entry['last_source_post_id'], $new_entry['last_source_author']);
                self::SaveRegistryChanges(array($form_id => $entry), array($form_id => $new_entry));
            }
        }

        $form_block = self::FindFormBlock($form_id, parse_blocks($post->post_content));
        if (!$form_block) {
            return null;
        }

        return self::StoreConfig($form_id, $form_block);
    }

    /**
     * Point a form at a live page that shows it when visitors can't see the post the registry names yet (a
     * scheduled, draft, trashed or deleted post) or the form waits to be deleted, and rebuild its config from
     * that page. A live source stays. The form moves to the live page whose author can edit every other live
     * page with the form; failing that, to the oldest live page (as MayClaimForm lets the oldest keep it), unless
     * the source is a scheduled post older than every live page with the form. A form that waits to be deleted
     * moves only to a page whose author may take it (see MayTakePendingForm). GetConfig runs it before reading a
     * form's config, and a save runs it for each formId the post didn't hold before.
     * The upgrade step (SyncRegistryStep) runs it once for every form.
     *
     * @return bool Whether the form was moved.
     */
    public static function FollowLiveHolder($form_id)
    {
        $form_id = sanitize_key($form_id);
        if ($form_id === '' || isset(self::$followed[self::RequestKey($form_id)])) {
            return false;
        }
        self::$followed[self::RequestKey($form_id)] = true;

        $registry = self::GetAll();
        if (!isset($registry[$form_id]) || !is_array($registry[$form_id])) {
            return false;
        }
        $entry = $registry[$form_id];
        $source = null;
        if (empty($entry['pending_delete']) && !empty($entry['source_post_id'])) {
            $source = get_post(intval($entry['source_post_id']));
            if ($source && self::StatusRank($source->post_status) === 2) {
                return false;
            }
        }
        // A recent search found nothing to follow, and no post with the form changed since
        if (self::GetScanResult($form_id, $entry) !== '') {
            return false;
        }

        $live = self::GetHoldersWhoMayTake(self::FindPostsHoldingForm($form_id, self::$live_statuses), $entry);
        // A scheduled source older than the live pages may be the original they copy
        $strict = $source && self::StatusRank($source->post_status) === 1 && !empty($live) && intval($source->ID) < intval($live[0]->ID);
        $target = self::PickLiveHolder($live, $strict);
        $block = $target ? self::FindFormBlock($form_id, parse_blocks($target->post_content)) : null;
        if (!$block) {
            self::SetScanResult($form_id, $entry, 'no_move');
            return false;
        }

        self::StoreConfig($form_id, $block);
        $new_entry = self::BuildEntry($block, $target);
        if (isset($entry['copied_from'])) {
            $new_entry['copied_from'] = $entry['copied_from'];
        }

        // Another request may have changed the form since it was read. Its change stands, and the config
        // written here goes, so GetConfig rebuilds it from the registry as it is now.
        $registry = self::GetStoredRegistry();
        if (!isset($registry[$form_id]) || $registry[$form_id] !== $entry) {
            delete_option(self::CONFIG_PREFIX . $form_id);
            return false;
        }
        $registry[$form_id] = $new_entry;
        update_option(self::OPTION_KEY, $registry, false);
        self::$holders_cache = array();

        return true;
    }

    /**
     * Upgrade step 'form-registry-sync' (MigrationController): check the next registry entry after $cursor, in
     * formId order, with the rule GetConfig applies (FollowLiveHolder). A form whose source visitors can't see
     * then uses the settings of the live page that shows it before anyone submits it or saves that page. A form
     * with a live source stays. Forms that other authors' live posts show too are listed in one log line at the
     * end.
     *
     * @param string|null $cursor The formId checked last; null the first time.
     * @return string|true The formId checked now, or true when every entry has been checked.
     */
    public static function SyncRegistryStep($cursor)
    {
        // Another request may have saved a form since this one read the registry
        $registry = MigrationController::GetFreshOption(self::OPTION_KEY, array());
        $registry = is_array($registry) ? $registry : array();
        // A slice runs many steps: keep none of the earlier steps' holder searches
        self::$scans = array();

        // The first formId after the cursor, compared as strings (an all-digit formId is an integer key)
        $form_id = null;
        foreach (array_keys($registry) as $key) {
            $key = (string) $key;
            if (($cursor === null || strcmp($key, (string) $cursor) > 0) && ($form_id === null || strcmp($key, $form_id) < 0)) {
                $form_id = $key;
            }
        }
        if ($form_id === null) {
            self::LogSharedForms();
            return true;
        }

        $entry = $registry[$form_id];
        if (!is_array($entry) || sanitize_key($form_id) !== $form_id) {
            MigrationController::Note('skipped');
            return $form_id;
        }

        for ($check = 1; ; $check++) {
            $from_id = empty($entry['source_post_id']) ? 0 : intval($entry['source_post_id']);
            $from = $from_id > 0 ? get_post($from_id) : null;
            $is_pending = !empty($entry['pending_delete']);
            $from_status = self::GetSourceStatus($from_id, $from, $is_pending);
            $rank = $from && !$is_pending ? self::StatusRank($from->post_status) : 0;

            if (self::FollowLiveHolder($form_id)) {
                self::NoteMovedForm($form_id, $from_id, $from, $from_status);
                return $form_id;
            }
            // A live source stays. A form that another request changed while FollowLiveHolder checked it stays
            // too (see its compare-and-set): check that form once more as it is now.
            if ($rank === 2) {
                break;
            }
            $stored = self::GetStoredRegistry();
            $current = isset($stored[$form_id]) && is_array($stored[$form_id]) ? $stored[$form_id] : null;
            if ($current === $entry) {
                break;
            }
            if ($current === null || $check > 1) {
                MigrationController::Note('changed', array('form' => $form_id));
                return $form_id;
            }
            $entry = $current;
            unset(self::$followed[self::RequestKey($form_id)]);
            self::$scans = array();
        }

        // Not moved. Count what other live posts show it, for the record only
        $live = self::FindPostsHoldingForm($form_id, self::$live_statuses, $from_id);
        $outcome = 'kept';
        if ($rank === 2) {
            // A live source: is the form also on a live post whose author can't edit the source?
            foreach ($live as $holder) {
                if (!user_can(intval($holder->post_author), 'edit_post', $from_id)) {
                    $outcome = 'shared_live';
                    break;
                }
            }
        } elseif (!empty($live)) {
            // A scheduled source on live posts none of whose authors can edit all the others, or a source visitors
            // can't see that a recent search found no live page for (see GetScanResult)
            $outcome = $rank === 1 ? 'scheduled_source' : 'hidden_source';
        }
        if ($outcome === 'kept') {
            MigrationController::Note($outcome);
            return $form_id;
        }

        $live_ids = array();
        foreach ($live as $holder) {
            $live_ids[] = intval($holder->ID);
        }
        MigrationController::Note($outcome, array(
            'form' => $form_id,
            'from' => $from_id,
            'from_status' => $from_status,
            'live' => $live_ids,
        ));
        return $form_id;
    }

    /**
     * For SyncRegistryStep: the report row and log line of a form FollowLiveHolder moved. The log drops an entry
     * whose details repeat the one before it, so the details name the form.
     */
    private static function NoteMovedForm($form_id, $from_id, $from, $from_status)
    {
        $moved = self::Get($form_id);
        $to_id = $moved && isset($moved['source_post_id']) ? intval($moved['source_post_id']) : 0;
        $row = array(
            'form' => $form_id,
            'from' => $from_id,
            'from_status' => $from_status,
            'to' => $to_id,
            'to_status' => (string) get_post_status($to_id),
        );
        // Other live posts that show the form, when one of their authors can't edit the post it uses now (listed
        // at the end, as for a live source)
        $live_ids = array();
        $is_shared = false;
        foreach (self::FindPostsHoldingForm($form_id, self::$live_statuses) as $holder) {
            if (intval($holder->ID) !== $to_id) {
                $live_ids[] = intval($holder->ID);
                $is_shared = $is_shared || !user_can(intval($holder->post_author), 'edit_post', $to_id);
            }
        }
        if ($is_shared) {
            $row['live'] = $live_ids;
        }
        // The form keeps its stored webhook secret, which a save of the old source's block may have set
        if (self::HasWebhookSecret($from, $form_id) && !self::HasWebhookSecret($to_id > 0 ? get_post($to_id) : null, $form_id)) {
            $row['webhook_secret_check'] = true;
        }
        MigrationController::Note('moved', $row);
        LogController::LogInfo(
            sprintf('Form %1$s now uses the settings shown on post %2$d.', $form_id, $to_id),
            $from_id > 0 ? sprintf('Form %1$s. Before: post %2$d (%3$s).', $form_id, $from_id, $from_status) : sprintf('Form %1$s. Before: no post (%2$s).', $form_id, $from_status)
        );
    }

    /**
     * Whether a post's block of a form has a webhookSecret attribute (see StoreConfig).
     */
    private static function HasWebhookSecret($post, $form_id)
    {
        $block = $post ? self::FindFormBlock($form_id, parse_blocks($post->post_content)) : null;
        return $block && isset($block['attrs']['webhookSecret']) && is_string($block['attrs']['webhookSecret']) && $block['attrs']['webhookSecret'] !== '';
    }

    /**
     * A registry source's status for the upgrade report: the post status, 'deleted' when the post is gone,
     * 'pending_delete' when the form waits to be deleted, 'none' when the entry names no post.
     */
    private static function GetSourceStatus($post_id, $post, $is_pending)
    {
        if ($is_pending) {
            return 'pending_delete';
        }
        if ($post_id <= 0) {
            return 'none';
        }
        return $post ? $post->post_status : 'deleted';
    }

    /**
     * End of 'form-registry-sync': one log line listing the forms that other authors' live posts show too.
     */
    private static function LogSharedForms()
    {
        $notes = MigrationController::GetNotes();
        $count = 0;
        foreach (array('shared_live', 'scheduled_source', 'hidden_source') as $outcome) {
            $count += isset($notes['counts'][$outcome]) ? intval($notes['counts'][$outcome]) : 0;
        }

        $lines = array();
        foreach ($notes['rows'] as $row) {
            if (!isset($row['outcome'], $row['form'], $row['live'])) {
                continue;
            }
            if ($row['outcome'] === 'moved') {
                // Moved forms are counted as moved; only their rows tell which are shared
                $count++;
                $lines[] = sprintf('Form %1$s now uses the settings of post %2$d (%3$s); also on live posts: %4$s.', $row['form'], $row['to'], $row['to_status'], implode(', ', $row['live']));
            } else {
                $lines[] = $row['from'] > 0
                    ? sprintf('Form %1$s uses the settings of post %2$d (%3$s); also on live posts: %4$s.', $row['form'], $row['from'], $row['from_status'], implode(', ', $row['live']))
                    : sprintf('Form %1$s has no source post (%2$s); on live posts: %3$s.', $row['form'], $row['from_status'], implode(', ', $row['live']));
            }
        }
        if ($count === 0) {
            return;
        }
        if (count($lines) < $count) {
            $lines[] = sprintf('%d more not listed.', $count - count($lines));
        }
        // The run's time first, so a later run's summary isn't dropped as a repeat of this one
        array_unshift($lines, sprintf('%1$d registry entries checked, finished %2$s UTC.', array_sum($notes['counts']), gmdate('Y-m-d H:i:s')));
        LogController::LogInfo(sprintf('Forms also shown on other live posts: %d', $count), implode("\n", $lines));
    }

    /**
     * When the post named as a form's source no longer holds it, point the form at another post that shows
     * it, live (see PickLiveHolder) or else scheduled, and rebuild its config from there. The form moves only
     * to a post whose author can edit the post it leaves, so a copy never receives another author's form; a
     * form that stays waits to be deleted, and only users who may edit the post it left take it later (see
     * MayTakePendingForm).
     *
     * @param array $registry The registry being updated (by reference).
     * @return bool Whether another post took the form.
     */
    private static function MoveToOtherHolder(&$registry, $form_id, $exclude_post_id)
    {
        $live = self::GetHoldersWhoseAuthorCanEdit(self::FindPostsHoldingForm($form_id, self::$live_statuses, $exclude_post_id), $exclude_post_id);
        $target = self::PickLiveHolder($live);
        if (!$target) {
            $scheduled = self::GetHoldersWhoseAuthorCanEdit(self::FindPostsHoldingForm($form_id, array('future'), $exclude_post_id), $exclude_post_id);
            $target = empty($scheduled) ? null : $scheduled[0];
        }
        if (!$target) {
            return false;
        }
        $block = self::FindFormBlock($form_id, parse_blocks($target->post_content));
        if (!$block) {
            return false;
        }

        self::StoreConfig($form_id, $block);
        $new_entry = self::BuildEntry($block, $target);
        if (isset($registry[$form_id]['copied_from'])) {
            $new_entry['copied_from'] = $registry[$form_id]['copied_from'];
        }
        $registry[$form_id] = $new_entry;

        return true;
    }

    /**
     * Of the given holders, those whose author can edit a post.
     */
    private static function GetHoldersWhoseAuthorCanEdit($holders, $post_id)
    {
        $result = array();
        foreach ($holders as $holder) {
            if (user_can(intval($holder->post_author), 'edit_post', $post_id)) {
                $result[] = $holder;
            }
        }
        return $result;
    }

    /**
     * Of the given holders, those whose author may take the form of this entry (see MayTakePendingForm).
     */
    private static function GetHoldersWhoMayTake($holders, $entry)
    {
        $result = array();
        foreach ($holders as $holder) {
            if (self::MayTakePendingForm($entry, $holder->post_author)) {
                $result[] = $holder;
            }
        }
        return $result;
    }

    /**
     * The registry as stored now, read past the object cache; null when it no longer exists.
     */
    private static function GetStoredRegistry()
    {
        wp_cache_delete(self::OPTION_KEY, 'options');
        wp_cache_delete('notoptions', 'options');
        $alloptions = wp_load_alloptions();
        if (isset($alloptions[self::OPTION_KEY])) {
            wp_cache_delete('alloptions', 'options');
        }
        $registry = get_option(self::OPTION_KEY, null);
        return is_array($registry) ? $registry : null;
    }

    /**
     * Store the entries that differ between $before and $after over the registry as stored now, so entries
     * another request changed in the meantime stay as they are.
     *
     * @param array $before The entries as they were read.
     * @param array $after  The same entries with this request's changes.
     */
    private static function SaveRegistryChanges($before, $after)
    {
        $stored = self::GetStoredRegistry();
        $registry = $stored === null ? array() : $stored;
        foreach ($after as $form_id => $entry) {
            if (!array_key_exists($form_id, $before) || $before[$form_id] !== $entry) {
                $registry[$form_id] = $entry;
            }
        }
        foreach (array_keys($before) as $form_id) {
            if (!array_key_exists($form_id, $after)) {
                unset($registry[$form_id]);
            }
        }
        if ($registry !== $stored) {
            update_option(self::OPTION_KEY, $registry, false);
        }
    }

    /**
     * What a recent holder search found for a form whose registry entry is still $entry: 'no_move' (no live
     * page for FollowLiveHolder to follow) or 'none' (no post holds the form), else ''. It keeps the admin
     * views and the submissions of forms that can't be moved from searching the posts on every request. A
     * save or delete of a post with the form forgets it, and it expires after five minutes.
     */
    private static function GetScanResult($form_id, $entry)
    {
        $stored = get_transient(self::SCAN_RESULT_PREFIX . md5($form_id));
        if (!is_array($stored) || !isset($stored['entry'], $stored['found']) || $stored['entry'] !== md5(serialize($entry))) {
            return '';
        }
        return (string) $stored['found'];
    }

    private static function SetScanResult($form_id, $entry, $found)
    {
        set_transient(self::SCAN_RESULT_PREFIX . md5($form_id), array('entry' => md5(serialize($entry)), 'found' => $found), 5 * MINUTE_IN_SECONDS);
    }

    private static function ForgetScanResults($form_ids)
    {
        foreach (array_unique(array_map('strval', $form_ids)) as $form_id) {
            delete_transient(self::SCAN_RESULT_PREFIX . md5($form_id));
        }
    }

    /**
     * How soon visitors see a post's forms: 2 live, 1 scheduled, 0 not at all (draft, pending, trash...).
     */
    private static function StatusRank($status)
    {
        if (in_array($status, self::$live_statuses, true)) {
            return 2;
        }
        return $status === 'future' ? 1 : 0;
    }

    /**
     * Whether the registry may list the post being saved as a form's source.
     * - A post never takes a form from one that visitors see sooner, so a draft or a scheduled copy leaves a
     *   live page's form where it is (a Rewrite & Republish copy's forms go live when it is republished).
     * - A staging copy (see GetCopyOriginalId) never takes a form from the post it stands in for, and never
     *   keeps that post from taking it.
     * - A post that held the form before this save takes it back from posts visitors can't see.
     * - Otherwise the acting user must be able to edit every other post that holds the form. A live post that
     *   already held the form keeps it over newer live and scheduled posts (a form shared before each copy got
     *   its own formId).
     * - A form that waits to be deleted goes only to a user who may take it (see MayTakePendingForm).
     */
    private static function MayClaimForm($form_id, $entry, $post, $user_id, $was_held)
    {
        if (!self::MayTakePendingForm($entry, $user_id)) {
            return false;
        }
        $holders = self::GetOtherHolders($form_id, $entry, $post->ID);
        if (empty($holders)) {
            return true;
        }

        $rank = self::StatusRank($post->post_status);
        $original_id = self::GetCopyOriginalId($post);
        foreach ($holders as $holder) {
            if ($original_id > 0 && intval($holder->ID) === $original_id) {
                return false;
            }
            if (self::IsCopyOf($holder, $post->ID)) {
                continue;
            }
            $holder_rank = self::StatusRank($holder->post_status);
            if ($rank < $holder_rank) {
                return false;
            }
            if ($was_held && $rank > 0 && $holder_rank === 0) {
                continue;
            }
            if ($user_id && user_can($user_id, 'edit_post', $holder->ID)) {
                continue;
            }
            // A live post that held the form keeps it over newer live and scheduled posts
            if (!$was_held || $rank < 2 || $holder->ID < $post->ID) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a post may hold a form block with this formId: when no other post holds the form, or when the
     * acting user can edit every post that does. The post's staging copies don't count, and neither does
     * $original_id, the post that the post being saved stands in for as a staging copy. A form that waits to be
     * deleted needs a user who may take it (see MayTakePendingForm).
     */
    private static function MayHoldForm($form_id, $post_id, $user_id, $original_id = 0)
    {
        $entry = self::Get($form_id);
        if (!self::MayTakePendingForm($entry, $user_id)) {
            return false;
        }
        foreach (self::GetOtherHolders($form_id, $entry, $post_id) as $holder) {
            if (($original_id > 0 && intval($holder->ID) === intval($original_id)) || self::IsCopyOf($holder, $post_id)) {
                continue;
            }
            if (!$user_id || !user_can($user_id, 'edit_post', $holder->ID)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Whether a user may take a form that waits to be deleted (its submissions and webhook secret with it): the
     * author of the post the form left, or a user who can edit that post (who can edit others' posts, once that
     * post is deleted). Any user may take other forms, and the entries of earlier versions that name no post.
     */
    private static function MayTakePendingForm($entry, $user_id)
    {
        if (!is_array($entry) || empty($entry['pending_delete']) || empty($entry['last_source_post_id'])) {
            return true;
        }
        $user_id = intval($user_id);
        if ($user_id <= 0) {
            return false;
        }
        if (isset($entry['last_source_author']) && intval($entry['last_source_author']) === $user_id) {
            return true;
        }
        $last_id = intval($entry['last_source_post_id']);
        return get_post($last_id) ? user_can($user_id, 'edit_post', $last_id) : user_can($user_id, 'edit_others_posts');
    }

    /**
     * Whether a post is a staging copy of another (see GetCopyOriginalId).
     */
    private static function IsCopyOf($post, $original_id)
    {
        return intval($original_id) > 0 && self::GetCopyOriginalId($post) === intval($original_id);
    }

    /**
     * The post a staging copy stands in for, whose forms are that post's own being edited for republishing:
     * the original of a Rewrite & Republish copy (Duplicate Post), or the published post of a revision
     * (PublishPress Revisions: post_mime_type draft-revision, pending-revision or future-revision, the
     * Statuses Pro revision statuses, or any status the plugin's rvy_is_revision_status() accepts, with the
     * published post's ID in _rvy_base_post_id and comment_count). 0 for other posts.
     *
     * @param \WP_Post|null $post      The stored post; null for a post being inserted.
     * @param array         $post_data For a post being inserted: its post_mime_type and comment_count.
     */
    public static function GetCopyOriginalId($post, $post_data = array())
    {
        if ($post && get_post_meta($post->ID, '_dp_is_rewrite_republish_copy', true)) {
            return intval(get_post_meta($post->ID, '_dp_original', true));
        }
        if ($post) {
            $post_data = array(
                'post_mime_type' => $post->post_mime_type,
                'comment_count' => isset($post->comment_count) ? $post->comment_count : 0,
            );
        }
        $mime_type = isset($post_data['post_mime_type']) ? $post_data['post_mime_type'] : '';
        if (!is_string($mime_type) || $mime_type === '') {
            return 0;
        }
        $revision_statuses = array('draft-revision', 'pending-revision', 'future-revision', 'revision-deferred', 'revision-needs-work', 'revision-rejected');
        if (!in_array($mime_type, $revision_statuses, true) && !(function_exists('rvy_is_revision_status') && rvy_is_revision_status($mime_type))) {
            return 0;
        }
        $base_id = $post ? intval(get_post_meta($post->ID, '_rvy_base_post_id', true)) : 0;
        if ($base_id === 0 && isset($post_data['comment_count'])) {
            $base_id = intval($post_data['comment_count']);
        }
        return $base_id === ($post ? intval($post->ID) : 0) ? 0 : $base_id;
    }

    /**
     * The posts other than $post_id that hold a form: the registry's source post while it still holds the
     * form (with every live, scheduled, draft or pending post that holds it too while the source isn't live),
     * else every such post. Empty when $post_id is the source or no other post holds it.
     */
    private static function GetOtherHolders($form_id, $entry, $post_id)
    {
        // The registry filter, access control and save_post all ask during one save
        $key = self::RequestKey($form_id . '|' . intval($post_id) . '|' . md5(serialize($entry)));
        if (isset(self::$holders_cache[$key])) {
            return self::$holders_cache[$key];
        }

        $holders = null;
        if ($entry && empty($entry['pending_delete']) && !empty($entry['source_post_id'])) {
            $source_id = intval($entry['source_post_id']);
            if ($source_id === intval($post_id)) {
                $holders = array();
            } else {
                $source = get_post($source_id);
                if ($source && self::PostContainsForm($form_id, $source)) {
                    $holders = array($source);
                    // A source visitors can't see yet doesn't stand in for the other posts that show the form
                    if (self::StatusRank($source->post_status) < 2) {
                        foreach (self::FindPostsHoldingForm($form_id, self::$holder_statuses, $post_id) as $holder) {
                            if (intval($holder->ID) !== $source_id) {
                                $holders[] = $holder;
                            }
                        }
                    }
                }
            }
        }
        if ($holders === null) {
            $holders = self::FindPostsHoldingForm($form_id, self::$holder_statuses, $post_id);
        }

        self::$holders_cache[$key] = $holders;
        return $holders;
    }

    /**
     * The form block that another post holds, when the current user can edit every post that holds it.
     * FormAccessControl checks a form moved into the post being saved against it. Null otherwise, so nothing
     * is ever copied from a post the user can't edit.
     */
    public static function GetMovedFormBlock($form_id, $post_id)
    {
        $holders = self::GetOtherHolders($form_id, self::Get($form_id), $post_id);
        if (empty($holders)) {
            return null;
        }
        foreach ($holders as $holder) {
            if (!current_user_can('edit_post', $holder->ID)) {
                return null;
            }
        }

        return self::FindFormBlock($form_id, parse_blocks($holders[0]->post_content));
    }

    /**
     * The user a save acts for: the current user; with no one logged in, the post's author for a cron or WP-CLI
     * update of an existing post (a scheduled publish or republish); otherwise no one.
     *
     * @param int  $post_author The post's author.
     * @param bool $is_update   Whether the save updates an existing post.
     */
    private static function GetActingUserId($post_author, $is_update)
    {
        $user_id = get_current_user_id();
        if ($user_id) {
            return $user_id;
        }
        return self::IsSystemUpdate($is_update) ? intval($post_author) : 0;
    }

    /**
     * Whether a save with no one logged in is cron or WP-CLI updating an existing post.
     */
    public static function IsSystemUpdate($is_update)
    {
        return $is_update && (wp_doing_cron() || (defined('WP_CLI') && WP_CLI));
    }

    /**
     * The registry as the block editor sees it while editing a post. The editor gives a form a new formId
     * when the registry names another post as its source; the form keeps its formId when the post is:
     * - a staging copy of that source (see GetCopyOriginalId), whose forms replace the original's;
     * - a live post with the form while visitors can't see the source yet (see FollowLiveHolder).
     */
    public static function GetEditorRegistry($post_id)
    {
        $registry = self::GetAll();
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post || !self::MentionsForm($post->post_content)) {
            return $registry;
        }

        $original = self::GetCopyOriginalId($post);
        foreach (array_keys(self::CollectFormIds(parse_blocks($post->post_content))) as $form_id) {
            $form_id = (string) $form_id;
            if (empty($registry[$form_id]['source_post_id']) || intval($registry[$form_id]['source_post_id']) === intval($post->ID)) {
                continue;
            }
            $source_id = intval($registry[$form_id]['source_post_id']);
            $source = get_post($source_id);
            $source_hidden = !$source || self::StatusRank($source->post_status) < 2;
            if (($original > 0 && $original === $source_id) || (self::StatusRank($post->post_status) === 2 && $source_hidden)) {
                $registry[$form_id]['source_post_id'] = intval($post->ID);
            }
        }

        return $registry;
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
     * Search across post types for the post to rebuild a form from: a live post that holds it (see
     * PickLiveHolder), else the oldest unpublished one, so a form a live page shows is built from that page.
     * Only posts whose author may take a form that waits to be deleted count (see MayTakePendingForm).
     * Returns array('post_id' => int, 'post_type' => string) or null.
     *
     * @param array|null $entry The form's registry entry.
     */
    private static function FindFormInPosts($form_id, $entry = null)
    {
        $live = self::GetHoldersWhoMayTake(self::FindPostsHoldingForm($form_id, self::$live_statuses), $entry);
        if (!empty($live)) {
            $post = self::PickLiveHolder($live);
        } else {
            $unpublished = self::GetHoldersWhoMayTake(self::FindPostsHoldingForm($form_id, array('future', 'draft', 'pending')), $entry);
            $post = empty($unpublished) ? null : $unpublished[0];
        }

        if (!$post) {
            return null;
        }
        return array(
            'post_id' => $post->ID,
            'post_type' => $post->post_type,
        );
    }

    /**
     * Of the live posts that hold a form, the oldest whose author can edit all the others, so the original
     * stays the form's source rather than a copy; failing that, the oldest (usually the original), or null
     * when $strict.
     */
    private static function PickLiveHolder($holders, $strict = false)
    {
        foreach ($holders as $candidate) {
            $can_edit_all = true;
            foreach ($holders as $other) {
                if ($other->ID !== $candidate->ID && !user_can(intval($candidate->post_author), 'edit_post', $other->ID)) {
                    $can_edit_all = false;
                    break;
                }
            }
            if ($can_edit_all) {
                return $candidate;
            }
        }
        return empty($holders) || $strict ? null : $holders[0];
    }

    /**
     * Posts with one of the given statuses that hold a form, oldest first.
     */
    private static function FindPostsHoldingForm($form_id, $statuses, $exclude_post_id = 0)
    {
        global $wpdb;

        if ($form_id === '') {
            return array();
        }
        // GetConfig's FollowLiveHolder and RebuildConfig both look for the live posts
        $key = self::RequestKey($form_id . '|' . implode(',', $statuses) . '|' . intval($exclude_post_id));
        if (isset(self::$scans[$key])) {
            return self::$scans[$key];
        }

        // Matches the formId as saved in a form's attributes or markup; PostContainsForm confirms each post.
        // A direct query, so search filters and password rules for visitors can't hide a post.
        $types = self::$supported_post_types;
        $sql = "SELECT ID FROM {$wpdb->posts}
            WHERE post_type IN (" . implode(', ', array_fill(0, count($types), '%s')) . ")
            AND post_status IN (" . implode(', ', array_fill(0, count($statuses), '%s')) . ")
            AND (post_content LIKE %s OR post_content LIKE %s)";
        $args = array_merge($types, $statuses, array(
            '%' . $wpdb->esc_like('"formId":"' . $form_id . '"') . '%',
            '%' . $wpdb->esc_like('data-form-id="' . $form_id . '"') . '%',
        ));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare($sql, $args)));
        // Sorted here rather than in SQL, so MySQL can use the post type and status index
        sort($ids);

        $posts = array();
        foreach ($ids as $pid) {
            if (intval($pid) === intval($exclude_post_id)) {
                continue;
            }
            $post = get_post($pid);
            if ($post && self::PostContainsForm($form_id, $post)) {
                $posts[] = $post;
            }
        }

        self::$scans[$key] = $posts;
        return $posts;
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
