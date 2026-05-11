<?php

namespace SuperbAddons\Gutenberg\Controllers;

defined('ABSPATH') || exit();

use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\RestController;

use WP_Error;
use WP_REST_Server;
use WP_HTML_Tag_Processor;

class GutenbergDynamicContentController
{
    public static function Initialize()
    {
        self::InitializeEndpoints();
        add_filter('render_block', array(__CLASS__, 'FilterDynamicContent'), 10, 2);
    }

    private static function InitializeEndpoints()
    {
        RestController::AddRoute('/dynamic-content/resolve', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array(
                'SuperbAddons\Gutenberg\Controllers\GutenbergEnhancementsController',
                'OptionsCallbackPermissionCheck'
            ),
            'callback' => array(__CLASS__, 'ResolveCallback'),
        ));
    }

    // === REST API (editor preview) ===

    public static function ResolveCallback($request)
    {
        $type = isset($request['type']) ? sanitize_text_field($request['type']) : '';
        $post_id = isset($request['post_id']) ? max(0, intval($request['post_id'])) : 0;
        $format = isset($request['format']) ? sanitize_text_field($request['format']) : '';
        $kind = isset($request['kind']) ? sanitize_text_field($request['kind']) : 'value';

        if (empty($type)) {
            return new WP_Error('missing_type', 'Missing type parameter', array('status' => 400));
        }

        // Gate per-post reads so contributors can't enumerate drafts, private posts,
        // or password-protected content by calling the endpoint with arbitrary IDs.
        if ($post_id > 0 && !current_user_can('read_post', $post_id)) {
            return new WP_Error('rest_forbidden', 'You do not have permission to read this post.', array('status' => 403));
        }

        $value = $kind === 'link'
            ? self::ResolveLinkValue($type, $post_id, $format)
            : self::ResolveValue($type, $post_id, $format);

        return rest_ensure_response(array('value' => $value));
    }

    // === render_block filter (frontend rendering) ===

    public static function FilterDynamicContent($block_content, $block)
    {
        // Process dynamic values first (unwraps span, resolves text, preserves inner spans)
        if (strpos($block_content, 'spbadd-dynamic-value') !== false) {
            $block_content = self::ProcessDynamicValues($block_content);
        }

        // Then process dynamic links (converts span to <a>, preserves inner content)
        if (strpos($block_content, 'spbadd-dynamic-link') !== false) {
            $block_content = self::ProcessDynamicLinks($block_content);
        }

        return $block_content;
    }

    private static function ProcessDynamicValues($content)
    {
        while ($span = self::FindBalancedSpan($content, 'spbadd-dynamic-value')) {
            $type = self::ExtractAttribute($span['open_tag'], 'data-spbadd-dv-type');
            if (empty($type)) {
                // Unwrap the broken span and keep scanning the rest of the content.
                $content = substr_replace($content, $span['inner'], $span['start'], $span['end'] - $span['start']);
                continue;
            }

            $format = self::ExtractAttribute($span['open_tag'], 'data-spbadd-dv-format');

            // Use the span's inner text content as fallback (the user's selected text)
            $fallback_text = wp_strip_all_tags($span['inner']);

            $post_id = max(0, intval(get_the_ID()));
            $resolved = self::ResolveValue($type, $post_id, $format);
            if (empty($resolved) && !empty($fallback_text)) {
                $resolved = $fallback_text;
            }

            // Replace text content inside, preserving inner span wrappers (e.g. typing animation)
            $inner = self::ReplaceTextContent($span['inner'], esc_html($resolved));

            // Unwrap: replace the full dynamic-value span with just its (modified) inner HTML
            $content = substr_replace($content, $inner, $span['start'], $span['end'] - $span['start']);
        }

        return $content;
    }

    private static function ProcessDynamicLinks($content)
    {
        while ($span = self::FindBalancedSpan($content, 'spbadd-dynamic-link')) {
            $type = self::ExtractAttribute($span['open_tag'], 'data-spbadd-dl-type');
            if (empty($type)) {
                // Unwrap the broken span and keep scanning the rest of the content.
                $content = substr_replace($content, $span['inner'], $span['start'], $span['end'] - $span['start']);
                continue;
            }

            $target = self::ExtractAttribute($span['open_tag'], 'data-spbadd-dl-target');
            $fallback = self::ExtractAttribute($span['open_tag'], 'data-spbadd-dl-fallback');
            $format = self::ExtractAttribute($span['open_tag'], 'data-spbadd-dl-format');

            $post_id = max(0, intval(get_the_ID()));
            $url = self::ResolveLinkValue($type, $post_id, $format);

            if (empty($url) && !empty($fallback)) {
                $url = $fallback;
            }

            if (empty($url)) {
                // Remove the span wrapper but keep inner content
                $content = substr_replace($content, $span['inner'], $span['start'], $span['end'] - $span['start']);
                continue;
            }

            $target_attr = '';
            if ($target === '_blank') {
                $target_attr = ' target="_blank" rel="noopener noreferrer"';
            }

            // Replace span with <a> tag, preserving all inner content (including nested spans)
            $replacement = '<a href="' . esc_url($url) . '"' . $target_attr . '>' . $span['inner'] . '</a>';
            $content = substr_replace($content, $replacement, $span['start'], $span['end'] - $span['start']);
        }

        return $content;
    }

    /**
     * Find a span with the given class, properly handling nested spans.
     * Returns array with start, end, open_tag, inner HTML, or false if not found.
     */
    private static function FindBalancedSpan($content, $class_name)
    {
        $offset = 0;
        $start = -1;
        $open_tag = '';
        while (preg_match('/<span\s[^>]*>/s', $content, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $candidate = $match[0][0];
            $candidate_start = $match[0][1];
            $p = new WP_HTML_Tag_Processor($candidate);
            if ($p->next_tag() && $p->has_class($class_name)) {
                $start = $candidate_start;
                $open_tag = $candidate;
                break;
            }
            $offset = $candidate_start + 1;
        }
        if ($start === -1) {
            return false;
        }

        $pos = $start + strlen($open_tag);
        $depth = 1;
        $len = strlen($content);

        while ($depth > 0 && $pos < $len) {
            $next_open = strpos($content, '<span', $pos);
            $next_close = strpos($content, '</span>', $pos);

            if ($next_close === false) {
                break;
            }

            if ($next_open !== false && $next_open < $next_close) {
                $char_after = isset($content[$next_open + 5]) ? $content[$next_open + 5] : '';
                if ($char_after === ' ' || $char_after === '>' || $char_after === '/') {
                    $depth++;
                }
                $pos = $next_open + 5;
            } else {
                $depth--;
                if ($depth === 0) {
                    $end = $next_close + 7; // strlen('</span>')
                    $inner_start = $start + strlen($open_tag);
                    return array(
                        'start' => $start,
                        'end' => $end,
                        'open_tag' => $open_tag,
                        'inner' => substr($content, $inner_start, $next_close - $inner_start),
                    );
                }
                $pos = $next_close + 7;
            }
        }

        return false;
    }

    /**
     * Replace the text content within HTML, preserving inner span tags.
     * If no inner tags exist, replaces the entire content.
     */
    private static function ReplaceTextContent($html, $new_text)
    {
        if (strpos($html, '<') === false) {
            return $new_text;
        }
        $gt = strpos($html, '>');
        if ($gt === false) {
            return $new_text;
        }
        $lt = strpos($html, '<', $gt + 1);
        if ($lt === false) {
            return $new_text;
        }
        return substr($html, 0, $gt + 1) . $new_text . substr($html, $lt);
    }

    private static function ExtractAttribute($html, $attr_name)
    {
        $p = new WP_HTML_Tag_Processor($html);
        if (!$p->next_tag()) {
            return '';
        }
        $value = $p->get_attribute($attr_name);
        return is_string($value) ? $value : '';
    }

    // === Value Resolution ===

    private static function ResolveValue($type, $post_id, $format)
    {
        $type = sanitize_text_field($type);
        $format = sanitize_text_field($format);

        switch ($type) {
            case 'postID':
                return strval($post_id);

            case 'postTitle':
                return get_the_title($post_id);

            case 'postContent':
                $post = get_post($post_id);
                if (!$post) {
                    return '';
                }
                $post_content = wp_strip_all_tags($post->post_content);
                $max_length = !empty($format) ? intval($format) : 200;
                if ($max_length > 0 && mb_strlen($post_content, 'UTF-8') > $max_length) {
                    $post_content = mb_substr($post_content, 0, $max_length, 'UTF-8') . '...';
                }
                return $post_content;

            case 'postExcerpt':
                $post = get_post($post_id);
                return isset($post->post_excerpt) ? wp_strip_all_tags($post->post_excerpt) : '';

            case 'postDate':
                $date_format = !empty($format) ? $format : get_option('date_format');
                return get_the_date($date_format, $post_id);

            case 'postTime':
                $time_format = !empty($format) ? $format : get_option('time_format');
                return get_the_time($time_format, $post_id);

            case 'postType':
                $post_type_obj = get_post_type_object(get_post_type($post_id));
                return $post_type_obj ? $post_type_obj->labels->singular_name : '';

            case 'postStatus':
                $status = get_post_status($post_id);
                $status_obj = get_post_status_object($status);
                return $status_obj ? $status_obj->label : $status;

            case 'siteTitle':
                return get_bloginfo('name');

            case 'siteTagline':
                return get_bloginfo('description');

            case 'authorName':
                $author_id = get_post_field('post_author', $post_id);
                return get_the_author_meta('display_name', $author_id);

            case 'authorDescription':
                $author_id = get_post_field('post_author', $post_id);
                return wp_strip_all_tags(get_the_author_meta('description', $author_id));

            case 'loggedInUserName':
                GutenbergCacheUtil::MarkAsUncacheable();
                $user = wp_get_current_user();
                return $user->exists() ? $user->display_name : '';

            case 'loggedInUserDescription':
                GutenbergCacheUtil::MarkAsUncacheable();
                $user = wp_get_current_user();
                return $user->exists() ? $user->description : '';

            case 'loggedInUserEmail':
                GutenbergCacheUtil::MarkAsUncacheable();
                $user = wp_get_current_user();
                return $user->exists() ? $user->user_email : '';

            case 'archiveTitle':
                return is_archive() ? wp_strip_all_tags(get_the_archive_title()) : '';

            case 'archiveDescription':
                return is_archive() ? wp_strip_all_tags(get_the_archive_description()) : '';

            case 'currentDate':
                GutenbergCacheUtil::MarkAsUncacheable();
                $date_format = !empty($format) ? $format : get_option('date_format');
                return current_time($date_format);

            case 'currentTime':
                GutenbergCacheUtil::MarkAsUncacheable();
                $time_format = !empty($format) ? $format : get_option('time_format');
                return current_time($time_format);

            default:
                /* Filter callbacks that resolve to user-specific data must call
                GutenbergCacheUtil::MarkAsUncacheable() to prevent state leaks via
                page caches. */
                $result = apply_filters('superbaddons_resolve_dynamic_value', '', $type, $post_id, $format);
                return is_string($result) ? $result : '';
        }
    }

    private static function ResolveLinkValue($type, $post_id, $format)
    {
        $type = sanitize_text_field($type);
        $format = sanitize_text_field($format);

        switch ($type) {
            case 'postURL':
                return get_permalink($post_id);

            case 'featuredImageURL':
                $thumbnail_id = get_post_thumbnail_id($post_id);
                if (!$thumbnail_id) {
                    return '';
                }
                $image_url = wp_get_attachment_url($thumbnail_id);
                return $image_url ? $image_url : '';

            case 'authorURL':
                $author_id = get_post_field('post_author', $post_id);
                return get_author_posts_url($author_id);

            case 'authorWebsite':
                $author_id = get_post_field('post_author', $post_id);
                return get_the_author_meta('url', $author_id);

            default:
                /* Filter callbacks that resolve to user-specific URLs must call
                GutenbergCacheUtil::MarkAsUncacheable() to prevent state leaks via
                page caches. */
                $result = apply_filters('superbaddons_resolve_dynamic_link', '', $type, $post_id, $format);
                return is_string($result) ? $result : '';
        }
    }
}
