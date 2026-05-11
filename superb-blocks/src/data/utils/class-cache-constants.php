<?php

namespace SuperbAddons\Data\Utils;

defined('ABSPATH') || exit();

class CacheTypes
{
    const ELEMENTOR = 'elementor';
    const GUTENBERG = 'gutenberg';
}

class CacheOptions
{
    const SERVICE_VERSION = 'service_version';
}

class ElementorCache
{
    const SECTIONS = 'elementor_section_cache';
}

class GutenbergCache
{
    const LIBRARY = 'gutenberg_library_cache';
    const LIBRARY_PARTIAL = 'gutenberg_library_partial_cache';

    // Legacy (v1) - kept so ClearCacheAll can remove leftover data after plugin update
    const PATTERNS = 'gutenberg_pattern_cache';
    const PAGES = 'gutenberg_page_cache';
}

class ChunkLoading
{
    const LOADING_TRANSIENT = 'spba_library_loading';
}
