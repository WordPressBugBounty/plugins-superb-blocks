<?php

namespace SuperbAddons\Library\Controllers;

defined('ABSPATH') || exit();

use Exception;
use WP_Error;
use WP_REST_Server;
use SuperbAddons\Config\Capabilities;
use SuperbAddons\Data\Controllers\CacheController;
use SuperbAddons\Data\Controllers\DomainShiftController;
use SuperbAddons\Data\Controllers\KeyController;
use SuperbAddons\Data\Controllers\OptionController;
use SuperbAddons\Data\Controllers\RestController;
use SuperbAddons\Data\Utils\CacheException;
use SuperbAddons\Data\Utils\CacheTypes;
use SuperbAddons\Data\Utils\ElementorCache;
use SuperbAddons\Data\Utils\ChunkLoading;
use SuperbAddons\Data\Utils\GutenbergCache;
use SuperbAddons\Data\Utils\RequestException;
use SuperbAddons\Elementor\Controllers\ElementorController;
use SuperbAddons\Gutenberg\Controllers\GutenbergController;


class LibraryRequestController
{
    const ELEMENTOR_LIST_ROUTE = '/elementor-list';
    const ELEMENTOR_INSERT_ROUTE = '/elementor-insert';

    // Gutenberg v2 routes (unified)
    const GUTENBERG_V2_LIST_ROUTE = '/gutenberg-v2-list';
    const GUTENBERG_V2_LIST_CHUNK_ROUTE = '/gutenberg-v2-list-chunk';
    const GUTENBERG_V2_WARM_CACHE_ROUTE = '/gutenberg-v2-warm-cache';
    const GUTENBERG_V2_INSERT_ROUTE = '/gutenberg-v2-insert';

    // Gutenberg v2 insert type params
    const GUTENBERG_TYPE_PATTERN = 'pattern';
    const GUTENBERG_TYPE_PAGE = 'page';

    const ELEMENTOR_ENDPOINT_BASE = 'elementor-library/';
    const GUTENBERG_V2_ENDPOINT = 'gutenberg-library/v2/library';

    const PLUGIN_NAMES = array(
        'woocommerce/woocommerce.php' => 'WooCommerce',
    );

    public function __construct()
    {
        RestController::AddRoute(self::ELEMENTOR_LIST_ROUTE, array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array($this, 'LibraryCallbackPermissionCheck'),
            'callback' => array($this, 'ElementorListCallback'),
        ));
        RestController::AddRoute(self::ELEMENTOR_INSERT_ROUTE, array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array($this, 'LibraryCallbackPermissionCheck'),
            'callback' => array($this, 'ElementorInsertCallback'),
        ));

        // v2 Gutenberg routes
        RestController::AddRoute(self::GUTENBERG_V2_LIST_ROUTE, array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array($this, 'LibraryCallbackPermissionCheck'),
            'callback' => array($this, 'GutenbergV2ListCallback'),
        ));
        RestController::AddRoute(self::GUTENBERG_V2_LIST_CHUNK_ROUTE, array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array($this, 'LibraryCallbackPermissionCheck'),
            'callback' => array($this, 'GutenbergV2ListChunkCallback'),
        ));
        RestController::AddRoute(self::GUTENBERG_V2_WARM_CACHE_ROUTE, array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array($this, 'LibraryCallbackPermissionCheck'),
            'callback' => array($this, 'GutenbergV2WarmCacheCallback'),
        ));
        RestController::AddRoute(self::GUTENBERG_V2_INSERT_ROUTE, array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array($this, 'LibraryCallbackPermissionCheck'),
            'callback' => array($this, 'GutenbergV2InsertCallback'),
        ));
    }

    public function LibraryCallbackPermissionCheck()
    {
        // Restrict endpoint to only users who have the proper capability.
        if (!current_user_can(Capabilities::CONTRIBUTOR)) {
            return new WP_Error('rest_forbidden', esc_html__('Unauthorized. Please check user permissions.', "superb-blocks"), array('status' => 401));
        }

        return true;
    }

    // ─── Elementor (unchanged) ──────────────────────────────────────────

    public function ElementorListCallback()
    {
        try {
            $section_cache = CacheController::GetCache(ElementorCache::SECTIONS, CacheTypes::ELEMENTOR);
            if (!!$section_cache) {
                // Local cache accepted
                $section_cache->premium = KeyController::HasValidPremiumKey();
                return rest_ensure_response($section_cache);
            }

            return $this->ElementorListHandler();
        } catch (CacheException $cex) {
            return new \WP_Error('internal_error_cache', 'Internal Cache Error: ' .  esc_html($cex->getMessage()), array('status' => 500));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    private function ElementorListHandler()
    {
        // Fetch data cache from service
        $options_controller = new OptionController();
        $license_key = $options_controller->GetKey();

        $response = DomainShiftController::RemoteGet(self::ELEMENTOR_ENDPOINT_BASE . 'sections?action=list&key=' . $license_key);
        ///
        if (!is_array($response) || is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return new \WP_Error('service_unavailable', 'Plugin Service Unavailable', array('status' => 503));
        }
        ///
        $data = json_decode($response['body']);
        if (isset($data->code) && isset($data->data) && isset($data->message)) {
            $status = isset($data->data->status) ? $data->data->status : 500;
            return new \WP_Error($data->code, $data->message, array('status' => $status));
        }
        if (isset($data->level)) {
            KeyController::UpdateKeyType($data->level, $data->active, $data->expired, $data->exceeded);
        }

        // Sort items
        if (isset($data->items) && is_array($data->items) && !empty($data->items)) {
            usort($data->items, function ($a, $b) {
                if (!isset($a->title) || !isset($b->title)) {
                    return 0;
                }
                return strnatcmp($a->title, $b->title);
            });
        }

        // Cache data
        CacheController::SetCache(ElementorCache::SECTIONS, $data);

        $data->premium = KeyController::HasValidPremiumKey();
        //
        return rest_ensure_response($data);
    }

    public function ElementorInsertCallback($request)
    {
        return $this->ElementorInsertHandler($request);
    }

    // ─── Gutenberg v2 (unified) ─────────────────────────────────────────

    public function GutenbergV2ListCallback()
    {
        try {
            $data = $this->FetchGutenbergLibraryData();

            // _retry response: another request is loading, JS should poll again
            if (isset($data->_retry) && $data->_retry) {
                return rest_ensure_response($data);
            }

            if (isset($data->patterns->items)) {
                $this->UpdatePatternRequirementStatus($data->patterns);
            }
            if (isset($data->pages->items)) {
                $this->UpdatePatternRequirementStatus($data->pages);
            }
            $data->premium = KeyController::HasValidPremiumKey();
            return rest_ensure_response($data);
        } catch (CacheException $cex) {
            return new \WP_Error('internal_error_cache', 'Internal Cache Error: ' . esc_html($cex->getMessage()), array('status' => 500));
        } catch (RequestException $rex) {
            return new \WP_Error('internal_error_request', 'Internal Request Error: ' . esc_html($rex->getMessage()), array('status' => $rex->getCode()));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    public function GutenbergV2InsertCallback($request)
    {
        try {
            $type = isset($request['type']) ? $request['type'] : '';
            if ($type !== self::GUTENBERG_TYPE_PATTERN && $type !== self::GUTENBERG_TYPE_PAGE) {
                return new \WP_Error('invalid_type', 'Invalid type parameter', array('status' => 400));
            }

            $data = self::GetInsertDataV2($request, $type);

            if (isset($data['access_failed'])) {
                return rest_ensure_response($data);
            }

            $data = GutenbergController::GutenbergDataImportAction($data);
            return rest_ensure_response(array("content" => $data['content'], "name" => esc_html(isset($data['title']) ? $data['title'] : '')));
        } catch (RequestException $rex) {
            return new \WP_Error('internal_error_request', 'Internal Request Error: ' . esc_html($rex->getMessage()), array('status' => $rex->getCode()));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    /**
     * Fetches unified Gutenberg library data from the v2 API.
     * Warm cache: returns immediately.
     * Cold cache: uses paginated proxy requests. Returns first page with _loading flag,
     * JS drives remaining chunk loading via the chunk endpoint.
     */
    private function FetchGutenbergLibraryData()
    {
        // 1. Warm cache — instant return
        $cache = CacheController::GetCache(GutenbergCache::LIBRARY, CacheTypes::GUTENBERG);
        if (!!$cache) {
            return $cache;
        }

        // 2. Check for resumable partial cache
        $partial = CacheController::GetDataCacheDirect(GutenbergCache::LIBRARY_PARTIAL);
        if ($partial !== false && isset($partial->_pagination)) {
            // Return partial data so JS can resume chunk loading
            $partial->_loading = true;
            return $partial;
        }

        // 3. Stampede prevention: check if another request is already loading
        $loading_lock = get_transient(ChunkLoading::LOADING_TRANSIENT);
        if ($loading_lock !== false) {
            // Check for stale lock: if partial cache exists but hasn't been updated in >60s, override
            $partial_raw = CacheController::GetDataCacheRaw(GutenbergCache::LIBRARY_PARTIAL);
            if ($partial_raw !== false && isset($partial_raw['last_update'])) {
                if (time() - $partial_raw['last_update'] > 60) {
                    // Stale lock — allow override
                    delete_transient(ChunkLoading::LOADING_TRANSIENT);
                } else {
                    // Another request is actively loading — serve partial if available
                    if (isset($partial_raw['data']) && is_object($partial_raw['data']) && isset($partial_raw['data']->_pagination)) {
                        $partial_raw['data']->_loading = true;
                        return $partial_raw['data'];
                    }
                    // No partial yet — tell JS to retry
                    $retry = new \stdClass();
                    $retry->_retry = true;
                    $retry->_retry_after = 3;
                    return $retry;
                }
            } else if ($loading_lock !== false) {
                // Lock exists but no partial cache yet — tell JS to retry
                $retry = new \stdClass();
                $retry->_retry = true;
                $retry->_retry_after = 3;
                return $retry;
            }
        }

        // 4. Set loading lock (2 min TTL)
        set_transient(ChunkLoading::LOADING_TRANSIENT, true, 120);

        // 5. Fetch page 1 from proxy
        $options_controller = new OptionController();
        $license_key = $options_controller->GetKey();

        $response = DomainShiftController::RemoteGet(self::GUTENBERG_V2_ENDPOINT . '?action=list&page=1&perPage=100&key=' . $license_key);
        if (!is_array($response) || is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            delete_transient(ChunkLoading::LOADING_TRANSIENT);
            throw new RequestException('Plugin Service Unavailable', 503);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (!is_object($data)) {
            delete_transient(ChunkLoading::LOADING_TRANSIENT);
            throw new RequestException('Invalid response from library service', 502);
        }
        if (isset($data->code) && isset($data->data) && isset($data->message)) {
            delete_transient(ChunkLoading::LOADING_TRANSIENT);
            $status = isset($data->data->status) ? $data->data->status : 500;
            throw new RequestException(esc_html($data->message), intval($status));
        }
        if (!isset($data->patterns) || !isset($data->pages)) {
            delete_transient(ChunkLoading::LOADING_TRANSIENT);
            throw new RequestException('Unexpected response format from library service', 502);
        }
        if (isset($data->level)) {
            KeyController::UpdateKeyType($data->level, $data->active, $data->expired, $data->exceeded);
        }

        // Sort categories and industries by sort_order
        if (isset($data->patterns->categories)) {
            self::SortByOrder($data->patterns->categories);
        }
        if (isset($data->pages->categories)) {
            self::SortByOrder($data->pages->categories);
        }
        if (isset($data->industries)) {
            self::SortByOrder($data->industries);
        }

        // Sort items alphabetically by name
        if (isset($data->patterns->items)) {
            self::SortItemsByName($data->patterns->items);
        }
        if (isset($data->pages->items)) {
            self::SortItemsByName($data->pages->items);
        }

        // Check if all data fits in page 1 (no more chunks needed)
        $pagination = isset($data->pagination) ? $data->pagination : null;
        $patterns_total = ($pagination && isset($pagination->patterns->totalPages)) ? $pagination->patterns->totalPages : 1;
        $pages_total = ($pagination && isset($pagination->pages->totalPages)) ? $pagination->pages->totalPages : 1;

        if ($patterns_total <= 1 && $pages_total <= 1) {
            // Everything fits in one page — cache as full and return without _loading
            CacheController::SetCache(GutenbergCache::LIBRARY, $data);
            delete_transient(ChunkLoading::LOADING_TRANSIENT);

            // Clean up legacy v1 caches
            CacheController::ClearCache(GutenbergCache::PATTERNS);
            CacheController::ClearCache(GutenbergCache::PAGES);

            return $data;
        }

        // Store pagination metadata and loaded pages tracking on the data object
        $data->_pagination = $pagination;
        $loaded_pages = new \stdClass();
        $loaded_pages->patterns = array(1);
        $loaded_pages->pages = array(1);
        $data->_pagination->loaded_pages = $loaded_pages;
        $data->_loading = true;

        // Store as partial cache
        CacheController::SetCache(GutenbergCache::LIBRARY_PARTIAL, $data);

        // Clean up legacy v1 caches
        CacheController::ClearCache(GutenbergCache::PATTERNS);
        CacheController::ClearCache(GutenbergCache::PAGES);

        return $data;
    }

    /**
     * Chunk endpoint: fetches subsequent pages of library data.
     */
    public function GutenbergV2ListChunkCallback($request)
    {
        try {
            $page = isset($request['page']) ? absint($request['page']) : 0;
            if ($page < 2) {
                return new \WP_Error('invalid_page', 'Page parameter must be >= 2', array('status' => 400));
            }

            // Rate limiting: prevent chunk request floods per user
            $user_id = get_current_user_id();
            $rate_key = 'spb_chunk_last_' . $user_id;
            $last_chunk_time = get_transient($rate_key);
            if ($last_chunk_time !== false && (microtime(true) - floatval($last_chunk_time)) < 0.5) {
                return new \WP_Error('too_many_requests', 'Please slow down', array('status' => 429));
            }
            set_transient($rate_key, microtime(true), 10);

            // Guard: if full cache is already warm, serve from it directly
            $full_cache = CacheController::GetCache(GutenbergCache::LIBRARY, CacheTypes::GUTENBERG);
            if (!!$full_cache) {
                $result = new \stdClass();
                $result->_complete = true;
                return rest_ensure_response($result);
            }

            $chunk = $this->FetchAndMergeChunk($page);
            return rest_ensure_response($chunk);
        } catch (CacheException $cex) {
            return new \WP_Error('internal_error_cache', 'Internal Cache Error: ' . esc_html($cex->getMessage()), array('status' => 500));
        } catch (RequestException $rex) {
            return new \WP_Error('internal_error_request', 'Internal Request Error: ' . esc_html($rex->getMessage()), array('status' => $rex->getCode()));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    /**
     * Warm-cache endpoint: drives sequential chunk loading until the full LIBRARY cache
     * is assembled. Each call fetches at most one chunk and returns a status snapshot
     * so a client interstitial can poll to completion.
     *
     * Response: { status: 'ready' | 'loading' | 'error', progress?: {...} }
     */
    public function GutenbergV2WarmCacheCallback($request)
    {
        try {
            // 1. Fast path — full cache warm
            $full_cache = CacheController::GetCache(GutenbergCache::LIBRARY, CacheTypes::GUTENBERG);
            if (!!$full_cache) {
                return rest_ensure_response(array('status' => 'ready'));
            }

            // 2. Cold cache — trigger page 1 load (handles stampede via LOADING_TRANSIENT)
            $partial = CacheController::GetDataCacheDirect(GutenbergCache::LIBRARY_PARTIAL);
            if ($partial === false || !isset($partial->_pagination)) {
                $data = $this->FetchGutenbergLibraryData();
                if (isset($data->_retry) && $data->_retry) {
                    return rest_ensure_response(array('status' => 'loading', 'progress' => null));
                }
                $full_cache = CacheController::GetCache(GutenbergCache::LIBRARY, CacheTypes::GUTENBERG);
                if (!!$full_cache) {
                    return rest_ensure_response(array('status' => 'ready'));
                }
                $partial = CacheController::GetDataCacheDirect(GutenbergCache::LIBRARY_PARTIAL);
                if ($partial === false || !isset($partial->_pagination)) {
                    return rest_ensure_response(array('status' => 'loading', 'progress' => null));
                }
            }

            // 3. Warm loop — fetch the next missing chunk
            $next_page = self::PickNextChunkPage($partial);
            if ($next_page !== null) {
                $this->FetchAndMergeChunk($next_page);
            }

            // 4. Re-read state after potential merge/promotion
            $full_cache = CacheController::GetCache(GutenbergCache::LIBRARY, CacheTypes::GUTENBERG);
            if (!!$full_cache) {
                return rest_ensure_response(array('status' => 'ready'));
            }
            $partial = CacheController::GetDataCacheDirect(GutenbergCache::LIBRARY_PARTIAL);
            return rest_ensure_response(array('status' => 'loading', 'progress' => self::BuildProgress($partial)));
        } catch (CacheException $cex) {
            return new \WP_Error('internal_error_cache', 'Internal Cache Error: ' . esc_html($cex->getMessage()), array('status' => 500));
        } catch (RequestException $rex) {
            return new \WP_Error('internal_error_request', 'Internal Request Error: ' . esc_html($rex->getMessage()), array('status' => $rex->getCode()));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }

    /**
     * Fetches a single page from the library proxy, merges items into the partial cache,
     * and promotes to full cache when all pages are loaded. Returns the chunk object
     * (with _complete set when promotion happens).
     */
    private function FetchAndMergeChunk($page)
    {
        // Race guard: if this page's needed sections are already loaded, skip the remote fetch.
        $partial_raw = CacheController::GetDataCacheRaw(GutenbergCache::LIBRARY_PARTIAL);
        if ($partial_raw !== false && isset($partial_raw['data']) && is_object($partial_raw['data']) && isset($partial_raw['data']->_pagination)) {
            $pag = $partial_raw['data']->_pagination;
            $patterns_total_pages = isset($pag->patterns->totalPages) ? intval($pag->patterns->totalPages) : 1;
            $pages_total_pages = isset($pag->pages->totalPages) ? intval($pag->pages->totalPages) : 1;
            $loaded_pattern_pages = (isset($pag->loaded_pages->patterns) && is_array($pag->loaded_pages->patterns)) ? $pag->loaded_pages->patterns : array();
            $loaded_page_pages = (isset($pag->loaded_pages->pages) && is_array($pag->loaded_pages->pages)) ? $pag->loaded_pages->pages : array();
            $needs_patterns = ($page <= $patterns_total_pages) && !in_array($page, $loaded_pattern_pages, true);
            $needs_pages = ($page <= $pages_total_pages) && !in_array($page, $loaded_page_pages, true);
            if (!$needs_patterns && !$needs_pages) {
                return new \stdClass();
            }
        }

        $options_controller = new OptionController();
        $license_key = $options_controller->GetKey();

        $response = DomainShiftController::RemoteGet(self::GUTENBERG_V2_ENDPOINT . '?action=list&page=' . $page . '&perPage=100&key=' . $license_key);
        if (!is_array($response) || is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new RequestException('Plugin Service Unavailable', 503);
        }

        $body = wp_remote_retrieve_body($response);
        $chunk = json_decode($body);

        if (!is_object($chunk)) {
            throw new RequestException('Invalid chunk response from library service', 502);
        }

        // Apply requirement status checks to chunk items
        if (isset($chunk->patterns->items)) {
            $this->UpdatePatternRequirementStatus($chunk->patterns);
        }
        if (isset($chunk->pages->items)) {
            $this->UpdatePatternRequirementStatus($chunk->pages);
        }

        // Re-read partial to get latest state after possible concurrent merge
        $partial_raw = CacheController::GetDataCacheRaw(GutenbergCache::LIBRARY_PARTIAL);
        if ($partial_raw !== false && isset($partial_raw['data']) && is_object($partial_raw['data'])) {
            $partial = $partial_raw['data'];

            // Merge pattern items (guarded against double-append if another request beat us here)
            if (isset($chunk->patterns->items) && is_array($chunk->patterns->items)) {
                if (!isset($partial->patterns->items) || !is_array($partial->patterns->items)) {
                    $partial->patterns->items = array();
                }
                $already_loaded = isset($partial->_pagination->loaded_pages->patterns) && in_array($page, $partial->_pagination->loaded_pages->patterns, true);
                if (!$already_loaded) {
                    $partial->patterns->items = array_merge($partial->patterns->items, $chunk->patterns->items);
                    if (isset($partial->_pagination->loaded_pages->patterns)) {
                        $partial->_pagination->loaded_pages->patterns[] = $page;
                    }
                }
            }

            // Merge page items
            if (isset($chunk->pages->items) && is_array($chunk->pages->items)) {
                if (!isset($partial->pages->items) || !is_array($partial->pages->items)) {
                    $partial->pages->items = array();
                }
                $already_loaded = isset($partial->_pagination->loaded_pages->pages) && in_array($page, $partial->_pagination->loaded_pages->pages, true);
                if (!$already_loaded) {
                    $partial->pages->items = array_merge($partial->pages->items, $chunk->pages->items);
                    if (isset($partial->_pagination->loaded_pages->pages)) {
                        $partial->_pagination->loaded_pages->pages[] = $page;
                    }
                }
            }

            // Check if all pages are now loaded
            $patterns_total = isset($partial->_pagination->patterns->totalPages) ? $partial->_pagination->patterns->totalPages : 1;
            $pages_total = isset($partial->_pagination->pages->totalPages) ? $partial->_pagination->pages->totalPages : 1;
            $patterns_loaded = isset($partial->_pagination->loaded_pages->patterns) ? count($partial->_pagination->loaded_pages->patterns) : 0;
            $pages_loaded = isset($partial->_pagination->loaded_pages->pages) ? count($partial->_pagination->loaded_pages->pages) : 0;

            $all_complete = ($patterns_loaded >= $patterns_total) && ($pages_loaded >= $pages_total);

            if ($all_complete) {
                // Sort assembled data before promoting to full cache
                if (isset($partial->patterns->items)) {
                    self::SortItemsByName($partial->patterns->items);
                }
                if (isset($partial->pages->items)) {
                    self::SortItemsByName($partial->pages->items);
                }
                if (isset($partial->patterns->categories)) {
                    self::SortByOrder($partial->patterns->categories);
                }
                if (isset($partial->pages->categories)) {
                    self::SortByOrder($partial->pages->categories);
                }
                if (isset($partial->industries)) {
                    self::SortByOrder($partial->industries);
                }

                // Remove loading metadata before promoting
                unset($partial->_pagination);
                unset($partial->_loading);

                // Promote partial -> full cache
                CacheController::SetCache(GutenbergCache::LIBRARY, $partial);
                CacheController::ClearCache(GutenbergCache::LIBRARY_PARTIAL);
                delete_transient(ChunkLoading::LOADING_TRANSIENT);

                $chunk->_complete = true;
            } else {
                // Update partial cache with merged data
                CacheController::SetCache(GutenbergCache::LIBRARY_PARTIAL, $partial);
            }
        }

        return $chunk;
    }

    /**
     * Picks the next page number needed from the partial cache pagination metadata.
     * Mirrors the library JS chunk loader's selection logic. Returns null when done.
     */
    private static function PickNextChunkPage($partial)
    {
        if (!isset($partial->_pagination)) {
            return null;
        }
        $pag = $partial->_pagination;
        $patterns_total_pages = isset($pag->patterns->totalPages) ? intval($pag->patterns->totalPages) : 1;
        $pages_total_pages = isset($pag->pages->totalPages) ? intval($pag->pages->totalPages) : 1;
        $max_pages = max($patterns_total_pages, $pages_total_pages);

        $loaded_pattern_pages = (isset($pag->loaded_pages->patterns) && is_array($pag->loaded_pages->patterns)) ? $pag->loaded_pages->patterns : array(1);
        $loaded_page_pages = (isset($pag->loaded_pages->pages) && is_array($pag->loaded_pages->pages)) ? $pag->loaded_pages->pages : array(1);

        for ($p = 2; $p <= $max_pages; $p++) {
            $needs_patterns = ($p <= $patterns_total_pages) && !in_array($p, $loaded_pattern_pages, true);
            $needs_pages = ($p <= $pages_total_pages) && !in_array($p, $loaded_page_pages, true);
            if ($needs_patterns || $needs_pages) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Builds the progress payload returned by the warm-cache endpoint.
     */
    private static function BuildProgress($partial)
    {
        if (!is_object($partial) || !isset($partial->_pagination)) {
            return null;
        }
        $pag = $partial->_pagination;
        return array(
            'patterns' => array(
                'loaded' => isset($pag->loaded_pages->patterns) ? count($pag->loaded_pages->patterns) : 0,
                'total' => isset($pag->patterns->totalPages) ? intval($pag->patterns->totalPages) : 1,
            ),
            'pages' => array(
                'loaded' => isset($pag->loaded_pages->pages) ? count($pag->loaded_pages->pages) : 0,
                'total' => isset($pag->pages->totalPages) ? intval($pag->pages->totalPages) : 1,
            ),
        );
    }

    /**
     * Sort array of objects by sort_order, then remove the sort_order property.
     */
    private static function SortByOrder(&$items)
    {
        if (!is_array($items) || empty($items)) {
            return;
        }
        usort($items, function ($a, $b) {
            $a_order = isset($a->sort_order) ? $a->sort_order : 0;
            $b_order = isset($b->sort_order) ? $b->sort_order : 0;
            return $a_order - $b_order;
        });
        foreach ($items as $item) {
            if (isset($item->sort_order)) {
                unset($item->sort_order);
            }
        }
    }

    private static function SortItemsByName(&$items)
    {
        if (!is_array($items) || empty($items)) {
            return;
        }
        usort($items, function ($a, $b) {
            if (!isset($a->name) || !isset($b->name)) {
                return 0;
            }
            return strnatcmp($a->name, $b->name);
        });
    }

    /**
     * v2 insert: calls the unified v2 endpoint with a type parameter.
     */
    public static function GetInsertDataV2($request, $type)
    {
        $options_controller = new OptionController();
        $license_key = $options_controller->GetKey();
        if (!isset($request['id']) || !isset($request['package']) || $request['package'] === "premium" && !$license_key) {
            throw new RequestException("Forbidden", 403);
        }

        $stamp = $options_controller->GetStamp();
        $package = $request['package'] === 'premium' ? 'premium' : 'free';
        $id = urlencode(sanitize_text_field($request['id']));
        $response = DomainShiftController::RemoteGet(self::GUTENBERG_V2_ENDPOINT . '?action=insert&type=' . $type . '&id=' . $id . '&package=' . $package . '&key=' . urlencode($license_key) . '&stamp=' . absint($stamp));
        ///
        if (!is_array($response) || is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            throw new RequestException('Plugin Service Unavailable', 503);
        }
        ///
        $data = json_decode($response['body'], true);
        if (isset($data['code']) && isset($data['data']) && isset($data['message'])) {
            $status = isset($data['data']['status']) ? $data['data']['status'] : 500;
            throw new RequestException(esc_html($data['message']), intval($status));
        }
        if (isset($data['level'])) {
            KeyController::UpdateKeyType($data['level'], $data['active'], $data['expired'], $data['exceeded']);
        }
        if (!isset($data['verified']) || !$data['verified']) {
            KeyController::VerificationFailed();
        }

        $data['premium'] = KeyController::HasValidPremiumKey();

        return $data;
    }

    // ─── Shared ─────────────────────────────────────────────────────────

    private function UpdatePatternRequirementStatus(&$data)
    {
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        // Check plugin and library version requirements
        foreach ($data->items as $item) {
            $item->external_plugin_required = false;
            $item->plugin_update_required = false;
            if (isset($item->required_plugins)) {
                foreach ($item->required_plugins as $required_plugin) {
                    if (!is_plugin_active($required_plugin)) {
                        $item->external_plugin_required = true;
                        $item->required_plugin_names = isset($item->required_plugin_names) ? $item->required_plugin_names : array();
                        if (isset(self::PLUGIN_NAMES[$required_plugin])) {
                            $item->required_plugin_names[] = self::PLUGIN_NAMES[$required_plugin];
                        } else {
                            $item->required_plugin_names[] = esc_attr(explode('/', $required_plugin)[0]);
                        }
                    }
                }
            }
            if (isset($item->required_library) && version_compare($item->required_library, SUPERBADDONS_LIBRARY_VERSION, '>')) {
                $item->plugin_update_required = true;
            }
        }
    }

    private function ElementorInsertHandler($request)
    {
        try {
            $options_controller = new OptionController();
            $license_key = $options_controller->GetKey();
            if (!isset($request['id']) || !isset($request['package']) || $request['package'] === "premium" && !$license_key) {
                throw new RequestException("Forbidden", 403);
            }

            $stamp = $options_controller->GetStamp();
            $collection = $request['package'] === 'premium' ? "premium" : "free";
            $id = urlencode(sanitize_text_field($request['id']));
            $response = DomainShiftController::RemoteGet(self::ELEMENTOR_ENDPOINT_BASE . 'sections?action=insert&id=' . $id . '&collection=' . $collection . '&key=' . urlencode($license_key) . '&stamp=' . absint($stamp));
            if (!is_array($response) || is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                throw new RequestException('Plugin Service Unavailable', 503);
            }

            $data = json_decode($response['body'], true);
            if (isset($data['code']) && isset($data['data']) && isset($data['message'])) {
                $status = isset($data['data']['status']) ? $data['data']['status'] : 500;
                throw new RequestException(esc_html($data['message']), intval($status));
            }
            if (isset($data['level'])) {
                KeyController::UpdateKeyType($data['level'], $data['active'], $data['expired'], $data['exceeded']);
            }
            if (!isset($data['verified']) || !$data['verified']) {
                KeyController::VerificationFailed();
            }

            $data['premium'] = KeyController::HasValidPremiumKey();

            if (isset($data['access_failed'])) {
                return rest_ensure_response($data);
            }

            $data = ElementorController::ElementorDataImportAction($data);
            return rest_ensure_response($data['content']);
        } catch (RequestException $rex) {
            return new \WP_Error('internal_error_request', 'Internal Request Error: ' .  esc_html($rex->getMessage()), array('status' => $rex->getCode()));
        } catch (Exception $ex) {
            LogController::HandleException($ex);
            return new \WP_Error('internal_error_plugin', 'Internal Plugin Error', array('status' => 500));
        }
    }
}
