<?php

namespace SuperbAddons\Data\Utils\Wizard;

use SuperbAddons\Admin\Controllers\Wizard\WizardController;

defined('ABSPATH') || exit();

class WizardStageUtil
{
    private $type;
    private $stages;
    private $hasPatterns;
    private $hasPages;
    private $isRestore;

    private $templateProvider;

    public function __construct($type = false)
    {
        // If type is not set, get it from the query parameter
        // No need to verify nonce here, as we are simply reading the value to determine the type of stage and not submitting any data
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $this->type = !$type && isset($_GET[WizardController::ACTION_QUERY_PARAM]) ? sanitize_text_field(wp_unslash($_GET[WizardController::ACTION_QUERY_PARAM])) : $type;
        $this->Init();
    }

    private function Init()
    {
        // Set stages
        switch ($this->type) {
            case WizardActionParameter::THEME_DESIGNER:
                $this->hasPages = true;
                $this->hasPatterns = true;
                $this->isRestore = false;
                $this->stages = array_merge(WizardStageTypes::ALL_STAGES, array(WizardStageTypes::COMPLETION_STAGE));
                break;
            case WizardActionParameter::HEADER_FOOTER:
                $this->hasPages = false;
                $this->hasPatterns = true;
                $this->isRestore = false;
                $this->stages = [
                    WizardStageTypes::HEADER_STAGE,
                    WizardStageTypes::FOOTER_STAGE,
                    WizardStageTypes::COMPLETION_STAGE
                ];
                break;
            case WizardActionParameter::WOOCOMMERCE_HEADER:
                $this->hasPages = false;
                $this->hasPatterns = true;
                $this->isRestore = false;
                $this->stages = [
                    WizardStageTypes::HEADER_STAGE,
                    WizardStageTypes::COMPLETION_STAGE
                ];
                break;
            case WizardActionParameter::ADD_NEW_PAGES:
                $this->hasPages = true;
                $this->hasPatterns = false;
                $this->isRestore = false;
                $this->stages = [
                    WizardStageTypes::TEMPLATE_PAGE_STAGE,
                    WizardStageTypes::NAVIGATION_MENU_STAGE,
                    WizardStageTypes::COMPLETION_STAGE
                ];
                break;
            case WizardActionParameter::RESTORE:
                $this->hasPages = false;
                $this->hasPatterns = false;
                $this->isRestore = true;
                $this->stages = [
                    WizardStageTypes::HEADER_STAGE,
                    WizardStageTypes::FOOTER_STAGE,
                    WizardStageTypes::FRONT_PAGE_STAGE,
                    WizardStageTypes::BLOG_PAGE_STAGE,
                    WizardStageTypes::COMPLETION_STAGE
                ];
                break;
        }
    }

    public function InitializeTemplates()
    {
        $this->templateProvider = new WizardTemplateProvider();

        if ($this->HasPatterns()) {
            if ($this->GetType() === WizardActionParameter::WOOCOMMERCE_HEADER) {
                $this->templateProvider->InitializePatterns("woocommerce/woocommerce.php");
            } else {
                $this->templateProvider->InitializePatterns();
            }
        }

        if ($this->HasPages()) {
            $this->templateProvider->InitalizePageTemplates();
        }

        if ($this->IsRestore()) {
            $this->templateProvider->InitializeRestorationTemplates();
        }
    }

    /**
     * Get the value of type
     */
    public function GetType()
    {
        return $this->type;
    }

    /**
     * Get the value of stages
     */
    public function GetStages()
    {
        return $this->stages;
    }

    /**
     * Get the value of hasPatterns
     */
    public function HasPatterns()
    {
        return $this->hasPatterns;
    }

    /**
     * Get the value of hasPages
     */
    public function HasPages()
    {
        return $this->hasPages;
    }

    /**
     * Get the value of isRestore
     */
    public function IsRestore()
    {
        return $this->isRestore;
    }

    public function HasPageStages()
    {
        return !empty(array_intersect($this->stages, WizardStageTypes::PAGE_STAGES));
    }

    public function GetAvailableConfiguredStages()
    {
        $available_stages = [];
        $stage_configs = $this->GetStageConfigs();
        foreach ($this->stages as $stage_type) {
            if (!array_key_exists($stage_type, $stage_configs)) {
                continue;
            }

            if (!$stage_configs[$stage_type]['enabled']) {
                continue;
            }

            $available_stages[$stage_type] = $stage_configs[$stage_type];
        }

        return $available_stages;
    }

    public function GetMenuAvailability()
    {
        $displayReplaceMenu = $this->GetType() === WizardActionParameter::THEME_DESIGNER;
        $hasNavigationTemplatePart = WizardCreationUtil::HasNavigationTemplatePart();
        $displayAppendMenu = WizardCreationUtil::GetNavigationTemplatePartMenuId() !== false;

        return array(
            'replace' => $displayReplaceMenu,
            'append' => $displayAppendMenu,
            'available' => $hasNavigationTemplatePart && ($displayReplaceMenu || $displayAppendMenu)
        );
    }

    private function GetStageConfigs()
    {
        $menuAvailability = $this->GetMenuAvailability();
        $displayReplaceMenu = $menuAvailability['replace'];
        $displayAppendMenu = $menuAvailability['append'];
        $hasNavigationTemplatePart = $menuAvailability['available'];

        return [
            WizardStageTypes::HEADER_STAGE => [
                'enabled' => !empty($this->templateProvider->GetHeaderTemplates()),
                'templates' => $this->templateProvider->GetHeaderTemplates(),
                'type' => 'single-selection',
                'required' => true,
                'has-title-input' => false,
                'has-multiple-parts' => $this->templateProvider->HasMultipleHeaderParts(),
                'label' => __("Menu Layout", "superb-blocks"),
            ],
            WizardStageTypes::FOOTER_STAGE => [
                'enabled' => !empty($this->templateProvider->GetFooterTemplates()),
                'templates' => $this->templateProvider->GetFooterTemplates(),
                'type' => 'single-selection',
                'required' => true,
                'has-title-input' => false,
                'has-multiple-parts' => $this->templateProvider->HasMultipleFooterParts(),
            ],
            WizardStageTypes::FRONT_PAGE_STAGE => [
                'enabled' => !empty($this->templateProvider->GetFrontPageTemplates()),
                'templates' => $this->templateProvider->GetFrontPageTemplates(),
                'type' => 'single-selection',
                'required' => true,
                'has-title-input' => $this->GetType() !== WizardActionParameter::RESTORE,
                'input-suggestion' => __('Home', "superb-blocks"),
            ],
            WizardStageTypes::BLOG_PAGE_STAGE => [
                'enabled' => !empty($this->templateProvider->GetBlogTemplates()),
                'templates' => $this->templateProvider->GetBlogTemplates(),
                'type' => 'single-selection',
                'required' => true,
                'has-title-input' => $this->GetType() !== WizardActionParameter::RESTORE,
                'input-suggestion' => __('Blog', "superb-blocks"),
            ],
            WizardStageTypes::TEMPLATE_PAGE_STAGE => [
                'enabled' => !empty($this->templateProvider->GetTemplatePages()),
                'templates' => $this->templateProvider->GetTemplatePages(),
                'type' => 'multi-selection',
                'required' => $this->GetType() === WizardActionParameter::ADD_NEW_PAGES,
                'has-title-input' => $this->GetType() !== WizardActionParameter::RESTORE,
            ],
            WizardStageTypes::NAVIGATION_MENU_STAGE => [
                'enabled' => $displayReplaceMenu || $displayAppendMenu,
                'templates' => [],
                'type' => 'radio-checkbox',
                'required' => true,
                'has-title-input' => false,
                'unique_render' => true,
                'args' => [$displayReplaceMenu, $displayAppendMenu, $hasNavigationTemplatePart]
            ],
            WizardStageTypes::COMPLETION_STAGE => [
                'enabled' => true,
                'templates' => [],
                'type' => 'completion',
                'required' => false,
                'has-title-input' => false,
                'unique_render' => true
            ]
        ];
    }

    public function GetStageTitle($stageType)
    {
        $titles = [
            WizardStageTypes::HEADER_STAGE => [
                'default' => __("Select Menu Layout", "superb-blocks"),
                WizardActionParameter::RESTORE => __("Restore Header", "superb-blocks")
            ],
            WizardStageTypes::FOOTER_STAGE => [
                'default' => __("Select Footer Layout", "superb-blocks"),
                WizardActionParameter::RESTORE => __("Restore Footer", "superb-blocks")
            ],
            WizardStageTypes::FRONT_PAGE_STAGE => [
                'default' => __("Select Front Page", "superb-blocks"),
                WizardActionParameter::RESTORE => __("Restore Front Page", "superb-blocks")
            ],
            WizardStageTypes::BLOG_PAGE_STAGE => [
                'default' => __("Select Blog", "superb-blocks"),
                WizardActionParameter::RESTORE => __("Restore Blog", "superb-blocks")
            ],
            WizardStageTypes::TEMPLATE_PAGE_STAGE => [
                'default' => __("Select Additional Pages", "superb-blocks"),
                WizardActionParameter::ADD_NEW_PAGES => __("Select Pages", "superb-blocks"),
            ],
            WizardStageTypes::NAVIGATION_MENU_STAGE => [
                'default' => __("Update Navigation Menu", "superb-blocks")
            ],
            WizardStageTypes::COMPLETION_STAGE => [
                'default' => __("Summary", "superb-blocks")
            ]
        ];

        if (!isset($titles[$stageType][$this->GetType()])) {
            return $titles[$stageType]['default'];
        }

        return $titles[$stageType][$this->GetType()];
    }

    public function GetStageDescription($stageType)
    {
        $descriptions = [
            WizardStageTypes::HEADER_STAGE => [
                'default' => __("Select the menu layout you want to use as your header template.", "superb-blocks"),
                WizardActionParameter::RESTORE => __("Restore the header template to a previous state, or keep the current template.", "superb-blocks"),
            ],
            WizardStageTypes::FOOTER_STAGE => [
                'default' => __("Select the footer layout you want to use as your footer template.", "superb-blocks"),
                WizardActionParameter::RESTORE => __("Restore the footer template to a previous state, or keep the current template.", "superb-blocks"),
            ],
            WizardStageTypes::FRONT_PAGE_STAGE => [
                'default' => __("Select the page template you want to use as your front page.", "superb-blocks"),
                WizardActionParameter::RESTORE => __("Restore the front page template to a previous state, or keep the current template.", "superb-blocks"),
            ],
            WizardStageTypes::BLOG_PAGE_STAGE => [
                'default' => array(
                    __("Select the blog template you want to use as your blog page.", "superb-blocks"),
                    __("If your front page uses a blog template, you can’t select a separate blog page.", "superb-blocks"),
                ),
                WizardActionParameter::RESTORE => __("Restore the home or index template to a previous state, or keep the current template.", "superb-blocks"),
            ],
            WizardStageTypes::TEMPLATE_PAGE_STAGE => [
                'default' => __("Select the additional pages you'd like to have added to your website, if any.", "superb-blocks"),
                WizardActionParameter::ADD_NEW_PAGES => __("Select the pages you'd like to have added to your website.", "superb-blocks"),
            ],
            WizardStageTypes::NAVIGATION_MENU_STAGE => [
                'default' => __("Choose how you'd like to handle the theme navigation menu items, if a navigation block is available in the theme header template.", "superb-blocks"),
            ],
            WizardStageTypes::COMPLETION_STAGE => [
                'default' => __("Here’s a summary of your selections. If everything looks good, simply complete the Theme Designer to finalize your choices.", "superb-blocks"),
                WizardActionParameter::RESTORE => __("Here’s a summary of your selections. If everything looks good, simply complete the restoration to finalize your choices.", "superb-blocks"),
                WizardActionParameter::THEME_DESIGNER => array(__("Here’s a summary of your selections, where you can also update the page titles.", "superb-blocks"), __("If everything looks good, simply complete the Theme Designer to finalize your choices.", "superb-blocks")),
                WizardActionParameter::ADD_NEW_PAGES => array(__("Here’s a summary of your selected pages, where you can also update the page titles.", "superb-blocks"), __("If everything looks good, simply complete the Theme Designer to finalize your choices.", "superb-blocks")),
            ]
        ];

        $description = isset($descriptions[$stageType][$this->GetType()])
            ? $descriptions[$stageType][$this->GetType()]
            : (isset($descriptions[$stageType]['default']) ? $descriptions[$stageType]['default'] : '');

        if (is_array($description)) {
            return $description;
        }
        return $description !== '' ? array($description) : array();
    }

    public function GetStageLabel($stageType)
    {
        $labels = [
            WizardStageTypes::HEADER_STAGE => [
                'default' => __("Header", "superb-blocks"),
            ],
            WizardStageTypes::FOOTER_STAGE => [
                'default' => __("Footer", "superb-blocks"),
            ],
            WizardStageTypes::FRONT_PAGE_STAGE => [
                'default' => __("Front Page", "superb-blocks"),
            ],
            WizardStageTypes::BLOG_PAGE_STAGE => [
                'default' => __("Blog Page", "superb-blocks"),
            ],
            WizardStageTypes::TEMPLATE_PAGE_STAGE => [
                'default' => __("Additional Pages", "superb-blocks"),
                WizardActionParameter::ADD_NEW_PAGES => __("Pages", "superb-blocks"),
            ],
            WizardStageTypes::NAVIGATION_MENU_STAGE => [
                'default' => __("Navigation Menu", "superb-blocks"),
            ],
            WizardStageTypes::COMPLETION_STAGE => [
                'default' => __("Summary", "superb-blocks"),
            ]
        ];

        if (!isset($labels[$stageType][$this->GetType()])) {
            return $labels[$stageType]['default'];
        }

        return $labels[$stageType][$this->GetType()];
    }
}
