<?php

namespace SuperbAddons\Components\Admin;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Components\Admin\LinkBox;

class FeatureRequestBox
{
    public function __construct()
    {
        new LinkBox(
            array(
                "icon" => "purple-bulb.svg",
                "title" => __("Request a feature", "superb-blocks"),
                "description" => __("We're always looking for ways to improve Superb Addons. If you have a feature request or suggestion, we'd love to hear from you.", "superb-blocks"),
                "cta" => __("Request feature", "superb-blocks"),
                "link" => AdminLinkUtil::GetLink(AdminLinkSource::DEFAULT, array("url" => "https://superbthemes.com/contact/"))
            )
        );
    }
}
