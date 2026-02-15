<?php
/**
 * SEO Integration for Lingua plugin
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_SEO_Integration {
    
    private $languages;
    private $default_language;
    private $url_rewriter;
    
    public function __construct() {
        // v5.2.137: Fixed hardcoded 'ru' default
        $this->default_language = get_option('lingua_default_language', 'en');
        $this->languages = get_option('lingua_languages', array());
    }
    
    /**
     * Initialize SEO features
     */
    public function init() {
        // Filter HTML lang attribute to match current language
        add_filter('language_attributes', array($this, 'filter_html_lang_attribute'), 10, 1);

        // v5.2.137: Yoast SEO Open Graph fixes
        if (defined('WPSEO_VERSION')) {
            // Fix og:locale and og:url for multilingual
            add_filter('wpseo_locale', array($this, 'filter_og_locale'), 10, 1);
            add_filter('wpseo_opengraph_url', array($this, 'filter_og_url'), 10, 1);
            // Translate JSON-LD schema
            add_filter('wpseo_schema_graph', array($this, 'translate_schema_graph'), 10, 2);
        }

        // v5.2.144: Use Lingua's existing output buffer for hreflang replacement (best practice)
        add_filter('lingua_process_output_buffer', array($this, 'replace_hreflang_in_output'), 20, 1);

        // Add language to sitemap
        add_filter('wpseo_sitemap_entry', array($this, 'add_languages_to_sitemap'), 10, 3);

        // Add JSON-LD structured data for multilingual content
        add_action('wp_head', array($this, 'add_json_ld_language_data'), 5);

        // RankMath integration
        if (defined('RANK_MATH_VERSION')) {
            add_filter('rank_math/frontend/title', array($this, 'translate_rank_math_title'), 10, 1);
            add_filter('rank_math/frontend/description', array($this, 'translate_rank_math_description'), 10, 1);
        }
    }

    /**
     * Replace Yoast hreflang with our multilingual version using Lingua's output buffer
     * v5.2.144: Best practice - use existing output buffer instead of creating a new one
     */
    public function replace_hreflang_in_output($output) {
        // Remove all existing hreflang tags
        $output = preg_replace('/<link[^>]*rel=["\']alternate["\'][^>]*hreflang=["\'][^"\']*["\'][^>]*\/?>\s*/i', '', $output);

        // Generate our hreflang tags
        $hreflang_tags = $this->generate_hreflang_tags();

        // Insert our tags after <head> opening tag
        $output = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $hreflang_tags, $output, 1);

        return $output;
    }

    /**
     * Generate hreflang tags HTML
     * v5.2.144: Best practice approach using Lingua's existing output buffer
     */
    private function generate_hreflang_tags() {
        if (!is_singular() && !is_home() && !is_front_page() && !is_archive()) {
            return '';
        }

        $base_url = rtrim(site_url(), '/');
        $languages = get_option('lingua_languages', array());
        $default_lang = get_option('lingua_default_language', 'en');

        if (empty($languages)) {
            return '';
        }

        if (!isset($languages[$default_lang])) {
            $languages[$default_lang] = array('name' => 'Default');
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $current_path = parse_url($request_uri, PHP_URL_PATH);
        $current_path = rtrim($current_path, '/');
        if (empty($current_path)) {
            $current_path = '/';
        }

        // Remove language prefix
        $page_path = $current_path;
        foreach (array_keys($languages) as $lang) {
            $pattern = '#^/' . preg_quote($lang, '#') . '(?:/|$)#';
            if (preg_match($pattern, $current_path)) {
                $page_path = preg_replace('#^/' . preg_quote($lang, '#') . '#', '', $current_path);
                if (empty($page_path)) {
                    $page_path = '/';
                }
                break;
            }
        }

        if ($page_path[0] !== '/') {
            $page_path = '/' . $page_path;
        }

        $output = "    <!-- Lingua hreflang v5.2.144 | page_path: {$page_path} | default: {$default_lang} -->\n";

        foreach (array_keys($languages) as $lang_code) {
            if ($lang_code === $default_lang) {
                $lang_url = $base_url . $page_path;
            } else {
                if ($page_path === '/') {
                    $lang_url = $base_url . '/' . $lang_code . '/';
                } else {
                    $lang_url = $base_url . '/' . $lang_code . $page_path;
                }
            }
            $output .= '    <link rel="alternate" hreflang="' . esc_attr($lang_code) . '" href="' . esc_url($lang_url) . '" />' . "\n";
        }

        $default_url = $base_url . $page_path;
        $output .= '    <link rel="alternate" hreflang="x-default" href="' . esc_url($default_url) . '" />' . "\n";

        return $output;
    }

    /**
     * Filter og:locale to match current language
     * v5.2.137: Fixes og:locale showing en_US on non-English pages
     */
    public function filter_og_locale($locale) {
        global $LINGUA_LANGUAGE;

        $current_language = !empty($LINGUA_LANGUAGE) ? $LINGUA_LANGUAGE : $this->default_language;

        // Map language codes to Facebook locale format
        $locale_map = array(
            'en' => 'en_US',
            'ru' => 'ru_RU',
            'fr' => 'fr_FR',
            'de' => 'de_DE',
            'es' => 'es_ES',
            'it' => 'it_IT',
            'zh' => 'zh_CN',
            'ja' => 'ja_JP',
            'ko' => 'ko_KR',
            'pt' => 'pt_BR',
            'ar' => 'ar_AR',
            'sl' => 'sl_SI',
            'uk' => 'uk_UA',
            'pl' => 'pl_PL',
            'nl' => 'nl_NL',
            'tr' => 'tr_TR',
            'he' => 'he_IL',
            'fa' => 'fa_IR'
        );

        $new_locale = isset($locale_map[$current_language]) ? $locale_map[$current_language] : $locale;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA SEO] og:locale changed from {$locale} to {$new_locale} for language: {$current_language}");
        }

        return $new_locale;
    }

    /**
     * Filter og:url to include language prefix
     * v5.2.137: Fixes og:url missing language prefix
     */
    public function filter_og_url($url) {
        global $LINGUA_LANGUAGE;

        $current_language = !empty($LINGUA_LANGUAGE) ? $LINGUA_LANGUAGE : $this->default_language;

        // Don't modify URL for default language
        if ($current_language === $this->default_language) {
            return $url;
        }

        // Parse the URL
        $parsed = parse_url($url);
        if (!$parsed) {
            return $url;
        }

        // Check if language prefix already exists
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        $all_langs = array_keys($this->languages);

        foreach ($all_langs as $lang) {
            if (preg_match('#^/' . preg_quote($lang, '#') . '(/|$)#', $path)) {
                // Language prefix already exists
                return $url;
            }
        }

        // Add language prefix
        $new_path = '/' . $current_language . $path;

        // Rebuild URL
        $new_url = $parsed['scheme'] . '://' . $parsed['host'];
        if (isset($parsed['port'])) {
            $new_url .= ':' . $parsed['port'];
        }
        $new_url .= $new_path;
        if (isset($parsed['query'])) {
            $new_url .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment'])) {
            $new_url .= '#' . $parsed['fragment'];
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA SEO] og:url changed from {$url} to {$new_url}");
        }

        return $new_url;
    }

    /**
     * Translate Yoast JSON-LD schema graph
     * v5.2.137: Translates name, description, and other text fields in schema
     */
    public function translate_schema_graph($graph, $context) {
        global $LINGUA_LANGUAGE;

        $current_language = !empty($LINGUA_LANGUAGE) ? $LINGUA_LANGUAGE : $this->default_language;

        // Don't translate for default language
        if ($current_language === $this->default_language) {
            return $graph;
        }

        // Get translations for current context
        $translation_manager = new Lingua_Translation_Manager();
        $post_id = is_singular() ? get_the_ID() : 0;

        // Build translation map
        $translation_map = array();

        if ($post_id) {
            global $wpdb;
            $table = $wpdb->prefix . 'lingua_string_translations';

            $translations = $wpdb->get_results($wpdb->prepare(
                "SELECT original_text, translated_text FROM {$table}
                 WHERE language_code = %s
                 AND (context LIKE %s OR context LIKE %s)
                 AND translated_text != ''",
                $current_language,
                'post_' . $post_id . '_%',
                'global_%'
            ));

            foreach ($translations as $t) {
                if (!empty($t->original_text) && !empty($t->translated_text)) {
                    $translation_map[$t->original_text] = $t->translated_text;
                }
            }
        }

        // Also get global translations without post context
        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';
        $global_translations = $wpdb->get_results($wpdb->prepare(
            "SELECT original_text, translated_text FROM {$table}
             WHERE language_code = %s
             AND translated_text != ''",
            $current_language
        ));

        foreach ($global_translations as $t) {
            if (!empty($t->original_text) && !empty($t->translated_text)) {
                // Don't overwrite post-specific translations
                if (!isset($translation_map[$t->original_text])) {
                    $translation_map[$t->original_text] = $t->translated_text;
                }
            }
        }

        if (empty($translation_map)) {
            return $graph;
        }

        // Translate schema graph
        $graph = $this->translate_schema_array($graph, $translation_map);

        return $graph;
    }

    /**
     * Recursively translate schema array
     * v5.2.138: Also fix URLs to include language prefix
     */
    private function translate_schema_array($data, $translation_map) {
        global $LINGUA_LANGUAGE;

        $current_language = !empty($LINGUA_LANGUAGE) ? $LINGUA_LANGUAGE : get_option('lingua_default_language', 'en');
        $default_lang = get_option('lingua_default_language', 'en');
        $site_url = rtrim(site_url(), '/');

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    $data[$key] = $this->translate_schema_array($value, $translation_map);
                } elseif (is_string($value) && !empty($value)) {
                    // Translate specific keys that contain translatable text
                    $translatable_keys = array(
                        'name', 'description', 'headline', 'articleBody',
                        'caption', 'text', 'alternateName', 'slogan'
                    );

                    if (in_array($key, $translatable_keys)) {
                        if (isset($translation_map[$value])) {
                            $data[$key] = $translation_map[$value];
                        }
                    }

                    // v5.2.138: Fix URLs to include language prefix
                    $url_keys = array('url', 'urlTemplate', '@id');
                    if (in_array($key, $url_keys) && $current_language !== $default_lang) {
                        // Check if URL belongs to this site and doesn't have language prefix
                        if (strpos($value, $site_url) === 0) {
                            $path = substr($value, strlen($site_url));
                            // Check if path already has a language prefix
                            $languages = get_option('lingua_languages', array());
                            $has_prefix = false;
                            foreach (array_keys($languages) as $lang) {
                                // v5.2.179: Fixed regex - escape # delimiter inside pattern
                                if (preg_match('#^/' . preg_quote($lang, '#') . '(?:/|$|\#|\?)#', $path)) {
                                    $has_prefix = true;
                                    break;
                                }
                            }
                            // Add language prefix if not present
                            if (!$has_prefix) {
                                $data[$key] = $site_url . '/' . $current_language . $path;
                            }
                        }
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Filter HTML lang attribute to match current language
     * Fixes issue where <html lang="ru-RU"> appears on EN pages
     */
    public function filter_html_lang_attribute($output) {
        global $LINGUA_LANGUAGE;

        // Determine current language
        $current_language = !empty($LINGUA_LANGUAGE) ? $LINGUA_LANGUAGE : $this->default_language;

        // Fallback to constant if global not set
        if ($current_language === $this->default_language && defined('LINGUA_CURRENT_LANG')) {
            $current_language = LINGUA_CURRENT_LANG;
        }

        // Convert language code to locale format (en -> en-US, ru -> ru-RU, etc.)
        $locale_map = array(
            'en' => 'en-US',
            'ru' => 'ru-RU',
            'fr' => 'fr-FR',
            'de' => 'de-DE',
            'es' => 'es-ES',
            'it' => 'it-IT',
            'zh' => 'zh-CN',
            'ja' => 'ja-JP',
            'ko' => 'ko-KR',
            'pt' => 'pt-PT'
        );

        $locale = isset($locale_map[$current_language]) ? $locale_map[$current_language] : $current_language;

        // Replace lang attribute in output
        $output = preg_replace('/lang="[^"]*"/', 'lang="' . esc_attr($locale) . '"', $output);

        // Debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA SEO] HTML lang attribute set to: {$locale} (from language: {$current_language})");
        }

        return $output;
    }

    /**
     * Translate Yoast SEO title
     */
    public function translate_yoast_title($title) {
        if (!defined('LINGUA_CURRENT_LANG') || LINGUA_CURRENT_LANG === $this->default_language) {
            return $title;
        }

        global $post;
        if (!$post) {
            return $title;
        }

        // v3.6: Use new v2.0 string translation architecture
        $translation_manager = new Lingua_Translation_Manager();

        // Get original SEO title (with Yoast variables replaced)
        $original_seo_title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
        if (empty($original_seo_title)) {
            return $title;
        }

        // Replace Yoast variables in original to match what we extracted
        $original_seo_title = $this->replace_yoast_variables($original_seo_title, $post->ID);

        // Find translation in string translations table
        $translated = $translation_manager->find_string_in_post_translations(
            $original_seo_title,
            $post->ID,
            LINGUA_CURRENT_LANG
        );

        if (!empty($translated)) {
            lingua_debug_log("[LINGUA SEO v3.6] Applied SEO title translation: " . substr($translated, 0, 50));
            return $translated;
        }

        return $title;
    }

    /**
     * v3.6: Replace Yoast variables (same as in extractor)
     */
    private function replace_yoast_variables($text, $post_id) {
        if (empty($text)) {
            return $text;
        }

        $post = get_post($post_id);
        if (!$post) {
            return $text;
        }

        $replacements = array(
            '%%sitename%%' => get_bloginfo('name'),
            '%%sitedesc%%' => get_bloginfo('description'),
            '%%title%%' => $post->post_title,
            '%%excerpt%%' => $post->post_excerpt,
            '%%sep%%' => '-',
        );

        foreach ($replacements as $var => $value) {
            $text = str_replace($var, $value, $text);
        }

        return $text;
    }
    
    /**
     * Translate Yoast SEO description
     */
    public function translate_yoast_description($description) {
        if (!defined('LINGUA_CURRENT_LANG') || LINGUA_CURRENT_LANG === $this->default_language) {
            return $description;
        }
        
        global $post;
        if (!$post) {
            return $description;
        }
        
        $translation_manager = new Lingua_Translation_Manager();
        $translation = $translation_manager->get_translation($post->ID, LINGUA_CURRENT_LANG);
        
        if ($translation && isset($translation->meta['seo_description']) && !empty($translation->meta['seo_description'])) {
            return $translation->meta['seo_description'];
        }
        
        return $description;
    }
    
    /**
     * Translate Open Graph title
     */
    public function translate_og_title($title) {
        if (!defined('LINGUA_CURRENT_LANG') || LINGUA_CURRENT_LANG === $this->default_language) {
            return $title;
        }
        
        global $post;
        if (!$post) {
            return $title;
        }
        
        $translation_manager = new Lingua_Translation_Manager();
        $translation = $translation_manager->get_translation($post->ID, LINGUA_CURRENT_LANG);
        
        if ($translation && isset($translation->meta['og_title']) && !empty($translation->meta['og_title'])) {
            return $translation->meta['og_title'];
        }
        
        return $title;
    }
    
    /**
     * Translate Open Graph description
     */
    public function translate_og_description($description) {
        if (!defined('LINGUA_CURRENT_LANG') || LINGUA_CURRENT_LANG === $this->default_language) {
            return $description;
        }
        
        global $post;
        if (!$post) {
            return $description;
        }
        
        $translation_manager = new Lingua_Translation_Manager();
        $translation = $translation_manager->get_translation($post->ID, LINGUA_CURRENT_LANG);
        
        if ($translation && isset($translation->meta['og_description']) && !empty($translation->meta['og_description'])) {
            return $translation->meta['og_description'];
        }
        
        return $description;
    }
    
    /**
     * Adjust canonical URL for language
     */
    public function adjust_canonical_url($canonical) {
        if (!defined('LINGUA_CURRENT_LANG') || LINGUA_CURRENT_LANG === $this->default_language) {
            return $canonical;
        }
        
        $url_rewriter = new Lingua_URL_Rewriter();
        return $url_rewriter->get_url_for_language(LINGUA_CURRENT_LANG);
    }
    
    /**
     * Add language variations to sitemap
     */
    public function add_languages_to_sitemap($url, $type, $post) {
        if ($type !== 'post' || !$post) {
            return $url;
        }
        
        $translation_manager = new Lingua_Translation_Manager();
        $translations = $translation_manager->get_post_translations($post->ID);
        
        if (!empty($translations)) {
            $url['languages'] = array();
            
            // Add default language
            $url_rewriter = new Lingua_URL_Rewriter();
            $url['languages'][$this->default_language] = $url_rewriter->get_url_for_language($this->default_language);
            
            // Add translations
            foreach ($translations as $translation) {
                $lang_url = $url_rewriter->get_url_for_language($translation->language_code);
                $url['languages'][$translation->language_code] = $lang_url;
            }
        }
        
        return $url;
    }
    
    /**
     * Get current page URL without language prefix
     */
    private function get_current_page_url() {
        // Get the current request URI
        $request_uri = $_SERVER['REQUEST_URI'];
        
        // Remove query string if present
        $path = strtok($request_uri, '?');
        
        // Get all possible language codes
        $all_languages = $this->languages;
        if (!isset($all_languages[$this->default_language])) {
            $all_languages[$this->default_language] = array(); // Include default
        }
        
        
        // Remove language prefix from path
        foreach (array_keys($all_languages) as $lang_code) {
            // Check if path starts with /lang_code/ or is exactly /lang_code
            if (preg_match('#^/' . preg_quote($lang_code, '#') . '(/|$)#', $path)) {
                $path = preg_replace('#^/' . preg_quote($lang_code, '#') . '(/|$)#', '/', $path);
                break;
            }
        }
        
        // Clean up path
        $path = '/' . ltrim($path, '/');
        $path = rtrim($path, '/');
        
        // If empty, make it root
        if ($path === '') {
            $path = '/';
        }
        
        
        return home_url($path);
    }
    
    /**
     * Add language prefix to URL
     */
    private function add_language_prefix_to_url($url, $lang_code) {
        if ($lang_code === $this->default_language) {
            return $url;
        }
        
        $home_url = rtrim(home_url(), '/');
        $url = rtrim($url, '/');
        
        if (strpos($url, $home_url) === 0) {
            $path = substr($url, strlen($home_url));
            return $home_url . '/' . $lang_code . $path;
        }
        
        return $url;
    }
    
    /**
     * Add JSON-LD structured data for language information
     */
    public function add_json_ld_language_data() {
        if (!is_singular()) {
            return;
        }
        
        global $post;
        if (!$post) {
            return;
        }
        
        $current_lang = defined('LINGUA_CURRENT_LANG') ? LINGUA_CURRENT_LANG : $this->default_language;
        
        // Get available translations
        $translation_manager = new Lingua_Translation_Manager();
        $translations = $translation_manager->get_post_translations($post->ID);
        
        $json_ld = array(
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'inLanguage' => $current_lang,
            'url' => get_permalink($post->ID)
        );
        
        // Add translations if available
        if (!empty($translations)) {
            $json_ld['availableLanguage'] = array();
            
            // Add default language
            $json_ld['availableLanguage'][] = array(
                '@type' => 'Language',
                'name' => $this->get_language_name($this->default_language),
                'alternateName' => $this->default_language
            );
            
            // Add other translations
            foreach ($translations as $translation) {
                $json_ld['availableLanguage'][] = array(
                    '@type' => 'Language',
                    'name' => $this->get_language_name($translation->language_code),
                    'alternateName' => $translation->language_code
                );
            }
        }
        
        echo '<script type="application/ld+json">' . wp_json_encode($json_ld, JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
    
    /**
     * Translate RankMath title
     */
    public function translate_rank_math_title($title) {
        return $this->translate_yoast_title($title);
    }
    
    /**
     * Translate RankMath description
     */
    public function translate_rank_math_description($description) {
        return $this->translate_yoast_description($description);
    }
    
    /**
     * Get language name for a language code
     */
    private function get_language_name($lang_code) {
        $language_names = array(
            'en' => 'English',
            'ru' => 'Russian', 
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'it' => 'Italian',
            'zh' => 'Chinese'
        );
        
        return isset($language_names[$lang_code]) ? $language_names[$lang_code] : $lang_code;
    }
}