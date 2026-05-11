<?php

namespace SuperbAddons\Gutenberg\Controllers;

defined('ABSPATH') || exit();

use WP_HTML_Tag_Processor;

class GutenbergStickyController
{
    const FRONTEND_SCRIPT_HANDLE = 'superbaddons-sticky';

    public static function Initialize()
    {
        add_filter('render_block', array(__CLASS__, 'FilterStickyRender'), 9, 2);
    }

    public static function FilterStickyRender($block_content, $block)
    {
        if (empty($block_content)) {
            return $block_content;
        }

        if (isset($block['blockName']) && $block['blockName'] === 'superb-addons/popup') {
            return $block_content;
        }

        $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : array();
        if (empty($attrs['spbaddStickyEnabled'])) {
            return $block_content;
        }

        $position = isset($attrs['spbaddStickyPosition']) ? $attrs['spbaddStickyPosition'] : 'top';
        if (!in_array($position, array('top', 'bottom'), true)) {
            $position = 'top';
        }

        $scope = isset($attrs['spbaddStickyScope']) ? $attrs['spbaddStickyScope'] : 'screen';
        if (!in_array($scope, array('screen', 'parent', 'top-level'), true)) {
            $scope = 'screen';
        }

        $offset = self::SanitizeOffset(isset($attrs['spbaddStickyOffset']) ? $attrs['spbaddStickyOffset'] : '0px');

        $disable_on = array();
        if (isset($attrs['spbaddStickyDisableOn']) && is_array($attrs['spbaddStickyDisableOn'])) {
            $disable_on = array_values(array_intersect(
                $attrs['spbaddStickyDisableOn'],
                array('desktop', 'tablet', 'mobile')
            ));
        }

        $processor = new WP_HTML_Tag_Processor($block_content);
        if (!$processor->next_tag()) {
            return $block_content;
        }

        $processor->set_attribute('data-spbadd-sticky', 'true');
        $processor->set_attribute('data-spbadd-sticky-position', $position);
        $processor->set_attribute('data-spbadd-sticky-scope', $scope);

        if (!empty($disable_on)) {
            $processor->set_attribute('data-spbadd-sticky-disable', implode(',', $disable_on));
        }

        $existing_style = $processor->get_attribute('style');
        $existing_style = is_string($existing_style) ? $existing_style : '';
        if ($existing_style !== '' && substr($existing_style, -1) !== ';') {
            $existing_style .= ';';
        }
        $existing_style .= '--spbadd-sticky-offset:' . $offset . ';--spbadd-sticky-z:10;';
        $processor->set_attribute('style', $existing_style);

        wp_enqueue_script(
            self::FRONTEND_SCRIPT_HANDLE,
            SUPERBADDONS_ASSETS_PATH . '/js/dynamic-blocks/sticky.js',
            array(),
            SUPERBADDONS_VERSION,
            true
        );

        return $processor->get_updated_html();
    }

    private static function SanitizeOffset($raw)
    {
        $raw = is_string($raw) ? trim($raw) : (string) $raw;
        if ($raw === '') {
            return '0px';
        }
        if (preg_match('/^-?\d*\.?\d+$/', $raw)) {
            return $raw . 'px';
        }
        if (preg_match('/^-?\d*\.?\d+(px|rem|em|vh|%)$/i', $raw)) {
            return strtolower($raw);
        }
        return '0px';
    }
}
