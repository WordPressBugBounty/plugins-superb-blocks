<?php

namespace SuperbAddons\Data\Controllers;

use SuperbAddons\Data\Utils\CacheException;

use SuperbAddons\Data\Utils\CacheTypes;
use SuperbAddons\Data\Utils\CacheOptions;
use SuperbAddons\Data\Utils\ElementorCache;
use SuperbAddons\Data\Utils\ChunkLoading;
use SuperbAddons\Data\Utils\GutenbergCache;

defined('ABSPATH') || exit();

class CacheController
{
    // Set when a service status check fails, so the next check waits until it expires
    const SERVICE_OFFLINE_TRANSIENT = 'spba_service_offline';
    const SERVICE_OFFLINE_RETRY = HOUR_IN_SECONDS;
    // Shorter retry delay when there is no local data cache to serve
    const SERVICE_OFFLINE_RETRY_NO_DATA = 5 * MINUTE_IN_SECONDS;
    const SERVICE_UNAVAILABLE_CODE = 503;

    public static function GetCache($cache_option, $cache_type)
    {
        try {
            if (self::IsDataCacheOutdated($cache_type, $cache_option)) {
                // Return false to indicate that the data cache is outdated and should not be fetched locally
                return false;
            }
        } catch (CacheException $cex) {
            if ($cex->getCode() !== self::SERVICE_UNAVAILABLE_CODE) {
                throw $cex;
            }

            // Service unavailable -> Serve local data cache if it has not expired
            $data_cache = self::GetDataCache($cache_option);
            if ($data_cache === false) {
                throw $cex;
            }
            return $data_cache;
        }

        return self::GetDataCache($cache_option);
    }

    private static function IsDataCacheOutdated($cache_type, $cache_option)
    {
        // Check cache version and update if needed
        $option_string = self::GetCacheOptionString(CacheOptions::SERVICE_VERSION);

        $option_controller = new OptionController();
        $service_info_cache = $option_controller->GetCache($option_string);
        $cache_invalid = !$service_info_cache || !isset($service_info_cache['last_update']) || !isset($service_info_cache['data']);

        if ($cache_invalid || time() - $service_info_cache['last_update'] > 86400) {
            // Recent status check failed -> Skip the check until the retry delay has passed
            $offline_message = get_transient(self::SERVICE_OFFLINE_TRANSIENT);
            if ($offline_message !== false) {
                throw new CacheException(esc_html($offline_message), true, (int) self::SERVICE_UNAVAILABLE_CODE);
            }

            // Cache expired or invalid -> Update service info cache
            $service_status = DomainShiftController::GetServiceStatus();
            if (!$service_status['online']) {
                if (empty($service_status['unavailable'])) {
                    // Not a connection problem -> Report it and check again on the next request
                    throw new CacheException(esc_html($service_status['message']));
                }

                $retry = self::GetDataCache($cache_option) === false ? self::SERVICE_OFFLINE_RETRY_NO_DATA : self::SERVICE_OFFLINE_RETRY;
                set_transient(self::SERVICE_OFFLINE_TRANSIENT, $service_status['message'], $retry);
                throw new CacheException(esc_html($service_status['message']), false, (int) self::SERVICE_UNAVAILABLE_CODE);
            }

            self::SetCache(CacheOptions::SERVICE_VERSION, $service_status);

            if ($cache_invalid) {
                // Cache invalid -> Data cache should be updated
                return true;
            }

            switch ($cache_type) {
                case CacheTypes::ELEMENTOR:
                case CacheTypes::GUTENBERG:
                    if ($service_status[$cache_type] !== $service_info_cache['data'][$cache_type]) {
                        // Cache outdated -> Data cache should be updated
                        return true;
                    }
                    break;
                default:
                    throw new CacheException(esc_html(__("Invalid cache type:", "superb-blocks") . " " . $cache_type));
            }
        }

        // Local data cache accepted or is up to date
        return false;
    }

    private static function GetDataCache($cache_option)
    {
        $option_string = self::GetCacheOptionString($cache_option);

        $option_controller = new OptionController();
        $cache = $option_controller->GetCache($option_string);
        if (!$cache || !isset($cache['last_update']) || !isset($cache['data']) || time() - $cache['last_update'] > 7 * 86400) {
            // Cache expired
            return false;
        }

        return $cache['data'];
    }

    /**
     * Read cache data directly (with 24h TTL check) without the service-version outdated check.
     * Used for partial cache reads where we don't want to trigger a service version refresh.
     */
    public static function GetDataCacheDirect($cache_option)
    {
        return self::GetDataCache($cache_option);
    }

    /**
     * Read the raw cache entry (including last_update timestamp).
     * Returns the full array with 'last_update' and 'data' keys, or false.
     */
    public static function GetDataCacheRaw($cache_option)
    {
        $option_string = self::GetCacheOptionString($cache_option);

        $option_controller = new OptionController();
        $cache = $option_controller->GetCache($option_string);
        if (!$cache || !isset($cache['last_update']) || !isset($cache['data'])) {
            return false;
        }

        return $cache;
    }

    public static function SetCache($cache_option, $data)
    {
        $option_string = self::GetCacheOptionString($cache_option);

        $option_controller = new OptionController();
        return $option_controller->SetCache($option_string, $data);
    }

    public static function ClearCache($cache_option)
    {
        if ($cache_option === CacheOptions::SERVICE_VERSION) {
            // Allow a new status check right after a manual cache reset
            delete_transient(self::SERVICE_OFFLINE_TRANSIENT);
        }

        $option_string = self::GetCacheOptionString($cache_option);

        $option_controller = new OptionController();
        return $option_controller->ClearCache($option_string);
    }

    public static function ClearCacheAll()
    {
        // Clear the loading lock and status retry transients so a stuck lock or retry delay is always cleared on cache reset
        delete_transient(ChunkLoading::LOADING_TRANSIENT);
        delete_transient(self::SERVICE_OFFLINE_TRANSIENT);

        $option_controller = new OptionController();
        return $option_controller->ClearCache(self::GetCacheOptionString(CacheOptions::SERVICE_VERSION))
            && $option_controller->ClearCache(self::GetCacheOptionString(ElementorCache::SECTIONS))
            && $option_controller->ClearCache(self::GetCacheOptionString(GutenbergCache::LIBRARY))
            && $option_controller->ClearCache(self::GetCacheOptionString(GutenbergCache::LIBRARY_PARTIAL))
            // Legacy v1 keys - clean up leftover data from users updating from older versions
            && $option_controller->ClearCache(self::GetCacheOptionString(GutenbergCache::PATTERNS))
            && $option_controller->ClearCache(self::GetCacheOptionString(GutenbergCache::PAGES));
    }

    private static function GetCacheOptionString($cache_option)
    {
        switch ($cache_option) {
            case CacheOptions::SERVICE_VERSION:
                return Option::PREFIX . CacheOptions::SERVICE_VERSION;
            case ElementorCache::SECTIONS:
                return Option::PREFIX . ElementorCache::SECTIONS;
            case GutenbergCache::LIBRARY:
                return Option::PREFIX . GutenbergCache::LIBRARY;
            case GutenbergCache::LIBRARY_PARTIAL:
                return Option::PREFIX . GutenbergCache::LIBRARY_PARTIAL;
                // Legacy v1 keys - kept for cleanup on update
            case GutenbergCache::PATTERNS:
                return Option::PREFIX . GutenbergCache::PATTERNS;
            case GutenbergCache::PAGES:
                return Option::PREFIX . GutenbergCache::PAGES;
            default:
                throw new CacheException(esc_html(__("Invalid cache option:", "superb-blocks") . " " . $cache_option));
        }
    }
}
