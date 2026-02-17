<?php
/**
 * Admin functionality
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Admin {
    
    private $version;
    
    public function __construct($version) {
        $this->version = $version;

        lingua_debug_log('[Lingua Admin] Class loaded - version with debug logs v5.1');

        // v5.2.44: Re-enabled nonce injection for frontend pages (fixes /de language nonce issue)
        add_action('wp_footer', array($this, 'inject_fresh_nonce'), 999);
        add_action('admin_footer', array($this, 'inject_fresh_nonce'), 999);
    }

    /**
     * Inject fresh nonce in footer to prevent caching issues
     * v3.0.33: Outputs inline script with fresh nonce value
     */
    public function inject_fresh_nonce() {
        // v5.2.155: CRITICAL FIX - Create lingua_admin object if it doesn't exist
        // wp_enqueue_scripts doesn't fire on frontend with HTML cache, so we create object inline

        $nonce = wp_create_nonce('lingua_admin_nonce');

        // Get Pro status for inline initialization
        $middleware_api = new Lingua_Middleware_API();
        $is_pro = $middleware_api->is_pro_active();

        lingua_debug_log('[Lingua v5.2.155] inject_fresh_nonce: Generated nonce = ' . substr($nonce, 0, 10) . '..., is_pro = ' . ($is_pro ? 'true' : 'false'));
        ?>
        <script type="text/javascript">
        // v5.2.155: CRITICAL FIX - Create lingua_admin object inline if it doesn't exist
        (function() {
            var freshNonce = '<?php echo $nonce; ?>';
            var noncePrefix = '<?php echo substr($nonce, 0, 10); ?>';

            // Create lingua_admin object if it doesn't exist (enqueue may not have fired)
            if (typeof window.lingua_admin === 'undefined') {
                window.lingua_admin = {
                    ajax_url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                    nonce: freshNonce,
                    is_pro: <?php echo $is_pro ? 'true' : 'false'; ?>,
                    strings: {
                        confirm_delete: '<?php echo esc_js(__('Are you sure?', 'yourtranslater')); ?>',
                        loading: '<?php echo esc_js(__('Loading...', 'yourtranslater')); ?>',
                        error: '<?php echo esc_js(__('Error', 'yourtranslater')); ?>'
                    }
                };
                console.log('[Lingua v5.2.155 INLINE CREATE] Created lingua_admin object with nonce: ' + noncePrefix + '..., is_pro: <?php echo $is_pro ? 'true' : 'false'; ?>');
            } else {
                // Update existing object
                window.lingua_admin.nonce = freshNonce;
                window.lingua_admin.is_pro = <?php echo $is_pro ? 'true' : 'false'; ?>;
                console.log('[Lingua v5.2.155 UPDATE] Updated lingua_admin.nonce = ' + noncePrefix + '..., is_pro: <?php echo $is_pro ? 'true' : 'false'; ?>');
            }

            // v5.2.156: CRITICAL FIX - Iframe should NOT overwrite parent nonce
            // Each page load creates a different nonce, so iframe's nonce won't work for parent's AJAX calls
            // Instead, iframe should inherit parent's nonce
            if (window.self !== window.top) {
                try {
                    // Get parent's nonce and use it in iframe
                    if (window.parent.lingua_admin && window.parent.lingua_admin.nonce) {
                        var parentNonce = window.parent.lingua_admin.nonce;
                        window.lingua_admin.nonce = parentNonce;
                        console.log('[Lingua v5.2.156 IFRAME] ✅ Inherited parent nonce: ' + parentNonce.substring(0, 10) + '... (NOT overwriting parent)');
                    } else {
                        console.warn('[Lingua v5.2.156 IFRAME] ⚠️ Parent lingua_admin not found, using iframe nonce: ' + noncePrefix + '...');
                    }
                } catch (e) {
                    console.error('[Lingua v5.2.156 IFRAME] ❌ Failed to access parent:', e);
                }
            }
        })();
        </script>
        <?php
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Debug: Логирование регистрации меню (для отладки)
        // TODO: Удалить после завершения разработки
        lingua_debug_log('Lingua: Registering admin menu');
        
        // Главная страница меню плагина в админке WordPress
        // Используем права 'edit_posts' для доступа редакторов к переводам
        $hook = add_menu_page(
            __('YourTranslate Settings', 'yourtranslater'),    // Заголовок страницы
            __('YourTranslate', 'yourtranslater'),             // Название в меню
            'manage_options',                   // Права доступа - стандарт для настроек плагина
            'lingua-settings',                  // Slug страницы
            array($this, 'display_settings_page'), // Callback функция
            'dashicons-translation',            // Иконка меню
            80                                  // Позиция в меню
        );
        
        // Debug: Логирование созданного hook
        lingua_debug_log('Lingua: Menu hook: ' . $hook);
        
        // Settings submenu
        add_submenu_page(
            'lingua-settings',
            __('Settings', 'yourtranslater'),
            __('Settings', 'yourtranslater'),
            'manage_options',
            'lingua-settings',
            array($this, 'display_settings_page')
        );
        
        // String translations submenu (for universal HTML processing)
        // v5.3.35: Renamed menu from "Strings" to "String Translations"
        add_submenu_page(
            'lingua-settings',
            __('String Translations', 'yourtranslater'),
            __('String Translations', 'yourtranslater'),
            'manage_options',
            'lingua-strings',
            array($this, 'display_strings_page')
        );

    }
    
    /**
     * Handle settings save on admin_init
     * This runs BEFORE the page is rendered
     */
    public function handle_settings_save() {
        // Only process on settings page
        if (!isset($_GET['page']) || $_GET['page'] !== 'lingua-settings') {
            return;
        }

        lingua_debug_log('[Lingua Settings] handle_settings_save called');
        lingua_debug_log('[Lingua Settings] REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD']);
        lingua_debug_log('[Lingua Settings] POST isset: ' . (isset($_POST['lingua_settings_nonce']) ? 'YES' : 'NO'));

        // Handle form submission
        if (isset($_POST['lingua_settings_nonce'])) {
            lingua_debug_log('[Lingua Settings] Nonce found in POST');
            lingua_debug_log('[Lingua Settings] Nonce value: ' . $_POST['lingua_settings_nonce']);

            if (wp_verify_nonce($_POST['lingua_settings_nonce'], 'lingua_save_settings')) {
                lingua_debug_log('[Lingua Settings] Nonce verified successfully, calling save_settings()');
                $this->save_settings();

                // v5.2.130: Redirect to same tab after save
                $active_tab = isset($_POST['active_tab']) ? sanitize_text_field($_POST['active_tab']) : 'api-settings';
                $redirect_url = add_query_arg(array(
                    'settings-updated' => 'true',
                    'tab' => $active_tab
                ), admin_url('admin.php?page=lingua-settings'));
                lingua_debug_log('[Lingua Settings] Redirecting to: ' . $redirect_url);
                wp_redirect($redirect_url);
                exit;
            } else {
                lingua_debug_log('[Lingua Settings] Nonce verification FAILED');
            }
        } else {
            lingua_debug_log('[Lingua Settings] No nonce in POST data');
        }
    }

    /**
     * Display settings page
     */
    public function display_settings_page() {
        include LINGUA_PLUGIN_DIR . 'admin/views/settings-page.php';
    }
    
    /**
     * Display translations management page
     */
    public function display_translations_page() {
        include LINGUA_PLUGIN_DIR . 'admin/views/translations-page.php';
    }
    
    /**
     * Display bulk translation page
     */
    public function display_bulk_translate_page() {
        // Handle bulk translation request
        if (isset($_POST['bulk_translate']) && wp_verify_nonce($_POST['lingua_bulk_nonce'], 'lingua_bulk_translate')) {
            $this->handle_bulk_translation();
        }
        
        include LINGUA_PLUGIN_DIR . 'admin/views/bulk-translate-page.php';
    }
    
    /**
     * Display strings page - v5.2.187 with gettext scanning and filters
     */
    public function display_strings_page() {
        global $wpdb;

        $string_table = $wpdb->prefix . 'lingua_string_translations';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$string_table'") === $string_table;

        $strings = array();
        $total_strings = 0;

        if ($table_exists) {
            // Get filter parameters
            $current_filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
            $current_domain = isset($_GET['domain']) ? sanitize_text_field($_GET['domain']) : '';
            $current_search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
            $current_lang = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : '';
            $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
            $per_page = 100;
            $offset = ($current_page - 1) * $per_page;

            // Build WHERE clause
            $where = array('1=1');
            $where_args = array();

            // Source filter
            if ($current_filter === 'gettext') {
                $where[] = "source = 'gettext'";
            } elseif ($current_filter === 'custom') {
                $where[] = "source = 'custom'";
            } elseif ($current_filter === 'email') {
                $where[] = "context LIKE %s";
                $where_args[] = '%.email%';
            }

            // Domain filter
            if (!empty($current_domain)) {
                $where[] = "gettext_domain = %s";
                $where_args[] = $current_domain;
            }

            // Language filter
            if (!empty($current_lang)) {
                $where[] = "language_code = %s";
                $where_args[] = $current_lang;
            }

            // Search filter
            if (!empty($current_search)) {
                $where[] = "(original_text LIKE %s OR translated_text LIKE %s)";
                $search_like = '%' . $wpdb->esc_like($current_search) . '%';
                $where_args[] = $search_like;
                $where_args[] = $search_like;
            }

            $where_sql = implode(' AND ', $where);

            // Get total count
            $count_query = "SELECT COUNT(*) FROM $string_table WHERE $where_sql";
            if (!empty($where_args)) {
                $count_query = $wpdb->prepare($count_query, $where_args);
            }
            $total_strings = $wpdb->get_var($count_query);

            // v5.2.191: Group plural strings together like Modal does
            // First, get all strings but group plurals by original_text + language_code

            // For plural strings, only get form 0 (the "base" form) for display
            // All forms will be loaded when editing
            $query = "SELECT s.* FROM $string_table s WHERE $where_sql
                      AND (s.plural_form IS NULL OR s.plural_form = 0)
                      ORDER BY s.created_at DESC LIMIT %d OFFSET %d";
            $query_args = array_merge($where_args, array($per_page, $offset));
            $strings = $wpdb->get_results($wpdb->prepare($query, $query_args));

            // Add plural form count for plural strings
            foreach ($strings as $string) {
                $string->is_plural_group = false;
                $string->plural_forms_count = 0;
                $string->plural_forms_translated = 0;

                // Check if this is a plural string (has original_plural and plural_form = 0)
                if (!empty($string->original_plural) && $string->plural_form !== null) {
                    $string->is_plural_group = true;

                    // Count total plural forms for this string
                    $forms = $wpdb->get_results($wpdb->prepare(
                        "SELECT id, plural_form, translated_text FROM $string_table
                         WHERE original_text = %s AND language_code = %s AND plural_form IS NOT NULL
                         ORDER BY plural_form ASC",
                        $string->original_text,
                        $string->language_code
                    ));

                    $string->plural_forms_count = count($forms);
                    $string->plural_forms_translated = 0;
                    $string->all_forms = $forms;

                    foreach ($forms as $form) {
                        if (!empty($form->translated_text)) {
                            $string->plural_forms_translated++;
                        }
                    }
                }
            }

            // Recalculate total count excluding non-base plural forms
            $count_query = "SELECT COUNT(*) FROM $string_table WHERE $where_sql
                           AND (plural_form IS NULL OR plural_form = 0)";
            if (!empty($where_args)) {
                $count_query = $wpdb->prepare($count_query, $where_args);
            }
            $total_strings = $wpdb->get_var($count_query);
        }

        include LINGUA_PLUGIN_DIR . 'admin/views/strings-page.php';
    }
    
    private function add_debug_test_strings() {
        global $wpdb;
        $string_table = $wpdb->prefix . 'lingua_string_translations';
        
        $test_strings = [
            ['text' => 'Home', 'context' => 'menu'],
            ['text' => 'About Us', 'context' => 'menu'],
            ['text' => 'Contact', 'context' => 'menu'],
            ['text' => 'Search', 'context' => 'button'],
            ['text' => 'Submit', 'context' => 'button'],
            ['text' => 'Read more', 'context' => 'text_node'],
            ['text' => 'Previous', 'context' => 'text_node'],
            ['text' => 'Next', 'context' => 'text_node'],
        ];
        
        $languages = get_option('lingua_languages', []);
        $default_lang = get_option('lingua_default_language', 'ru');
        
        foreach ($test_strings as $string) {
            foreach (array_keys($languages) as $lang_code) {
                if ($lang_code === $default_lang) continue;
                
                $hash = md5($string['text']);
                
                $wpdb->insert(
                    $string_table,
                    [
                        'original_text' => $string['text'],
                        'original_text_hash' => $hash,
                        'language_code' => $lang_code,
                        'context' => $string['context'],
                        'status' => Lingua_Database::NOT_TRANSLATED
                    ],
                    ['%s', '%s', '%s', '%s', '%s']
                );
            }
        }
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        lingua_debug_log('Lingua: Starting save_settings()');
        
        try {
        // v5.2.64: Middleware API settings (только API key, URL захардкожен в константе)
        if (isset($_POST['middleware_api_key'])) {
            $new_key = sanitize_text_field($_POST['middleware_api_key']);
            $old_key = get_option('lingua_middleware_api_key', '');
            
            if ($old_key !== $new_key) {
                update_option('lingua_middleware_api_key', $new_key);
                // Clear permanent Pro status when API key changes (URL всегда из константы)
                delete_option('lingua_pro_status_' . md5($old_key . LINGUA_MIDDLEWARE_URL));
                delete_option('lingua_pro_status_' . md5($new_key . LINGUA_MIDDLEWARE_URL));
                lingua_debug_log('[Lingua v5.2.64] API key changed, Pro status cleared');
            } else {
                lingua_debug_log('[Lingua v5.2.64] API key unchanged, Pro status preserved permanently');
            }
        }
        
        // Language settings
        if (isset($_POST['default_language'])) {
            $default_lang = sanitize_text_field($_POST['default_language']);
            $old_default = get_option('lingua_default_language');

            // v5.2.124: COMPREHENSIVE cache clearing when default language changes
            if ($old_default !== $default_lang) {
                update_option('lingua_default_language', $default_lang);

                lingua_debug_log("[Lingua v5.2.124] 🔥 Default language changed from '{$old_default}' to '{$default_lang}' - CLEARING ALL CACHES");

                // 1. Clear all Lingua transients (gettext cache, page cache, etc.)
                global $wpdb;
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_%'");
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lingua_%'");

                // 2. Clear WordPress object cache
                wp_cache_flush();

                // 3. CRITICAL: Flush rewrite rules (needed for language URL structure changes)
                delete_option('rewrite_rules'); // v5.2.134: Force complete regeneration
                flush_rewrite_rules();
                lingua_debug_log("[Lingua v5.2.134] ✅ Deleted rewrite_rules option and flushed");

                // 4. CRITICAL: Unload all gettext domains to force .mo file reload
                $languages = get_option('lingua_languages', array());
                foreach ($languages as $lang_code => $lang_data) {
                    if ($lang_code !== $default_lang) {
                        unload_textdomain('lingua-' . $lang_code);
                        lingua_debug_log("[Lingua v5.2.124] ✅ Unloaded gettext domain: lingua-{$lang_code}");
                    }
                }

                // 5. Clear WordPress post cache
                wp_cache_delete('all_post_ids', 'posts');

                lingua_debug_log("[Lingua v5.2.124] ✅ COMPREHENSIVE cache clear complete: transients, object cache, rewrite rules, gettext domains, post cache");
            } else {
                // No change, just update option
                update_option('lingua_default_language', $default_lang);
            }
        }
        
        // Process translation languages
        // v5.2.128: Use centralized language list
        $available_languages = Lingua_Languages::get_all();
        
        $translation_languages = array();
        if (isset($_POST['translation_languages']) && is_array($_POST['translation_languages'])) {
            foreach ($_POST['translation_languages'] as $lang_code) {
                $lang_code = sanitize_text_field($lang_code);
                if (isset($available_languages[$lang_code])) {
                    $translation_languages[$lang_code] = $available_languages[$lang_code];
                }
            }
        }
        
        // Always include default language in translation languages
        $default_lang = get_option('lingua_default_language', 'ru');
        if (isset($available_languages[$default_lang]) && !isset($translation_languages[$default_lang])) {
            $translation_languages[$default_lang] = $available_languages[$default_lang];
        }
        
        update_option('lingua_languages', $translation_languages);
        update_option('lingua_translation_languages', array_keys($translation_languages));

        // v5.2.177: Sync active languages to middleware API
        $api_key = get_option('lingua_middleware_api_key', '');
        if (!empty($api_key)) {
            $middleware_api = new Lingua_Middleware_API();
            $language_codes = array_keys($translation_languages);
            $sync_result = $middleware_api->update_active_languages($language_codes);

            if (is_wp_error($sync_result)) {
                lingua_debug_log('[Lingua v5.2.177] Failed to sync languages to middleware: ' . $sync_result->get_error_message());
            } else {
                lingua_debug_log('[Lingua v5.2.177] Synced ' . count($language_codes) . ' languages to middleware: ' . implode(', ', $language_codes));
                // Clear API status cache to refresh dashboard
                $cache_key = 'lingua_api_status_' . md5($api_key . LINGUA_MIDDLEWARE_URL);
                delete_transient($cache_key);
            }
        }

        // Save URL slugs
        if (isset($_POST['url_slugs']) && is_array($_POST['url_slugs'])) {
            $url_slugs = array();
            foreach ($_POST['url_slugs'] as $lang_code => $slug) {
                $url_slugs[sanitize_text_field($lang_code)] = sanitize_title($slug);
            }
            update_option('lingua_url_slugs', $url_slugs);
        }

        // Translation settings
        update_option('lingua_auto_translate_website', isset($_POST['auto_translate_website']));

        // v5.3.17: Save translatable post types
        if (isset($_POST['translatable_post_types']) && is_array($_POST['translatable_post_types'])) {
            $post_types = array_map('sanitize_text_field', $_POST['translatable_post_types']);
            update_option('lingua_translatable_post_types', $post_types);
        } else {
            // If none selected, keep defaults
            update_option('lingua_translatable_post_types', array('post', 'page', 'product'));
        }

        // v5.3.0: Save auto-translate domains whitelist
        if (isset($_POST['auto_translate_domains']) && is_array($_POST['auto_translate_domains'])) {
            $domains = array_map('sanitize_text_field', $_POST['auto_translate_domains']);
            update_option('lingua_auto_translate_domains', $domains);
        } else {
            update_option('lingua_auto_translate_domains', array());
        }

        // Language Switcher settings
        if (isset($_POST['switcher_format'])) {
            $new_format = sanitize_text_field($_POST['switcher_format']);
            $old_format = get_option('lingua_switcher_format', 'flags_full');

            update_option('lingua_switcher_format', $new_format);

            // v5.2.134: Clear WordPress object cache when switcher format changes
            if ($old_format !== $new_format) {
                wp_cache_flush();
                lingua_debug_log("[Lingua v5.2.134] Switcher format changed from '{$old_format}' to '{$new_format}' - cache flushed");
            }
        }

        update_option('lingua_native_names', isset($_POST['native_names']));

        // v5.3.20: Debug mode setting
        $old_debug = get_option('lingua_debug_mode', false);
        $new_debug = isset($_POST['debug_mode']);
        update_option('lingua_debug_mode', $new_debug);

        if ($old_debug !== $new_debug) {
            if ($new_debug) {
                error_log('[Lingua v5.3.20] Debug mode ENABLED via admin settings');
            } else {
                error_log('[Lingua v5.3.20] Debug mode DISABLED via admin settings');
            }
        }

        // v5.2.178: Menu integration always enabled, removed non-functional options
        update_option('lingua_enable_menu_switcher', true);

        // Flush rewrite rules after language changes
        flush_rewrite_rules();
        
        // Also force refresh of URL rewriter languages
        global $lingua_url_rewriter;
        if ($lingua_url_rewriter && method_exists($lingua_url_rewriter, 'refresh_languages')) {
            $lingua_url_rewriter->refresh_languages();
        }
        
        lingua_debug_log('Lingua: save_settings() completed successfully');
        
        } catch (Exception $e) {
            lingua_debug_log('Lingua: Error in save_settings(): ' . $e->getMessage());
            wp_die('Error saving settings: ' . $e->getMessage());
        }
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_styles() {
        // Основные стили админки
        wp_enqueue_style(
            'lingua-admin',
            LINGUA_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            $this->version
        );

        // Стили модального окна перевода (v2.0 Enhanced)
        wp_enqueue_style(
            'lingua-translation-modal',
            LINGUA_PLUGIN_URL . 'admin/css/translation-modal.css',
            array(),
            '2.0.2-ux-enhanced-' . time() // v2.0.2 UX Enhanced with site push and responsive
        );

        // v5.5.2: Flag icons CSS for admin (SVG flags instead of emoji)
        wp_enqueue_style(
            'lingua-flag-icons',
            LINGUA_PLUGIN_URL . 'public/css/flags/flag-icons.min.css',
            array(),
            '7.2.3'
        );

        // v5.2.129: Select2 for language selects on settings page
        if (isset($_GET['page']) && $_GET['page'] === 'lingua-settings') {
            wp_enqueue_style(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
                array(),
                '4.1.0'
            );
        }
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_scripts() {
        lingua_debug_log('[Lingua] enqueue_scripts called on page: ' . (isset($_GET['page']) ? $_GET['page'] : 'N/A'));

        // v5.2: Enqueue WordPress Media Library scripts (for Media tab "Add Media" button)
        wp_enqueue_media();

        // v5.2.129: Select2 for language selects on settings page
        if (isset($_GET['page']) && $_GET['page'] === 'lingua-settings') {
            wp_enqueue_script(
                'select2',
                'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
                array('jquery'),
                '4.1.0',
                true
            );
        }

        // Основной скрипт админки
        wp_enqueue_script(
            'lingua-admin',
            LINGUA_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            $this->version,
            true
        );

        lingua_debug_log('[Lingua] Enqueued lingua-admin script');

        // Скрипт модального окна перевода (v2.1.4 Unified)
        wp_enqueue_script(
            'lingua-translation-modal',
            LINGUA_PLUGIN_URL . 'admin/js/translation-modal.js',
            array('jquery', 'lingua-admin'),
            '2.1.4-unified-final-' . time(), // v2.1.4 Final unified save with autotranslate fix
            true
        );
        
        // Localize script for AJAX (расширенный набор строк)
        // v3.0.33: NONCE moved to wp_footer to prevent caching issues
        // v5.2.137: Restored Pro status check from Middleware API
        $middleware_api = new Lingua_Middleware_API();
        $is_pro = $middleware_api->is_pro_active();
        lingua_debug_log('[Lingua Admin] is_pro_active: ' . ($is_pro ? 'TRUE' : 'FALSE'));

        // v5.2.1: Generate nonce directly here to avoid timing issues
        $nonce = wp_create_nonce('lingua_admin_nonce');
        lingua_debug_log('[Lingua Admin] Generated nonce: ' . substr($nonce, 0, 10) . '...');

        // v5.2.150: CRITICAL FIX - Only call wp_localize_script on ADMIN pages
        // On frontend, public class handles wp_localize_script to avoid duplication
        // Problem: Both admin and public classes were calling wp_localize_script('lingua-admin'),
        // causing WordPress to output TWO inline scripts, with the second overwriting the first
        if (!is_admin()) {
            lingua_debug_log('[Lingua v5.2.150] Skipping wp_localize_script on frontend (handled by public class)');
            return; // Skip wp_localize_script on frontend
        }

        // v5.3.21: Add debug_mode flag for JS console logging control
        wp_localize_script('lingua-admin', 'lingua_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $nonce, // v5.2.1: Generate nonce directly
            'is_pro' => $is_pro, // v5.2.137: Pro status from Middleware API
            'middleware_url' => get_option('lingua_middleware_url', 'http://77.95.201.43:4002'), // v5.2: Middleware portal URL
            'default_language' => get_option('lingua_default_language', 'en'), // v5.2.72: Pass default language to JS
            'debug_mode' => lingua_is_debug_enabled(),
            'strings' => array(
                // API тестирование
                'testing_connection' => __('Testing connection...', 'yourtranslater'),
                'connection_successful' => __('Connection successful!', 'yourtranslater'),
                'connection_failed' => __('Connection failed!', 'yourtranslater'),
                
                // Модальное окно перевода
                'invalid_post_id' => __('Invalid post ID', 'yourtranslater'),
                'no_post_selected' => __('No post selected', 'yourtranslater'),
                'select_target_language' => __('Please select target language', 'yourtranslater'),
                'extracting_content' => __('Extracting content...', 'yourtranslater'),
                'content_extracted' => __('Content extracted successfully', 'yourtranslater'),
                'extraction_failed' => __('Content extraction failed', 'yourtranslater'),
                'ajax_error' => __('AJAX request failed', 'yourtranslater'),
                'ready_to_extract' => __('Ready to extract content', 'yourtranslater'),
                
                // Переводы
                'translating' => __('Translating...', 'yourtranslater'),
                'translate' => __('Translate', 'yourtranslater'),
                'translation' => __('Translation', 'yourtranslater'),
                'original' => __('Original', 'yourtranslater'),
                'enter_translation' => __('Enter translation...', 'yourtranslater'),
                'translation_complete' => __('Translation completed!', 'yourtranslater'),
                'translation_failed' => __('Translation failed', 'yourtranslater'),
                'confirm_auto_translate' => __('Auto-translate all content?', 'yourtranslater'),
                'no_content_to_translate' => __('No content to translate', 'yourtranslater'),
                'auto_translate_complete' => __('Auto-translation completed', 'yourtranslater'),
                
                // Сохранение
                'invalid_state' => __('Invalid state for saving', 'yourtranslater'),
                'no_translations_to_save' => __('No translations to save', 'yourtranslater'),
                'saving' => __('Saving...', 'yourtranslater'),
                'saving_translations' => __('Saving translations...', 'yourtranslater'),
                'translations_saved' => __('Translations saved successfully', 'yourtranslater'),
                'save_failed' => __('Save failed', 'yourtranslater'),
                'save_translation' => __('Save Translation', 'yourtranslater'),

                // v5.3.35: SEO и медиа строки для модала
                'seo_title' => __('SEO Title', 'yourtranslater'),
                'seo_title_desc' => __('SEO title from Yoast/RankMath (shown in search engine results)', 'yourtranslater'),
                'seo_description' => __('Meta Description', 'yourtranslater'),
                'seo_description_desc' => __('Brief description of the page shown in search results', 'yourtranslater'),
                'og_title' => __('OG Title', 'yourtranslater'),
                'og_title_desc' => __('Title shown when shared on Facebook, LinkedIn, etc.', 'yourtranslater'),
                'og_title_desc_default' => __('Title shown when shared on Facebook, LinkedIn, etc. (defaults to SEO Title if empty)', 'yourtranslater'),
                'og_description' => __('OG Description', 'yourtranslater'),
                'og_description_desc' => __('Description shown when shared on social media', 'yourtranslater'),
                'og_description_desc_default' => __('Description shown when shared on social media (defaults to Meta Description if empty)', 'yourtranslater'),
                'open_graph_social' => __('Open Graph (Social Media)', 'yourtranslater'),
                'auto_translate' => __('Auto-translate', 'yourtranslater'),
                'add_media' => __('Add Media', 'yourtranslater'),
                'save_media' => __('Save Media', 'yourtranslater'),
                'enter_url_or_add_media' => __('Enter URL or use Add Media button', 'yourtranslater')
            )
        ));
    }
    
    /**
     * v5.2.167: AJAX handler for saving API settings
     */
    public function ajax_save_api_settings() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error(__('Permission denied', 'yourtranslater'));
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';

        if (empty($api_key)) {
            wp_send_json_error(__('API key is required', 'yourtranslater'));
        }

        // Clear old Pro status cache before saving new key
        $old_key = get_option('lingua_middleware_api_key', '');
        if ($old_key !== $api_key) {
            $old_cache_key = 'lingua_pro_status_' . md5($old_key . LINGUA_MIDDLEWARE_URL);
            delete_option($old_cache_key);
        }

        // Save new API key
        update_option('lingua_middleware_api_key', $api_key);

        wp_send_json_success(__('Settings saved', 'yourtranslater'));
    }

    /**
     * v5.2: AJAX handler for testing Middleware API connection
     */
    public function ajax_test_api() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_die();
        }

        // v5.2.167: Get API key from saved options (already saved by ajax_save_api_settings)
        $api_key = get_option('lingua_middleware_api_key');

        if (empty($api_key)) {
            wp_send_json_error(__('API key is required', 'yourtranslater'));
        }

        $api = new Lingua_Middleware_API();
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(__('API connection successful!', 'yourtranslater'));
        }
    }

    /**
     * v5.2.169: AJAX handler for checking Pro status (called on page load)
     */
    public function ajax_check_pro_status() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error(__('Permission denied', 'yourtranslater'));
        }

        $api_key = get_option('lingua_middleware_api_key');

        if (empty($api_key)) {
            wp_send_json_success(array('is_pro' => false));
            return;
        }

        // Test connection to verify current status
        $api = new Lingua_Middleware_API();
        $result = $api->test_connection();

        $is_pro = !is_wp_error($result);

        // Update cached status
        $cache_key = 'lingua_pro_status_' . md5($api_key . LINGUA_MIDDLEWARE_URL);
        update_option($cache_key, $is_pro ? 1 : 0);

        wp_send_json_success(array('is_pro' => $is_pro));
    }

    /**
     * v5.2.171: AJAX handler for disconnecting license
     */
    public function ajax_disconnect_license() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error(__('Permission denied', 'yourtranslater'));
        }

        $api_key = get_option('lingua_middleware_api_key', '');

        // Clear Pro status cache
        if (!empty($api_key)) {
            $cache_key = 'lingua_pro_status_' . md5($api_key . LINGUA_MIDDLEWARE_URL);
            delete_option($cache_key);
        }

        // Clear API key
        delete_option('lingua_middleware_api_key');

        wp_send_json_success(__('License disconnected', 'yourtranslater'));
    }

    /**
     * AJAX handler for translating content
     */
    public function ajax_translate_content() {
        // Add debugging
        
        // Проверяем nonce
        if (!wp_verify_nonce($_POST['nonce'], 'lingua_admin_nonce')) {
            wp_send_json_error('Nonce verification failed');
        }
        
        if (!current_user_can(lingua_translating_capability())) {
            wp_die();
        }
        
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';
        $is_front_page = isset($_POST['is_front_page']) && $_POST['is_front_page'] === 'true';
        
        
        if (!$target_lang) {
            wp_send_json_error(__('Invalid parameters', 'yourtranslater'));
        }
        
        // Для главной страницы используем другую логику
        if ($is_front_page) {
            $this->process_front_page_content($target_lang);
            return;
        }
        
        if (!$post_id) {
            wp_send_json_error(__('No post ID provided', 'yourtranslater'));
        }
        
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(__('Post not found', 'yourtranslater'));
        }
        
        
        try {
            // Extract translatable content
            if (!class_exists('Lingua_Content_Processor')) {
                wp_send_json_error('Content processor not available');
            }
            
            $processor = new Lingua_Content_Processor();
            
            $translatable = $processor->extract_translatable_content($post->post_content);
            
            $meta_fields = $processor->extract_meta_fields($post_id);
            
            wp_send_json_success(array(
                'title' => $post->post_title,
                'content' => $translatable,
                'excerpt' => $post->post_excerpt,
                'meta' => $meta_fields
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Content processing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Process front page content for translation
     */
    private function process_front_page_content($target_lang) {
        try {
            // Для главной страницы мы будем извлекать контент из текущего HTML
            // Это требует специальной обработки, пока вернем заглушку
            
            wp_send_json_success(array(
                'title' => get_bloginfo('name') . ' - ' . __('Front Page', 'yourtranslater'),
                'content' => array(
                    array(
                        'type' => 'text',
                        'original' => __('Front page content extraction is not yet implemented. Please select a specific post or page to translate.', 'yourtranslater')
                    )
                ),
                'excerpt' => '',
                'meta' => array()
            ));
            
        } catch (Exception $e) {
            wp_send_json_error('Front page processing failed: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for saving translations
     */
    public function ajax_save_translation() {
        // v5.2.77: CRITICAL DEBUG - Log BEFORE any checks
        lingua_debug_log("🚨 LINGUA v5.2.77: ajax_save_translation() CALLED!");
        lingua_debug_log("🚨 POST keys: " . implode(', ', array_keys($_POST)));

        // LINGUA v2.1.3 unified save: handle core_fields, meta_fields, taxonomy_terms, attributes, page_strings
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_translating_capability())) {
            wp_die();
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
        $translations = isset($_POST['translations']) ? $_POST['translations'] : array();

        // v5.0.6: Get taxonomy parameters for archive pages
        $page_type = isset($_POST['page_type']) ? sanitize_text_field($_POST['page_type']) : 'post';
        $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';

        // LINGUA v2.0.3 diagnostics: Log parsed data
        lingua_debug_log("🔍 LINGUA v5.2.73: Save translation called for LANGUAGE={$language}");
        lingua_debug_log("🔍 LINGUA v2.0.3 DIAGNOSTICS: Parsed translations array:");
        lingua_debug_log(print_r($translations, true));
        lingua_debug_log("🔍 LINGUA v5.0.6: Page context: page_type=$page_type, term_id=$term_id, taxonomy=$taxonomy");
        $global_mode = isset($_POST['global_translation']) && $_POST['global_translation'] === 'true';

        // v5.0.6: Allow post_id=0 for taxonomy pages
        // v5.5.2: Allow post_id=0 for post_type_archive pages (e.g. /docs/)
        $is_taxonomy = ($page_type === 'taxonomy' && $term_id > 0);
        $is_archive = ($page_type === 'post_type_archive');
        if (!$is_taxonomy && !$is_archive && !$post_id) {
            wp_send_json_error(__('Invalid parameters: missing post_id or term_id', 'yourtranslater'));
        }
        if (!$language || empty($translations)) {
            wp_send_json_error(__('Invalid parameters: missing language or translations', 'yourtranslater'));
        }
        
        // LINGUA v2.1 unified pipeline: Process all translations through single JSON structure
        lingua_debug_log("🔍 LINGUA v2.1 UNIFIED: Processing unified translations structure");

        $clean_translations = array();

        // Check for v2.1 unified structure first
        if (isset($translations['translation_strings'])) {
            // LINGUA v2.1 unified pipeline: Decode and process all strings
            $translation_strings = json_decode(stripslashes($translations['translation_strings']), true);

            if (is_array($translation_strings)) {
                lingua_debug_log("[LINGUA SAVE v2.1.3] Successfully decoded " . count($translation_strings) . " translation strings");
                lingua_debug_log("[LINGUA SAVE v2.1.3] Sample strings: " . print_r(array_slice($translation_strings, 0, 3), true));

            } else {
                lingua_debug_log("[LINGUA SAVE v2.1.3] ERROR: Failed to decode translation_strings JSON");
                lingua_debug_log("[LINGUA SAVE v2.1.3] JSON Error: " . json_last_error_msg());
            }

            if (is_array($translation_strings)) {
                // v5.2.163: Declare global $wpdb for deletion operations
                global $wpdb;

                $processed_strings = array();
                $deleted_count = 0;  // v5.2.163: Track deleted translations
                $statistics = array(
                    'seo_fields' => 0,
                    'core_fields' => 0,
                    'meta_fields' => 0,
                    'taxonomy_terms' => 0,
                    'attributes' => 0,
                    'page_strings' => 0,
                    'content_blocks' => 0,
                    'media' => 0,  // v5.1: Media translations count
                    'total_processed' => 0,
                    'deleted' => 0  // v5.2.163: Track deletions
                );

                foreach ($translation_strings as $string_data) {
                    if (!isset($string_data['original'])) {
                        continue;
                    }

                    // v5.2.163: If translated is empty but original exists - DELETE the translation
                    if (empty($string_data['translated']) && !empty($string_data['original'])) {
                        $original_hash = md5($string_data['original']);
                        $field_group = $string_data['field_group'] ?? '';
                        $seo_type = $string_data['type'] ?? '';

                        lingua_debug_log("[LINGUA v5.2.165 DELETE] Attempting delete: field_group={$field_group}, type={$seo_type}, original=" . substr($string_data['original'], 0, 30));

                        // For media: delete by specific context
                        if (isset($string_data['src_hash']) && isset($string_data['attribute'])) {
                            $delete_context = "media.src[{$string_data['src_hash']}].{$string_data['attribute']}";
                            $deleted = $wpdb->delete(
                                $wpdb->prefix . 'lingua_string_translations',
                                array(
                                    'context' => $delete_context,
                                    'language_code' => $language
                                ),
                                array('%s', '%s')
                            );
                            if ($deleted) {
                                $deleted_count++;
                                $statistics['deleted']++;
                                lingua_debug_log("[Lingua v5.2.163] Deleted media translation: {$delete_context}, lang: {$language}");
                            }
                        } else if ($field_group === 'seo_fields' && !empty($seo_type)) {
                            // v5.2.165: SEO fields - delete by context (like media)
                            // This prevents deleting wrong translation when og_description and meta_description have same text
                            $delete_context = ($is_taxonomy ? "taxonomy_{$taxonomy}_{$term_id}" : "post_{$post_id}") . "_{$seo_type}";
                            $deleted = $wpdb->delete(
                                $wpdb->prefix . 'lingua_string_translations',
                                array(
                                    'context' => $delete_context,
                                    'language_code' => $language
                                ),
                                array('%s', '%s')
                            );
                            if ($deleted) {
                                $deleted_count++;
                                $statistics['deleted']++;
                                lingua_debug_log("[Lingua v5.2.165] Deleted SEO translation: {$delete_context}, lang: {$language}");
                            }
                        } else {
                            // For text strings: delete by original_text_hash
                            $deleted = $wpdb->delete(
                                $wpdb->prefix . 'lingua_string_translations',
                                array(
                                    'original_text_hash' => $original_hash,
                                    'language_code' => $language
                                ),
                                array('%s', '%s')
                            );
                            if ($deleted) {
                                $deleted_count++;
                                $statistics['deleted']++;
                                lingua_debug_log("[Lingua v5.2.163] Deleted text translation hash: {$original_hash}, lang: {$language}, text: " . substr($string_data['original'], 0, 50));
                            }
                        }
                        continue;
                    }

                    // Skip if no original
                    if (empty($string_data['original'])) {
                        continue;
                    }

                    // v3.0.21: Use wp_kses_post() to preserve safe HTML tags like <br>, <span>, <strong>
                    // sanitize_textarea_field() removes ALL HTML tags - we need to keep inline tags!
                    $clean_string = array(
                        'id' => sanitize_key($string_data['id']),
                        'original' => wp_kses_post($string_data['original']),
                        'translated' => wp_kses_post($string_data['translated']),
                        'context' => sanitize_text_field($string_data['context'] ?? 'general'),
                        'type' => sanitize_text_field($string_data['type'] ?? 'string'),
                        'field_group' => sanitize_text_field($string_data['field_group'] ?? 'general')
                    );

                    // Add meta_key for meta fields
                    if (isset($string_data['meta_key'])) {
                        $clean_string['meta_key'] = sanitize_key($string_data['meta_key']);
                    }

                    // v5.1: Add media-specific fields (src_hash, attribute)
                    if (isset($string_data['src_hash'])) {
                        $clean_string['src_hash'] = sanitize_text_field($string_data['src_hash']);
                    }
                    if (isset($string_data['attribute'])) {
                        $clean_string['attribute'] = sanitize_text_field($string_data['attribute']);
                    }

                    // v5.2.64: Add gettext-specific fields (source, domain, plural_pair, russian_forms)
                    if (isset($string_data['source'])) {
                        $clean_string['source'] = sanitize_text_field($string_data['source']);
                    }
                    if (isset($string_data['gettext_domain'])) {
                        $clean_string['gettext_domain'] = sanitize_text_field($string_data['gettext_domain']);
                    }
                    if (isset($string_data['is_plural'])) {
                        $clean_string['is_plural'] = (bool) $string_data['is_plural'];
                    }
                    if (isset($string_data['plural_pair'])) {
                        $clean_string['plural_pair'] = sanitize_text_field($string_data['plural_pair']);
                    }
                    // v5.2.82: Add plural_form_index for 2-form plurals
                    if (isset($string_data['plural_form_index'])) {
                        $clean_string['plural_form_index'] = intval($string_data['plural_form_index']);
                    }
                    if (isset($string_data['russian_forms']) && is_array($string_data['russian_forms'])) {
                        // Preserve Russian plural forms array
                        $clean_string['russian_forms'] = array_map('sanitize_text_field', $string_data['russian_forms']);
                    }

                    $processed_strings[] = $clean_string;

                    // Update statistics
                    $field_group = $clean_string['field_group'];
                    if (isset($statistics[$field_group])) {
                        $statistics[$field_group]++;
                    }
                    $statistics['total_processed']++;
                }

                // v2 unified save - no legacy conversion needed
                // LINGUA v2.1.3 unified save: Use new comprehensive save system
                // v5.0.6: Pass taxonomy context
                $save_results = $this->save_unified_translations($processed_strings, $post_id, $language, $page_type, $term_id, $taxonomy);

                // Check for success - v5.2.163: Also count deletions as success
                $success = ($save_results['total_processed'] > 0 || $deleted_count > 0) && empty($save_results['errors']);

                if ($success) {
                    // v3.0.32: Clear page cache after successful translation save
                    if (class_exists('Lingua_Output_Buffer')) {
                        $output_buffer = new Lingua_Output_Buffer();
                        // v5.0.6: Clear cache for taxonomy or post
                        if ($is_taxonomy) {
                            // For taxonomy, clear all posts in category (future enhancement)
                            lingua_debug_log("[LINGUA CACHE v5.0.6] Taxonomy translation saved: $taxonomy:$term_id");
                        } else {
                            $output_buffer->clear_cache_on_post_update($post_id);
                            lingua_debug_log("[LINGUA CACHE v3.0.32] Cleared cache for post $post_id after translation save");
                        }

                        // v5.0.18: CRITICAL - Clear ALL translation caches when translations are updated
                        $output_buffer->clear_all_translation_caches();
                        lingua_debug_log("[LINGUA CACHE v5.0.18] Cleared ALL translation caches (original + translated HTML)");
                    }

                    // v5.2.137: Clear Yoast SEO caches
                    if (defined('WPSEO_VERSION')) {
                        // Clear Yoast transients
                        global $wpdb;
                        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpseo%' OR option_name LIKE '_transient_timeout_wpseo%'");

                        // Clear indexable cache for this post
                        if ($post_id && class_exists('WPSEO_Meta')) {
                            delete_post_meta($post_id, '_yoast_wpseo_primary_category');
                            // Force Yoast to regenerate indexables
                            do_action('wpseo_save_compare_data', get_post($post_id));
                        }

                        lingua_debug_log("[LINGUA CACHE v5.2.137] Cleared Yoast SEO caches after translation save");
                    }

                    // v5.2.163: Build message based on operations performed
                    $message_parts = array();
                    if ($save_results['total_processed'] > 0) {
                        $message_parts[] = sprintf('%d saved', $save_results['total_processed']);
                    }
                    if ($deleted_count > 0) {
                        $message_parts[] = sprintf('%d deleted', $deleted_count);
                    }
                    $message = !empty($message_parts) ? 'Translation: ' . implode(', ', $message_parts) : 'Translation updated';

                    wp_send_json_success(array(
                        'message' => $message,
                        'statistics' => $statistics,
                        'save_results' => $save_results,
                        'processed_strings' => count($processed_strings),
                        'deleted_count' => $deleted_count
                    ));
                } else {
                    wp_send_json_error(array(
                        'message' => __('Failed to save unified translation', 'yourtranslater'),
                        'errors' => $save_results['errors'],
                        'save_results' => $save_results
                    ));
                }
            } else {
                wp_send_json_error(__('Invalid translation strings format', 'yourtranslater'));
            }
        }
        else {
            // v2 unified only - no legacy fallback
            wp_send_json_error(__('Invalid unified translation format', 'yourtranslater'));
        }
    }

    /**
     * Save translations globally
     * When enabled, identical text will be translated consistently across all pages
     */
    private function save_global_translations($manager, $post_id, $language, $clean_translations) {
        global $wpdb;

        // First save the main translation for this specific post
        $result = $manager->save_translation($post_id, $language, $clean_translations);

        if (!$result) {
            return false;
        }

        // Then apply globally to string translations table
        $string_table = $wpdb->prefix . 'lingua_string_translations';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$string_table'") !== $string_table) {
            return $result; // Return success for main translation even if global update fails
        }

        $saved_count = 0;

        // Process content blocks for global translation
        if (isset($clean_translations['content'])) {
            $content_data = json_decode($clean_translations['content'], true);
            if (is_array($content_data)) {
                foreach ($content_data as $block) {
                    if (isset($block['original']) && isset($block['translated']) && !empty($block['translated'])) {
                        $original = sanitize_text_field($block['original']);
                        $translated = sanitize_text_field($block['translated']);

                        // Update or insert global translation
                        $existing = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM $string_table WHERE original_text = %s AND language_code = %s",
                            $original, $language
                        ));

                        if ($existing) {
                            $wpdb->update(
                                $string_table,
                                array(
                                    'translated_text' => $translated,
                                    'status' => Lingua_Database::HUMAN_REVIEWED,
                                    'updated_at' => current_time('mysql')
                                ),
                                array('id' => $existing->id),
                                array('%s', '%s', '%s'),
                                array('%d')
                            );
                        } else {
                            $wpdb->insert(
                                $string_table,
                                array(
                                    'original_text' => $original,
                                    'translated_text' => $translated,
                                    'language_code' => $language,
                                    'context' => 'content',
                                    'status' => Lingua_Database::HUMAN_REVIEWED,
                                    'created_at' => current_time('mysql'),
                                    'updated_at' => current_time('mysql')
                                ),
                                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
                            );
                        }
                        $saved_count++;
                    }
                }
            }
        }

        // Process meta fields globally
        if (isset($clean_translations['meta']) && is_array($clean_translations['meta'])) {
            foreach ($clean_translations['meta'] as $meta_key => $translated_value) {
                if (!empty($translated_value)) {
                    // Get the original meta value
                    $original_meta = get_post_meta($post_id, '_yoast_wpseo_title', true);
                    if ($meta_key === 'seo_description') {
                        $original_meta = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
                    } elseif ($meta_key === 'woo_short_desc') {
                        $original_meta = get_post($post_id)->post_excerpt;
                    }

                    if (!empty($original_meta)) {
                        // Save globally
                        $existing = $wpdb->get_row($wpdb->prepare(
                            "SELECT id FROM $string_table WHERE original_text = %s AND language_code = %s",
                            $original_meta, $language
                        ));

                        if ($existing) {
                            $wpdb->update(
                                $string_table,
                                array(
                                    'translated_text' => sanitize_text_field($translated_value),
                                    'status' => Lingua_Database::HUMAN_REVIEWED,
                                    'updated_at' => current_time('mysql')
                                ),
                                array('id' => $existing->id),
                                array('%s', '%s', '%s'),
                                array('%d')
                            );
                        } else {
                            $wpdb->insert(
                                $string_table,
                                array(
                                    'original_text' => $original_meta,
                                    'translated_text' => sanitize_text_field($translated_value),
                                    'language_code' => $language,
                                    'context' => $meta_key,
                                    'status' => Lingua_Database::HUMAN_REVIEWED,
                                    'created_at' => current_time('mysql'),
                                    'updated_at' => current_time('mysql')
                                ),
                                array('%s', '%s', '%s', '%s', '%s', '%s', '%s')
                            );
                        }
                        $saved_count++;
                    }
                }
            }
        }

        lingua_debug_log("Lingua Debug: Global mode saved $saved_count strings globally for language: $language");

        return $result;
    }

    /**
     * Auto-translate on post save
     */
    public function maybe_auto_translate($post_id, $post, $update) {
        // Check if auto-translation is enabled
        $auto_translate_posts = get_option('lingua_auto_translate_posts', false);
        $auto_translate_pages = get_option('lingua_auto_translate_pages', false);
        
        if (!$auto_translate_posts && !$auto_translate_pages) {
            return;
        }
        
        // Check post type
        if (($post->post_type === 'post' && !$auto_translate_posts) ||
            ($post->post_type === 'page' && !$auto_translate_pages)) {
            return;
        }
        
        // Only for published posts
        if ($post->post_status !== 'publish') {
            return;
        }
        
        // Avoid infinite loops
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        // Get enabled languages
        $languages = get_option('lingua_languages', array());
        $default_lang = get_option('lingua_default_language', 'ru');
        
        // Auto-translate to all enabled languages
        foreach ($languages as $lang_code => $lang_data) {
            if ($lang_code !== $default_lang) {
                wp_schedule_single_event(time() + 10, 'lingua_auto_translate_post', array($post_id, $lang_code));
            }
        }
    }
    
    /**
     * Add translate button to WordPress admin bar (верхняя панель)
     */
    public function add_translate_button($wp_admin_bar) {
        
        // Проверяем права пользователя
        if (!current_user_can(lingua_translating_capability())) {
            return;
        }
        
        // Проверяем наличие активных языков
        $languages = get_option('lingua_languages', array());
        
        if (empty($languages)) {
            return;
        }
        
        // Упрощенная проверка - показываем кнопку везде, где есть админ бар
        $wp_admin_bar->add_node(array(
            'id'    => 'lingua-translate-page',
            'title' => '<span class="ab-icon dashicons-translation"></span>' . __('Translate Page', 'yourtranslater'),
            'href'  => '#',
            'meta'  => array(
                'class' => 'lingua-admin-bar-translate-btn',
                'onclick' => 'return false;',
                'title' => __('Open translation manager for this page', 'yourtranslater')
            )
        ));
    }
    
    /**
     * Add translate button data attributes via JavaScript
     * (так как admin bar не поддерживает data-атрибуты напрямую)
     */
    public function add_translate_button_script() {
        if (!current_user_can(lingua_translating_capability())) {
            return;
        }
        
        global $post;
        $post_id = $post ? $post->ID : 0;
        
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Добавляем data-post-id к кнопке в admin bar
            $('#wp-admin-bar-lingua-translate-page .lingua-admin-bar-translate-btn').attr('data-post-id', '<?php echo $post_id; ?>');
            
            // Debug информация
            console.log('Lingua: Button script loaded, post ID: <?php echo $post_id; ?>');
        });
        </script>
        <?php
    }

    /**
     * AJAX handler for getting modal template
     */
    public function ajax_get_modal_template() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_translating_capability())) {
            wp_die();
        }

        // Включаем шаблон модального окна
        ob_start();
        include LINGUA_PLUGIN_DIR . 'admin/views/translation-modal.php';
        $template = ob_get_clean();

        wp_send_json_success($template);
    }

    /**
     * AJAX handler for auto-translating text
     */
    public function ajax_auto_translate_text() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_translating_capability())) {
            wp_die();
        }

        // v5.2.174: Check Pro license for auto-translation feature
        $api = new Lingua_Middleware_API();
        if (!$api->is_pro_active()) {
            wp_send_json_error(array(
                'message' => __('Auto-translation is a Pro feature. Please activate your license in Settings → License.', 'yourtranslater'),
                'upgrade_required' => true
            ));
            return;
        }

        // Allow HTML tags in text for proper translation (use wp_kses_post for security)
        $text = isset($_POST['text']) ? wp_kses_post(stripslashes($_POST['text'])) : '';
        $source_lang = isset($_POST['source_lang']) ? sanitize_text_field($_POST['source_lang']) : 'auto';
        $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';

        if (empty($text) || empty($target_lang)) {
            wp_send_json_error(__('Invalid parameters for translation', 'yourtranslater'));
        }

        // Проверяем наличие HTML тегов и используем соответствующий метод перевода
        if (strpos($text, '<') !== false && strpos($text, '>') !== false) {
            // HTML content - use HTML-preserving translation
            $result = $api->translate_html($text, $target_lang, $source_lang);
        } else {
            // Plain text - use regular translation
            $result = $api->translate($text, $target_lang, $source_lang);
        }

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success(array(
                'translated_text' => $result,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang
            ));
        }
    }

    /**
     * v5.2.199: AJAX handler for batch auto-translation (10-50x faster)
     */
    public function ajax_auto_translate_batch() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_translating_capability())) {
            wp_die();
        }

        $api = new Lingua_Middleware_API();
        if (!$api->is_pro_active()) {
            wp_send_json_error(array(
                'message' => __('Auto-translation is a Pro feature.', 'yourtranslater'),
                'upgrade_required' => true
            ));
            return;
        }

        $texts = isset($_POST['texts']) ? $_POST['texts'] : array();
        $target_lang = isset($_POST['target_lang']) ? sanitize_text_field($_POST['target_lang']) : '';
        $source_lang = isset($_POST['source_lang']) ? sanitize_text_field($_POST['source_lang']) : 'auto';

        if (empty($texts) || empty($target_lang)) {
            wp_send_json_error(__('Invalid parameters', 'yourtranslater'));
            return;
        }

        // Sanitize texts (allow HTML)
        $clean_texts = array();
        foreach ($texts as $text) {
            $clean_texts[] = wp_kses_post(stripslashes($text));
        }

        // Use batch translation
        $results = $api->translate_batch($clean_texts, $target_lang, $source_lang);

        if (is_wp_error($results)) {
            wp_send_json_error($results->get_error_message());
        } else {
            wp_send_json_success(array(
                'translations' => $results,
                'count' => count($results)
            ));
        }
    }

    /**
     * Handle bulk translation request
     */
    private function handle_bulk_translation() {
        $post_types = isset($_POST['post_types']) ? $_POST['post_types'] : array();
        $target_languages = isset($_POST['target_languages']) ? $_POST['target_languages'] : array();
        $post_status = isset($_POST['post_status']) ? sanitize_text_field($_POST['post_status']) : 'publish';
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10;
        
        if (empty($post_types) || empty($target_languages)) {
            add_settings_error('lingua_bulk', 'missing_params', __('Please select post types and target languages.', 'yourtranslater'));
            return;
        }
        
        // Get posts to translate
        $args = array(
            'post_type' => $post_types,
            'post_status' => $post_status,
            'posts_per_page' => $limit,
            'meta_query' => array(
                array(
                    'key' => '_lingua_auto_translated',
                    'compare' => 'NOT EXISTS'
                )
            )
        );
        
        $posts = get_posts($args);
        $scheduled_count = 0;
        
        foreach ($posts as $post) {
            foreach ($target_languages as $lang) {
                // Schedule auto-translation
                wp_schedule_single_event(time() + ($scheduled_count * 30), 'lingua_auto_translate_post', array($post->ID, $lang));
                $scheduled_count++;
            }
            
            // Mark as scheduled for auto-translation
            update_post_meta($post->ID, '_lingua_auto_translated', time());
        }
        
        add_settings_error('lingua_bulk', 'success',
            sprintf(__('Scheduled %d translations for %d posts.', 'yourtranslater'), $scheduled_count, count($posts)),
            'updated'
        );
    }

    /**
     * Save unified translations - v2 UNIFIED VERSION
     * Processes all translation groups and saves to unified table
     */
    /**
     * PHASE 3: BATCH SAVE
     * Saves all translations in a single SQL query for massive performance boost
     */
    private function save_unified_translations($processed_strings, $post_id, $language, $page_type = 'post', $term_id = 0, $taxonomy = '') {
        global $wpdb;

        $results = array(
            'total_processed' => 0,
            'total_saved' => 0,
            'errors' => array(),
            'deduplicated' => 0
        );

        if (empty($processed_strings)) {
            return $results;
        }

        // v5.0.6: Build context prefix for taxonomy pages
        // v5.5.2: Support post_type_archive context
        $is_taxonomy = ($page_type === 'taxonomy' && $term_id > 0);
        if ($is_taxonomy) {
            $context_prefix = "taxonomy_{$taxonomy}_{$term_id}";
        } elseif ($page_type === 'post_type_archive') {
            $context_prefix = "archive";
        } else {
            $context_prefix = "post_{$post_id}";
        }

        lingua_debug_log("[LINGUA SAVE v5.0.6] Processing " . count($processed_strings) . " strings for context: $context_prefix");

        // Step 1: Deduplication
        // v5.2.116: CRITICAL FIX - Include plural_form_index in fingerprint to prevent deduplication of plural forms!
        $deduplicated_strings = array();
        $seen_fingerprints = array();

        foreach ($processed_strings as $string) {
            $original_text = $string['original'] ?? '';
            $plural_form_index = isset($string['plural_form_index']) ? intval($string['plural_form_index']) : 'null';

            // v5.2.165: Include field_group and type in fingerprint for SEO fields
            // This prevents og_description and meta_description with same text from being deduplicated
            $field_group = $string['field_group'] ?? '';
            $seo_type = $string['type'] ?? '';
            $context_suffix = '';
            if ($field_group === 'seo_fields' && !empty($seo_type)) {
                $context_suffix = '|seo_type:' . $seo_type;
            }

            // v5.2.116: Include plural_form_index in fingerprint
            // Example: "product" with form=0 and "product" with form=1 should be different!
            $fingerprint = md5(trim($original_text) . '|plural_form:' . $plural_form_index . $context_suffix);

            if (!isset($seen_fingerprints[$fingerprint])) {
                $seen_fingerprints[$fingerprint] = true;
                $deduplicated_strings[] = $string;
            } else {
                $results['deduplicated']++;
            }
        }

        lingua_debug_log("[LINGUA SAVE v3.0 BATCH] After dedup: " . count($deduplicated_strings) . " unique strings");

        // Step 2: Prepare batch INSERT with ON DUPLICATE KEY UPDATE
        $table_name = $wpdb->prefix . 'lingua_string_translations';
        $values = array();
        $placeholders = array();

        // Step 3: Sync original_strings table first
        $originals_table = $wpdb->prefix . 'lingua_original_strings';
        $original_texts = array();

        foreach ($deduplicated_strings as $string) {
            $original_texts[] = $string['original'];
        }

        if (!empty($original_texts)) {
            // Insert unique originals (IGNORE duplicates)
            $original_values = array();
            foreach ($original_texts as $original) {
                $original_values[] = $wpdb->prepare("(%s, NOW())", $original);
            }

            $wpdb->query(
                "INSERT IGNORE INTO `{$originals_table}` (original, created_at) VALUES " .
                implode(', ', $original_values)
            );
        }

        // Step 4: Build batch INSERT for translations
        foreach ($deduplicated_strings as $string) {
            $original = $string['original'];
            $translated = $string['translated'];

            // v3.0.18: NO normalization - preserve HTML tags in translations!
            // Yandex API with format="HTML" returns translations WITH HTML tags intact
            // Example: "Text<br>More" → "Translation<br>More translation"

            $context = $string['context'] ?? 'general';
            $field_group = $string['field_group'] ?? 'page_strings';

            // Calculate hash
            $hash = md5($original);

            // v5.2.198: Extract gettext metadata FIRST (before context building)
            $source = isset($string['source']) ? $string['source'] : 'custom';
            $gettext_domain = isset($string['gettext_domain']) ? $string['gettext_domain'] : null;

            // v5.2.198: Build context - gettext strings MUST use gettext-specific context
            // to match existing records and avoid duplicates
            if ($source === 'gettext' && $gettext_domain) {
                // Gettext strings use "gettext.{domain}" context (e.g., "gettext.woodmart")
                // This ensures Modal saves update existing Strings page records
                $final_context = "gettext." . $gettext_domain;
            } elseif ($field_group === 'media' && isset($string['src_hash']) && isset($string['attribute'])) {
                $final_context = "media.src[{$string['src_hash']}].{$string['attribute']}";
            } elseif ($field_group === 'seo_fields' && isset($string['type'])) {
                // v5.2.165: SEO fields get type-specific context to prevent overwrites
                // e.g., post_2222_seo_title, post_2222_og_title, post_2222_og_description
                $final_context = $context_prefix . '_' . $string['type'];
            } else {
                // v5.0.6: Use context_prefix for taxonomy pages
                $final_context = $context_prefix . '_' . $field_group;
            }

            // v5.2.76: Extract plural metadata
            $is_plural = isset($string['is_plural']) && $string['is_plural'];
            $plural_pair = isset($string['plural_pair']) ? $string['plural_pair'] : null;
            $plural_form_index = isset($string['plural_form_index']) ? intval($string['plural_form_index']) : null;
            $russian_forms = isset($string['russian_forms']) && is_array($string['russian_forms']) ? $string['russian_forms'] : null;

            // v5.2.115: ULTRA DEBUG - Log EVERY string's plural metadata
            lingua_debug_log("🔥 LINGUA v5.2.115 STRING DEBUG:");
            lingua_debug_log("  - Original: " . substr($original, 0, 50));
            lingua_debug_log("  - is_plural: " . ($is_plural ? 'TRUE' : 'FALSE'));
            lingua_debug_log("  - plural_form_index: " . ($plural_form_index !== null ? $plural_form_index : 'NULL'));
            lingua_debug_log("  - plural_pair: " . ($plural_pair ?: 'NULL'));
            lingua_debug_log("  - translated type: " . gettype($translated));
            lingua_debug_log("  - translated is_array: " . (is_array($translated) ? 'TRUE' : 'FALSE'));
            lingua_debug_log("  - russian_forms: " . ($russian_forms ? 'YES' : 'NULL'));

            // v5.2.76: Debug log for gettext strings with DETAILED plural info
            if ($source === 'gettext') {
                lingua_debug_log("🔍 LINGUA v5.2.76 GETTEXT SAVE DEBUG:");
                lingua_debug_log("  - Language: {$language}");
                lingua_debug_log("  - Domain: {$gettext_domain}");
                lingua_debug_log("  - Original: " . substr($original, 0, 50));
                lingua_debug_log("  - is_plural: " . ($is_plural ? 'TRUE' : 'FALSE'));
                lingua_debug_log("  - plural_pair: " . ($plural_pair ? $plural_pair : 'NULL'));
                lingua_debug_log("  - plural_form_index: " . ($plural_form_index !== null ? $plural_form_index : 'NULL'));
                lingua_debug_log("  - russian_forms: " . ($russian_forms ? print_r($russian_forms, true) : 'NULL'));
                lingua_debug_log("  - translated type: " . gettype($translated));
                lingua_debug_log("  - translated value: " . print_r($translated, true));
            }

            // v5.2.76: PLURAL FORMS (multiple rows per plural string)
            if ($is_plural && $russian_forms && is_array($russian_forms)) {
                // RUSSIAN 3-FORM PLURAL - save all 3 forms from russian_forms array
                // This happens when JavaScript sends russian_forms: [form0, form1, form2]
                lingua_debug_log("🔥 LINGUA v5.2.115: BRANCH 1 - RUSSIAN 3-form plural for original='{$original}'");

                foreach ($russian_forms as $form_index => $form_translated) {
                    if (empty($form_translated)) {
                        continue; // Skip empty forms
                    }

                    $values[] = $original;  // Same original for all forms (msgid)
                    $values[] = $hash;
                    $values[] = $form_translated;  // Different translation per form
                    $values[] = $language;
                    $values[] = $final_context;
                    $values[] = $source;
                    $values[] = $gettext_domain;
                    $values[] = intval($form_index);  // 0, 1, 2 for Russian
                    $values[] = $plural_pair;  // msgid_plural
                    $values[] = Lingua_Database::BLOCK_TYPE_REGULAR_STRING;
                    $values[] = Lingua_Database::HUMAN_REVIEWED;

                    $placeholders[] = "(%s, %s, %s, %s, %s, %s, %s, %d, %s, %d, %d)";
                    $results['total_processed']++;

                    lingua_debug_log("🔍 LINGUA v5.2.76 RUSSIAN PLURAL: Saved form={$form_index}, text=" . substr($form_translated, 0, 30));
                }
            } elseif ($is_plural && $plural_form_index !== null && !is_array($translated)) {
                // 2-FORM PLURAL (English/German/Italian) - save single form with plural_form_index
                // This happens when JavaScript sends plural_form_index: 0 or 1
                lingua_debug_log("🔥 LINGUA v5.2.115: BRANCH 2 - 2-form plural: original='{$original}', form={$plural_form_index}, pair='{$plural_pair}'");

                $values[] = $original;  // msgid or msgid_plural
                $values[] = $hash;
                $values[] = $translated;  // This form's translation
                $values[] = $language;
                $values[] = $final_context;
                $values[] = $source;
                $values[] = $gettext_domain;
                $values[] = intval($plural_form_index);  // 0 for singular, 1 for plural
                $values[] = $plural_pair;  // Link to other form (msgid_plural or msgid)
                $values[] = Lingua_Database::BLOCK_TYPE_REGULAR_STRING;
                $values[] = Lingua_Database::HUMAN_REVIEWED;

                $placeholders[] = "(%s, %s, %s, %s, %s, %s, %s, %d, %s, %d, %d)";
                $results['total_processed']++;
            } elseif ($is_plural && is_array($translated)) {
                // LEGACY: Array format (backwards compatibility)
                // This should not happen with new JavaScript code
                lingua_debug_log("🔥 LINGUA v5.2.115: BRANCH 3 - LEGACY array format detected!");

                foreach ($translated as $plural_form => $translated_text) {
                    $values[] = $original;  // Same original for all forms
                    $values[] = $hash;
                    $values[] = $translated_text;  // Different translation per form
                    $values[] = $language;
                    $values[] = $final_context;
                    $values[] = $source;
                    $values[] = $gettext_domain;
                    $values[] = intval($plural_form);  // NEW: 0, 1, 2 for plural forms
                    $values[] = $plural_pair;  // NEW: plural msgid (e.g., "%d items")
                    $values[] = Lingua_Database::BLOCK_TYPE_REGULAR_STRING;
                    $values[] = Lingua_Database::HUMAN_REVIEWED;

                    $placeholders[] = "(%s, %s, %s, %s, %s, %s, %s, %d, %s, %d, %d)";

                    $results['total_processed']++;

                    lingua_debug_log("🔍 LINGUA v5.2.76 PLURAL: Saved plural_form={$plural_form}, text=" . substr($translated_text, 0, 30));
                }
            } else {
                // SINGLE STRING - one row (original behavior)
                lingua_debug_log("🔥 LINGUA v5.2.115: BRANCH 4 - SINGLE STRING (not plural)");
                $single_translated = is_array($translated) ? $translated[0] : $translated;

                $values[] = $original;
                $values[] = $hash;
                $values[] = $single_translated;
                $values[] = $language;
                $values[] = $final_context;
                $values[] = $source;
                $values[] = $gettext_domain;
                $values[] = null;  // NEW: plural_form = NULL for single strings
                $values[] = null;  // NEW: original_plural = NULL for single strings
                $values[] = Lingua_Database::BLOCK_TYPE_REGULAR_STRING;
                $values[] = Lingua_Database::HUMAN_REVIEWED;

                $placeholders[] = "(%s, %s, %s, %s, %s, %s, %s, %d, %s, %d, %d)";

                $results['total_processed']++;

                // Save core fields to postmeta for backward compatibility
                if (in_array($string['type'], ['title', 'excerpt'])) {
                    $meta_key = "lingua_{$language}_{$string['type']}";
                    update_post_meta($post_id, $meta_key, $single_translated);
                }
            }
        }

        // Step 5: Execute batch INSERT
        if (!empty($values)) {
            $query = "INSERT INTO `{$table_name}`
                      (original_text, original_text_hash, translated_text, language_code, context, source, gettext_domain, plural_form, original_plural, block_type, status)
                      VALUES " . implode(', ', $placeholders) . "
                      ON DUPLICATE KEY UPDATE
                      translated_text = VALUES(translated_text),
                      source = VALUES(source),
                      gettext_domain = COALESCE(VALUES(gettext_domain), gettext_domain),
                      plural_form = VALUES(plural_form),
                      original_plural = COALESCE(VALUES(original_plural), original_plural),
                      status = VALUES(status),
                      updated_at = CURRENT_TIMESTAMP";

            $prepared = $wpdb->prepare($query, $values);
            $result = $wpdb->query($prepared);

            if ($result === false) {
                $results['errors'][] = $wpdb->last_error;
                lingua_debug_log("[LINGUA SAVE v5.2.73 BATCH] ERROR: " . $wpdb->last_error);
                lingua_debug_log("[LINGUA SAVE v5.2.73 BATCH] Failed query: " . substr($prepared, 0, 500));
            } else {
                $results['total_saved'] = $result;
                lingua_debug_log("[LINGUA SAVE v5.2.73 BATCH] SUCCESS: Saved {$result} translations for language={$language}");
            }
        }

        // Step 6: Update original_id FK references
        $wpdb->query("
            UPDATE `{$table_name}` t
            INNER JOIN `{$originals_table}` o
            ON t.original_text = o.original
            SET t.original_id = o.id
            WHERE t.original_id IS NULL AND t.language_code = '{$language}'
        ");

        lingua_debug_log("[LINGUA SAVE v3.0 BATCH] COMPLETE: Processed {$results['total_processed']}, Saved {$results['total_saved']}");

        return $results;
    }

    /**
     * LEGACY METHOD REMOVED - v3.0 uses batch save only
     */

    /**
     * Cleanup existing database duplicates for post/language
     * Removes duplicate translations where same content exists as different field_groups
     */
    private function cleanup_existing_duplicates($post_id, $language) {
        global $wpdb;
        $string_table = $wpdb->prefix . 'lingua_string_translations';

        // Find duplicates by content hash for this post/language
        $duplicates_query = $wpdb->prepare("
            SELECT original_text_hash, COUNT(*) as dup_count, GROUP_CONCAT(id) as duplicate_ids
            FROM {$string_table}
            WHERE post_id = %d AND language_code = %s
            GROUP BY original_text_hash
            HAVING COUNT(*) > 1
        ", $post_id, $language);

        $duplicates = $wpdb->get_results($duplicates_query);

        if (!empty($duplicates)) {
            lingua_debug_log("[LINGUA CLEANUP] Found " . count($duplicates) . " duplicate groups for post {$post_id}, language {$language}");

            foreach ($duplicates as $duplicate_group) {
                $ids = explode(',', $duplicate_group->duplicate_ids);

                // Keep the first one (lowest ID), delete the rest
                $keep_id = array_shift($ids);
                $delete_ids = $ids;

                if (!empty($delete_ids)) {
                    $delete_ids_placeholders = implode(',', array_fill(0, count($delete_ids), '%d'));
                    $delete_query = "DELETE FROM {$string_table} WHERE id IN ({$delete_ids_placeholders})";
                    $wpdb->query($wpdb->prepare($delete_query, $delete_ids));

                    lingua_debug_log("[LINGUA CLEANUP] Removed " . count($delete_ids) . " duplicate records, kept ID: {$keep_id}");
                }
            }
        }
    }

    /**
     * Save unified translation strings to lingua_string_translations table
     * @param array $processed_strings Unified translation strings
     * @param int $post_id Post ID
     * @param string $language Language code
     */
    private function save_unified_strings_to_db($processed_strings, $post_id, $language) {
        global $wpdb;

        $string_table = $wpdb->prefix . 'lingua_string_translations';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$string_table'") !== $string_table) {
            lingua_debug_log("🚨 LINGUA v2.1 UNIFIED: lingua_string_translations table does not exist");
            return false;
        }

        $saved_count = 0;
        $updated_count = 0;

        foreach ($processed_strings as $string) {
            $original = $string['original'];
            $translated = $string['translated'];
            $context = $string['context'];
            $type = $string['type'];
            $field_group = $string['field_group'];

            // v5.2: Build media-specific context with src_hash
            if ($field_group === 'media' && isset($string['src_hash']) && isset($string['attribute'])) {
                $context = "media.src[{$string['src_hash']}].{$string['attribute']}";
            }

            if (empty($translated)) {
                continue; // Skip empty translations
            }

            // Check if this string already exists
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, translated_text FROM $string_table
                 WHERE original_text = %s AND language_code = %s AND context = %s",
                $original, $language, $context
            ));

            if ($existing) {
                // Update existing translation
                $wpdb->update(
                    $string_table,
                    array(
                        'translated_text' => $translated,
                        'status' => Lingua_Database::HUMAN_REVIEWED,
                        'post_id' => $post_id,
                        'field_group' => $field_group,
                        'type' => $type,
                        'updated_at' => current_time('mysql')
                    ),
                    array('id' => $existing->id),
                    array('%s', '%s', '%d', '%s', '%s', '%s'),
                    array('%d')
                );
                $updated_count++;
            } else {
                // Insert new translation
                $result = $wpdb->insert(
                    $string_table,
                    array(
                        'original_text' => $original,
                        'original_text_hash' => md5($original),
                        'translated_text' => $translated,
                        'language_code' => $language,
                        'context' => $context,
                        'status' => Lingua_Database::HUMAN_REVIEWED,
                        'post_id' => $post_id,
                        'field_group' => $field_group,
                        'type' => $type,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
                );

                if ($result) {
                    $saved_count++;
                }
            }
        }

        lingua_debug_log("🔍 LINGUA v2.1 UNIFIED: Saved $saved_count new strings, updated $updated_count existing strings");

        return array(
            'saved' => $saved_count,
            'updated' => $updated_count,
            'total' => $saved_count + $updated_count
        );
    }

    /**
     * Save core WordPress fields (title, excerpt) with language suffix
     */
    private function save_core_field($post_id, $type, $translated, $language) {
        switch ($type) {
            case 'title':
                $meta_key = "lingua_{$language}_title";
                $result = update_post_meta($post_id, $meta_key, $translated);
                lingua_debug_log("[LINGUA SAVE v2.1.3] CORE FIELD: Saved {$meta_key} = '{$translated}' for post {$post_id} | Result: " . ($result ? 'SUCCESS' : 'FAILED'));
                return $result;
            case 'excerpt':
                $meta_key = "lingua_{$language}_excerpt";
                $result = update_post_meta($post_id, $meta_key, $translated);
                lingua_debug_log("[LINGUA SAVE v2.1.3] CORE FIELD: Saved {$meta_key} = '{$translated}' for post {$post_id} | Result: " . ($result ? 'SUCCESS' : 'FAILED'));
                return $result;
            default:
                lingua_debug_log("[LINGUA SAVE v2.1.3] CORE FIELD: Unknown type '{$type}'");
                return false;
        }
    }

    /**
     * Save SEO fields with language suffix
     */
    private function save_seo_field($post_id, $type, $translated, $language) {
        switch ($type) {
            case 'seo_title':
                $meta_key = "lingua_{$language}_seo_title";
                return update_post_meta($post_id, $meta_key, $translated);
            case 'seo_description':
                $meta_key = "lingua_{$language}_seo_description";
                return update_post_meta($post_id, $meta_key, $translated);
            default:
                return false;
        }
    }

    /**
     * Save meta fields with language suffix (WooCommerce Short Description, custom fields)
     */
    private function save_meta_field($post_id, $field_id, $translated, $language, $type) {
        $meta_key = "lingua_{$language}_{$field_id}";

        // Special handling for WooCommerce Short Description
        if ($type === 'woo_short_desc') {
            $meta_key = "lingua_{$language}_short_description";
        }

        $result = update_post_meta($post_id, $meta_key, $translated);
        lingua_debug_log("[LINGUA SAVE v2.1.3] META FIELD: Saved {$meta_key} = '{$translated}' for post {$post_id} (type: {$type}) | Result: " . ($result ? 'SUCCESS' : 'FAILED'));

        // Additional check: verify it was actually saved
        $saved_value = get_post_meta($post_id, $meta_key, true);
        if ($saved_value === $translated) {
            lingua_debug_log("[LINGUA SAVE v2.1.3] META FIELD: VERIFICATION PASSED - Value correctly saved");
            return true; // Success if verification passes, even if update_post_meta returned false
        } else {
            lingua_debug_log("[LINGUA SAVE v2.1.3] META FIELD: VERIFICATION FAILED - Expected: '{$translated}', Got: '{$saved_value}'");
            return false;
        }
    }

    /**
     * Save taxonomy terms (WooCommerce categories, product tags)
     */
    private function save_taxonomy_term($post_id, $term_id, $original, $translated, $language, $context) {
        // Save to wp_termmeta table with language suffix
        $meta_key = "lingua_{$language}_name";
        $result = update_term_meta($term_id, $meta_key, $translated);

        // Also save to string translations table for global lookup
        return $result !== false;
    }

    /**
     * Save WooCommerce product attributes (Color, Size, etc)
     */
    private function save_woo_attribute($post_id, $attribute_id, $original, $translated, $language, $context) {
        // WooCommerce attributes are stored in post meta as complex arrays
        $meta_key = "lingua_{$language}_attribute_{$attribute_id}";
        return update_post_meta($post_id, $meta_key, $translated);
    }

    /**
     * Save page strings (buttons, labels, general text) - v2 UNIFIED VERSION
     * Now saves to lingua_string_translations table instead of postmeta
     */
    private function save_page_string($post_id, $string_id, $original, $translated, $language, $context) {
        global $wpdb;

        $string_table = $wpdb->prefix . 'lingua_string_translations';

        // v2 unified: Save directly to lingua_string_translations
        $context_key = "page_strings.{$string_id}.post_{$post_id}";

        lingua_debug_log("[LINGUA SAVE v2] PAGE STRING: Saving to unified table - context='{$context_key}'");
        lingua_debug_log("[LINGUA SAVE v2] PAGE STRING: original='{$original}', translated='{$translated}'");

        // Check if string already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$string_table} WHERE context = %s AND language_code = %s",
            $context_key,
            $language
        ));

        // Generate hash for the original text
        $original_hash = md5($original);

        if ($existing) {
            // Update existing
            $result = $wpdb->update(
                $string_table,
                array(
                    'original_text' => $original,
                    'translated_text' => $translated,
                    'original_text_hash' => $original_hash,
                    'status' => Lingua_Database::HUMAN_REVIEWED
                ),
                array('id' => $existing->id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            // Insert new
            $result = $wpdb->insert(
                $string_table,
                array(
                    'original_text' => $original,
                    'translated_text' => $translated,
                    'language_code' => $language,
                    'context' => $context_key,
                    'original_text_hash' => $original_hash,
                    'status' => Lingua_Database::HUMAN_REVIEWED
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s')
            );
        }

        lingua_debug_log("[LINGUA SAVE v2] PAGE STRING: " . ($result !== false ? 'SUCCESS' : 'FAILED') . " for context '{$context_key}'");

        return $result !== false;
    }

    /**
     * Save content blocks (paragraph blocks, etc.)
     */
    private function save_content_block($post_id, $block_id, $original, $translated, $language) {
        $meta_key = "lingua_{$language}_content_blocks";
        $existing_blocks = get_post_meta($post_id, $meta_key, true) ?: array();

        // Update or add the block
        $existing_blocks[$block_id] = array(
            'original' => $original,
            'translated' => $translated
        );

        return update_post_meta($post_id, $meta_key, $existing_blocks);
    }

    /**
     * Save translation to lingua_string_translations table for global lookup
     */
    private function save_to_string_translations_table($original, $translated, $language, $context, $field_group, $type, $post_id) {
        global $wpdb;

        $string_table = $wpdb->prefix . 'lingua_string_translations';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$string_table'") !== $string_table) {
            return false;
        }

        // Check if this string already exists
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $string_table WHERE original_text = %s AND language_code = %s AND context = %s",
            $original, $language, $context
        ));

        if ($existing) {
            // Update existing
            return $wpdb->update(
                $string_table,
                array(
                    'translated_text' => $translated,
                    'field_group' => $field_group,
                    'type' => $type,
                    'post_id' => $post_id,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => $existing->id),
                array('%s', '%s', '%s', '%d', '%s'),
                array('%d')
            );
        } else {
            // Insert new
            return $wpdb->insert(
                $string_table,
                array(
                    'original_text' => $original,
                    'original_text_hash' => md5($original),
                    'translated_text' => $translated,
                    'language_code' => $language,
                    'context' => $context,
                    'status' => Lingua_Database::HUMAN_REVIEWED,
                    'field_group' => $field_group,
                    'type' => $type,
                    'post_id' => $post_id,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s')
            );
        }
    }

    /**
     * v3.2: AJAX handler to clear bad WooCommerce translations
     * Removes translations containing JSON data and HTML fragments
     */
    public function ajax_clear_bad_translations() {
        global $wpdb;

        // Security check
        if (!check_ajax_referer('lingua_admin_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error('Post ID required');
            return;
        }

        lingua_debug_log("[LINGUA v3.2 CLEAR] Starting cleanup for post $post_id");

        $string_table = $wpdb->prefix . 'lingua_string_translations';

        // Delete translations with WooCommerce JSON/HTML fragments
        $deleted = $wpdb->query($wpdb->prepare("
            DELETE FROM $string_table
            WHERE post_id = %d
            AND (
                original_text LIKE %s
                OR original_text LIKE %s
                OR original_text LIKE %s
                OR original_text LIKE %s
                OR translated_text LIKE %s
                OR translated_text LIKE %s
                OR translated_text LIKE %s
                OR translated_text LIKE %s
            )
        ",
            $post_id,
            '%</span>%',
            '%<\\\\/span>%',
            '%variation%',
            '%\"sku\"%',
            '%</span>%',
            '%<\\\\/span>%',
            '%variation%',
            '%\"sku\"%'
        ));

        lingua_debug_log("[LINGUA v3.2 CLEAR] Deleted $deleted bad translations");

        // Clear page cache if exists
        if (class_exists('Lingua_Output_Buffer')) {
            $output_buffer = new Lingua_Output_Buffer();
            $output_buffer->clear_cache_on_post_update($post_id);
            lingua_debug_log("[LINGUA v3.2 CLEAR] Cleared cache for post $post_id");
        }

        wp_send_json_success(array(
            'message' => "Cleared $deleted bad translations",
            'deleted' => $deleted,
            'post_id' => $post_id
        ));
    }

    /**
     * v5.0.12: Clear page cache via AJAX
     * Called after saving translations to refresh cached HTML
     */
    public function ajax_clear_page_cache() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_translating_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';

        if (empty($url)) {
            wp_send_json_error('No URL provided');
            return;
        }

        // Clear Lingua transient caches for this URL
        $url_path = parse_url($url, PHP_URL_PATH);
        $user_status = is_user_logged_in() ? 'logged_in' : 'guest';

        // Clear original HTML cache
        $original_cache_key = 'lingua_original_html_' . md5($url_path . '_' . $user_status);
        delete_transient($original_cache_key);

        // Clear translated cache for all languages
        $languages = get_option('lingua_languages', array());
        $cleared = 1;

        foreach ($languages as $lang_code => $lang_data) {
            $cache_key = md5($url_path . '_' . $user_status . '_' . $lang_code);
            if (delete_transient('lingua_cache_' . $cache_key)) {
                $cleared++;
            }
        }

        lingua_debug_log("[Lingua v5.0.12] Cleared $cleared cache entries for URL: $url_path");

        wp_send_json_success(array(
            'message' => "Cleared cache for $url_path",
            'cleared' => $cleared,
            'url' => $url_path
        ));
    }

    /**
     * AJAX: Delete all translations for specific post/page
     * v5.0.13: Clear all translations for debugging
     */
    public function ajax_delete_page_translations() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_translating_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';

        if (empty($post_id) && empty($term_id)) {
            wp_send_json_error('No post_id or term_id provided');
            return;
        }

        if (empty($language)) {
            wp_send_json_error('No language provided');
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';

        if ($post_id > 0) {
            // Delete all translations for this post in specified language
            // Context pattern: "post_{$post_id}" or "*.post_{$post_id}"
            $context_pattern = "%post_{$post_id}%";
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE context LIKE %s AND language_code = %s",
                $context_pattern,
                $language
            ));
        } else {
            // Delete all translations for this term in specified language
            // Context pattern: "taxonomy_*_{$term_id}" or "*.taxonomy_*_{$term_id}"
            $context_pattern = "%_{$term_id}%";
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE context LIKE %s AND language_code = %s",
                $context_pattern,
                $language
            ));
        }

        lingua_debug_log("[Lingua v5.0.13] Deleted $deleted translations for " .
                  ($post_id > 0 ? "post_id=$post_id (context like %post_{$post_id}%)" : "term_id=$term_id (context like %_{$term_id}%)") .
                  ", language=$language");

        wp_send_json_success(array(
            'message' => "Deleted $deleted translations",
            'deleted' => $deleted,
            'post_id' => $post_id,
            'term_id' => $term_id,
            'language' => $language
        ));
    }

    /**
     * v5.2: AJAX handler for testing Middleware API connection
     * v5.2.157: Delegates to test_connection() which handles caching via update_option()
     */
    public function ajax_test_middleware_connection() {
        check_ajax_referer('lingua_test_connection', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'yourtranslater'));
            return;
        }

        $middleware_api = new Lingua_Middleware_API();
        $result = $middleware_api->test_connection();

        if (is_wp_error($result)) {
            // v5.2.157: test_connection() already deleted cache via delete_option() on error
            // Just return error message to user
            wp_send_json_error($result->get_error_message());
        } else {
            // v5.2.157: test_connection() already saved status via update_option() on success
            // No need to overwrite with set_transient() - this was causing conflicts
            wp_send_json_success(__('Connection successful! API is working correctly.', 'yourtranslater'));
        }
    }

    /**
     * AJAX handler for saving string translation from strings page
     * v5.2.187
     */
    public function ajax_save_string_translation() {
        if (!wp_verify_nonce($_POST['nonce'], 'lingua_admin_nonce')) {
            wp_send_json_error(__('Security check failed', 'yourtranslater'));
            return;
        }

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error(__('Unauthorized', 'yourtranslater'));
            return;
        }

        $string_id = isset($_POST['string_id']) ? intval($_POST['string_id']) : 0;
        $translation = isset($_POST['translation']) ? wp_kses_post($_POST['translation']) : '';

        if (!$string_id) {
            wp_send_json_error(__('Invalid string ID', 'yourtranslater'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';

        $result = $wpdb->update(
            $table,
            array(
                'translated_text' => $translation,
                'status' => empty($translation) ? 0 : 2, // 0 = NOT_TRANSLATED, 2 = HUMAN_REVIEWED
                'updated_at' => current_time('mysql')
            ),
            array('id' => $string_id),
            array('%s', '%d', '%s'),
            array('%d')
        );

        if ($result !== false) {
            wp_send_json_success(__('Translation saved', 'yourtranslater'));
        } else {
            wp_send_json_error(__('Failed to save translation', 'yourtranslater'));
        }
    }

    /**
     * AJAX handler for getting plural forms for a string
     * v5.2.191: Simplified - find all forms by original_text + language_code
     */
    public function ajax_get_plural_forms() {
        if (!wp_verify_nonce($_POST['nonce'], 'lingua_admin_nonce')) {
            wp_send_json_error(__('Security check failed', 'yourtranslater'));
            return;
        }

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error(__('Unauthorized', 'yourtranslater'));
            return;
        }

        $string_id = isset($_POST['string_id']) ? intval($_POST['string_id']) : 0;
        $language = isset($_POST['language']) ? sanitize_text_field($_POST['language']) : '';
        $original = isset($_POST['original']) ? wp_unslash($_POST['original']) : '';

        if (!$language || empty($original)) {
            wp_send_json_error(__('Missing parameters', 'yourtranslater'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';

        // v5.2.191: Simple strategy - find all forms by original_text + language_code
        // All plural forms now have the same original_text (msgid/singular)
        $forms = $wpdb->get_results($wpdb->prepare(
            "SELECT id, original_text, translated_text, plural_form, original_plural
             FROM $table
             WHERE original_text = %s
             AND language_code = %s
             AND plural_form IS NOT NULL
             ORDER BY plural_form ASC",
            $original,
            $language
        ));

        // Fallback: Try to find by string_id if no forms found
        if (empty($forms) && $string_id) {
            // Get the original string first
            $base_string = $wpdb->get_row($wpdb->prepare(
                "SELECT original_text, original_plural FROM $table WHERE id = %d",
                $string_id
            ));

            if ($base_string && !empty($base_string->original_text)) {
                $forms = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, original_text, translated_text, plural_form, original_plural
                     FROM $table
                     WHERE original_text = %s
                     AND language_code = %s
                     AND plural_form IS NOT NULL
                     ORDER BY plural_form ASC",
                    $base_string->original_text,
                    $language
                ));
            }
        }

        if (empty($forms)) {
            // No plural forms found - this might be old data format
            // Try legacy format with context.plural
            $context = isset($_POST['context']) ? sanitize_text_field($_POST['context']) : '';
            $context_base = preg_replace('/\.plural$/', '', $context);

            // Find singular (base context)
            $singular = $wpdb->get_row($wpdb->prepare(
                "SELECT id, original_text, translated_text FROM $table
                 WHERE language_code = %s AND context = %s",
                $language, $context_base
            ));

            // Find plural (context.plural)
            $plural = $wpdb->get_row($wpdb->prepare(
                "SELECT id, original_text, translated_text FROM $table
                 WHERE language_code = %s AND context = %s",
                $language, $context_base . '.plural'
            ));

            if ($singular || $plural) {
                $forms = array();
                if ($singular) {
                    $forms[] = (object) array(
                        'id' => $singular->id,
                        'original_text' => $singular->original_text,
                        'translated_text' => $singular->translated_text,
                        'plural_form' => 0
                    );
                }
                if ($plural) {
                    $forms[] = (object) array(
                        'id' => $plural->id,
                        'original_text' => $plural->original_text,
                        'translated_text' => $plural->translated_text,
                        'plural_form' => 1
                    );
                }
            }
        }

        if (empty($forms)) {
            wp_send_json_error(__('No plural forms found', 'yourtranslater'));
            return;
        }

        $result = array();
        foreach ($forms as $form) {
            $result[] = array(
                'id' => $form->id,
                'original' => $form->original_text,
                'translation' => $form->translated_text,
                'form_index' => $form->plural_form
            );
        }

        wp_send_json_success(array('forms' => $result));
    }

    /**
     * AJAX handler for saving plural form translations
     * v5.2.188
     */
    public function ajax_save_plural_translations() {
        if (!wp_verify_nonce($_POST['nonce'], 'lingua_admin_nonce')) {
            wp_send_json_error(__('Security check failed', 'yourtranslater'));
            return;
        }

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error(__('Unauthorized', 'yourtranslater'));
            return;
        }

        $forms_json = isset($_POST['forms']) ? stripslashes($_POST['forms']) : '[]';
        $forms = json_decode($forms_json, true);

        if (empty($forms) || !is_array($forms)) {
            wp_send_json_error(__('Invalid forms data', 'yourtranslater'));
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';
        $saved = 0;

        foreach ($forms as $form) {
            if (empty($form['id'])) {
                continue;
            }

            $translation = isset($form['translation']) ? wp_kses_post($form['translation']) : '';

            $result = $wpdb->update(
                $table,
                array(
                    'translated_text' => $translation,
                    'status' => empty($translation) ? 0 : 2,
                    'updated_at' => current_time('mysql')
                ),
                array('id' => intval($form['id'])),
                array('%s', '%d', '%s'),
                array('%d')
            );

            if ($result !== false) {
                $saved++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf(__('Saved %d translations', 'yourtranslater'), $saved),
            'saved' => $saved
        ));
    }

}