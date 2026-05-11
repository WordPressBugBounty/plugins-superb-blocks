<?php

namespace SuperbAddons\Gutenberg\Popup;

defined('ABSPATH') || exit();

/**
 * Filters the rendered output of core/button blocks that have a
 * spbaddPopupTarget attribute, converting them into popup trigger buttons.
 *
 * The spbaddPopupTarget attribute is stored only in the block comment
 * delimiter (not in the saved HTML), so deactivating this plugin causes
 * no block validation errors — the button simply renders normally.
 */
class PopupButtonRender
{
    public static function Initialize()
    {
        add_filter('render_block_core/button', array(__CLASS__, 'MaybeRenderPopupTrigger'), 10, 2);
    }

    /**
     * If a core/button has the spbaddPopupTarget attribute, modify its
     * rendered HTML to act as a popup trigger.
     */
    public static function MaybeRenderPopupTrigger($block_content, $block)
    {
        if (empty($block['attrs']['spbaddPopupTarget'])) {
            return $block_content;
        }

        $popup_target = sanitize_text_field($block['attrs']['spbaddPopupTarget']);
        if (empty($popup_target)) {
            return $block_content;
        }

        $processor = new \WP_HTML_Tag_Processor($block_content);

        // Find the inner <a> element (core/button renders as <div><a>...</a></div>)
        if ($processor->next_tag('a')) {
            $processor->set_attribute('data-popup-target', $popup_target);
            $processor->set_attribute('aria-haspopup', 'dialog');
            $processor->set_attribute('role', 'button');
            // Remove link-specific attributes since this is now a button
            $processor->remove_attribute('href');
            $processor->remove_attribute('target');
            $processor->remove_attribute('rel');
        }

        return $processor->get_updated_html();
    }
}
