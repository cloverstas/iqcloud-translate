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

        // v1.0.6: Use cached default language to avoid recursion through get_locale()
        static $cached_default_lang = null;
        if ($cached_default_lang === null) {
            $cached_default_lang = get_option('lingua_default_language', 'ru');
        }
        $default_language = $cached_default_lang;
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
        // v1.0.6: CRITICAL FIX - Prevent infinite recursion!
        // This filter calls lingua_get_site_language() → get_locale() → triggers this filter again → ∞ loop
        // This was the ROOT CAUSE of memory exhaustion on all servers, not just weak ones.
        static $in_filter = false;
        if ($in_filter) {
            return $locale;
        }
        $in_filter = true;

        // Don't change locale in admin
        if (is_admin() && !wp_doing_ajax()) {
            $in_filter = false;
            return $locale;
        }

        global $LINGUA_LANGUAGE;
        // v1.0.6: Cache default language and avoid calling lingua_get_site_language()
        // which calls get_locale() and would trigger this filter again (infinite recursion)
        static $cached_default_lang = null;
        if ($cached_default_lang === null) {
            $cached_default_lang = get_option('lingua_default_language', 'ru');
        }
        $default_language = $cached_default_lang;

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

            $in_filter = false;
            return $new_locale;
        }

        $in_filter = false;
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

        // Allow add-ons to extend plugin functionality
        do_action('lingua_loaded');
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
        // v1.0.6: CORE classes - always needed (lightweight)
        require_once LINGUA_PLUGIN_DIR . 'includes/class-database.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-languages.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-url-rewriter.php';

        // Admin class - always needed for AJAX handlers
        require_once LINGUA_PLUGIN_DIR . 'admin/class-lingua-admin.php';

        // v1.0.7: Load full dependencies for page rendering AND lingua AJAX requests
        // Lingua AJAX handlers (e.g. lingua_get_translatable_content) need Translation_Manager,
        // Plural_Forms, etc. to process translations.
        $is_ajax = (defined('DOING_AJAX') && DOING_AJAX);
        $is_rest = (defined('REST_REQUEST') && REST_REQUEST);
        $is_cron = (defined('DOING_CRON') && DOING_CRON);
        $is_cli  = (defined('WP_CLI') && WP_CLI);
        $is_lingua_ajax = ($is_ajax && lingua_is_our_ajax_request());

        // Load full deps for: page rendering, admin pages, AND lingua AJAX
        if ((!$is_ajax && !$is_rest && !$is_cron && !$is_cli) || $is_lingua_ajax) {
            require_once LINGUA_PLUGIN_DIR . 'includes/class-content-processor.php';
            require_once LINGUA_PLUGIN_DIR . 'includes/class-translation-manager.php';
            require_once LINGUA_PLUGIN_DIR . 'includes/class-seo-integration.php';
            require_once LINGUA_PLUGIN_DIR . 'includes/class-nav-menu-integration.php';
            require_once LINGUA_PLUGIN_DIR . 'includes/class-translation-render.php';
            require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-plural-forms.php';
            require_once LINGUA_PLUGIN_DIR . 'includes/class-string-capture-settings.php';
            require_once LINGUA_PLUGIN_DIR . 'public/class-lingua-public.php';

            if (!class_exists('Lingua_Media_Replacer')) {
                require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-media-replacer.php';
            }

            lingua_debug_log('[Lingua v1.0.7] Full dependencies loaded (' . ($is_lingua_ajax ? 'lingua AJAX' : 'page rendering') . ')');
        } else {
            lingua_debug_log('[Lingua v1.0.7] Minimal dependencies loaded (AJAX/REST/Cron mode)');
        }
    }
    
    private function init_components() {
        // Initialize URL rewriter - always needed
        $this->url_rewriter = new Lingua_URL_Rewriter();
        $this->url_rewriter->init();

        // Store globally for access from admin
        global $lingua_url_rewriter;
        $lingua_url_rewriter = $this->url_rewriter;

        // v1.0.6: Skip heavy component initialization for AJAX/REST/Cron
        $is_ajax = (defined('DOING_AJAX') && DOING_AJAX);
        $is_rest = (defined('REST_REQUEST') && REST_REQUEST);
        $is_cron = (defined('DOING_CRON') && DOING_CRON);
        $is_cli  = (defined('WP_CLI') && WP_CLI);

        if (!$is_ajax && !$is_rest && !$is_cron && !$is_cli) {
            // Initialize translation manager
            $this->translation_manager = new Lingua_Translation_Manager();
            $this->translation_manager->apply_translation_filters();

            // Initialize SEO integration
            $this->seo_integration = new Lingua_SEO_Integration();
            $this->seo_integration->init();

            // Initialize translation render (DOM-based HTML processing)
            $this->translation_render = new Lingua_Translation_Render();

            // SAFE v2.0 INITIALIZATION: Check if v2.0 classes exist before initializing
            $this->init_v2_architecture_safe();

            // Nav Menu Integration
            $nav_menu = new Lingua_Nav_Menu_Integration();

            lingua_debug_log('[Lingua v1.0.6] Full components initialized');
        } else {
            lingua_debug_log('[Lingua v1.0.6] Skipped heavy components (AJAX/REST/Cron)');
        }

        // Allow add-ons to hook into component initialization
        do_action('lingua_components_initialized');
    }


    
    private function set_locale() {
        // Translations are loaded automatically by WordPress.org since WP 4.6
        // No manual load_plugin_textdomain() call needed
    }
    
    private function define_admin_hooks() {
        // Создаем админ экземпляр всегда (для AJAX запросов)
        $admin = new Lingua_Admin($this->version);
        
        // AJAX handlers (должны быть доступны всегда)
        add_action('wp_ajax_lingua_translate_content', array($admin, 'ajax_translate_content'));

        // v5.2.77: DEBUG - Log all AJAX requests to lingua actions
        add_action('admin_init', function() {
            if (defined('DOING_AJAX') && DOING_AJAX && isset($_POST['action'])) {
                if (strpos($_POST['action'], 'lingua') !== false) {
                    lingua_debug_log("🔥 LINGUA AJAX REQUEST: action={$_POST['action']}");
                }
            }
        });

        add_action('wp_ajax_lingua_save_translation', array($admin, 'ajax_save_translation'));

        add_action('wp_ajax_lingua_get_modal_template', array($admin, 'ajax_get_modal_template'));

        // v3.2: Clear bad WooCommerce translations
        add_action('wp_ajax_lingua_clear_bad_translations', array($admin, 'ajax_clear_bad_translations'));

        // v5.0.12: Clear page cache after save
        add_action('wp_ajax_lingua_clear_page_cache', array($admin, 'ajax_clear_page_cache'));

        // v5.0.13: Delete page translations (for debugging)
        add_action('wp_ajax_lingua_delete_page_translations', array($admin, 'ajax_delete_page_translations'));

        // v5.2.187: Save string translation from strings page
        add_action('wp_ajax_lingua_save_string_translation', array($admin, 'ajax_save_string_translation'));

        // v5.2.188: Plural forms AJAX handlers
        add_action('wp_ajax_lingua_get_plural_forms', array($admin, 'ajax_get_plural_forms'));
        add_action('wp_ajax_lingua_save_plural_translations', array($admin, 'ajax_save_plural_translations'));

        // Allow add-ons (e.g., IQCloud Translate Pro) to register additional AJAX handlers
        do_action('lingua_register_ajax_handlers', $admin);

        // ОТКАТ: Menu AJAX handler удален - Nav_Menu_Integration сам обрабатывает меню
        // add_action('wp_ajax_add-menu-item', array($this, 'ajax_add_menu_item'), 5);
        
        // REMOVED: AJAX handlers для неавторизованных пользователей - потенциальная угроза безопасности
        // Translation functions should only be available to authorized users
        // add_action('wp_ajax_nopriv_lingua_translate_content', array($admin, 'ajax_translate_content'));
        // add_action('wp_ajax_nopriv_lingua_get_modal_template', array($admin, 'ajax_get_modal_template'));
        // add_action('wp_ajax_nopriv_lingua_auto_translate_text', array($admin, 'ajax_auto_translate_text'));
        
        // Админ-специфичные хуки только в админке
        if (is_admin()) {
            // v1.0.6: Load admin-specific heavy components (gettext scan, plural forms etc.)
            // v1.0.10: Also load for lingua AJAX requests (scan, plural forms need these)
            if (!(defined('DOING_AJAX') && DOING_AJAX) || lingua_is_our_ajax_request_early()) {
                lingua_load_admin_components();
            }

            lingua_debug_log('[Lingua] Registering admin hooks...');
            // Menu and pages
            add_action('admin_menu', array($admin, 'add_admin_menu'));
            add_action('admin_init', array($admin, 'handle_settings_save'));

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

        wp_print_inline_script_tag($debug_js);
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
        // v1.0.6: Skip public hooks for AJAX/REST/Cron to save memory
        $is_ajax = (defined('DOING_AJAX') && DOING_AJAX);
        $is_rest = (defined('REST_REQUEST') && REST_REQUEST);
        $is_cron = (defined('DOING_CRON') && DOING_CRON);
        $is_cli  = (defined('WP_CLI') && WP_CLI);

        if ($is_ajax || $is_rest || $is_cron || $is_cli) {
            // v1.0.6: For AJAX requests, only register AJAX handlers
            // Load the public class file only if needed for AJAX handlers
            if ($is_ajax && !class_exists('Lingua_Public')) {
                require_once LINGUA_PLUGIN_DIR . 'public/class-lingua-public.php';
            }
            if (class_exists('Lingua_Public')) {
                $this->public = new Lingua_Public($this->version);
                $public = $this->public;
                // Register only AJAX handlers (no scripts/styles/output buffer needed)
                add_action('wp_ajax_lingua_get_translatable_content', array($public, 'ajax_get_translatable_content'));
                add_action('wp_ajax_nopriv_lingua_get_translatable_content', array($public, 'ajax_get_translatable_content'));
                add_action('wp_ajax_lingua_translate_frontend', array($public, 'ajax_translate_frontend'));
                add_action('wp_ajax_nopriv_lingua_translate_frontend', array($public, 'ajax_translate_frontend'));
                add_action('wp_ajax_lingua_get_dynamic_translations', array($public, 'ajax_get_dynamic_translations'));
                add_action('wp_ajax_nopriv_lingua_get_dynamic_translations', array($public, 'ajax_get_dynamic_translations'));
                add_action('wp_ajax_lingua_translate_ajax_content', array($public, 'ajax_translate_ajax_content'));
                add_action('wp_ajax_nopriv_lingua_translate_ajax_content', array($public, 'ajax_translate_ajax_content'));
                add_action('wp_ajax_lingua_refresh_nonce', function() {
                    wp_send_json_success(array('nonce' => wp_create_nonce('lingua_admin_nonce')));
                });
                add_action('wp_ajax_lingua_load_media_library_scripts', array($public, 'ajax_load_media_library_scripts'));
            }
            lingua_debug_log('[Lingua v1.0.6] Public hooks: AJAX-only mode');
            return; // Skip all frontend rendering hooks
        }

        // v5.2.151: Save as class property to prevent garbage collection
        $this->public = new Lingua_Public($this->version);

        // PHASE 2.1: Initialize Output Buffer EARLY (with lazy loading)
        // v1.0.6: Load heavy frontend components only when actually rendering a page
        add_action('template_redirect', function() {
            // Load frontend components on demand
            lingua_load_frontend_components();

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
}