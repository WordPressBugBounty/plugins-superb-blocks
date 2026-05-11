<?php

namespace SuperbAddons\Components\Admin;

defined('ABSPATH') || exit();

class InputApiKey
{
    private $Id;
    private $Integration;
    private $Title;
    private $Description;
    private $DashboardUrl;
    private $DashboardLabel;
    private $Configured;
    private $MaskedKey;
    private $Placeholder;
    private $IconPath;

    /**
     * @param string $id              HTML element ID prefix (used for input, save btn, remove btn, spinner).
     * @param string $integration     Integration slug sent to the REST API (e.g. "mailchimp", "brevo").
     * @param string $title           Heading label.
     * @param string $description     Help text shown below the heading.
     * @param string $dashboard_url   URL to the provider's dashboard for obtaining API keys.
     * @param string $dashboard_label Link text for the dashboard URL.
     * @param bool   $configured      Whether an API key is currently stored.
     * @param string $masked_key      Masked representation of the stored key.
     * @param string $placeholder     Placeholder text for the input field.
     */
    public function __construct($id, $integration, $title, $description, $dashboard_url, $dashboard_label, $configured, $masked_key = '', $placeholder = '')
    {
        $this->Id = $id;
        $this->Integration = $integration;
        $this->Title = $title;
        $this->Description = $description;
        $this->DashboardUrl = $dashboard_url;
        $this->DashboardLabel = $dashboard_label;
        $this->Configured = $configured;
        $this->MaskedKey = $masked_key;
        $this->Placeholder = $placeholder;

        switch ($integration) {
            case 'mailchimp':
                $this->IconPath = SUPERBADDONS_ASSETS_PATH . '/img/mailchimp-icon.svg';
                break;
            case 'brevo':
                $this->IconPath = SUPERBADDONS_ASSETS_PATH . '/img/brevo-icon.svg';
                break;
            default:
                $this->IconPath = SUPERBADDONS_ASSETS_PATH . '/img/purple-plugs.svg';
        }

        $this->Render();
    }

    private function Render()
    {
?>
        <div class="superbaddons-integration-card <?php echo $this->Configured ? 'superbaddons-integration-card--connected' : ''; ?>" data-integration="<?php echo esc_attr($this->Integration); ?>">
            <div class="superbaddons-integration-card-header">
                <img class="superbaddons-integration-card-logo" src="<?php echo esc_url($this->IconPath); ?>" />
                <h5 class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-element-text-800 superbaddons-element-m0"><?php echo esc_html($this->Title); ?></h5>
                <?php if ($this->Configured) : ?>
                    <span class="superbaddons-integration-card-badge superbaddons-integration-card-badge--connected"><?php echo esc_html__("Connected", "superb-blocks"); ?></span>
                <?php else : ?>
                    <span class="superbaddons-integration-card-badge"><?php echo esc_html__("Not Connected", "superb-blocks"); ?></span>
                <?php endif; ?>
            </div>
            <div class="superbaddons-integration-card-body">
                <?php if ($this->Configured) : ?>
                    <div class="superbaddons-integration-card-connected-info">
                        <code class="superbaddons-input-api-key-masked"><?php echo esc_html($this->MaskedKey); ?></code>
                        <button type="button" class="superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-button-sm superbaddons-input-api-key-remove-btn" id="<?php echo esc_attr($this->Id); ?>-remove-btn"><img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" /><?php echo esc_html__("Disconnect", "superb-blocks"); ?></button>
                    </div>
                <?php else : ?>
                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-integration-card-description"><?php echo esc_html($this->Description); ?></p>
                    <div class="superbaddons-input-api-key-input-row">
                        <input id="<?php echo esc_attr($this->Id); ?>-input" type="text" class="superbaddons-input-api-key-input superbaddons-input-masked" placeholder="<?php echo esc_attr($this->Placeholder); ?>" autocomplete="off" />
                        <button type="button" class="superbaddons-element-button superbaddons-element-button-sm superbaddons-input-api-key-save-btn" id="<?php echo esc_attr($this->Id); ?>-save-btn" disabled><?php echo esc_html__("Connect", "superb-blocks"); ?></button>
                    </div>
                    <div class="superbaddons-spinner-wrapper superbaddons-input-api-key-spinner" id="<?php echo esc_attr($this->Id); ?>-spinner" style="display:none;">
                        <img class="spbaddons-spinner" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . "/img/blocks-spinner.svg"); ?>" />
                    </div>
                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><a href="<?php echo esc_url($this->DashboardUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($this->DashboardLabel); ?></a></p>
                <?php endif; ?>
            </div>
        </div>
<?php
    }
}
