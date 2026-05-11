<?php

namespace SuperbAddons\Components\Admin;

use SuperbAddons\Gutenberg\Form\FormEncryption;

defined('ABSPATH') || exit();

class EncryptionNotice
{
    public function __construct()
    {
        if (FormEncryption::IsAvailable()) {
            return;
        }
        $this->Render();
    }

    private function Render()
    {
?>
        <div class="notice notice-warning spbaddons-encryption-notice">
            <p>
                <strong><?php esc_html_e('Encryption Unavailable', 'superb-blocks'); ?></strong> &mdash;
                <?php esc_html_e('The OpenSSL PHP extension is not installed on your server. Sensitive form field values will be stored in plaintext. Contact your hosting provider to enable the OpenSSL extension.', 'superb-blocks'); ?>
            </p>
        </div>
<?php
    }
}
