<?php

namespace SuperbAddons\Gutenberg\BlocksAPI\Controllers;

use SuperbAddons\Data\Controllers\LogController;
use SuperbAddons\Data\Controllers\OptionController;
use Exception;

defined('ABSPATH') || exit();

class TableOfContentsController
{
    private static $cached_toc = null;
    private static $anchor_map = array();
    private static $anchor_map_index = array();
    private static $excluded_levels = array();
    private static $instance_count = 0;

    // Blocks whose headings are not part of the linear page content flow:
    // flattenBlockTree() leaves their innerBlocks untouched and heading
    // extraction does not descend into them.
    private static $skip_blocks = array(
        'superb-addons/popup',
        'superb-addons/carousel',
        'superb-addons/accordion-block',
        'core/details',
        'core/query',
    );

    public static function Initialize()
    {
        // The template_include pass exists solely to serve the TOC block;
        // when the block is disabled in settings, skip the hook
        if (OptionController::IsBlockDisabled('table-of-contents')) {
            return;
        }

        add_filter('template_include', array(__CLASS__, 'setupTOC'));
    }

    public static function setupTOC($template = '')
    {
        try {
            // The TOC block can render from anywhere in the page's block tree
            // (template body, a template part, the post content, a pattern or a reusable block)
            $blocks = self::getFlattenedPageBlocks();

            // false => the page renders no TOC block; nothing to set up.
            $toc_attributes = self::findTocBlockAttributes($blocks);
            if ($toc_attributes === false) {
                return $template;
            }

            self::$cached_toc = null;
            self::$anchor_map = array();
            self::$anchor_map_index = array();
            self::$excluded_levels = array();

            $auto_anchor_links = isset($toc_attributes['autoAnchorLinks']) ? (bool) $toc_attributes['autoAnchorLinks'] : true;
            $excluded_levels = isset($toc_attributes['excludedHeadingLevels']) && is_array($toc_attributes['excludedHeadingLevels']) ? array_map('intval', $toc_attributes['excludedHeadingLevels']) : array();

            self::$excluded_levels = $excluded_levels;

            $headings = array();
            self::extractHeadingsAndBuildAnchors($blocks, $headings, $auto_anchor_links, $excluded_levels);
            self::$cached_toc = self::buildTableOfContents($headings);

            // Register anchor injection filter when auto anchor links are enabled
            if ($auto_anchor_links && !empty(self::$anchor_map)) {
                add_filter('render_block', array(__CLASS__, 'injectHeadingAnchors'), 10, 2);
            }
        } catch (Exception $ex) {
            LogController::HandleException($ex);
        }

        return $template;
    }

    private static function getFlattenedPageBlocks()
    {
        global $_wp_current_template_content;

        if (is_string($_wp_current_template_content) && $_wp_current_template_content !== '') {
            $root_markup = $_wp_current_template_content;
        } else {
            $post = get_post();
            $root_markup = ($post && isset($post->post_content) && is_string($post->post_content)) ? $post->post_content : '';
        }

        $visited = array();
        return self::parseAndFlatten($root_markup, $visited);
    }

    private static function parseAndFlatten($markup, &$visited)
    {
        if (!is_string($markup) || $markup === '') {
            return array();
        }
        $blocks = parse_blocks($markup);
        if (!is_array($blocks)) {
            return array();
        }
        return self::flattenBlockTree($blocks, $visited);
    }

    private static function flattenBlockTree($blocks, &$visited)
    {
        $result = array();
        if (!is_array($blocks)) {
            return $result;
        }

        foreach ($blocks as $block) {
            // parse_blocks() yields non-block entries (raw HTML) with no blockName.
            if (!is_array($block) || !isset($block['blockName'])) {
                continue;
            }

            $name = $block['blockName'];

            if ($name === 'core/template-part') {
                $result = array_merge($result, self::expandTemplatePart($block, $visited));
            } elseif ($name === 'core/post-content') {
                $result = array_merge($result, self::expandPostContent($visited));
            } elseif ($name === 'core/pattern') {
                $result = array_merge($result, self::expandPattern($block, $visited));
            } elseif ($name === 'core/block') {
                $result = array_merge($result, self::expandReusableBlock($block, $visited));
            } else {
                // Recurse into children unless the block is skip-listed.
                if (!in_array($name, self::$skip_blocks, true) && !empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                    $block['innerBlocks'] = self::flattenBlockTree($block['innerBlocks'], $visited);
                }
                $result[] = $block;
            }
        }

        return $result;
    }

    private static function expandTemplatePart($block, &$visited)
    {
        if (!isset($block['attrs']['slug']) || !is_string($block['attrs']['slug']) || $block['attrs']['slug'] === '') {
            return array();
        }

        // Skip site chrome up-front when the placement declares its area.
        $attr_area = isset($block['attrs']['area']) && is_string($block['attrs']['area']) ? $block['attrs']['area'] : '';
        if ($attr_area === 'header' || $attr_area === 'footer') {
            return array();
        }

        $theme = isset($block['attrs']['theme']) && is_string($block['attrs']['theme']) && $block['attrs']['theme'] !== '' ? $block['attrs']['theme'] : get_stylesheet();
        $template_part_id = $theme . '//' . $block['attrs']['slug'];

        $visit_key = 'part:' . $template_part_id;
        if (isset($visited[$visit_key])) {
            return array();
        }
        $visited[$visit_key] = true;

        if (!function_exists('get_block_template')) {
            return array();
        }
        $part = get_block_template($template_part_id, 'wp_template_part');
        if (!is_object($part) || !isset($part->content) || !is_string($part->content) || $part->content === '') {
            return array();
        }

        // Confirm the area against the resolved part before descending.
        if (isset($part->area) && is_string($part->area) && ($part->area === 'header' || $part->area === 'footer')) {
            return array();
        }

        return self::parseAndFlatten($part->content, $visited);
    }

    private static function expandPostContent(&$visited)
    {
        $post = get_post();
        if (!is_object($post) || !isset($post->ID)) {
            return array();
        }

        $visit_key = 'post:' . intval($post->ID);
        if (isset($visited[$visit_key])) {
            return array();
        }
        $visited[$visit_key] = true;

        if (!isset($post->post_content) || !is_string($post->post_content)) {
            return array();
        }

        return self::parseAndFlatten($post->post_content, $visited);
    }

    private static function expandPattern($block, &$visited)
    {
        if (!isset($block['attrs']['slug']) || !is_string($block['attrs']['slug']) || $block['attrs']['slug'] === '') {
            return array();
        }
        $slug = $block['attrs']['slug'];

        $visit_key = 'pattern:' . $slug;
        if (isset($visited[$visit_key])) {
            return array();
        }
        $visited[$visit_key] = true;

        if (!class_exists('WP_Block_Patterns_Registry')) {
            return array();
        }
        $registry = \WP_Block_Patterns_Registry::get_instance();
        if (!$registry->is_registered($slug)) {
            return array();
        }
        $pattern = $registry->get_registered($slug);
        if (!is_array($pattern) || !isset($pattern['content']) || !is_string($pattern['content'])) {
            return array();
        }

        return self::parseAndFlatten($pattern['content'], $visited);
    }

    private static function expandReusableBlock($block, &$visited)
    {
        if (!isset($block['attrs']['ref'])) {
            return array();
        }
        $ref = intval($block['attrs']['ref']);
        if ($ref <= 0) {
            return array();
        }

        // Keyed by post ID (same namespace as expandPostContent) so a reusable
        // block and core/post-content resolving to the same post expand once.
        $visit_key = 'post:' . $ref;
        if (isset($visited[$visit_key])) {
            return array();
        }
        $visited[$visit_key] = true;

        $reusable_post = get_post($ref);
        if (!is_object($reusable_post) || !isset($reusable_post->post_content) || !is_string($reusable_post->post_content)) {
            return array();
        }

        return self::parseAndFlatten($reusable_post->post_content, $visited);
    }

    private static function findTocBlockAttributes($blocks)
    {
        if (!is_array($blocks)) {
            return false;
        }
        foreach ($blocks as $block) {
            if (!is_array($block) || !isset($block['blockName'])) {
                continue;
            }
            if ($block['blockName'] === 'superb-addons/table-of-contents') {
                return isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
            }
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $result = self::findTocBlockAttributes($block['innerBlocks']);
                if ($result !== false) {
                    return $result;
                }
            }
        }
        return false;
    }

    /**
     * Dynamic render callback for the TOC block.
     */
    public static function DynamicRender($attributes, $content)
    {
        try {
            $attributes = is_array($attributes) ? $attributes : array();

            if (self::$cached_toc !== null) {
                $toc = self::$cached_toc;
            } else {
                // Fallback for REST / preview contexts, or when template_include
                // setup did not run (e.g. a TOC block nested inside a template
                // part). Headings come from the same flattened page tree; auto
                // anchor injection is unavailable here because the render_block
                // filter was not registered.
                $blocks = self::getFlattenedPageBlocks();
                $auto_anchor_links = isset($attributes['autoAnchorLinks']) ? (bool) $attributes['autoAnchorLinks'] : true;
                $excluded_levels = isset($attributes['excludedHeadingLevels']) && is_array($attributes['excludedHeadingLevels']) ? array_map('intval', $attributes['excludedHeadingLevels']) : array();
                $headings = array();
                self::extractHeadingsAndBuildAnchors($blocks, $headings, $auto_anchor_links, $excluded_levels);
                $toc = self::buildTableOfContents($headings);
            }

            $smooth_scroll = isset($attributes['smoothScroll']) ? (bool) $attributes['smoothScroll'] : true;
            if ($smooth_scroll) {
                wp_enqueue_script(
                    'superbaddons-toc-smooth-scroll',
                    SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/table-of-contents-smooth-scroll.js',
                    array(),
                    SUPERBADDONS_VERSION,
                    true
                );
            }

            return self::render($attributes, $toc);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return '';
        }
    }

    /**
     * Recursively extract headings from parsed blocks.
     * Builds anchor map for auto-anchor injection.
     */
    private static function extractHeadingsAndBuildAnchors($blocks, &$headings, $auto_anchor_links, $excluded_levels = array())
    {
        $seen_slugs = array();

        self::extractHeadingsRecursive($blocks, $headings, $auto_anchor_links, $seen_slugs, $excluded_levels);
    }

    private static function extractHeadingsRecursive($blocks, &$headings, $auto_anchor_links, &$seen_slugs, $excluded_levels = array())
    {
        if (!is_array($blocks)) {
            return;
        }
        if (!is_array($excluded_levels)) {
            $excluded_levels = array();
        }
        foreach ($blocks as $block) {
            // parse_blocks() can yield non-block array entries (e.g. raw HTML),
            // which have no blockName. Skip them rather than throwing.
            if (!is_array($block) || !isset($block['blockName'])) {
                continue;
            }
            if ($block['blockName'] === 'core/heading') {
                $inner_html = isset($block['innerHTML']) && is_string($block['innerHTML']) ? $block['innerHTML'] : '';
                $text = wp_strip_all_tags($inner_html);
                $level = isset($block['attrs']['level']) ? intval($block['attrs']['level']) : 2;

                // Skip excluded heading levels
                if (in_array($level, $excluded_levels, true)) {
                    continue;
                }
                // core/heading stores its manual anchor as the HTML `id` (block.json
                // declares `anchor` with source: "attribute"), so it is NOT present
                // in $block['attrs'] after parse_blocks(). Read attrs first as a
                // belt-and-braces fallback, then extract from innerHTML.
                $anchor = isset($block['attrs']['anchor']) && is_string($block['attrs']['anchor']) && $block['attrs']['anchor'] !== '' ? $block['attrs']['anchor'] : false;
                if ($anchor === false && $inner_html !== '') {
                    $manual_id = self::extractHeadingIdFromHtml($inner_html);
                    if ($manual_id !== '') {
                        $anchor = $manual_id;
                    }
                }

                if ($anchor) {
                    // Manual anchor set
                    $headings[] = array(
                        'title' => $text,
                        'level' => $level,
                        'anchor' => $anchor,
                    );
                } elseif ($auto_anchor_links && $text !== '') {
                    // Auto-generate anchor
                    $slug = sanitize_title($text);
                    if (isset($seen_slugs[$slug])) {
                        $seen_slugs[$slug]++;
                        $slug = $slug . '-' . $seen_slugs[$slug];
                    } else {
                        $seen_slugs[$slug] = 1;
                    }
                    $headings[] = array(
                        'title' => $text,
                        'level' => $level,
                        'anchor' => $slug,
                    );
                    // Store in anchor map for injection (array to handle duplicate texts)
                    if (!isset(self::$anchor_map[$text])) {
                        self::$anchor_map[$text] = array();
                    }
                    self::$anchor_map[$text][] = $slug;
                } else {
                    // No anchor, not auto-linked
                    $headings[] = array(
                        'title' => $text,
                        'level' => $level,
                        'anchor' => false,
                    );
                }
            }

            // Don't recurse into blocks whose headings aren't part of the page
            // content flow. Reference blocks (template parts, post content,
            // patterns, reusable blocks) were already inlined by flattenBlockTree().
            if (!in_array($block['blockName'], self::$skip_blocks, true) && !empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::extractHeadingsRecursive($block['innerBlocks'], $headings, $auto_anchor_links, $seen_slugs, $excluded_levels);
            }
        }
    }

    /**
     * Build nested table of contents from flat heading list.
     * PHP port of the JS nesting algorithm from headinghandler.js.
     */
    private static function buildTableOfContents($headings)
    {
        $toc = array();
        $top_level_headings = array();

        foreach ($headings as $heading) {
            $item = array(
                'title' => $heading['title'],
                'level' => $heading['level'],
                'anchor' => $heading['anchor'],
                'children' => array(),
            );

            // Reset all lower levels
            $max_level = empty($top_level_headings) ? 0 : max(array_keys($top_level_headings));
            for ($i = $item['level'] + 1; $i <= $max_level; $i++) {
                unset($top_level_headings[$i]);
            }

            // Set current level
            $top_level_headings[$item['level']] = &$item;

            // Find parent
            $parent = false;
            for ($i = $item['level'] - 1; $i > 0; $i--) {
                if (isset($top_level_headings[$i]) && $top_level_headings[$i] !== false) {
                    $parent = &$top_level_headings[$i];
                    break;
                }
            }

            if ($parent !== false) {
                $parent['children'][] = &$item;
            } else {
                $toc[] = &$item;
            }

            unset($item);
            unset($parent);
        }

        return $toc;
    }

    /**
     * Extract the `id` attribute from the first heading tag in a chunk of
     * heading-block innerHTML. Returns '' if none is present or HTML parsing
     * fails. core/heading's manual anchor lives here (source: "attribute"),
     * not in the block's attrs JSON.
     */
    private static function extractHeadingIdFromHtml($inner_html)
    {
        if (!is_string($inner_html) || $inner_html === '') {
            return '';
        }
        if (!class_exists('WP_HTML_Tag_Processor')) {
            return '';
        }
        $processor = new \WP_HTML_Tag_Processor($inner_html);
        while ($processor->next_tag()) {
            $tag = $processor->get_tag();
            if ($tag !== null && preg_match('/^H[1-6]$/', $tag)) {
                $id = $processor->get_attribute('id');
                if (is_string($id) && $id !== '') {
                    return $id;
                }
                return '';
            }
        }
        return '';
    }

    /*
     * Resolve a color value: prefer WPC slug as CSS custom property, then explicit raw value.
     */
    private static function resolveColor($attributes, $attrName)
    {
        $wpc = isset($attributes[$attrName . 'WPC']) && is_string($attributes[$attrName . 'WPC']) ? $attributes[$attrName . 'WPC'] : '';
        $raw = isset($attributes[$attrName]) && is_string($attributes[$attrName]) ? $attributes[$attrName] : '';
        if ($wpc !== '') {
            return 'var(--wp--preset--color--' . esc_attr($wpc) . ')';
        }
        if ($raw !== '') {
            return esc_attr($raw);
        }
        return '';
    }

    /*
     * Render the TOC HTML.
     */
    private static function render($attributes, $toc)
    {
        $alignment = isset($attributes['toolbarAlignment']) && is_string($attributes['toolbarAlignment']) ? $attributes['toolbarAlignment'] : 'left';
        if (!in_array($alignment, array('left', 'center', 'right'), true)) {
            $alignment = 'left';
        }
        $label_enabled = isset($attributes['labelTitleEnabled']) ? (bool) $attributes['labelTitleEnabled'] : true;
        $label_title = isset($attributes['labelTitle']) && is_string($attributes['labelTitle']) ? $attributes['labelTitle'] : __('Table of Contents', 'superb-blocks');
        $font_size_title = isset($attributes['fontSizeTitle']) ? intval($attributes['fontSizeTitle']) : 32;
        $font_size_text = isset($attributes['fontSizeText']) ? intval($attributes['fontSizeText']) : 14;
        $list_style = isset($attributes['listStyle']) && is_string($attributes['listStyle']) ? $attributes['listStyle'] : 'ordered';
        $use_ordered_list = $list_style === 'ordered';

        // Build inline style with CSS variables for colors
        $style_parts = array();
        $color_title = self::resolveColor($attributes, 'colorTitle');
        $color_text = self::resolveColor($attributes, 'colorText');
        $color_anchor = self::resolveColor($attributes, 'colorAnchor');
        if ($color_title) {
            $style_parts[] = '--superb-toc-title-color:' . $color_title;
        }
        if ($color_text) {
            $style_parts[] = '--superb-toc-text-color:' . $color_text;
        }
        if ($color_anchor) {
            $style_parts[] = '--superb-toc-anchor-color:' . $color_anchor;
        }
        $inline_style = !empty($style_parts) ? implode(';', $style_parts) : '';

        $smooth_scroll = isset($attributes['smoothScroll']) ? (bool) $attributes['smoothScroll'] : true;

        $wrapper_extra = array();
        if (!empty($inline_style)) {
            $wrapper_extra['style'] = $inline_style;
        }
        if ($smooth_scroll) {
            $wrapper_extra['data-smooth-scroll'] = 'true';
        }

        $wrapper_attributes = get_block_wrapper_attributes($wrapper_extra);

        self::$instance_count++;
        $title_id = 'superb-toc-title-' . self::$instance_count;

        ob_start();
?>
        <div <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped HTML attribute markup per WP core API.
                echo $wrapper_attributes;
                ?>>
            <nav class="superbaddons-tableofcontents superbaddons-tableofcontents-alignment-<?php echo esc_attr($alignment); ?>" <?php echo $label_enabled ? ' aria-labelledby="' . esc_attr($title_id) . '"' : ' aria-label="' . esc_attr($label_title) . '"'; ?>>
                <?php if ($label_enabled) : ?>
                    <span id="<?php echo esc_attr($title_id); ?>" class="superbaddons-tableofcontents-title" style="font-size:<?php echo esc_attr($font_size_title); ?>px;line-height:<?php echo esc_attr($font_size_title + 8); ?>px;"><?php echo wp_kses_post($label_title); ?></span>
                <?php endif; ?>
                <div class="superbaddons-tableofcontents-table">
                    <?php
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderList() returns HTML composed of values passed through tag_escape/esc_attr/esc_html within the method.
                    echo self::renderList($toc, $font_size_text, $use_ordered_list ? 'decimal' : '', $use_ordered_list);
                    ?>
                </div>
            </nav>
        </div>
    <?php
        return ob_get_clean();
    }

    /**
     * Recursively render nested lists (ordered or unordered).
     */
    private static function renderList($items, $font_size_text, $list_style_type, $use_ordered_list = true)
    {
        if (empty($items)) {
            return '';
        }

        $tag = $use_ordered_list ? 'ol' : 'ul';
        $list_style_attr_value = $use_ordered_list && $list_style_type !== '' ? $list_style_type : '';

        ob_start();
    ?>
        <<?php echo tag_escape($tag); ?><?php if ($list_style_attr_value !== '') : ?> style="list-style-type:<?php echo esc_attr($list_style_attr_value); ?>" <?php endif; ?>>
            <?php foreach ($items as $item) : ?>
                <li style="font-size:<?php echo esc_attr($font_size_text); ?>px;line-height:<?php echo esc_attr($font_size_text + 14); ?>px;">
                    <?php if ($item['anchor'] !== false) : ?>
                        <a href="#<?php echo esc_attr($item['anchor']); ?>"><?php echo esc_html($item['title']); ?></a>
                    <?php else : ?>
                        <span><?php echo esc_html($item['title']); ?></span>
                    <?php endif; ?>
                    <?php
                    if (!empty($item['children'])) {
                        // First nesting level uses lower-alpha, deeper uses lower-roman
                        $child_style = ($list_style_type === 'decimal') ? 'lower-alpha' : 'lower-roman';
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderList() returns HTML composed of values passed through tag_escape/esc_attr/esc_html within the method.
                        echo self::renderList($item['children'], $font_size_text, $use_ordered_list ? $child_style : '', $use_ordered_list);
                    }
                    ?>
                </li>
            <?php endforeach; ?>
        </<?php echo tag_escape($tag); ?>>
<?php
        return ob_get_clean();
    }

    /**
     * render_block filter callback — injects id attributes onto core/heading blocks.
     */
    public static function injectHeadingAnchors($block_content, $block)
    {
        // Other plugins on the same filter can return non-string content for
        // hidden/empty blocks; bail before handing it to WP_HTML_Tag_Processor.
        if (!is_string($block_content) || $block_content === '') {
            return $block_content;
        }
        if (!is_array($block) || !isset($block['blockName']) || $block['blockName'] !== 'core/heading') {
            return $block_content;
        }

        // Skip excluded heading levels
        $level = isset($block['attrs']['level']) ? intval($block['attrs']['level']) : 2;
        if (!empty(self::$excluded_levels) && in_array($level, self::$excluded_levels, true)) {
            return $block_content;
        }

        // Skip headings that already have an anchor attribute
        if (isset($block['attrs']['anchor']) && !empty($block['attrs']['anchor'])) {
            return $block_content;
        }

        $text = wp_strip_all_tags($block_content);
        if (empty($text) || !isset(self::$anchor_map[$text]) || empty(self::$anchor_map[$text])) {
            return $block_content;
        }

        // Track which anchor to use for duplicate heading texts
        if (!isset(self::$anchor_map_index[$text])) {
            self::$anchor_map_index[$text] = 0;
        }
        $index = self::$anchor_map_index[$text];
        if (!isset(self::$anchor_map[$text][$index])) {
            return $block_content;
        }

        $processor = new \WP_HTML_Tag_Processor($block_content);
        if ($processor->next_tag()) {
            $existing_id = $processor->get_attribute('id');
            if (empty($existing_id)) {
                $processor->set_attribute('id', self::$anchor_map[$text][$index]);
                self::$anchor_map_index[$text]++;
                $block_content = $processor->get_updated_html();
            }
        }

        return $block_content;
    }
}
