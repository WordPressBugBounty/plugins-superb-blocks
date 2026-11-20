<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormSubmissionCPT
{
    const POST_TYPE = 'spb_form_submission';

    public static function Initialize()
    {
        add_action('init', array(__CLASS__, 'RegisterPostType'));
        add_filter('posts_where', array(__CLASS__, 'KeepOutOfPublicQueries'), PHP_INT_MAX, 2);
    }

    public static function RegisterPostType()
    {
        // Records are reached through the plugin's own routes, which check FormPermissions. Core's post
        // capabilities (XML-RPC, post.php, the posts REST API) map to manage_options, so a role the form
        // access-control matrix does not grant cannot list, change or delete records through them.
        // The meta capabilities (edit_post, read_post, delete_post) keep their derived names: core registers
        // the reverse mapping of those three globally, so pointing them at manage_options would turn every
        // manage_options check on the site into a post check.
        $admin_only = array_fill_keys(array(
            'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'read',
            'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts',
            'edit_private_posts', 'edit_published_posts', 'create_posts',
        ), 'manage_options');

        // Records are private data. Every argument that decides whether a post type shows up on the front end
        // is set here rather than left to its default, so a changed default in WordPress does not open one.
        $args = array(
            'labels' => array(
                'name' => __('Form Submissions', 'superb-blocks'),
                'singular_name' => __('Form Submission', 'superb-blocks'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'has_archive' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array('title'),
            'capability_type' => array('spb_form_submission', 'spb_form_submissions'),
            'capabilities' => $admin_only,
            'map_meta_cap' => true,
        );
        // WordPress 7.2 redirects /random/ to a random post of every post type registered as randomable.
        // The argument is only passed where WordPress knows it.
        if (function_exists('wp_is_random_content_redirect_enabled')) {
            $args['randomable'] = false;
        }

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Records never appear in a public listing. The registration above keeps them out of archives, search,
     * feeds and the random content redirect; this holds the same line in the query itself, so it also applies
     * when other code changes the registration or WordPress adds another kind of public listing. Only the main
     * query of a front end request is touched: the plugin reads records through queries of its own, in the
     * admin and over REST.
     *
     * @param string    $where The WHERE clause of the query.
     * @param \WP_Query $query The query.
     * @return string
     */
    public static function KeepOutOfPublicQueries($where, $query = null)
    {
        global $wpdb;

        if (!($query instanceof \WP_Query) || is_admin() || !$query->is_main_query()) {
            return $where;
        }

        return $where . $wpdb->prepare(" AND {$wpdb->posts}.post_type <> %s", self::POST_TYPE);
    }
}
