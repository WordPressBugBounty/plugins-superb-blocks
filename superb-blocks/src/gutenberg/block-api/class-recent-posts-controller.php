<?php

namespace SuperbAddons\Gutenberg\BlocksAPI\Controllers;

use SuperbAddons\Data\Controllers\LogController;
use Exception;

defined('ABSPATH') || exit();

class RecentPostsController
{
    public static function DynamicRender($attributes, $content)
    {
        try {
            if ((!$attributes['displayBlockOnFront'] && is_front_page()) ||
                (!$attributes['displayBlockOnBlog'] && is_home()) ||
                (!$attributes['displayBlockOnPagesPosts'] && !is_front_page() && !is_home())
            ) {
                return '<!-- Superb Recent Posts Block Hidden -->';
            }
            $excludecurrent = ($attributes['excludeCurrent'] && !is_front_page() && !is_home()) ? intval(get_the_ID()) : false;
            $numberOfPosts = $excludecurrent !== false ? intval($attributes['numberOfPosts']) + 1 : intval($attributes['numberOfPosts']);
            $recent_posts_args = array("numberposts" => $numberOfPosts, "post_status" => "publish");

            if (count($attributes['selectedCategories']) > 0) {
                $recent_posts_args['category__in'] = $attributes['selectedCategories'];
            }
            if (count($attributes['selectedTags']) > 0) {
                $recent_posts_args['tag__in'] = $attributes['selectedTags'];
            }
            $recent_posts_args = apply_filters('superbaddons_recent_posts_block_args', $recent_posts_args, $attributes);

            $recent_posts = wp_get_recent_posts($recent_posts_args);

            // Filter in PHP to preserve query cache across pages
            if ($excludecurrent !== false) {
                $filtered_posts = array();
                $limit = intval($attributes['numberOfPosts']);
                $count = 0;
                foreach ($recent_posts as $post) {
                    if (intval($post['ID']) !== $excludecurrent) {
                        $filtered_posts[] = $post;
                        if (++$count >= $limit) {
                            break;
                        }
                    }
                }
                $recent_posts = $filtered_posts;
            }

            return self::Render($attributes, $recent_posts);
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return;
        }
    }

    /**
     * Legacy defaults for blocks saved before the native-color migration.
     * Dynamic blocks cannot use WordPress' deprecated/migrate pipeline
     * (no save function = validation never fails), so we fall back here
     * when a block has no WPC slug and no explicit raw value.
     */
    private static $color_legacy_defaults = array(
        'colorTitle'        => '#444444',
        'colorExcerpt'      => '#7C7C7C',
        'colorMeta'         => '#7C7C7C',
        'colorCommentCount' => '#7C7C7C',
    );

    /**
     * Resolve a color value: prefer WPC slug as CSS custom property,
     * then explicit raw value, then legacy default for backwards compat.
     */
    private static function resolveColor($attributes, $attrName)
    {
        $wpc = isset($attributes[$attrName . 'WPC']) ? $attributes[$attrName . 'WPC'] : '';
        $raw = isset($attributes[$attrName]) ? $attributes[$attrName] : '';
        if (!empty($wpc)) {
            return 'var(--wp--preset--color--' . esc_attr($wpc) . ')';
        }
        if (!empty($raw)) {
            return esc_attr($raw);
        }
        return isset(self::$color_legacy_defaults[$attrName]) ? esc_attr(self::$color_legacy_defaults[$attrName]) : '';
    }

    private static function Render($attributes, $recent_posts)
    {
        ob_start();

        // If no posts found
        if (count($recent_posts) <= 0) : ?>
            <div class="superbaddons-recentposts-wrapper">
                <p style="font-weight:500;"><?php echo esc_html__("No posts found", "superb-blocks"); ?></p>
            </div>
        <?php return ob_get_clean();
        endif;

        // If posts found
        ?>
        <div class="superbaddons-recentposts-wrapper superbaddons-recentposts-alignment-<?php echo esc_attr($attributes['toolbarAlignment']); ?>">
            <ul class="superbaddons-recentposts-list">
                <?php
                $wrapperTag = $attributes['IsInEditor'] ? 'span' : 'a';
                foreach ($recent_posts as $post) {
                    $permalink = $attributes['IsInEditor'] ? "#" : get_permalink($post['ID']);
                    $the_post_title = $post['post_title'] === '' ? $post['post_name'] : $post['post_title'];
                    $temp_thumbnail_url = get_the_post_thumbnail_url($post['ID'], array($attributes['thumbnailSize'], $attributes['thumbnailSize']));
                    $thumbnail_url = !$temp_thumbnail_url ? SUPERBADDONS_ASSETS_PATH . '/img/post-thumbnail-placeholder.png' : $temp_thumbnail_url;
                ?>
                    <li class="superbaddons-recentposts-item">
                        <<?php echo esc_html($wrapperTag); ?> href="<?php echo esc_url($permalink) ?>" <?php echo $attributes['linksTargetBlank'] && !$attributes['IsInEditor'] ? 'target="_blank"' : '' ?>>
                            <div class="superbaddons-recentposts-item-inner">
                                <?php if ($attributes['displayThumbnails'] && ($attributes['displayPlaceholderThumbnails'] || $temp_thumbnail_url !== false)) : ?>
                                    <div class="superbaddons-recentposts-item-left">
                                        <img width="<?php echo esc_attr($attributes['thumbnailSize']) ?>" height="<?php echo esc_attr($attributes['thumbnailSize']) ?>" src="<?php echo esc_url($thumbnail_url) ?>" <?php echo $attributes['imgBorderRadiusEnabled'] ? 'style="border-radius:' . esc_attr($attributes['imgBorderRadius'] / 2) . '%;"' : ""; ?> />
                                    </div>
                                <?php endif; ?>
                                <div class="superbaddons-recentposts-item-right">
                                    <?php if ($attributes['displayDate'] || $attributes['displayAuthor']) : ?>
                                        <?php
                                        // Meta
                                        $colorMeta = self::resolveColor($attributes, 'colorMeta');
                                        $metaStyle = 'font-size:' . esc_attr($attributes['fontSizeMeta']) . 'px;';
                                        if ($colorMeta) $metaStyle .= ' color:' . $colorMeta . ';';
                                        ?>
                                        <span style="<?php echo esc_attr($metaStyle); ?>">
                                            <?php if ($attributes['displayDate']) : ?>
                                                <time class="superbaddons-recentposts-item-date">
                                                    <?php echo esc_html(get_the_date(get_option('date_format', 'F j, Y'), $post['ID'])); ?>
                                                </time>
                                            <?php endif; ?>
                                            <?php if ($attributes['displayAuthor']) : ?>
                                                <span class="superbaddons-recentposts-item-author">
                                                    <?php echo esc_html(__("by", "superb-blocks") . " " . get_the_author_meta($attributes['useAuthorDisplayName'] ? 'display_name' : 'user_nicename', $post['post_author'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php
                                    // Title
                                    $colorTitle = self::resolveColor($attributes, 'colorTitle');
                                    $titleStyle = 'font-size:' . esc_attr($attributes['fontSizeTitle']) . 'px;';
                                    if ($colorTitle) $titleStyle .= ' color:' . $colorTitle . ';';
                                    ?>
                                    <span style="<?php echo esc_attr($titleStyle); ?>"><?php echo esc_html($the_post_title); ?></span>

                                    <?php if ($attributes['displayExcerpt']) : ?>
                                        <?php
                                        // Excerpt
                                        $colorExcerpt = self::resolveColor($attributes, 'colorExcerpt');
                                        $excerptStyle = 'font-size:' . esc_attr($attributes['fontSizeExcerpt']) . 'px;';
                                        if ($colorExcerpt) $excerptStyle .= ' color:' . $colorExcerpt . ';';
                                        ?>
                                        <span style="<?php echo esc_attr($excerptStyle); ?>">
                                            <?php echo esc_html(
                                                wp_trim_words(
                                                    excerpt_remove_blocks(strip_shortcodes($post['post_content'])),
                                                    $attributes['excerptLength'],
                                                    // Can't apply this filter using wp_trim_excerpt() as we want to apply the users custom excerpt length without affecting general excerpts.
                                                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
                                                    apply_filters('excerpt_more', ' ' . '[&hellip;]')
                                                )
                                            ); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($attributes['displayCommentCount']) : ?>
                                        <?php
                                        // Comment Count
                                        $colorCommentCount = self::resolveColor($attributes, 'colorCommentCount');
                                        $commentStyle = 'font-size:' . esc_attr($attributes['fontSizeCommentCount']) . 'px;';
                                        if ($colorCommentCount) $commentStyle .= ' color:' . $colorCommentCount . ';';
                                        ?>
                                        <span style="<?php echo esc_attr($commentStyle); ?>">
                                            <?php echo esc_html(get_comment_count($post['ID'])['approved'] . " " . __("comment(s)", "superb-blocks")); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </<?php echo esc_html($wrapperTag) ?>>
                    </li>
                <?php
                }
                ?>
            </ul>
        </div>
<?php return ob_get_clean();
    }
}
