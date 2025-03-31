<?php

namespace SuperbAddons\Components\Buttons;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Components\Buttons\Button;
use SuperbAddons\Components\Buttons\ButtonIcon;
use SuperbAddons\Components\Buttons\ButtonType;

class PremiumButton
{
    public function __construct($source, $options = false, $additional_attributes = false)
    {
        $attributes = array(
            'text' => __('Get Premium', "superb-blocks"),
            'url' => AdminLinkUtil::GetLink($source, $options),
            'class' => 'superb-addons-template-library-template-item-premium-btn superbaddons-element-button-pro superbaddons-element-flex1 superbaddons-item-premium-element',
            'target' => '_blank',
        );

        if (!empty($additional_attributes)) {
            $attributes = array_merge($attributes, $additional_attributes);
        }

        new Button(
            ButtonType::Primary,
            ButtonIcon::ExternalLink,
            $attributes
        );
    }
}
