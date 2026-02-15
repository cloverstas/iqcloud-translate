<?php
/**
 * URL Rewriter for language prefixes
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_URL_Rewriter {
    
    private $languages;
    private $default_language;
    private $settings;
    
    public function __construct($settings = array()) {
        $this->settings = $settings;
        $this->default_language = get_option('lingua_default_language', 'ru');
        $this->languages = get_option('lingua_languages', array());
        $this->apply_fallback_languages();
    }
    
    /**
     * Refresh language data - called early in init
     */
    public function refresh_languages() {
        $this->default_language = get_option('lingua_default_language', 'ru');
        $this->languages = get_option('lingua_languages', array());
        $this->apply_fallback_languages();
        
        lingua_debug_log('Lingua: refresh_languages() - default: ' . $this->default_language);
        lingua_debug_log('Lingua: refresh_languages() - languages: ' . var_export(array_keys($this->languages), true));
    }
    
    /**
     * Apply fallback languages if none configured (same as nav-menu-integration)
     */
    private function apply_fallback_languages() {
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] apply_fallback_languages called');
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] Current languages count: ' . count($this->languages));
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] Default language: ' . $this->default_language);
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] Condition check: empty=' . (empty($this->languages) ? 'true' : 'false') . ', default_exists=' . (isset($this->languages[$this->default_language]) ? 'true' : 'false'));

        if (empty($this->languages) || !isset($this->languages[$this->default_language])) {
            lingua_debug_log('[LINGUA v5.2.12 DEBUG] ✅ Adding fallback languages...');
            // v5.2.128: Use centralized language list
            $available_languages = Lingua_Languages::get_all();
            
            // Add default language if missing
            if (!isset($this->languages[$this->default_language])) {
                $this->languages[$this->default_language] = $available_languages[$this->default_language];
            }
            
            // v5.2.12: FIX - Add ALL available languages to fallback list (not just en,de,fr)
            // This ensures language detection works for ALL language URLs (e.g., /zh/, /ja/, /ko/)
            foreach ($available_languages as $lang_code => $lang_data) {
                if (!isset($this->languages[$lang_code])) {
                    $this->languages[$lang_code] = $lang_data;
                }
            }
            
            lingua_debug_log('Lingua URL Rewriter: Added fallback languages: ' . implode(', ', array_keys($this->languages)));
        }
    }
    
    /**
     * Get current language
     *
     * This is the SINGLE source of truth for current language.
     * Called from Lingua constructor to set $LINGUA_LANGUAGE globally.
     *
     * @return string Language code
     */
    public function get_current_language() {
        $language_from_url = $this->get_lang_from_url_string();

        $needed_language = $this->determine_needed_language($language_from_url);

        lingua_debug_log('[LINGUA v4.0] get_current_language() -> ' . $needed_language . ' (from URL: ' . ($language_from_url ?? 'null') . ')');

        return $needed_language;
    }

    /**
     * Determine needed language based on URL and settings
     *
     * @param string|null $lang_from_url Language detected from URL
     * @return string Needed language code
     */
    private function determine_needed_language($lang_from_url) {
        if ($lang_from_url == null) {
            // No language in URL - use default
            $needed_language = $this->default_language;
        } else {
            // Language found in URL - use it
            $needed_language = $lang_from_url;
        }

        // Allow filtering (for automatic language detection addons, etc)
        return apply_filters('lingua_needed_language', $needed_language, $lang_from_url, $this->settings);
    }

    /**
     * Initialize URL rewriting with improved architecture
     */
    public function init() {
        lingua_debug_log('Lingua URL Rewriter: Initializing hooks');

        // NOTE: Language detection is now done in Lingua constructor!
        // No need for detect_current_language() hooks anymore.
        
        // Main URL filter with high priority
        add_filter('home_url', array($this, 'add_language_to_home_url'), 1, 4);
        
        // Add filters for all WordPress URL types
        add_filter('post_link', array($this, 'add_language_to_url'), 1, 2);
        add_filter('page_link', array($this, 'add_language_to_url'), 1, 2);
        add_filter('post_type_link', array($this, 'add_language_to_url'), 1, 2);
        add_filter('term_link', array($this, 'add_language_to_url'), 1, 3);
        add_filter('author_link', array($this, 'add_language_to_url'), 1, 2);
        add_filter('day_link', array($this, 'add_language_to_url'), 1, 4);
        add_filter('month_link', array($this, 'add_language_to_url'), 1, 3);
        add_filter('year_link', array($this, 'add_language_to_url'), 1, 2);
        add_filter('attachment_link', array($this, 'add_language_to_url'), 1, 2);

        // v3.7.3: Add filter for all URLs in content (catches hardcoded links)
        add_filter('the_content', array($this, 'add_language_to_content_urls'), 999);

        // Add rewrite rules for proper URL handling
        add_action('init', array($this, 'add_rewrite_rules'), 10);
        add_filter('query_vars', array($this, 'add_query_vars'));
    }
    
    /**
     * Add language code as subdirectory after home url
     * Hooked to home_url
     */
    public function add_language_to_home_url($url, $path, $orig_scheme, $blog_id) {
        global $LINGUA_LANGUAGE;

        // v5.3.3: Removed hot-path logging (was called 100+ times per page)

        // If language not set, don't modify URL
        if (empty($LINGUA_LANGUAGE)) {
            return $url;
        }
        
        // Skip language prefix for default language
        if ($LINGUA_LANGUAGE == $this->default_language) {
            return $url;
        }
        
        // Skip admin requests
        if (is_admin() && !wp_doing_ajax()) {
            return $url;
        }
        
        lingua_debug_log('Lingua: Adding language prefix /' . $LINGUA_LANGUAGE . '/ to URL');
        
        // Get home URL
        $abs_home = trailingslashit(get_option('home'));
        
        // Add language slug
        $new_url = trailingslashit($abs_home . $LINGUA_LANGUAGE);
        
        if (!empty($path)) {
            $new_url = trailingslashit($new_url) . ltrim($path, '/');
        }
        
        lingua_debug_log('Lingua: Final URL: ' . $new_url);
        
        return $new_url;
    }
    
    /**
     * Add language code to any URL
     */
    public function add_language_to_url($url) {
        global $LINGUA_LANGUAGE;
        
        // Skip if no language or default language
        if (empty($LINGUA_LANGUAGE) || $LINGUA_LANGUAGE == $this->default_language) {
            return $url;
        }
        
        // Skip admin URLs
        if (strpos($url, admin_url()) === 0) {
            return $url;
        }
        
        // Parse URL
        $parsed = wp_parse_url($url);
        $home_parsed = wp_parse_url(home_url());
        
        // Check if URL already has language prefix
        if (isset($parsed['path']) && strpos($parsed['path'], '/' . $LINGUA_LANGUAGE . '/') === 0) {
            return $url;
        }
        
        // Build new URL with language prefix
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] . '://' : '';
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        
        // Remove home path if exists
        if (isset($home_parsed['path']) && $home_parsed['path'] !== '/') {
            $path = str_replace($home_parsed['path'], '', $path);
        }
        
        // Add language prefix
        $path = '/' . $LINGUA_LANGUAGE . $path;
        
        // Re-add home path if needed
        if (isset($home_parsed['path']) && $home_parsed['path'] !== '/') {
            $path = $home_parsed['path'] . $path;
        }
        
        return $scheme . $host . $port . $path . $query . $fragment;
    }
    
    /**
     * Detect current language early in the process
     */
    public function detect_current_language() {
        global $LINGUA_LANGUAGE;

        lingua_debug_log('Lingua: detect_current_language called');

        // v3.7.4: CRITICAL FIX - Always re-detect from URL to avoid stale cached value
        // This prevents using cached $LINGUA_LANGUAGE from previous request
        // Only skip if we're in the same request and language is already detected
        static $detected_in_this_request = false;

        if ($detected_in_this_request && !empty($LINGUA_LANGUAGE)) {
            lingua_debug_log('Lingua: Language already detected in this request: ' . $LINGUA_LANGUAGE);
            return;
        }
        
        // Check for AJAX requests
        if (wp_doing_ajax()) {
            $this->detect_language_in_ajax();
            return;
        }
        
        // Get language from URL
        $LINGUA_LANGUAGE = $this->get_lang_from_url_string();
        
        // Try query var as fallback
        if (empty($LINGUA_LANGUAGE)) {
            $lang = get_query_var('lang');
            if (!empty($lang) && isset($this->languages[$lang])) {
                $LINGUA_LANGUAGE = $lang;
            }
        }
        
        if (empty($LINGUA_LANGUAGE)) {
            $LINGUA_LANGUAGE = $this->default_language;
        }
        
        lingua_debug_log('Lingua: Detected language: ' . $LINGUA_LANGUAGE);
        
        // CRITICAL: Synchronize with LINGUA_CURRENT_LANG constant for SEO compatibility
        if (!defined('LINGUA_CURRENT_LANG')) {
            define('LINGUA_CURRENT_LANG', $LINGUA_LANGUAGE);
            lingua_debug_log('Lingua: Synchronized LINGUA_CURRENT_LANG = ' . $LINGUA_LANGUAGE);
        }
        
        // Set locale if needed
        if ($LINGUA_LANGUAGE !== $this->default_language) {
            $locale = $this->get_locale_for_language($LINGUA_LANGUAGE);
            if ($locale) {
                switch_to_locale($locale);
                lingua_debug_log('Lingua: Switched to locale: ' . $locale);
            }
        }

        // v3.7.4: Mark that language has been detected in this request
        $detected_in_this_request = true;
    }
    
    /**
     * Detect language in AJAX requests
     */
    private function detect_language_in_ajax() {
        global $LINGUA_LANGUAGE;

        // v3.7.4: Check if already detected in this request
        static $detected_in_this_request = false;
        if ($detected_in_this_request && !empty($LINGUA_LANGUAGE)) {
            return;
        }

        // Check referer
        $referer = wp_get_referer();
        if ($referer) {
            $LINGUA_LANGUAGE = $this->get_lang_from_url_string($referer);
        }

        // Fallback to default
        if (empty($LINGUA_LANGUAGE)) {
            $LINGUA_LANGUAGE = $this->default_language;
        }

        lingua_debug_log('Lingua: AJAX language detected: ' . $LINGUA_LANGUAGE);

        // CRITICAL: Synchronize with LINGUA_CURRENT_LANG constant for SEO compatibility
        if (!defined('LINGUA_CURRENT_LANG')) {
            define('LINGUA_CURRENT_LANG', $LINGUA_LANGUAGE);
            lingua_debug_log('Lingua: AJAX synchronized LINGUA_CURRENT_LANG = ' . $LINGUA_LANGUAGE);
        }

        // v3.7.4: Mark as detected
        $detected_in_this_request = true;
    }
    
    /**
     * Get language code from URL
     */
    public function get_lang_from_url_string($url = null) {
        if (!$url) {
            // CRITICAL FIX v2.0.4: Better URL detection
            $url = $_SERVER['REQUEST_URI'] ?? '/';

            // Fallback: try current page URL
            if (empty($url) || $url === '/') {
                global $wp;
                if (isset($wp->request)) {
                    $url = '/' . $wp->request;
                } else {
                    // Last resort: parse current URL
                    $current_url = home_url($_SERVER['REQUEST_URI'] ?? '/');
                    $parsed = wp_parse_url($current_url);
                    $url = $parsed['path'] ?? '/';
                }
            }
        }

        lingua_debug_log('[LINGUA v5.2.12 DEBUG] ========== get_lang_from_url_string START ==========');
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] Input URL: ' . $url);
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] $_SERVER[REQUEST_URI]: ' . ($_SERVER['REQUEST_URI'] ?? 'not set'));

        // Parse URL path
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$path) $path = '/';

        // Remove home path
        $home_path = wp_parse_url(get_option('home'), PHP_URL_PATH);
        if ($home_path && $home_path !== '/') {
            $path = str_replace($home_path, '', $path);
        }

        // Clean path
        $path = '/' . ltrim($path, '/');
        
        // Extract first segment as potential language
        $segments = explode('/', trim($path, '/'));
        $first_segment = $segments[0] ?? '';

        lingua_debug_log('[LINGUA v5.2.12 DEBUG] Cleaned path: ' . $path);
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] Segments array: ' . json_encode($segments));
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] First segment: "' . $first_segment . '"');
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] Available languages: ' . json_encode(array_keys($this->languages)));
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] Default language: ' . $this->default_language);
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] Check isset($this->languages["' . $first_segment . '"]): ' . (isset($this->languages[$first_segment]) ? 'TRUE' : 'FALSE'));

        // Check if first segment is a valid language
        if (!empty($first_segment) && isset($this->languages[$first_segment])) {
            lingua_debug_log('[LINGUA v5.2.12 DEBUG] ✅ Found language in URL: ' . $first_segment);
            lingua_debug_log('[LINGUA v5.2.12 DEBUG] ========== get_lang_from_url_string END ==========');
            return $first_segment;
        }

        lingua_debug_log('[LINGUA v5.2.12 DEBUG] ❌ No language found in URL (segment: "' . $first_segment . '"), returning null');
        lingua_debug_log('[LINGUA v5.2.12 DEBUG] ========== get_lang_from_url_string END ==========');
        return null;
    }
    
    /**
     * Add rewrite rules for language prefixes
     */
    public function add_rewrite_rules() {
        $all_languages = array_keys($this->languages);
        
        // Only create rules for non-default languages
        $languages = array_filter($all_languages, function($lang) {
            return $lang !== $this->default_language;
        });
        
        lingua_debug_log('Lingua: add_rewrite_rules() called');
        lingua_debug_log('Lingua: languages for rewrite = ' . var_export($languages, true));
        
        if (empty($languages)) {
            lingua_debug_log('Lingua: no non-default languages configured');
            return;
        }
        
        $lang_regex = '(' . implode('|', $languages) . ')';
        
        // Front page with language
        add_rewrite_rule("^{$lang_regex}/?$", 'index.php?lang=$matches[1]', 'top');
        
        // Pages with language - more specific pattern
        add_rewrite_rule("^{$lang_regex}/(.+?)/?$", 'index.php?pagename=$matches[2]&lang=$matches[1]', 'top');
        
        // Flush rewrite rules if needed
        $rules_version = get_option('lingua_rewrite_rules_version', 0);
        if ($rules_version < 2) {
            flush_rewrite_rules();
            update_option('lingua_rewrite_rules_version', 2);
            lingua_debug_log('Lingua: Flushed rewrite rules');
        }
    }
    
    /**
     * Flush rewrite rules if needed
     */
    private function maybe_flush_rewrite_rules() {
        $flush_needed = get_option('lingua_flush_rewrite_rules');
        if ($flush_needed) {
            flush_rewrite_rules();
            delete_option('lingua_flush_rewrite_rules');
        }
    }
    
    /**
     * Mark that rewrite rules need to be flushed
     */
    public function mark_for_flush() {
        update_option('lingua_flush_rewrite_rules', true);
    }
    
    /**
     * Add language query var - FIXED
     */
    public function add_query_vars($vars) {
        if (!in_array('lang', $vars)) {
            $vars[] = 'lang';
            lingua_debug_log('Lingua: Added lang to query_vars: ' . implode(', ', $vars));
        }
        return $vars;
    }
    
    /**
     * OLD detect_language method - DEPRECATED
     */
    public function detect_language_old() {
        // Сначала проверим query_var
        $lang = get_query_var('lang');
        
        // Debug logging
        lingua_debug_log('Lingua: detect_language() called');
        lingua_debug_log('Lingua: query_var lang = ' . var_export($lang, true));
        lingua_debug_log('Lingua: available languages = ' . var_export(array_keys($this->languages), true));
        lingua_debug_log('Lingua: REQUEST_URI = ' . ($_SERVER['REQUEST_URI'] ?? 'empty'));
        
        // КРИТИЧНО: также проверим глобальный wp_query
        global $wp_query;
        if (empty($lang) && isset($wp_query->query_vars['lang'])) {
            $lang = $wp_query->query_vars['lang'];
            lingua_debug_log('Lingua: Found lang in wp_query: ' . $lang);
        }
        
        // Enhanced fallback: parse URL directly 
        if (empty($lang)) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            
            // Remove any home path from the URI
            $parsed_home = wp_parse_url(home_url());
            $home_path = isset($parsed_home['path']) ? trim($parsed_home['path'], '/') : '';
            
            if (!empty($home_path) && strpos($request_uri, '/' . $home_path) === 0) {
                $request_uri = substr($request_uri, strlen('/' . $home_path));
            }
            
            // Ensure URI starts with slash
            $request_uri = '/' . ltrim($request_uri, '/');
            
            $lang_keys = array_keys($this->languages);
            if (!empty($lang_keys)) {
                // Create pattern to match language at start of path
                $non_default_langs = array_filter($lang_keys, function($code) {
                    return $code !== $this->default_language;
                });
                
                if (!empty($non_default_langs)) {
                    $pattern = '#^/(' . implode('|', array_map('preg_quote', $non_default_langs)) . ')(/|$)#';
                    lingua_debug_log('Lingua: regex pattern = ' . $pattern);
                    lingua_debug_log('Lingua: testing against URI = ' . $request_uri);
                    
                    if (preg_match($pattern, $request_uri, $matches)) {
                        $lang = $matches[1];
                        lingua_debug_log('Lingua: matched language from URL = ' . $lang);
                    }
                }
            }
        }
        
        // Final fallback: check GET parameter
        if (empty($lang) && isset($_GET['lang'])) {
            $lang = sanitize_text_field($_GET['lang']);
            lingua_debug_log('Lingua: language from GET parameter = ' . $lang);
        }
        
        if (empty($lang)) {
            $lang = $this->default_language;
            lingua_debug_log('Lingua: using default language = ' . $lang);
        }
        
        if (!isset($this->languages[$lang]) && $lang !== $this->default_language) {
            lingua_debug_log('Lingua: invalid language, falling back to default');
            $lang = $this->default_language;
        }
        
        // Store current language
        if (!defined('LINGUA_CURRENT_LANG')) {
            define('LINGUA_CURRENT_LANG', $lang);
            lingua_debug_log('Lingua: defined LINGUA_CURRENT_LANG = ' . $lang);
        }
        
        // Set locale if needed
        if ($lang !== $this->default_language) {
            $locale = $this->get_locale_for_language($lang);
            if ($locale) {
                switch_to_locale($locale);
                lingua_debug_log('Lingua: switched to locale = ' . $locale);
            }
        }
    }
    
    /**
     * Filter home URL to add language prefix
     */
    public function filter_home_url_old($url, $path) {
        $current_lang = defined('LINGUA_CURRENT_LANG') ? LINGUA_CURRENT_LANG : $this->default_language;
        
        if ($current_lang !== $this->default_language) {
            // Проверяем, не содержит ли URL уже языковой префикс
            if (strpos($url, '/' . $current_lang) === false) {
                $home = trailingslashit(get_option('home'));
                $url = str_replace($home, $home . $current_lang . '/', $url);
            }
        }
        
        return $url;
    }
    
    /**
     * Filter post permalink to add language prefix
     */
    public function filter_post_link_old($permalink, $post) {
        return $this->add_language_to_url($permalink);
    }
    
    /**
     * Filter page permalink to add language prefix
     */
    public function filter_page_link_old($permalink, $post_id) {
        return $this->add_language_to_url($permalink);
    }
    
    /**
     * Filter custom post type permalink to add language prefix
     */
    public function filter_post_type_link_old($permalink, $post) {
        return $this->add_language_to_url($permalink);
    }
    
    /**
     * Filter term link to add language prefix
     */
    public function filter_term_link_old($termlink, $term, $taxonomy) {
        return $this->add_language_to_url($termlink);
    }
    
    
    /**
     * Get URL for specific language
     * Теперь принимает полный URL параметр для точного переключения
     */
    public function get_url_for_language($lang, $url = null) {
        // STEP 1: Определяем исходный URL
        if (empty($url)) {
            $url = $this->get_current_full_url();
        }
        
        lingua_debug_log('Lingua IMPROVED: get_url_for_language(' . $lang . ') for URL: ' . $url);
        
        // STEP 2: Парсим URL на компоненты  
        $parsed = wp_parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? $_SERVER['HTTP_HOST'];
        $path = $parsed['path'] ?? '/';
        $query = $parsed['query'] ?? '';
        $fragment = $parsed['fragment'] ?? '';
        
        // STEP 3: Получаем РЕАЛЬНЫЙ домашний URL (без языкового префикса)
        // Используем get_option напрямую, чтобы избежать языковых фильтров
        $base_home_url = get_option('home');
        $home_parsed = wp_parse_url($base_home_url);
        $home_path = $home_parsed['path'] ?? '';
        
        lingua_debug_log('Lingua IMPROVED: Original path: ' . $path . ', Base home: ' . $base_home_url . ', Home path: ' . $home_path);
        
        // STEP 4: Убираем ТОЛЬКО реальный home_path (не языковые префиксы!)
        $relative_path = $path;
        if (!empty($home_path) && $home_path !== '/' && strpos($path, $home_path) === 0) {
            $relative_path = substr($path, strlen($home_path));
            // Ensure relative path starts with /
            if ($relative_path && $relative_path[0] !== '/') {
                $relative_path = '/' . $relative_path;
            }
        }
        
        // STEP 5: Удаляем ВСЕ языковые префиксы
        $clean_path = $this->remove_all_language_prefixes($relative_path);
        
        lingua_debug_log('Lingua IMPROVED: Relative path: ' . $relative_path . ', Clean path: ' . $clean_path);
        
        // STEP 6: Добавляем языковой префикс для целевого языка
        $final_relative_path = $clean_path;
        if ($lang !== $this->default_language) {
            $final_relative_path = '/' . $lang . $clean_path;
        }
        
        // STEP 7: Собираем финальный путь (НЕ добавляем home_path дважды)
        $final_path = $final_relative_path;
        $final_path = $this->normalize_path($final_path);
        
        lingua_debug_log('Lingua IMPROVED: Final path: ' . $final_path);
        
        // STEP 8: Собираем финальный URL через базовый home_url (без языковых фильтров)
        $final_url = $base_home_url . $final_path;
        if ($query) {
            $final_url .= '?' . $query;
        }
        if ($fragment) {
            $final_url .= '#' . $fragment;
        }
        
        lingua_debug_log('Lingua IMPROVED: Final URL for ' . $lang . ': ' . $final_url);
        
        return $final_url;
    }
    
    /**
     * Получить полный URL текущей страницы
     */
    private function get_current_full_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        
        $full_url = $scheme . '://' . $host . $uri;
        
        lingua_debug_log('Lingua IMPROVED: Current full URL: ' . $full_url);
        
        return $full_url;
    }
    
    /**
     * Remove ALL language prefixes from path - NEW METHOD
     */
    private function remove_all_language_prefixes($path) {
        // Get ONLY valid language codes
        $all_languages = array_keys($this->languages);
        
        // Ensure default language is included
        if (!in_array($this->default_language, $all_languages)) {
            $all_languages[] = $this->default_language;
        }
        
        // Remove duplicates and filter out invalid codes
        $all_languages = array_unique($all_languages);
        $all_languages = array_filter($all_languages, function($lang) {
            return !empty($lang) && strlen($lang) === 2; // Only 2-letter codes
        });
        
        lingua_debug_log('Lingua REWRITTEN: Valid languages to remove: ' . implode(', ', $all_languages));
        
        // Remove language prefixes repeatedly until none are left
        $clean_path = $path;
        $max_iterations = 5; // Prevent infinite loops
        $iteration = 0;
        
        do {
            $before = $clean_path;
            
            // Remove any language prefix from the start
            foreach ($all_languages as $lang_code) {
                $pattern = '#^/' . preg_quote($lang_code, '#') . '(/|$)#';
                if (preg_match($pattern, $clean_path)) {
                    $clean_path = preg_replace($pattern, '$1', $clean_path);
                    lingua_debug_log('Lingua REWRITTEN: Removed "' . $lang_code . '" prefix, now: ' . $clean_path);
                    break; // Only remove one at a time to be safe
                }
            }
            
            $iteration++;
        } while ($before !== $clean_path && $iteration < $max_iterations);
        
        // Ensure we have at least /
        if (empty($clean_path) || $clean_path === '') {
            $clean_path = '/';
        }
        
        return $clean_path;
    }
    
    /**
     * Normalize path to prevent double slashes - NEW METHOD
     */
    private function normalize_path($path) {
        // Ensure starts with /
        if (empty($path) || $path[0] !== '/') {
            $path = '/' . $path;
        }
        
        // Remove double slashes
        $path = preg_replace('#/+#', '/', $path);
        
        // Ensure we don't end with double slashes (except single /)
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        
        return $path;
    }
    
    /**
     * Get current URL - fixed for front page and language prefixes
     */
    private function get_current_url() {
        global $wp;
        
        // Use REQUEST_URI for more reliable URL detection
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH) ?: '';
        
        // Remove home path if exists
        if ($home_path && strpos($request_uri, $home_path) === 0) {
            $request_uri = substr($request_uri, strlen($home_path));
        }
        
        // Clean the URI
        $request_uri = '/' . ltrim($request_uri, '/');
        
        // Special handling for front page
        if ($request_uri === '/' || empty(trim($request_uri, '/'))) {
            // Check if we're on a language-prefixed front page
            $current_lang = defined('LINGUA_CURRENT_LANG') ? LINGUA_CURRENT_LANG : $this->default_language;
            if ($current_lang !== $this->default_language) {
                return home_url('/' . $current_lang . '/');
            }
            return home_url('/');
        }
        
        return home_url($request_uri);
    }
    
    /**
     * Get default language - PUBLIC GETTER для использования в других классах
     */
    public function get_default_language() {
        return $this->default_language;
    }
    
    /**
     * Get locale for language code
     */
    /**
     * v3.7.3: Add language prefix to all URLs in content
     * This catches hardcoded links in content that bypass WordPress URL functions
     */
    public function add_language_to_content_urls($content) {
        global $LINGUA_LANGUAGE;

        // Only process if we have a non-default language
        if (empty($LINGUA_LANGUAGE) || $LINGUA_LANGUAGE === $this->default_language) {
            return $content;
        }

        // Skip in admin
        if (is_admin()) {
            return $content;
        }

        // Get site URL
        $site_url = home_url();
        $site_url_escaped = preg_quote($site_url, '/');

        // Pattern to match internal links that don't have language prefix
        // Match: href="https://site.com/page" but NOT href="https://site.com/en/page"
        $pattern = '/href="(' . $site_url_escaped . ')\/(?!' . preg_quote($LINGUA_LANGUAGE, '/') . '\/)([^"]*?)"/i';

        // Replace with language-prefixed URL
        $replacement = 'href="$1/' . $LINGUA_LANGUAGE . '/$2"';

        $content = preg_replace($pattern, $replacement, $content);

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $matches_count = preg_match_all($pattern, $content);
            if ($matches_count > 0) {
                lingua_debug_log("[LINGUA URL v3.7.3] Added language prefix to {$matches_count} URLs in content");
            }
        }

        return $content;
    }

    private function get_locale_for_language($lang) {
        $locales = array(
            'en' => 'en_US',
            'ru' => 'ru_RU',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'es' => 'es_ES',
            'it' => 'it_IT',
            'zh' => 'zh_CN'
        );
        
        return isset($locales[$lang]) ? $locales[$lang] : false;
    }
}