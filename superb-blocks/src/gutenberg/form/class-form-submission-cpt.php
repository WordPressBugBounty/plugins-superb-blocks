<?php

namespace SuperbAddons\Gutenberg\Form;

defined('ABSPATH') || exit();

class FormSubmissionCPT
{
    const POST_TYPE = 'spb_form_submission';

    public static function Initialize()
    {
        add_action('init', array(__CLASS__, 'RegisterPostType'));
    }

    public static function RegisterPostType()
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Form Submissions', 'superb-blocks'),
                'singular_name' => __('Form Submission', 'superb-blocks'),
            ),
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => array('title'),
            'capability_type' => 'post',
        ));
    }
}
