<?php
/**
 * Lingua String Capture
 * Manages HTML capture through output buffering for translation processing
 *
 * @package Lingua
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_String_Capture {

    /**
     * Instance of this class
     *
     * @var Lingua_String_Capture
     */
    private static $instance = null;

    /**
     * Cache manager instance
     *
     * @var Lingua_Cache_Manager
     */
    private $cache_manager;

    /**
     * DOM parser instance
     *
     * @var Lingua_DOM_Parser
     */
    private $dom_parser;

    /**
     * Filter engine instance
     *
     * @var Lingua_String_Filter_Engine
     */
    private $filter_engine;

    /**
     * Current language
     *
     * @var string
     */
    private $current_language;

    /**
     * Default language
     *
     * @var string
     */
    private $default_language;

    /**
     * Capture enabled flag
     *
     * @var bool
     */
    private $capture_enabled = false;

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Get singleton instance
     *
     * @return Lingua_String_Capture
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Initialize the string capture system
     */
    private function init() {
        // Load dependencies - will be available after all classes are loaded
        add_action('plugins_loaded', array($this, 'init_dependencies'), 15);

        // Get language settings
        $this->default_language = get_option('lingua_default_language', 'ru');

        // Initialize capture on appropriate hook
        add_action('template_redirect', array($this, 'start_capture'), 1);

        // Handle language detection
        add_action('wp', array($this, 'detect_current_language'), 5);
    }

    /**
     * Initialize dependencies after all classes are loaded
     */
    public function init_dependencies() {
        if (class_exists('Lingua_Cache_Manager')) {
            $this->cache_manager = new Lingua_Cache_Manager();
        }
        if (class_exists('Lingua_DOM_Parser')) {
            $this->dom_parser = new Lingua_DOM_Parser();
        }
        if (class_exists('Lingua_String_Filter_Engine')) {
            $this->filter_engine = new Lingua_String_Filter_Engine();
        }
    }

    /**
     * Detect current language
     */
    public function detect_current_language() {
        global $LINGUA_LANGUAGE;

        // Use global if set
        if (!empty($LINGUA_LANGUAGE)) {
            $this->current_language = sanitize_text_field($LINGUA_LANGUAGE);
            return;
        }

        // Try to detect from URL rewriter
        if (class_exists('Lingua_URL_Rewriter')) {
            global $lingua_url_rewriter;
            if ($lingua_url_rewriter) {
                $detected = $lingua_url_rewriter->get_current_language();
                if ($detected) {
                    $this->current_language = sanitize_text_field($detected);
                    return;
                }
            }
        }

        // Default to site default language
        $this->current_language = $this->default_language;

        // Allow filtering
        $this->current_language = apply_filters('lingua_current_language', $this->current_language);
        $this->current_language = sanitize_text_field($this->current_language);
    }

    /**
     * Start HTML capture
     */
    public function start_capture() {
        // Check if capture should be enabled
        if (!$this->should_capture_page()) {
            return;
        }

        // Ensure dependencies are loaded
        if (!$this->cache_manager || !$this->dom_parser || !$this->filter_engine) {
            return;
        }

        $this->capture_enabled = true;

        // Start output buffering with our callback
        if (!ob_get_level()) {
            ob_start(array($this, 'process_captured_html'));
        }

        // Log capture start for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log('Lingua String Capture: Started HTML capture for language: ' . $this->current_language);
        }
    }

    /**
     * Check if page should be captured
     *
     * @return bool
     */
    public function should_capture_page() {
        // Skip if disabled
        if (!get_option('lingua_enable_string_capture', true)) {
            return false;
        }

        // Skip admin pages
        if (is_admin()) {
            return false;
        }

        // Skip AJAX requests
        if (wp_doing_ajax()) {
            return false;
        }

        // Skip REST API requests
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        // Skip cron requests
        if (wp_doing_cron()) {
            return false;
        }

        // Skip feeds
        if (is_feed()) {
            return false;
        }

        // Skip XML-RPC requests
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return false;
        }

        // Skip login/register pages
        global $pagenow;
        $skip_pages = array('wp-login.php', 'wp-register.php');
        if (in_array($pagenow, $skip_pages)) {
            return false;
        }

        // Skip certain file types
        $request_uri = sanitize_text_field($_SERVER['REQUEST_URI'] ?? '');
        if (preg_match('/\.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot|xml|txt|pdf)$/i', $request_uri)) {
            return false;
        }

        // Skip if user agent looks like a bot (basic check)
        $user_agent = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (preg_match('/(bot|crawler|spider|scraper)/i', $user_agent)) {
            return false;
        }

        // Allow filtering
        return apply_filters('lingua_should_capture_page', true);
    }

    /**
     * Process captured HTML
     *
     * @param string $html The captured HTML content
     * @return string Processed HTML content
     */
    public function process_captured_html($html) {
        // Validate HTML length
        if (strlen($html) < 100) {
            return $html; // Too short to be a real page
        }

        // Sanitize HTML input
        if (!is_string($html)) {
            return '';
        }

        // Check if we have a non-default language
        $force_capture = get_option('lingua_force_capture_mode', false);
        if (!$force_capture && ($this->current_language === $this->default_language || empty($this->current_language))) {
            return $html; // No translation needed
        }

        // Generate cache key for this page
        $page_cache_key = $this->generate_page_cache_key($html);

        // Try to get processed HTML from cache
        $cached_html = $this->cache_manager->get_page_cache($page_cache_key, $this->current_language);
        if ($cached_html !== false) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log('Lingua String Capture: Serving cached HTML for ' . $this->current_language);
            }
            return $cached_html;
        }

        try {
            // Parse HTML and extract translatable content
            $dom = $this->dom_parser->parse_html($html);
            if (!$dom) {
                return $html; // Failed to parse
            }

            // Get translatable elements
            $translatable_elements = $this->dom_parser->get_translatable_content($dom);

            // Filter elements
            $filtered_elements = $this->filter_engine->filter_elements($translatable_elements);

            // Process translations
            $processed_html = $this->apply_translations_to_html($dom, $filtered_elements);

            // Cache the result
            $this->cache_manager->set_page_cache($page_cache_key, $this->current_language, $processed_html);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log('Lingua String Capture: Processed ' . count($filtered_elements) . ' elements for ' . $this->current_language);
            }

            return $processed_html;

        } catch (Exception $e) {
            // Log error but don't break the page
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log('Lingua String Capture Error: ' . $e->getMessage());
            }
            return $html;
        }
    }

    /**
     * Apply translations to HTML
     *
     * @param DOMDocument $dom The DOM document
     * @param array $elements Array of translatable elements
     * @return string Processed HTML
     */
    private function apply_translations_to_html($dom, $elements) {
        if (empty($elements)) {
            return $this->dom_parser->get_html_from_dom($dom);
        }

        // Get translation manager
        if (!class_exists('Lingua_Translation_Manager')) {
            return $this->dom_parser->get_html_from_dom($dom);
        }

        $translation_manager = new Lingua_Translation_Manager();

        // Process elements in batches for performance
        $batch_size = apply_filters('lingua_translation_batch_size', 50);
        $batches = array_chunk($elements, $batch_size);

        foreach ($batches as $batch) {
            $translations = array();

            // Collect translations for this batch
            foreach ($batch as $element) {
                $original_text = $element['text'];
                $translation = $translation_manager->get_string_translation($original_text, $this->current_language);

                if ($translation && $translation !== $original_text) {
                    $translations[] = array(
                        'element' => $element,
                        'translation' => $translation
                    );
                } else {
                    // Register string for future translation if not already registered
                    $this->register_string_for_translation($original_text, $element['context']);
                }
            }

            // Apply translations to DOM
            $this->dom_parser->apply_translations($dom, $translations);
        }

        return $this->dom_parser->get_html_from_dom($dom);
    }

    /**
     * Register string for translation
     *
     * @param string $string The string to register
     * @param string $context The context for the string
     */
    private function register_string_for_translation($string, $context = 'general') {
        global $wpdb;

        // Sanitize input
        $string = sanitize_text_field($string);
        $context = sanitize_text_field($context);

        // Skip if string is too short
        if (strlen($string) < 2) {
            return;
        }

        $string_hash = md5($string);
        $languages = get_option('lingua_languages', array());

        // Check if already exists using prepared statement
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lingua_string_translations
             WHERE original_text_hash = %s LIMIT 1",
            $string_hash
        ));

        if (!$exists && !empty($languages)) {
            // Insert for each non-default language
            foreach ($languages as $lang_code => $lang_data) {
                if ($lang_code === $this->default_language) {
                    continue;
                }

                // Sanitize language code
                $lang_code = sanitize_text_field($lang_code);

                $result = $wpdb->insert(
                    $wpdb->prefix . 'lingua_string_translations',
                    array(
                        'original_text' => $string,
                        'original_text_hash' => $string_hash,
                        'language_code' => $lang_code,
                        'context' => $context,
                        'status' => Lingua_Database::NOT_TRANSLATED,
                        'created_date' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%s')
                );

                if (!$result) {
                    lingua_debug_log('Lingua: Failed to register string for translation - ' . $wpdb->last_error);
                }
            }
        }
    }

    /**
     * Generate cache key for page
     *
     * @param string $html HTML content
     * @return string Cache key
     */
    private function generate_page_cache_key($html) {
        // Create hash based on URL and content signature
        $url = sanitize_text_field($_SERVER['REQUEST_URI'] ?? '');
        $content_signature = substr(md5($html), 0, 8); // Short signature to detect content changes

        return 'page_' . md5($url . $content_signature);
    }

    /**
     * Check if capture is enabled
     *
     * @return bool
     */
    public function is_capture_enabled() {
        return $this->capture_enabled;
    }

    /**
     * Get current language
     *
     * @return string
     */
    public function get_current_language() {
        return $this->current_language;
    }

    /**
     * Get default language
     *
     * @return string
     */
    public function get_default_language() {
        return $this->default_language;
    }

    /**
     * Force language for testing
     *
     * @param string $language Language code
     */
    public function set_current_language($language) {
        $this->current_language = sanitize_text_field($language);
    }

    /**
     * Clear capture cache
     */
    public function clear_cache() {
        if ($this->cache_manager) {
            $this->cache_manager->clear_all_cache();
        }
    }
}