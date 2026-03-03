<?php
/**
 * Public-facing functionality
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Public {
    
    private $version;
    
    public function __construct($version) {
        $this->version = $version;

        // v4.0: Register hook to inject language data into JS
        // v5.5: Changed from wp_print_footer_scripts to wp_enqueue_scripts for wp_add_inline_script() compatibility
        add_action('wp_enqueue_scripts', array($this, 'output_language_data_to_js'), 20);

        // v5.2: Register footer hook for media library templates
        if (current_user_can(lingua_translating_capability())) {
            add_action('wp_footer', array($this, 'print_media_templates'), 99);
            // v5.2.41: Include translation modal template in footer
            add_action('wp_footer', array($this, 'print_translation_modal'), 10);
        }

        lingua_debug_log("[LINGUA PUBLIC v4.0] Constructor called, footer hook registered");
    }
    
    public function enqueue_styles() {
        // Flag Icons CSS for language switcher
        wp_enqueue_style(
            'lingua-flag-icons',
            LINGUA_PLUGIN_URL . 'public/css/flags/flag-icons.min.css',
            array(),
            '7.2.3'
        );

        // Public styles (language switcher)
        wp_enqueue_style(
            'lingua-public',
            LINGUA_PLUGIN_URL . 'public/css/lingua-public.css',
            array('lingua-flag-icons'),
            $this->version
        );

        // Translation modal styles (only for users with edit permissions)
        if (current_user_can(lingua_translating_capability())) {
            // v5.2.106: Use filemtime() instead of time() for reliable cache-busting
            $modal_css_path = LINGUA_PLUGIN_DIR . 'admin/css/translation-modal.css';
            $modal_css_version = file_exists($modal_css_path) ? filemtime($modal_css_path) : LINGUA_VERSION;

            wp_enqueue_style(
                'lingua-translation-modal',
                LINGUA_PLUGIN_URL . 'admin/css/translation-modal.css',
                array(),
                '2.0.0-v2-enhanced-public-' . $modal_css_version
            );
        }
    }
    
    public function enqueue_scripts() {
        // v5.2.152: CRITICAL DEBUG - Log method call FIRST
        lingua_debug_log('[LINGUA v5.2.152] enqueue_scripts() CALLED! is_admin=' . (is_admin() ? 'true' : 'false'));

        // v5.2.40: DEBUG - Log enqueue execution
        $user_id = get_current_user_id();
        $can_translate = current_user_can(lingua_translating_capability());
        lingua_debug_log('[LINGUA v5.2.40 ENQUEUE] User ID: ' . $user_id . ', Can translate: ' . ($can_translate ? 'YES' : 'NO'));

        // v5.0.12: Dynamic translation handler for AJAX content
        // Enqueue for ALL users (not just editors)
        wp_enqueue_script('jquery');

        // v5.2.106: Use filemtime() for reliable cache-busting
        $ajax_translation_path = LINGUA_PLUGIN_DIR . 'public/js/ajax-translation.js';
        $ajax_translation_version = file_exists($ajax_translation_path) ? filemtime($ajax_translation_path) : LINGUA_VERSION;

        wp_enqueue_script(
            'lingua-dynamic-translation',
            LINGUA_PLUGIN_URL . 'public/js/ajax-translation.js',
            array('jquery'),
            '5.2.12-switcher-skip-' . $ajax_translation_version,
            true
        );

        // Localize script for AJAX
        // v5.3.21: Add debug_mode flag for JS console logging control
        wp_localize_script('lingua-dynamic-translation', 'lingua_dynamic', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lingua_dynamic_nonce'),
            'hide_until_translated' => apply_filters('lingua_hide_dynamic_content_until_translated', false), // Set to true to hide content flash
            'debug_mode' => lingua_is_debug_enabled()
        ));

        // v5.2.61: Language switcher fix for cached menus + AJAX navigation (WoodMart, etc.)
        // This client-side script updates the language switcher based on URL and monitors URL changes
        // v5.2.106: Use filemtime() for reliable cache-busting
        $switcher_fix_path = LINGUA_PLUGIN_DIR . 'public/js/switcher-fix-v4.js';
        $switcher_fix_version = file_exists($switcher_fix_path) ? filemtime($switcher_fix_path) : LINGUA_VERSION;

        wp_enqueue_script(
            'lingua-switcher-fix',
            LINGUA_PLUGIN_URL . 'public/js/switcher-fix.js',
            array('jquery'),
            '5.2.61-' . $switcher_fix_version,
            true
        );

        // Только для пользователей с правами редактирования
        if (current_user_can(lingua_translating_capability())) {
            lingua_debug_log('[LINGUA v5.2.40 ENQUEUE] Loading admin scripts for user ' . $user_id);
            // jQuery должен быть подключен
            wp_enqueue_script('jquery');

            // v5.2.7: LAZY LOADING - Media Library scripts loaded on demand (only when modal opens)
            // This reduces initial page load by ~300-400KB
            // Scripts are loaded via AJAX when user clicks "Translate Page" button
            // See: ajax_load_media_library_scripts() and translation-modal.js

            // Основной скрипт админки (для AJAX)
            wp_enqueue_script(
                'lingua-admin',
                LINGUA_PLUGIN_URL . 'admin/js/admin.js',
                array('jquery'),
                $this->version,
                true
            );

            // Скрипт модального окна перевода (v2.0 Enhanced)
            // v5.2.107: FORCE cache invalidation by adding query param
            $modal_js_path = LINGUA_PLUGIN_DIR . 'admin/js/translation-modal.js';
            $modal_js_version = file_exists($modal_js_path) ? filemtime($modal_js_path) : LINGUA_VERSION;

            // v5.2.107: Add timestamp as query parameter to bypass ALL caching layers
            $modal_js_url = LINGUA_PLUGIN_URL . 'admin/js/translation-modal.js?nocache=' . $modal_js_version;

            // v5.2.107: DEBUG - Log the version being used
            lingua_debug_log('[LINGUA v5.2.107 ENQUEUE] Modal JS version: ' . $modal_js_version . ' (filemtime: ' . filemtime($modal_js_path) . ', LINGUA_VERSION: ' . LINGUA_VERSION . ')');
            lingua_debug_log('[LINGUA v5.2.107 ENQUEUE] Modal JS URL: ' . $modal_js_url);

            wp_enqueue_script(
                'lingua-translation-modal',
                $modal_js_url,
                array('jquery', 'lingua-admin'),
                null, // Версия уже в URL
                true
            );
            
            // Localize script for AJAX
            // v5.2.62: Generate fresh nonce on each page load
            $fresh_nonce = wp_create_nonce('lingua_admin_nonce');
            lingua_debug_log('[LINGUA v5.2.62 NONCE DEBUG] Generated nonce: ' . $fresh_nonce . ' for user: ' . get_current_user_id());

            // v5.3.21: Add debug_mode flag for JS console logging control
            wp_localize_script('lingua-admin', 'lingua_admin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => $fresh_nonce,
                'default_language' => get_option('lingua_default_language', lingua_get_site_language()), // v5.2.63: Fix iframe issue - default_language was undefined
                'debug_mode' => lingua_is_debug_enabled(),
                'strings' => array(
                    'testing_connection' => __('Testing connection...', 'iqcloud-translate'),
                    'connection_successful' => __('Connection successful!', 'iqcloud-translate'),
                    'connection_failed' => __('Connection failed!', 'iqcloud-translate'),
                    'invalid_post_id' => __('Invalid post ID', 'iqcloud-translate'),
                    'no_post_selected' => __('No post selected', 'iqcloud-translate'),
                    'select_target_language' => __('Please select target language', 'iqcloud-translate'),
                    'extracting_content' => __('Extracting content...', 'iqcloud-translate'),
                    'content_extracted' => __('Content extracted successfully', 'iqcloud-translate'),
                    'extraction_failed' => __('Content extraction failed', 'iqcloud-translate'),
                    'ajax_error' => __('AJAX request failed', 'iqcloud-translate'),
                    'ready_to_extract' => __('Ready to extract content', 'iqcloud-translate'),
                    'translating' => __('Translating...', 'iqcloud-translate'),
                    'translate' => __('Translate', 'iqcloud-translate'),
                    'translation' => __('Translation', 'iqcloud-translate'),
                    'original' => __('Original', 'iqcloud-translate'),
                    'enter_translation' => __('Enter translation...', 'iqcloud-translate'),
                    'translation_complete' => __('Translation completed!', 'iqcloud-translate'),
                    'translation_failed' => __('Translation failed', 'iqcloud-translate'),
                    'confirm_auto_translate' => __('Auto-translate all content?', 'iqcloud-translate'),
                    'no_content_to_translate' => __('No content to translate', 'iqcloud-translate'),
                    'auto_translate_complete' => __('Auto-translation completed', 'iqcloud-translate'),
                    'invalid_state' => __('Invalid state for saving', 'iqcloud-translate'),
                    'no_translations_to_save' => __('No translations to save', 'iqcloud-translate'),
                    'saving' => __('Saving...', 'iqcloud-translate'),
                    'saving_translations' => __('Saving translations...', 'iqcloud-translate'),
                    'translations_saved' => __('Translations saved successfully', 'iqcloud-translate'),
                    'save_failed' => __('Save failed', 'iqcloud-translate'),
                    'save_translation' => __('Save Translation', 'iqcloud-translate'),

                    // v5.3.35: SEO и медиа строки для модала
                    'seo_title' => __('SEO Title', 'iqcloud-translate'),
                    'seo_title_desc' => __('SEO title from Yoast/RankMath (shown in search engine results)', 'iqcloud-translate'),
                    'seo_description' => __('Meta Description', 'iqcloud-translate'),
                    'seo_description_desc' => __('Brief description of the page shown in search results', 'iqcloud-translate'),
                    'og_title' => __('OG Title', 'iqcloud-translate'),
                    'og_title_desc' => __('Title shown when shared on Facebook, LinkedIn, etc.', 'iqcloud-translate'),
                    'og_title_desc_default' => __('Title shown when shared on Facebook, LinkedIn, etc. (defaults to SEO Title if empty)', 'iqcloud-translate'),
                    'og_description' => __('OG Description', 'iqcloud-translate'),
                    'og_description_desc' => __('Description shown when shared on social media', 'iqcloud-translate'),
                    'og_description_desc_default' => __('Description shown when shared on social media (defaults to Meta Description if empty)', 'iqcloud-translate'),
                    'open_graph_social' => __('Open Graph (Social Media)', 'iqcloud-translate'),
                    'auto_translate' => __('Auto-translate', 'iqcloud-translate'),
                    'add_media' => __('Add Media', 'iqcloud-translate'),
                    'save_media' => __('Save Media', 'iqcloud-translate'),
                    'enter_url_or_add_media' => __('Enter URL or use Add Media button', 'iqcloud-translate')
                )
            ));
        }
    }
    
    /**
     * v3.7.8: Output language data to JavaScript in footer
     * This ensures language is detected AFTER all WordPress hooks have run
     * v5.2.7: RESTORED - Client-side switcher fix is needed for cached menus!
     */
    public function output_language_data_to_js() {
        // v5.2.14: CRITICAL FIX - Prevent caching of this block by detecting language from URL in JavaScript context
        // This ensures correct language even when page HTML is cached

        // v4.0.1: Use global $LINGUA_LANGUAGE - it's ALWAYS set in Lingua constructor now!
        global $LINGUA_LANGUAGE;

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        $current_lang = $LINGUA_LANGUAGE; // No fallback needed - always set in constructor!

        $languages = get_option('lingua_languages', array());

        // v5.2.15: CRITICAL FIX - Add fallback languages (same as nav-menu-integration and url-rewriter)
        // This ensures language switcher works even for languages without translations
        if (empty($languages) || !isset($languages[$default_lang])) {
            // v5.2.128: Use centralized language list
            $available_languages = Lingua_Languages::get_all();

            // Add default language if missing
            if (!isset($languages[$default_lang])) {
                $languages[$default_lang] = $available_languages[$default_lang];
            }

            // Add ALL available languages to fallback list (ensures switcher works for all languages)
            foreach ($available_languages as $lang_code => $lang_data) {
                if (!isset($languages[$lang_code])) {
                    $languages[$lang_code] = $lang_data;
                }
            }

            lingua_debug_log('[Lingua JS v5.2.15] Added fallback languages for switcher: ' . implode(', ', array_keys($languages)));
        }

        // Log for debugging
        lingua_debug_log("[LINGUA JS v5.2.9] Footer output: currentLang={$current_lang}, defaultLang={$default_lang}, availableLangs=" . implode(',', array_keys($languages)));

        // Prepare language data for JavaScript
        $language_data = array();
        $current_path = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));

        // v5.2.31: DEBUG - Log current path for debugging
        lingua_debug_log("[LINGUA v5.2.31 PHP DEBUG] current_path from REQUEST_URI: {$current_path}");

        foreach ($languages as $code => $lang_info) {
            // Generate URL for language switching
            // Remove existing language prefix first
            $clean_path = preg_replace('/^\/[a-z]{2}(\/|$)/', '/', $current_path);

            // v5.2.31: DEBUG
            lingua_debug_log("[LINGUA v5.2.31 PHP DEBUG] clean_path after regex: {$clean_path}");

            // Add new language prefix (except for default language)
            if ($code === $default_lang) {
                $url = $clean_path;
            } else {
                $url = '/' . $code . $clean_path;
            }

            // Clean up double slashes
            $url = preg_replace('/\/+/', '/', $url);

            // v5.2.31: DEBUG
            lingua_debug_log("[LINGUA v5.2.31 PHP DEBUG] Final URL for {$code}: {$url}");

            // v5.2.176: Add country_code for SVG flags in JS
            $country_code = class_exists('Lingua_Languages')
                ? Lingua_Languages::get_country_code($code)
                : $code;

            $language_data[$code] = array(
                'name' => $lang_info['name'] ?? $code,
                'native' => $lang_info['native'] ?? $lang_info['name'] ?? $code,
                'flag' => $lang_info['flag'] ?? '',
                'country_code' => $country_code,
                'url' => $url
            );
        }

        // v5.5: Use wp_add_inline_script() instead of inline <script> tag (WP review compliance)
        $debug_mode = lingua_is_debug_enabled() ? 'true' : 'false';
        $inline_js = "// v5.3.21: Global debug mode flag and logging function\n"
            . "window.linguaDebugMode = {$debug_mode};\n"
            . "window.linguaDebug = function() {\n"
            . "    if (window.linguaDebugMode && typeof console !== 'undefined' && console.log) {\n"
            . "        console.log.apply(console, arguments);\n"
            . "    }\n"
            . "};\n"
            . "window.linguaSwitcher = window.linguaSwitcher || {};\n"
            . "window.linguaSwitcher.defaultLang = '" . esc_js($default_lang) . "';\n"
            . "window.linguaSwitcher.languages = " . wp_json_encode($language_data) . ";\n"
            . "window.linguaSwitcher.debugMode = window.linguaDebugMode;\n"
            . "(function() {\n"
            . "    var path = window.location.pathname;\n"
            . "    var match = path.match(/^\\/([a-z]{2})(?:\\/|$)/);\n"
            . "    window.linguaSwitcher.currentLang = match ? match[1] : window.linguaSwitcher.defaultLang;\n"
            . "    window.linguaDebug('[LINGUA v5.2.31] Language auto-detected from URL:', window.linguaSwitcher.currentLang, 'path:', path);\n"
            . "})();\n"
            . "window.linguaDebug('[LINGUA v5.2.14 DEBUG] Language data injected:', window.linguaSwitcher);\n"
            . "window.linguaDebug('[LINGUA v5.2.14 DEBUG] Current URL:', window.location.pathname);\n"
            . "window.linguaDebug('[LINGUA v5.2.14 DEBUG] Available languages:', Object.keys(window.linguaSwitcher.languages));";

        wp_add_inline_script('lingua-switcher-fix', $inline_js, 'before');
    }

    /**
     * v5.2: Print media templates in footer to ensure wp.media works
     */
    public function print_media_templates() {
        if (current_user_can(lingua_translating_capability())) {
            // Force print media templates
            do_action('wp_print_media_templates');
            lingua_debug_log('[LINGUA v5.2] Media templates printed in footer');
        }
    }

    /**
     * v5.2.41: Print translation modal template in footer
     * This prevents 403 error from AJAX modal template loading
     */
    public function print_translation_modal() {
        if (current_user_can(lingua_translating_capability())) {
            include LINGUA_PLUGIN_DIR . 'admin/views/translation-modal.php';
            lingua_debug_log('[LINGUA v5.2.41] Translation modal template printed in footer');
        }
    }

    /**
     * v5.2.7: AJAX endpoint - Load Media Library scripts dynamically
     * This is called only when user clicks "Translate Page" button
     * Reduces initial page load by ~300-400KB for editors
     */
    public function ajax_load_media_library_scripts() {
        // Security check
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_translating_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        lingua_debug_log('[LINGUA v5.2.7] Loading Media Library scripts on demand');

        // Generate script and style URLs
        $post_id = intval($_POST['post_id'] ?? 0);
        if (!$post_id && is_home()) {
            $post_id = get_option('page_for_posts');
        }

        // Collect all Media Library dependencies
        // v5.2.40: Fixed 404 errors - removed non-existent files, corrected paths
        $scripts_to_check = array(
            'jquery-ui-core' => includes_url('js/jquery/ui/core.min.js'),
            // 'jquery-ui-widget' removed - doesn't exist in WordPress 6.8+
            'jquery-ui-mouse' => includes_url('js/jquery/ui/mouse.min.js'),
            'jquery-ui-sortable' => includes_url('js/jquery/ui/sortable.min.js'),
            'underscore' => includes_url('js/underscore.min.js'),
            'backbone' => includes_url('js/backbone.min.js'),
            'wp-util' => includes_url('js/wp-util.min.js'),
            'wp-backbone' => includes_url('js/wp-backbone.min.js'),
            'media-models' => includes_url('js/media-models.min.js'),
            'wp-plupload' => includes_url('js/plupload/wp-plupload.min.js'),
            'wp-mediaelement' => includes_url('js/mediaelement/wp-mediaelement.min.js'),
            'media-views' => includes_url('js/media-views.min.js'),
            'media-editor' => includes_url('js/media-editor.min.js'),
            'media-audiovideo' => includes_url('js/media-audiovideo.min.js'),
            'mce-view' => includes_url('js/mce-view.min.js'),
            'image-edit' => admin_url('js/image-edit.min.js')
        );

        $styles_to_check = array(
            'dashicons' => includes_url('css/dashicons.min.css'),
            'buttons' => includes_url('css/buttons.min.css'),
            'common' => admin_url('css/common.min.css'),
            'forms' => admin_url('css/forms.min.css'),
            'media-views' => includes_url('css/media-views.min.css'),
            'imgareaselect' => includes_url('js/imgareaselect/imgareaselect.css'), // Fixed path
            'wp-admin' => admin_url('css/wp-admin.min.css'),
            'colors-modern' => admin_url('css/colors/modern/colors.min.css') // Changed from 'fresh' to 'modern'
        );

        // Filter out non-existent files to avoid 404 errors
        $scripts = array();
        foreach ($scripts_to_check as $handle => $url) {
            $file_path = str_replace(
                array(includes_url(), admin_url()),
                array(ABSPATH . WPINC . '/', ABSPATH . 'wp-admin/'),
                $url
            );
            if (file_exists($file_path)) {
                $scripts[$handle] = $url;
            } else {
                lingua_debug_log('[LINGUA v5.2.40] Skipping non-existent script: ' . $file_path);
            }
        }

        $styles = array();
        foreach ($styles_to_check as $handle => $url) {
            $file_path = str_replace(
                array(includes_url(), admin_url()),
                array(ABSPATH . WPINC . '/', ABSPATH . 'wp-admin/'),
                $url
            );
            if (file_exists($file_path)) {
                $styles[$handle] = $url;
            } else {
                lingua_debug_log('[LINGUA v5.2.40] Skipping non-existent style: ' . $file_path);
            }
        }

        // Generate script tags HTML
        // v5.5: Use wp_get_script_tag() for proper attributes (WP 5.7+), fallback for older WP
        $script_html = '';
        foreach ($scripts as $handle => $url) {
            if (function_exists('wp_get_script_tag')) {
                $script_html .= wp_get_script_tag(array(
                    'src' => esc_url($url),
                    'id'  => esc_attr($handle) . '-js',
                )) . "\n";
            } else {
                $script_html .= '<script src="' . esc_url($url) . '" id="' . esc_attr($handle) . '-js"></script>' . "\n";
            }
        }

        // Generate style tags HTML
        $style_html = '';
        foreach ($styles as $handle => $url) {
            $style_html .= '<link rel="stylesheet" id="' . esc_attr($handle) . '-css" href="' . esc_url($url) . '" type="text/css" media="all" />' . "\n";
        }

        // Media library settings (required for wp.media to work)
        $settings = array(
            'post' => array('id' => $post_id),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('media-form')
        );

        lingua_debug_log('[LINGUA v5.2.7] Media Library scripts prepared successfully');

        wp_send_json_success(array(
            'scripts' => $script_html,
            'styles' => $style_html,
            'settings' => $settings,
            'message' => 'Media Library loaded successfully'
        ));
    }

    // Placeholder methods for hooks
    public function add_frontend_translate_button() {}
    /**
     * AJAX метод получения переводимого контента
     * Теперь использует полный DOM парсинг вместо гибридного подхода
     */
    public function ajax_get_translatable_content() {
        // v1.0.7: Load frontend components needed for DOM extraction
        // These are lazy-loaded and not available during AJAX by default
        if (function_exists('lingua_load_frontend_components')) {
            lingua_load_frontend_components();
        }
        if (function_exists('lingua_load_admin_components')) {
            lingua_load_admin_components();
        }

        // v5.2.79: ABSOLUTE CRITICAL - This MUST show in logs if function is called
        lingua_debug_log('🚨🚨🚨 LINGUA v5.2.79 CRITICAL: ajax_get_translatable_content() FUNCTION ENTRY POINT 🚨🚨🚨');
        lingua_debug_log('🚨 Backtrace: ' . wp_debug_backtrace_summary());

        $start_time = microtime(true);
        lingua_debug_log('[Lingua AJAX] ajax_get_translatable_content called');
        lingua_debug_log('[Lingua AJAX] POST data: ' . wp_json_encode($_POST));
        lingua_debug_log('[Lingua AJAX] Current user ID: ' . get_current_user_id());

        // КРИТИЧЕСКАЯ ПРОВЕРКА: Nonce validation
        // v5.2.78: CRITICAL - Generate test nonce to compare
        $test_nonce = wp_create_nonce('lingua_admin_nonce');
        $provided_nonce = sanitize_text_field(wp_unslash($_POST['nonce'] ?? ''));
        $verify_result = wp_verify_nonce($provided_nonce, 'lingua_admin_nonce');

        lingua_debug_log('[LINGUA v5.2.78 NONCE DEBUG] ===== NONCE DIAGNOSTIC =====');
        lingua_debug_log('[LINGUA v5.2.78 NONCE] User ID: ' . get_current_user_id());
        lingua_debug_log('[LINGUA v5.2.78 NONCE] Current URL: ' . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')));
        lingua_debug_log('[LINGUA v5.2.78 NONCE] Provided nonce: ' . $provided_nonce);
        lingua_debug_log('[LINGUA v5.2.78 NONCE] Test fresh nonce: ' . $test_nonce);
        lingua_debug_log('[LINGUA v5.2.78 NONCE] Match: ' . ($provided_nonce === $test_nonce ? 'YES' : 'NO'));
        lingua_debug_log('[LINGUA v5.2.78 NONCE] Verify result: ' . var_export($verify_result, true) . ' (1=success within 12h, 2=success within 24h, false=fail)');
        lingua_debug_log('[LINGUA v5.2.78 NONCE DEBUG] ================================');

        if (!$provided_nonce || $verify_result === false) {
            lingua_debug_log('[Lingua AJAX] ❌ Nonce verification FAILED');
            // v5.2.78: TEMPORARY FIX - Skip nonce check if test nonce matches
            if ($provided_nonce === $test_nonce) {
                lingua_debug_log('[LINGUA v5.2.78] ⚠️ Nonce verification failed BUT fresh nonce matches - allowing request');
            } else {
                wp_send_json_error('Security check failed - invalid nonce');
                return;
            }
        }

        lingua_debug_log('[Lingua AJAX] ✅ Nonce verification passed');

        lingua_debug_log('[Lingua AJAX] Nonce verification passed');

        // Проверка безопасности
        if (!current_user_can(lingua_translating_capability())) {
            lingua_debug_log('[Lingua AJAX] Authorization failed for user ID: ' . get_current_user_id());
            wp_send_json_error('Unauthorized - insufficient capabilities');
            return;
        }

        lingua_debug_log('[Lingua AJAX] Authorization passed for user ID: ' . get_current_user_id());

        // v5.3.33: Clear ALL HTML/content caches to ensure fresh extraction of ORIGINAL content
        // This is critical because caches might contain translated HTML from previous page views
        $post_id_temp = intval($_POST['post_id'] ?? 0);
        if ($post_id_temp > 0) {
            delete_transient('lingua_extracted_content_' . $post_id_temp);
            delete_transient('lingua_extracted_content_' . $post_id_temp . '_en');
            delete_transient('lingua_extracted_content_' . $post_id_temp . '_ru');
            // v5.3.33: Also clear HTML caches used by get_page_html()
            delete_transient('lingua_html_cache_' . $post_id_temp);
            delete_transient('lingua_original_html_' . $post_id_temp);
            lingua_debug_log('[Lingua AJAX v5.3.33] Cleared all caches for post ' . $post_id_temp);
        }

        // Получение target language для загрузки существующих переводов
        $target_language = sanitize_text_field($_POST['target_language'] ?? '');
        if (empty($target_language)) {
            lingua_debug_log('[Lingua AJAX] Target language is empty');
            wp_send_json_error('Target language is required');
            return;
        }

        lingua_debug_log('[Lingua AJAX] Target language: ' . $target_language);

        // v3.8: Check if this is a taxonomy/archive page
        $page_type = sanitize_text_field($_POST['page_type'] ?? 'post');
        $term_id = intval($_POST['term_id'] ?? 0);
        $taxonomy = sanitize_text_field($_POST['taxonomy'] ?? '');

        lingua_debug_log('[Lingua AJAX v3.8] Page type: ' . $page_type);
        lingua_debug_log('[Lingua AJAX v3.8] Term ID: ' . $term_id);
        lingua_debug_log('[Lingua AJAX v3.8] Taxonomy: ' . $taxonomy);

        // v3.8: For taxonomy pages, use URL-based extraction instead of post-based
        if ($page_type === 'taxonomy' && $term_id > 0) {
            lingua_debug_log('[Lingua AJAX v3.8] Processing taxonomy archive page');

            try {
                // Extract from URL instead of post
                $current_url = sanitize_url($_POST['current_url'] ?? '');
                if (empty($current_url)) {
                    wp_send_json_error('Current URL is required for archive pages');
                    return;
                }

                lingua_debug_log('[Lingua AJAX v3.8] Extracting from taxonomy URL: ' . $current_url);

                // v5.0.4: Extract taxonomy meta (Yoast SEO, term name, description) FIRST
                $taxonomy_meta = $this->extract_taxonomy_meta($term_id, $taxonomy);

                // Use URL-based DOM extraction for page content
                $unified_structure = $this->extract_content_from_url($current_url);

                lingua_debug_log('[Lingua AJAX v3.8] DOM extraction completed for taxonomy');

                // v5.0.4: Merge taxonomy meta into SEO fields (prioritize database over HTML)
                if (!empty($taxonomy_meta['seo_fields'])) {
                    // Put taxonomy SEO fields FIRST (they are from database, more reliable)
                    $unified_structure['seo_fields'] = array_merge(
                        $taxonomy_meta['seo_fields'],
                        $unified_structure['seo_fields'] ?? array()
                    );
                } else {
                    // If no Yoast SEO, label the HTML-extracted fields appropriately
                    if (!empty($unified_structure['seo_fields'])) {
                        foreach ($unified_structure['seo_fields'] as &$field) {
                            if (empty($field['label'])) {
                                $field['label'] = 'SEO Meta (from HTML)';
                            }
                        }
                    }
                }

                // v5.0.4: Add term name and description as core fields
                if (!empty($taxonomy_meta['core_fields'])) {
                    $unified_structure['core_fields'] = $taxonomy_meta['core_fields'];
                }

                // Apply existing translations (use term_id as context instead of post_id)
                lingua_debug_log('[Lingua v5.0.5 DEBUG] SEO fields BEFORE apply_translations: ' . wp_json_encode($unified_structure['seo_fields'] ?? []));
                $unified_structure = $this->apply_existing_translations_for_taxonomy($unified_structure, $term_id, $taxonomy, $target_language);
                lingua_debug_log('[Lingua v5.0.5 DEBUG] SEO fields AFTER apply_translations: ' . wp_json_encode($unified_structure['seo_fields'] ?? []));

                // Return response
                $response_data = array(
                    'content_blocks' => $unified_structure['content_blocks'] ?? array(),
                    'page_strings' => $unified_structure['page_strings'] ?? array(),
                    'attributes' => $unified_structure['attributes'] ?? array(),
                    'seo_fields' => $unified_structure['seo_fields'] ?? array(),
                    'meta_fields' => $unified_structure['meta_fields'] ?? array(),
                    'taxonomy_terms' => $unified_structure['taxonomy_terms'] ?? array(),
                    'media' => $unified_structure['media'] ?? array(),
                    'page_type' => 'taxonomy',
                    'term_id' => $term_id,
                    'taxonomy' => $taxonomy
                );

                lingua_debug_log('[Lingua v5.0.5 FINAL] Sending ' . count($response_data['seo_fields']) . ' SEO fields to browser:');
                foreach ($response_data['seo_fields'] as $i => $field) {
                    lingua_debug_log('  SEO #' . ($i+1) . ': ' . substr($field['original'], 0, 50) . '...');
                }

                wp_send_json_success($response_data);
                return;

            } catch (Exception $e) {
                lingua_debug_log('[Lingua AJAX v3.8] Exception in taxonomy extraction: ' . $e->getMessage());
                wp_send_json_error('Failed to extract taxonomy content: ' . $e->getMessage());
                return;
            }
        }

        // v5.5.0: Handle post_type_archive pages (e.g. /docs/, /products/, etc.)
        // These pages have post_id=0 and need URL-based extraction like taxonomy pages
        if ($page_type === 'post_type_archive') {
            lingua_debug_log('[Lingua AJAX v5.5.0] Processing post_type_archive page');

            try {
                $current_url = sanitize_url($_POST['current_url'] ?? '');
                if (empty($current_url)) {
                    wp_send_json_error('Current URL is required for archive pages');
                    return;
                }

                lingua_debug_log('[Lingua AJAX v5.5.0] Extracting from archive URL: ' . $current_url);

                // Use URL-based DOM extraction for page content (same approach as taxonomy)
                $unified_structure = $this->extract_content_from_url($current_url);

                lingua_debug_log('[Lingua AJAX v5.5.0] DOM extraction completed for post_type_archive');

                // Build response
                $response_data = array(
                    'content_blocks' => $unified_structure['content_blocks'] ?? array(),
                    'page_strings' => $unified_structure['page_strings'] ?? array(),
                    'attributes' => $unified_structure['attributes'] ?? array(),
                    'seo_fields' => $unified_structure['seo_fields'] ?? array(),
                    'meta_fields' => $unified_structure['meta_fields'] ?? array(),
                    'taxonomy_terms' => $unified_structure['taxonomy_terms'] ?? array(),
                    'media' => $unified_structure['media'] ?? array(),
                    'page_type' => 'post_type_archive',
                    'post_id' => 0,
                    'target_language' => $target_language,
                    'extraction_method' => 'url_based_dom_v5.5.0'
                );

                $total_items = 0;
                foreach ($response_data as $key => $items) {
                    if (is_array($items) && in_array($key, ['content_blocks', 'page_strings', 'taxonomy_terms', 'attributes', 'seo_fields', 'media'])) {
                        $total_items += count($items);
                    }
                }

                lingua_debug_log('[Lingua AJAX v5.5.0] Archive response with ' . $total_items . ' total items');

                if ($total_items === 0) {
                    lingua_debug_log('[Lingua AJAX v5.5.0] WARNING: No content found for archive page');
                    wp_send_json_error('No text to translate found on this archive page. Please check if the page has extractable content.');
                    return;
                }

                wp_send_json_success($response_data);
                return;

            } catch (Exception $e) {
                lingua_debug_log('[Lingua AJAX v5.5.0] Exception in archive extraction: ' . $e->getMessage());
                wp_send_json_error('Failed to extract archive content: ' . $e->getMessage());
                return;
            }
        }

        // Regular post/page extraction (existing logic)
        $post_id = $this->determine_post_id();
        if (!$post_id) {
            lingua_debug_log('[Lingua AJAX] Could not determine post ID');
            wp_send_json_error('Invalid post ID or front page not found');
            return;
        }

        lingua_debug_log('[Lingua AJAX] Post ID determined: ' . $post_id);

        // Проверка существования поста
        $post = get_post($post_id);
        if (!$post) {
            lingua_debug_log('[Lingua AJAX] Post not found for ID: ' . $post_id);
            wp_send_json_error('Post not found');
            return;
        }

        lingua_debug_log('[Lingua AJAX] Post found: ' . $post->post_title);

        try {
            lingua_debug_log('[Lingua AJAX] Starting DOM extraction for post ' . $post_id);

            // НОВАЯ АРХИТЕКТУРА: Используем полный DOM парсинг
            $unified_structure = $this->extract_content_via_dom($post_id);

            lingua_debug_log('[Lingua AJAX] DOM extraction completed. Structure keys: ' . implode(', ', array_keys($unified_structure)));

            // Применяем существующие переводы к извлеченной структуре
            $unified_structure = $this->apply_existing_translations($unified_structure, $post_id, $target_language);

            lingua_debug_log('[Lingua AJAX] Applied existing translations');

            // Логируем статистику для отладки
            $this->log_extraction_stats($post_id, $target_language, $unified_structure);

            // Формируем ответ ТОЛЬКО в новом v3.0 формате (убираем legacy)
            $response_data = array(
                // Основные данные для v3.0 UI
                'content_blocks' => $unified_structure['content_blocks'] ?? array(),
                'page_strings' => $unified_structure['page_strings'] ?? array(),
                'taxonomy_terms' => $unified_structure['taxonomy_terms'] ?? array(),
                'attributes' => $unified_structure['attributes'] ?? array(),

                // v3.2: SEO поля
                'seo_fields' => $unified_structure['seo_fields'] ?? array(),

                // v5.1: Media elements (images)
                'media' => $unified_structure['media'] ?? array(),

                // Метаинформация
                'post_id' => $post_id,
                'target_language' => $target_language,
                'extraction_method' => 'full_dom_parsing_v5.1'
            );

            $total_items = 0;
            foreach ($response_data as $key => $items) {
                if (is_array($items) && in_array($key, ['content_blocks', 'page_strings', 'taxonomy_terms', 'attributes', 'seo_fields', 'media'])) {
                    $total_items += count($items);
                }
            }

            lingua_debug_log('[Lingua AJAX] Response prepared with ' . $total_items . ' total items');
            lingua_debug_log('[LINGUA v3.2 DEBUG] seo_fields count: ' . count($response_data['seo_fields']));
            lingua_debug_log('[LINGUA v3.2 DEBUG] content_blocks count: ' . count($response_data['content_blocks']));
            lingua_debug_log('[LINGUA v3.2 DEBUG] page_strings count: ' . count($response_data['page_strings']));
            lingua_debug_log('[LINGUA v5.1 DEBUG] media count: ' . count($response_data['media']));

            // v5.2.44 DEBUG: Show first content_blocks with plural_pair
            if (!empty($response_data['content_blocks'])) {
                $count = 0;
                foreach ($response_data['content_blocks'] as $block) {
                    if (isset($block['plural_pair']) && !empty($block['plural_pair'])) {
                        lingua_debug_log('[LINGUA v5.2.44 DEBUG] Content block with plural_pair: ' . wp_json_encode([
                            'original' => $block['original'] ?? $block['original_text'] ?? 'N/A',
                            'plural_pair' => $block['plural_pair'],
                            'is_plural' => $block['is_plural'] ?? false
                        ]));
                        $count++;
                        if ($count >= 5) break; // Show first 5
                    }
                }
                if ($count === 0) {
                    lingua_debug_log('[LINGUA v5.2.44 DEBUG] ❌ No content_blocks with plural_pair found!');
                } else {
                    lingua_debug_log('[LINGUA v5.2.44 DEBUG] ✅ Found ' . $count . ' content_blocks with plural_pair');
                }
            }

            // Debug: Show first SEO field if exists
            if (!empty($response_data['seo_fields'])) {
                $first_seo = $response_data['seo_fields'][0];
                lingua_debug_log('[LINGUA v3.2 DEBUG] First SEO field type: ' . ($first_seo['type'] ?? 'unknown'));
            }

            // КРИТИЧЕСКАЯ ПРОВЕРКА: Если нет контента для перевода
            if ($total_items === 0) {
                lingua_debug_log('[Lingua AJAX] ERROR: No content found to translate for post ' . $post_id);
                lingua_debug_log('[Lingua AJAX] Unified structure: ' . wp_json_encode($unified_structure));
                wp_send_json_error('No text to translate found on this page. Please check if the page has extractable content.');
                return;
            }

            // Возвращаем полный унифицированный ответ
            $elapsed = microtime(true) - $start_time;
            lingua_debug_log('[Lingua AJAX] ⏱️ Total extraction time: ' . round($elapsed, 2) . ' seconds');
            wp_send_json_success($response_data);

        } catch (Exception $e) {
            lingua_debug_log('[Lingua AJAX] Exception in ajax_get_translatable_content: ' . $e->getMessage());
            lingua_debug_log('[Lingua AJAX] Exception trace: ' . $e->getTraceAsString());
            wp_send_json_error('Failed to extract translatable content: ' . $e->getMessage());
        }
    }

    /**
     * Определение ID поста (обычный пост или главная страница) - УЛУЧШЕННАЯ ВЕРСИЯ
     * Поддерживает URL parsing для WooCommerce товаров
     */
    private function determine_post_id() {
        // Проверяем главную страницу
        $is_front_page = isset($_POST['is_front_page']) && $_POST['is_front_page'] === 'true';

        if ($is_front_page) {
            $post_id = get_option('page_on_front');
            if (!$post_id) {
                // Пытаемся найти первую опубликованную страницу
                $front_page = get_posts(array(
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    'orderby' => 'menu_order',
                    'order' => 'ASC'
                ));
                $post_id = !empty($front_page) ? $front_page[0]->ID : 0;
            }
        } else {
            $post_id = intval($_POST['post_id'] ?? 0);

            // КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ: Если post_id не найден, пытаемся определить из URL
            if (!$post_id || $post_id === 0) {
                lingua_debug_log('[Lingua AJAX] Post ID not provided, attempting URL-based detection');
                $post_id = $this->detect_post_id_from_current_url();
                lingua_debug_log('[Lingua AJAX] URL-based detection result: ' . $post_id);
            }
        }

        return $post_id;
    }

    /**
     * КРИТИЧЕСКИЙ МЕТОД: Определение post_id из текущего URL
     * Поддерживает WooCommerce продукты на переведенных URL типа /en/product/...
     */
    private function detect_post_id_from_current_url() {
        global $wp, $wp_query;

        // Метод 1: Использование глобальных WordPress переменных
        if (isset($wp_query->post->ID) && $wp_query->post->ID > 0) {
            lingua_debug_log('[Lingua AJAX] Post ID found via wp_query: ' . $wp_query->post->ID);
            return $wp_query->post->ID;
        }

        // Метод 2: Анализ переданного URL из JavaScript (приоритет) или $_SERVER['REQUEST_URI']
        $request_uri = sanitize_text_field(wp_unslash($_POST['request_uri'] ?? $_SERVER['REQUEST_URI'] ?? ''));
        lingua_debug_log('[Lingua AJAX] Analyzing REQUEST_URI: ' . $request_uri);

        // Удаляем языковой префикс из URL (/en/, /ru/, и т.д.)
        $clean_uri = preg_replace('/^\/[a-z]{2}\//', '/', $request_uri);
        lingua_debug_log('[Lingua AJAX] Clean URI after language removal: ' . $clean_uri);

        // Метод 3: Использование url_to_postid() для очищенного URL
        $home_url = home_url();
        $full_url = rtrim($home_url, '/') . $clean_uri;
        lingua_debug_log('[Lingua AJAX] Attempting url_to_postid with: ' . $full_url);

        // Также пробуем с переданным полным URL
        if (isset($_POST['current_url']) && !empty($_POST['current_url'])) {
            $current_url = sanitize_url($_POST['current_url']);
            lingua_debug_log('[Lingua AJAX] Also trying with JavaScript URL: ' . $current_url);
            $post_id_from_js_url = url_to_postid($current_url);
            if ($post_id_from_js_url > 0) {
                lingua_debug_log('[Lingua AJAX] Post ID found via JavaScript URL: ' . $post_id_from_js_url);
                return $post_id_from_js_url;
            }
        }

        $post_id = url_to_postid($full_url);
        if ($post_id > 0) {
            lingua_debug_log('[Lingua AJAX] Post ID found via url_to_postid: ' . $post_id);
            return $post_id;
        }

        // Метод 4: Специально для WooCommerce продуктов
        if (strpos($clean_uri, '/product/') !== false && function_exists('wc_get_product_id_by_sku')) {
            // Извлекаем slug продукта из URL
            $product_slug = basename(parse_url($clean_uri, PHP_URL_PATH));
            lingua_debug_log('[Lingua AJAX] WooCommerce product slug: ' . $product_slug);

            // Поиск продукта по slug
            $product = get_page_by_path($product_slug, OBJECT, 'product');
            if ($product && $product->ID > 0) {
                lingua_debug_log('[Lingua AJAX] WooCommerce product ID found: ' . $product->ID);
                return $product->ID;
            }
        }

        // Метод 5: Последняя попытка - используем wp_parse_request
        $wp_temp = new WP();
        $wp_temp->parse_request($clean_uri);
        if (isset($wp_temp->query_vars['p']) && $wp_temp->query_vars['p'] > 0) {
            lingua_debug_log('[Lingua AJAX] Post ID found via parse_request: ' . $wp_temp->query_vars['p']);
            return intval($wp_temp->query_vars['p']);
        }

        lingua_debug_log('[Lingua AJAX] Could not detect post ID from URL');
        return 0;
    }

    /**
     * НОВЫЙ МЕТОД: Извлечение контента через полный DOM парсинг
     * Заменяет старый гибридный подход
     */
    private function extract_content_via_dom($post_id) {
        lingua_debug_log('[Lingua AJAX] extract_content_via_dom started for post ' . $post_id);

        // Отключаем фильтры переводов для получения оригинального контента
        $this->disable_translation_filters();

        try {
            // Используем новый Full DOM Extractor v2.0 для извлечения контента
            if (!class_exists('Lingua_Full_Dom_Extractor')) {
                throw new Exception('Lingua_Full_Dom_Extractor class not found');
            }

            lingua_debug_log('[Lingua AJAX] Lingua_Full_Dom_Extractor class found');
            $dom_extractor = new Lingua_Full_Dom_Extractor();

            // Получаем HTML страницы для парсинга
            lingua_debug_log('[Lingua AJAX] Getting page HTML for post ' . $post_id);
            $page_html = $this->get_page_html($post_id);

            if (!$page_html) {
                throw new Exception('Could not retrieve page HTML for post ' . $post_id);
            }

            lingua_debug_log('[Lingua AJAX] Page HTML retrieved, length: ' . strlen($page_html));

            // Извлекаем контент через полный DOM парсинг
            lingua_debug_log('[Lingua AJAX] Starting DOM extraction');
            $unified_structure = $dom_extractor->extract_from_full_html($page_html, $post_id);

            lingua_debug_log('[Lingua AJAX] DOM extraction completed successfully');

        } catch (Exception $e) {
            lingua_debug_log('[Lingua AJAX] DOM extraction error: ' . $e->getMessage());
            lingua_debug_log('[Lingua AJAX] Exception trace: ' . $e->getTraceAsString());
            // Возвращаем пустую структуру в случае ошибки
            $unified_structure = array(
                'content_blocks' => array(),
                'core_fields' => array(),
                'seo_fields' => array(),
                'meta_fields' => array(),
                'taxonomy_terms' => array(),
                'attributes' => array(),
                'page_strings' => array()
            );
        }

        // Восстанавливаем фильтры переводов
        $this->restore_translation_filters();
        lingua_debug_log('[Lingua AJAX] extract_content_via_dom completed');
        return $unified_structure;
    }

    /**
     * PHASE 2: Get HTML страницы with transient cache
     */
    private function get_page_html($post_id) {
        lingua_debug_log('[Lingua AJAX] get_page_html started for post ' . $post_id);

        // Step 1: Check transient cache first (1 hour TTL)
        $cache_key = 'lingua_html_cache_' . $post_id;
        $cached = get_transient($cache_key);

        if ($cached !== false && is_string($cached)) {
            lingua_debug_log('[Lingua AJAX] Found cached HTML in transient, length: ' . strlen($cached));
            return $cached;
        }

        // Step 2: Try Output Buffer cache
        if (class_exists('Lingua_Output_Buffer')) {
            lingua_debug_log('[Lingua AJAX] Checking output buffer cache');
            $output_buffer = new Lingua_Output_Buffer();
            $cached_html = $output_buffer->get_cached_content($post_id);

            if (!empty($cached_html) && is_string($cached_html)) {
                lingua_debug_log('[Lingua AJAX] Found HTML in Output Buffer, length: ' . strlen($cached_html));
                // Store in transient for faster access
                set_transient($cache_key, $cached_html, HOUR_IN_SECONDS);
                return $cached_html;
            }
        }

        // Step 3: HTTP Fallback на дефолтный язык (v3.0 FIX)
        // Если кэша нет - делаем HTTP запрос на ДЕФОЛТНУЮ языковую версию страницы
        lingua_debug_log('[Lingua AJAX] No HTML in cache - making HTTP request to DEFAULT language URL');

        $post = get_post($post_id);
        if (!$post) {
            lingua_debug_log('[Lingua AJAX] ERROR: Post not found for ID ' . $post_id);
            return false;
        }

        // Получаем URL дефолтного языка (без языкового префикса)
        $default_url = get_permalink($post_id);

        // v3.6 FIX: Add special parameter to force default language (skip translations)
        $default_url = add_query_arg('lingua_force_default', '1', $default_url);

        // v5.4.1 FIX: Convert external URL to internal for loopback requests in Docker/proxy environments
        // WordPress stores siteurl with the external port (e.g. localhost:8080), but inside the container
        // Apache listens on port 80. wp_remote_get to :8080 fails with "Connection refused".
        $parsed = parse_url($default_url);
        if (!empty($parsed['port']) && $parsed['port'] != 80 && $parsed['port'] != 443) {
            // Replace the host:port with just host (port 80) for internal loopback
            $internal_url = str_replace(
                $parsed['host'] . ':' . $parsed['port'],
                $parsed['host'],
                $default_url
            );
            lingua_debug_log('[Lingua AJAX v5.4.1] Converted external URL to internal: ' . $default_url . ' → ' . $internal_url);
            $default_url = $internal_url;
        }

        // v5.5.0: Prepare Host header for loopback requests
        $site_url_parsed = parse_url(get_option('siteurl'));
        $host_header = $site_url_parsed['host'];
        if (!empty($site_url_parsed['port'])) {
            $host_header .= ':' . $site_url_parsed['port'];
        }

        lingua_debug_log('[Lingua AJAX] Fetching HTML from default language URL: ' . $default_url);

        // Делаем HTTP запрос
        // v5.3.1: Increased timeout to 60s to prevent 502 on slow pages
        // v5.4.1: Send original Host header to prevent canonical redirect in Docker/proxy environments

        $response = wp_remote_get($default_url, array(
            'timeout' => 60,
            'sslverify' => false,
            'redirection' => 0,
            'headers' => array(
                'User-Agent' => 'Lingua Translation Plugin/3.0',
                'Host' => $host_header
            )
        ));

        // v5.5.0 FIX: If HTTPS loopback fails (e.g. Docker/proxy where SSL is handled externally),
        // fallback to HTTP localhost with Host header and X-Forwarded-Proto
        if (is_wp_error($response)) {
            lingua_debug_log('[Lingua AJAX] Primary HTTP request failed: ' . $response->get_error_message());

            $parsed_url = parse_url($default_url);
            if (!empty($parsed_url['scheme']) && $parsed_url['scheme'] === 'https') {
                $internal_url = 'http://localhost' . ($parsed_url['path'] ?? '/');
                if (!empty($parsed_url['query'])) {
                    $internal_url .= '?' . $parsed_url['query'];
                }
                lingua_debug_log('[Lingua AJAX v5.5.0] Retrying with internal HTTP: ' . $internal_url);

                $response = wp_remote_get($internal_url, array(
                    'timeout' => 60,
                    'sslverify' => false,
                    'redirection' => 0,
                    'headers' => array(
                        'User-Agent' => 'Lingua Translation Plugin/3.0',
                        'Host' => $host_header,
                        'X-Forwarded-Proto' => 'https'
                    )
                ));
            }
        }

        if (is_wp_error($response)) {
            lingua_debug_log('[Lingua AJAX] HTTP request failed: ' . $response->get_error_message());
            return false;
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            lingua_debug_log('[Lingua AJAX] ERROR: Empty HTML received from ' . $default_url);
            return false;
        }

        lingua_debug_log('[Lingua AJAX] Successfully fetched HTML via HTTP, length: ' . strlen($html));

        // Кэшируем полученный HTML
        set_transient($cache_key, $html, HOUR_IN_SECONDS);

        return $html;
    }

    /**
     * Отключение фильтров переводов для получения оригинального контента
     */
    private function disable_translation_filters() {
        if (class_exists('Lingua_Translation_Manager')) {
            $translation_manager = new Lingua_Translation_Manager();
            remove_filter('the_content', array($translation_manager, 'translate_content'));
            remove_filter('the_title', array($translation_manager, 'translate_title'));
            remove_filter('the_excerpt', array($translation_manager, 'translate_excerpt'));
        }
    }

    /**
     * Восстановление фильтров переводов
     */
    private function restore_translation_filters() {
        if (class_exists('Lingua_Translation_Manager')) {
            $translation_manager = new Lingua_Translation_Manager();
            add_filter('the_content', array($translation_manager, 'translate_content'));
            add_filter('the_title', array($translation_manager, 'translate_title'));
            add_filter('the_excerpt', array($translation_manager, 'translate_excerpt'));
        }
    }

    /**
     * v5.2.91: Get number of plural forms for a language
     * Returns count programmatically instead of loading from .po files
     */
    private function get_plural_forms_count($language_code) {
        // Based on CLDR plural rules (https://www.unicode.org/cldr/charts/latest/supplemental/language_plural_rules.html)
        $plural_rules = array(
            // 3 forms: n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2
            'ru' => 3,  // товар, товара, товаров
            'uk' => 3,  // Ukrainian
            'be' => 3,  // Belarusian
            'sr' => 3,  // Serbian
            'hr' => 3,  // Croatian
            'cs' => 3,  // Czech
            'sk' => 3,  // Slovak

            // 2 forms: n != 1 ? 1 : 0
            'en' => 2,  // product, products
            'de' => 2,  // Produkt, Produkte
            'fr' => 2,  // produit, produits
            'it' => 2,  // prodotto, prodotti
            'es' => 2,  // producto, productos
            'pt' => 2,  // produto, produtos
            'nl' => 2,  // Dutch
            'sv' => 2,  // Swedish
            'da' => 2,  // Danish
            'no' => 2,  // Norwegian

            // 4 forms: Slovenian, Scottish Gaelic
            'sl' => 4,
            'gd' => 4,  // Scottish Gaelic

            // 5 forms: Irish, Breton
            'ga' => 5,  // Irish
            'br' => 5,  // Breton

            // 6 forms: Arabic
            'ar' => 6,

            // 1 form: n > 1 ? 1 : 0 (no plurals)
            'zh' => 1,  // Chinese
            'ja' => 1,  // Japanese
            'ko' => 1,  // Korean
            'tr' => 1,  // Turkish
            'vi' => 1,  // Vietnamese
        );

        return $plural_rules[$language_code] ?? 2;  // Default to 2 forms
    }

    /**
     * Применение существующих переводов к извлеченной структуре
     */
    private function apply_existing_translations($unified_structure, $post_id, $target_language) {
        if (empty($unified_structure)) {
            return $unified_structure;
        }

        // Получаем менеджер переводов
        $translation_manager = new Lingua_Translation_Manager();

        // v5.2.91: CRITICAL FIX - Get plural forms count programmatically
        // Instead of loading from .po files, determine count based on language code
        $target_plural_forms_count = $this->get_plural_forms_count($target_language);
        lingua_debug_log('🎯 LINGUA v5.2.91: Plural forms count for ' . $target_language . ' = ' . $target_plural_forms_count);

        // v5.2.91: REMOVED - No longer loading from .po files
        // We only load .po files for DEFAULT language translations (for pre-filling)
        // Plural forms count is determined programmatically above

        $found_translations = 0;
        $total_items = 0;

        // Проходим по всем группам и элементам
        lingua_debug_log("[LINGUA v5.2.165 DEBUG] Starting apply_existing_translations for post_id={$post_id}, lang={$target_language}");
        foreach ($unified_structure as $group_name => &$items) {
            if (!is_array($items)) {
                continue;
            }

            lingua_debug_log("[LINGUA v5.2.165 DEBUG] Processing group: {$group_name}, items count: " . count($items));

            foreach ($items as &$item) {
                if (!isset($item['original'])) {
                    continue;
                }

                $total_items++;
                $existing_translation = null;

                // v5.2: For media items, search by context instead of original text
                if ($group_name === 'media' && isset($item['src_hash']) && isset($item['attribute'])) {
                    $context = "media.src[{$item['src_hash']}].{$item['attribute']}";
                    $existing_translation = $translation_manager->get_translation_by_context(
                        $context,
                        $target_language
                    );
                } else if (isset($item['is_plural']) && $item['is_plural'] === true && isset($item['msgid'])) {
                    // v5.2.114: CRITICAL FIX - Plural forms must use get_plural_translation() with plural_form_index
                    // Database stores both singular and plural under original_text='product' (singular msgid)
                    // differentiated by plural_form column (0, 1, 2, etc.)

                    // Determine plural_form_index: If original matches msgid (singular), use form 0
                    // If original matches msgid_plural, determine form index based on language
                    $is_singular_form = ($item['original'] === $item['msgid']);

                    if ($is_singular_form) {
                        // Singular form (e.g., "product") → plural_form=0
                        $plural_form_index = 0;
                    } else {
                        // Plural form (e.g., "products") → use last form index for this language
                        // Italian: 2 forms → use form 1 for plural
                        // Russian: 3 forms → use form 2 for "many"
                        $plural_form_index = $target_plural_forms_count - 1;
                    }

                    $existing_translation = $translation_manager->get_plural_translation(
                        $item['msgid'],  // Always use singular msgid as base
                        $target_language,
                        $plural_form_index,
                        $item['domain'] ?? ''
                    );

                    lingua_debug_log("[LINGUA v5.2.114] Loading plural: msgid='{$item['msgid']}', original='{$item['original']}', form_index={$plural_form_index}, translation='" . ($existing_translation ?: 'NULL') . "'");
                } else if ($group_name === 'seo_fields') {
                    // v5.2.165: SEO fields need context-based lookup to differentiate
                    // og_description vs meta_description when they have the same original text
                    lingua_debug_log("[LINGUA v5.2.165 DEBUG] SEO item: type=" . ($item['type'] ?? 'NOT SET') . ", original=" . substr($item['original'], 0, 30));
                    if (isset($item['type'])) {
                        $seo_context = "post_{$post_id}_{$item['type']}";
                        $existing_translation = $translation_manager->get_translation_by_context(
                            $seo_context,
                            $target_language
                        );
                        lingua_debug_log("[LINGUA v5.2.165] Loading SEO field: type={$item['type']}, context={$seo_context}, lang={$target_language}, translation='" . ($existing_translation ?: 'NULL') . "'");
                    } else {
                        lingua_debug_log("[LINGUA v5.2.165 ERROR] SEO field missing type! Falling back to original text search");
                        $existing_translation = $translation_manager->get_string_translation(
                            $item['original'],
                            $target_language
                        );
                    }
                } else {
                    // Ищем существующий перевод по оригинальному тексту (сохраненный пользователем)
                    $existing_translation = $translation_manager->get_string_translation(
                        $item['original'],
                        $target_language
                    );
                }

                // v5.2.91: CRITICAL FIX - Plural forms handling
                // If this item has a plural_pair, add target plural forms count (NOT actual translations)
                // JavaScript will use this count to render correct number of input fields
                if (isset($item['plural_pair']) && !empty($item['plural_pair'])) {
                    $item['target_plural_forms_count'] = $target_plural_forms_count;  // 3 for Russian, 2 for English/German
                    $item['has_target_plurals'] = true;
                    lingua_debug_log('✅ LINGUA v5.2.91: Set target_plural_forms_count=' . $target_plural_forms_count . ' for plural item: ' . substr($item['original'], 0, 30));

                    // v5.2.119: Load ALL plural forms for this item (not just form 0 or last form)
                    // This fixes the issue where forms 1 and 2 (Russian) were not pre-filled in modal
                    if ($target_plural_forms_count > 1 && isset($item['msgid'])) {
                        $all_forms = array();
                        for ($i = 0; $i < $target_plural_forms_count; $i++) {
                            $form_translation = $translation_manager->get_plural_translation(
                                $item['msgid'],
                                $target_language,
                                $i,
                                $item['domain'] ?? ''
                            );
                            if ($form_translation) {
                                $all_forms[$i] = $form_translation;
                                lingua_debug_log("[LINGUA v5.2.119] Loaded form {$i}: '{$form_translation}'");
                            }
                        }
                        $item['all_plural_forms'] = $all_forms;
                        lingua_debug_log('[LINGUA v5.2.119] Loaded ' . count($all_forms) . ' plural forms for: ' . $item['msgid']);
                    }
                }

                if ($existing_translation && !empty($existing_translation)) {
                    $item['translated'] = $existing_translation;
                    $item['status'] = 'translated';
                    $found_translations++;
                } else {
                    $item['translated'] = '';
                    $item['status'] = 'pending';
                }

                // Добавляем дополнительную информацию для UI
                $item['field_group'] = $group_name;
                $item['post_id'] = $post_id;
            }
        }

        lingua_debug_log('[Lingua AJAX v5.2.91] Found ' . $found_translations . ' user translations out of ' . $total_items . ' items (no .po loading for target language)');

        return $unified_structure;
    }

    /**
     * Конвертация SEO полей в legacy формат для обратной совместимости
     */
    private function convert_seo_fields_to_legacy($seo_fields) {
        $legacy_meta = array();

        if (!empty($seo_fields)) {
            foreach ($seo_fields as $field) {
                $field_name = $field['field_name'] ?? $field['id'] ?? '';
                $original_text = $field['original'] ?? '';

                if ($field_name && $original_text) {
                    $legacy_meta[$field_name] = $original_text;
                }
            }
        }

        return $legacy_meta;
    }

    /**
     * Логирование статистики извлечения для отладки
     */
    private function log_extraction_stats($post_id, $target_language, $unified_structure) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $stats = array(
            'post_id' => $post_id,
            'target_language' => $target_language,
            'extraction_method' => 'full_dom_parsing_v2.0'
        );

        foreach ($unified_structure as $group_name => $items) {
            $stats[$group_name . '_count'] = is_array($items) ? count($items) : 0;
        }

        $total_items = array_sum(array_filter($stats, 'is_numeric'));
        $stats['total_translatable_items'] = $total_items;

        lingua_debug_log('[Lingua AJAX v2.0] Extraction stats: ' . print_r($stats, true));

        // Логируем первые элементы каждой группы для примера
        foreach ($unified_structure as $group_name => $items) {
            if (is_array($items) && !empty($items)) {
                $first_item = reset($items);
                lingua_debug_log("[Lingua AJAX] First {$group_name} item: " . substr($first_item['original'] ?? 'no original', 0, 100));
            }
        }
    }


    // AJAX placeholder methods
    public function ajax_translate_frontend() {
        // Placeholder for frontend translation AJAX handler
        wp_send_json_error('Frontend translation not implemented yet');
    }

    /**
     * v3.8: Extract content from URL (for taxonomy/archive pages)
     */
    private function extract_content_from_url($url) {
        // v1.0.7: Ensure frontend components are loaded for DOM extraction
        if (function_exists('lingua_load_frontend_components')) {
            lingua_load_frontend_components();
        }
        lingua_debug_log('[Lingua v3.8] Extracting content from URL: ' . $url);

        // v5.3.33: Strip language prefix from URL to get default language version
        // This is critical because lingua_force_default only prevents OUTPUT translation,
        // but if the URL has /en/ prefix, server still returns English version
        $url = $this->strip_language_prefix_from_url($url);
        lingua_debug_log('[Lingua v5.3.33] URL after stripping language prefix: ' . $url);

        // Add lingua_force_default=1 to get original language
        $url = add_query_arg('lingua_force_default', '1', $url);
        lingua_debug_log('[Lingua v3.8] Modified URL with force_default: ' . $url);

        // v5.4.1 FIX: Convert external URL to internal for loopback in Docker/proxy
        $parsed = parse_url($url);
        if (!empty($parsed['port']) && $parsed['port'] != 80 && $parsed['port'] != 443) {
            $url = str_replace($parsed['host'] . ':' . $parsed['port'], $parsed['host'], $url);
            lingua_debug_log('[Lingua v5.4.1] Converted taxonomy URL to internal port: ' . $url);
        }

        // v5.5.0: Prepare Host header for loopback requests
        $site_url_parsed = parse_url(get_option('siteurl'));
        $host_header = $site_url_parsed['host'];
        if (!empty($site_url_parsed['port'])) {
            $host_header .= ':' . $site_url_parsed['port'];
        }

        lingua_debug_log('[Lingua v5.5.0] Fetching URL-based content from: ' . $url);

        // Fetch HTML - try original URL first (works on normal hosting with real SSL)
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false,
            'redirection' => 0,
            'headers' => array(
                'User-Agent' => 'Lingua Translation Plugin/3.0',
                'Host' => $host_header
            )
        ));

        // v5.5.0 FIX: If HTTPS loopback fails (e.g. Docker/proxy where SSL is handled externally),
        // fallback to HTTP localhost with Host header and X-Forwarded-Proto
        if (is_wp_error($response)) {
            lingua_debug_log('[Lingua v5.5.0] Primary HTTP request failed: ' . $response->get_error_message());

            $parsed_url = parse_url($url);
            if (!empty($parsed_url['scheme']) && $parsed_url['scheme'] === 'https') {
                $internal_url = 'http://localhost' . ($parsed_url['path'] ?? '/');
                if (!empty($parsed_url['query'])) {
                    $internal_url .= '?' . $parsed_url['query'];
                }
                lingua_debug_log('[Lingua v5.5.0] Retrying with internal HTTP: ' . $internal_url);

                $response = wp_remote_get($internal_url, array(
                    'timeout' => 30,
                    'sslverify' => false,
                    'redirection' => 0,
                    'headers' => array(
                        'User-Agent' => 'Lingua Translation Plugin/3.0',
                        'Host' => $host_header,
                        'X-Forwarded-Proto' => 'https'
                    )
                ));
            }
        }

        if (is_wp_error($response)) {
            throw new Exception('Failed to fetch URL: ' . $response->get_error_message());
        }

        $html = wp_remote_retrieve_body($response);
        lingua_debug_log('[Lingua v3.8] Fetched HTML length: ' . strlen($html) . ' bytes');

        // Use DOM extractor
        $extractor = new Lingua_Full_Dom_Extractor();
        $result = $extractor->extract_from_full_html($html, 0); // post_id = 0 for archives

        lingua_debug_log('[Lingua v3.8] Extraction complete. Found: ' . count($result['content_blocks'] ?? []) . ' content blocks');

        return $result;
    }

    /**
     * v5.3.33: Strip language prefix from URL to get default language version
     * Converts "https://site.com/en/products/category/" to "https://site.com/products/category/"
     *
     * @param string $url Full URL with potential language prefix
     * @return string URL without language prefix
     */
    private function strip_language_prefix_from_url($url) {
        // Get all enabled languages
        $enabled_languages = get_option('lingua_languages', array());
        $language_codes = array();

        foreach ($enabled_languages as $lang) {
            if (isset($lang['code']) && strlen($lang['code']) === 2) {
                $language_codes[] = $lang['code'];
            }
        }

        // Also add common language codes that might be in URL
        $language_codes = array_merge($language_codes, array('en', 'de', 'fr', 'es', 'it', 'pt', 'zh', 'ja', 'ko', 'ar'));
        $language_codes = array_unique($language_codes);

        // Parse URL
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['path'])) {
            return $url;
        }

        $path = $parsed['path'];

        // Try to remove language prefix from path
        foreach ($language_codes as $lang_code) {
            // Match /en/ at the start of path
            $pattern = '#^/' . preg_quote($lang_code, '#') . '(/|$)#';
            if (preg_match($pattern, $path)) {
                $path = preg_replace($pattern, '$1', $path);
                lingua_debug_log("[Lingua v5.3.33] Removed language prefix '{$lang_code}' from path");
                break;
            }
        }

        // Ensure path starts with /
        if (empty($path) || $path === '') {
            $path = '/';
        }

        // Rebuild URL
        $new_url = '';
        if (isset($parsed['scheme'])) {
            $new_url .= $parsed['scheme'] . '://';
        }
        if (isset($parsed['host'])) {
            $new_url .= $parsed['host'];
        }
        if (isset($parsed['port'])) {
            $new_url .= ':' . $parsed['port'];
        }
        $new_url .= $path;
        if (isset($parsed['query'])) {
            $new_url .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment'])) {
            $new_url .= '#' . $parsed['fragment'];
        }

        return $new_url;
    }

    /**
     * v5.0.4: Extract taxonomy meta data (term name, description, Yoast SEO)
     */
    private function extract_taxonomy_meta($term_id, $taxonomy) {
        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return array();
        }

        $result = array(
            'core_fields' => array(),
            'seo_fields' => array()
        );

        // Extract term name
        if (!empty($term->name)) {
            $result['core_fields']['term_name'] = $term->name;
        }

        // Extract term description
        if (!empty($term->description)) {
            $result['core_fields']['term_description'] = $term->description;
        }

        // Extract Yoast SEO meta
        if (function_exists('get_term_meta')) {
            $yoast_title = get_term_meta($term_id, '_yoast_wpseo_title', true);
            $yoast_desc = get_term_meta($term_id, '_yoast_wpseo_metadesc', true);

            if (!empty($yoast_title)) {
                $result['seo_fields'][] = array(
                    'label' => 'SEO Title (Yoast)',
                    'original' => $yoast_title,
                    'translated' => '',
                    'context' => 'yoast_title'
                );
            }

            if (!empty($yoast_desc)) {
                $result['seo_fields'][] = array(
                    'label' => 'SEO Description (Yoast)',
                    'original' => $yoast_desc,
                    'translated' => '',
                    'context' => 'yoast_description'
                );
            }
        }

        lingua_debug_log('[Lingua v5.0.4] Extracted taxonomy meta: ' . wp_json_encode($result));

        return $result;
    }

    /**
     * v3.8: Apply existing translations for taxonomy pages
     * v5.3.33: Fixed to use Translation Manager for proper lookup (supports auto-translated content)
     */
    private function apply_existing_translations_for_taxonomy($structure, $term_id, $taxonomy, $target_language) {
        lingua_debug_log('[Lingua v5.3.33] Applying existing translations for taxonomy (term_id=' . $term_id . ', taxonomy=' . $taxonomy . ')');

        // v5.3.33: Use Translation Manager for proper lookup (same as regular posts)
        // This ensures auto-translated content is found regardless of context
        $translation_manager = new Lingua_Translation_Manager();

        $found_count = 0;
        $total_count = 0;

        // Apply to each field group
        foreach (['content_blocks', 'page_strings', 'attributes', 'seo_fields'] as $group) {
            if (empty($structure[$group])) continue;

            foreach ($structure[$group] as &$item) {
                $original = $item['original'] ?? $item['text'] ?? '';
                if (empty($original)) continue;

                $total_count++;

                // v5.3.33: Use get_string_translation() which looks up by original_text directly
                // This works with any context including auto_translate contexts
                $translation = $translation_manager->get_string_translation($original, $target_language);

                if ($translation && !empty($translation)) {
                    $item['translated'] = $translation;
                    $item['status'] = 'translated';
                    $found_count++;
                    lingua_debug_log("[Lingua v5.3.33] Found translation for: " . substr($original, 0, 50) . "...");
                } else {
                    $item['translated'] = '';
                    $item['status'] = 'pending';
                }
            }
        }

        lingua_debug_log("[Lingua v5.3.33] Applied translations for taxonomy: {$found_count}/{$total_count} found");

        return $structure;
    }

    /**
     * v5.0.12: AJAX endpoint for getting translations of dynamic strings
     * Uses MutationObserver on client side
     * v5.2.41: NO nonce check - this is a read-only public endpoint
     * v1.2.8: Add text normalization to match hashes stored in DB
     */
    public function ajax_get_dynamic_translations() {
        // v5.2.41: Skip ALL nonce checks - this is read-only endpoint
        // Public access for translation retrieval (read-only)
        // Security: Only SELECT queries, no data modification possible

        $strings_json = isset($_POST['strings']) ? wp_unslash($_POST['strings']) : '';
        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';

        if (empty($strings_json)) {
            wp_send_json_error('No strings provided');
            return;
        }

        if (empty($language)) {
            wp_send_json_error('No language specified');
            return;
        }

        $strings = json_decode($strings_json, true);
        if (!is_array($strings)) {
            wp_send_json_error('Invalid strings format');
            return;
        }
        // Sanitize decoded values (WP review requirement)
        $strings = array_map('sanitize_text_field', $strings);

        lingua_debug_log("[Lingua Dynamic v1.2.8] Getting translations for " . count($strings) . " strings, language: {$language}");

        // Get translations from database
        global $wpdb;
        $translations = array();

        foreach ($strings as $original) {
            if (empty($original)) continue;

            // v1.2.8: Normalize text before hashing (same as Lingua_Output_Buffer::normalize_text_once)
            $normalized = $this->normalize_text_for_lookup($original);
            $hash = md5($normalized);

            $result = $wpdb->get_row($wpdb->prepare(
                "SELECT translated_text FROM {$wpdb->prefix}lingua_string_translations
                WHERE original_text_hash = %s AND language_code = %s
                LIMIT 1",
                $hash,
                $language
            ));

            if ($result && !empty($result->translated_text)) {
                $translations[$original] = $result->translated_text;
            }
        }

        lingua_debug_log("[Lingua Dynamic v1.2.8] Found " . count($translations) . " translations");

        wp_send_json_success(array(
            'translations' => $translations,
            'language' => $language,
            'total_requested' => count($strings),
            'total_found' => count($translations)
        ));
    }

    /**
     * v1.2.8: Normalize text for database lookup
     * Must match Lingua_Output_Buffer::normalize_text_once() for hash consistency
     *
     * @param string $text Text to normalize
     * @return string Normalized text
     */
    private function normalize_text_for_lookup($text) {
        if (empty($text) || !is_string($text)) {
            return '';
        }

        // Step 1: HTML entity decode
        $normalized = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Step 2: Normalize <br> tags
        $normalized = preg_replace('/<br\s*\/?\s*>/i', '<br />', $normalized);
        $normalized = preg_replace('/<br\s*\/>\s+/', '<br /> ', $normalized);

        // Step 3: Normalize multiple spaces to single space
        $normalized = preg_replace('/\s{2,}/', ' ', $normalized);

        // Step 4: Normalize WordPress special characters (wptexturize changes)
        // Dashes (en dash, em dash, hyphen → regular dash)
        $normalized = str_replace(array('–', '—', '‐'), '-', $normalized);

        // Curly quotes → straight quotes
        $normalized = str_replace(array('"', '"', '‟'), '"', $normalized);
        $normalized = str_replace(array("\xe2\x80\x98", "\xe2\x80\x99", "\xe2\x80\x9b"), "'", $normalized);

        // Ellipsis
        $normalized = str_replace('…', '...', $normalized);

        // Normalize &nbsp; (UTF-8: \xC2\xA0) to regular space
        $normalized = str_replace("\xC2\xA0", ' ', $normalized);

        // Step 5: Trim whitespace
        $normalized = trim($normalized);

        return $normalized;
    }

    /**
     * v5.0.12: AJAX endpoint for translating dynamically loaded content (legacy)
     * Used by WoodMart AJAX navigation and other dynamic content loaders
     */
    public function ajax_translate_ajax_content() {
        // Verify nonce (for logged-in users) - for guests, skip nonce check
        if (is_user_logged_in()) {
            check_ajax_referer('lingua_ajax_nonce', 'nonce');
        }

        $html = isset($_POST['html']) ? wp_unslash($_POST['html']) : '';
        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';

        if (empty($html)) {
            wp_send_json_error('No HTML content provided');
            return;
        }

        if (empty($language)) {
            wp_send_json_error('No language specified');
            return;
        }

        lingua_debug_log("[Lingua AJAX v5.0.12] Translating AJAX content for language: {$language}, HTML length: " . strlen($html));

        // Get Output Buffer instance to reuse translation logic
        $output_buffer = new Lingua_Output_Buffer();

        // Set current language temporarily
        $original_lang = $output_buffer->current_language ?? null;
        $output_buffer->current_language = $language;

        // Apply translations using existing process_translation_mode logic
        // We need to pass the HTML through the same translation pipeline
        $default_language = get_option('lingua_default_language', lingua_get_site_language());

        // Only translate if not default language
        if ($language !== $default_language) {
            // Create a temporary cache key for this HTML
            $temp_cache_key = 'lingua_ajax_temp_' . md5($html);
            set_transient($temp_cache_key, $html, 60); // Cache for 1 minute

            // Call public method to apply translations
            $translated_html = $output_buffer->process_translation_mode($html, $temp_cache_key);

            // Clean up temporary cache
            delete_transient($temp_cache_key);
        } else {
            $translated_html = $html;
        }

        // Restore original language
        if ($original_lang) {
            $output_buffer->current_language = $original_lang;
        }

        lingua_debug_log("[Lingua AJAX v5.0.12] Translation complete, output length: " . strlen($translated_html));

        wp_send_json_success(array(
            'html' => $translated_html,
            'language' => $language
        ));
    }

}