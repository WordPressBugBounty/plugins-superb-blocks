<?php

namespace SuperbAddons\Gutenberg\Controllers;

defined('ABSPATH') || exit();

class GutenbergConditionsController
{
    public static function Initialize()
    {
        add_filter('pre_render_block', [__CLASS__, 'MaybeHideBlock'], 10, 2);
    }

    public static function MaybeHideBlock($pre_render, $parsed_block)
    {
        // Preview contexts should always show blocks regardless of conditions,
        // so authors can see their own content when clicking "Preview".
        if (is_preview() || is_customize_preview()) {
            return $pre_render;
        }

        if (!isset($parsed_block['attrs']['spbaddConditions']) || !is_array($parsed_block['attrs']['spbaddConditions'])) {
            return $pre_render;
        }

        $visibility = $parsed_block['attrs']['spbaddConditions'];
        if (empty($visibility['ruleGroups']) || !is_array($visibility['ruleGroups'])) {
            return $pre_render;
        }

        $action = isset($visibility['action']) ? sanitize_text_field($visibility['action']) : 'show';
        $has_user_condition = false;
        $has_any_evaluated = false;
        $block_visible = false;

        foreach ($visibility['ruleGroups'] as $group) {
            if (empty($group['conditions']) || !is_array($group['conditions'])) {
                continue;
            }

            $all_pass = true;
            $has_evaluated = false;
            foreach ($group['conditions'] as $condition) {
                if (!is_array($condition) || empty($condition['type'])) {
                    continue;
                }

                $has_evaluated = true;
                $has_any_evaluated = true;
                $type = sanitize_text_field($condition['type']);
                if (in_array($type, array('userVisibility', 'userRoles'), true)) {
                    $has_user_condition = true;
                }

                if (!self::EvaluateCondition($condition)) {
                    $all_pass = false;
                    break;
                }
            }

            if ($all_pass && $has_evaluated) {
                $block_visible = true;
                break; // OR logic — any group passing is enough
            }
        }

        // Nothing evaluable (malformed/migrated data) — fall through as if no
        // conditions were configured rather than silently hiding the block.
        if (!$has_any_evaluated) {
            return $pre_render;
        }

        // Invert result for "hide if" action
        if ($action === 'hide') {
            $block_visible = !$block_visible;
        }

        // Output varies per visitor for user-based conditions — must not be cached.
        if ($has_user_condition) {
            GutenbergCacheUtil::MarkAsUncacheable();
        }

        if (!$block_visible) {
            // Short-circuits the entire render pipeline: children, render_callback,
            // and enqueues tied to this block never run.
            return '';
        }

        return $pre_render;
    }

    private static function EvaluateCondition($condition)
    {
        $type = isset($condition['type']) ? sanitize_text_field($condition['type']) : '';
        $operator = isset($condition['operator']) ? sanitize_text_field($condition['operator']) : 'is';
        if (!in_array($operator, array('is', 'isNot'), true)) {
            $operator = 'is';
        }

        switch ($type) {
            case 'userVisibility':
                // No operator — values are already opposites
                $val = isset($condition['value']) ? sanitize_text_field($condition['value']) : '';
                if ($val === 'logged-in') return is_user_logged_in();
                if ($val === 'logged-out') return !is_user_logged_in();
                return true;

            case 'userRoles':
                $roles = isset($condition['value']) && is_array($condition['value']) ? $condition['value'] : array();
                $roles = array_map('sanitize_text_field', $roles);
                if (empty($roles)) return true;
                if (!is_user_logged_in()) {
                    $result = false;
                } else {
                    $user = wp_get_current_user();
                    $result = !empty(array_intersect($roles, $user->roles));
                }
                return $operator === 'isNot' ? !$result : $result;

            case 'postType':
                $types = isset($condition['value']) && is_array($condition['value']) ? $condition['value'] : array();
                $types = array_map('sanitize_text_field', $types);
                if (empty($types)) return true;
                $result = in_array(get_post_type(), $types, true);
                return $operator === 'isNot' ? !$result : $result;

            case 'postAuthor':
                $authors = isset($condition['value']) && is_array($condition['value']) ? $condition['value'] : array();
                if (empty($authors)) return true;
                $result = in_array((int) get_post_field('post_author'), array_map('intval', $authors), true);
                return $operator === 'isNot' ? !$result : $result;

            case 'postCategory':
                $cats = isset($condition['value']) && is_array($condition['value']) ? $condition['value'] : array();
                if (empty($cats)) return true;
                $result = has_category(array_map('intval', $cats));
                return $operator === 'isNot' ? !$result : $result;

            case 'postTag':
                $tags = isset($condition['value']) && is_array($condition['value']) ? $condition['value'] : array();
                if (empty($tags)) return true;
                $result = has_tag(array_map('intval', $tags));
                return $operator === 'isNot' ? !$result : $result;

            default:
                // Delegate to premium/extensions (pass operator via condition array)
                $result = apply_filters('superbaddons_evaluate_visibility_condition', null, $condition);
                return $result === null ? true : (bool) $result;
        }
    }

    public static function GetConditionsRoles()
    {
        $wp_roles = wp_roles();
        $roles = array();
        foreach ($wp_roles->roles as $role_key => $role) {
            $roles[] = array(
                'value' => sanitize_text_field($role_key),
                'label' => translate_user_role($role['name']),
            );
        }
        return $roles;
    }
}
