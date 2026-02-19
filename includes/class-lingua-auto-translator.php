<?php
/**
 * Lingua Auto Translator
 *
 * Background automatic translation for entire website
 * v5.3.1: Changed from sync on-demand to async background queue
 *
 * @package Lingua
 * @since 5.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Auto_Translator {

    /**
     * Maximum strings to translate in one batch
     * v5.3.2: Increased from 50 to 100 for better throughput
     * v5.3.6: Reduced back to 50 to prevent server overload during cron
     */
    const MAX_BATCH_SIZE = 50;

    /**
     * Delay between batch API calls in microseconds
     * v5.3.6: 100ms pause between batches (balanced speed vs stability)
     */
    const BATCH_DELAY_USEC = 100000;

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - register hooks
     */
    public function __construct() {
        // v5.3.1: Auto-queue posts for translation on save/publish
        add_action('save_post', array($this, 'on_post_save'), 20, 3);
        add_action('publish_post', array($this, 'on_post_publish'), 20, 2);
        add_action('publish_page', array($this, 'on_post_publish'), 20, 2);
        add_action('publish_product', array($this, 'on_post_publish'), 20, 2);

        // Register cron hook for processing queue
        add_action('lingua_process_translation_queue', array($this, 'process_queue'));

        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('lingua_process_translation_queue')) {
            wp_schedule_event(time(), 'every_minute', 'lingua_process_translation_queue');
        }
    }

    /**
     * Check if auto-translation is enabled
     *
     * @return bool
     */
    public static function is_enabled() {
        if (!get_option('lingua_auto_translate_website', false)) {
            return false;
        }

        if (!class_exists('Lingua_Middleware_API')) {
            return false;
        }

        $api = new Lingua_Middleware_API();
        return $api->is_pro_active();
    }

    /**
     * Hook: When post is saved (draft, update, etc)
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     * @param bool $update Whether this is an update
     */
    public function on_post_save($post_id, $post, $update) {
        // Skip if auto-translate disabled
        if (!self::is_enabled()) {
            return;
        }

        // Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Only process published posts
        if ($post->post_status !== 'publish') {
            return;
        }

        // Skip certain post types (v5.3.3: added lingua_switcher and other service types)
        $skip_types = array('nav_menu_item', 'revision', 'attachment', 'customize_changeset', 'lingua_switcher', 'acf-field', 'acf-field-group', 'wp_template', 'wp_template_part', 'wp_global_styles', 'oembed_cache');
        if (in_array($post->post_type, $skip_types)) {
            return;
        }

        // Add to translation queue
        $this->queue_post_for_translation($post_id);

        lingua_debug_log("[Lingua Auto v5.3.1] Post {$post_id} queued for translation on save");
    }

    /**
     * Hook: When post is published
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function on_post_publish($post_id, $post) {
        if (!self::is_enabled()) {
            return;
        }

        $this->queue_post_for_translation($post_id);
        lingua_debug_log("[Lingua Auto v5.3.1] Post {$post_id} queued for translation on publish");
    }

    /**
     * Add post to translation queue for all enabled languages
     *
     * @param int $post_id Post ID
     * @return int Number of queue items added
     */
    public function queue_post_for_translation($post_id) {
        if (!class_exists('Lingua_Translation_Queue')) {
            return 0;
        }

        // Use existing queue system with high priority (5 = urgent)
        return Lingua_Translation_Queue::add_post($post_id, 5);
    }

    /**
     * How many posts to process per cron run
     * v5.3.6: Increased from 1 to 5 for faster queue processing
     */
    const POSTS_PER_CRON = 5;

    /**
     * Process translation queue (called by cron)
     * v5.3.6: Now processes multiple posts per run for speed
     * v5.3.16: Added taxonomy term support
     */
    public function process_queue() {
        if (!self::is_enabled()) {
            return;
        }

        // Check if queue is paused
        if (class_exists('Lingua_Translation_Queue') && Lingua_Translation_Queue::is_paused()) {
            return;
        }

        // v5.3.6: Process multiple posts per cron run
        for ($i = 0; $i < self::POSTS_PER_CRON; $i++) {
            // Get next item from queue
            $item = Lingua_Translation_Queue::get_next();
            if (!$item) {
                return;
            }

            // v5.3.16: Check if this is a taxonomy term
            $is_term = Lingua_Translation_Queue::is_term_item($item->post_type);

            if ($is_term) {
                $taxonomy = Lingua_Translation_Queue::get_taxonomy_from_type($item->post_type);
                lingua_debug_log("[Lingua Auto v5.3.16] Processing queue item: term {$item->post_id} (taxonomy: {$taxonomy}), lang {$item->language_code}");
            } else {
                lingua_debug_log("[Lingua Auto v5.3.6] Processing queue item: post {$item->post_id}, lang {$item->language_code}");
            }

            // Mark as processing
            Lingua_Translation_Queue::mark_processing($item->id);

            try {
                // v5.3.16: Translate term or post based on type
                if ($is_term) {
                    $taxonomy = Lingua_Translation_Queue::get_taxonomy_from_type($item->post_type);
                    $result = $this->translate_term($item->post_id, $taxonomy, $item->language_code);
                } else {
                    $result = $this->translate_post($item->post_id, $item->language_code);
                }

                if (is_wp_error($result)) {
                    Lingua_Translation_Queue::mark_failed($item->id, $result->get_error_message());
                    lingua_debug_log("[Lingua Auto v5.3.16] Failed: " . $result->get_error_message());
                } else {
                    Lingua_Translation_Queue::mark_completed($item->id, $result['total'], $result['translated']);
                    lingua_debug_log("[Lingua Auto v5.3.16] Completed: {$result['translated']}/{$result['total']} strings");

                    // v5.5.1: Clear translated HTML cache so new translations take effect immediately
                    // Without this, stale cached HTML (with partial/broken translations) would be served
                    $ob = new Lingua_Output_Buffer();
                    $ob->clear_all_translation_caches();
                }
            } catch (Exception $e) {
                Lingua_Translation_Queue::mark_failed($item->id, $e->getMessage());
                lingua_debug_log("[Lingua Auto v5.3.16] Exception: " . $e->getMessage());
            }
        } // end for loop
    }

    /**
     * Translate a single post to a specific language
     * v5.3.2: Fetches rendered HTML to extract ALL strings (not just post fields)
     *
     * @param int $post_id Post ID
     * @param string $target_lang Target language code
     * @return array|WP_Error Result with total/translated counts or error
     */
    public function translate_post($post_id, $target_lang) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('invalid_post', 'Post not found');
        }

        // v5.3.2: Fetch rendered HTML page
        // This captures ALL strings: WooCommerce, theme, plugins, etc.
        $page_html = $this->fetch_page_html($post_id);
        $fetch_failed = false;

        if (is_wp_error($page_html)) {
            // Fallback to basic fields if HTTP fetch fails
            lingua_debug_log("[Lingua Auto v5.3.2] HTTP fetch failed for post {$post_id}, using fallback");
            $page_html = $post->post_title . "\n" . $post->post_excerpt . "\n" . $post->post_content;
            $fetch_failed = true;
        }

        // Extract strings from full HTML
        $strings = $this->extract_strings_from_html($page_html);

        // v5.3.18: If no strings extracted, mark as failed instead of completed
        if (empty($strings)) {
            if ($fetch_failed) {
                return new WP_Error('no_strings', 'HTTP fetch failed and no strings extracted from fallback content');
            }
            return new WP_Error('no_strings', 'No translatable strings found in page content');
        }

        // Get existing translations
        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';

        // v5.5.2: Include strings where translated == original (marked as untranslatable)
        // to avoid re-sending them to API on every queue run
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT original_text FROM $table
             WHERE language_code = %s
             AND translated_text != ''",
            $target_lang
        ));

        // Find strings that need translation
        $to_translate = array_diff($strings, $existing);

        if (empty($to_translate)) {
            return array('total' => count($strings), 'translated' => 0);
        }

        // v5.3.2: Split into batches by character length (API limit: 10000 chars)
        // Also limit by count to avoid memory issues
        $api = new Lingua_Middleware_API();
        $source_lang = get_option('lingua_default_language', 'en');
        $saved = 0;
        $batch = array();
        $batch_length = 0;
        $max_batch_chars = 8000; // Leave margin for API overhead

        $to_translate_array = array_values($to_translate);

        foreach ($to_translate_array as $string) {
            $string_length = mb_strlen($string);

            // Skip very long strings (likely not translatable content)
            if ($string_length > 2000) {
                continue;
            }

            // If adding this string exceeds limit, process current batch first
            if ($batch_length + $string_length > $max_batch_chars && !empty($batch)) {
                $saved += $this->process_translation_batch($batch, $target_lang, $source_lang, $post_id, $api);
                $batch = array();
                $batch_length = 0;
            }

            $batch[] = $string;
            $batch_length += $string_length;

            // Also limit by count
            if (count($batch) >= self::MAX_BATCH_SIZE) {
                $saved += $this->process_translation_batch($batch, $target_lang, $source_lang, $post_id, $api);
                $batch = array();
                $batch_length = 0;
            }
        }

        // Process remaining batch
        if (!empty($batch)) {
            $saved += $this->process_translation_batch($batch, $target_lang, $source_lang, $post_id, $api);
        }

        // Clear cache for this post
        $this->clear_post_cache($post_id);

        return array('total' => count($strings), 'translated' => $saved);
    }

    /**
     * v5.3.16: Translate a taxonomy term to a specific language
     * v5.3.29: Now also extracts and translates SEO meta tags from archive page
     * Translates term name, description, and SEO strings
     *
     * @param int $term_id Term ID
     * @param string $taxonomy Taxonomy name (e.g., 'product_cat')
     * @param string $target_lang Target language code
     * @return array|WP_Error Result with total/translated counts or error
     */
    public function translate_term($term_id, $taxonomy, $target_lang) {
        $term = get_term($term_id, $taxonomy);
        if (!$term || is_wp_error($term)) {
            return new WP_Error('invalid_term', 'Term not found');
        }

        // Collect strings to translate: name and description
        $strings = array();

        if (!empty($term->name)) {
            $strings[] = $term->name;
        }

        if (!empty($term->description)) {
            $strings[] = $term->description;
        }

        // v5.3.29: Fetch term archive page and extract SEO meta tags
        $term_link = get_term_link($term);
        if (!is_wp_error($term_link)) {
            $seo_strings = $this->extract_seo_from_url($term_link);
            if (!empty($seo_strings)) {
                $strings = array_merge($strings, $seo_strings);
                lingua_debug_log("[Lingua Auto v5.3.29] Extracted " . count($seo_strings) . " SEO strings from term archive: {$term_link}");
            }
        }

        // Remove duplicates
        $strings = array_unique($strings);

        if (empty($strings)) {
            return array('total' => 0, 'translated' => 0);
        }

        // Get existing translations
        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';

        // v5.5.2: Include strings where translated == original (marked as untranslatable)
        // to avoid re-sending them to API on every queue run
        $existing = $wpdb->get_col($wpdb->prepare(
            "SELECT original_text FROM $table
             WHERE language_code = %s
             AND translated_text != ''",
            $target_lang
        ));

        // Find strings that need translation
        $to_translate = array_diff($strings, $existing);

        if (empty($to_translate)) {
            return array('total' => count($strings), 'translated' => 0);
        }

        // Translate via API
        $api = new Lingua_Middleware_API();
        $source_lang = get_option('lingua_default_language', 'en');

        lingua_debug_log("[Lingua Auto v5.3.16] Translating term {$term_id} ({$term->name}): " . count($to_translate) . " strings");

        $translations = $api->translate_batch(array_values($to_translate), $target_lang, $source_lang);

        if (is_wp_error($translations)) {
            return $translations;
        }

        // Save translations
        $saved = 0;
        $to_translate_indexed = array_values($to_translate);
        foreach ($translations as $index => $translated_text) {
            if (isset($to_translate_indexed[$index]) && !empty($translated_text)) {
                // Use term context for easier identification
                $context = "term_{$taxonomy}_{$term_id}";
                if ($this->save_translation_with_context($to_translate_indexed[$index], $translated_text, $target_lang, $context)) {
                    $saved++;
                }
            }
        }

        lingua_debug_log("[Lingua Auto v5.3.16] Term {$term_id} completed: {$saved}/" . count($to_translate) . " strings translated");

        return array('total' => count($strings), 'translated' => $saved);
    }

    /**
     * v5.3.16: Save translation with custom context
     *
     * @param string $original Original text
     * @param string $translated Translated text
     * @param string $lang Language code
     * @param string $context Context string
     * @return bool Success
     */
    private function save_translation_with_context($original, $translated, $lang, $context) {
        global $wpdb;

        $original = trim(str_replace("\xC2\xA0", ' ', $original));
        $translated = trim($translated);

        $table = $wpdb->prefix . 'lingua_string_translations';
        $hash = md5($original);
        $now = current_time('mysql');

        // v5.5.2: Save even when translated == original (status 0 = untranslatable)
        $is_same = ($translated === $original);
        $status = $is_same ? 0 : Lingua_Database::MACHINE_TRANSLATED;

        // Check if exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE original_text_hash = %s AND language_code = %s LIMIT 1",
            $hash,
            $lang
        ));

        if ($exists) {
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT translated_text FROM $table WHERE id = %d",
                $exists
            ));

            if (empty($current) || $current === $original) {
                return $wpdb->update(
                    $table,
                    array(
                        'translated_text' => $translated,
                        'status' => $status,
                        'updated_at' => $now
                    ),
                    array('id' => $exists),
                    array('%s', '%d', '%s'),
                    array('%d')
                ) !== false;
            }

            return false;
        }

        return $wpdb->insert(
            $table,
            array(
                'original_text' => $original,
                'original_text_hash' => $hash,
                'translated_text' => $translated,
                'language_code' => $lang,
                'context' => $context,
                'status' => $status,
                'source' => 'auto_queue',
                'created_at' => $now,
                'updated_at' => $now
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        ) !== false;
    }

    /**
     * Process a batch of strings for translation
     * v5.3.2: Helper method to handle API calls and save results
     *
     * @param array $batch Strings to translate
     * @param string $target_lang Target language
     * @param string $source_lang Source language
     * @param int $post_id Post ID for context
     * @param Lingua_Middleware_API $api API instance
     * @return int Number of saved translations
     */
    private function process_translation_batch($batch, $target_lang, $source_lang, $post_id, $api) {
        if (empty($batch)) {
            return 0;
        }

        lingua_debug_log("[Lingua Auto v5.3.2] Processing batch of " . count($batch) . " strings (" . array_sum(array_map('mb_strlen', $batch)) . " chars)");

        $translations = $api->translate_batch($batch, $target_lang, $source_lang);

        if (is_wp_error($translations)) {
            lingua_debug_log("[Lingua Auto v5.3.2] Batch translation error: " . $translations->get_error_message());
            return 0;
        }

        $saved = 0;
        foreach ($translations as $index => $translated_text) {
            if (isset($batch[$index]) && !empty($translated_text)) {
                if ($this->save_translation($batch[$index], $translated_text, $target_lang, $post_id)) {
                    $saved++;
                }
            }
        }

        lingua_debug_log("[Lingua Auto v5.3.2] Batch completed: saved {$saved} translations");

        // v5.3.6: Pause between batches to prevent server overload
        usleep(self::BATCH_DELAY_USEC);
        @flush(); // Keep nginx connection alive

        return $saved;
    }

    /**
     * Fetch rendered HTML of a page
     * v5.3.2: Uses HTTP request to get full rendered page (like modal does)
     *
     * @param int $post_id Post ID
     * @return string|WP_Error HTML content or error
     */
    private function fetch_page_html($post_id) {
        $url = get_permalink($post_id);
        if (!$url) {
            return new WP_Error('no_permalink', 'Could not get permalink');
        }

        // Add parameter to skip translation processing (get original content)
        $url = add_query_arg('lingua_force_default', '1', $url);

        // v5.5.1: Prepare Host header for loopback requests
        $site_url_parsed = parse_url(get_option('siteurl'));
        $host_header = $site_url_parsed['host'];
        if (!empty($site_url_parsed['port'])) {
            $host_header .= ':' . $site_url_parsed['port'];
        }

        lingua_debug_log("[Lingua Auto v5.5.1] Fetching HTML from: {$url}");

        // Try original URL first (works on normal hosting with real SSL)
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false,
            'redirection' => 0,
            'headers' => array(
                'User-Agent' => 'Lingua Auto Translator/5.5.1',
                'Host' => $host_header
            )
        ));

        // v5.5.1: If HTTPS loopback fails, fallback to HTTP localhost
        if (is_wp_error($response)) {
            lingua_debug_log("[Lingua Auto v5.5.1] Primary request failed: " . $response->get_error_message());
            $parsed_url = parse_url($url);
            if (!empty($parsed_url['scheme']) && $parsed_url['scheme'] === 'https') {
                $internal_url = 'http://localhost' . ($parsed_url['path'] ?? '/');
                if (!empty($parsed_url['query'])) {
                    $internal_url .= '?' . $parsed_url['query'];
                }
                lingua_debug_log("[Lingua Auto v5.5.1] Retrying with internal HTTP: " . $internal_url);
                $response = wp_remote_get($internal_url, array(
                    'timeout' => 30,
                    'sslverify' => false,
                    'redirection' => 0,
                    'headers' => array(
                        'User-Agent' => 'Lingua Auto Translator/5.5.1',
                        'Host' => $host_header,
                        'X-Forwarded-Proto' => 'https'
                    )
                ));
            }
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return new WP_Error('http_error', "HTTP error: {$status_code}");
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return new WP_Error('empty_response', 'Empty HTML response');
        }

        lingua_debug_log("[Lingua Auto v5.3.2] Fetched " . strlen($html) . " bytes of HTML");

        // v5.3.6: Brief pause after HTTP fetch to not overload server
        usleep(50000); // 50ms

        return $html;
    }

    /**
     * Extract translatable strings from HTML content
     * v5.3.2: Uses same logic as output buffer for consistency
     *
     * @param string $html HTML content
     * @return array Unique strings
     */
    private function extract_strings_from_html($html) {
        // Use output buffer's extraction if available
        if (class_exists('Lingua_Output_Buffer')) {
            $buffer = new Lingua_Output_Buffer();
            if (method_exists($buffer, 'extract_strings_from_html')) {
                return $buffer->extract_strings_from_html($html);
            }
        }

        // Fallback: basic extraction
        return $this->extract_strings_from_content($html);
    }

    /**
     * Extract translatable strings from content (fallback)
     *
     * @param string $content HTML/text content
     * @return array Unique strings
     */
    private function extract_strings_from_content($content) {
        $strings = array();

        // Strip HTML tags but keep text
        $text = wp_strip_all_tags($content);

        // Split by common delimiters
        $parts = preg_split('/[\n\r\t]+/', $text);

        foreach ($parts as $part) {
            $part = trim($part);

            // Skip empty or very short strings
            if (empty($part) || mb_strlen($part) < 2) {
                continue;
            }

            // Skip numbers only
            if (is_numeric($part)) {
                continue;
            }

            // Skip URLs
            if (filter_var($part, FILTER_VALIDATE_URL)) {
                continue;
            }

            $strings[] = $part;
        }

        return array_unique($strings);
    }

    /**
     * v5.3.29: Extract SEO meta tags from a URL
     * Fetches the page HTML and extracts meta description, og:title, og:description
     *
     * @param string $url URL to fetch
     * @return array Array of SEO strings
     */
    private function extract_seo_from_url($url) {
        if (empty($url)) {
            return array();
        }

        // Add parameter to get original content
        $url = add_query_arg('lingua_force_default', '1', $url);

        lingua_debug_log("[Lingua Auto v5.3.29] Fetching SEO from URL: {$url}");

        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'sslverify' => false,
            'headers' => array(
                'User-Agent' => 'Lingua Auto Translator/5.3.29'
            )
        ));

        if (is_wp_error($response)) {
            lingua_debug_log("[Lingua Auto v5.3.29] HTTP error: " . $response->get_error_message());
            return array();
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            lingua_debug_log("[Lingua Auto v5.3.29] HTTP status: {$status_code}");
            return array();
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            return array();
        }

        $strings = array();

        // Extract meta description
        if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $content = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            if (!empty($content) && strlen($content) > 5) {
                $strings[] = trim($content);
            }
        }

        // Extract og:title
        if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $content = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            if (!empty($content) && strlen($content) > 5) {
                $strings[] = trim($content);
            }
        }

        // Extract og:description
        if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            $content = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            if (!empty($content) && strlen($content) > 5) {
                $strings[] = trim($content);
            }
        }

        // Extract <title>
        if (preg_match('/<title>([^<]+)<\/title>/i', $html, $matches)) {
            $content = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
            if (!empty($content) && strlen($content) > 5) {
                $strings[] = trim($content);
            }
        }

        lingua_debug_log("[Lingua Auto v5.3.29] Extracted " . count($strings) . " SEO strings from URL");

        return array_unique($strings);
    }

    /**
     * Save translation to database
     *
     * @param string $original Original text
     * @param string $translated Translated text
     * @param string $lang Language code
     * @param int $post_id Post ID for context
     * @return bool Success
     */
    private function save_translation($original, $translated, $lang, $post_id = 0) {
        global $wpdb;

        // v5.3.7: Normalize original text (trim whitespace, convert nbsp to space)
        // This ensures hash matches during lookup
        $original = trim(str_replace("\xC2\xA0", ' ', $original));
        $translated = trim($translated);

        $table = $wpdb->prefix . 'lingua_string_translations';
        $hash = md5($original);
        $now = current_time('mysql');
        $context = $post_id ? "auto_translate.post_{$post_id}" : 'auto_translate';

        // v5.5.2: When translated == original (e.g. "Toyota", "FAQ"), still save the record
        // so it won't be re-sent to API next time. Use status 0 (untranslatable) to distinguish.
        $is_same = ($translated === $original);
        $status = $is_same ? 0 : Lingua_Database::MACHINE_TRANSLATED;

        // Check if exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE original_text_hash = %s AND language_code = %s LIMIT 1",
            $hash,
            $lang
        ));

        if ($exists) {
            // Update only if currently empty or same-as-original (re-translate attempt)
            $current = $wpdb->get_var($wpdb->prepare(
                "SELECT translated_text FROM $table WHERE id = %d",
                $exists
            ));

            if (empty($current) || $current === $original) {
                return $wpdb->update(
                    $table,
                    array(
                        'translated_text' => $translated,
                        'status' => $status,
                        'updated_at' => $now
                    ),
                    array('id' => $exists),
                    array('%s', '%d', '%s'),
                    array('%d')
                ) !== false;
            }

            return false; // Already has a real translation
        }

        // Insert new
        return $wpdb->insert(
            $table,
            array(
                'original_text' => $original,
                'original_text_hash' => $hash,
                'translated_text' => $translated,
                'language_code' => $lang,
                'context' => $context,
                'status' => $status,
                'source' => 'auto_queue',
                'created_at' => $now,
                'updated_at' => $now
            ),
            array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
        ) !== false;
    }

    /**
     * Clear translation cache for a post
     *
     * @param int $post_id Post ID
     */
    private function clear_post_cache($post_id) {
        global $wpdb;

        // Clear transients for this post
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE %s",
            '%lingua_translated_html_%'
        ));

        // Trigger cache clear hook
        do_action('lingua_clear_post_cache', $post_id);
    }

    /**
     * Get available gettext domains with info
     *
     * @return array Domain info [domain => ['name', 'has_mo', 'source']]
     */
    public static function get_available_domains() {
        global $l10n;

        $domains = array();

        if (empty($l10n)) {
            return $domains;
        }

        $theme = wp_get_theme();
        $theme_domain = $theme->get('TextDomain');

        foreach ($l10n as $domain => $mo) {
            if ($domain === 'iqcloud-translate') {
                continue;
            }

            $source = 'plugin';
            if ($domain === 'default') {
                $source = 'wordpress';
            } elseif ($domain === $theme_domain) {
                $source = 'theme';
            }

            $has_mo = false;
            if ($mo instanceof MO || $mo instanceof Translations) {
                $has_mo = !empty($mo->entries);
            }

            $domains[$domain] = array(
                'name' => $domain,
                'has_mo' => $has_mo,
                'source' => $source,
                'label' => self::get_domain_label($domain, $source)
            );
        }

        uasort($domains, function($a, $b) {
            $order = array('wordpress' => 0, 'theme' => 1, 'plugin' => 2);
            $order_a = $order[$a['source']] ?? 3;
            $order_b = $order[$b['source']] ?? 3;

            if ($order_a !== $order_b) {
                return $order_a - $order_b;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $domains;
    }

    /**
     * Get human-readable label for domain
     *
     * @param string $domain Domain name
     * @param string $source Source type
     * @return string Label
     */
    private static function get_domain_label($domain, $source) {
        if ($domain === 'default') {
            return __('WordPress Core', 'iqcloud-translate');
        }

        $source_labels = array(
            'wordpress' => __('Core', 'iqcloud-translate'),
            'theme' => __('Theme', 'iqcloud-translate'),
            'plugin' => __('Plugin', 'iqcloud-translate')
        );

        $source_label = $source_labels[$source] ?? '';

        if ($source === 'plugin') {
            $plugins = get_plugins();
            foreach ($plugins as $file => $plugin) {
                if (strpos($file, $domain) !== false || $plugin['TextDomain'] === $domain) {
                    return $plugin['Name'] . ' (' . $source_label . ')';
                }
            }
        }

        return ucfirst($domain) . ($source_label ? ' (' . $source_label . ')' : '');
    }
}

// Initialize singleton on plugins_loaded
add_action('plugins_loaded', function() {
    Lingua_Auto_Translator::get_instance();
}, 20);
