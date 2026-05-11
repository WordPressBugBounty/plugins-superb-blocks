<?php

namespace SuperbAddons\Gutenberg\BlocksAPI\Controllers;

use SuperbAddons\Data\Controllers\LogController;
use Exception;

defined('ABSPATH') || exit();

class AuthorBoxController
{
    private static $allowed_name_tags = array('p', 'h2', 'h3', 'h4');
    private static $legacy_socials = array(
        'socialsLinkFacebook'  => 'facebook',
        'socialsLinkInstagram' => 'instagram',
        'socialsLinkX'         => 'x',
        'socialsLinkLinkedin'  => 'linkedin',
    );

    public static function Render($attributes, $content, $block = null)
    {
        try {
            $attributes = is_array($attributes) ? $attributes : array();

            $source = isset($attributes['authorSource']) ? $attributes['authorSource'] : 'custom';
            if (!in_array($source, array('custom', 'user', 'currentPost'), true)) {
                $source = 'custom';
            }

            $size = isset($attributes['avatarSize']) ? intval($attributes['avatarSize']) : 96;
            if ($size < 24) {
                $size = 24;
            } elseif ($size > 96) {
                $size = 96;
            }

            $user_id = self::resolveUserId($source, $attributes, $block);

            // Resolve display values
            if ($source === 'custom') {
                $name = isset($attributes['authorName']) ? (string) $attributes['authorName'] : '';
                $bio  = isset($attributes['authorBio'])  ? (string) $attributes['authorBio']  : '';
            } elseif ($user_id > 0) {
                $name = get_the_author_meta('display_name', $user_id);
                $bio  = get_the_author_meta('description', $user_id);
            } else {
                $name = '';
                $bio  = '';
            }

            $avatar_url  = self::resolveAvatarUrl($source, $user_id, $attributes, $size);
            $archive_url = '';
            if (!empty($attributes['linkNameToArchive']) && $user_id > 0) {
                $archive_url = get_author_posts_url($user_id);
            }
            $website_url = '';
            if ($source !== 'custom' && $user_id > 0) {
                $website_url = get_the_author_meta('user_url', $user_id);
            }

            $alignment = isset($attributes['toolbarAlignment']) ? $attributes['toolbarAlignment'] : 'left';
            if (!in_array($alignment, array('left', 'center', 'right'), true)) {
                $alignment = 'left';
            }

            $name_tag = isset($attributes['nameTagName']) ? $attributes['nameTagName'] : 'p';
            if (!in_array($name_tag, self::$allowed_name_tags, true)) {
                $name_tag = 'p';
            }

            $display_name    = !isset($attributes['displayName'])    || (bool) $attributes['displayName'];
            $display_bio     = !isset($attributes['displayBio'])     || (bool) $attributes['displayBio'];
            $display_socials = !isset($attributes['displaySocials']) || (bool) $attributes['displaySocials'];

            $avatar_enabled = !isset($attributes['avatarEnabled']) || (bool) $attributes['avatarEnabled'];
            $border_radius  = isset($attributes['avatarBorderRadius']) ? intval($attributes['avatarBorderRadius']) : 100;
            $font_name      = isset($attributes['fontSizeAuthorName']) ? intval($attributes['fontSizeAuthorName']) : 32;
            $font_bio       = isset($attributes['fontSizeAuthorBio'])  ? intval($attributes['fontSizeAuthorBio'])  : 14;

            // Wrapper colors via CSS variables (mirrors editor preview).
            $color_style = self::buildColorStyle($attributes);
            $wrapper_extra = array(
                'class' => 'superbaddons-authorbox superbaddons-authorbox-alignment-' . $alignment,
            );
            if ($color_style !== '') {
                $wrapper_extra['style'] = $color_style;
            }
            $wrapper_attributes = get_block_wrapper_attributes($wrapper_extra);

            $socials_html = '';
            if ($display_socials) {
                $socials_html = self::renderSocials($content, $block, $attributes, $website_url);
            }

            ob_start();
?>
<div <?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() returns pre-escaped HTML attribute markup per WP core API.
echo $wrapper_attributes;
?>>
    <?php if ($avatar_enabled) : ?>
        <div class="superbaddons-authorbox-left">
            <?php if ($avatar_url !== '') : ?>
                <img
                    class="superbaddons-authorbox-avatar"
                    src="<?php echo esc_url($avatar_url); ?>"
                    alt="<?php echo esc_attr($name); ?>"
                    width="<?php echo esc_attr($size); ?>"
                    height="<?php echo esc_attr($size); ?>"
                    style="border-radius:<?php echo esc_attr($border_radius / 2); ?>%;width:<?php echo esc_attr($size); ?>px;"
                />
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="superbaddons-authorbox-right">
        <?php if ($display_name && $name !== '') : ?>
            <?php
            $name_class = 'superbaddons-authorbox-authorname';
            if ($archive_url !== '') {
                $name_class .= ' superbaddons-authorbox-authorname-linked';
            }
            $name_style = 'font-size:' . $font_name . 'px;line-height:' . ($font_name + 8) . 'px;';
            ?>
            <<?php echo esc_html($name_tag); ?> class="<?php echo esc_attr($name_class); ?>" style="<?php echo esc_attr($name_style); ?>">
                <?php if ($archive_url !== '') : ?>
                    <a href="<?php echo esc_url($archive_url); ?>"><?php echo esc_html($name); ?></a>
                <?php else : ?>
                    <?php echo esc_html($name); ?>
                <?php endif; ?>
            </<?php echo esc_html($name_tag); ?>>
        <?php endif; ?>

        <?php if ($display_bio && $bio !== '') : ?>
            <p class="superbaddons-authorbox-authorbio" style="font-size:<?php echo esc_attr($font_bio); ?>px;line-height:<?php echo esc_attr($font_bio + 5); ?>px;">
                <?php echo esc_html($bio); ?>
            </p>
        <?php endif; ?>

        <?php if ($socials_html !== '') : ?>
            <div class="superbaddons-authorbox-social-wrapper">
                <?php
                // SAFE OUTPUT: $socials_html contains only HTML produced by trusted WordPress core code, never raw user input.
                // It is the concatenation of:
                //   1. The output of WordPress core's render_block() for the inner core/social-links block
                //      (passed in as $content by core, or built via render_block() in renderLegacySocials()).
                //      User-supplied attributes (service name, URL) are sanitized by core's own block render
                //      callbacks before being inserted into the markup. We do not concatenate any user input here.
                //   2. An optional website link built in renderSocials() using esc_url() on the href and
                //      esc_html() on the visible text.
                // Escaping with wp_kses_post() is NOT safe here: core/social-links emits inline <svg> icons,
                // and wp_kses_post()'s allowlist strips <svg> entirely, which would render empty social links.
                // This matches the pattern WordPress core itself uses to output rendered inner block content.
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $socials_html;
                ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php
            return ob_get_clean();
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return '';
        }
    }

    private static function resolveUserId($source, $attributes, $block)
    {
        if ($source === 'currentPost') {
            $post_id = 0;
            if ($block !== null && isset($block->context) && isset($block->context['postId'])) {
                $post_id = intval($block->context['postId']);
            }
            if ($post_id <= 0) {
                return 0;
            }
            $author = get_post_field('post_author', $post_id);
            return $author ? intval($author) : 0;
        }
        if ($source === 'user') {
            return isset($attributes['authorUserId']) ? intval($attributes['authorUserId']) : 0;
        }
        return 0;
    }

    private static function resolveAvatarUrl($source, $user_id, $attributes, $size)
    {
        if ($source === 'custom') {
            $custom_id = isset($attributes['customAuthorImageId']) ? intval($attributes['customAuthorImageId']) : 0;
            if ($custom_id > 0) {
                $url = wp_get_attachment_image_url($custom_id, array($size, $size));
                if ($url) {
                    return $url;
                }
            }
            if (!empty($attributes['customAuthorImage'])) {
                return esc_url_raw($attributes['customAuthorImage']);
            }
            if (!empty($attributes['authorImage'])) {
                return esc_url_raw($attributes['authorImage']);
            }
            return '';
        }
        if ($user_id > 0) {
            $url = get_avatar_url($user_id, array('size' => $size));
            return $url ? $url : '';
        }
        return '';
    }

    private static function buildColorStyle($attributes)
    {
        $parts = array();

        $name_wpc = isset($attributes['colorAuthorNameWPC']) ? $attributes['colorAuthorNameWPC'] : '';
        $name_raw = isset($attributes['colorAuthorName']) ? $attributes['colorAuthorName'] : '';
        if (!empty($name_wpc)) {
            $parts[] = '--superb-authorbox-name-color:var(--wp--preset--color--' . sanitize_html_class($name_wpc) . ')';
        } elseif (!empty($name_raw) && self::isValidColor($name_raw)) {
            $parts[] = '--superb-authorbox-name-color:' . $name_raw;
        }

        $bio_wpc = isset($attributes['colorAuthorBioWPC']) ? $attributes['colorAuthorBioWPC'] : '';
        $bio_raw = isset($attributes['colorAuthorBio']) ? $attributes['colorAuthorBio'] : '';
        if (!empty($bio_wpc)) {
            $parts[] = '--superb-authorbox-bio-color:var(--wp--preset--color--' . sanitize_html_class($bio_wpc) . ')';
        } elseif (!empty($bio_raw) && self::isValidColor($bio_raw)) {
            $parts[] = '--superb-authorbox-bio-color:' . $bio_raw;
        }

        // Trailing semicolon matters: get_block_wrapper_attributes() concatenates
        // this with the block-supports style string (background, border, etc.).
        // Without it, the last declaration runs into the next one and the CSS
        // parser drops the combined invalid declaration.
        return empty($parts) ? '' : implode(';', $parts) . ';';
    }

    private static function isValidColor($value)
    {
        if (!is_string($value)) {
            return false;
        }
        return (bool) preg_match('/^(#[0-9a-f]{3,8}|(?:rgb|hsl|oklch|oklab|color)\([^;{}]*\))$/i', $value);
    }

    /**
     * Build the social row HTML.
     *
     * For migrated/new blocks $content is the pre-rendered core/social-links
     * InnerBlock (WP renders inner_blocks into $content before calling us).
     * Legacy 0.2.0 blocks that were never re-saved have no inner_blocks and
     * $content is the old static layout HTML — we ignore it and render fresh
     * icons from the legacy socialsLink* attributes so users still see them
     * on the frontend until the editor migration runs.
     */
    private static function renderSocials($content, $block, $attributes, $website_url)
    {
        $has_inner_blocks = $block !== null && !empty($block->inner_blocks);
        $socials = $has_inner_blocks && is_string($content) ? $content : '';

        if (trim($socials) === '') {
            $socials = self::renderLegacySocials($attributes);
        }

        $website = '';
        if (!empty($website_url)) {
            $website = '<a class="superbaddons-authorbox-author-website" href="' . esc_url($website_url) . '" rel="noopener noreferrer" target="_blank">' . esc_html($website_url) . '</a>';
        }

        if (trim($socials) === '' && $website === '') {
            return '';
        }
        return $socials . $website;
    }

    private static function renderLegacySocials($attributes)
    {
        $children = array();
        foreach (self::$legacy_socials as $attr_key => $service) {
            $url = isset($attributes[$attr_key]) ? trim((string) $attributes[$attr_key]) : '';
            if ($url === '') {
                continue;
            }
            $children[] = array(
                'blockName' => 'core/social-link',
                'attrs' => array('service' => $service, 'url' => $url),
                'innerBlocks' => array(),
                'innerHTML' => '',
                'innerContent' => array(),
            );
        }
        if (empty($children)) {
            return '';
        }
        $block = array(
            'blockName' => 'core/social-links',
            'attrs' => array(),
            'innerBlocks' => $children,
            'innerHTML' => '',
            'innerContent' => array_merge(array(null), array_fill(0, count($children), null)),
        );
        return render_block($block);
    }
}
