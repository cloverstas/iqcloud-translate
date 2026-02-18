<?php
/**
 * Translation Render Class - Advanced String Capture System v2.0
 * Handles HTML parsing, string capture, and translation rendering
 * Integrated with new architecture components
 *
 * @package Lingua
 * @version 2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include HTML parser
require_once LINGUA_PLUGIN_DIR . 'includes/lib/simple_html_dom.php';

class Lingua_Translation_Render {

    private $settings;
    private $url_rewriter;

    // New architecture v2.0 components
    private $string_capture;
    private $cache_manager;
    private $dom_parser;
    private $filter_engine;

    // v5.2.2: Gettext translations cache for batch loading
    private $gettext_translations_cache = array();

    // v5.3.13: Cache for legacy table existence check
    private $legacy_table_exists = null;

    public function __construct() {
        $this->init();
    }

    /**
     * v5.3.13: Check if legacy table exists (with caching)
     * @return bool
     */
    private function legacy_table_exists() {
        if ($this->legacy_table_exists === null) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'lingua_translations';
            $this->legacy_table_exists = ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name);
        }
        return $this->legacy_table_exists;
    }

    /**
     * Initialize the translation render with new architecture v2.0
     * v5.2.2: Deferred gettext registration for proper timing
     */
    public function init() {
        // Get global URL rewriter instance (backward compatibility)
        global $lingua_url_rewriter;
        $this->url_rewriter = $lingua_url_rewriter ?: new Lingua_URL_Rewriter();

        // Initialize new architecture v2.0 components
        $this->init_new_architecture();

        // v5.3.5: Register gettext filters on wp_head hook
        // This ensures filters only apply to strings loaded AFTER the page starts rendering
        // Priority 100 = late execution, after other plugins have loaded their strings
        add_action('wp_head', array($this, 'register_gettext_filters'), 100);

        // v5.3.35: Disabled v2.0 upgrade notice (no longer needed)
        // add_action('admin_notices', array($this, 'show_architecture_upgrade_notice'));
    }

    /**
     * Initialize new architecture v2.0 components
     */
    private function init_new_architecture() {
        // Initialize component classes
        if (class_exists('Lingua_Cache_Manager')) {
            $this->cache_manager = new Lingua_Cache_Manager();
        }

        if (class_exists('Lingua_DOM_Parser')) {
            $this->dom_parser = new Lingua_DOM_Parser();
        }

        if (class_exists('Lingua_String_Filter_Engine')) {
            $this->filter_engine = new Lingua_String_Filter_Engine();
        }

        if (class_exists('Lingua_String_Capture')) {
            $this->string_capture = Lingua_String_Capture::get_instance();
        }

        // Log successful initialization
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log('Lingua Translation Render: New architecture v2.0 initialized');
        }
    }
    
    /**
     * Register gettext filters on wp_head hook (v5.3.5)
     * Only registers if output buffer is started (visible page content only)
     */
    public function register_gettext_filters() {
        global $LINGUA_LANGUAGE, $lingua_output_buffer_started;

        // v5.3.5: Check if output buffer is started
        // This ensures we only process strings that will be visible on the page
        if (empty($lingua_output_buffer_started)) {
            lingua_debug_log('[Lingua GETTEXT] Skipped - output buffer not started');
            return;
        }

        // Skip if default language
        if (empty($LINGUA_LANGUAGE) || $LINGUA_LANGUAGE === $this->url_rewriter->get_default_language()) {
            lingua_debug_log('[Lingua GETTEXT] Skipped - default language or language not set');
            return;
        }

        // Skip admin (except AJAX on frontend)
        if (is_admin() && !wp_doing_ajax()) {
            lingua_debug_log('[Lingua GETTEXT] Skipped - admin area');
            return;
        }

        // Skip REST API
        if (defined('REST_REQUEST') && REST_REQUEST) {
            lingua_debug_log('[Lingua GETTEXT] Skipped - REST API');
            return;
        }

        // BATCH LOAD: Load all gettext translations ONCE
        $this->load_all_gettext_translations($LINGUA_LANGUAGE);

        // Register filters with priority 100 (late execution after other plugins)
        add_filter('gettext', array($this, 'process_gettext_string'), 100, 3);
        add_filter('gettext_with_context', array($this, 'process_gettext_with_context'), 100, 4);
        add_filter('ngettext', array($this, 'process_ngettext_string'), 100, 5);
        add_filter('ngettext_with_context', array($this, 'process_ngettext_with_context'), 100, 6);

        lingua_debug_log('[Lingua GETTEXT] Filters registered for language: ' . $LINGUA_LANGUAGE . ' with ' . count($this->gettext_translations_cache) . ' translations');
    }

    /**
     * Load all gettext translations for current language (batch loading)
     * ONE SQL query instead of per-string queries
     * v5.2.2: Performance optimization
     */
    private function load_all_gettext_translations($language) {
        global $wpdb;

        // Check cache first
        $cache_key = 'lingua_gettext_' . $language;
        $cached = wp_cache_get($cache_key, 'lingua');

        if ($cached !== false) {
            $this->gettext_translations_cache = $cached;
            lingua_debug_log('[Lingua GETTEXT] Loaded from cache: ' . count($cached) . ' translations');
            return;
        }

        // Load from database - ONE query for ALL translations
        $translations = $wpdb->get_results($wpdb->prepare(
            "SELECT original_text, translated_text
             FROM {$wpdb->prefix}lingua_string_translations
             WHERE language_code = %s
             AND status >= %d
             AND translated_text != ''
             AND translated_text != original_text",
            $language,
            1 // MACHINE_TRANSLATED or better
        ), ARRAY_A);

        // Build hash map for O(1) lookup
        foreach ($translations as $t) {
            $this->gettext_translations_cache[$t['original_text']] = $t['translated_text'];
        }

        // Cache for 12 hours
        wp_cache_set($cache_key, $this->gettext_translations_cache, 'lingua', 12 * HOUR_IN_SECONDS);

        lingua_debug_log('[Lingua GETTEXT] Loaded from DB: ' . count($this->gettext_translations_cache) . ' translations');
    }

    /**
     * Process gettext strings (v5.3.5 optimized)
     * O(1) lookup in hash map
     * Note: Blacklist function check removed - debug_backtrace() too heavy for performance
     * The wp_head timing already filters out most admin strings
     */
    public function process_gettext_string($translation, $text, $domain) {
        // O(1) lookup in hash map
        if (isset($this->gettext_translations_cache[$text])) {
            return $this->gettext_translations_cache[$text];
        }

        return $translation;
    }
    
    /**
     * Process gettext with context
     */
    public function process_gettext_with_context($translation, $text, $context, $domain) {
        return $this->process_gettext_string($translation, $text, $domain);
    }
    
    /**
     * Process ngettext (plural forms)
     * v5.2.98: Fixed plural forms calculation
     */
    public function process_ngettext_string($translation, $single, $plural, $number, $domain) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE)) {
            return $translation;
        }

        // v5.2.126: Debug - log WP locale and incoming translation for product strings
        if ($single === 'product' && defined('WP_DEBUG') && WP_DEBUG) {
            $wp_locale = get_locale();
            $user_locale = get_user_locale();
            $is_admin = is_user_logged_in() ? 'YES' : 'NO';
            lingua_debug_log("[Lingua v5.2.126 ngettext DEBUG] single='{$single}', plural='{$plural}', n={$number}");
            lingua_debug_log("[Lingua v5.2.126 ngettext DEBUG] WP_translation='{$translation}', WP_locale={$wp_locale}, user_locale={$user_locale}, logged_in={$is_admin}");
        }

        // v5.2.98: Calculate plural form index using Lingua_Plural_Forms
        $plural_forms = Lingua_Plural_Forms::get_instance();
        $plural_form_index = $plural_forms->get_plural_form($number, $LINGUA_LANGUAGE);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[Lingua v5.2.98 ngettext] Processing: '{$single}'/'{$plural}' with count={$number}, plural_form_index={$plural_form_index}");
        }

        // v5.2.98: Use SINGULAR ($single) as original (msgid) for ALL plural forms
        // This matches gettext standard: msgid is always singular, translations vary by plural_form
        $original = $single;

        // Get translation for specific plural form
        $translation_manager = new Lingua_Translation_Manager();
        $lingua_translation = $translation_manager->get_plural_translation(
            $original,
            $LINGUA_LANGUAGE,
            $plural_form_index,
            $domain
        );

        // Return Lingua translation if found
        if ($lingua_translation && $lingua_translation !== $original) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[Lingua v5.2.98 ngettext] ✅ Found translation: '{$lingua_translation}'");
            }
            return $lingua_translation;
        }

        // Fallback to WordPress translation (may be msgid if no .po file)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[Lingua v5.2.98 ngettext] ⚠️ No Lingua translation, using WP translation: '{$translation}'");
        }
        return $translation;
    }
    
    /**
     * Process ngettext with context
     */
    public function process_ngettext_with_context($translation, $single, $plural, $number, $context, $domain) {
        return $this->process_ngettext_string($translation, $single, $plural, $number, $domain);
    }
    
    /**
     * Start output buffering to capture HTML
     */
    public function start_output_buffering() {
        // Skip for specific pages/requests
        if ($this->should_skip_processing()) {
            return;
        }
        
        ob_start(array($this, 'process_html_output'));
        
        lingua_debug_log('Lingua Translation Render: Started output buffering');
    }
    
    /**
     * Check if we should skip HTML processing
     */
    private function should_skip_processing() {
        global $pagenow;
        
        // Skip for admin pages
        if (is_admin()) {
            return true;
        }
        
        // Skip for AJAX requests  
        if (wp_doing_ajax()) {
            return true;
        }
        
        // Skip for REST API requests
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }
        
        // Skip for cron requests
        if (wp_doing_cron()) {
            return true;
        }
        
        // Skip for feeds
        if (is_feed()) {
            return true;
        }
        
        // Skip for certain file types
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('/\.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$/i', $request_uri)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Process HTML output - Universal DOM-based approach
     */
    public function process_html_output($html) {
        global $LINGUA_LANGUAGE;

        lingua_debug_log('Lingua Translation Render: Processing HTML output, length: ' . strlen($html));
        lingua_debug_log('Lingua Translation Render: Current language: ' . ($LINGUA_LANGUAGE ?? 'NOT_SET'));
        lingua_debug_log('Lingua Translation Render: Default language: ' . $this->url_rewriter->get_default_language());
        lingua_debug_log('Lingua Translation Render: REQUEST_URI: ' . ($_SERVER['REQUEST_URI'] ?? 'NOT_SET'));
        
        // Check force capture mode
        $force_capture = get_option('lingua_force_capture_mode', false);
        $force_language = get_option('lingua_force_capture_language', 'en');
        
        // Only process if we have a non-default language OR force capture is enabled
        if (!$force_capture && (empty($LINGUA_LANGUAGE) || $LINGUA_LANGUAGE === $this->url_rewriter->get_default_language())) {
            lingua_debug_log('Lingua Translation Render: Skipping - default language or empty');
            return $html;
        }
        
        // Use force language if in force capture mode
        if ($force_capture) {
            $LINGUA_LANGUAGE = $force_language;
            lingua_debug_log('Lingua Translation Render: Force capture mode enabled for language: ' . $force_language);
        }
        
        // Skip if HTML is too short (likely not a full page)
        if (strlen($html) < 100) {
            lingua_debug_log('Lingua Translation Render: Skipping - HTML too short');
            return $html;
        }
        
        try {
            // Parse HTML using our parser
            $dom_parser = Lingua_HTML_Parser::str_get_html($html);
            
            if (!$dom_parser) {
                lingua_debug_log('Lingua Translation Render: Failed to create HTML parser');
                return $html;
            }
            
            // UNIVERSAL PROCESSING
            // 1. Process all text nodes
            $this->process_text_nodes($dom_parser, $LINGUA_LANGUAGE);
            
            // 2. Process all attributes (title, alt, placeholder, etc)
            $this->process_translatable_attributes($dom_parser, $LINGUA_LANGUAGE);
            
            // 3. Process all links
            $this->process_html_links($dom_parser, $LINGUA_LANGUAGE);
            
            // 4. Process meta tags
            $this->process_meta_tags($dom_parser, $LINGUA_LANGUAGE);
            
            // 5. Process forms (labels, buttons, inputs)
            $this->process_form_elements($dom_parser, $LINGUA_LANGUAGE);
            
            // Get processed HTML
            $processed_html = $dom_parser->save();
            
            lingua_debug_log('Lingua Translation Render: HTML processing completed');
            
            return $processed_html;
            
        } catch (Exception $e) {
            lingua_debug_log('Lingua Translation Render: Error processing HTML - ' . $e->getMessage());
            return $html; // Return original HTML on error
        }
    }
    
    /**
     * Process all links in HTML
     */
    private function process_html_links($dom_parser, $current_language) {
        $home_url = trailingslashit(home_url());
        $admin_url = admin_url();
        
        // Find all <a> tags
        $links = $dom_parser->find('a');
        
        lingua_debug_log('Lingua Translation Render: Found ' . count($links) . ' links to process');
        
        foreach ($links as $link) {
            $url = $link->href;
            
            // Skip empty URLs
            if (empty($url)) {
                continue;
            }
            
            // Skip anchors
            if (strpos($url, '#') === 0) {
                continue;
            }
            
            // Skip mailto, tel, etc.
            if ($this->is_special_url($url)) {
                continue;
            }
            
            // Skip external links
            if ($this->is_external_link($url, $home_url)) {
                continue;
            }
            
            // Skip admin links
            if ($this->is_admin_link($url, $admin_url)) {
                continue;
            }
            
            // Process internal link
            $new_url = $this->process_internal_link($url, $current_language);
            
            if ($new_url && $new_url !== $url) {
                $link->href = $new_url;
                lingua_debug_log('Lingua Translation Render: Fixed link ' . $url . ' → ' . $new_url);
            }
        }
    }
    
    /**
     * Check if URL is external
     */
    private function is_external_link($url, $home_url) {
        // Relative URLs are internal
        if (strpos($url, 'http') !== 0) {
            return false;
        }
        
        // Check if URL starts with home URL
        return strpos($url, $home_url) !== 0;
    }
    
    /**
     * Check if URL is admin link
     */
    private function is_admin_link($url, $admin_url) {
        return strpos($url, $admin_url) === 0;
    }
    
    /**
     * Check if URL is special (mailto, tel, etc.)
     */
    private function is_special_url($url) {
        $special_schemes = array('mailto:', 'tel:', 'callto:', 'sms:', 'javascript:');
        
        foreach ($special_schemes as $scheme) {
            if (strpos($url, $scheme) === 0) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Process internal link to add language prefix
     */
    private function process_internal_link($url, $current_language) {
        try {
            // Use our URL rewriter to generate the correct URL
            $new_url = $this->url_rewriter->get_url_for_language($current_language, $url);
            
            return $new_url;
            
        } catch (Exception $e) {
            lingua_debug_log('Lingua Translation Render: Error processing internal link ' . $url . ': ' . $e->getMessage());
            return $url;
        }
    }
    
    /**
     * Process all text nodes in HTML
     */
    private function process_text_nodes($dom_parser, $current_language) {
        // Get translation manager instance
        $translation_manager = new Lingua_Translation_Manager();
        
        $processed_count = 0;
        $registered_count = 0;
        
        try {
            // Use DOMDocument directly for better text node processing
            $dom = new DOMDocument('1.0', 'UTF-8');
            libxml_use_internal_errors(true);
            
            // Get HTML and process with DOMDocument
            $html = $dom_parser->save();
            $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Get all text nodes
            $text_nodes = $xpath->query('//text()[normalize-space(.) != ""]');

            lingua_debug_log("Lingua: Found " . $text_nodes->length . " text nodes to process");

            foreach ($text_nodes as $node) {
                // Skip if parent is script, style, etc
                $parent = $node->parentNode;
                if ($parent && in_array($parent->nodeName, array('script', 'style', 'code', 'pre'))) {
                    continue;
                }
                
                // Skip admin bar elements
                if ($this->isAdminBarElement($node)) {
                    continue;
                }
                
                $text = trim($node->nodeValue);
                
                // Use configurable settings for string capture
                if (!Lingua_String_Capture_Settings::should_capture_string($text, 'text_node')) {
                    continue;
                }
                
                // Skip if matches skip patterns (emails, URLs, etc)
                if (Lingua_String_Capture_Settings::matches_skip_pattern($text)) {
                    continue;
                }
                
                // Skip if only numbers or special characters
                if (!preg_match('/[a-zA-Z\p{L}]/u', $text)) { // \p{L} matches any Unicode letter
                    continue;
                }
                
                // Skip known admin strings
                if ($this->isAdminString($text)) {
                    continue;
                }
                
                // Skip if text appears to be in target language already
                if ($this->isTextInTargetLanguage($text, $current_language)) {
                    continue;
                }
                
                // Check if we have a translation
                $translation = $translation_manager->get_string_translation($text, $current_language);
                
                if ($translation && $translation !== $text) {
                    // Apply translation
                    $node->nodeValue = $translation;
                    $processed_count++;
                } else {
                    // Before registering, check if this text is already a translation
                    if (!$this->isAlreadyTranslated($text, $current_language)) {
                        // Register for translation
                        $this->register_string_for_translation($text, 'text_node');
                        $registered_count++;
                    }
                }
            }
            
            // Save modified HTML back to parser
            $modified_html = $dom->saveHTML();
            $modified_html = str_replace('<?xml encoding="UTF-8">', '', $modified_html);
            
            // Update the parser's internal HTML
            $dom_parser->html = $modified_html;
            $dom_parser->dom->loadHTML('<?xml encoding="UTF-8">' . $modified_html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            
        } catch (Exception $e) {
            lingua_debug_log("Lingua: Error processing text nodes: " . $e->getMessage());
        }

        lingua_debug_log("Lingua: Processed $processed_count translations, registered $registered_count new strings");
    }
    
    /**
     * Process translatable attributes (title, alt, placeholder, etc)
     */
    private function process_translatable_attributes($dom_parser, $current_language) {
        $translation_manager = new Lingua_Translation_Manager();
        
        // Define attributes to translate
        $translatable_attributes = array(
            'title',
            'alt', 
            'placeholder',
            'aria-label',
            'data-title',
            'data-caption',
            'data-alt'
        );
        
        $processed_count = 0;
        
        foreach ($translatable_attributes as $attr) {
            // Find all elements with this attribute
            $elements = $dom_parser->find("[$attr]");
            
            foreach ($elements as $element) {
                $original_value = $element->getAttribute($attr);
                
                if (empty($original_value) || strlen($original_value) < 2) {
                    continue;
                }
                
                // Get translation
                $translation = $translation_manager->get_string_translation($original_value, $current_language);
                
                if ($translation && $translation !== $original_value) {
                    $element->setAttribute($attr, $translation);
                    $processed_count++;
                } else {
                    // Register for translation
                    $this->register_string_for_translation($original_value, 'attribute_' . $attr);
                }
            }
        }
        
        lingua_debug_log("Lingua: Processed $processed_count attributes");
    }
    
    /**
     * Process meta tags for SEO
     */
    private function process_meta_tags($dom_parser, $current_language) {
        $translation_manager = new Lingua_Translation_Manager();
        
        // Process meta description
        $meta_descriptions = $dom_parser->find('meta[name="description"]');
        foreach ($meta_descriptions as $meta) {
            $content = $meta->getAttribute('content');
            if (!empty($content)) {
                $translation = $translation_manager->get_string_translation($content, $current_language);
                if ($translation && $translation !== $content) {
                    $meta->setAttribute('content', $translation);
                }
            }
        }
        
        // Process og:title, og:description
        $og_tags = $dom_parser->find('meta[property^="og:"]');
        foreach ($og_tags as $meta) {
            $property = $meta->getAttribute('property');
            if (in_array($property, array('og:title', 'og:description', 'og:site_name'))) {
                $content = $meta->getAttribute('content');
                if (!empty($content)) {
                    $translation = $translation_manager->get_string_translation($content, $current_language);
                    if ($translation && $translation !== $content) {
                        $meta->setAttribute('content', $translation);
                    }
                }
            }
        }
    }
    
    /**
     * Process form elements
     */
    private function process_form_elements($dom_parser, $current_language) {
        $translation_manager = new Lingua_Translation_Manager();
        
        // Process submit buttons
        $submit_buttons = $dom_parser->find('input[type="submit"]');
        foreach ($submit_buttons as $button) {
            $value = $button->getAttribute('value');
            if (!empty($value)) {
                $translation = $translation_manager->get_string_translation($value, $current_language);
                if ($translation && $translation !== $value) {
                    $button->setAttribute('value', $translation);
                }
            }
        }
        
        // Process button text
        $buttons = $dom_parser->find('button');
        foreach ($buttons as $button) {
            $text = trim($button->innertext);
            if (!empty($text) && !$button->find('*')) { // Only if button contains plain text
                $translation = $translation_manager->get_string_translation($text, $current_language);
                if ($translation && $translation !== $text) {
                    $button->innertext = $translation;
                }
            }
        }
        
        // Process labels
        $labels = $dom_parser->find('label');
        foreach ($labels as $label) {
            $text = trim($label->plaintext);
            if (!empty($text)) {
                $translation = $translation_manager->get_string_translation($text, $current_language);
                if ($translation && $translation !== $text) {
                    // Preserve HTML structure
                    $label->innertext = str_replace($text, $translation, $label->innertext);
                }
            }
        }
    }
    
    /**
     * Register string for translation (auto-discovery)
     */
    private function register_string_for_translation($string, $context = 'general') {
        global $wpdb;

        // v5.4.0: Trim whitespace before saving to prevent mismatches
        $string = trim($string);

        // Skip if string is too short or numeric
        if (strlen($string) < 2 || is_numeric($string)) {
            return;
        }

        $string_hash = md5($string);
        $languages = get_option('lingua_languages', array());
        
        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lingua_string_translations 
             WHERE original_text_hash = %s LIMIT 1",
            $string_hash
        ));
        
        if (!$exists) {
            $default_lang = get_option('lingua_default_language', 'ru');
            $registered_count = 0;
            
            // Insert for each non-default language
            foreach ($languages as $lang_code => $lang_data) {
                if ($lang_code === $default_lang) {
                    continue; // Skip default language
                }
                
                $result = $wpdb->insert(
                    $wpdb->prefix . 'lingua_string_translations',
                    array(
                        'original_text' => $string,
                        'original_text_hash' => $string_hash,
                        'language_code' => $lang_code,
                        'context' => $context,
                        'status' => Lingua_Database::NOT_TRANSLATED
                    ),
                    array('%s', '%s', '%s', '%s', '%s')
                );
                
                if ($result) {
                    $registered_count++;
                }
            }
            
            if ($registered_count > 0) {
                lingua_debug_log("Lingua: Registered new string for translation: " . substr($string, 0, 50) . "... (for $registered_count languages)");
            }
        }
    }
    
    /**
     * Check if node is part of admin bar
     */
    private function isAdminBarElement($node) {
        $parent = $node->parentNode;
        while ($parent && $parent->nodeType === XML_ELEMENT_NODE) {
            if ($parent->hasAttribute('id')) {
                $id = $parent->getAttribute('id');
                if (strpos($id, 'wpadminbar') !== false || strpos($id, 'wp-admin-bar') !== false) {
                    return true;
                }
            }
            if ($parent->hasAttribute('class')) {
                $class = $parent->getAttribute('class');
                if (strpos($class, 'admin-bar') !== false || strpos($class, 'wp-admin') !== false) {
                    return true;
                }
            }
            $parent = $parent->parentNode;
        }
        return false;
    }
    
    /**
     * Check if string is known admin string
     */
    private function isAdminString($text) {
        // Default admin strings that should not be translated
        $default_admin_strings = array(
            'About WordPress', 'Edit Profile', 'Log Out', 'Howdy',
            'Dashboard', 'New', 'Edit', 'View', 'Comments',
            'Toolbar', 'Skip to toolbar', 'WordPress.org',
            'Documentation', 'Support', 'Feedback',
            'Administrator', 'Editor', 'Author', 'Contributor', 'Subscriber',
            'Customize', 'Translate Page', 'Visit Site', 'Updates',
            'Plugins', 'Users', 'Tools', 'Settings', 'Collapse menu',
            'Media', 'Pages', 'Posts', 'Appearance', 'Widgets', 'Menus',
            'Header', 'Background', 'Customizer', 'Themes',
            'lingua_clover', 'Добавить комментарий', 'Выйти', 'Изменить свой профиль'
        );
        
        // Allow filtering to add custom admin strings
        $admin_strings = apply_filters('lingua_skip_admin_strings', $default_admin_strings);
        
        // Also skip usernames (any string that looks like a username)
        if (preg_match('/^[a-z0-9_\-\.]+$/i', $text) && strlen($text) < 30) {
            return true; // Likely a username
        }
        
        // Check for admin strings - both exact match and partial match
        foreach ($admin_strings as $admin_string) {
            // Exact match
            if (strcasecmp(trim($text), trim($admin_string)) === 0) {
                return true;
            }
            // Also check if admin string is contained within the text (for composed strings)
            if (stripos($text, $admin_string) !== false && strlen($admin_string) > 3) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if text appears to be in target language already
     * Universal approach - checks if text exists as a translation OR from wrong language
     */
    private function isTextInTargetLanguage($text, $target_language) {
        global $wpdb;
        
        $default_language = $this->url_rewriter->get_default_language();
        
        // If we're processing default language, skip this check
        if ($target_language === $default_language) {
            return false;
        }
        
        // Check if this text exists as a translation for ANY original text in target language
        $exists_as_translation = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lingua_string_translations 
             WHERE translated_text = %s 
             AND language_code = %s 
             AND status = 'translated'",
            $text,
            $target_language
        ));
        
        // Check if this text exists as a translation in ANY non-default language
        $exists_as_any_translation = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lingua_string_translations 
             WHERE translated_text = %s 
             AND language_code != %s 
             AND status = 'translated'",
            $text,
            $default_language
        ));
        
        // Also check if it's a known translated post title/content (v5.3.13: only if legacy table exists)
        $exists_in_posts = 0;
        if ($this->legacy_table_exists()) {
            $exists_in_posts = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}lingua_translations
                 WHERE (translated_title = %s OR translated_excerpt = %s)
                 AND language_code != %s",
                $text,
                $text,
                $default_language
            ));
        }

        return ($exists_as_translation > 0 || $exists_as_any_translation > 0 || $exists_in_posts > 0);
    }
    
    /**
     * Check if text is already a translated string
     */
    private function isAlreadyTranslated($text, $current_language) {
        global $wpdb;
        
        // Check if this text exists as a translation in the database
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lingua_string_translations 
             WHERE translated_text = %s AND language_code = %s",
            $text,
            $current_language
        ));
        
        if ($exists > 0) {
            return true;
        }

        // Also check in post translations (v5.3.13: only if legacy table exists)
        if ($this->legacy_table_exists()) {
            $post_translation_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}lingua_translations
                 WHERE (translated_title = %s OR translated_excerpt LIKE %s)
                 AND language_code = %s",
                $text,
                '%' . $wpdb->esc_like($text) . '%',
                $current_language
            ));

            if ($post_translation_exists > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Show admin notice about new architecture v2.0
     */
    public function show_architecture_upgrade_notice() {
        // Only show to admins
        if (!current_user_can('manage_options')) {
            return;
        }

        // Only show once per user
        $notice_dismissed = get_user_meta(get_current_user_id(), 'lingua_v2_notice_dismissed', true);
        if ($notice_dismissed) {
            return;
        }

        // Check if all components are loaded
        $components_loaded = $this->cache_manager && $this->dom_parser && $this->filter_engine && $this->string_capture;

        if ($components_loaded) {
            echo '<div class="notice notice-success is-dismissible" data-notice="lingua-v2-upgrade">';
            echo '<p><strong>Linguateq:</strong> ';
            echo esc_html__('New architecture v2.0 is active! Enhanced performance and better string capture are now available.', 'linguateq');
            echo '</p>';
            echo '</div>';

            // Add JavaScript to handle dismissal
            echo '<script>
            jQuery(document).ready(function($) {
                $(document).on("click", "[data-notice=\"lingua-v2-upgrade\"] .notice-dismiss", function() {
                    $.post(ajaxurl, {
                        action: "lingua_dismiss_v2_notice",
                        _ajax_nonce: "' . wp_create_nonce('lingua_dismiss_notice') . '"
                    });
                });
            });
            </script>';
        }
    }

    /**
     * Handle dismissal of admin notice
     */
    public function handle_notice_dismissal() {
        add_action('wp_ajax_lingua_dismiss_v2_notice', array($this, 'dismiss_v2_notice'));
    }

    /**
     * AJAX handler for notice dismissal
     */
    public function dismiss_v2_notice() {
        if (!wp_verify_nonce($_POST['_ajax_nonce'], 'lingua_dismiss_notice')) {
            wp_die('Security check failed');
        }

        update_user_meta(get_current_user_id(), 'lingua_v2_notice_dismissed', true);
        wp_send_json_success();
    }

    /**
     * Get new architecture status
     *
     * @return array Status of new architecture components
     */
    public function get_architecture_status() {
        return array(
            'string_capture' => $this->string_capture !== null,
            'cache_manager' => $this->cache_manager !== null,
            'dom_parser' => $this->dom_parser !== null,
            'filter_engine' => $this->filter_engine !== null,
            'version' => '2.0'
        );
    }

    /**
     * Clear all caches (integration with new cache manager)
     */
    public function clear_all_caches() {
        if ($this->cache_manager) {
            $this->cache_manager->clear_all_cache();
            return true;
        }
        return false;
    }

    /**
     * Get cache statistics (integration with new cache manager)
     *
     * @return array Cache statistics
     */
    public function get_cache_stats() {
        if ($this->cache_manager) {
            return $this->cache_manager->get_stats();
        }
        return array();
    }

    /**
     * Check if new architecture is active
     *
     * @return bool
     */
    public function is_new_architecture_active() {
        return $this->string_capture !== null &&
               $this->cache_manager !== null &&
               $this->dom_parser !== null &&
               $this->filter_engine !== null;
    }
}