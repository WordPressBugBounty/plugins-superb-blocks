<?php

namespace SuperbAddons\Admin\Utils;

class AdminLinkUtil
{
    public static function GetLinkID()
    {
        return apply_filters('superb_addons_link_id', '');
    }

    public static function GetLink($source, $options = false)
    {
        if (!in_array($source, AdminLinkSource::ALLOWED_SOURCE)) {
            $source = AdminLinkSource::DEFAULT;
        }
        $args = array(
            'su_source' => $source,
        );
        $id = self::GetLinkID();
        if (!empty($id)) {
            $args['ref'] = substr(sanitize_text_field($id), 0, 25);
        }
        $url = is_array($options) && isset($options['url']) ? $options['url'] : 'https://superbthemes.com/superb-addons/';
        if (is_array($options) && isset($options['anchor'])) {
            $url .= '#' . $options['anchor'];
        }
        return add_query_arg($args, $url);
    }
}

class AdminLinkSource
{
    const DEFAULT = 'superb-addons';
    const NOTICE = 'notice';
    const WP_PLUGIN_PAGE = 'plugin-page';
    const NAVIGATION = 'navigation';
    const SETTINGS = 'settings';
    const LIBRARY_ITEM = 'pattern-library';
    const LIBRARY_PAGE_ITEM = 'prebuilt-pages';
    const DESIGNER = 'designer';
    const CSS = 'css';
    const CSS_TARGET = 'css-target';
    const CSS_EXPORT = 'css-export';
    const SUPPORT = 'support';

    const ALLOWED_SOURCE = array(
        self::NOTICE,
        self::WP_PLUGIN_PAGE,
        self::NAVIGATION,
        self::SETTINGS,
        self::LIBRARY_ITEM,
        self::LIBRARY_PAGE_ITEM,
        self::DESIGNER,
        self::CSS,
        self::CSS_TARGET,
        self::CSS_EXPORT,
        self::SUPPORT
    );
}
