<?php

namespace SuperbAddons\Gutenberg\Import;

defined('ABSPATH') || exit();

/**
 * Rewrites unique block IDs (and the attributes that reference them) inside
 * library-imported block content so that every import produces fresh IDs.
 *
 * Context: the theme designer wizard and the editor library browser insert
 * prebuilt library content via pure PHP (wp_insert_post). The client-side
 * duplicate-detection hooks in the block edit components never run on that
 * content, so importing the same template twice would otherwise leave the
 * site with colliding popupId / formId values, breaking FormRegistry,
 * PopupRegistry, form submission storage, and button->popup references.
 */
class GutenbergBlockIdRegenerator
{
    /**
     * Blocks that own a unique ID attribute.
     *
     * Each entry:
     *   - attr:      the attribute name holding the unique ID
     *   - prefix:    prefix used when generating a new ID (mirrors JS generateBlockId)
     *   - namespace: logical grouping used for reference rewrites
     *   - extras:    optional map of additional attributes that should also be
     *                regenerated whenever the owner ID is regenerated
     *                (e.g. form honeypot key). Keyed by attribute name, value
     *                is the prefix for the extra.
     */
    private static $owners = array(
        'superb-addons/popup' => array(
            'attr'      => 'popupId',
            'prefix'    => 'superb-popup-',
            'namespace' => 'popup',
        ),
        'superb-addons/form' => array(
            'attr'      => 'formId',
            'prefix'    => 'form_',
            'namespace' => 'form',
            'extras'    => array(
                'honeypotKey' => 'field_',
            ),
        ),
        'superb-addons/multistep-form' => array(
            'attr'      => 'formId',
            'prefix'    => 'form_',
            'namespace' => 'form',
            'extras'    => array(
                'honeypotKey' => 'field_',
            ),
        ),
        'superb-addons/accordion-block' => array(
            'attr'      => 'accordionId',
            'prefix'    => 'superb-accordion-',
            'namespace' => 'accordion',
        ),
        'superb-addons/countdown' => array(
            'attr'      => 'countdownId',
            'prefix'    => 'superb-countdown-',
            'namespace' => 'countdown',
        ),
    );

    /**
     * Blocks that reference an owner ID via an attribute. Each block can
     * have multiple reference attributes.
     *
     * Structure: blockName => array of array('attr' => ..., 'namespace' => ...)
     */
    private static $references = array(
        'core/button' => array(
            array(
                'attr'      => 'spbaddPopupTarget',
                'namespace' => 'popup',
            ),
        ),
    );

    /**
     * Live registries to avoid collisions with IDs that already exist on
     * other posts. Loaded lazily once per call.
     */
    private static $live_taken = null;

    /**
     * Entry point. Takes serialized block markup, regenerates owner IDs,
     * rewrites matching references, and returns the rewritten markup.
     *
     * @param string $content
     * @return string
     */
    public static function RegenerateIds($content)
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }

        // Early exit: if content contains none of the managed owner blocks, nothing to do.
        $has_any_owner = false;
        foreach (self::$owners as $block_name => $config) {
            if (has_block($block_name, $content)) {
                $has_any_owner = true;
                break;
            }
        }
        if (!$has_any_owner) {
            return $content;
        }

        $parsed_blocks = parse_blocks($content);

        // Namespace => array(old_id => new_id)
        $id_map = array();
        // Namespace => array(id => true) of all IDs already used (live registries + current batch).
        $taken = self::LoadLiveTaken();

        // Pass 1: regenerate owner IDs, populate id_map.
        self::WalkBlocks($parsed_blocks, function (&$block) use (&$id_map, &$taken) {
            if (empty($block['blockName']) || !isset(self::$owners[$block['blockName']])) {
                return;
            }
            $config    = self::$owners[$block['blockName']];
            $attr_name = $config['attr'];
            $namespace = $config['namespace'];

            if (!isset($block['attrs']) || !is_array($block['attrs'])) {
                $block['attrs'] = array();
            }

            $old_id = isset($block['attrs'][$attr_name]) ? (string) $block['attrs'][$attr_name] : '';

            $new_id = self::GenerateUniqueId($config['prefix'], $namespace, $taken);
            $block['attrs'][$attr_name] = $new_id;

            if ($old_id !== '') {
                if (!isset($id_map[$namespace])) {
                    $id_map[$namespace] = array();
                }
                $id_map[$namespace][$old_id] = $new_id;
            }

            // Regenerate "extras" (e.g. form honeypotKey). These are not cross-referenced,
            // so we do not track them in the id_map.
            if (!empty($config['extras']) && is_array($config['extras'])) {
                foreach ($config['extras'] as $extra_attr => $extra_prefix) {
                    // Use a synthetic "extras" namespace for collision tracking so the
                    // same-call taken-set catches intra-batch dupes. This is cheap.
                    $block['attrs'][$extra_attr] = self::GenerateUniqueId($extra_prefix, '__extras__', $taken);
                }
            }
        });

        // Pass 2: rewrite references using the id_map.
        if (!empty($id_map)) {
            self::WalkBlocks($parsed_blocks, function (&$block) use ($id_map) {
                if (empty($block['blockName']) || !isset(self::$references[$block['blockName']])) {
                    return;
                }
                if (!isset($block['attrs']) || !is_array($block['attrs'])) {
                    return;
                }
                foreach (self::$references[$block['blockName']] as $ref_config) {
                    $attr_name = $ref_config['attr'];
                    $namespace = $ref_config['namespace'];
                    if (!isset($block['attrs'][$attr_name])) {
                        continue;
                    }
                    $current = (string) $block['attrs'][$attr_name];
                    if ($current === '') {
                        continue;
                    }
                    if (isset($id_map[$namespace]) && isset($id_map[$namespace][$current])) {
                        $block['attrs'][$attr_name] = $id_map[$namespace][$current];
                    }
                }
            });
        }

        return serialize_blocks($parsed_blocks);
    }

    /**
     * Recursively walk a block tree (by reference) and invoke $callback on
     * each block. Descent into innerBlocks is skipped for core/block so that
     * synced pattern references stay byte-identical with their shared source.
     *
     * @param array    $blocks
     * @param callable $callback
     */
    private static function WalkBlocks(&$blocks, $callback)
    {
        if (!is_array($blocks)) {
            return;
        }
        foreach ($blocks as &$block) {
            if (!is_array($block)) {
                continue;
            }
            call_user_func_array($callback, array(&$block));

            // Skip descent into synced patterns: editing IDs inside a core/block
            // wrapper would desync it from the shared wp_block source.
            $block_name = isset($block['blockName']) ? $block['blockName'] : '';
            if ($block_name === 'core/block') {
                continue;
            }

            if (isset($block['innerBlocks']) && is_array($block['innerBlocks']) && !empty($block['innerBlocks'])) {
                self::WalkBlocks($block['innerBlocks'], $callback);
            }
        }
        unset($block);
    }

    /**
     * Generate a fresh ID with the given prefix, ensuring it does not collide
     * with any ID already recorded in $taken (for the given namespace) or in
     * the cross-namespace "*" bucket that holds live registry entries.
     *
     * @param string $prefix
     * @param string $namespace
     * @param array  $taken Keyed by namespace => array(id => true).
     * @return string
     */
    private static function GenerateUniqueId($prefix, $namespace, &$taken)
    {
        if (!isset($taken[$namespace])) {
            $taken[$namespace] = array();
        }

        // Retry loop. wp_generate_password(8, false, false) yields 8 lowercase
        // alphanumeric chars (~2.8e12 space), so collisions are essentially
        // impossible; loop is a defensive safety net.
        for ($i = 0; $i < 10; $i++) {
            $candidate = $prefix . wp_generate_password(8, false, false);
            if (isset($taken[$namespace][$candidate])) {
                continue;
            }
            $taken[$namespace][$candidate] = true;
            return $candidate;
        }

        // Fallback: uniqid() is always distinct within a process.
        $candidate = $prefix . substr(str_replace('.', '', uniqid('', true)), 0, 8);
        $taken[$namespace][$candidate] = true;
        return $candidate;
    }

    /**
     * Build a per-namespace "taken" map seeded from the live form and popup
     * registries so that generated IDs never collide with IDs already
     * assigned to forms/popups on other posts.
     *
     * @return array
     */
    private static function LoadLiveTaken()
    {
        $taken = array();

        $form_registry = get_option('spb_form_registry', array());
        if (is_array($form_registry) && !empty($form_registry)) {
            $taken['form'] = array();
            foreach ($form_registry as $fid => $_entry) {
                $taken['form'][(string) $fid] = true;
            }
        }

        $popup_registry = get_option('spb_popup_registry', array());
        if (is_array($popup_registry) && !empty($popup_registry)) {
            $taken['popup'] = array();
            foreach ($popup_registry as $pid => $_entry) {
                $taken['popup'][(string) $pid] = true;
            }
        }

        return $taken;
    }
}
