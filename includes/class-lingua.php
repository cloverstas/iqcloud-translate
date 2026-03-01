<?php
/**
 * Main plugin class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua {

    protected $version;
    protected $url_rewriter;
    protected $translation_manager;
    protected $seo_integration;
    protected $translation_render;
    protected $content_filter;
    protected $public; // v5.2.151: Store public instance to prevent garbage collection
    
    public function __construct() {
        $this->version = LINGUA_VERSION;

        // v5.0.14: DEBUG - confirm Lingua class instantiated
        $url = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
        lingua_debug_log("[Lingua v5.0.14] 🏗️ Lingua::__construct() called for URL: {$url}");

        // CRITICAL v4.0: Set current language IMMEDIATELY        // This must happen BEFORE any menu hooks are registered!
        $this->detect_and_set_current_language();
    }

    /**
     * Detect and set current language globally before hooks are registered
     *
     * This is the SINGLE point where $LINGUA_LANGUAGE is set.
     * Called in __construct() to ensure it happens before ANY WordPress hooks.
     */
    private function detect_and_set_current_language() {
        // Load minimal dependencies for language detection
        require_once LINGUA_PLUGIN_DIR . 'includes/class-url-rewriter.php';

        // Create URL rewriter instance
        $url_rewriter = new Lingua_URL_Rewriter();

        // Get current language from URL
        $current_language = $url_rewriter->get_current_language();

        // Set global language variable
        global $LINGUA_LANGUAGE;
        $LINGUA_LANGUAGE = $current_language;

        lingua_debug_log('[LINGUA v4.0 CONSTRUCTOR] Set $LINGUA_LANGUAGE = ' . $LINGUA_LANGUAGE);

        // Also set constant for backward compatibility
        if (!defined('LINGUA_CURRENT_LANG')) {
            define('LINGUA_CURRENT_LANG', $LINGUA_LANGUAGE);
        }

        // v5.0.1: Send HTTP headers to prevent cross-language caching
        add_action('send_headers', array($this, 'send_language_headers'));

        // v5.0.2: Change WordPress locale to match current language (for WooCommerce, themes, etc.)
        add_filter('locale', array($this, 'change_locale_for_language'));

        // v5.3.19: Translate site name and description for non-default languages
        add_filter('option_blogname', array($this, 'translate_site_option'));
        add_filter('option_blogdescription', array($this, 'translate_site_option'));

        // v5.2.134: Add RTL support for Arabic, Hebrew, Persian, etc.
        add_filter('language_attributes', array($this, 'add_rtl_direction'), 20);
    }

    /**
     * Add dir="rtl" attribute to HTML element for RTL languages
     * v5.2.134: Supports Arabic, Hebrew, Persian, Kurdish, Pashto, Urdu, Sindhi
     *
     * @param string $output Language attributes string
     * @return string Modified attributes with dir="rtl" if needed
     */
    public function add_rtl_direction($output) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE)) {
            return $output;
        }

        // Check if current language is RTL using centralized language class
        if (class_exists('Lingua_Languages') && Lingua_Languages::is_rtl($LINGUA_LANGUAGE)) {
            // Add dir="rtl" if not already present
            if (strpos($output, 'dir=') === false) {
                $output .= ' dir="rtl"';
            }
            lingua_debug_log("[Lingua v5.2.134] Added dir=\"rtl\" for language: {$LINGUA_LANGUAGE}");
        }

        return $output;
    }

    /**
     * v5.3.19: Translate site name and description for non-default languages
     * Filters: option_blogname, option_blogdescription
     *
     * @param string $value Original option value
     * @return string Translated value or original
     */
    public function translate_site_option($value) {
        global $LINGUA_LANGUAGE, $wpdb;

        // Skip if default language or no language set
        $default_language = get_option('lingua_default_language', 'ru');
        if (empty($LINGUA_LANGUAGE) || $LINGUA_LANGUAGE === $default_language) {
            return $value;
        }

        // Skip if empty value
        if (empty($value)) {
            return $value;
        }

        // Prevent recursion during option loading
        static $translating = false;
        if ($translating) {
            return $value;
        }
        $translating = true;

        // Look up translation in database (case-insensitive)
        $table_name = $wpdb->prefix . 'lingua_string_translations';
        $normalized_value = mb_strtolower(trim($value), 'UTF-8');

        $translated = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text FROM {$table_name}
             WHERE LOWER(original_text) = %s
             AND language_code = %s
             AND translated_text != ''
             LIMIT 1",
            $normalized_value,
            $LINGUA_LANGUAGE
        ));

        $translating = false;

        if (!empty($translated)) {
            lingua_debug_log("[Lingua v5.3.19] Translated site option: {$value} => {$translated}");
            return $translated;
        }

        return $value;
    }

    /**
     * Send HTTP headers to prevent caching issues between languages
     * v5.0.1: Critical for preventing menu cache pollution
     */
    public function send_language_headers() {
        if (!headers_sent()) {
            global $LINGUA_LANGUAGE;

            lingua_debug_log("[Lingua v5.0.14] 📤 send_language_headers called, LINGUA_LANGUAGE: {$LINGUA_LANGUAGE}");

            // Tell caches (browser, CDN, proxy) that content varies by current language
            header('Vary: Cookie', false);
            // Add language-specific cache key
            header('X-Lingua-Language: ' . $LINGUA_LANGUAGE);
        }
    }

    /**
     * Change WordPress locale to match current language
     * v5.0.2: Enables WooCommerce and theme translations to load correctly
     *
     * This allows WordPress to load the correct .mo files for plugins/themes
     * Changes WordPress locale based on detected language
     *
     * @param string $locale Current WordPress locale
     * @return string Modified locale
     */
    public function change_locale_for_language($locale) {
        // Don't change locale in admin
        if (is_admin() && !wp_doing_ajax()) {
            return $locale;
        }

        global $LINGUA_LANGUAGE;
        $default_language = get_option('lingua_default_language', 'ru');

        // Only change locale if we're NOT on default language
        if (!empty($LINGUA_LANGUAGE) && $LINGUA_LANGUAGE !== $default_language) {
            // Map language codes to WordPress locales
            $locale_map = array(
                'en' => 'en_US',
                'ru' => 'ru_RU',
                'fr' => 'fr_FR',
                'de' => 'de_DE',
                'es' => 'es_ES',
                'it' => 'it_IT',
                'pt' => 'pt_PT',
                'zh' => 'zh_CN',
                'ja' => 'ja',
                'ko' => 'ko_KR',
                'ar' => 'ar',
                'pl' => 'pl_PL',
                'nl' => 'nl_NL',
                'tr' => 'tr_TR',
            );

            // Convert language code to locale
            $new_locale = isset($locale_map[$LINGUA_LANGUAGE]) ? $locale_map[$LINGUA_LANGUAGE] : $LINGUA_LANGUAGE;

            lingua_debug_log("[Lingua v5.0.2] Changing locale from {$locale} to {$new_locale} (language: {$LINGUA_LANGUAGE})");

            return $new_locale;
        }

        return $locale;
    }

    public function run() {
        $this->load_dependencies();
        $this->set_locale();
        $this->init_components();
        $this->define_admin_hooks();
        $this->define_public_hooks();

        // v5.2: Hide admin bar in iframe preview mode
        $this->hide_admin_bar_in_preview();
    }

    /**
     * v5.2: Hide admin bar when iframe preview parameter is present
     */
    private function hide_admin_bar_in_preview() {
        if (isset($_GET['lingua_preview']) && $_GET['lingua_preview'] == '1') {
            add_filter('show_admin_bar', '__return_false');
        }
    }
    
    private function load_dependencies() {
        // Core classes
        require_once LINGUA_PLUGIN_DIR . 'includes/class-database.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-languages.php'; // v5.2.128: Centralized language list
        // v5.2.174: Yandex API disabled - all translation goes through Middleware
        // require_once LINGUA_PLUGIN_DIR . 'includes/class-yandex-api.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-middleware-api.php'; // v5.2: Middleware integration
        require_once LINGUA_PLUGIN_DIR . 'includes/class-url-rewriter.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-content-processor.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-translation-manager.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-seo-integration.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-media-replacer.php';
        // ОТКАТ: class-lingua-language-switcher.php УДАЛЕН - используем nav-menu-integration
        // require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-language-switcher.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-nav-menu-integration.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-translation-render.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-string-capture-settings.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-translation-queue.php'; // v5.2.179: Auto-translate queue
        
        // Admin classes (загружаем всегда для AJAX запросов)
        require_once LINGUA_PLUGIN_DIR . 'admin/class-lingua-admin.php';
        
        // Public classes
        require_once LINGUA_PLUGIN_DIR . 'public/class-lingua-public.php';
    }
    
    private function init_components() {
        // Initialize URL rewriter
        $this->url_rewriter = new Lingua_URL_Rewriter();
        $this->url_rewriter->init();
        
        // Store globally for access from admin
        global $lingua_url_rewriter;
        $lingua_url_rewriter = $this->url_rewriter;
        
        // Initialize translation manager
        $this->translation_manager = new Lingua_Translation_Manager();
        $this->translation_manager->apply_translation_filters();
        
        // Initialize SEO integration
        $this->seo_integration = new Lingua_SEO_Integration();
        $this->seo_integration->init();
        
        // Initialize language switcher - ОТКАТ К РАБОЧЕЙ ВЕРСИИ
        // new Lingua_Language_Switcher(); // ОТКЛЮЧЕНО - имеет git конфликты
        
        // Initialize translation render (DOM-based HTML processing)
        // v5.2.2: Re-enabled with proper gettext timing fix
        // Gettext filters now register on wp_loaded hook instead of constructor
        $this->translation_render = new Lingua_Translation_Render();

        // SAFE v2.0 INITIALIZATION: Check if v2.0 classes exist before initializing
        $this->init_v2_architecture_safe();

        // ВКЛЮЧАЕМ Nav Menu Integration - возвращаем рабочую версию
        lingua_debug_log("[Lingua] Creating Lingua_Nav_Menu_Integration instance...");
        $nav_menu = new Lingua_Nav_Menu_Integration();
        lingua_debug_log("[Lingua] Lingua_Nav_Menu_Integration created: " . (is_object($nav_menu) ? 'SUCCESS' : 'FAILED'));
        
        // Register widgets - TEMPORARILY DISABLED (file missing)
        // add_action('widgets_init', array($this, 'register_widgets'));
        
        // Register cron hooks for auto-translation
        add_action('lingua_auto_translate_post', array($this, 'handle_auto_translate_post'), 10, 2);

        // v5.2.179: Auto-translate queue initialization
        $this->init_translation_queue();
    }

    /**
     * Initialize translation queue system
     * v5.2.179: Auto-translate website feature
     */
    private function init_translation_queue() {
        // Create queue table if needed
        if (get_option('lingua_queue_table_version') !== '1.0.0') {
            Lingua_Translation_Queue::create_table();
            update_option('lingua_queue_table_version', '1.0.0');
        }

        // Hook into post publish to add to queue
        if (Lingua_Translation_Queue::is_auto_translate_enabled()) {
            add_action('publish_post', array($this, 'on_post_publish'), 10, 2);
            add_action('publish_page', array($this, 'on_post_publish'), 10, 2);

            // WooCommerce products
            add_action('publish_product', array($this, 'on_post_publish'), 10, 2);

            // v5.3.2: Queue processing moved to Lingua_Auto_Translator class
            // It uses full HTML extraction for complete page translation
            // See: includes/class-lingua-auto-translator.php::process_queue()

            // Schedule queue processing if not already scheduled
            if (!wp_next_scheduled('lingua_process_translation_queue') && !Lingua_Translation_Queue::is_paused()) {
                wp_schedule_event(time(), 'every_minute', 'lingua_process_translation_queue');
            }
        } else {
            // Clear scheduled event if disabled
            wp_clear_scheduled_hook('lingua_process_translation_queue');
        }
    }

    /**
     * Add published post to translation queue
     * v5.2.179: Auto-translate website feature
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function on_post_publish($post_id, $post) {
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        // Skip if auto-translate is disabled
        if (!Lingua_Translation_Queue::is_auto_translate_enabled()) {
            return;
        }

        // Check Pro status
        $api = new Lingua_Middleware_API();
        if (!$api->is_pro_active()) {
            return;
        }

        // Add to queue with high priority (new posts should be processed first)
        $added = Lingua_Translation_Queue::add_post($post_id, 5);

        if ($added > 0) {
            lingua_debug_log("[Lingua v5.2.179] Added post #{$post_id} to translation queue for {$added} languages");
        }
    }

    /**
     * Process queue batch via WP-Cron
     * v5.2.179: Background processing
     */
    public function process_queue_batch() {
        // Skip if paused
        if (Lingua_Translation_Queue::is_paused()) {
            return;
        }

        // Check Pro status
        $api = new Lingua_Middleware_API();
        if (!$api->is_pro_active()) {
            return;
        }

        // Process up to 5 items per batch
        $processed = 0;
        $max_items = 5;

        while ($processed < $max_items) {
            $item = Lingua_Translation_Queue::get_next();

            if (!$item) {
                break; // No more items
            }

            $this->process_queue_item($item);
            $processed++;
        }

        if ($processed > 0) {
            lingua_debug_log("[Lingua v5.2.179] Processed {$processed} queue items");
        }
    }

    /**
     * Process single queue item
     *
     * @param object $item Queue item
     */
    /**
     * Process a queue item and return result
     * v5.3.12: Uses Lingua_Auto_Translator for complete page translation
     * v5.3.37: Returns array with success status and error message for proper JS handling
     */
    private function process_queue_item($item) {
        // Mark as processing
        Lingua_Translation_Queue::mark_processing($item->id);

        try {
            // v5.3.12: Use Lingua_Auto_Translator for full HTML extraction
            // This extracts ALL strings from rendered page, not just 3 fields
            $translator = Lingua_Auto_Translator::get_instance();
            $result = $translator->translate_post($item->post_id, $item->language_code);

            if (is_wp_error($result)) {
                $error_message = $result->get_error_message();
                Lingua_Translation_Queue::mark_failed($item->id, $error_message);
                lingua_debug_log("[Lingua v5.3.12] Failed to translate post #{$item->post_id}: " . $error_message);
                // v5.3.37: Return error info for JavaScript
                return array('success' => false, 'error' => $error_message);
            } else {
                Lingua_Translation_Queue::mark_completed($item->id, $result['total'], $result['translated']);
                lingua_debug_log("[Lingua v5.3.12] Translated post #{$item->post_id} to {$item->language_code}: {$result['translated']}/{$result['total']} strings");
                return array('success' => true, 'total' => $result['total'], 'translated' => $result['translated']);
            }

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            Lingua_Translation_Queue::mark_failed($item->id, $error_message);
            lingua_debug_log("[Lingua v5.3.12] Exception translating post #{$item->post_id}: " . $error_message);
            // v5.3.37: Return error info for JavaScript
            return array('success' => false, 'error' => $error_message);
        }
    }
    
    private function set_locale() {
        // Translations are loaded automatically by WordPress.org since WP 4.6
        // No manual load_plugin_textdomain() call needed
    }
    
    private function define_admin_hooks() {
        // Создаем админ экземпляр всегда (для AJAX запросов)
        $admin = new Lingua_Admin($this->version);
        
        // AJAX handlers (должны быть доступны всегда)
        add_action('wp_ajax_lingua_save_api_settings', array($admin, 'ajax_save_api_settings'));
        add_action('wp_ajax_lingua_test_api', array($admin, 'ajax_test_api'));
        add_action('wp_ajax_lingua_check_pro_status', array($admin, 'ajax_check_pro_status'));
        add_action('wp_ajax_lingua_disconnect_license', array($admin, 'ajax_disconnect_license'));
        add_action('wp_ajax_lingua_translate_content', array($admin, 'ajax_translate_content'));

        // v5.2.77: DEBUG - Log all AJAX requests to lingua actions
        add_action('admin_init', function() {
            if (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action'])) {
                if (strpos($_POST['action'], 'lingua') !== false) {
                    lingua_debug_log("🔥 LINGUA AJAX REQUEST: action={$_POST['action']}");
                }
            }
        });

        // v5.2.77: CRITICAL DEBUG - Wrap save handler with logging
        add_action('wp_ajax_lingua_save_translation', function() use ($admin) {
            lingua_debug_log("🔥🔥🔥 LINGUA v5.2.77: wp_ajax_lingua_save_translation ACTION TRIGGERED!");
            lingua_debug_log("🔥 POST data: " . print_r($_POST, true));
            call_user_func(array($admin, 'ajax_save_translation'));
        });

        add_action('wp_ajax_lingua_get_modal_template', array($admin, 'ajax_get_modal_template'));
        add_action('wp_ajax_lingua_auto_translate_text', array($admin, 'ajax_auto_translate_text'));
        add_action('wp_ajax_lingua_auto_translate_batch', array($admin, 'ajax_auto_translate_batch')); // v5.2.199

        // v3.2: Clear bad WooCommerce translations
        add_action('wp_ajax_lingua_clear_bad_translations', array($admin, 'ajax_clear_bad_translations'));

        // v5.0.12: Clear page cache after save
        add_action('wp_ajax_lingua_clear_page_cache', array($admin, 'ajax_clear_page_cache'));

        // v5.0.13: Delete page translations (for debugging)
        add_action('wp_ajax_lingua_delete_page_translations', array($admin, 'ajax_delete_page_translations'));

        // v5.2: Middleware API test connection
        add_action('wp_ajax_lingua_test_middleware_connection', array($admin, 'ajax_test_middleware_connection'));

        // v5.2.187: Save string translation from strings page
        add_action('wp_ajax_lingua_save_string_translation', array($admin, 'ajax_save_string_translation'));

        // v5.2.188: Plural forms AJAX handlers
        add_action('wp_ajax_lingua_get_plural_forms', array($admin, 'ajax_get_plural_forms'));
        add_action('wp_ajax_lingua_save_plural_translations', array($admin, 'ajax_save_plural_translations'));

        // v5.2.179: Translation queue AJAX endpoints
        add_action('wp_ajax_lingua_get_queue_status', array($this, 'ajax_get_queue_status'));
        add_action('wp_ajax_lingua_translate_all', array($this, 'ajax_translate_all'));
        add_action('wp_ajax_lingua_pause_queue', array($this, 'ajax_pause_queue'));
        add_action('wp_ajax_lingua_resume_queue', array($this, 'ajax_resume_queue'));
        add_action('wp_ajax_lingua_retry_failed', array($this, 'ajax_retry_failed'));
        add_action('wp_ajax_lingua_process_queue_item', array($this, 'ajax_process_queue_item'));

        // v5.2: Middleware verification endpoint (public REST API)
        add_action('rest_api_init', array($this, 'register_middleware_verification_endpoint'));

        // ОТКАТ: Menu AJAX handler удален - Nav_Menu_Integration сам обрабатывает меню
        // add_action('wp_ajax_add-menu-item', array($this, 'ajax_add_menu_item'), 5);
        
        // REMOVED: AJAX handlers для неавторизованных пользователей - потенциальная угроза безопасности
        // Translation functions should only be available to authorized users
        // add_action('wp_ajax_nopriv_lingua_translate_content', array($admin, 'ajax_translate_content'));
        // add_action('wp_ajax_nopriv_lingua_get_modal_template', array($admin, 'ajax_get_modal_template'));
        // add_action('wp_ajax_nopriv_lingua_auto_translate_text', array($admin, 'ajax_auto_translate_text'));
        
        // Админ-специфичные хуки только в админке
        if (is_admin()) {
            lingua_debug_log('[Lingua] Registering admin hooks...');
            // Menu and pages
            add_action('admin_menu', array($admin, 'add_admin_menu'));
            add_action('admin_init', array($admin, 'handle_settings_save'));
            lingua_debug_log('[Lingua] Registered admin_init hook for handle_settings_save');

            // Scripts and styles - admin area
            add_action('admin_enqueue_scripts', array($admin, 'enqueue_styles'));
            add_action('admin_enqueue_scripts', array($admin, 'enqueue_scripts'));

            // Post actions
            add_action('save_post', array($admin, 'maybe_auto_translate'), 10, 3);
        }

        // v5.2.44: Load admin scripts on frontend for users with translation capability
        // This ensures lingua_admin object exists on frontend pages like /de
        if (!is_admin() && current_user_can(lingua_translating_capability())) {
            lingua_debug_log('[Lingua v5.2.44] Loading admin scripts on frontend for translation capability');
            add_action('wp_enqueue_scripts', array($admin, 'enqueue_styles'));
            add_action('wp_enqueue_scripts', array($admin, 'enqueue_scripts'));
        }

        // Store admin instance globally
        global $lingua_admin;
        $lingua_admin = $admin;
        
        // Admin bar hooks нужно регистрировать раньше
        add_action('wp_loaded', array($this, 'init_admin_bar_hooks'));

        // v5.3.4: Gettext debug - показывает все gettext строки в консоли браузера
        // Использование: добавьте ?lingua_debug_gettext=1 к URL
        if (isset($_GET['lingua_debug_gettext']) && $_GET['lingua_debug_gettext'] === '1') {
            $this->init_gettext_debug();
        }
    }

    /**
     * v5.3.4: Initialize gettext debugging
     * Collects all gettext strings and outputs them to browser console
     */
    private function init_gettext_debug() {
        global $lingua_gettext_strings;
        $lingua_gettext_strings = array();

        // Hook into gettext filter to collect strings
        add_filter('gettext', function($translation, $text, $domain) {
            global $lingua_gettext_strings;
            $key = md5($text . $domain);
            if (!isset($lingua_gettext_strings[$key])) {
                $lingua_gettext_strings[$key] = array(
                    'original' => $text,
                    'translated' => $translation,
                    'domain' => $domain,
                    'has_translation' => ($translation !== $text)
                );
            }
            return $translation;
        }, 10, 3);

        // Output collected strings in footer
        add_action('wp_footer', array($this, 'output_gettext_debug'), 9999);
        add_action('admin_footer', array($this, 'output_gettext_debug'), 9999);
    }

    /**
     * v5.3.4: Output gettext debug info to browser console
     */
    public function output_gettext_debug() {
        global $lingua_gettext_strings;

        if (empty($lingua_gettext_strings)) {
            return;
        }

        // Group by domain
        $by_domain = array();
        foreach ($lingua_gettext_strings as $item) {
            $domain = $item['domain'] ?: 'default';
            if (!isset($by_domain[$domain])) {
                $by_domain[$domain] = array('translated' => 0, 'untranslated' => 0, 'strings' => array());
            }
            $by_domain[$domain]['strings'][] = $item;
            if ($item['has_translation']) {
                $by_domain[$domain]['translated']++;
            } else {
                $by_domain[$domain]['untranslated']++;
            }
        }

        // v5.5: Use wp_print_inline_script_tag() instead of raw <script> tag (WP review compliance)
        // This runs at wp_footer/admin_footer time when wp_add_inline_script() is not available
        $debug_js = "console.group('Lingua Gettext Debug');\n"
            . "console.log('Total strings: " . count($lingua_gettext_strings) . "');\n";

        foreach ($by_domain as $domain => $data) {
            $debug_js .= "console.groupCollapsed('" . esc_js($domain) . " (" . $data['translated'] . " translated, " . $data['untranslated'] . " untranslated)');\n";
            $debug_js .= "console.table(" . wp_json_encode(array_values($data['strings'])) . ");\n";
            $debug_js .= "console.groupEnd();\n";
        }

        $debug_js .= "console.groupEnd();";

        if (function_exists('wp_print_inline_script_tag')) {
            wp_print_inline_script_tag($debug_js);
        } else {
            // Fallback for WP < 5.7
            echo '<script>' . $debug_js . '</script>';
        }
    }

    public function init_admin_bar_hooks() {
        // Просто регистрируем хуки без создания экземпляра
        add_action('admin_bar_menu', array($this, 'add_translate_button_to_bar'), 100);
        // v5.5: Changed from footer hooks to enqueue hooks for wp_add_inline_script() compatibility (WP review)
        add_action('admin_enqueue_scripts', array($this, 'add_translate_button_script_to_head'), 20);
        add_action('wp_enqueue_scripts', array($this, 'add_translate_button_script_to_head'), 20);
    }
    
    public function add_translate_button_to_bar($wp_admin_bar) {
        // Проверяем права пользователя
        if (!current_user_can('edit_posts')) {
            return;
        }
        
        // Не показываем кнопку в админке
        if (is_admin()) {
            return;
        }
        
        // Проверяем наличие активных языков
        $languages = get_option('lingua_languages', array());
        if (empty($languages)) {
            return;
        }
        
        // Получаем post ID
        global $post;
        $post_id = 0;
        
        // Try to get the correct post ID based on context
        if (is_front_page() || is_home()) {
            // Get front page ID if set
            $post_id = get_option('page_on_front');
            if (!$post_id) {
                // Get blog page ID if it's the blog home
                $post_id = get_option('page_for_posts');
            }
        } elseif ($post) {
            $post_id = $post->ID;
        } elseif (is_singular()) {
            $post_id = get_queried_object_id();
        }
        
        // Добавляем кнопку (data-атрибуты добавим через JavaScript)
        $wp_admin_bar->add_node(array(
            'id'    => 'lingua-translate-page',
            'title' => '<span class="ab-icon dashicons-translation"></span>' . __('Translate Page', 'iqcloud-translate'),
            'href'  => '#lingua-translate-' . $post_id, // Добавляем post ID в href как альтернативу
            'meta'  => array(
                'class' => 'lingua-admin-bar-translate-btn',
                'onclick' => 'return false;',
                'title' => __('Open translation manager for this page', 'iqcloud-translate')
            )
        ));
    }
    
    public function add_translate_button_script_to_head() {
        if (!current_user_can('edit_posts')) {
            return;
        }

        global $post;
        $post_id = 0;
        $page_type = 'post'; // Default: regular post/page
        $term_id = 0;
        $taxonomy = '';

        // v3.8: Enhanced context detection with taxonomy/archive support
        if (is_front_page() || is_home()) {
            // Get front page ID if set
            $post_id = get_option('page_on_front');
            if (!$post_id) {
                // Get blog page ID if it's the blog home
                $post_id = get_option('page_for_posts');
            }
            $page_type = 'front_page';
        } elseif (is_tax() || is_category() || is_tag()) {
            // v3.8: Taxonomy archive (category, tag, custom taxonomy)
            $queried_object = get_queried_object();
            if ($queried_object && isset($queried_object->term_id)) {
                $term_id = $queried_object->term_id;
                $taxonomy = $queried_object->taxonomy;
                $page_type = 'taxonomy';
                $post_id = 0; // Archives don't have post_id
            }
        } elseif (is_post_type_archive()) {
            // v3.8: Post type archive
            $page_type = 'post_type_archive';
            $post_id = 0;
        } elseif ($post) {
            $post_id = $post->ID;
            $page_type = 'post';
        } elseif (is_singular()) {
            $post_id = get_queried_object_id();
            $page_type = 'post';
        }

        // v5.5: Use wp_add_inline_script() instead of inline <script> tag (WP review compliance)
        $post_id_js = intval($post_id);
        $page_type_js = esc_js($page_type);
        $term_id_js = intval($term_id);
        $taxonomy_js = esc_js($taxonomy);

        $inline_js = "jQuery(document).ready(function($) {\n"
            . "function setButtonAttributes() {\n"
            . "    var \$button = \$('#wp-admin-bar-lingua-translate-page a.ab-item');\n"
            . "    if (\$button.length) {\n"
            . "        \$button.attr('data-post-id', '{$post_id_js}');\n"
            . "        \$button.attr('data-page-type', '{$page_type_js}');\n"
            . "        \$button.attr('data-term-id', '{$term_id_js}');\n"
            . "        \$button.attr('data-taxonomy', '{$taxonomy_js}');\n"
            . "        console.log('Lingua v5.0.6: Button context set successfully!');\n"
            . "        console.log('  - Post ID: {$post_id_js}');\n"
            . "        console.log('  - Page Type: {$page_type_js}');\n"
            . "        console.log('  - Term ID: {$term_id_js}');\n"
            . "        console.log('  - Taxonomy: {$taxonomy_js}');\n"
            . "        return true;\n"
            . "    }\n"
            . "    return false;\n"
            . "}\n"
            . "if (setButtonAttributes()) { return; }\n"
            . "setTimeout(function() {\n"
            . "    if (setButtonAttributes()) { return; }\n"
            . "    var observer = new MutationObserver(function(mutations) {\n"
            . "        if (setButtonAttributes()) { observer.disconnect(); }\n"
            . "    });\n"
            . "    observer.observe(document.body, { childList: true, subtree: true });\n"
            . "    setTimeout(function() {\n"
            . "        observer.disconnect();\n"
            . "        if (!\$('#wp-admin-bar-lingua-translate-page a.ab-item').attr('data-page-type')) {\n"
            . "            console.warn('Lingua v5.0.6: Button attributes not set after 5s');\n"
            . "        }\n"
            . "    }, 5000);\n"
            . "}, 100);\n"
            . "});";

        wp_add_inline_script('lingua-admin', $inline_js, 'after');
    }
    
    private function define_public_hooks() {
        // v5.2.151: Save as class property to prevent garbage collection
        $this->public = new Lingua_Public($this->version);

        // PHASE 2.1: Initialize Output Buffer EARLY        // v5.0.11 FIX: Use template_redirect instead of init (is_admin() is unreliable on init)
        // template_redirect fires only on frontend, never in admin
        add_action('template_redirect', function() {
            if (class_exists('Lingua_Output_Buffer')) {
                $output_buffer = new Lingua_Output_Buffer();
                $output_buffer->start_output_buffering();
            }
        }, -9999);

        // PHASE 2.2: Cache invalidation hooks
        add_action('save_post', array($this, 'clear_page_cache'), 10, 1);
        add_action('wp_update_nav_menu', array($this, 'clear_menu_cache'));
        add_action('edited_term', array($this, 'clear_all_cache'));
        add_action('delete_term', array($this, 'clear_all_cache'));

        // Scripts and styles
        // v5.2.151: Use $this->public instead of local $public to prevent garbage collection
        // v5.2.153: Add debug logs to verify hook registration
        // v5.2.154: TEST - Add anonymous function to verify wp_enqueue_scripts fires at all
        lingua_debug_log('[LINGUA v5.2.154] About to register test hook and real hooks');

        add_action('wp_enqueue_scripts', function() {
            lingua_debug_log('[LINGUA v5.2.154 TEST] ✅ wp_enqueue_scripts ACTION FIRED! is_admin=' . (is_admin() ? 'true' : 'false'));
        }, 1);

        lingua_debug_log('[LINGUA v5.2.154] Registering wp_enqueue_scripts hooks. $this->public is ' . (is_object($this->public) ? 'OBJECT' : 'NULL'));
        add_action('wp_enqueue_scripts', array($this->public, 'enqueue_styles'));
        add_action('wp_enqueue_scripts', array($this->public, 'enqueue_scripts'));
        lingua_debug_log('[LINGUA v5.2.154] wp_enqueue_scripts hooks registered successfully');

        // AJAX handlers for frontend
        // v5.2.80: Wrap with logging to debug
        // v5.2.151: Changed to use $this->public
        $public = $this->public; // For backwards compatibility with closures
        add_action('wp_ajax_lingua_get_translatable_content', function() use ($public) {
            lingua_debug_log('🔥🔥🔥 LINGUA v5.2.80: wp_ajax_lingua_get_translatable_content HOOK TRIGGERED!');
            lingua_debug_log('🔥 Calling $public->ajax_get_translatable_content()...');
            call_user_func(array($public, 'ajax_get_translatable_content'));
        });
        add_action('wp_ajax_nopriv_lingua_get_translatable_content', array($public, 'ajax_get_translatable_content'));
        add_action('wp_ajax_lingua_translate_frontend', array($public, 'ajax_translate_frontend'));
        add_action('wp_ajax_nopriv_lingua_translate_frontend', array($public, 'ajax_translate_frontend'));

        // v5.0.12: AJAX handlers for dynamic content translation
        add_action('wp_ajax_lingua_get_dynamic_translations', array($public, 'ajax_get_dynamic_translations'));
        add_action('wp_ajax_nopriv_lingua_get_dynamic_translations', array($public, 'ajax_get_dynamic_translations'));
        add_action('wp_ajax_lingua_translate_ajax_content', array($public, 'ajax_translate_ajax_content'));
        add_action('wp_ajax_nopriv_lingua_translate_ajax_content', array($public, 'ajax_translate_ajax_content'));

        // v5.4.1: AJAX handler for refreshing nonce (fixes stale nonce on cached pages)
        add_action('wp_ajax_lingua_refresh_nonce', function() {
            wp_send_json_success(array('nonce' => wp_create_nonce('lingua_admin_nonce')));
        });

        // v5.2.7: AJAX handler for lazy loading Media Library scripts
        add_action('wp_ajax_lingua_load_media_library_scripts', array($public, 'ajax_load_media_library_scripts'));
    }

    /**
     * PHASE 2.2: Cache invalidation methods
     */
    public function clear_page_cache($post_id) {
        delete_transient('lingua_html_cache_' . $post_id);
        delete_transient('lingua_extracted_content_' . $post_id);

        // v5.2.164: Clear ALL translated HTML caches when post is updated
        // Cache keys are MD5 hashes, so we can't target specific post - must clear all
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_translated_html_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lingua_translated_html_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_original_html_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lingua_original_html_%'");

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log('[Lingua Cache v5.2.164] Cleared ALL translation caches for post ' . $post_id);
        }
    }

    public function clear_menu_cache() {
        // Clear all menu-related caches
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_html_cache_%'");

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log('[Lingua Cache] Cleared all menu caches');
        }
    }

    public function clear_all_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_%'");

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log('[Lingua Cache] Cleared ALL caches');
        }
    }
    
    
    /**
     * Get plugin components for external use
     */
    public function get_url_rewriter() {
        return $this->url_rewriter;
    }
    
    public function get_translation_manager() {
        return $this->translation_manager;
    }
    
    public function get_seo_integration() {
        return $this->seo_integration;
    }
    
    /**
     * Handle auto-translation cron job
     */
    public function handle_auto_translate_post($post_id, $target_lang) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return;
        }
        
        // Check if translation already exists
        $translation_manager = new Lingua_Translation_Manager();
        $existing = $translation_manager->get_translation($post_id, $target_lang);
        
        if ($existing) {
            // Translation already exists, skip
            return;
        }
        
        try {
            // Extract content
            $processor = new Lingua_Content_Processor();
            $translatable = $processor->extract_translatable_content($post->post_content);
            $meta_fields = $processor->extract_meta_fields($post_id);
            
            // v5.2.174: Use Middleware API instead of Yandex
            $api = new Lingua_Middleware_API();
            
            // Auto-translate title
            $translated_title = '';
            if (!empty($post->post_title)) {
                $title_result = $api->translate($post->post_title, $target_lang);
                if (!is_wp_error($title_result)) {
                    $translated_title = $title_result;
                }
            }
            
            // Auto-translate excerpt
            $translated_excerpt = '';
            if (!empty($post->post_excerpt)) {
                $excerpt_result = $api->translate($post->post_excerpt, $target_lang);
                if (!is_wp_error($excerpt_result)) {
                    $translated_excerpt = $excerpt_result;
                }
            }
            
            // Auto-translate content blocks
            $translated_content_blocks = array();
            foreach ($translatable as $block) {
                if (!empty($block['original'])) {
                    $block_result = $api->translate($block['original'], $target_lang);
                    if (!is_wp_error($block_result)) {
                        $translated_content_blocks[] = array(
                            'type' => $block['type'],
                            'original' => $block['original'],
                            'translated' => $block_result
                        );
                    }
                }
            }
            
            // Reconstruct translated content
            $translated_content = $processor->reconstruct_content($post->post_content, $translated_content_blocks);
            
            // Auto-translate meta fields if enabled
            $translated_meta = array();
            $auto_translate_seo = get_option('lingua_auto_translate_seo', false);
            
            if ($auto_translate_seo) {
                foreach ($meta_fields as $key => $value) {
                    if (!empty($value)) {
                        $meta_result = $api->translate($value, $target_lang);
                        if (!is_wp_error($meta_result)) {
                            $translated_meta[$key] = $meta_result;
                        }
                    }
                }
            }
            
            // Save auto-translation
            $translation_data = array(
                'title' => $translated_title,
                'content' => $translated_content,
                'excerpt' => $translated_excerpt,
                'status' => 'auto',
                'meta' => $translated_meta
            );
            
            $result = $translation_manager->save_translation($post_id, $target_lang, $translation_data);
            
            if ($result) {
                // Log successful auto-translation
                lingua_debug_log("Lingua: Auto-translated post {$post_id} to {$target_lang}");
            }

        } catch (Exception $e) {
            // Log error
            lingua_debug_log("Lingua: Auto-translation failed for post {$post_id} to {$target_lang}: " . $e->getMessage());
        }
    }
    
    /**
     * ОТКАТ: ajax_add_menu_item метод удален - Nav_Menu_Integration обрабатывает меню
     * Переключатель теперь добавляется через стандартную WordPress архитектуру
     */
    
    /**
     * SAFE v2.0 ARCHITECTURE INITIALIZATION
     * Prevents Fatal Errors if v2.0 classes are missing
     */
    private function init_v2_architecture_safe() {
        // Feature flag for v2.0 architecture
        $v2_enabled = get_option('lingua_enable_v2_pipeline', false);

        // Check if all v2.0 classes exist before initializing
        $v2_classes_available = (
            class_exists('Lingua_String_Capture') &&
            class_exists('Lingua_Cache_Manager') &&
            class_exists('Lingua_DOM_Parser') &&
            class_exists('Lingua_String_Filter_Engine')
        );

        // Check if v2.0 architecture components are available
        $dom_architecture_available = (
            class_exists('Lingua_Full_Dom_Extractor') &&
            class_exists('Lingua_Output_Buffer') &&
            class_exists('Lingua_Content_Filter')
        );

        if ($v2_enabled && $v2_classes_available) {
            // Initialize v2.0 String Capture system
            try {
                $string_capture = Lingua_String_Capture::get_instance();

                // v5.0.11: Output Buffer initialization moved to define_public_hooks() with template_redirect hook
                // (Old init hook code removed to prevent conflicts)

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log('Lingua: v2.0 components available, Output Buffer will start on template_redirect');
                }

                // Log successful v2.0 initialization
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log('Lingua: v2.0 architecture initialized successfully');
                }

                // Disable old pipeline to prevent conflicts
                add_filter('lingua_disable_old_pipeline', '__return_true');

            } catch (Exception $e) {
                // Log error but don't break the site
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log('Lingua: v2.0 initialization failed - ' . $e->getMessage());
                }

                // Fallback: disable v2.0 if it fails
                update_option('lingua_enable_v2_pipeline', false);
            }
        } elseif ($v2_enabled && !$v2_classes_available) {
            // v2.0 is enabled but classes are missing - disable it
            update_option('lingua_enable_v2_pipeline', false);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log('Lingua: v2.0 classes missing, disabling v2.0 pipeline');
            }
        }

        // If v2.0 is not available, keep old system working
        if (!$v2_enabled || !$v2_classes_available) {
            // Re-enable old Translation Render if v2.0 is not working
            if (!apply_filters('lingua_disable_old_pipeline', false)) {
                // Only initialize if String Capture is not running
                if (!class_exists('Lingua_String_Capture') ||
                    !Lingua_String_Capture::get_instance()->is_capture_enabled()) {

                    // Safe fallback to old system
                    new Lingua_Translation_Render();

                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        lingua_debug_log('Lingua: Fallback to old Translation Render system');
                    }
                }
            }
        }
    }

    /**
     * Register widgets - Missing method causing Fatal Error
     */
    public function register_widgets() {
        // Load language switcher widget
        require_once LINGUA_PLUGIN_DIR . 'includes/class-language-switcher-widget.php';
        register_widget('Lingua_Language_Switcher_Widget');

        lingua_debug_log('Lingua: Widgets registered successfully');
    }

    /**
     * Register Middleware verification endpoint (v5.2)
     * This endpoint allows Middleware API to verify WordPress site ownership
     */
    public function register_middleware_verification_endpoint() {
        register_rest_route('lingua/v1', '/middleware/verify', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_middleware_verification'),
            'permission_callback' => '__return_true', // Public endpoint
        ));
    }

    /**
     * Handle Middleware verification request (v5.2)
     * Verifies that the API key matches the configured key
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_middleware_verification($request) {
        $api_key = $request->get_header('X-API-Key');
        $challenge = $request->get_param('challenge');

        lingua_debug_log('[Lingua Middleware Verify] Received verification request');
        lingua_debug_log('[Lingua Middleware Verify] API Key: ' . substr($api_key, 0, 10) . '...');
        lingua_debug_log('[Lingua Middleware Verify] Challenge: ' . $challenge);

        // Get configured API key
        $configured_key = get_option('lingua_middleware_api_key', '');

        // Verify API key
        if (empty($api_key) || $api_key !== $configured_key) {
            lingua_debug_log('[Lingua Middleware Verify] API key mismatch or empty');
            return new WP_Error(
                'invalid_api_key',
                'Invalid API key',
                array('status' => 401)
            );
        }

        // Return verification response
        $response = array(
            'verified' => true,
            'site' => home_url(),
            'wordpress_version' => get_bloginfo('version'),
            'lingua_version' => defined('LINGUA_VERSION') ? LINGUA_VERSION : '5.0',
            'challenge_response' => !empty($challenge) ? hash('sha256', $challenge . $configured_key) : null
        );

        lingua_debug_log('[Lingua Middleware Verify] Verification successful');

        return rest_ensure_response($response);
    }

    // =========================================================================
    // v5.2.179: Translation Queue AJAX Handlers
    // =========================================================================

    /**
     * AJAX: Get queue status
     */
    public function ajax_get_queue_status() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $status = array(
            'enabled' => Lingua_Translation_Queue::is_auto_translate_enabled(),
            'paused' => Lingua_Translation_Queue::is_paused(),
            'stats' => Lingua_Translation_Queue::get_stats(),
            'by_language' => Lingua_Translation_Queue::get_language_status(),
            'recent_errors' => Lingua_Translation_Queue::get_recent_errors(5),
            'next_cron' => wp_next_scheduled('lingua_process_translation_queue')
        );

        wp_send_json_success($status);
    }

    /**
     * AJAX: Start translating all posts (populate queue)
     * v5.3.16: Added taxonomy support (product categories, tags)
     */
    public function ajax_translate_all() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        // Check Pro status
        $api = new Lingua_Middleware_API();
        if (!$api->is_pro_active()) {
            wp_send_json_error(__('Pro license required for auto-translation', 'iqcloud-translate'));
            return;
        }

        // v5.3.17: Get post types from settings (checkboxes in admin)
        $post_types = lingua_get_translatable_post_types();

        // Populate queue with all posts
        $added = Lingua_Translation_Queue::populate_all_posts($post_types);

        // v5.3.16: Also populate taxonomy terms (product categories, tags)
        // If 'product' is in post types, also include WooCommerce taxonomies
        if (in_array('product', $post_types)) {
            $taxonomies = array('product_cat', 'product_tag');
            $added += Lingua_Translation_Queue::populate_all_terms($taxonomies);
        }

        // Resume queue if paused
        Lingua_Translation_Queue::resume();

        // Reschedule cron if needed
        if (!wp_next_scheduled('lingua_process_translation_queue')) {
            wp_schedule_event(time(), 'every_minute', 'lingua_process_translation_queue');
        }

        wp_send_json_success(array(
            'added' => $added,
            'message' => sprintf(__('Added %d items to translation queue', 'iqcloud-translate'), $added)
        ));
    }

    /**
     * AJAX: Pause queue processing
     */
    public function ajax_pause_queue() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        Lingua_Translation_Queue::pause();
        wp_clear_scheduled_hook('lingua_process_translation_queue');

        wp_send_json_success(array(
            'paused' => true,
            'message' => __('Queue processing paused', 'iqcloud-translate')
        ));
    }

    /**
     * AJAX: Resume queue processing
     */
    public function ajax_resume_queue() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        Lingua_Translation_Queue::resume();

        // Reschedule cron
        if (!wp_next_scheduled('lingua_process_translation_queue')) {
            wp_schedule_event(time(), 'every_minute', 'lingua_process_translation_queue');
        }

        wp_send_json_success(array(
            'paused' => false,
            'message' => __('Queue processing resumed', 'iqcloud-translate')
        ));
    }

    /**
     * AJAX: Retry all failed items
     */
    public function ajax_retry_failed() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $count = Lingua_Translation_Queue::retry_failed();

        wp_send_json_success(array(
            'retried' => $count,
            'message' => sprintf(__('Reset %d failed items for retry', 'iqcloud-translate'), $count)
        ));
    }

    /**
     * AJAX: Process single queue item (for real-time progress)
     */
    public function ajax_process_queue_item() {
        check_ajax_referer('lingua_admin_nonce', 'nonce');

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        // Check Pro status
        $api = new Lingua_Middleware_API();
        if (!$api->is_pro_active()) {
            wp_send_json_error(__('Pro license required', 'iqcloud-translate'));
            return;
        }

        // Get next item
        $item = Lingua_Translation_Queue::get_next();

        if (!$item) {
            wp_send_json_success(array(
                'processed' => false,
                'message' => __('No items in queue', 'iqcloud-translate'),
                'stats' => Lingua_Translation_Queue::get_stats()
            ));
            return;
        }

        // Process item and get result
        // v5.3.37: Now returns array with success status and error message
        $process_result = $this->process_queue_item($item);

        // Get updated stats
        $stats = Lingua_Translation_Queue::get_stats();
        $by_language = Lingua_Translation_Queue::get_language_status();

        // v5.3.37: Return error to JavaScript if processing failed
        if (!$process_result['success']) {
            wp_send_json_error(array(
                'message' => $process_result['error'],
                'item' => array(
                    'post_id' => $item->post_id,
                    'language' => $item->language_code
                ),
                'stats' => $stats,
                'by_language' => $by_language,
                'has_more' => $stats['pending'] > 0
            ));
            return;
        }

        wp_send_json_success(array(
            'processed' => true,
            'item' => array(
                'post_id' => $item->post_id,
                'language' => $item->language_code
            ),
            'stats' => $stats,
            'by_language' => $by_language,
            'has_more' => $stats['pending'] > 0
        ));
    }
}