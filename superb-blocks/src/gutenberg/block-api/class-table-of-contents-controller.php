<?php

namespace SuperbAddons\Gutenberg\BlocksAPI\Controllers;

use SuperbAddons\Data\Controllers\LogController;
use Exception;

defined('ABSPATH') || exit();

class TableOfContentsController
{
    private static $cached_toc = null;
    private static $anchor_map = array();
    private static $anchor_map_index = array();
    private static $excluded_levels = array();
    private static $instance_count = 0;

    public static function Initialize()
    {
        add_action('wp', array(__CLASS__, 'setupTOC'));
    }

    /**
     * Runs on the `wp` hook (before block rendering).
     * If the current post uses a TOC block, parse headings and
     * optionally register a render_block filter to inject anchor IDs.
     */
    public static function setupTOC()
    {
        $post = get_post();
        if (!$post || !has_block('superb-addons/table-of-contents', $post)) {
            return;
        }

        self::$cached_toc = null;
        self::$anchor_map = array();
        self::$anchor_map_index = array();
        self::$excluded_levels = array();

        $blocks = parse_blocks($post->post_content);

        // Find the TOC block to get its attributes
        $toc_attributes = self::findTocBlockAttributes($blocks);
        $auto_anchor_links = isset($toc_attributes['autoAnchorLinks']) ? (bool) $toc_attributes['autoAnchorLinks'] : true;
        $excluded_levels = isset($toc_attributes['excludedHeadingLevels']) ? $toc_attributes['excludedHeadingLevels'] : array();
        if (!is_array($excluded_levels)) {
            $excluded_levels = array();
        }
        if (!empty($excluded_levels)) {
            $excluded_levels = array_map('intval', $excluded_levels);
        }

        self::$excluded_levels = $excluded_levels;

        $headings = array();
        self::extractHeadingsAndBuildAnchors($blocks, $headings, $auto_anchor_links, $excluded_levels);
        $toc = self::buildTableOfContents($headings);

        self::$cached_toc = $toc;

        // Register anchor injection filter when auto anchor links are enabled
        if ($auto_anchor_links && !empty(self::$anchor_map)) {
            add_filter('render_block', array(__CLASS__, 'injectHeadingAnchors'), 10, 2);
        }
    }

    /**
     * Find the TOC block in parsed blocks and return its attributes.
     */
    private static function findTocBlockAttributes($blocks)
    {
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'superb-addons/table-of-contents') {
                return $block['attrs'];
            }
            if (!empty($block['innerBlocks'])) {
                $result = self::findTocBlockAttributes($block['innerBlocks']);
                if ($result !== null) {
                    return $result;
                }
            }
        }
        return array();
    }

    /**
     * Dynamic render callback for the TOC block.
     */
    public static function DynamicRender($attributes, $content)
    {
        try {
            if (self::$cached_toc !== null) {
                $toc = self::$cached_toc;
            } else {
                // Fallback for REST/preview contexts
                $post = get_post();
                if (!$post) {
                    return '';
                }
                $blocks = parse_blocks($post->post_content);
                $auto_anchor_links = isset($attributes['autoAnchorLinks']) ? (bool) $attributes['autoAnchorLinks'] : true;
                $excluded_levels = isset($attributes['excludedHeadingLevels']) ? $attributes['excludedHeadingLevels'] : array();
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
        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/heading') {
                $text = isset($block['innerHTML']) ? wp_strip_all_tags($block['innerHTML']) : '';
                $level = isset($block['attrs']['level']) ? intval($block['attrs']['level']) : 2;

                // Skip excluded heading levels
                if (in_array($level, $excluded_levels)) {
                    continue;
                }
                $anchor = isset($block['attrs']['anchor']) ? $block['attrs']['anchor'] : false;

                if ($anchor) {
                    // Manual anchor set
                    $headings[] = array(
                        'title' => $text,
                        'level' => $level,
                        'anchor' => $anchor,
                    );
                } elseif ($auto_anchor_links && !empty($text)) {
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
            } elseif ($block['blockName'] === 'core/block' && isset($block['attrs']['ref'])) {
                // Reusable block — fetch and parse its content
                $reusable_post = get_post($block['attrs']['ref']);
                if ($reusable_post) {
                    $reusable_blocks = parse_blocks($reusable_post->post_content);
                    self::extractHeadingsRecursive($reusable_blocks, $headings, $auto_anchor_links, $seen_slugs, $excluded_levels);
                }
            }

            // Don't recurse into blocks whose headings aren't part of the page content flow
            $skip_blocks = array('superb-addons/popup', 'superb-addons/carousel', 'superb-addons/accordion-block', 'core/details');
            if (!in_array($block['blockName'], $skip_blocks, true) && !empty($block['innerBlocks'])) {
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
     * Resolve a color value: prefer WPC slug as CSS custom property,
     * then explicit raw value.
     */
    private static function resolveColor($attributes, $attrName)
    {
        $wpc = isset($attributes[$attrName . 'WPC']) ? $attributes[$attrName . 'WPC'] : '';
        $raw = isset($attributes[$attrName]) ? $attributes[$attrName] : '';
        if (!empty($wpc)) {
            return 'var(--wp--preset--color--' . esc_attr($wpc) . ')';
        }
        if (!empty($raw)) {
            return esc_attr($raw);
        }
        return '';
    }

    /**
     * Render the TOC HTML.
     */
    private static function render($attributes, $toc)
    {
        $alignment = isset($attributes['toolbarAlignment']) ? $attributes['toolbarAlignment'] : 'left';
        $label_enabled = isset($attributes['labelTitleEnabled']) ? (bool) $attributes['labelTitleEnabled'] : true;
        $label_title = isset($attributes['labelTitle']) ? $attributes['labelTitle'] : __('Table of Contents', 'superb-blocks');
        $font_size_title = isset($attributes['fontSizeTitle']) ? intval($attributes['fontSizeTitle']) : 32;
        $font_size_text = isset($attributes['fontSizeText']) ? intval($attributes['fontSizeText']) : 14;
        $list_style = isset($attributes['listStyle']) ? $attributes['listStyle'] : 'ordered';
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
            <nav class="superbaddons-tableofcontents superbaddons-tableofcontents-alignment-<?php echo esc_attr($alignment); ?>"<?php echo $label_enabled ? ' aria-labelledby="' . esc_attr($title_id) . '"' : ' aria-label="' . esc_attr($label_title) . '"'; ?>>
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
        <<?php echo tag_escape($tag); ?><?php if ($list_style_attr_value !== '') : ?> style="list-style-type:<?php echo esc_attr($list_style_attr_value); ?>"<?php endif; ?>>
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
        if ($block['blockName'] !== 'core/heading') {
            return $block_content;
        }

        // Skip excluded heading levels
        $level = isset($block['attrs']['level']) ? intval($block['attrs']['level']) : 2;
        if (!empty(self::$excluded_levels) && in_array($level, self::$excluded_levels)) {
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
