<?php

namespace SuperbAddons\Admin\Pages;

defined('ABSPATH') || exit();

use SuperbAddons\Admin\Controllers\DashboardController;
use SuperbAddons\Admin\Utils\AdminLinkSource;
use SuperbAddons\Admin\Utils\AdminLinkUtil;
use SuperbAddons\Components\Admin\Modal;
use SuperbAddons\Components\Admin\EncryptionNotice;
use SuperbAddons\Components\Admin\PremiumBox;
use SuperbAddons\Components\Admin\ReviewBox;
use SuperbAddons\Components\Admin\SupportBox;
use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Gutenberg\Controllers\GutenbergController;
use SuperbAddons\Gutenberg\Form\FormAccessControl;
use SuperbAddons\Gutenberg\Form\FormRegistry;
use SuperbAddons\Gutenberg\Form\FormSettings;
use SuperbAddons\Gutenberg\Form\FormSubmissionHandler;

class FormsPage
{
    private $form_id = '';
    private $form_name = '';
    private $forms = array();
    private $is_single_form = false;
    private $form_stats = array();
    private $form_entry = null;
    private $total_forms = 0;
    private $total_submissions = 0;
    private $total_unread = 0;

    public function __construct()
    {
        if (!GutenbergController::is_compatible()) {
            $this->RenderIncompatible();
            return;
        }

        // We can skip nonce verification here because we're not performing any sensitive actions based on this value, just using it to determine which view to show.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $this->form_id = isset($_GET['form_id']) ? sanitize_text_field(wp_unslash($_GET['form_id'])) : '';
        $this->is_single_form = !empty($this->form_id);

        if ($this->is_single_form) {
            $this->form_entry = FormRegistry::Get($this->form_id);
            $this->form_stats = FormSubmissionHandler::GetFormStats($this->form_id);
            // Form doesn't exist in registry and has no submissions
            if (!$this->form_entry && $this->form_stats['total'] === 0) {
                $this->RenderNotFound();
                return;
            }
            $this->LoadFormName();
        } else {
            $this->LoadFormsList();
        }

        $this->Render();
    }

    private function LoadFormName()
    {
        $this->form_name = FormRegistry::GetName($this->form_id);
    }

    private static function RenderAccessControlNotice()
    {
        if (!current_user_can(Capabilities::ADMIN) || FormAccessControl::IsEnabled()) {
            return;
        }
?>
        <div class="superbaddons-dashboard-suggest-card superbaddons-dashboard-suggest-card--sidebar">
            <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-shield-check.svg'); ?>" alt="" aria-hidden="true" />
            <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html__('Access Control', 'superb-blocks'); ?></strong>
            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__('All users with editor access currently have full access to this page and form blocks. You can restrict permissions per role in Settings.', 'superb-blocks'); ?></p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . DashboardController::SETTINGS . '#forms')); ?>" class="superbaddons-element-colorlink superbaddons-element-text-xxs"><?php echo esc_html__('Manage Permissions', 'superb-blocks'); ?> &rarr;</a>
        </div>
    <?php
    }

    /**
     * Get the action statuses for a form from its stored config.
     * Returns array with store_enabled, email_enabled, send_confirmation, and integration statuses.
     * Integration statuses use: false (off), 'on' (fully configured), 'no_api_key', 'no_lists'.
     */
    private static function GetFormActionStatuses($form_id)
    {
        $config = FormRegistry::GetConfig($form_id);
        $defaults = array(
            'store_enabled' => false,
            'email_enabled' => false,
            'send_confirmation' => false,
            'mailchimp_status' => false,
            'brevo_status' => false,
            'webhook_status' => false,
            'google_sheets_status' => false,
            'slack_status' => false,
        );

        if (!$config || !is_array($config)) {
            return $defaults;
        }

        return array(
            'store_enabled' => !empty($config['storeEnabled']),
            'email_enabled' => !empty($config['emailEnabled']),
            'send_confirmation' => !empty($config['sendConfirmation']),
            'mailchimp_status' => self::GetIntegrationStatus(
                !empty($config['mailchimpEnabled']),
                FormSettings::Get(FormSettings::OPTION_MAILCHIMP_API_KEY),
                isset($config['mailchimpListIds']) ? $config['mailchimpListIds'] : array()
            ),
            'brevo_status' => self::GetIntegrationStatus(
                !empty($config['brevoEnabled']),
                FormSettings::Get(FormSettings::OPTION_BREVO_API_KEY),
                isset($config['brevoListIds']) ? $config['brevoListIds'] : array()
            ),
            'webhook_status' => self::GetWebhookStatus($config),
            'google_sheets_status' => self::GetGoogleSheetsStatus($config),
            'slack_status' => self::GetSlackStatus($config),
        );
    }

    /**
     * Determine the status of an integration.
     * Returns false (off), 'on' (fully configured), 'no_api_key', or 'no_lists'.
     */
    private static function GetIntegrationStatus($enabled, $api_key, $list_ids)
    {
        if (!$enabled) {
            return false;
        }
        if (empty($api_key)) {
            return 'no_api_key';
        }
        if (!is_array($list_ids) || empty($list_ids)) {
            return 'no_lists';
        }
        return 'on';
    }

    private static function GetWebhookStatus($config)
    {
        if (empty($config['webhookEnabled'])) {
            return false;
        }
        if (empty($config['webhookUrl'])) {
            return 'no_url';
        }
        return 'on';
    }

    private static function GetGoogleSheetsStatus($config)
    {
        if (empty($config['googleSheetsEnabled'])) {
            return false;
        }
        if (!FormSettings::HasValue(FormSettings::OPTION_GOOGLE_SHEETS_CLIENT_EMAIL) || !FormSettings::HasValue(FormSettings::OPTION_GOOGLE_SHEETS_PRIVATE_KEY)) {
            return 'no_api_key';
        }
        if (empty($config['googleSheetsSpreadsheetUrl'])) {
            return 'no_spreadsheet';
        }
        return 'on';
    }

    private static function GetSlackStatus($config)
    {
        if (empty($config['slackEnabled'])) {
            return false;
        }
        if (empty($config['slackWebhookUrl'])) {
            return 'no_url';
        }
        return 'on';
    }

    private static function GetEditLabel($source_post_type)
    {
        $edit_labels = array(
            'page' => __('Edit in Page', 'superb-blocks'),
            'post' => __('Edit in Post', 'superb-blocks'),
            'wp_template' => __('Edit in Template', 'superb-blocks'),
            'wp_template_part' => __('Edit in Template Part', 'superb-blocks'),
            'wp_block' => __('Edit in Pattern', 'superb-blocks'),
        );
        return isset($edit_labels[$source_post_type]) ? $edit_labels[$source_post_type] : __('Edit', 'superb-blocks');
    }

    /**
     * Extract the short reference from a block ID.
     * Returns the portion after the last underscore or hyphen.
     */
    private static function GetShortRef($id)
    {
        if (empty($id)) {
            return $id;
        }
        $last_underscore = strrpos($id, '_');
        $last_hyphen = strrpos($id, '-');
        $pos = false;
        if ($last_underscore !== false && $last_hyphen !== false) {
            $pos = $last_underscore > $last_hyphen ? $last_underscore : $last_hyphen;
        } elseif ($last_underscore !== false) {
            $pos = $last_underscore;
        } elseif ($last_hyphen !== false) {
            $pos = $last_hyphen;
        }
        if ($pos === false) {
            return $id;
        }
        return substr($id, $pos + 1);
    }

    private function LoadFormsList()
    {
        $registry = FormRegistry::GetAll();
        $form_ids_with_submissions = FormSubmissionHandler::GetDistinctFormIds();

        // Merge: all registry forms + any submission-only forms not in registry
        $all_form_ids = array_unique(array_merge(array_keys($registry), $form_ids_with_submissions));

        foreach ($all_form_ids as $fid) {
            $count = FormSubmissionHandler::GetCount($fid);
            $last_date = FormSubmissionHandler::GetLastSubmissionDate($fid);
            $entry = isset($registry[$fid]) ? $registry[$fid] : null;

            $this->forms[] = array(
                'form_id' => $fid,
                'form_name' => FormRegistry::GetName($fid),
                'total' => $count['total'],
                'new' => $count['new'],
                'last_date' => $last_date,
                'source_post_id' => ($entry && isset($entry['source_post_id'])) ? $entry['source_post_id'] : null,
                'source_post_type' => ($entry && isset($entry['source_post_type'])) ? $entry['source_post_type'] : null,
                'pending_delete' => ($entry && !empty($entry['pending_delete'])),
                'actions' => self::GetFormActionStatuses($fid),
                'spam_count' => FormSubmissionHandler::GetSpamCount($fid),
            );
        }

        // Compute aggregate stats for the welcome strip
        $this->total_forms = count($this->forms);
        foreach ($this->forms as $form) {
            $this->total_submissions += $form['total'];
            $this->total_unread += $form['new'];
        }
    }

    private function Render()
    {
        if ($this->is_single_form) {
            $this->RenderSingleForm();
        } else {
            $this->RenderFormsList();
        }
        new Modal();
    }

    private function RenderIncompatible()
    {
    ?>
        <div class="superbaddons-admindashboard-content-box-large spbaddons-form-submissions-page">
            <div class="spbaddons-form-submissions-empty">
                <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/icon-superb.svg'); ?>" alt="" aria-hidden="true" />
                <h3 class="superbaddons-element-text-md superbaddons-element-text-800 superbaddons-element-text-dark"><?php echo esc_html__('WordPress Update Required', 'superb-blocks'); ?></h3>
                <p class="superbaddons-element-text-xs superbaddons-element-text-gray"><?php
                                                                                        echo esc_html(sprintf(
                                                                                            /* translators: %s: minimum WordPress version number */
                                                                                            __('Forms require WordPress %s or later. Please update WordPress to use the form blocks and view submissions.', 'superb-blocks'),
                                                                                            GutenbergController::MINIMUM_WORDPRESS_VERSION
                                                                                        ));
                                                                                        ?></p>
                <a href="<?php echo esc_url(admin_url('update-core.php')); ?>" class="superbaddons-element-button spbaddons-admin-btn-primary"><?php echo esc_html__('Update WordPress', 'superb-blocks'); ?></a>
            </div>
        </div>
    <?php
    }

    private function RenderNotFound()
    {
    ?>
        <div class="superbaddons-admindashboard-content-box-large spbaddons-form-submissions-page">
            <div class="spbaddons-form-submissions-header">
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . DashboardController::FORMS)); ?>" class="spbaddons-form-submissions-back superbaddons-element-text-xs">
                    <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-arrow-left.svg'); ?>" alt="" aria-hidden="true" />
                    <?php echo esc_html__('All Forms', 'superb-blocks'); ?>
                </a>
            </div>
            <div class="spbaddons-form-submissions-empty">
                <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/icon-superb.svg'); ?>" alt="" aria-hidden="true" />
                <h3 class="superbaddons-element-text-md superbaddons-element-text-800 superbaddons-element-text-dark"><?php echo esc_html__('Form not found', 'superb-blocks'); ?></h3>
                <p class="superbaddons-element-text-xs superbaddons-element-text-gray"><?php echo esc_html__('The form you are looking for does not exist or has been deleted.', 'superb-blocks'); ?></p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . DashboardController::FORMS)); ?>" class="superbaddons-element-button spbaddons-admin-btn-primary"><?php echo esc_html__('View All Forms', 'superb-blocks'); ?></a>
            </div>
        </div>
    <?php
    }

    private function RenderFormsList()
    {
    ?>
        <!-- Welcome / Status Strip -->
        <div class="superbaddons-dashboard-welcome-strip">
            <span class="superbaddons-dashboard-welcome-title"><?php echo esc_html__('Forms', 'superb-blocks'); ?> <span class="spbaddons-live-dot" title="<?php echo esc_attr__('Live monitoring active', 'superb-blocks'); ?>"></span></span>
            <div class="superbaddons-dashboard-stat-items">
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot superbaddons-status-dot--purple"></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of forms */
                        _n('%d Form', '%d Forms', $this->total_forms, 'superb-blocks'),
                        $this->total_forms
                    )); ?>
                </span>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo $this->total_submissions > 0 ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--gray'; ?>"></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of submissions */
                        _n('%d Submission', '%d Submissions', $this->total_submissions, 'superb-blocks'),
                        $this->total_submissions
                    )); ?>
                </span>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo $this->total_unread > 0 ? 'superbaddons-status-dot--purple' : 'superbaddons-status-dot--gray'; ?>"></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of unread submissions */
                        __('%d Unread', 'superb-blocks'),
                        $this->total_unread
                    )); ?>
                </span>
            </div>
        </div>

        <!-- Sidebar Layout -->
        <div class="superbaddons-admindashboard-sidebarlayout">
            <div class="superbaddons-admindashboard-sidebarlayout-left">
                <div class="superbaddons-settings-section">
                    <?php if (empty($this->forms)) : ?>
                        <div class="spbaddons-form-submissions-empty">
                            <h3 class="superbaddons-element-text-md superbaddons-element-text-800 superbaddons-element-text-dark"><?php echo esc_html__('No forms yet', 'superb-blocks'); ?></h3>
                            <p class="superbaddons-element-text-xs superbaddons-element-text-gray"><?php echo esc_html__('Create a form by adding a Superb Form block to any page, post, or template.', 'superb-blocks'); ?></p>
                            <div class="spbaddons-form-empty-actions">
                                <div class="spbaddons-form-empty-action-card">
                                    <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-note.svg'); ?>" alt="" aria-hidden="true" />
                                    <span><?php echo esc_html__('Create in a Page', 'superb-blocks'); ?></span>
                                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=page')); ?>" class="superbaddons-element-button superbaddons-element-button-sm"><?php echo esc_html__('View Pages', 'superb-blocks'); ?></a>
                                </div>
                                <div class="spbaddons-form-empty-action-card">
                                    <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-note.svg'); ?>" alt="" aria-hidden="true" />
                                    <span><?php echo esc_html__('Create in a Post', 'superb-blocks'); ?></span>
                                    <a href="<?php echo esc_url(admin_url('edit.php')); ?>" class="superbaddons-element-button superbaddons-element-button-sm"><?php echo esc_html__('View Posts', 'superb-blocks'); ?></a>
                                </div>
                                <div class="spbaddons-form-empty-action-card">
                                    <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-image.svg'); ?>" alt="" aria-hidden="true" />
                                    <span><?php echo esc_html__('Create in a Template', 'superb-blocks'); ?></span>
                                    <a href="<?php echo esc_url(admin_url('site-editor.php?path=/wp_template')); ?>" class="superbaddons-element-button superbaddons-element-button-sm"><?php echo esc_html__('View Templates', 'superb-blocks'); ?></a>
                                </div>
                            </div>
                            <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__('Forms will appear here automatically once saved. Make sure you have the "Save submissions" option enabled in your form block settings if you want to manage submissions here.', 'superb-blocks'); ?></p>
                        </div>
                    <?php else : ?>
                        <p class="superbaddons-element-text-xs superbaddons-element-text-gray superbaddons-help-text-inline"><?php echo esc_html__('View and manage submissions from your forms. Forms appear here after saving a page that contains a form block, or after receiving a submission.', 'superb-blocks'); ?></p>
                        <div class="spbaddons-forms-grid">
                            <?php foreach ($this->forms as $form) :
                                $has_new = $form['new'] > 0;
                                $last_date_display = '';
                                if (!empty($form['last_date'])) {
                                    $last_date_display = esc_html(sprintf(
                                        /* translators: %s: human-readable time difference */
                                        __('Last submission: %s ago', 'superb-blocks'),
                                        human_time_diff(strtotime($form['last_date']), current_time('timestamp'))
                                    ));
                                } else {
                                    $last_date_display = esc_html__('No submissions yet', 'superb-blocks');
                                }
                            ?>
                                <div class="spbaddons-form-card<?php echo $has_new ? ' spbaddons-form-card--has-new' : ''; ?>">
                                    <div class="spbaddons-form-card-header">
                                        <img class="spbaddons-form-card-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/icon-form.svg'); ?>" alt="" aria-hidden="true" />
                                        <h3 class="spbaddons-form-card-name superbaddons-element-text-sm superbaddons-element-text-800 superbaddons-element-text-dark superbaddons-element-m0"><?php echo esc_html($form['form_name']); ?></h3>
                                        <span class="spbaddons-form-card-ref">#<?php echo esc_html(self::GetShortRef($form['form_id'])); ?></span>
                                    </div>
                                    <?php if ($form['pending_delete']) : ?>
                                        <span class="spbaddons-form-card-pending-badge"><?php echo esc_html__('Form Block Removed', 'superb-blocks'); ?></span>
                                    <?php endif; ?>
                                    <?php if ($form['actions']['store_enabled'] || $form['total'] > 0) : ?>
                                        <div class="spbaddons-form-card-stats">
                                            <div class="spbaddons-form-card-stat">
                                                <span class="spbaddons-form-card-stat-number"><?php echo esc_html($form['total']); ?></span>
                                                <span class="spbaddons-form-card-stat-label"><?php echo esc_html__('Total', 'superb-blocks'); ?></span>
                                            </div>
                                            <div class="spbaddons-form-card-stat">
                                                <span class="spbaddons-form-card-stat-number<?php echo $has_new ? ' spbaddons-form-card-stat-number--unread' : ''; ?>"><?php echo esc_html($form['new']); ?></span>
                                                <span class="spbaddons-form-card-stat-label"><?php echo esc_html__('Unread', 'superb-blocks'); ?></span>
                                            </div>
                                        </div>
                                        <?php if ($form['spam_count'] > 0) : ?>
                                            <span class="spbaddons-form-card-spam-badge"><?php echo esc_html(sprintf(
                                                                                                /* translators: %d: number of blocked submissions */
                                                                                                _n('%d blocked', '%d blocked', $form['spam_count'], 'superb-blocks'),
                                                                                                $form['spam_count']
                                                                                            )); ?></span>
                                        <?php endif; ?>
                                        <p class="spbaddons-form-card-activity superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html($last_date_display); ?></p>
                                    <?php endif; ?>
                                    <?php if (!$form['pending_delete']) : ?>
                                        <div class="spbaddons-form-action-statuses">
                                            <span class="spbaddons-form-action-status <?php echo $form['actions']['store_enabled'] ? 'spbaddons-form-action-status--on' : 'spbaddons-form-action-status--off'; ?>">
                                                <?php echo esc_html__('Save Submissions', 'superb-blocks'); ?>
                                            </span>
                                            <span class="spbaddons-form-action-status <?php echo $form['actions']['email_enabled'] ? 'spbaddons-form-action-status--on' : 'spbaddons-form-action-status--off'; ?>">
                                                <?php echo esc_html__('Admin Notification', 'superb-blocks'); ?>
                                            </span>
                                            <span class="spbaddons-form-action-status <?php echo $form['actions']['send_confirmation'] ? 'spbaddons-form-action-status--on' : 'spbaddons-form-action-status--off'; ?>">
                                                <?php echo esc_html__('User Notification', 'superb-blocks'); ?>
                                            </span>
                                            <?php if ($form['actions']['mailchimp_status']) : ?>
                                                <span class="spbaddons-form-action-status <?php echo $form['actions']['mailchimp_status'] === 'on' ? 'spbaddons-form-action-status--on' : 'spbaddons-form-action-status--warn'; ?>">
                                                    <?php echo esc_html__('Mailchimp', 'superb-blocks'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($form['actions']['brevo_status']) : ?>
                                                <span class="spbaddons-form-action-status <?php echo $form['actions']['brevo_status'] === 'on' ? 'spbaddons-form-action-status--on' : 'spbaddons-form-action-status--warn'; ?>">
                                                    <?php echo esc_html__('Brevo', 'superb-blocks'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($form['actions']['webhook_status']) : ?>
                                                <span class="spbaddons-form-action-status <?php echo $form['actions']['webhook_status'] === 'on' ? 'spbaddons-form-action-status--on' : 'spbaddons-form-action-status--warn'; ?>">
                                                    <?php echo esc_html__('Webhook', 'superb-blocks'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($form['actions']['google_sheets_status']) : ?>
                                                <span class="spbaddons-form-action-status <?php echo $form['actions']['google_sheets_status'] === 'on' ? 'spbaddons-form-action-status--on' : 'spbaddons-form-action-status--warn'; ?>">
                                                    <?php echo esc_html__('Google Sheets', 'superb-blocks'); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($form['actions']['slack_status']) : ?>
                                                <span class="spbaddons-form-action-status <?php echo $form['actions']['slack_status'] === 'on' ? 'spbaddons-form-action-status--on' : 'spbaddons-form-action-status--warn'; ?>">
                                                    <?php echo esc_html__('Slack', 'superb-blocks'); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($form['pending_delete']) : ?>
                                        <p class="spbaddons-form-action-bar-notice superbaddons-element-m0 spbaddons-form-action-bar-notice--warn">
                                            <?php echo esc_html__('This form block no longer exists on your site. You can still view and manage existing submissions. If all submissions are deleted, this form will be removed automatically.', 'superb-blocks'); ?>
                                        </p>
                                    <?php elseif (empty($form['source_post_id'])) : ?>
                                        <p class="spbaddons-form-action-bar-notice superbaddons-element-m0">
                                            <?php echo esc_html__('This form is not currently linked to any page, post, or template.', 'superb-blocks'); ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="spbaddons-form-card-actions">
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . DashboardController::FORMS . '&form_id=' . urlencode($form['form_id']))); ?>" class="spbaddons-form-card-link">
                                            <?php echo esc_html__('View Details', 'superb-blocks'); ?>
                                            <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-arrow-right.svg'); ?>" alt="" aria-hidden="true" />
                                        </a>
                                        <?php
                                        $edit_url = !empty($form['source_post_id']) ? get_edit_post_link(intval($form['source_post_id']), 'raw') : '';
                                        if ($edit_url) : ?>
                                            <a href="<?php echo esc_url($edit_url); ?>" class="spbaddons-form-card-link spbaddons-form-card-link--secondary">
                                                <?php echo esc_html(self::GetEditLabel($form['source_post_type'])); ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="superbaddons-dashboard-suggest-card">
                                <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-bulb.svg'); ?>" alt="" aria-hidden="true" />
                                <div class="superbaddons-dashboard-suggest-card-content">
                                    <strong class="superbaddons-element-text-xxs superbaddons-element-text-dark"><?php echo esc_html__('Missing a feature?', 'superb-blocks'); ?></strong>
                                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray superbaddons-element-m0"><?php echo esc_html__('Let us know how we can improve our forms.', 'superb-blocks'); ?></p>
                                </div>
                                <a href="<?php echo esc_url(AdminLinkUtil::GetLink(AdminLinkSource::DEFAULT, array("url" => "https://superbthemes.com/contact/", "anchor" => "create-ticket"))); ?>" target="_blank" class="superbaddons-element-colorlink superbaddons-element-text-xxs"><?php echo esc_html__('Request Feature', 'superb-blocks'); ?> &rarr;</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="superbaddons-admindashboard-sidebarlayout-right">
                <?php
                self::RenderAccessControlNotice();
                if (!KeyController::HasValidPremiumKey()) {
                    new PremiumBox(AdminLinkSource::FORMS);
                } else {
                    new SupportBox();
                }
                ?>
            </div>
        </div>
    <?php
    }

    private function RenderSingleForm()
    {
        $stats = $this->form_stats;
        $actions = self::GetFormActionStatuses($this->form_id);
        $pending_delete = $this->form_entry && !empty($this->form_entry['pending_delete']);
        $has_source = $this->form_entry && !empty($this->form_entry['source_post_id']);
        $edit_url = $has_source ? get_edit_post_link(intval($this->form_entry['source_post_id']), 'raw') : '';
        new EncryptionNotice();
    ?>
        <!-- Welcome / Status Strip -->
        <div class="superbaddons-dashboard-welcome-strip">
            <span class="superbaddons-dashboard-welcome-title"><?php echo esc_html($this->form_name); ?> <span class="spbaddons-form-title-ref">#<?php echo esc_html(self::GetShortRef($this->form_id)); ?></span> <?php if (!$pending_delete) : ?><span class="spbaddons-live-dot" title="<?php echo esc_attr__('Live monitoring active', 'superb-blocks'); ?>"></span><?php endif; ?><?php if ($edit_url) : ?> <a href="<?php echo esc_url($edit_url); ?>" class="spbaddons-form-title-edit"><?php echo esc_html(self::GetEditLabel($this->form_entry['source_post_type'])); ?></a><?php endif; ?></span>
            <div class="superbaddons-dashboard-stat-items">
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo $actions['store_enabled'] ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--gray'; ?>"></span>
                    <?php echo esc_html__('Save Submissions:', 'superb-blocks'); ?> <?php echo $actions['store_enabled'] ? esc_html__('On', 'superb-blocks') : esc_html__('Off', 'superb-blocks'); ?>
                </span>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo $actions['email_enabled'] ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--gray'; ?>"></span>
                    <?php echo esc_html__('Admin Email:', 'superb-blocks'); ?> <?php echo $actions['email_enabled'] ? esc_html__('On', 'superb-blocks') : esc_html__('Off', 'superb-blocks'); ?>
                </span>
                <span class="superbaddons-dashboard-stat-item">
                    <span class="superbaddons-status-dot <?php echo $actions['send_confirmation'] ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--gray'; ?>"></span>
                    <?php echo esc_html__('User Email:', 'superb-blocks'); ?> <?php echo $actions['send_confirmation'] ? esc_html__('On', 'superb-blocks') : esc_html__('Off', 'superb-blocks'); ?>
                </span>
                <?php if ($actions['mailchimp_status']) : ?>
                    <span class="superbaddons-dashboard-stat-item">
                        <span class="superbaddons-status-dot <?php echo $actions['mailchimp_status'] === 'on' ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--yellow'; ?>"></span>
                        <?php echo esc_html__('Mailchimp:', 'superb-blocks'); ?> <?php echo $actions['mailchimp_status'] === 'on' ? esc_html__('On', 'superb-blocks') : esc_html__('Misconfigured', 'superb-blocks'); ?>
                    </span>
                <?php endif; ?>
                <?php if ($actions['brevo_status']) : ?>
                    <span class="superbaddons-dashboard-stat-item">
                        <span class="superbaddons-status-dot <?php echo $actions['brevo_status'] === 'on' ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--yellow'; ?>"></span>
                        <?php echo esc_html__('Brevo:', 'superb-blocks'); ?> <?php echo $actions['brevo_status'] === 'on' ? esc_html__('On', 'superb-blocks') : esc_html__('Misconfigured', 'superb-blocks'); ?>
                    </span>
                <?php endif; ?>
                <?php if ($actions['webhook_status']) : ?>
                    <span class="superbaddons-dashboard-stat-item">
                        <span class="superbaddons-status-dot <?php echo $actions['webhook_status'] === 'on' ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--yellow'; ?>"></span>
                        <?php echo esc_html__('Webhook:', 'superb-blocks'); ?> <?php echo $actions['webhook_status'] === 'on' ? esc_html__('On', 'superb-blocks') : esc_html__('Misconfigured', 'superb-blocks'); ?>
                    </span>
                <?php endif; ?>
                <?php if ($actions['google_sheets_status']) : ?>
                    <span class="superbaddons-dashboard-stat-item">
                        <span class="superbaddons-status-dot <?php echo $actions['google_sheets_status'] === 'on' ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--yellow'; ?>"></span>
                        <?php echo esc_html__('Google Sheets:', 'superb-blocks'); ?> <?php echo $actions['google_sheets_status'] === 'on' ? esc_html__('On', 'superb-blocks') : esc_html__('Misconfigured', 'superb-blocks'); ?>
                    </span>
                <?php endif; ?>
                <?php if ($actions['slack_status']) : ?>
                    <span class="superbaddons-dashboard-stat-item">
                        <span class="superbaddons-status-dot <?php echo $actions['slack_status'] === 'on' ? 'superbaddons-status-dot--green' : 'superbaddons-status-dot--yellow'; ?>"></span>
                        <?php echo esc_html__('Slack:', 'superb-blocks'); ?> <?php echo $actions['slack_status'] === 'on' ? esc_html__('On', 'superb-blocks') : esc_html__('Misconfigured', 'superb-blocks'); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Notices Area -->
        <div class="spbaddons-form-notices">
            <?php if (!$pending_delete) : ?>
                <?php if (!$actions['store_enabled']) : ?>
                    <p class="spbaddons-form-action-bar-notice">
                        <?php echo esc_html__('Saving submissions is currently disabled for this form. If you\'d like to store and manage submissions here, you can enable "Save submissions" in the form block settings.', 'superb-blocks'); ?>
                    </p>
                <?php endif; ?>
                <?php if ($actions['mailchimp_status'] === 'no_api_key') : ?>
                    <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                        <?php echo esc_html__('Mailchimp is enabled but no API key has been configured. Add your API key in the form block integration settings.', 'superb-blocks'); ?>
                    </p>
                <?php elseif ($actions['mailchimp_status'] === 'no_lists') : ?>
                    <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                        <?php echo esc_html__('Mailchimp is enabled but no audience has been selected. Select an audience in the form block integration settings.', 'superb-blocks'); ?>
                    </p>
                <?php endif; ?>
                <?php if ($actions['brevo_status'] === 'no_api_key') : ?>
                    <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                        <?php echo esc_html__('Brevo is enabled but no API key has been configured. Add your API key in the form block integration settings.', 'superb-blocks'); ?>
                    </p>
                <?php elseif ($actions['brevo_status'] === 'no_lists') : ?>
                    <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                        <?php echo esc_html__('Brevo is enabled but no contact list has been selected. Select a list in the form block integration settings.', 'superb-blocks'); ?>
                    </p>
                <?php endif; ?>
                <?php if ($actions['webhook_status'] === 'no_url') : ?>
                    <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                        <?php echo esc_html__('Webhook is enabled but no URL has been configured. Add a webhook URL in the form block settings.', 'superb-blocks'); ?>
                    </p>
                <?php endif; ?>
                <?php if ($actions['google_sheets_status'] === 'no_api_key') : ?>
                    <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                        <?php echo esc_html__('Google Sheets is enabled but no Service Account key has been configured. Add your key in Settings.', 'superb-blocks'); ?>
                    </p>
                <?php elseif ($actions['google_sheets_status'] === 'no_spreadsheet') : ?>
                    <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                        <?php echo esc_html__('Google Sheets is enabled but no spreadsheet URL has been configured. Add a spreadsheet URL in the form block settings.', 'superb-blocks'); ?>
                    </p>
                <?php endif; ?>
                <?php if ($actions['slack_status'] === 'no_url') : ?>
                    <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                        <?php echo esc_html__('Slack is enabled but no webhook URL has been configured. Add a Slack webhook URL in the form block settings.', 'superb-blocks'); ?>
                    </p>
                <?php endif; ?>
                <?php if ($actions['email_enabled'] && FormSubmissionHandler::HasRecentEmailFailures($this->form_id)) : ?>
                    <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                        <?php echo esc_html__('Recent form emails failed to send. Please contact your hosting provider or install an SMTP plugin to set up email sending for your site. This notice will disappear automatically once emails are sent successfully.', 'superb-blocks'); ?>
                    </p>
                <?php endif; ?>
            <?php elseif ($pending_delete) : ?>
                <p class="spbaddons-form-action-bar-notice spbaddons-form-action-bar-notice--warn">
                    <?php echo esc_html__('This form block no longer exists on your site. You can still view and manage existing submissions. If all submissions are deleted, this form will be removed automatically.', 'superb-blocks'); ?>
                </p>
            <?php elseif (!$has_source) : ?>
                <p class="spbaddons-form-action-bar-notice">
                    <?php echo esc_html__('This form is not currently linked to any page, post, or template.', 'superb-blocks'); ?>
                </p>
            <?php endif; ?>
        </div>

        <!-- Sidebar Layout -->
        <div class="superbaddons-admindashboard-sidebarlayout">
            <div class="superbaddons-admindashboard-sidebarlayout-left">
                <?php if ($actions['store_enabled'] || $stats['total'] > 0) : ?>
                    <div class="superbaddons-settings-section">
                        <!-- Stats Bar -->
                        <div class="spbaddons-form-stats-bar">
                            <button type="button" class="spbaddons-form-stat spbaddons-form-stat--clickable" data-stat-action="reset">
                                <span class="spbaddons-form-stat-number" id="spbaddons-stat-total"><?php echo esc_html($stats['total']); ?></span>
                                <span class="spbaddons-form-stat-label"><?php echo esc_html__('Total', 'superb-blocks'); ?></span>
                            </button>
                            <button type="button" class="spbaddons-form-stat spbaddons-form-stat--clickable" data-stat-action="unread">
                                <span class="spbaddons-form-stat-number<?php echo $stats['new'] > 0 ? ' spbaddons-form-stat-number--unread' : ''; ?>" id="spbaddons-stat-unread"><?php echo esc_html($stats['new']); ?></span>
                                <span class="spbaddons-form-stat-label"><?php echo esc_html__('Unread', 'superb-blocks'); ?></span>
                            </button>
                            <button type="button" class="spbaddons-form-stat spbaddons-form-stat--clickable" data-date-filter="today">
                                <span class="spbaddons-form-stat-number" id="spbaddons-stat-today"><?php echo esc_html($stats['today']); ?></span>
                                <span class="spbaddons-form-stat-label"><?php echo esc_html__('Today', 'superb-blocks'); ?></span>
                            </button>
                            <button type="button" class="spbaddons-form-stat spbaddons-form-stat--clickable" data-date-filter="this_week">
                                <span class="spbaddons-form-stat-number" id="spbaddons-stat-week"><?php echo esc_html($stats['this_week']); ?></span>
                                <span class="spbaddons-form-stat-label"><?php echo esc_html__('This Week', 'superb-blocks'); ?></span>
                            </button>
                            <?php
                            $spam_count = FormSubmissionHandler::GetSpamCount($this->form_id);
                            if ($spam_count > 0) : ?>
                                <div class="spbaddons-form-stat spbaddons-form-stat--spam">
                                    <span class="spbaddons-form-stat-number spbaddons-form-stat-number--spam" id="spbaddons-stat-spam"><?php echo esc_html($spam_count); ?></span>
                                    <span class="spbaddons-form-stat-label"><?php echo esc_html__('Blocked', 'superb-blocks'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Filter Bar -->
                        <div class="spbaddons-form-submissions-filter-bar">
                            <div class="spbaddons-form-submissions-filter-bar-left">
                                <div class="spbaddons-form-submissions-filter-tabs" role="tablist">
                                    <button type="button" class="spbaddons-form-submissions-filter-tab spbaddons-form-submissions-filter-tab--active" data-filter="" role="tab" aria-selected="true">
                                        <?php echo esc_html__('All', 'superb-blocks'); ?> <span class="spbaddons-form-submissions-filter-count" id="spbaddons-filter-count-all"><?php echo esc_html($stats['total']); ?></span>
                                    </button>
                                    <button type="button" class="spbaddons-form-submissions-filter-tab" data-filter="new" role="tab" aria-selected="false">
                                        <?php echo esc_html__('Unread', 'superb-blocks'); ?> <span class="spbaddons-form-submissions-filter-count" id="spbaddons-filter-count-new"><?php echo esc_html($stats['new']); ?></span>
                                    </button>
                                    <button type="button" class="spbaddons-form-submissions-filter-tab" data-filter="read" role="tab" aria-selected="false">
                                        <?php echo esc_html__('Read', 'superb-blocks'); ?> <span class="spbaddons-form-submissions-filter-count" id="spbaddons-filter-count-read"><?php echo esc_html($stats['total'] - $stats['new']); ?></span>
                                    </button>
                                </div>
                                <button type="button" id="spbaddons-form-submissions-star-filter" class="spbaddons-form-submissions-star-filter" aria-label="<?php echo esc_attr__('Filter starred submissions', 'superb-blocks'); ?>" title="<?php echo esc_attr__('Starred', 'superb-blocks'); ?>">
                                    <img class="spbaddons-star-icon-regular" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-star-regular.svg'); ?>" alt="" aria-hidden="true" width="16" height="16" />
                                    <img class="spbaddons-star-icon-fill" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-star-fill.svg'); ?>" alt="" aria-hidden="true" width="16" height="16" style="display:none;" />
                                </button>
                                <div class="spbaddons-date-filter-wrap">
                                    <button type="button" id="spbaddons-form-submissions-date-filter" class="spbaddons-date-filter-trigger" aria-label="<?php echo esc_attr__('Filter by date range', 'superb-blocks'); ?>" aria-expanded="false">
                                        <span id="spbaddons-date-filter-label"><?php echo esc_html__('All Time', 'superb-blocks'); ?></span>
                                        <svg class="spbaddons-date-filter-chevron" width="10" height="6" viewBox="0 0 10 6" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                            <path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div class="spbaddons-form-submissions-filter-bar-right">
                                <input type="search" id="spbaddons-form-submissions-search" class="spbaddons-form-submissions-search" placeholder="<?php echo esc_attr__('Search submissions...', 'superb-blocks'); ?>" />
                                <?php if (!$pending_delete) : ?>
                                    <?php /* We can't filter fields when the form is pending deletion as it has no config */ ?>
                                    <div class="spbaddons-fields-wrap">
                                        <button type="button" id="spbaddons-form-submissions-fields" class="superbaddons-element-button superbaddons-element-m0">
                                            <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-list-bullets.svg'); ?>" alt="" aria-hidden="true" />
                                            <span><?php echo esc_html__('Fields', 'superb-blocks'); ?></span>
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <button type="button" id="spbaddons-form-submissions-export" class="superbaddons-element-button superbaddons-element-m0">
                                    <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/download-simple-duotone.svg'); ?>" alt="" aria-hidden="true" />
                                    <span id="spbaddons-form-submissions-export-label"><?php echo esc_html__('Export', 'superb-blocks'); ?></span>
                                </button>
                                <!-- Export popover -->
                                <div id="spbaddons-form-submissions-export-popover" class="spbaddons-form-submissions-export-popover" style="display:none;">
                                    <div id="spbaddons-form-submissions-export-scope" class="spbaddons-form-submissions-export-scope" style="display:none;">
                                        <label class="spbaddons-form-submissions-export-scope-option">
                                            <input type="radio" name="spbaddons_export_scope" value="filtered" checked />
                                            <span id="spbaddons-form-submissions-export-scope-filtered-label"><?php echo esc_html__('Filtered results', 'superb-blocks'); ?></span>
                                        </label>
                                        <label class="spbaddons-form-submissions-export-scope-option">
                                            <input type="radio" name="spbaddons_export_scope" value="all" />
                                            <span><?php echo esc_html__('All submissions', 'superb-blocks'); ?></span>
                                        </label>
                                    </div>
                                    <label class="spbaddons-form-submissions-export-popover-label spbaddons-checkbox-wrap" id="spbaddons-form-submissions-export-sensitive-wrap" style="display:none;">
                                        <input type="checkbox" id="spbaddons-form-submissions-export-sensitive" />
                                        <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                        <span><?php echo esc_html__('Include sensitive fields', 'superb-blocks'); ?></span>
                                    </label>
                                    <label class="spbaddons-form-submissions-export-popover-label spbaddons-checkbox-wrap" id="spbaddons-form-submissions-export-notes-wrap" style="display:none;">
                                        <input type="checkbox" id="spbaddons-form-submissions-export-notes" />
                                        <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                        <span><?php echo esc_html__('Include notes', 'superb-blocks'); ?></span>
                                    </label>
                                    <label class="spbaddons-form-submissions-export-popover-label spbaddons-checkbox-wrap" id="spbaddons-form-submissions-export-all-fields-wrap" style="display:none;">
                                        <input type="checkbox" id="spbaddons-form-submissions-export-all-fields" />
                                        <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                        <span><?php echo esc_html__('Export all fields', 'superb-blocks'); ?></span>
                                    </label>
                                    <button type="button" id="spbaddons-form-submissions-export-download" class="superbaddons-element-button spbaddons-admin-btn-primary superbaddons-element-button-small superbaddons-element-m0">
                                        <?php echo esc_html__('Download CSV', 'superb-blocks'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Contextual Bulk Actions Toolbar (hidden until selection) -->
                        <div id="spbaddons-form-submissions-toolbar" class="spbaddons-form-submissions-toolbar">
                            <div class="spbaddons-form-submissions-toolbar-top">
                                <span id="spbaddons-form-submissions-selected-count" class="spbaddons-form-submissions-selected-count"></span>
                                <span class="spbaddons-form-submissions-toolbar-sep">&middot;</span>
                                <button type="button" id="spbaddons-form-submissions-deselect" class="spbaddons-form-submissions-deselect"><?php echo esc_html__('Clear', 'superb-blocks'); ?></button>
                            </div>
                            <div class="spbaddons-form-submissions-toolbar-actions">
                                <div class="spbaddons-form-submissions-toolbar-group">
                                    <button type="button" id="spbaddons-form-submissions-bulk-read" class="superbaddons-element-button superbaddons-element-button-sm">
                                        <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/eye.svg'); ?>" alt="" aria-hidden="true" />
                                        <?php echo esc_html__('Mark as Read', 'superb-blocks'); ?>
                                    </button>
                                    <button type="button" id="spbaddons-form-submissions-bulk-unread" class="superbaddons-element-button superbaddons-element-button-sm">
                                        <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/eye-slash.svg'); ?>" alt="" aria-hidden="true" />
                                        <?php echo esc_html__('Mark as Unread', 'superb-blocks'); ?>
                                    </button>
                                </div>
                                <div class="spbaddons-form-submissions-toolbar-group">
                                    <button type="button" id="spbaddons-form-submissions-bulk-star" class="superbaddons-element-button superbaddons-element-button-sm">
                                        <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-star-fill.svg'); ?>" alt="" aria-hidden="true" />
                                        <?php echo esc_html__('Star', 'superb-blocks'); ?>
                                    </button>
                                    <button type="button" id="spbaddons-form-submissions-bulk-unstar" class="superbaddons-element-button superbaddons-element-button-sm">
                                        <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-star-regular.svg'); ?>" alt="" aria-hidden="true" />
                                        <?php echo esc_html__('Unstar', 'superb-blocks'); ?>
                                    </button>
                                </div>
                                <div class="spbaddons-form-submissions-toolbar-group">
                                    <button type="button" id="spbaddons-form-submissions-bulk-delete" class="superbaddons-element-button superbaddons-element-button-sm spbaddons-admin-btn-danger">
                                        <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" alt="" aria-hidden="true" />
                                        <?php echo esc_html__('Delete', 'superb-blocks'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div id="spbaddons-form-submissions-table-wrapper" class="spbaddons-form-submissions-table-wrapper">
                            <table class="spbaddons-form-submissions-table" role="grid">
                                <thead>
                                    <tr>
                                        <th class="spbaddons-form-submissions-col-check" scope="col">
                                            <label class="spbaddons-checkbox-wrap">
                                                <input type="checkbox" id="spbaddons-form-submissions-select-all" aria-label="<?php echo esc_attr__('Select all submissions', 'superb-blocks'); ?>" />
                                                <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                            </label>
                                        </th>
                                        <th class="spbaddons-form-submissions-col-star" scope="col"></th>
                                        <th class="spbaddons-form-submissions-col-primary superbaddons-element-text-xxs superbaddons-element-text-800" scope="col"><?php echo esc_html__('Submission', 'superb-blocks'); ?></th>
                                        <th class="spbaddons-form-submissions-col-fields superbaddons-element-text-xxs superbaddons-element-text-800" scope="col"><?php echo esc_html__('Fields', 'superb-blocks'); ?></th>
                                        <th class="spbaddons-form-submissions-col-actions superbaddons-element-text-xxs superbaddons-element-text-800" scope="col"></th>
                                    </tr>
                                </thead>
                                <tbody id="spbaddons-form-submissions-tbody" aria-live="polite">
                                    <!-- Populated by JS -->
                                </tbody>
                            </table>
                            <div id="spbaddons-form-submissions-loading" class="spbaddons-form-submissions-loading">
                                <!-- Skeleton rows populated by JS -->
                            </div>
                            <div id="spbaddons-form-submissions-empty" class="spbaddons-form-submissions-empty" style="display:none;">
                                <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/icon-superb.svg'); ?>" alt="" aria-hidden="true" />
                                <p id="spbaddons-form-submissions-empty-text" class="superbaddons-element-text-sm superbaddons-element-text-gray"><?php echo esc_html__('No submissions found.', 'superb-blocks'); ?></p>
                                <button type="button" id="spbaddons-form-submissions-clear-filters" class="superbaddons-element-button superbaddons-element-button-sm" style="display:none;"><?php echo esc_html__('Clear filters', 'superb-blocks'); ?></button>
                            </div>
                        </div>

                        <div id="spbaddons-form-submissions-pagination" class="spbaddons-form-submissions-pagination" style="display:none;">
                            <button type="button" id="spbaddons-form-submissions-prev" class="superbaddons-element-button superbaddons-element-button-sm" disabled><img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/caret-left.svg'); ?>" alt="" aria-hidden="true" /> <?php echo esc_html__('Previous', 'superb-blocks'); ?></button>
                            <span id="spbaddons-form-submissions-page-info" class="superbaddons-element-text-xs superbaddons-element-text-gray"></span>
                            <button type="button" id="spbaddons-form-submissions-next" class="superbaddons-element-button superbaddons-element-button-sm"><?php echo esc_html__('Next', 'superb-blocks'); ?> <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/caret-right.svg'); ?>" alt="" aria-hidden="true" /></button>
                            <select id="spbaddons-form-submissions-per-page" class="spbaddons-form-submissions-per-page" aria-label="<?php echo esc_attr__('Submissions per page', 'superb-blocks'); ?>">
                                <option value="20">20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Danger Zone -->
                <div class="superbaddons-danger-zone">
                    <h5 class="superbaddons-danger-zone-title">
                        <img class="superbaddons-admindashboard-content-icon superbaddons-element-mr1" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/color-warning-octagon.svg'); ?>" alt="" aria-hidden="true" />
                        <?php echo esc_html__('Danger Zone', 'superb-blocks'); ?>
                    </h5>
                    <div class="superbaddons-danger-zone-item">
                        <div class="superbaddons-danger-zone-item-info">
                            <strong><?php echo esc_html__('Delete Form', 'superb-blocks'); ?></strong>
                            <p><?php echo esc_html__('Permanently delete this form and all its submissions. This action cannot be undone.', 'superb-blocks'); ?></p>
                        </div>
                        <button type="button" id="spbaddons-form-delete" class="superbaddons-element-button spbaddons-admin-btn-danger superbaddons-element-button-sm" data-form-id="<?php echo esc_attr($this->form_id); ?>" <?php echo $has_source ? ' data-has-source="1" data-source-type="' . esc_attr($this->form_entry['source_post_type']) . '"' : ''; ?>>
                            <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" alt="" aria-hidden="true" />
                            <?php echo esc_html__('Delete', 'superb-blocks'); ?>
                        </button>
                    </div>
                </div>
            </div>
            <div class="superbaddons-admindashboard-sidebarlayout-right">
                <?php self::RenderAccessControlNotice(); ?>
                <!-- Keyboard Shortcuts Card -->
                <div class="spbaddons-form-shortcuts-card">
                    <h5><?php echo esc_html__('Keyboard Shortcuts', 'superb-blocks'); ?></h5>
                    <p class="superbaddons-element-text-xxs superbaddons-element-text-gray"><?php echo esc_html__('Available when viewing a submission in the detail panel.', 'superb-blocks'); ?></p>
                    <ul class="spbaddons-form-shortcuts-list">
                        <li>
                            <span><?php echo esc_html__('Next submission', 'superb-blocks'); ?></span>
                            <kbd>J</kbd>
                        </li>
                        <li>
                            <span><?php echo esc_html__('Previous submission', 'superb-blocks'); ?></span>
                            <kbd>K</kbd>
                        </li>
                        <li>
                            <span><?php echo esc_html__('Close panel', 'superb-blocks'); ?></span>
                            <kbd>Esc</kbd>
                        </li>
                    </ul>
                </div>
                <?php
                new ReviewBox();
                new SupportBox();
                ?>
            </div>
        </div>

        <!-- Slide-in Detail Panel (fixed position, outside sidebar layout) -->
        <div id="spbaddons-form-submissions-panel-overlay" class="spbaddons-form-submissions-panel-overlay" style="display:none;"></div>
        <div id="spbaddons-form-submissions-panel" class="spbaddons-form-submissions-panel" style="display:none;" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__('Submission Details', 'superb-blocks'); ?>">
            <div class="spbaddons-form-submissions-panel-header">
                <div class="spbaddons-form-submissions-panel-header-left">
                    <button type="button" id="spbaddons-form-submissions-panel-prev" class="spbaddons-form-submissions-panel-nav" aria-label="<?php echo esc_attr__('Previous submission', 'superb-blocks'); ?>"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/caret-left.svg'); ?>" alt="" aria-hidden="true" /></button>
                    <span id="spbaddons-form-submissions-panel-counter" class="superbaddons-element-text-xs superbaddons-element-text-gray"></span>
                    <button type="button" id="spbaddons-form-submissions-panel-next" class="spbaddons-form-submissions-panel-nav" aria-label="<?php echo esc_attr__('Next submission', 'superb-blocks'); ?>"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/caret-right.svg'); ?>" alt="" aria-hidden="true" /></button>
                </div>
                <div class="spbaddons-form-submissions-panel-header-right">
                    <button type="button" id="spbaddons-form-submissions-panel-star" class="spbaddons-form-submissions-panel-star" aria-label="<?php echo esc_attr__('Star submission', 'superb-blocks'); ?>">
                        <img class="spbaddons-star-icon-regular" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-star-regular.svg'); ?>" alt="" aria-hidden="true" width="18" height="18" />
                        <img class="spbaddons-star-icon-fill" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/purple-star-fill.svg'); ?>" alt="" aria-hidden="true" width="18" height="18" style="display:none;" />
                    </button>
                    <div class="spbaddons-form-submissions-panel-print-wrap">
                        <button type="button" id="spbaddons-form-submissions-panel-print" class="spbaddons-form-submissions-panel-print" aria-label="<?php echo esc_attr__('Print submission', 'superb-blocks'); ?>">
                            <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/printer-duotone.svg'); ?>" alt="" aria-hidden="true" width="18" height="18" />
                        </button>
                        <!-- Print popover -->
                        <div id="spbaddons-form-submissions-print-popover" class="spbaddons-form-submissions-print-popover" style="display:none;">
                            <label class="spbaddons-form-submissions-print-popover-label spbaddons-checkbox-wrap" id="spbaddons-form-submissions-print-sensitive-wrap" style="display:none;">
                                <input type="checkbox" id="spbaddons-form-submissions-print-sensitive" />
                                <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                <span><?php echo esc_html__('Include sensitive fields (unmasked)', 'superb-blocks'); ?></span>
                            </label>
                            <label class="spbaddons-form-submissions-print-popover-label spbaddons-checkbox-wrap" id="spbaddons-form-submissions-print-notes-wrap" style="display:none;">
                                <input type="checkbox" id="spbaddons-form-submissions-print-notes" />
                                <span class="spbaddons-checkbox-mark"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/checkmark.svg'); ?>" alt="" aria-hidden="true" /></span>
                                <span><?php echo esc_html__('Include notes', 'superb-blocks'); ?></span>
                            </label>
                            <button type="button" id="spbaddons-form-submissions-print-go" class="superbaddons-element-button spbaddons-admin-btn-primary superbaddons-element-button-small superbaddons-element-m0">
                                <?php echo esc_html__('Print', 'superb-blocks'); ?>
                            </button>
                        </div>
                    </div>
                    <div class="spbaddons-form-submissions-panel-actions-wrap">
                        <button type="button" id="spbaddons-form-submissions-panel-more" class="spbaddons-form-submissions-panel-more" aria-label="<?php echo esc_attr__('More actions', 'superb-blocks'); ?>">
                            <img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/dots-three.svg'); ?>" alt="" aria-hidden="true" width="18" height="18" />
                        </button>
                        <!-- Actions popover -->
                        <div id="spbaddons-form-submissions-actions-popover" style="display:none;">
                            <button type="button" id="spbaddons-form-submissions-panel-toggle-read" class="superbaddons-element-button superbaddons-element-button-small superbaddons-element-m0 spbaddons-panel-action-btn">
                                <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/eye-slash.svg'); ?>" alt="" aria-hidden="true" />
                                <span><?php echo esc_html__('Mark as Unread', 'superb-blocks'); ?></span>
                            </button>
                            <button type="button" id="spbaddons-form-submissions-panel-resend-admin" class="superbaddons-element-button superbaddons-element-button-small superbaddons-element-m0 spbaddons-panel-action-btn" style="display:none;">
                                <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/arrow-clockwise-duotone.svg'); ?>" alt="" aria-hidden="true" />
                                <?php echo esc_html__('Resend Admin Email', 'superb-blocks'); ?>
                            </button>
                            <button type="button" id="spbaddons-form-submissions-panel-resend-user" class="superbaddons-element-button superbaddons-element-button-small superbaddons-element-m0 spbaddons-panel-action-btn" style="display:none;">
                                <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/arrow-clockwise-duotone.svg'); ?>" alt="" aria-hidden="true" />
                                <?php echo esc_html__('Resend User Email', 'superb-blocks'); ?>
                            </button>
                            <button type="button" id="spbaddons-form-submissions-panel-not-spam" class="superbaddons-element-button superbaddons-element-button-small superbaddons-element-m0 spbaddons-panel-action-btn" style="display:none;">
                                <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/eye.svg'); ?>" alt="" aria-hidden="true" />
                                <?php echo esc_html__('Not Spam', 'superb-blocks'); ?>
                            </button>
                            <button type="button" id="spbaddons-form-submissions-panel-delete" class="superbaddons-element-button superbaddons-element-button-small spbaddons-admin-btn-danger superbaddons-element-m0 spbaddons-panel-action-btn">
                                <img class="superbaddons-element-button-icon" src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/trash-light.svg'); ?>" alt="" aria-hidden="true" />
                                <?php echo esc_html__('Delete Submission', 'superb-blocks'); ?>
                            </button>
                        </div>
                    </div>
                    <button type="button" id="spbaddons-form-submissions-panel-close" class="spbaddons-form-submissions-panel-close" aria-label="<?php echo esc_attr__('Close panel', 'superb-blocks'); ?>"><img src="<?php echo esc_url(SUPERBADDONS_ASSETS_PATH . '/img/x.svg'); ?>" alt="" aria-hidden="true" /></button>
                </div>
            </div>
            <div class="spbaddons-form-submissions-panel-meta" id="spbaddons-form-submissions-panel-meta">
                <!-- Date + status populated by JS -->
            </div>
            <div class="spbaddons-form-submissions-panel-fields" id="spbaddons-form-submissions-panel-fields">
                <!-- Field key-value pairs populated by JS -->
            </div>
            <!-- Email / Integration Status (informational, populated by JS) -->
            <div id="spbaddons-form-submissions-panel-email-status" class="spbaddons-form-submissions-panel-status-section" style="display:none;">
            </div>
            <div id="spbaddons-form-submissions-panel-integration-status" class="spbaddons-form-submissions-panel-status-section" style="display:none;">
            </div>
            <!-- Notes Section -->
            <div class="spbaddons-form-submissions-panel-notes" id="spbaddons-form-submissions-panel-notes">
                <div class="spbaddons-panel-notes-header">
                    <span class="spbaddons-panel-notes-title"><?php echo esc_html__('Notes', 'superb-blocks'); ?></span>
                    <span class="spbaddons-panel-notes-count" id="spbaddons-panel-notes-count"></span>
                </div>
                <div class="spbaddons-panel-notes-list" id="spbaddons-panel-notes-list">
                    <!-- Notes populated by JS -->
                </div>
                <div class="spbaddons-panel-notes-add">
                    <textarea id="spbaddons-panel-notes-input" class="spbaddons-panel-notes-input" placeholder="<?php echo esc_attr__('Add a note...', 'superb-blocks'); ?>" maxlength="1000" rows="2"></textarea>
                    <div class="spbaddons-panel-notes-add-footer">
                        <span class="spbaddons-panel-notes-chars" id="spbaddons-panel-notes-chars"></span>
                        <button type="button" id="spbaddons-panel-notes-submit" class="superbaddons-element-button spbaddons-admin-btn-primary superbaddons-element-button-sm" disabled>
                            <?php echo esc_html__('Add Note', 'superb-blocks'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Print-only container -->
        <div id="spbaddons-form-submissions-print" class="spbaddons-form-submissions-print">
            <div id="spbaddons-form-submissions-print-content"></div>
            <div class="spbaddons-form-submissions-print-footer"><?php echo esc_html(home_url()); ?></div>
        </div>
<?php
    }
}
