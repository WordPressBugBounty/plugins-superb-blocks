<?php

namespace SuperbAddons\Gutenberg\Controllers;

defined('ABSPATH') || exit();

use WP_HTML_Tag_Processor;

class GutenbergZIndexController
{
    const Z_INDEX_MIN = -100;
    const Z_INDEX_MAX = 999;

    public static function Initialize()
    {
        add_filter('render_block', array(__CLASS__, 'FilterZIndexRender'), 9, 2);
    }

    public static function FilterZIndexRender($block_content, $block)
    {
        if (empty($block_content)) {
            return $block_content;
        }

        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        if (!isset($attrs['spbaddZIndex']) || !is_numeric($attrs['spbaddZIndex'])) {
            return $block_content;
        }

        $zindex = intval($attrs['spbaddZIndex']);
        if ($zindex < self::Z_INDEX_MIN) {
            $zindex = self::Z_INDEX_MIN;
        } elseif ($zindex > self::Z_INDEX_MAX) {
            $zindex = self::Z_INDEX_MAX;
        }

        $processor = new WP_HTML_Tag_Processor($block_content);
        if (!$processor->next_tag()) {
            return $block_content;
        }

        $existing_style = $processor->get_attribute('style');
        $existing_style = is_string($existing_style) ? $existing_style : '';
        $existing_style_lower = strtolower($existing_style);

        $addition = 'z-index:' . $zindex . ';';
        // z-index only applies to positioned elements. Add position:relative as a
        // safe default when the block hasn't already set position explicitly.
        if (strpos($existing_style_lower, 'position:') === false) {
            $addition .= 'position:relative;';
        }

        if ($existing_style !== '' && substr($existing_style, -1) !== ';') {
            $existing_style .= ';';
        }
        $processor->set_attribute('style', $existing_style . $addition);

        return $processor->get_updated_html();
    }
}
