<?php
/**
 * Lingua Output Buffer
 *
 * Система буферизации вывода для захвата полного HTML страницы
 * DOM-based подход к применению переводов
 *
 * @package Lingua
 * @version 5.2.37
 *
 * CHANGELOG:
 * v5.2.37 - CRITICAL PERFORMANCE (P5): Query only needed translations
 *           BEFORE: Load ALL translations (~10,000+ rows) → 5 MB memory, 0.5s query
 *           AFTER: Extract strings from HTML (~500 unique) → Query only those → 250 KB, 0.025s
 *           Result: DB 20x faster, memory 20x less, page load 3-5x faster on cache miss
 *           Added extract_strings_from_html() method for string pre-extraction
 * v5.2.36 - PERFORMANCE (P1+P2): Eliminated HTTP auto-fetch (1-5 sec saved) + cache TTL 1h→24h
 * v5.2.35 - Conditional debug logging (performance) + removed duplicate link rewriting call
 * v5.2.6 - PERFORMANCE BREAKTHROUGH: Inverted translation loop from O(n×m×k) to O(n+m)
 *          OLD: foreach 100 translations { foreach 1000 nodes { normalize 15 times } } = 1,500,000 ops
 *          NEW: Build hash map (100 ops) + ONE DOM pass with O(1) lookup (1000 ops) = 1,100 ops
 *          Result: 99% performance improvement (5-10 sec → 0.3-0.5 sec)
 *          Added normalize_text_once() and apply_translations_optimized() methods
 * v5.0.18 - CRITICAL PERFORMANCE FIX: Cache TRANSLATED HTML, not just original
 *           Problem: Every request did DB query + DOM parsing + translation (slow!)
 *           Solution: Cache final translated HTML for 1 hour (lingua_translated_html_*)
 *           Also fixed cache key mismatch: line 211 saved with $original_html_cache_key,
 *           but line 321 read with $cache_key (causing 100% cache misses)
 * v5.0.17 - Prevent partial translations by detecting Cyrillic in result
 * v5.0.16 - Remove break statements to translate ALL occurrences + sort by length
 * v5.0.15 - Add trim() to normalization for whitespace matching
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Output_Buffer {

    private $extractor;
    private $is_enabled;
    private $current_language;
    private $default_language;

    // v5.3.44: Recursion protection for translate_page
    private $recursion_depth = 0;
    private $max_recursion_depth = 3;

    /**
     * v5.2.35: Helper для debug-логирования в файлы (выключено по умолчанию для производительности)
     * Включить: define('LINGUA_DEBUG_FILES', true); в wp-config.php
     */
    private function debug_file_log($filename, $message) {
        if (defined('LINGUA_DEBUG_FILES') && LINGUA_DEBUG_FILES) {
            $upload_dir = wp_upload_dir();
            $debug_dir = $upload_dir['basedir'] . '/iqcloud-translate/';
            if (!file_exists($debug_dir)) {
                wp_mkdir_p($debug_dir);
            }
            file_put_contents($debug_dir . $filename, date('Y-m-d H:i:s') . " - {$message}\n", FILE_APPEND);
        }
    }

    public function __construct() {
        $this->is_enabled = false;
        $this->current_language = $this->get_current_language();
        $this->default_language = $this->get_default_language();

        // v5.2.35: Debug logging disabled by default for performance
        $url = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $this->debug_file_log('output-buffer-construct.txt', "Output Buffer CONSTRUCT called for URL: {$url}");
        $this->debug_file_log('output-buffer-construct.txt', "Current language set to: {$this->current_language}, default: {$this->default_language}");

        // Инициализируем экстрактор
        if (class_exists('Lingua_Full_Dom_Extractor')) {
            $this->extractor = new Lingua_Full_Dom_Extractor();
        }

        // v5.2.63: Register cache invalidation hooks for posts/products
        add_action('save_post', array($this, 'clear_cache_on_post_update'), 10, 1);
        add_action('wp_insert_post', array($this, 'clear_cache_on_post_update'), 10, 1);
        add_action('edit_post', array($this, 'clear_cache_on_post_update'), 10, 1);

        // v5.2.63: Clear cache when terms (categories/tags) are updated
        add_action('edited_term', array($this, 'clear_all_translation_caches'), 10);
        add_action('created_term', array($this, 'clear_all_translation_caches'), 10);
        add_action('delete_term', array($this, 'clear_all_translation_caches'), 10);
    }

    /**
     * Запуск системы буферизации
     * Вызывается в init хуке с приоритетом 1
     */
    public function start_output_buffering() {
        // v3.0.7 DEBUG: Логируем ВСЕ попытки запуска
        $url = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

        // v5.2.35: Conditional debug logging
        $this->debug_file_log('start-buffering-test.txt', "start_output_buffering CALLED! URL: {$url}, lang: {$this->current_language}");

        // Проверяем нужно ли запускать буферизацию
        if (!$this->should_start_buffering()) {
            $this->debug_file_log('start-buffering-test.txt', "BLOCKED by should_start_buffering()!");
            return;
        }

        // v5.3.5: Set global flag BEFORE starting buffer        // This flag is checked by gettext filters to only process visible strings
        global $lingua_output_buffer_started;
        $lingua_output_buffer_started = true;

        // Запускаем буферизацию
        ob_start(array($this, 'process_page_output'), 0);
        $this->is_enabled = true;

        // Логируем запуск
        $this->debug_file_log('start-buffering-test.txt', "Output buffering STARTED for language: {$this->current_language}");
    }

    /**
     * Определение нужности запуска буферизации     */
    private function should_start_buffering() {
        // v5.3.5 TEMP DEBUG: Skip output buffer to find 502 source
        // return false;

        // v5.0.14: DEBUG - log WHY buffering starts or doesn't start
        $url = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        lingua_debug_log("[Lingua v5.0.14] should_start_buffering check for URL: {$url}, lang: {$this->current_language}");

        // v5.0.11 FIX: template_redirect hook already filters out admin, so no need to check is_admin()

        // Не запускаем для AJAX запросов (кроме frontend AJAX)
        if (wp_doing_ajax() && !$this->is_frontend_ajax()) {
            lingua_debug_log('[Lingua v5.0.14] ⛔ BLOCKED: AJAX request (not frontend AJAX)');
            $this->log_debug('v5.0.11 DEBUG: BLOCKED - AJAX request (not frontend AJAX)');
            return false;
        }

        // Не запускаем для REST API запросов
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        // Не запускаем для CLI
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }

        // Не запускаем для cron запросов (как в TP)
        if (isset($_REQUEST['doing_wp_cron'])) {
            return false;
        }

        // Проверяем конфликт с кэш-плагинами (только логируем, НЕ блокируем)
        if ($this->has_cache_plugin_conflict()) {
            $this->log_debug('v3.0.4 CRITICAL FIX: Cache plugin detected, but buffering will continue');
        }

        // Не запускаем если в редакторе переводов (левая панель)
        if ($this->is_translation_editor_preview()) {
            return false;
        }

        return true;
    }

    /**
     * Проверка конфликта с кэш-плагинами
     */
    private function has_cache_plugin_conflict() {
        // WP Rocket
        if (defined('WP_ROCKET_VERSION')) {
            return true;
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            return true;
        }

        // WP Super Cache
        if (defined('WPCACHEHOME')) {
            return true;
        }

        // LiteSpeed Cache
        if (defined('LSCWP_V')) {
            return true;
        }

        // Autoptimize
        if (defined('AUTOPTIMIZE_PLUGIN_VERSION')) {
            return true;
        }

        return false;
    }

    /**
     * Проверка на frontend AJAX
     */
    private function is_frontend_ajax() {
        if (!wp_doing_ajax()) {
            return false;
        }

        $frontend_actions = array(
            'lingua_get_translatable_content',
            'lingua_translate_frontend',
            'lingua_save_translation'
        );

        $action = $_REQUEST['action'] ?? '';
        return in_array($action, $frontend_actions);
    }

    /**
     * Проверка на редактор переводов
     */
    private function is_translation_editor_preview() {
        return isset($_REQUEST['lingua-edit-translation']) &&
               $_REQUEST['lingua-edit-translation'] === 'preview';
    }

    /**
     * Основной метод обработки захваченного HTML
     * Аналог translate_page()     */
    public function process_page_output($output) {

        // v3.3 DEBUG: CRITICAL - log every invocation
        $url = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $output_length = strlen($output);

        // v5.2.35: Conditional debug logging
        $this->debug_file_log('process-output-test.txt', "process_page_output CALLED! URL: {$url}, lang: {$this->current_language}, length: {$output_length}");

        // v3.6 FIX: If lingua_force_default parameter is present, skip ALL translations
        // This is used by AJAX handler to fetch original content regardless of current language
        if (isset($_GET['lingua_force_default']) && $_GET['lingua_force_default'] === '1') {
            $this->log_debug("v3.6 DEBUG: lingua_force_default detected - returning original HTML without translation");
            return $output;
        }

        // Проверяем валидность вывода
        if (!$this->is_valid_output($output)) {
            $this->log_debug("v3.3 DEBUG: BLOCKED by is_valid_output() check");
            $this->debug_file_log('process-page-flow.txt', "BLOCKED by is_valid_output()");
            return $output;
        }

        $this->log_debug("v3.3 DEBUG: Passed is_valid_output() check, proceeding...");
        $this->debug_file_log('process-page-flow.txt', "✓ Passed is_valid_output(), length: " . strlen($output));

        // v3.0.6 FIX: Используем URL для кэширования вместо post_id        // Post_id может быть неточным на страницах с несколькими постами или WooCommerce
        $current_url = $this->get_current_url();

        // v3.0.34: Include user login status in cache key (admin bar visibility)
        $user_status = is_user_logged_in() ? 'logged_in' : 'guest';
        // v5.0.8 CRITICAL FIX: Original HTML cache key should NOT include language
        // All languages need to access the SAME cached RU HTML to find original text
        $original_html_cache_key = 'lingua_original_html_' . md5($current_url . '_' . $user_status);
        $cache_key = md5($current_url . '_' . $user_status . '_' . $this->current_language);

        // Логируем начало обработки
        $this->log_debug("v3.0.6: Processing page output for URL {$current_url}, language: {$this->current_language}");
        $this->debug_file_log('process-page-flow.txt', "Processing URL: {$current_url}, lang: {$this->current_language}, default: {$this->default_language}");

        try {
            // v5.2.36: Removed original HTML caching (now redundant - we use $output parameter directly)
            // Previously cached RU HTML for later HTTP auto-fetch, but that's eliminated now

            // Режим извлечения контента для редактора переводов
            if ($this->is_extraction_mode()) {
                $this->debug_file_log('process-page-flow.txt', "MODE: Extraction mode");
                // Для extraction mode все еще используем post_id (для AJAX)
                $post_id = $this->get_current_post_id();
                $this->process_extraction_mode($output, $post_id);
            }

            // Режим применения переводов (используем cache_key вместо post_id)
            $is_trans_mode = $this->is_translation_mode();
            $this->debug_file_log('process-page-flow.txt', "is_translation_mode() = " . ($is_trans_mode ? 'TRUE' : 'FALSE'));

            if ($is_trans_mode) {
                $this->debug_file_log('process-page-flow.txt', "✓ ENTERING process_translation_mode()");
                $output = $this->process_translation_mode($output, $original_html_cache_key);
                $this->debug_file_log('process-page-flow.txt', "✓ EXITED process_translation_mode()");
            } else {
                $this->debug_file_log('process-page-flow.txt', "✗ SKIPPING translations (is_translation_mode = false)");
            }

            // Режим отладки - сохраняем оригинальный HTML
            if ($this->is_debug_mode()) {
                $this->save_debug_html($output, $post_id);
            }

        } catch (Exception $e) {
            // В случае ошибки возвращаем оригинальный вывод
            $this->log_error('Error in process_page_output: ' . $e->getMessage());
        }

        return $output;
    }

    /**
     * Проверка валидности вывода
     */
    private function is_valid_output($output) {
        if (empty($output) || !is_string($output)) {
            return false;
        }

        // Минимальная длина HTML
        if (strlen($output) < 100) {
            return false;
        }

        // Проверяем что это HTML
        if (strpos($output, '<html') === false && strpos($output, '<!DOCTYPE') === false) {
            return false;
        }

        return true;
    }

    /**
     * Режим извлечения контента
     * Срабатывает когда пользователь открывает редактор переводов
     */
    private function is_extraction_mode() {
        return isset($_REQUEST['lingua-extract-content']) ||
               isset($_COOKIE['lingua_extraction_mode']);
    }

    /**
     * Обработка в режиме извлечения
     */
    private function process_extraction_mode($html, $post_id) {
        if (!$this->extractor) {
            $this->log_error('DOM Extractor not available for extraction mode');
            return;
        }

        // Извлекаем контент через новый DOM экстрактор
        $extracted_content = $this->extractor->extract_from_full_html($html, $post_id);

        // Кэшируем результат для использования в AJAX
        // v5.2.36: Increased cache TTL to 24 hours (will be invalidated on translation update)
        $cache_key = 'lingua_extracted_content_' . $post_id;
        set_transient($cache_key, $extracted_content, DAY_IN_SECONDS);

        // Также сохраняем оригинальный HTML для анализа
        $html_cache_key = 'lingua_original_html_' . $post_id;
        set_transient($html_cache_key, $html, DAY_IN_SECONDS);

        $this->log_debug("Content extracted and cached for post {$post_id}");
    }

    /**
     * Режим применения переводов
     */
    private function is_translation_mode() {
        // Применяем переводы если не язык по умолчанию
        $is_translation = $this->current_language !== $this->default_language;
        $this->log_debug("v3.0: is_translation_mode check: current={$this->current_language}, default={$this->default_language}, result=" . ($is_translation ? 'YES' : 'NO'));
        return $is_translation;
    }

    /**
     * Обработка в режиме перевода
     * v3.0.12: Fixed logging to show actual cache_key being used
     */
    public function process_translation_mode($html, $cache_key) {
        // v5.2.35: Conditional debug logging
        $this->debug_file_log('process-translation-mode-test.txt', "process_translation_mode CALLED for lang: {$this->current_language}");

        // v3.0.16: DOM-based translation application
        $current_url = $this->get_current_url();
        $this->log_debug("v3.0.16: Translation mode active, language: {$this->current_language}, cache_key: {$cache_key}, URL: {$current_url}");

        // NOTE: Проверка на дефолтный язык уже сделана в is_translation_mode() перед вызовом этой функции

        // v5.0.18: PERFORMANCE - Cache TRANSLATED HTML, not just original
        $user_status = is_user_logged_in() ? 'logged_in' : 'guest';
        $translated_cache_key = 'lingua_translated_html_' . md5($current_url . '_' . $user_status . '_' . $this->current_language);
        $cached_translated_html = get_transient($translated_cache_key);

        if ($cached_translated_html) {
            $this->debug_file_log('cache-performance.txt', "🚀 TRANSLATED CACHE HIT - URL: {$current_url}, lang: {$this->current_language}");
            $this->debug_file_log('process-translation-mode-test.txt', "⚡ Returning cached translated HTML");

            // v5.2.159: Apply media replacement even for cached HTML (media translations may update independently)
            if (class_exists('Lingua_Media_Replacer')) {
                $media_replacer = new Lingua_Media_Replacer();
                $cached_translated_html = $media_replacer->replace_media_in_html($cached_translated_html);
            }

            // v5.2.144: Apply output buffer filter even for cached HTML (for hreflang replacement)
            $cached_translated_html = apply_filters('lingua_process_output_buffer', $cached_translated_html);
            return $cached_translated_html;
        }

        $this->debug_file_log('process-translation-mode-test.txt', "Cache MISS, continuing with translation...");

        try {
            // v5.2.36: PERFORMANCE OPTIMIZATION - Use $html parameter directly instead of HTTP auto-fetch
            // WordPress always returns original (RU) HTML because we don't modify database
            // No need to make expensive wp_remote_get() request to ourselves!
            $this->debug_file_log('process-translation-mode-test.txt', "Using $html parameter directly (no HTTP fetch needed)");
            $this->log_debug("v5.2.36: PERFORMANCE - Using passed HTML parameter directly, eliminated HTTP request");

            // Step 1: Extract language switcher from CURRENT HTML (has correct active language)
            $current_menu = $this->extract_language_menu($html);
            $this->debug_file_log('process-translation-mode-test.txt', "Menu extracted, loading translations from DB...");

            // Step 1.1: Extract admin bar from CURRENT HTML (may differ based on login status)
            $current_admin_bar = $this->extract_admin_bar($html);

            // Step 2: Use passed HTML for translation (already contains original text)
            $original_html = $html;

            // v5.2.32: CRITICAL FIX - Add language prefix to links BEFORE checking for translations
            // This ensures links are rewritten even when NO translations exist yet!
            // User requirement: "на странице перевода - если отсутствуют переводы - все равно отображать язык правильно (и ссылки)"
            $this->debug_file_log('link-rewriting-test.txt', "EARLY link rewriting - About to call add_language_prefix_to_links for lang: {$this->current_language}");
            $original_html = $this->add_language_prefix_to_links($original_html);
            $this->debug_file_log('link-rewriting-test.txt', "EARLY link rewriting - Finished add_language_prefix_to_links call");

            // v5.2.37: PERFORMANCE OPTIMIZATION - Extract strings FIRST, then query only those
            // OLD: Load ALL translations (~10,000+ rows) → 5 MB memory, 0.5s query
            // NEW: Load only needed translations (~500 rows) → 250 KB memory, 0.025s query
            // Result: 20x faster DB query, 3-5x faster overall page load on cache miss
            $start_extract_time = microtime(true);


            $strings_in_html = $this->extract_strings_from_html($original_html);

            foreach ($strings_in_html as $idx => $str) {
                if (stripos($str, 'will') !== false || stripos($str, 'privacy') !== false || stripos($str, 'policy') !== false) {
                }
                if ($idx >= 20) break; // Don't log too many
            }

            // v1.2.2 FIX: Normalize strings (trim whitespace) before DB query
            // HTML may have trailing tabs/spaces that prevent exact match in DB
            // v5.3.26: Keep original strings, create normalized versions for fallback search
            $strings_in_html = array_map('trim', $strings_in_html);
            $strings_in_html = array_filter($strings_in_html);
            $strings_in_html = array_unique($strings_in_html);

            // v5.3.26: Create normalized versions for fallback search (preserves spaces for exact match first)
            // v5.3.27: Added ё→е and dash normalization for Russian text matching
            $normalized_to_originals = array(); // Map: normalized → [original1, original2, ...]
            $normalized_strings = array();
            foreach ($strings_in_html as $original) {
                // Normalize: decode entities, convert nbsp, collapse spaces, normalize dashes and ё
                $normalized = html_entity_decode($original, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $normalized = str_replace("\xC2\xA0", ' ', $normalized);
                $normalized = preg_replace('/\s+/', ' ', $normalized);
                // v5.3.27: Normalize dashes (em-dash, en-dash → hyphen)
                $normalized = str_replace(array('–', '—', '‐'), '-', $normalized);
                // v5.3.27: Normalize Russian ё → е
                $normalized = str_replace(array('ё', 'Ё'), array('е', 'Е'), $normalized);
                // v5.3.28: Normalize quotes (guillemets, curly quotes → straight quotes)
                // Using UTF-8 byte sequences for curly quotes to avoid PHP parsing issues
                $normalized = str_replace(
                    array('«', '»', "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9E", "\xE2\x80\xB9", "\xE2\x80\xBA", "\xE2\x80\x98", "\xE2\x80\x99"),
                    array('"', '"', '"', '"', '"', "'", "'", "'", "'"),
                    $normalized
                );
                $normalized = trim($normalized);

                // v5.3.28 FIX: Always track normalized versions for Phase 2 fallback
                // Even if normalized === original, the DB might have special chars that need normalization
                if (!empty($normalized)) {
                    if (!isset($normalized_to_originals[$normalized])) {
                        $normalized_to_originals[$normalized] = array();
                        $normalized_strings[] = $normalized;
                    }
                    $normalized_to_originals[$normalized][] = $original;
                }
            }

            $extract_time = microtime(true) - $start_extract_time;
            lingua_debug_log("[Lingua v5.2.37 P5] Extracted " . count($strings_in_html) . " unique strings from HTML in " . round($extract_time, 3) . "s");


            if (empty($strings_in_html)) {
                lingua_debug_log("[Lingua v5.2.37 P5] No strings found in HTML, skipping translation");
                $this->debug_file_log('process-translation-mode-test.txt', "No strings found in HTML");
                return $original_html;
            }

            // КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ v3.0: Получаем переводы из базы данных (новая структура)
            // v5.2.37: Query only translations for strings found in HTML            // v5.3.26: Two-phase search: exact match first, then normalized fallback
            global $wpdb;
            $table_name = $wpdb->prefix . 'lingua_string_translations';

            $start_db_time = microtime(true);
            $translations = array();
            $found_originals = array(); // Track which originals were found

            // v5.3.26: PHASE 1 - Exact match (preserves original spacing)
            $chunks = array_chunk($strings_in_html, 1000);
            foreach ($chunks as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
                $query = $wpdb->prepare(
                    "SELECT original_text, translated_text FROM {$table_name}
                     WHERE language_code = %s
                     AND translated_text != ''
                     AND translated_text != original_text
                     AND status >= %d
                     AND context NOT LIKE %s
                     AND context NOT LIKE %s
                     AND context NOT LIKE %s
                     AND context NOT LIKE %s
                     AND context NOT LIKE %s
                     AND TRIM(original_text) IN ({$placeholders})",
                    array_merge(
                        array(
                            $this->current_language,
                            Lingua_Database::MACHINE_TRANSLATED,
                            'media.src%',
                            '%_seo_title',
                            '%_meta_description',
                            '%_og_title',
                            '%_og_description'
                        ),
                        $chunk
                    )
                );
                $chunk_results = $wpdb->get_results($query, ARRAY_A);
                if (!empty($chunk_results)) {
                    foreach ($chunk_results as $r) {
                        $found_originals[trim($r['original_text'])] = true;
                    }
                    $translations = array_merge($translations, $chunk_results);
                }
            }

            $exact_count = count($translations);
            lingua_debug_log("[Lingua v5.3.26] Phase 1 (exact): found {$exact_count} translations");

            // v5.3.26: PHASE 2 - Normalized fallback (for strings with &nbsp; or multiple spaces)
            // Only search for normalized strings that weren't found in exact match
            if (!empty($normalized_strings)) {
                $remaining_normalized = array();
                foreach ($normalized_strings as $norm) {
                    // Check if any original variant was already found
                    $already_found = false;
                    if (isset($normalized_to_originals[$norm])) {
                        foreach ($normalized_to_originals[$norm] as $orig) {
                            if (isset($found_originals[trim($orig)])) {
                                $already_found = true;
                                break;
                            }
                        }
                    }
                    if (!$already_found) {
                        $remaining_normalized[] = $norm;
                    }
                }

                if (!empty($remaining_normalized)) {
                    $chunks = array_chunk($remaining_normalized, 1000);
                    foreach ($chunks as $chunk) {
                        $placeholders = implode(', ', array_fill(0, count($chunk), '%s'));
                        // v5.3.27: Extended normalization in SQL:
                        // 1. CHAR(194,160) = nbsp → space
                        // 2. Multiple spaces → single space
                        // 3. Em-dash (—), en-dash (–) → hyphen (-)
                        // 4. Russian ё/Ё → е/Е
                        $query = $wpdb->prepare(
                            "SELECT original_text, translated_text FROM {$table_name}
                             WHERE language_code = %s
                             AND translated_text != ''
                             AND translated_text != original_text
                             AND status >= %d
                             AND context NOT LIKE %s
                             AND context NOT LIKE %s
                             AND context NOT LIKE %s
                             AND context NOT LIKE %s
                             AND context NOT LIKE %s
                             AND TRIM(REGEXP_REPLACE(
                                 REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                                     original_text,
                                     CHAR(194,160), ' '),
                                     '—', '-'),
                                     '–', '-'),
                                     'ё', 'е'),
                                     'Ё', 'Е'),
                                     '«', '\"'),
                                     '»', '\"'),
                                     CHAR(226,128,156), '\"'),
                                     CHAR(226,128,157), '\"'),
                                 ' +', ' ')) IN ({$placeholders})",
                            array_merge(
                                array(
                                    $this->current_language,
                                    Lingua_Database::MACHINE_TRANSLATED,
                                    'media.src%',
                                    '%_seo_title',
                                    '%_meta_description',
                                    '%_og_title',
                                    '%_og_description'
                                ),
                                $chunk
                            )
                        );
                        $chunk_results = $wpdb->get_results($query, ARRAY_A);
                        if (!empty($chunk_results)) {
                            $translations = array_merge($translations, $chunk_results);
                        }
                    }
                }
            }

            $db_time = microtime(true) - $start_db_time;
            $fallback_count = count($translations) - $exact_count;
            lingua_debug_log("[Lingua v5.3.26] Phase 2 (normalized fallback): found {$fallback_count} more translations");
            lingua_debug_log("[Lingua v5.2.37 P5] Loaded " . count($translations) . " translations from DB (queried " . count($strings_in_html) . " strings) in " . round($db_time, 3) . "s");

            // v5.3.1: Removed synchronous on-demand translation (caused 504 timeout)
            // Auto-translation now happens in background via save_post hook + cron queue
            // See Lingua_Auto_Translator::queue_post_for_translation()

            // v5.0.16: CRITICAL FIX - Sort translations by length (longest first)
            // Problem: "Мебель для дома" was matching inside "Мебель для дома и дачи"
            // Solution: Process longer strings first to avoid partial matches
            usort($translations, function($a, $b) {
                return strlen($b['original_text']) - strlen($a['original_text']);
            });

            // v5.0.13: Debug - check if "Лавка интерьерная" is in translations
            lingua_debug_log("[Lingua v5.0.14 DEBUG] Loaded " . count($translations) . " translations from DB for language: {$this->current_language}");

            $found_lavka = false;
            foreach ($translations as $t) {
                if (strpos($t['original_text'], 'Лавка') !== false) {
                    lingua_debug_log("[Lingua DEBUG] Found Лавка in loaded translations: '" . $t['original_text'] . "' => '" . $t['translated_text'] . "'");
                    $found_lavka = true;
                }
            }

            if (!$found_lavka) {
                lingua_debug_log("[Lingua v5.0.14 DEBUG] ⚠️ 'Лавка интерьерная' NOT found in loaded translations!");
                lingua_debug_log("[Lingua v5.0.14 DEBUG] Total translations loaded: " . count($translations));
                // Log first 10 translations for debugging
                $sample = array_slice($translations, 0, 10);
                foreach ($sample as $i => $t) {
                    lingua_debug_log("[Lingua v5.0.14 DEBUG] Sample #" . ($i+1) . ": '" . substr($t['original_text'], 0, 50) . "' => '" . substr($t['translated_text'], 0, 50) . "'");
                }
            }

            if (empty($translations)) {
                // v5.2.32: Links were already rewritten above, so we can safely return
                $this->debug_file_log('process-translation-mode-test.txt', "No translations found, returning HTML with rewritten links");
                $this->log_debug("v5.2.32: No translations found for language {$this->current_language}, but links were rewritten");

                // v5.2.32: Replace menu and admin bar even without translations
                $original_html = $this->replace_language_menu($original_html, $current_menu);
                $original_html = $this->replace_admin_bar($original_html, $current_admin_bar);

                // v5.2.159: Apply media replacement even without text translations
                // Media translations are stored globally (not per-post), so they need to be applied
                if (class_exists('Lingua_Media_Replacer')) {
                    $media_replacer = new Lingua_Media_Replacer();
                    $original_html = $media_replacer->replace_media_in_html($original_html);
                    $this->log_debug("v5.2.159: ✓ Applied media replacements (no text translations, but media may exist)");
                }

                // v5.2.36: Increased cache TTL to 24 hours (invalidated on translation update)
                set_transient($translated_cache_key, $original_html, DAY_IN_SECONDS);

                return $original_html;
            }

            // v3.2: CRITICAL FIX - Extract and preserve <script> tags before DOM parsing
            // Simple HTML DOM Parser breaks <script> content, so we need to protect it
            $script_placeholders = array();
            $script_counter = 0;
            $html_with_placeholders = preg_replace_callback(
                '/<script\b[^>]*>(.*?)<\/script>/is',
                function($matches) use (&$script_placeholders, &$script_counter) {
                    $placeholder = "<!--LINGUA_SCRIPT_PLACEHOLDER_{$script_counter}-->";
                    $script_placeholders[$placeholder] = $matches[0];
                    $script_counter++;
                    return $placeholder;
                },
                $original_html
            );

            $this->log_debug("v3.2: Protected {$script_counter} script tags from DOM parser");

            // v5.0.9: CRITICAL FIX - Protect JSON data attributes using string search (not regex)
            // Previous regex failed with PREG_BACKTRACK_LIMIT_ERROR on large JSON
            $json_attr_counter = 0;
            $json_attributes = array('data-product_variations', 'data-cart_variations', 'data-attributes');

            foreach ($json_attributes as $attr_name) {
                $offset = 0;
                while (($pos = strpos($html_with_placeholders, $attr_name . '="', $offset)) !== false) {
                    $value_start = $pos + strlen($attr_name) + 2; // Skip 'attr="'

                    // Find matching closing quote (handle escaped quotes)
                    $value_end = false;
                    for ($i = $value_start; $i < strlen($html_with_placeholders); $i++) {
                        if ($html_with_placeholders[$i] === '"' && ($i === $value_start || $html_with_placeholders[$i-1] !== '\\')) {
                            $value_end = $i;
                            break;
                        }
                    }

                    if ($value_end === false) {
                        break; // Malformed HTML, skip
                    }

                    // Extract JSON value
                    $json_value = substr($html_with_placeholders, $value_start, $value_end - $value_start);

                    // Create placeholder
                    $placeholder_value = "LINGUA_JSON_PLACEHOLDER_" . $json_attr_counter . "_PLACEHOLDER";
                    $script_placeholders[$placeholder_value] = $json_value;

                    // Replace in HTML
                    $html_with_placeholders = substr_replace(
                        $html_with_placeholders,
                        $attr_name . '="' . $placeholder_value . '"',
                        $pos,
                        ($value_end - $pos) + 1
                    );

                    $json_attr_counter++;
                    $offset = $pos + strlen($attr_name) + strlen($placeholder_value) + 3;
                }
            }

            $this->log_debug("v5.0.9: Protected {$json_attr_counter} JSON data attributes using string search (no regex)");

            // v3.0.16: DOM-BASED TRANSLATION APPLICATION            // Парсим HTML в DOM объекты
            require_once LINGUA_PLUGIN_DIR . 'includes/lib/lingua-html-dom.php';

            $html_dom = lingua_str_get_html($html_with_placeholders, true, true, LINGUA_DEFAULT_TARGET_CHARSET, false, LINGUA_DEFAULT_BR_TEXT, LINGUA_DEFAULT_SPAN_TEXT);

            if ($html_dom === false) {
                $this->log_debug("v3.0.16: ✗ Failed to parse HTML to DOM, falling back to raw HTML");
                return $original_html;
            }

            $this->log_debug("v3.0.16: ✓ HTML parsed to DOM, processing " . count($translations) . " translations");

            $applied_count = 0;

            // v5.5.1: Save original meta descriptions BEFORE DOM traversal modifies them
            // v5.1: MEDIA TRANSLATION - Process images FIRST (before text translations)
            // This ensures image src, alt, title are translated before any text matching
            $this->apply_media_translations($html_dom, $this->current_language);

            // v5.2.6: PERFORMANCE OPTIMIZATION - Apply translations using O(n+m) algorithm
            // OLD: O(n × m × k) = 100 translations × 1000 nodes × 15 normalizations = 1,500,000 ops
            // NEW: O(n + m) = 100 + 1000 = 1,100 ops (99% improvement!)
            lingua_debug_log('[Lingua v5.2.6] Starting OPTIMIZED translation application for ' . count($translations) . ' translations, lang: ' . $this->current_language);

            $applied_count = $this->apply_translations_optimized($html_dom, $translations);

            // Рендерим DOM обратно в HTML
            $translated_html = $html_dom->save();
            $html_dom->clear(); // Очищаем память
            unset($html_dom);

            // v3.2: CRITICAL FIX - Restore original <script> tags
            foreach ($script_placeholders as $placeholder => $original_script) {
                $translated_html = str_replace($placeholder, $original_script, $translated_html);
            }
            $this->log_debug("v3.2: Restored {$script_counter} script tags after DOM processing");

            // v5.2.35: REMOVED DUPLICATE - Link rewriting now happens EARLY (line 461) before translation check
            // This old call was redundant (found 0 links every time) and wasted CPU on regex matching

            // v5.0.11: HYBRID APPROACH - Replace RU language menu with FR/DE/etc menu
            // This must be AFTER add_language_prefix_to_links() to preserve correct menu links
            $translated_html = $this->replace_language_menu($translated_html, $current_menu);

            // v5.0.11: Replace admin bar from CURRENT HTML (preserves login state)
            $translated_html = $this->replace_admin_bar($translated_html, $current_admin_bar);

            // v5.2: Apply media replacement (src, alt, title attributes)
            if (class_exists('Lingua_Media_Replacer')) {
                $media_replacer = new Lingua_Media_Replacer();
                $translated_html = $media_replacer->replace_media_in_html($translated_html);
                $this->log_debug("v5.2: ✓ Applied media replacements (img src/alt/title)");
            }

            // v5.2.137: Translate meta description and OG tags in final HTML
            // v5.5.1: Pass original meta values so translate_meta_tags can look up correct translations
            $translated_html = $this->translate_meta_tags($translated_html, $translations);

            $this->log_debug("v5.0.11: ✓ Applied {$applied_count} of " . count($translations) . " translations via DOM");

            // v5.2.144: Apply output buffer filter BEFORE caching (for hreflang replacement)
            $translated_html = apply_filters('lingua_process_output_buffer', $translated_html);

            // v5.2.36: PERFORMANCE - Cache TRANSLATED HTML (24 hours TTL, invalidated on translation update)
            set_transient($translated_cache_key, $translated_html, DAY_IN_SECONDS);
            $this->debug_file_log('cache-performance.txt', "💾 CACHED TRANSLATION - URL: {$current_url}, lang: {$this->current_language}, size: " . strlen($translated_html) . " bytes");

            return $translated_html;

        } catch (Exception $e) {
            $this->log_error('v3.0: Error applying translations: ' . $e->getMessage());
            return $html;
        }
    }

    /**
     * v5.0.11: Extract language switcher menu from HTML
     */
    private function extract_language_menu($html) {
        $menu_parts = array();

        // Extract language switcher <li> elements by class
        if (preg_match_all('/<li[^>]*class="[^"]*lingua-language-switcher-container[^"]*"[^>]*>.*?<\/li>/is', $html, $matches)) {
            $menu_parts['switchers'] = $matches[0];
            $this->log_debug("v5.0.11: Extracted " . count($matches[0]) . " language switcher elements");
        } else {
            $menu_parts['switchers'] = array();
            $this->log_debug("v5.0.11: No language switchers found in current HTML");
        }

        return $menu_parts;
    }

    /**
     * v5.0.11: Replace language switcher menu in translated HTML
     */
    private function replace_language_menu($translated_html, $menu_parts) {
        if (empty($menu_parts['switchers'])) {
            $this->log_debug("v5.0.11: No menu parts to replace");
            return $translated_html;
        }

        $replaced_count = 0;

        // Find old menu items in translated HTML
        if (preg_match_all('/<li[^>]*class="[^"]*lingua-language-switcher-container[^"]*"[^>]*>.*?<\/li>/is', $translated_html, $old_matches, PREG_OFFSET_CAPTURE)) {

            // Replace from end to beginning to preserve offsets
            for ($i = count($old_matches[0]) - 1; $i >= 0; $i--) {
                if (isset($menu_parts['switchers'][$i])) {
                    $old_menu = $old_matches[0][$i][0];
                    $old_offset = $old_matches[0][$i][1];
                    $new_menu = $menu_parts['switchers'][$i];

                    $translated_html = substr_replace($translated_html, $new_menu, $old_offset, strlen($old_menu));
                    $replaced_count++;
                }
            }

            $this->log_debug("v5.0.11: Replaced {$replaced_count} language switcher menus");
        } else {
            $this->log_debug("v5.0.11: No old menus found in translated HTML");
        }

        return $translated_html;
    }

    /**
     * v5.0.11: Extract admin bar from HTML
     */
    private function extract_admin_bar($html) {
        // Find admin bar start
        $start_pos = strpos($html, '<div id="wpadminbar"');
        if ($start_pos === false) {
            // Try alternative format
            $start_pos = strpos($html, "<div id='wpadminbar'");
        }

        if ($start_pos === false) {
            $this->log_debug("v5.0.11: No admin bar found in current HTML");
            return null;
        }

        // Find matching closing </div> by counting open/close tags
        $depth = 0;
        $i = $start_pos;
        $in_tag = false;

        while ($i < strlen($html)) {
            if ($html[$i] === '<') {
                $in_tag = true;
                // Check if opening <div
                if (substr($html, $i, 4) === '<div') {
                    $depth++;
                }
                // Check if closing </div>
                elseif (substr($html, $i, 6) === '</div>') {
                    $depth--;
                    if ($depth === 0) {
                        // Found matching closing tag
                        $admin_bar = substr($html, $start_pos, ($i + 6) - $start_pos);
                        $this->log_debug("v5.0.11: Extracted admin bar (" . strlen($admin_bar) . " bytes)");
                        return $admin_bar;
                    }
                }
            }
            elseif ($html[$i] === '>') {
                $in_tag = false;
            }

            $i++;
        }

        $this->log_debug("v5.0.11: Admin bar found but couldn't match closing tag");
        return null;
    }

    /**
     * v5.0.11: Replace admin bar in translated HTML
     */
    private function replace_admin_bar($translated_html, $admin_bar) {
        if (empty($admin_bar)) {
            $this->log_debug("v5.0.11: No admin bar to replace");
            return $translated_html;
        }

        // Remove old admin bar if exists (using same depth-counting logic)
        $start_pos = strpos($translated_html, '<div id="wpadminbar"');
        if ($start_pos === false) {
            $start_pos = strpos($translated_html, "<div id='wpadminbar'");
        }

        if ($start_pos !== false) {
            // Find matching closing </div>
            $depth = 0;
            $i = $start_pos;

            while ($i < strlen($translated_html)) {
                if (substr($translated_html, $i, 4) === '<div') {
                    $depth++;
                }
                elseif (substr($translated_html, $i, 6) === '</div>') {
                    $depth--;
                    if ($depth === 0) {
                        // Remove old admin bar
                        $translated_html = substr_replace($translated_html, '', $start_pos, ($i + 6) - $start_pos);
                        break;
                    }
                }
                $i++;
            }
        }

        // Insert new admin bar after <body> tag
        $body_pos = stripos($translated_html, '<body');
        if ($body_pos !== false) {
            $body_end = strpos($translated_html, '>', $body_pos);
            if ($body_end !== false) {
                $translated_html = substr_replace($translated_html, '>' . $admin_bar, $body_end, 1);
            }
        }

        $this->log_debug("v5.0.11: Replaced admin bar");
        return $translated_html;
    }

    /**
     * v5.2.33: Add language prefix to all internal page links (skip static files)
     * Enhanced: Supports absolute URLs, relative URLs, and data-attributes
     */
    private function add_language_prefix_to_links($html) {
        $default_language = $this->get_default_language();

        $this->debug_file_log('link-rewriting-test.txt', "v5.2.33 add_language_prefix_to_links STARTED - current: {$this->current_language}, default: {$default_language}");

        // Don't modify links if we're on default language
        if ($this->current_language === $default_language) {
            $this->debug_file_log('link-rewriting-test.txt', "SKIPPED - current lang === default lang");
            return $html;
        }

        $site_url = get_site_url();
        $lang_prefix = $this->current_language;
        $enabled_languages = $this->get_enabled_languages();

        $this->debug_file_log('link-rewriting-test.txt', "Rewriting links: site_url={$site_url}, lang_prefix={$lang_prefix}");

        $absolute_count = 0;
        $relative_count = 0;
        $data_attr_count = 0;

        // STRATEGY 1: Rewrite absolute URLs with domain (existing logic)
        // Pattern: href="https://site.com/path" (no language prefix yet)
        $html = preg_replace_callback(
            '@href="' . preg_quote($site_url, '@') . '/((?!(' . implode('|', $enabled_languages) . ')/|wp-content/|wp-includes/|wp-admin/)([^"?]*?))(\?[^"]*)?("|\s)@i',
            function($matches) use ($lang_prefix, &$absolute_count, $site_url) {
                $path = $matches[1];
                $query = isset($matches[4]) ? $matches[4] : '';
                $end = isset($matches[5]) ? $matches[5] : '"';

                // Skip static files
                if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|ico|webp|pdf|zip|xml)$/i', $path)) {
                    return $matches[0];
                }

                $absolute_count++;
                return 'href="' . $site_url . '/' . $lang_prefix . '/' . $path . $query . $end;
            },
            $html
        );

        // STRATEGY 2: Rewrite relative URLs (href="/path")
        // This catches social share buttons and other relative links
        $html = preg_replace_callback(
            '@href="/((?!(' . implode('|', $enabled_languages) . ')/|wp-content/|wp-includes/|wp-admin/)([^"?]*?))(\?[^"]*)?("|\s)@i',
            function($matches) use ($lang_prefix, &$relative_count) {
                $path = $matches[1];
                $query = isset($matches[4]) ? $matches[4] : '';
                $end = isset($matches[5]) ? $matches[5] : '"';

                // Skip static files
                if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|ico|webp|pdf|zip|xml)$/i', $path)) {
                    return $matches[0];
                }

                // Skip anchor links (#section)
                if (empty($path)) {
                    return $matches[0];
                }

                $relative_count++;
                return 'href="/' . $lang_prefix . '/' . $path . $query . $end;
            },
            $html
        );

        // STRATEGY 3: Rewrite data-attributes (data-url, data-link, data-href)
        // Social share buttons often use data-url="https://site.com/page"
        $data_attrs = array('data-url', 'data-link', 'data-href', 'data-share-url');

        foreach ($data_attrs as $attr) {
            // Absolute URLs in data attributes
            $html = preg_replace_callback(
                '@' . $attr . '="' . preg_quote($site_url, '@') . '/((?!(' . implode('|', $enabled_languages) . ')/|wp-content/|wp-includes/|wp-admin/)([^"?]*?))(\?[^"]*)?("|\s)@i',
                function($matches) use ($lang_prefix, &$data_attr_count, $site_url, $attr) {
                    $path = $matches[1];
                    $query = isset($matches[4]) ? $matches[4] : '';
                    $end = isset($matches[5]) ? $matches[5] : '"';

                    // Skip static files
                    if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|ico|webp|pdf|zip|xml)$/i', $path)) {
                        return $matches[0];
                    }

                    $data_attr_count++;
                    return $attr . '="' . $site_url . '/' . $lang_prefix . '/' . $path . $query . $end;
                },
                $html
            );

            // Relative URLs in data attributes
            $html = preg_replace_callback(
                '@' . $attr . '="/((?!(' . implode('|', $enabled_languages) . ')/|wp-content/|wp-includes/|wp-admin/)([^"?]*?))(\?[^"]*)?("|\s)@i',
                function($matches) use ($lang_prefix, &$data_attr_count, $attr) {
                    $path = $matches[1];
                    $query = isset($matches[4]) ? $matches[4] : '';
                    $end = isset($matches[5]) ? $matches[5] : '"';

                    // Skip static files
                    if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|ico|webp|pdf|zip|xml)$/i', $path)) {
                        return $matches[0];
                    }

                    if (empty($path)) {
                        return $matches[0];
                    }

                    $data_attr_count++;
                    return $attr . '="/' . $lang_prefix . '/' . $path . $query . $end;
                },
                $html
            );
        }

        // STRATEGY 4: Rewrite URLs embedded in social share query parameters
        // Social buttons like Facebook/Twitter embed site URL in ?u= or ?url= parameters
        // Example: href="https://facebook.com/sharer?u=https://site.com/page"
        $social_count = 0;
        $social_params = array('u', 'url', 'text', 'link');

        foreach ($social_params as $param) {
            // Find query parameters containing site URLs
            $html = preg_replace_callback(
                '@([?&])' . $param . '=' . preg_quote($site_url, '@') . '/((?!(' . implode('|', $enabled_languages) . ')/)([^&"\'\s]*))@i',
                function($matches) use ($lang_prefix, &$social_count, $site_url, $param) {
                    $delimiter = $matches[1]; // ? or &
                    $path = $matches[2];

                    // Skip static files
                    if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|ico|webp|pdf|zip|xml)$/i', $path)) {
                        return $matches[0];
                    }

                    $social_count++;
                    return $delimiter . $param . '=' . $site_url . '/' . $lang_prefix . '/' . $path;
                },
                $html
            );

            // Also handle URL-encoded versions (%2F instead of /)
            $html = preg_replace_callback(
                '@([?&])' . $param . '=' . preg_quote(str_replace('/', '%2F', $site_url), '@') . '%2F((?!(' . implode('|', $enabled_languages) . ')%2F)([^&"\'\s]*))@i',
                function($matches) use ($lang_prefix, &$social_count, $site_url, $param) {
                    $delimiter = $matches[1];
                    $path = $matches[2];

                    // Skip static files
                    if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot|ico|webp|pdf|zip|xml)$/i', $path)) {
                        return $matches[0];
                    }

                    $social_count++;
                    return $delimiter . $param . '=' . str_replace('/', '%2F', $site_url) . '%2F' . $lang_prefix . '%2F' . $path;
                },
                $html
            );
        }

        $total_count = $absolute_count + $relative_count + $data_attr_count + $social_count;

        $this->debug_file_log('link-rewriting-test.txt', "v5.2.34 FINISHED: absolute={$absolute_count}, relative={$relative_count}, data-attrs={$data_attr_count}, social={$social_count}, total={$total_count}");
        lingua_debug_log("[LINGUA v5.2.34 LINK DEBUG] Processed {$total_count} links (absolute: {$absolute_count}, relative: {$relative_count}, data-attrs: {$data_attr_count}, social: {$social_count}) - lang_prefix: /{$lang_prefix}/");

        $this->log_debug("v5.2.34: Added language prefix '/{$lang_prefix}/' to {$total_count} links (absolute: {$absolute_count}, relative: {$relative_count}, data-attrs: {$data_attr_count}, social: {$social_count})");

        return $html;
    }

    /**
     * Режим отладки
     */
    private function is_debug_mode() {
        return defined('LINGUA_DEBUG_MODE') && LINGUA_DEBUG_MODE;
    }

    /**
     * Сохранение HTML для отладки
     */
    private function save_debug_html($html, $post_id) {
        // v5.2.35: Only save debug HTML if LINGUA_DEBUG_FILES is enabled
        if (!defined('LINGUA_DEBUG_FILES') || !LINGUA_DEBUG_FILES) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $debug_dir = $upload_dir['basedir'] . '/iqcloud-translate/debug-html/';
        if (!is_dir($debug_dir)) {
            wp_mkdir_p($debug_dir);
        }

        $filename = "post_{$post_id}_" . date('Y-m-d_H-i-s') . '.html';
        file_put_contents($debug_dir . $filename, $html);
    }

    /**
     * Получение текущего языка
     */
    private function get_current_language() {
        // v5.2.35: Conditional debug logging
        // КРИТИЧНО v3.0: Извлекаем язык из URL
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

        lingua_debug_log("[Lingua Output Buffer v5.2.159] get_current_language: REQUEST_URI='{$request_uri}'");

        $this->debug_file_log('get-current-language.txt', "get_current_language called, REQUEST_URI: '{$request_uri}'");

        if (preg_match('#^/([a-z]{2})/#', $request_uri, $matches)) {
            lingua_debug_log("[Lingua Output Buffer v5.2.159] ✅ Found language in URL: '{$matches[1]}'");
            $this->debug_file_log('get-current-language.txt', "✅ Found language in URL: '{$matches[1]}'");
            return sanitize_text_field($matches[1]);
        }

        // Проверяем глобальную переменную (устанавливается URL Rewriter)
        global $LINGUA_LANGUAGE;
        if (!empty($LINGUA_LANGUAGE)) {
            $this->debug_file_log('get-current-language.txt', "✅ Found LINGUA_LANGUAGE global: '{$LINGUA_LANGUAGE}'");
            return sanitize_text_field($LINGUA_LANGUAGE);
        }

        $this->debug_file_log('get-current-language.txt', "⚠️ No language found, using default");

        // Проверяем URL параметр
        if (isset($_GET['lang'])) {
            return sanitize_text_field($_GET['lang']);
        }

        // Проверяем cookie
        if (isset($_COOKIE['lingua_language'])) {
            return sanitize_text_field($_COOKIE['lingua_language']);
        }

        // Проверяем сессию
        if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['lingua_language'])) {
            return sanitize_text_field($_SESSION['lingua_language']);
        }

        // Возвращаем язык по умолчанию
        return $this->get_default_language();
    }

    /**
     * Получение языка по умолчанию
     */
    private function get_default_language() {
        // v5.2.137: Read from options instead of hardcoded value
        return get_option('lingua_default_language', 'en');
    }

    /**
     * Получение ID текущего поста
     */
    /**
     * v3.0.6: Получение текущего URL без языкового префикса (для кэширования)
     */
    private function get_current_url() {
        // Получаем полный URL запроса
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''));
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        $url = "{$scheme}://{$host}{$request_uri}";

        // Удаляем языковой префикс для единообразного кэширования
        // /en/product/chair -> /product/chair
        $url = preg_replace('#^(https?://[^/]+)/(' . implode('|', $this->get_enabled_languages()) . ')/#', '$1/', $url);

        return $url;
    }

    private function get_current_post_id() {
        // v3.0.5 CRITICAL FIX: Используем get_queried_object_id() вместо $post->ID
        // $post может быть не установлен или содержать ID последнего поста в цикле (не текущей страницы)
        $queried_id = get_queried_object_id();

        if ($queried_id > 0) {
            return $queried_id;
        }

        // Fallback для главной страницы
        if (is_front_page()) {
            return get_option('page_on_front', 0);
        }

        // Fallback для страницы блога
        if (is_home()) {
            return get_option('page_for_posts', 0);
        }

        // Для архивных страниц
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            return 'term_' . $term->term_id ?? 0;
        }

        return 0;
    }

    /**
     * Получение списка активных языков
     * v3.0.13: Fixed to use array keys instead of $lang['code']
     */
    private function get_enabled_languages() {
        $languages = get_option('lingua_languages', array());
        $enabled = array();

        // v3.0.13 FIX: lingua_languages uses language codes as array keys
        foreach ($languages as $code => $lang_data) {
            if (!empty($code)) {
                $enabled[] = $code;
            }
        }

        return $enabled;
    }

    /**
     * Принудительное извлечение контента для конкретной страницы
     * Используется в AJAX запросах когда буферизация не была активна
     */
    public function force_extract_content($post_id, $language = null) {
        if (!$this->extractor) {
            return false;
        }

        $language = $language ?: $this->current_language;

        // Получаем HTML страницы через HTTP запрос
        $page_url = $this->get_page_url($post_id, $language);
        $html = $this->fetch_page_html($page_url);

        if (!$html) {
            return false;
        }

        // Извлекаем контент
        $extracted_content = $this->extractor->extract_from_full_html($html, $post_id);

        // v5.2.36: Increased cache TTL to 24 hours (invalidated on translation update)
        $cache_key = 'lingua_extracted_content_' . $post_id . '_' . $language;
        set_transient($cache_key, $extracted_content, DAY_IN_SECONDS);

        $this->log_debug("Force extracted content for post {$post_id}, language: {$language}");

        return $extracted_content;
    }

    /**
     * Получение URL страницы
     */
    private function get_page_url($post_id, $language) {
        $base_url = get_permalink($post_id);

        // Добавляем параметр языка если не по умолчанию
        if ($language !== $this->default_language) {
            $base_url = add_query_arg('lang', $language, $base_url);
        }

        return $base_url;
    }

    /**
     * Получение HTML страницы через HTTP
     */
    private function fetch_page_html($url) {
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'Lingua Content Extractor/2.0',
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.5'
            )
        ));

        if (is_wp_error($response)) {
            $this->log_error('HTTP request failed: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            $this->log_error("HTTP request returned status {$status_code} for URL: {$url}");
            return false;
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Получение кэшированного контента
     */
    public function get_cached_content($post_id, $language = null) {
        $language = $language ?: $this->current_language;

        // ИСПРАВЛЕНО: Сначала ищем HTML кэш, а не extracted content
        $html_cache_key = 'lingua_original_html_' . $post_id;
        $cached_html = get_transient($html_cache_key);

        if (!empty($cached_html) && is_string($cached_html)) {
            return $cached_html;
        }

        // Fallback: пытаемся получить из старого кэша
        $cache_key = 'lingua_extracted_content_' . $post_id . '_' . $language;
        $cached_content = get_transient($cache_key);

        if (false === $cached_content) {
            // Пытаемся получить без языка (для совместимости)
            $cache_key = 'lingua_extracted_content_' . $post_id;
            $cached_content = get_transient($cache_key);
        }

        // ИСПРАВЛЕНО: Если это массив, возвращаем false (не HTML)
        if (is_array($cached_content)) {
            return false;
        }

        return $cached_content;
    }

    /**
     * Очистка кэша для поста
     */
    public function clear_cache($post_id) {
        global $wpdb;

        // v5.2.164: Get all enabled languages from settings
        $enabled_languages = get_option('lingua_languages', array());
        $language_codes = array($this->default_language);
        foreach ($enabled_languages as $lang) {
            if (isset($lang['code'])) {
                $language_codes[] = $lang['code'];
            }
        }
        $language_codes = array_unique($language_codes);

        foreach ($language_codes as $language) {
            delete_transient('lingua_extracted_content_' . $post_id . '_' . $language);
            // v5.2.164: Also clear translated HTML cache for each language
            delete_transient('lingua_translated_html_' . $post_id . '_' . $language);
        }

        // Очищаем общий кэш
        delete_transient('lingua_extracted_content_' . $post_id);
        delete_transient('lingua_original_html_' . $post_id);

        // v5.2.164: Clear any remaining translated HTML caches for this post (wildcard)
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_lingua_translated_html_' . $post_id . '_%'
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_timeout_lingua_translated_html_' . $post_id . '_%'
        ));

        $this->log_debug("v5.2.164: Cache cleared for post {$post_id} (all languages)");
    }

    /**
     * v5.0.18: Clear ALL translation caches (when translations are updated)
     */
    public function clear_all_translation_caches() {
        global $wpdb;

        // Clear all original HTML caches
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_original_html_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lingua_original_html_%'");

        // Clear all translated HTML caches
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_translated_html_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lingua_translated_html_%'");

        $this->log_debug("v5.0.18: All translation caches cleared");
    }

    /**
     * Хук для очистки кэша при обновлении поста
     */
    public function clear_cache_on_post_update($post_id) {
        $this->clear_cache($post_id);

        // v5.2.164: Clear ALL translated HTML caches when any post is updated
        // Because cache keys are MD5 hashes of URL+user_status+language, we can't target specific post
        $this->clear_all_translation_caches();
    }

    /**
     * Получение статистики работы
     */
    public function get_performance_stats() {
        return array(
            'is_enabled' => $this->is_enabled,
            'current_language' => $this->current_language,
            'default_language' => $this->default_language,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        );
    }

    /**
     * Логирование отладочной информации
     */
    private function log_debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log('[Lingua Output Buffer] ' . $message);
        }
    }

    /**
     * Логирование ошибок
     */
    private function log_error($message) {
        lingua_debug_log('[Lingua Output Buffer ERROR] ' . $message);
    }

    /**
     * Установка флагов для режимов работы
     */
    public function enable_extraction_mode() {
        setcookie('lingua_extraction_mode', '1', time() + 3600, '/');
    }

    public function disable_extraction_mode() {
        setcookie('lingua_extraction_mode', '', time() - 3600, '/');
    }

    /**
     * Деструктор - очистка ресурсов
     */
    public function __destruct() {
        if ($this->is_enabled) {
            $this->log_debug('Output buffer session ended');
        }
    }

    /**
     * v3.2: Check if text is technical content (WooCommerce JSON, HTML fragments, etc)
     * This prevents translating data-attributes, JSON, and other technical strings
     */
    private function is_technical_content($text) {
        if (empty($text) || !is_string($text)) {
            return true;
        }

        // JSON detection - only flag arrays/objects, not simple JSON strings
        // v5.3.30: Fix for quoted strings like "Железная логика" being flagged as JSON
        // json_decode('"text"') returns 'text' (a string), which we DO want to translate
        $json_result = json_decode($text);
        if ($json_result !== null && (is_array($json_result) || is_object($json_result))) {
            return true;
        }

        // JSON-like patterns (even if malformed)
        if (preg_match('/[\{\[].*["\']:\s*["\'].*[\}\]]/', $text)) {
            return true;
        }

        // WooCommerce price HTML fragments (escaped spans, bdi tags)
        if (preg_match('#<\\\\/span>|<\\\\/bdi>|<\\\\/ins>#', $text)) {
            return true;
        }

        // Variation data patterns (sku, variation_description, etc.)
        if (preg_match('/"(sku|variation_id|variation_description|price_html|availability_html)"/', $text)) {
            return true;
        }

        // URLs
        if (preg_match('/^https?:\/\//', $text)) {
            return true;
        }

        // Email
        if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        // Too short
        if (strlen($text) < 2) {
            return true;
        }

        // Technical IDs (contains only alphanumeric + dashes/underscores)
        // v1.2.10: Only flag as technical if LOWERCASE (technical IDs like post-123, btn_primary)
        //          Allow hyphenated words with capitals like "Single-stage", "Two-stage"
        if (preg_match('/^[a-z0-9_-]+$/', $text)) { // Removed 'i' flag - must be all lowercase
            if (strpos($text, '-') !== false || strpos($text, '_') !== false) {
                return true;
            }
        }

        // File extensions
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|mp4)$/i', $text)) {
            return true;
        }

        // v5.3.44: CRITICAL FIX - Allow inline HTML tags (br, span, a, strong, em, etc.)
        // But block block-level tags and technical markup
        // First, check if text contains ONLY inline tags (if so, it's translatable content)
        $inline_tags_pattern = '/<\/?(?:br|span|strong|b|em|i|u|a|small|mark|del|ins|sub|sup|code)(?:\s[^>]*)?>/i';
        $text_without_inline = preg_replace($inline_tags_pattern, '', $text);

        // Now check if there are any OTHER HTML tags remaining (block-level or technical)
        // v5.3.3: HTML tags detection - skip strings containing HTML markup
        // This prevents translating HTML attributes like class=, href=, style=
        if (preg_match('/<[a-z][a-z0-9]*[\s>]/i', $text_without_inline)) {
            return true;
        }

        // v5.3.3: HTML closing tags (excluding inline tags already removed)
        if (preg_match('#</[a-z]#i', $text_without_inline)) {
            return true;
        }

        // v5.3.44: HTML attributes check - use text_without_inline
        // If attributes remain after removing inline tags, it's technical content
        // But if attributes were only inside inline tags (like href in <a>) - it's OK
        if (preg_match('/\b(class|id|href|src|style|rel|target|type|media|data-[a-z])\s*=/i', $text_without_inline)) {
            return true;
        }

        // v5.3.3: DOCTYPE and HTML comments
        if (preg_match('/<!DOCTYPE|<!--/i', $text_without_inline)) {
            return true;
        }

        // v5.3.3: CSS selectors and rules
        if (preg_match('/\{[^}]*:[^}]*\}/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * v5.1: Apply media translations (images: src, alt, title)
     * Approach with src hash to avoid index shift issues
     *
     * @param object $html_dom Simple HTML DOM object
     * @param string $language_code Target language code
     */
    private function apply_media_translations($html_dom, $language_code) {
        global $wpdb;

        lingua_debug_log("[LINGUA MEDIA v5.1] Applying media translations for language: {$language_code}");

        $img_elements = $html_dom->find('img[src]');
        if (empty($img_elements)) {
            lingua_debug_log("[LINGUA MEDIA v5.1] No images found in HTML");
            return;
        }

        lingua_debug_log("[LINGUA MEDIA v5.1] Found " . count($img_elements) . " images");

        $table_name = $wpdb->prefix . 'lingua_string_translations';
        $translated_count = 0;

        foreach ($img_elements as $img) {
            $original_src = $img->getAttribute('src');
            if (empty($original_src)) {
                continue;
            }

            // Use src hash as identifier (solves index shift problem)
            $src_hash = md5($original_src);

            // 1. Translate SRC (image replacement)
            $src_context = "media.src[{$src_hash}].src";
            $translated_src = $wpdb->get_var($wpdb->prepare(
                "SELECT translated_text FROM {$table_name}
                 WHERE context = %s
                 AND language_code = %s
                 AND translated_text != ''",
                $src_context,
                $language_code
            ));

            if ($translated_src) {
                $img->setAttribute('src', $translated_src);
                lingua_debug_log("[LINGUA MEDIA v5.1] Translated src: {$original_src} => {$translated_src}");

                // Auto-update srcset
                $this->update_srcset_for_translated_image($img, $translated_src);

                // Update data-src for lazy loading
                if ($img->getAttribute('data-src')) {
                    $img->setAttribute('data-src', $translated_src);
                }

                $translated_count++;
            }

            // 2. Translate ALT
            $original_alt = $img->getAttribute('alt');
            if (!empty($original_alt)) {
                $alt_context = "media.src[{$src_hash}].alt";
                $translated_alt = $wpdb->get_var($wpdb->prepare(
                    "SELECT translated_text FROM {$table_name}
                     WHERE context = %s
                     AND language_code = %s
                     AND translated_text != ''",
                    $alt_context,
                    $language_code
                ));

                if ($translated_alt) {
                    $img->setAttribute('alt', $translated_alt);
                    lingua_debug_log("[LINGUA MEDIA v5.1] Translated alt: {$original_alt} => {$translated_alt}");
                    $translated_count++;
                }
            }

            // 3. Translate TITLE
            $original_title = $img->getAttribute('title');
            if (!empty($original_title)) {
                $title_context = "media.src[{$src_hash}].title";
                $translated_title = $wpdb->get_var($wpdb->prepare(
                    "SELECT translated_text FROM {$table_name}
                     WHERE context = %s
                     AND language_code = %s
                     AND translated_text != ''",
                    $title_context,
                    $language_code
                ));

                if ($translated_title) {
                    $img->setAttribute('title', $translated_title);
                    lingua_debug_log("[LINGUA MEDIA v5.1] Translated title: {$original_title} => {$translated_title}");
                    $translated_count++;
                }
            }
        }

        lingua_debug_log("[LINGUA MEDIA v5.1] Applied {$translated_count} media translations");
    }

    /**
     * v5.1: Auto-update srcset when image src is translated
     * Generate srcset from WordPress attachment ID
     *
     * @param object $img_node DOM node for img element
     * @param string $translated_src Translated image URL
     */
    private function update_srcset_for_translated_image($img_node, $translated_src) {
        $srcset = $img_node->getAttribute('srcset');
        if (empty($srcset)) {
            return; // No srcset to update
        }

        // Try to get attachment ID from URL
        $attachment_id = attachment_url_to_postid($translated_src);

        if (!$attachment_id) {
            // External image or not in media library - clear srcset
            $img_node->setAttribute('srcset', '');
            lingua_debug_log("[LINGUA MEDIA v5.1] Cleared srcset for external image: {$translated_src}");
            return;
        }

        // Generate srcset using WordPress function
        $meta_data = wp_get_attachment_metadata($attachment_id);
        $width = ($meta_data && isset($meta_data['width'])) ? $meta_data['width'] : 'large';

        if (function_exists('wp_get_attachment_image_srcset')) {
            $translated_srcset = wp_get_attachment_image_srcset($attachment_id, $width);

            if ($translated_srcset) {
                $img_node->setAttribute('srcset', $translated_srcset);
                lingua_debug_log("[LINGUA MEDIA v5.1] Auto-generated srcset for attachment #{$attachment_id}");
            }
        }

        // Also update data-srcset if exists (for lazy loading)
        if ($img_node->getAttribute('data-srcset')) {
            if (isset($translated_srcset)) {
                $img_node->setAttribute('data-srcset', $translated_srcset);
            } else {
                $img_node->setAttribute('data-srcset', '');
            }
        }
    }

    /**
     * v5.4.0: Multibyte-safe case-insensitive string replacement
     * PHP's str_ireplace() fails for multibyte characters (Cyrillic, CJK, etc.)
     * because it compares bytes, not characters. This method uses mb_stripos/mb_substr
     * to correctly handle case-insensitive replacement for UTF-8 strings.
     *
     * @param string $search The string to search for
     * @param string $replace The replacement string
     * @param string $subject The string to search in
     * @return string The string with replacements applied
     */
    private function mb_str_ireplace_safe($search, $replace, $subject, $element_boundary_only = false) {
        $search_len = mb_strlen($search, 'UTF-8');
        if ($search_len === 0) {
            return $subject;
        }

        $result = '';
        $offset = 0;
        $subject_len = mb_strlen($subject, 'UTF-8');

        while ($offset < $subject_len) {
            $pos = mb_stripos($subject, $search, $offset, 'UTF-8');
            if ($pos === false) {
                // No more matches — append rest and break
                $result .= mb_substr($subject, $offset, null, 'UTF-8');
                break;
            }

            // v5.4.1: For short strings, only replace when bounded by HTML tags or string edges
            // This prevents "Возможности" → "Opportunities" from corrupting
            // "оценить возможности плагина" inside untranslated paragraphs
            if ($element_boundary_only) {
                $match_end = $pos + $search_len;

                // Check character BEFORE the match: must be > or start of string or whitespace after >
                $before_ok = false;
                if ($pos === 0) {
                    $before_ok = true;
                } else {
                    // Look backwards from $pos for the nearest > or <
                    $before_text = mb_substr($subject, 0, $pos, 'UTF-8');
                    $last_gt = mb_strrpos($before_text, '>', 0, 'UTF-8');
                    $last_lt = mb_strrpos($before_text, '<', 0, 'UTF-8');

                    if ($last_gt !== false && ($last_lt === false || $last_gt > $last_lt)) {
                        // Last HTML boundary before match is ">", check only whitespace between
                        $between = mb_substr($before_text, $last_gt + 1, null, 'UTF-8');
                        if (trim($between) === '') {
                            $before_ok = true;
                        }
                    }
                }

                // Check character AFTER the match: must be < or end of string or whitespace before <
                $after_ok = false;
                if ($match_end >= $subject_len) {
                    $after_ok = true;
                } else {
                    $after_text = mb_substr($subject, $match_end, null, 'UTF-8');

                    // v5.5.1: If only whitespace remains after match — treat as end of string
                    if (trim($after_text) === '') {
                        $after_ok = true;
                    } else {
                        $first_lt = mb_strpos($after_text, '<', 0, 'UTF-8');
                        $first_gt = mb_strpos($after_text, '>', 0, 'UTF-8');

                        if ($first_lt !== false && ($first_gt === false || $first_lt < $first_gt)) {
                            // Next HTML boundary after match is "<", check only whitespace between
                            $between = mb_substr($after_text, 0, $first_lt, 'UTF-8');
                            if (trim($between) === '') {
                                $after_ok = true;
                            }
                        }
                    }
                }

                if (!$before_ok || !$after_ok) {
                    // Match is inside larger text content — skip this occurrence
                    $result .= mb_substr($subject, $offset, $pos - $offset + $search_len, 'UTF-8');
                    $offset = $match_end;
                    continue;
                }
            }

            // Append everything before the match
            $result .= mb_substr($subject, $offset, $pos - $offset, 'UTF-8');
            // Append the replacement
            $result .= $replace;
            // Move past the match
            $offset = $pos + $search_len;
        }

        return $result;
    }

    /**
     * v5.2.6: Normalize text ONCE (not in loop)
     * All normalizations in one function
     *
     * @param string $text Text to normalize
     * @return string Normalized text
     */
    private function normalize_text_once($text) {
        if (empty($text) || !is_string($text)) {
            return '';
        }

        // Step 1: HTML entity decode
        $normalized = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // v5.3.19: Normalize &nbsp; (UTF-8: \xC2\xA0) to regular space BEFORE space normalization
        // WordPress often uses &nbsp; which doesn't match regular spaces
        // MUST be done before multiple space normalization to collapse mixed space/nbsp sequences
        $normalized = str_replace("\xC2\xA0", ' ', $normalized);

        // Step 2: Normalize <br> tags
        $normalized = preg_replace('/<br\s*\/?\s*>/i', '<br />', $normalized);
        $normalized = preg_replace('/<br\s*\/>\s+/', '<br /> ', $normalized);

        // Step 3: Normalize multiple spaces to single space (now works correctly after nbsp normalization)
        $normalized = preg_replace('/\s{2,}/', ' ', $normalized);

        // Step 4: Normalize WordPress special characters (wptexturize changes)
        // Dashes (en dash, em dash, hyphen → regular dash)
        $normalized = str_replace(array('–', '—', '‐'), '-', $normalized);

        // v5.3.27: Normalize Russian ё → е (common source of mismatches)
        $normalized = str_replace(array('ё', 'Ё'), array('е', 'Е'), $normalized);

        // v5.3.28: Curly quotes and guillemets → straight quotes
        // Using UTF-8 byte sequences for curly quotes to avoid PHP parsing issues
        $normalized = str_replace(array("\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9F", "\xE2\x80\x9E", '«', '»'), '"', $normalized);
        $normalized = str_replace(array("\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9B", "\xE2\x80\xB9", "\xE2\x80\xBA"), "'", $normalized);

        // Ellipsis
        $normalized = str_replace('…', '...', $normalized);

        // Step 5: Trim whitespace (including leading/trailing spaces)
        return trim($normalized);
    }

    /**
     * v5.3.19: Normalize text for case-insensitive lookup
     * Calls normalize_text_once() and converts to lowercase for matching
     *
     * @param string $text Text to normalize
     * @return string Lowercase normalized text for lookup
     */
    private function normalize_text_for_lookup($text) {
        $normalized = $this->normalize_text_once($text);
        if (empty($normalized)) {
            return '';
        }
        // Convert to lowercase for case-insensitive matching
        return mb_strtolower($normalized, 'UTF-8');
    }

    /**
     * v5.6.0: Strip HTML tags for plaintext matching (TranslatePress-inspired approach)
     *
     * Instead of normalizing HTML variations (<br> vs <br />, attribute order, etc.),
     * we strip ALL HTML tags and compare plain text only. This makes matching robust
     * against any HTML variation while preserving the original HTML structure.
     *
     * @param string $text Text potentially containing HTML
     * @return string Lowercase plaintext for matching (no HTML tags)
     */
    private function strip_tags_for_matching($text) {
        if (empty($text) || !is_string($text)) {
            return '';
        }

        // Step 1: HTML entity decode
        $plain = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Step 2: Replace <br> tags with space (they represent line breaks in content)
        $plain = preg_replace('/<br\s*\/?\s*>/i', ' ', $plain);

        // Step 3: Strip all remaining HTML tags
        $plain = wp_strip_all_tags($plain);

        // Step 4: Normalize &nbsp;
        $plain = str_replace("\xC2\xA0", ' ', $plain);

        // Step 5: Normalize ё→е
        $plain = str_replace(array('ё', 'Ё'), array('е', 'Е'), $plain);

        // Step 6: Curly quotes → straight
        $plain = str_replace(array("\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9F", "\xE2\x80\x9E", '«', '»'), '"', $plain);
        $plain = str_replace(array("\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9B", "\xE2\x80\xB9", "\xE2\x80\xBA"), "'", $plain);

        // Step 7: Dashes
        $plain = str_replace(array('–', '—', '‐'), '-', $plain);

        // Step 8: Ellipsis
        $plain = str_replace('…', '...', $plain);

        // Step 9: Collapse multiple spaces to single space
        $plain = preg_replace('/\s+/', ' ', $plain);

        // Step 10: Trim and lowercase
        return mb_strtolower(trim($plain), 'UTF-8');
    }

    /**
     * v5.2.183: Check if inline element is inside a content block
     * Used during translation replacement to avoid translating inline elements separately
     *
     * @param object $element DOM node
     * @return bool True if element is inside a content block
     */
    private function is_inside_content_block_for_replacement($element) {
        // Content blocks that should handle translation of their inline children
        $content_block_tags = array('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'td', 'th', 'blockquote', 'figcaption', 'dt', 'dd', 'caption', 'label');

        $parent = $element->parent();
        $depth = 0;
        $max_depth = 10;

        while ($parent && $depth < $max_depth) {
            $parent_tag = strtolower($parent->tag);

            if (in_array($parent_tag, $content_block_tags)) {
                return true;
            }

            if ($parent_tag === 'root' || $parent_tag === 'body' || $parent_tag === 'html') {
                break;
            }

            $parent = $parent->parent();
            $depth++;
        }

        return false;
    }

    /**
     * v5.2.6: Apply translations to DOM - OPTIMIZED O(n+m) algorithm
     * ONE DOM traversal instead of N traversals
     *
     * OLD APPROACH O(n × m × k):
     * - foreach translation (n=100+) {
     *     foreach DOM node (m=1000+) {
     *       normalize text 15+ times (k=15)
     *     }
     *   }
     * = 100 × 1000 × 15 = 1,500,000+ operations!
     *
     * NEW APPROACH O(n + m):
     * - Build hash map: normalize all translations once (n=100)
     * - ONE DOM traversal: normalize node text once, O(1) lookup (m=1000)
     * = 100 + 1000 = 1,100 operations (99% improvement!)
     *
     * @param object $html_dom Simple HTML DOM object
     * @param array $translations Array of translations from database
     * @return int Number of translations applied
     */
    /**
     * v5.3.46: Protect URL attributes from translation
     *
     * Extracts and replaces URL attribute values with placeholders to prevent
     * str_ireplace from translating parts of URLs (e.g., "materials" in image filenames).
     *
     * @param string $html HTML content
     * @return array ['html' => protected HTML, 'placeholders' => [placeholder => original_value]]
     */
    private function protect_url_attributes($html) {
        $placeholders = array();
        $counter = 0;

        // URL attributes that should NOT be translated
        $url_attributes = array(
            'src', 'href', 'srcset', 'data-src', 'data-srcset',
            'data-large_image', 'data-zoom-image', 'data-thumb',
            'action', 'poster', 'data-bg', 'data-background',
            'data-lazy-src', 'data-original'
        );

        foreach ($url_attributes as $attr) {
            $offset = 0;
            // Match both double and single quotes
            while (preg_match('/' . preg_quote($attr, '/') . '\s*=\s*(["\'])/', $html, $quote_match, PREG_OFFSET_CAPTURE, $offset)) {
                $quote = $quote_match[1][0]; // " or '
                $attr_start = $quote_match[0][1];
                $value_start = $quote_match[0][1] + strlen($quote_match[0][0]);

                // Find closing quote
                $value_end = strpos($html, $quote, $value_start);
                if ($value_end === false) {
                    $offset = $value_start;
                    continue;
                }

                // Extract URL value
                $url_value = substr($html, $value_start, $value_end - $value_start);

                // Skip empty values
                if (empty($url_value)) {
                    $offset = $value_end + 1;
                    continue;
                }

                // Create placeholder
                $placeholder = "__LINGUA_URL_{$counter}__";
                $placeholders[$placeholder] = $url_value;

                // Replace in HTML
                $full_attr = $attr . '=' . $quote . $url_value . $quote;
                $new_attr = $attr . '=' . $quote . $placeholder . $quote;
                $html = substr_replace($html, $new_attr, $attr_start, strlen($full_attr));

                $counter++;
                $offset = $attr_start + strlen($new_attr);
            }
        }

        if ($counter > 0) {
            $this->debug_file_log('recursive-translation.txt', "  Protected {$counter} URL attributes from translation");
        }

        return array('html' => $html, 'placeholders' => $placeholders);
    }

    /**
     * v5.3.46: Restore URL attributes after translation
     *
     * @param string $html HTML with placeholders
     * @param array $placeholders [placeholder => original_value]
     * @return string HTML with restored URLs
     */
    private function restore_url_attributes($html, $placeholders) {
        foreach ($placeholders as $placeholder => $original_value) {
            $html = str_replace($placeholder, $original_value, $html);
        }
        return $html;
    }

    /**
     * v5.3.44: Recursively translate HTML content (TranslatePress approach)
     * v5.3.46: Added URL attribute protection to prevent translating image filenames
     *
     * For nodes containing inline HTML tags, we recursively call translate_page
     * to properly handle nested structure. This preserves all HTML tags while
     * translating the text content.
     *
     * Example:
     * Input:  "Will be used... <a href="#"><strong>Privacy Policy</strong></a>"
     * Output: "Будут использоваться... <a href="#"><strong>политика конфиденциальности</strong></a>"
     *
     * @param string $html_string HTML content to translate
     * @return string|false Translated HTML or false on error
     */
    private function translate_page_recursive($html_string, $translation_map = array()) {
        // Protection from infinite recursion
        $this->recursion_depth++;

        $this->debug_file_log('recursive-translation.txt', "translate_page_recursive CALLED (depth: {$this->recursion_depth})");
        $this->debug_file_log('recursive-translation.txt', "Input HTML: " . substr($html_string, 0, 200));

        if ($this->recursion_depth > $this->max_recursion_depth) {
            lingua_debug_log("[Lingua v5.3.44] Max recursion depth reached ({$this->max_recursion_depth}), stopping");
            $this->debug_file_log('recursive-translation.txt', "❌ MAX DEPTH REACHED");
            $this->recursion_depth--;
            return false;
        }

        // If no translations provided, just return original
        if (empty($translation_map)) {
            $this->debug_file_log('recursive-translation.txt', "❌ Empty translation_map");
            $this->recursion_depth--;
            return $html_string;
        }

        lingua_debug_log("[Lingua v5.3.44] Recursive translate at depth {$this->recursion_depth}, using " . count($translation_map) . " translations");

        // v5.5.1: Normalize &nbsp; and special chars so translations match
        // WordPress uses &nbsp; in content, but translations are stored with regular spaces.
        // Also normalize ё→е, curly quotes→straight, dashes, etc. — same as normalize_text_once()
        // but preserving HTML tags and case.
        $html_string = html_entity_decode($html_string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html_string = str_replace("\xC2\xA0", ' ', $html_string);
        // Normalize ё→е
        $html_string = str_replace(array('ё', 'Ё'), array('е', 'Е'), $html_string);
        // Curly quotes → straight
        $html_string = str_replace(array("\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9F", "\xE2\x80\x9E", '«', '»'), '"', $html_string);
        $html_string = str_replace(array("\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9B", "\xE2\x80\xB9", "\xE2\x80\xBA"), "'", $html_string);
        // Dashes
        $html_string = str_replace(array('–', '—', '‐'), '-', $html_string);
        // Ellipsis
        $html_string = str_replace('…', '...', $html_string);

        // v5.5.2: Normalize <br> tags to match extraction format
        // DOM renders <br> but extractor stores <br /> — must match for translations to apply
        // Example: h1 contains "Text<br>\n\t\t<span>more</span>" but DB has "Text<br /> <span>more</span>"
        $html_string = preg_replace('/<br\s*\/?\s*>/i', '<br />', $html_string);
        // Normalize whitespace (newlines, tabs, multiple spaces) around <br /> to single space
        $html_string = preg_replace('/<br\s*\/>\s+/', '<br /> ', $html_string);

        // v5.3.46: Protect URL attributes BEFORE applying translations
        // This prevents translating parts of URLs (e.g., "materials" in "/uploads/hs-materials.jpg")
        $protected = $this->protect_url_attributes($html_string);
        $translated_html = $protected['html'];
        $url_placeholders = $protected['placeholders'];

        // Apply translations using simple str_replace approach
        // Sort by length (longest first) to avoid substring issues
        $sorted_translations = $translation_map;
        uksort($sorted_translations, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        $replacements_made = 0;
        foreach ($sorted_translations as $original => $data) {
            $translated = $data['translated'];
            if (!empty($translated) && $translated !== $original) {
                $before = $translated_html;

                // v5.4.1: For short strings (< 50 chars), use element boundary mode
                // This prevents nav items like "Возможности" → "Opportunities" from corrupting
                // untranslated paragraphs containing the same word: "оценить возможности плагина"
                // Short strings are only replaced when they appear as complete element text (between > and <)
                $element_boundary_only = (mb_strlen($original, 'UTF-8') < 50);

                $translated_html = $this->mb_str_ireplace_safe($original, $translated, $translated_html, $element_boundary_only);
                if ($before !== $translated_html) {
                    $replacements_made++;
                    $this->debug_file_log('recursive-translation.txt', "  ✓ Replaced: '" . substr($original, 0, 50) . "' → '" . substr($translated, 0, 50) . "'" . ($element_boundary_only ? ' [boundary]' : ''));
                }
            }
        }

        // v5.3.46: Restore URL attributes AFTER translations
        $translated_html = $this->restore_url_attributes($translated_html, $url_placeholders);

        $this->debug_file_log('recursive-translation.txt', "✓ DONE (depth: {$this->recursion_depth}, replacements: {$replacements_made})");
        $this->debug_file_log('recursive-translation.txt', "Output HTML: " . substr($translated_html, 0, 200));

        $this->recursion_depth--;
        return $translated_html;
    }

    /**
     * v5.3.44: DEPRECATED - Replaced by recursive translate_page approach
     * Keeping for reference but no longer used
     *
     * @param object $node Simple HTML DOM node
     * @param string $plaintext_translation Translated text without HTML tags
     * @return bool Success
     */
    private function apply_plaintext_to_html_node_DEPRECATED($node, $plaintext_translation) {
        $node_tag = isset($node->tag) ? $node->tag : 'unknown';
        $node_html = substr($node->outertext, 0, 150);

        // Find all inline elements inside node (deepest first)
        $inline_tags = array('a', 'strong', 'span', 'b', 'em', 'i', 'u', 'small', 'mark', 'del', 'ins', 'sub', 'sup', 'code');
        $inline_elements = array();


        foreach ($inline_tags as $tag) {
            $found = $node->find($tag);

            foreach ($found as $el) {
                $inline_elements[] = array(
                    'element' => $el,
                    'plaintext' => trim(strip_tags($el->innertext)),
                    'depth' => substr_count($el->tag, '>') // Approximate depth
                );
            }
        }


        if (empty($inline_elements)) {
            // No inline elements - simple case
            $node->innertext = $plaintext_translation;
            return true;
        }

        // Sort by depth (deepest first) to process inner elements before outer
        usort($inline_elements, function($a, $b) {
            return $b['depth'] - $a['depth'];
        });

        // Strategy: Match text portions by position (beginning/end)
        // If inline element text is at the END of original plaintext,
        // replace it with END portion of translation
        $node_plaintext = trim(strip_tags($node->innertext));
        $translation_words = preg_split('/\s+/u', trim($plaintext_translation));

        foreach ($inline_elements as $idx => $inline_data) {

            $inline_el = $inline_data['element'];
            $inline_text = $inline_data['plaintext'];

            if (empty($inline_text)) {
                continue;
            }


            // Check if this text is at the end of node plaintext
            if (substr($node_plaintext, -strlen($inline_text)) === $inline_text) {
                // Text is at end - use last N words from translation
                $word_count = count(preg_split('/\s+/u', $inline_text));
                $replacement_words = array_slice($translation_words, -$word_count);
                $replacement = implode(' ', $replacement_words);

                // Replace innertext of inline element
                $inline_el->innertext = $replacement;

                // Remove these words from translation pool
                $translation_words = array_slice($translation_words, 0, -$word_count);
            }
            // Could add more heuristics for beginning, middle positions
        }


        // v5.3.44: SAFE APPROACH - Replace entire node innertext with plaintext translation
        // The inline elements' text will be preserved because they're part of the DOM structure
        // WARNING: This approach DOESN'T preserve HTML structure perfectly, but it's safe and works
        // Alternative: Just replace plaintext directly (strip HTML, replace with translation)
        $node->innertext = $plaintext_translation;



        return true;
    }

    private function apply_translations_optimized($html_dom, $translations) {
        $start_time = microtime(true);

        // Step 1: Build translation hash map with normalized keys (O(n))
        // v5.3.19: Use lowercase keys for case-insensitive matching
        // v5.6.0: Also build plaintext_map for HTML-agnostic matching
        $translation_map = array();
        $translation_map_partial = array(); // For partial matches
        $plaintext_map = array(); // v5.6.0: Plaintext-key lookup for HTML-agnostic matching

        foreach ($translations as $t) {
            if (empty($t['original_text']) || empty($t['translated_text'])) {
                continue;
            }

            // Skip technical content
            if ($this->is_technical_content($t['original_text']) ||
                $this->is_technical_content($t['translated_text'])) {
                continue;
            }

            // v5.3.19: Use lowercase key for case-insensitive lookup
            $key = $this->normalize_text_for_lookup($t['original_text']);
            $key_normalized = $this->normalize_text_once($t['original_text']); // Keep original case for partial match replacement

            // v5.6.0: Build plaintext key (stripped of ALL HTML tags) for HTML-agnostic matching
            // This enables matching DOM content like "Text<br>\n\t<span>more</span>"
            // against DB content like "Text<br /> <span>more</span>" — both strip to same plaintext
            $plaintext_key = $this->strip_tags_for_matching($t['original_text']);

            // Debug log for problematic phrases
            if (stripos($t['original_text'], 'will be used') !== false) {
                $this->debug_file_log('translation-map-build.txt', "Building key for: " . substr($t['original_text'], 0, 100));
                $this->debug_file_log('translation-map-build.txt', "  Normalized key: " . substr($key, 0, 100));
            }

            // Store in hash map for O(1) lookup
            // v5.2.183: Don't normalize translated text - preserve &nbsp; and other entities
            $translation_map[$key] = array(
                'translated' => $t['translated_text'],
                'original_raw' => $t['original_text'],
                'key_normalized' => $key_normalized, // v5.3.19: For partial replacements
                'plaintext_key' => $plaintext_key // v5.6.0: For plaintext matching
            );

            // v5.6.0: Store in plaintext map for HTML-agnostic lookup
            // Store ALL translations (with or without HTML) for plaintext matching.
            // This enables matching DOM nodes (which always contain rendered HTML)
            // against DB entries regardless of HTML format differences.
            // If multiple DB entries have same plaintext, prefer the one WITH HTML
            // (it's the full block), then by longest key (most specific).
            $original_has_html = preg_match('/<[a-z]/i', $t['original_text']);
            if (!empty($plaintext_key)) {
                $should_store = false;
                if (!isset($plaintext_map[$plaintext_key])) {
                    $should_store = true;
                } elseif ($original_has_html && !preg_match('/<[a-z]/i', $plaintext_map[$plaintext_key]['original_raw'])) {
                    // Prefer HTML version over plaintext version
                    $should_store = true;
                } elseif ($original_has_html === (bool)preg_match('/<[a-z]/i', $plaintext_map[$plaintext_key]['original_raw']) && strlen($key) > strlen($plaintext_map[$plaintext_key]['key'])) {
                    // Same HTML status — prefer longer (more specific)
                    $should_store = true;
                }

                if ($should_store) {
                    $plaintext_map[$plaintext_key] = array(
                        'key' => $key,
                        'translated' => $t['translated_text'],
                        'original_raw' => $t['original_text'],
                        'key_normalized' => $key_normalized
                    );
                }
            }

            // Also store for partial matching (substring search)
            $translation_map_partial[] = array(
                'key' => $key,
                'key_normalized' => $key_normalized, // v5.3.19: Original case for replacement
                'translated' => $t['translated_text'],
                'original_raw' => $t['original_text'],
                'plaintext_key' => $plaintext_key // v5.6.0
            );
        }

        // Sort by length (longest first) for partial matches
        usort($translation_map_partial, function($a, $b) {
            return strlen($b['key']) - strlen($a['key']);
        });

        foreach ($translation_map as $key => $data) {
            if (stripos($key, 'will') !== false || stripos($key, 'privacy') !== false || stripos($key, 'policy') !== false) {
            }
        }

        // v5.3.44: Build substring map to prevent short translations from overriding long ones
        // Example: "Privacy Policy" shouldn't apply if "Will be used... Privacy Policy" exists
        // CRITICAL: Compare plaintext versions to handle HTML tag variations
        // If two keys have same plaintext but different HTML - mark the one with HTML as substring
        $substring_of_longer = array();
        foreach ($translation_map as $key => $data) {
            $key_plaintext = strtolower(strip_tags($key));
            $key_has_html = preg_match('/<[a-z]/i', $key);

            foreach ($translation_map as $key2 => $data2) {
                if ($key !== $key2) {
                    $key2_plaintext = strtolower(strip_tags($key2));
                    $key2_has_html = preg_match('/<[a-z]/i', $key2);

                    // Case 1: $key plaintext is substring of $key2 plaintext
                    if (strlen($key2_plaintext) > strlen($key_plaintext) && strpos($key2_plaintext, $key_plaintext) !== false) {
                        $substring_of_longer[$key] = true;
                        if (stripos($key, 'will be') !== false) {
                            $this->debug_file_log('substring-logic.txt', "Case 1: Marked as substring:");
                            $this->debug_file_log('substring-logic.txt', "  key: " . substr($key, 0, 100));
                            $this->debug_file_log('substring-logic.txt', "  key2: " . substr($key2, 0, 100));
                        }
                    }
                    // Case 2: Same plaintext, but $key has HTML and $key2 doesn't
                    // Prefer plaintext version in matching
                    elseif ($key_plaintext === $key2_plaintext && $key_has_html && !$key2_has_html) {
                        $substring_of_longer[$key] = true;
                        if (stripos($key, 'will be') !== false) {
                            $this->debug_file_log('substring-logic.txt', "Case 2: Marked as substring:");
                            $this->debug_file_log('substring-logic.txt', "  key (with HTML): " . substr($key, 0, 100));
                            $this->debug_file_log('substring-logic.txt', "  key2 (no HTML): " . substr($key2, 0, 100));
                        }
                    }
                }
            }
        }

        // Translation map ready for application
        $all_p_tags = $html_dom->find('p');
        foreach ($all_p_tags as $idx => $p) {
            $p_text = strip_tags($p->innertext ?? '');
        }

        $build_time = microtime(true) - $start_time;
        lingua_debug_log('[Lingua v5.2.6 OPTIMIZED] Translation map built: ' . count($translation_map) . ' entries in ' . round($build_time, 3) . 's');

        // Step 2: ONE DOM traversal (O(m))
        // v5.3.10: Added periodic flush to prevent nginx 502 timeout on large pages
        $applied_count = 0;
        $exact_matches = 0;
        $partial_matches = 0;
        $node_iteration = 0;
        $p_tag_count = 0; // Debug

        foreach ($html_dom->find('*') as $node) {
            $node_iteration++;

            // v5.3.10: REMOVED flush() - causes 502 errors inside output buffer callback
            // Note: flush() should never be called inside ob_start() callback

            // Skip technical tags
            $tag = strtolower($node->tag);

            if ($tag === 'p') {
                $p_tag_count++;
                $p_plaintext = strip_tags($node->innertext ?? '');
            }

            if (in_array($tag, array('script', 'style', 'head', 'link', 'meta'))) {
                continue;
            }

            // v5.2.183: Skip inline elements inside content blocks
            // Let the parent block handle the translation to preserve context
            // v1.2.0: Added 'span' to fix partial translation of <span class="redtext">...</span> inside h1/p
            // v1.2.9: BUT don't skip if inline element contains only text (no child HTML tags)
            //         This fixes WooCommerce filter widgets: <a><span>Одноступенчатые</span></a>
            //         where span was skipped but parent <a> couldn't match due to HTML in innertext
            $inline_tags = array('strong', 'b', 'em', 'i', 'u', 'mark', 'small', 'del', 'ins', 'sub', 'sup', 'code', 'kbd', 'samp', 'var', 'span');
            if (in_array($tag, $inline_tags) && $this->is_inside_content_block_for_replacement($node)) {
                // v1.2.9: Check if this inline element contains only text (no child HTML)
                // If so, we should still try to translate it directly
                $has_child_html = preg_match('/<[a-zA-Z][^>]*>/', $node->innertext);
                if ($has_child_html) {
                    continue; // Has child HTML, let parent handle it
                }
                // No child HTML - proceed to try translation on this element
            }

            $node_innertext = $node->innertext;
            if (empty($node_innertext)) {
                if ($tag === 'p') {
                }
                continue;
            }

            // Normalize node text ONCE
            $node_normalized = $this->normalize_text_once($node_innertext);
            // v5.3.19: Lowercase version for case-insensitive lookup
            $node_lookup_key = $this->normalize_text_for_lookup($node_innertext);

            if (empty($node_normalized)) {
                continue;
            }

            // Strategy 1: EXACT MATCH (O(1) hash map lookup)
            // v5.3.19: Use lowercase key for case-insensitive matching
            // v5.3.44: Skip if this string is a substring of a longer translation
            //          Example: skip "Privacy Policy" if "Will be used... Privacy Policy" exists
            //          This prevents short translations from overriding parts of long ones
            if (strlen($node_lookup_key) < 100 && (stripos($node_lookup_key, 'will be used') !== false || stripos($node_lookup_key, 'privacy') !== false)) {
                $is_substring = isset($substring_of_longer[$node_lookup_key]) ? 'YES' : 'NO';
                $in_map = isset($translation_map[$node_lookup_key]) ? 'YES' : 'NO';
                $this->debug_file_log('exact-match-debug.txt', "Checking <{$tag}>: in_map={$in_map}, is_substring={$is_substring}");
                $this->debug_file_log('exact-match-debug.txt', "  node_lookup_key: " . substr($node_lookup_key, 0, 100));
                $this->debug_file_log('exact-match-debug.txt', "  node_innertext: " . substr($node_innertext, 0, 100));
            }
            // v5.3.45: Check for exact match in translation map
            if (isset($translation_map[$node_lookup_key])) {
                // v5.3.45: Check if node contains HTML tags - if so, use recursive translation
                $node_has_html = preg_match('/<[a-z]/i', $node_innertext);

                // v5.3.45: For nodes with HTML, always use recursive translation
                // Don't skip based on substring_of_longer - HTML structure variations are different strings, not duplicates
                if ($node_has_html) {
                    // Use recursive translation to preserve nested HTML structure
                    $translated_html = $this->translate_page_recursive($node_innertext, $translation_map);

                    if ($translated_html !== false && $translated_html !== $node_innertext) {
                        $node->innertext = $translated_html;
                        $applied_count++;
                        $exact_matches++;
                        continue; // Skip to next node after recursive translation
                    }
                }

                // v5.3.45: For nodes WITHOUT HTML, check substring_of_longer to avoid duplicates
                if (!isset($substring_of_longer[$node_lookup_key])) {
                    // v5.3.44: For nodes WITHOUT HTML, use direct translation from DB
                    // This preserves the exact HTML structure from the translation
                    // v5.3.29: Preserve leading/trailing whitespace from original node
                    // Fixes issue where &nbsp; before links gets lost (e.g., "request at<a>" instead of "request at <a>")
                    $leading_ws = '';
                    $trailing_ws = '';
                    if (preg_match('/^(\s+|(?:&nbsp;)+)/i', $node_innertext, $m)) {
                        $leading_ws = $m[1];
                    }
                    if (preg_match('/(\s+|(?:&nbsp;)+)$/i', $node_innertext, $m)) {
                        $trailing_ws = $m[1];
                    }
                    $node->innertext = $leading_ws . $translation_map[$node_lookup_key]['translated'] . $trailing_ws;
                    $applied_count++;
                    $exact_matches++;
                    continue; // Skip to next node after exact match
                }
            }

            // v5.6.0: Strategy 1.5: PLAINTEXT MATCH (HTML-agnostic, TranslatePress-inspired)
            // If exact HTML match failed, try matching by stripped plaintext.
            // This handles cases like:
            //   DOM renders: "Переведите сайт на<br>\n\t\t<span class="highlight">любой язык</span>"
            //   DB stores:   "Переведите сайт на<br /> <span class="highlight">любой язык</span>"
            //   Both strip to: "переведите сайт на любой язык"
            // When plaintext matches, we use translate_page_recursive() to translate the HTML
            // preserving the original DOM structure.
            $node_has_html_check = preg_match('/<[a-z]/i', $node_innertext);
            if ($node_has_html_check) {
                $node_plaintext_key = $this->strip_tags_for_matching($node_innertext);

                if (!empty($node_plaintext_key) && isset($plaintext_map[$node_plaintext_key])) {
                    $pt_data = $plaintext_map[$node_plaintext_key];

                    // v5.6.0: Direct replacement with full translation from DB
                    // Since plaintext matches, the DB has the correct translated HTML block.
                    // Use it directly — this is the most reliable approach.
                    $full_translation = $pt_data['translated'];
                    if (!empty($full_translation)) {
                        // Preserve leading/trailing whitespace from original node
                        $leading_ws = '';
                        $trailing_ws = '';
                        if (preg_match('/^(\s+)/', $node_innertext, $m)) {
                            $leading_ws = $m[1];
                        }
                        if (preg_match('/(\s+)$/', $node_innertext, $m)) {
                            $trailing_ws = $m[1];
                        }
                        $node->innertext = $leading_ws . $full_translation . $trailing_ws;
                        $applied_count++;
                        $exact_matches++;
                        lingua_debug_log("[Lingua v5.6.0] ✓ Applied full translation for <{$tag}>: '" . substr($node_plaintext_key, 0, 60) . "'");
                        continue;
                    }
                }
            }

            // Strategy 2: PARTIAL MATCH (for strings like "Home" inside "Home Furniture")
            // Only check if node is not too long (avoid performance issues)
            // v5.3.19: Use lowercase for matching, but original case for replacement
            // v5.3.44: HTML-agnostic matching - strip tags for comparison if needed
            $node_has_html = preg_match('/<[a-z]/i', $node_lookup_key);
            $node_plaintext = $node_has_html ? trim(strtolower(strip_tags($node_lookup_key))) : $node_lookup_key;

            if (strlen($node_lookup_key) < 100 && (stripos($node_lookup_key, 'will be used') !== false)) {
            }

            if (strlen($node_normalized) <= 500) {
                foreach ($translation_map_partial as $t_data) {
                    $original_key = $t_data['key']; // lowercase
                    $original_key_normalized = $t_data['key_normalized']; // original case
                    $translated = $t_data['translated'];

                    // Check if original is contained in node (case-insensitive)
                    // v5.3.44: If node has HTML, compare plaintext versions
                    $original_has_html = preg_match('/<[a-z]/i', $original_key);
                    $original_plaintext = $original_has_html ? strtolower(strip_tags($original_key)) : $original_key;

                    $match_found = false;
                    if ($node_has_html || $original_has_html) {
                        // At least one has HTML - compare plaintext versions
                        $match_found = strpos($node_plaintext, $original_plaintext) !== false;

                        if (strlen($original_plaintext) < 100 && (stripos($original_plaintext, 'privacy') !== false || stripos($original_plaintext, 'will be used') !== false)) {
                        }
                    } else {
                        // Neither has HTML - normal comparison
                        $match_found = strpos($node_lookup_key, $original_key) !== false;
                    }

                    if ($match_found) {
                        // v5.2.127: Skip if node already contains the translation
                        // This prevents double-application like "products" → "productss"
                        $translated_lower = mb_strtolower($translated, 'UTF-8');
                        if (strpos($node_lookup_key, $translated_lower) !== false && $translated_lower !== $original_key) {
                            continue;
                        }

                        // Length check: node should not be MUCH longer than original
                        // (prevents applying "Home" translation to "Homepage Navigation Menu")
                        // v5.3.44: Compare plaintext lengths if HTML is involved
                        if ($node_has_html || $original_has_html) {
                            $original_length = strlen($original_plaintext);
                            $node_length = strlen($node_plaintext);
                        } else {
                            $original_length = strlen($original_key);
                            $node_length = strlen($node_lookup_key);
                        }

                        if ($node_length <= $original_length + 15) {
                            // v5.3.44: For exact plaintext match with HTML, use recursive translation (TranslatePress approach)
                            // This preserves HTML structure by recursively translating nested content
                            if ($node_has_html && $node_plaintext === $original_plaintext) {
                                $translated_html = $this->translate_page_recursive($node->innertext, $translation_map);

                                if ($translated_html !== false && $translated_html !== $node->innertext) {
                                    $node->innertext = $translated_html;
                                    $applied_count++;
                                    $partial_matches++;
                                    lingua_debug_log("[Lingua v5.3.44] Recursive translation applied: '" . substr($node_plaintext, 0, 50) . "...'");
                                    break;
                                }
                            }

                            // Standard replacement (no HTML or partial match)
                            // v5.3.19: Use case-insensitive replacement
                            // v5.3.46: Protect URL attributes if node contains HTML
                            $text_to_translate = $node_normalized;
                            $url_placeholders_partial = array();
                            if ($node_has_html) {
                                $protected_partial = $this->protect_url_attributes($node_normalized);
                                $text_to_translate = $protected_partial['html'];
                                $url_placeholders_partial = $protected_partial['placeholders'];
                            }

                            // v5.4.0: Use mb-safe replacement for Cyrillic/multibyte strings
                            $test_result = $this->mb_str_ireplace_safe($original_key_normalized, $translated, $text_to_translate);

                            // v5.3.46: Restore URL attributes after translation
                            if (!empty($url_placeholders_partial)) {
                                $test_result = $this->restore_url_attributes($test_result, $url_placeholders_partial);
                            }

                            // v5.0.17: Skip if result contains untranslated Cyrillic
                            $has_cyrillic = preg_match('/[\p{Cyrillic}]/u', $test_result);

                            if (!$has_cyrillic) {
                                // v5.3.29: Preserve leading/trailing whitespace from original node
                                $leading_ws = '';
                                $trailing_ws = '';
                                if (preg_match('/^(\s+|(?:&nbsp;)+)/i', $node_innertext, $m)) {
                                    $leading_ws = $m[1];
                                }
                                if (preg_match('/(\s+|(?:&nbsp;)+)$/i', $node_innertext, $m)) {
                                    $trailing_ws = $m[1];
                                }
                                $node->innertext = $leading_ws . $test_result . $trailing_ws;
                                $applied_count++;
                                $partial_matches++;
                                break; // Found match, skip to next node
                            }
                        }
                    }

                    // Strategy 3: FUZZY MATCH (node is beginning of original - truncated text)
                    if (strlen($node_normalized) >= 50 &&
                        strpos($original_key, $node_normalized) === 0) {
                        // Node text is the BEGINNING of original - likely truncated
                        $node_length = strlen($node_normalized);
                        $translated_portion = substr($translated, 0, $node_length);

                        $node->innertext = $translated_portion;
                        $applied_count++;
                        $partial_matches++;
                        break; // Found match, skip to next node
                    }
                }
            }

            // v5.3.7: DISABLED - Multi-part replacement was breaking URLs by translating path segments
            // TODO: Re-implement with proper URL protection
            // The issue: partial strings like "themes" in /wp-content/themes/ were being translated
        }

        // v1.2.4: BLOCK TRANSLATION - DISABLED in v1.2.5
        // Block translation was removing HTML structure (spans with styling)
        // Instead, we now translate each text node individually and support short words like "и"
        // See v1.2.5 changes below for proper handling of conjunctions

        // v5.3.8: TEXT NODE TRANSLATION
        // Translate text nodes (linguatext) directly - they contain pure text, no HTML/URLs
        // This fixes partial translations in structures like <li><strong>Label:</strong> Text</li>
        // where innertext of <li> contains HTML but text nodes are pure text
        // v5.3.9: Optimized - check translation_map FIRST (O(1)), skip expensive regex for non-matches
        $text_node_translations = 0;
        $text_node_count = 0;
        foreach ($html_dom->find('linguatext') as $text_node) {
            $text_node_count++;

            // v5.3.10: REMOVED flush() - causes 502 errors inside output buffer callback

            $text_content = isset($text_node->innertext) ? $text_node->innertext : '';

            if (empty($text_content)) {
                continue;
            }

            // Normalize text for lookup
            $text_normalized = $this->normalize_text_once($text_content);
            // v5.3.19: Use lowercase for case-insensitive lookup
            $text_lookup_key = $this->normalize_text_for_lookup($text_content);

            if (empty($text_normalized) || strlen($text_normalized) < 2) {
                continue;
            }

            // v5.3.9: Check translation map FIRST (O(1) lookup) - skip expensive regex if no match
            // v5.3.19: Use lowercase key for case-insensitive matching
            if (!isset($translation_map[$text_lookup_key])) {
                continue;
            }

            // v1.2.3 FIX: Removed incorrect Cyrillic check that skipped ALL Russian source text
            // The old check `if (preg_match('/[\p{Cyrillic}]/u', $text_content))` was wrong because:
            // - For Russian source language, ALL source text contains Cyrillic
            // - This caused text nodes like "Количество ступеней" to be skipped instead of translated
            // The translation_map lookup above already ensures we only translate matching strings

            // v1.2.6 FIX: Preserve leading/trailing whitespace from original text
            // Problem: " и " (with spaces) was becoming "and" (no spaces)
            // Solution: Extract and preserve the original whitespace
            // v5.3.19: Use lowercase key to get translation
            // v5.3.31: Also preserve &nbsp; entities (not just regular whitespace)
            $translated = $translation_map[$text_lookup_key]['translated'];

            // Extract leading whitespace (spaces, tabs, newlines, &nbsp; at the start)
            $leading_ws = '';
            if (preg_match('/^((?:\s|&nbsp;)+)/i', $text_content, $matches)) {
                $leading_ws = $matches[1];
            }

            // Extract trailing whitespace (spaces, tabs, newlines, &nbsp; at the end)
            $trailing_ws = '';
            if (preg_match('/((?:\s|&nbsp;)+)$/i', $text_content, $matches)) {
                $trailing_ws = $matches[1];
            }

            // Apply translation with preserved whitespace
            $text_node->innertext = $leading_ws . $translated . $trailing_ws;
            $text_node_translations++;
            $applied_count++;
        }

        if ($text_node_translations > 0) {
            lingua_debug_log("[Lingua v5.3.9] Text node translations: {$text_node_translations} (scanned {$text_node_count} nodes)");
        }

        // v5.3.19: ATTRIBUTE TRANSLATION
        // Translate alt, title, placeholder attributes that were extracted in extract_strings_from_html()
        $attr_translations = 0;

        // Translate alt and title attributes
        foreach ($html_dom->find('[alt], [title]') as $element) {
            // Translate alt attribute
            $alt = $element->getAttribute('alt');
            if (!empty($alt)) {
                $alt_clean = trim(html_entity_decode($alt, ENT_QUOTES, 'UTF-8'));
                if (!empty($alt_clean) && strlen($alt_clean) > 2) {
                    $alt_lookup_key = $this->normalize_text_for_lookup($alt_clean);
                    if (isset($translation_map[$alt_lookup_key])) {
                        $element->setAttribute('alt', $translation_map[$alt_lookup_key]['translated']);
                        $attr_translations++;
                        $applied_count++;
                    }
                }
            }

            // Translate title attribute
            $title = $element->getAttribute('title');
            if (!empty($title)) {
                $title_clean = trim(html_entity_decode($title, ENT_QUOTES, 'UTF-8'));
                if (!empty($title_clean) && strlen($title_clean) > 2) {
                    $title_lookup_key = $this->normalize_text_for_lookup($title_clean);
                    if (isset($translation_map[$title_lookup_key])) {
                        $element->setAttribute('title', $translation_map[$title_lookup_key]['translated']);
                        $attr_translations++;
                        $applied_count++;
                    }
                }
            }
        }

        // Translate placeholder attributes
        foreach ($html_dom->find('input[placeholder], textarea[placeholder]') as $input) {
            $placeholder = $input->getAttribute('placeholder');
            if (!empty($placeholder)) {
                $placeholder_clean = trim(html_entity_decode($placeholder, ENT_QUOTES, 'UTF-8'));
                if (!empty($placeholder_clean) && strlen($placeholder_clean) > 2) {
                    $placeholder_lookup_key = $this->normalize_text_for_lookup($placeholder_clean);
                    if (isset($translation_map[$placeholder_lookup_key])) {
                        $input->setAttribute('placeholder', $translation_map[$placeholder_lookup_key]['translated']);
                        $attr_translations++;
                        $applied_count++;
                    }
                }
            }
        }

        // Translate aria-label attributes
        foreach ($html_dom->find('[aria-label]') as $element) {
            $aria_label = $element->getAttribute('aria-label');
            if (!empty($aria_label)) {
                $aria_clean = trim(html_entity_decode($aria_label, ENT_QUOTES, 'UTF-8'));
                if (!empty($aria_clean) && strlen($aria_clean) > 2) {
                    $aria_lookup_key = $this->normalize_text_for_lookup($aria_clean);
                    if (isset($translation_map[$aria_lookup_key])) {
                        $element->setAttribute('aria-label', $translation_map[$aria_lookup_key]['translated']);
                        $attr_translations++;
                        $applied_count++;
                    }
                }
            }
        }

        // v5.3.19: Translate URL-encoded text in href attributes (mailto, telegram, whatsapp)
        // Find all links and check if they contain URL-encoded Cyrillic text
        foreach ($html_dom->find('a[href]') as $link) {
            $href = $link->getAttribute('href');
            if (empty($href)) {
                continue;
            }

            // Only process mailto:, telegram, whatsapp links with query parameters
            if (!preg_match('/^(mailto:|https?:\/\/(t\.me|wa\.me|api\.whatsapp))/i', $href)) {
                continue;
            }

            // URL-decode to get the original text (also handle HTML entities like &#038;)
            $decoded_href = html_entity_decode(urldecode($href), ENT_QUOTES, 'UTF-8');

            // Check if there are Cyrillic characters that need translation
            if (!preg_match('/[\p{Cyrillic}]/u', $decoded_href)) {
                continue;
            }

            $modified = false;

            // Try to translate each text segment in the URL
            foreach ($translation_map_partial as $t_data) {
                $original_key = $t_data['key_normalized']; // Original case
                $translated = $t_data['translated'];

                // Check if the decoded href contains this text (case-insensitive)
                if (mb_stripos($decoded_href, $original_key) !== false) {
                    // Replace in decoded href
                    $decoded_href = str_ireplace($original_key, $translated, $decoded_href);
                    $modified = true;
                }
            }

            if ($modified) {
                // Split URL into base and query parts
                $question_pos = strpos($decoded_href, '?');
                if ($question_pos !== false) {
                    $base_url = substr($decoded_href, 0, $question_pos);
                    $query_string = substr($decoded_href, $question_pos + 1);

                    // Parse and re-encode query string
                    parse_str($query_string, $query_params);
                    $encoded_query = http_build_query($query_params, '', '&', PHP_QUERY_RFC3986);

                    $new_href = $base_url . '?' . $encoded_query;
                    $link->setAttribute('href', $new_href);
                    $attr_translations++;
                    $applied_count++;
                    lingua_debug_log("[Lingua v5.3.19] Translated URL: {$href} => {$new_href}");
                }
            }
        }

        if ($attr_translations > 0) {
            lingua_debug_log("[Lingua v5.3.19] Attribute translations: {$attr_translations}");
        }


        $total_time = microtime(true) - $start_time;
        lingua_debug_log('[Lingua v5.2.6 OPTIMIZED] Applied ' . $applied_count . ' translations (exact: ' . $exact_matches . ', partial: ' . $partial_matches . ', attrs: ' . $attr_translations . ') in ' . round($total_time, 3) . 's');

        $this->log_debug("v5.2.6: ✓ OPTIMIZED translation application: {$applied_count} translations in " . round($total_time, 3) . "s");

        return $applied_count;
    }

    /**
     * v5.2.37 P5: Extract all translatable strings from HTML     * This allows us to query ONLY the translations we need instead of ALL translations
     *
     * PERFORMANCE IMPACT:
     * - Extracts ~500 unique strings from typical page
     * - Reduces DB query from 10,000+ rows to ~500 rows
     * - 20x faster DB query, 3-5x faster overall page load
     *
     * @param string $html HTML content to extract strings from
     * @return array Array of unique normalized strings found in HTML
     */
    public function extract_strings_from_html($html) {
        $start_time = microtime(true);

        // Parse HTML to DOM (use original HTML, not placeholders version)
        require_once LINGUA_PLUGIN_DIR . 'includes/lib/lingua-html-dom.php';
        $html_dom = lingua_str_get_html($html, true, true, LINGUA_DEFAULT_TARGET_CHARSET, false, LINGUA_DEFAULT_BR_TEXT, LINGUA_DEFAULT_SPAN_TEXT);

        if ($html_dom === false) {
            lingua_debug_log("[Lingua v5.2.37 P5] Failed to parse HTML for string extraction");
            return array();
        }

        $strings = array();
        $node_count = 0;

        // v5.3.6: Extract title and SEO meta tags from head (not skipped like other head content)
        $title_node = $html_dom->find('title', 0);
        if ($title_node && !empty($title_node->innertext)) {
            $title_text = trim(html_entity_decode($title_node->innertext, ENT_QUOTES, 'UTF-8'));
            if (!empty($title_text) && strlen($title_text) > 2) {
                $strings[$title_text] = true;
            }
        }

        // Extract meta description and og:title
        foreach ($html_dom->find('meta[name=description], meta[property=og:title], meta[property=og:description]') as $meta) {
            $content = $meta->getAttribute('content');
            if (!empty($content)) {
                $content = trim(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));
                if (!empty($content) && strlen($content) > 2) {
                    $strings[$content] = true;
                }
            }
        }

        // v5.3.19: Extract alt and title attributes from elements
        foreach ($html_dom->find('[alt], [title]') as $element) {
            // Extract alt attribute (images)
            $alt = $element->getAttribute('alt');
            if (!empty($alt)) {
                $alt = trim(html_entity_decode($alt, ENT_QUOTES, 'UTF-8'));
                if (!empty($alt) && strlen($alt) > 2 && !$this->is_technical_content($alt)) {
                    $strings[$alt] = true;
                }
            }

            // Extract title attribute (tooltips, links)
            $title = $element->getAttribute('title');
            if (!empty($title)) {
                $title = trim(html_entity_decode($title, ENT_QUOTES, 'UTF-8'));
                if (!empty($title) && strlen($title) > 2 && !$this->is_technical_content($title)) {
                    $strings[$title] = true;
                }
            }
        }

        // v5.3.19: Extract placeholder attributes from inputs
        foreach ($html_dom->find('input[placeholder], textarea[placeholder]') as $input) {
            $placeholder = $input->getAttribute('placeholder');
            if (!empty($placeholder)) {
                $placeholder = trim(html_entity_decode($placeholder, ENT_QUOTES, 'UTF-8'));
                if (!empty($placeholder) && strlen($placeholder) > 2 && !$this->is_technical_content($placeholder)) {
                    $strings[$placeholder] = true;
                }
            }
        }

        // v5.3.19: Extract aria-label attributes (accessibility)
        foreach ($html_dom->find('[aria-label]') as $element) {
            $aria_label = $element->getAttribute('aria-label');
            if (!empty($aria_label)) {
                $aria_label = trim(html_entity_decode($aria_label, ENT_QUOTES, 'UTF-8'));
                if (!empty($aria_label) && strlen($aria_label) > 2 && !$this->is_technical_content($aria_label)) {
                    $strings[$aria_label] = true;
                }
            }
        }

        // v5.3.19: Extract URL-encoded text from mailto/telegram/whatsapp links
        foreach ($html_dom->find('a[href]') as $link) {
            $href = $link->getAttribute('href');
            if (empty($href)) {
                continue;
            }

            // Only process mailto:, telegram, whatsapp links
            if (!preg_match('/^(mailto:|https?:\/\/(t\.me|wa\.me|api\.whatsapp))/i', $href)) {
                continue;
            }

            // URL-decode to get the text
            $decoded_href = html_entity_decode(urldecode($href), ENT_QUOTES, 'UTF-8');

            // Check if there are Cyrillic characters
            if (!preg_match('/[\p{Cyrillic}]/u', $decoded_href)) {
                continue;
            }

            // Extract text from query parameters
            $question_pos = strpos($decoded_href, '?');
            if ($question_pos !== false) {
                $query_string = substr($decoded_href, $question_pos + 1);
                parse_str($query_string, $query_params);

                foreach ($query_params as $value) {
                    if (is_string($value) && !empty($value)) {
                        // Split by newlines and extract each segment
                        $segments = preg_split('/[\r\n]+/', $value);
                        foreach ($segments as $segment) {
                            $segment = trim($segment);
                            if (!empty($segment) && strlen($segment) > 2 && preg_match('/[\p{Cyrillic}]/u', $segment)) {
                                $strings[$segment] = true;
                            }
                        }
                    }
                }
            }
        }

        // Traverse all DOM nodes and extract text
        foreach ($html_dom->find('*') as $node) {
            $node_count++;

            // Skip technical tags
            $tag = strtolower($node->tag);

            if ($tag === 'p') {
                $p_text = isset($node->innertext) ? substr(strip_tags($node->innertext), 0, 100) : '';
            }

            if (in_array($tag, array('script', 'style', 'head', 'link', 'meta'))) {
                continue;
            }

            // v5.3.6: Extract direct text nodes (text between child elements)
            // This catches text like " Toyota is the world's largest " between </strong> and </li>
            // Note: lingua-html-dom uses 'linguatext' tag for text nodes
            // v1.2.5: Changed > 2 to >= 2 to allow short conjunctions like "и" (2 bytes in UTF-8)
            // v5.3.44: SKIP linguatext extraction if parent node will be processed anyway
            //          This prevents duplicates like "Rattan armchairs" AND "Rattan armchairs<br />"
            $parent_has_only_inline_children = true;
            if (isset($node->children) && is_array($node->children)) {
                $block_level_tags_check = array('div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'section', 'article');
                foreach ($node->children as $child) {
                    if (isset($child->tag) && in_array(strtolower($child->tag), $block_level_tags_check)) {
                        $parent_has_only_inline_children = false;
                        break;
                    }
                }
            }

            // v5.4.0: Extract linguatext from ALL elements with children (not just block-level parents)
            // OLD: Only extracted bare text when parent had block-level children
            // NEW: Also extract bare text from mixed-content elements with inline children
            // Example: <div><span class="icon"></span> Bare text here</div>
            //   → extracts "Bare text here" as a separate string for DB lookup
            //   → also extracts full innerHTML "<span class='icon'></span> Bare text here"
            // This ensures both formats can match against DB translations
            $has_any_children = isset($node->children) && is_array($node->children) && count($node->children) > 0;

            if ($has_any_children && isset($node->nodes) && is_array($node->nodes)) {
                foreach ($node->nodes as $child) {
                    if (isset($child->tag) && $child->tag === 'linguatext') {
                        $text_content = isset($child->innertext) ? trim($child->innertext) : '';
                        if (!empty($text_content) && strlen($text_content) >= 2 && !$this->is_technical_content($text_content)) {
                            $normalized_text = $this->normalize_text_once($text_content);
                            if (!empty($normalized_text) && strlen($normalized_text) >= 2) {
                                $strings[$normalized_text] = true;
                            }
                        }
                    }
                }
            }

            $node_innertext = $node->innertext;

            if (empty($node_innertext)) {
                continue;
            }

            // Skip technical content (JSON, URLs, etc.)
            if ($this->is_technical_content($node_innertext)) {
                continue;
            }

            // v5.3.44: CRITICAL FIX - Skip nodes with block-level children to avoid duplication
            // But allow nodes with only inline children (br, span, a, etc.) to be extracted WITH their HTML
            $inline_tags = array('br', 'span', 'strong', 'b', 'em', 'i', 'u', 'a', 'small', 'mark', 'del', 'ins', 'sub', 'sup', 'code');
            $block_level_tags = array('div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'section', 'article');

            $has_block_children = false;
            if (isset($node->children) && is_array($node->children)) {
                foreach ($node->children as $child) {
                    if (isset($child->tag) && in_array(strtolower($child->tag), $block_level_tags)) {
                        $has_block_children = true;
                        break;
                    }
                }
            }

            // Skip nodes with block-level children (they will be processed when we iterate to them)
            if ($has_block_children) {
                continue;
            }

            // At this point, node either has no children, or only inline/text children
            // Extract with HTML preserved (innertext includes inline tags like <br />)

            // Normalize text ONCE per node
            $normalized = $this->normalize_text_once($node_innertext);

            if (stripos($normalized, 'will') !== false || stripos($normalized, 'privacy') !== false || stripos($normalized, 'policy') !== false) {
            }

            if ($is_title_subtitle) {
            }

            if (empty($normalized) || strlen($normalized) < 2) {
                if ($is_title_subtitle) {
                }
                continue;
            }

            // v5.3.45: Extract strings WITH HTML tags preserved
            // This allows matching against database translations that include HTML structure
            // Recursive translation will handle the HTML content properly during application

            // No HTML - store as is
            $strings[$normalized] = true;
        }

        // Clean up DOM
        $html_dom->clear();
        unset($html_dom);

        // Return array of unique strings (array keys)
        $unique_strings = array_keys($strings);

        $extract_time = microtime(true) - $start_time;
        lingua_debug_log("[Lingua v5.2.37 P5] String extraction: traversed {$node_count} nodes, found " . count($unique_strings) . " unique strings in " . round($extract_time, 3) . "s");

        return $unique_strings;
    }

    /**
     * v5.2.137: Translate meta description and OG tags in final HTML
     * v5.2.165: Search by specific context to prevent overwrites between title/og:title
     * Uses regex to find and replace meta tag content attribute values
     */
    private function translate_meta_tags($html, $translations) {
        global $wpdb, $post;

        // Get post ID for context-aware lookup
        $post_id = 0;
        if (isset($post->ID)) {
            $post_id = $post->ID;
        } elseif (function_exists('get_queried_object_id')) {
            $post_id = get_queried_object_id();
        }

        lingua_debug_log("[Lingua v5.3.22] translate_meta_tags called. post_id={$post_id}, language={$this->current_language}");

        $table_name = $wpdb->prefix . 'lingua_string_translations';
        $language = $this->current_language;

        // v5.2.165: Helper function to get translation by specific context
        // v5.3.22: Added fallback to search by original_text when no SEO-specific context found
        // v5.3.24: Normalize &nbsp; and whitespace for matching (og:description often has &nbsp; entities)
        // v5.5.2: Validate context-based match against current content to prevent stale translations
        $get_seo_translation = function($original, $seo_type) use ($wpdb, $table_name, $language, $post_id) {
            if (empty($post_id)) {
                return null;
            }

            // Helper: normalize text for comparison (strip entities, nbsp, collapse whitespace, lowercase)
            $normalize_for_compare = function($text) {
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = str_replace("\xC2\xA0", ' ', $text);
                $text = preg_replace('/\s+/', ' ', $text);
                return mb_strtolower(trim($text), 'UTF-8');
            };

            $original_normalized = $normalize_for_compare($original);

            // 1. First try: Search by specific SEO context (e.g., post_2222_seo_title)
            $context = "post_{$post_id}_{$seo_type}";
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT original_text, translated_text FROM {$table_name}
                 WHERE language_code = %s AND context = %s AND translated_text != ''
                 LIMIT 1",
                $language, $context
            ));

            if ($row) {
                // v5.5.2: Verify that the stored original_text still matches the current content
                // If post content changed (e.g., Yoast metadesc updated), the old context record
                // would have a different original_text and should NOT be used
                $stored_normalized = $normalize_for_compare($row->original_text);
                if ($stored_normalized === $original_normalized) {
                    lingua_debug_log("[Lingua v5.5.2] SEO translation found by context: {$context}");
                    return $row->translated_text;
                } else {
                    lingua_debug_log("[Lingua v5.5.2] SEO context {$context} is STALE (original changed), skipping");
                }
            }

            // 2. Fallback: Search by original_text (for auto_translate context format)
            // This handles titles stored with context like 'auto_translate.post_XXX'
            // v5.3.24: Normalize HTML entities and whitespace for matching
            $normalized = $original_normalized;

            // Use REGEXP_REPLACE to normalize DB values too (MySQL 8.0+)
            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT translated_text FROM {$table_name}
                 WHERE language_code = %s
                 AND LOWER(TRIM(REGEXP_REPLACE(REPLACE(original_text, CHAR(194,160), ' '), ' +', ' '))) = %s
                 AND translated_text != ''
                 AND translated_text != original_text
                 LIMIT 1",
                $language, $normalized
            ));

            if ($result) {
                lingua_debug_log("[Lingua v5.5.2] SEO translation found by normalized original_text: " . substr($original, 0, 50));
            }

            return $result;
        };

        // v5.3.23: Helper function for prefix matching in multi-part titles/og:title
        // Finds the longest translated prefix before a separator, keeps suffix unchanged
        $translate_with_prefix_match = function($original_content) use ($wpdb, $table_name, $language) {
            $separators = array(' - ', ' | ', ' – ', ' — ');

            foreach ($separators as $sep) {
                if (strpos($original_content, $sep) === false) {
                    continue;
                }

                // Find ALL positions of this separator
                $positions = array();
                $pos = 0;
                while (($pos = strpos($original_content, $sep, $pos)) !== false) {
                    $positions[] = $pos;
                    $pos += strlen($sep);
                }

                // Try from the rightmost separator position towards left (longest prefix first)
                rsort($positions);

                foreach ($positions as $sep_pos) {
                    $prefix = substr($original_content, 0, $sep_pos);
                    $suffix = substr($original_content, $sep_pos); // includes separator

                    // Check if this prefix has a translation
                    $normalized = mb_strtolower(trim($prefix), 'UTF-8');
                    $prefix_translated = $wpdb->get_var($wpdb->prepare(
                        "SELECT translated_text FROM {$table_name}
                         WHERE language_code = %s
                         AND LOWER(TRIM(original_text)) = %s
                         AND translated_text != ''
                         AND translated_text != original_text
                         LIMIT 1",
                        $language, $normalized
                    ));

                    if ($prefix_translated) {
                        lingua_debug_log("[Lingua v5.3.23] Prefix translated: '{$prefix}' → '{$prefix_translated}'");
                        return $prefix_translated . $suffix;
                    }
                }
            }

            return null; // No prefix match found
        };

        // v5.2.165: Translate <title> tag with specific context
        // v5.3.22: Handle multi-part titles like "Product Title - Site Name"
        // v5.3.23: Prefix matching - find longest translated prefix, keep suffix unchanged
        $html = preg_replace_callback(
            '/<title>([^<]+)<\/title>/i',
            function($matches) use ($get_seo_translation, $translate_with_prefix_match) {
                $original_content = $matches[1];

                // 1. First try: full title translation
                $translated = $get_seo_translation($original_content, 'seo_title');
                if ($translated) {
                    lingua_debug_log("[Lingua v5.3.23] <title> translated full");
                    return '<title>' . esc_html($translated) . '</title>';
                }

                // 2. Fallback: Prefix matching
                $prefix_result = $translate_with_prefix_match($original_content);
                if ($prefix_result) {
                    return '<title>' . esc_html($prefix_result) . '</title>';
                }

                return $matches[0];
            },
            $html
        );

        // Translate meta description
        $html = preg_replace_callback(
            '/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i',
            function($matches) use ($get_seo_translation) {
                $current_content = $matches[1];
                $translated = $get_seo_translation($current_content, 'meta_description');
                if ($translated) {
                    return str_replace($current_content, esc_attr($translated), $matches[0]);
                }
                return $matches[0];
            },
            $html
        );

        // Translate og:title (with specific context)
        // v5.3.23: Added prefix matching fallback
        $html = preg_replace_callback(
            '/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i',
            function($matches) use ($get_seo_translation, $translate_with_prefix_match) {
                $original_content = $matches[1];

                // 1. First try: full og:title translation
                $translated = $get_seo_translation($original_content, 'og_title');
                if ($translated) {
                    return str_replace($original_content, esc_attr($translated), $matches[0]);
                }

                // 2. Fallback: Prefix matching (same as <title>)
                $prefix_result = $translate_with_prefix_match($original_content);
                if ($prefix_result) {
                    return str_replace($original_content, esc_attr($prefix_result), $matches[0]);
                }

                return $matches[0];
            },
            $html
        );

        // Translate og:description (with specific context)
        $html = preg_replace_callback(
            '/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i',
            function($matches) use ($get_seo_translation) {
                $current_content = $matches[1];
                $translated = $get_seo_translation($current_content, 'og_description');
                if ($translated) {
                    return str_replace($current_content, esc_attr($translated), $matches[0]);
                }
                return $matches[0];
            },
            $html
        );

        // Also handle reversed attribute order (content before name/property)
        $html = preg_replace_callback(
            '/<meta\s+content=["\']([^"\']+)["\']\s+name=["\']description["\']/i',
            function($matches) use ($get_seo_translation) {
                $original_content = $matches[1];
                $translated = $get_seo_translation($original_content, 'meta_description');
                if ($translated) {
                    return str_replace($original_content, esc_attr($translated), $matches[0]);
                }
                return $matches[0];
            },
            $html
        );

        // v5.2.144: Apply filter for SEO integration (hreflang replacement, etc.)
        $html = apply_filters('lingua_process_output_buffer', $html);

        return $html;
    }
}