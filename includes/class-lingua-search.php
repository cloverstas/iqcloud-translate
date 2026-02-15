<?php
/**
 * Lingua Search Integration
 * Enables search in translated content
 *
 * @package Lingua
 * @since 5.2.181
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Search {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Default language
     */
    private $default_language;

    /**
     * Current language
     */
    private $current_language;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->default_language = get_option('lingua_default_language', 'en');

        // Hook into WordPress search
        add_action('pre_get_posts', array($this, 'filter_search_query'), 10);

        // Hook into REST API search
        add_filter('rest_post_query', array($this, 'filter_rest_search'), 10, 2);

        // WoodMart specific filter - EARLY priority to run before WP_Query is created
        add_filter('woodmart_ajax_search_args', array($this, 'filter_woodmart_search'), 5, 2);

        // Filter to restore search query display
        add_filter('get_search_query', array($this, 'restore_search_query'), 10);
    }

    /**
     * Get current language
     */
    private function get_current_language() {
        global $LINGUA_LANGUAGE;

        // For AJAX requests, detect language from referer URL
        if (wp_doing_ajax()) {
            $referer = wp_get_referer();
            if ($referer) {
                $path = parse_url($referer, PHP_URL_PATH);
                $segments = explode('/', trim($path, '/'));
                $languages = get_option('lingua_languages', array());

                if (!empty($segments[0]) && isset($languages[$segments[0]])) {
                    return $segments[0];
                }
            }
        }

        return $LINGUA_LANGUAGE ?: $this->default_language;
    }

    /**
     * Check if we should filter search (not default language)
     */
    private function should_filter_search() {
        $current = $this->get_current_language();
        return $current !== $this->default_language;
    }

    /**
     * Filter WordPress search query
     *
     * @param WP_Query $query
     */
    public function filter_search_query($query) {
        // Skip if default language
        if (!$this->should_filter_search()) {
            return;
        }

        // Skip admin queries (except AJAX)
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }

        // Only filter search queries
        if (!$query->is_search() && empty($query->get('s'))) {
            return;
        }

        // Skip if already filtered
        if ($query->get('lingua_search_filtered')) {
            return;
        }

        $search_term = $query->get('s');
        if (empty($search_term)) {
            return;
        }

        $language = $this->get_current_language();

        // Search in translations
        $post_ids = $this->search_in_translations($search_term, $language);

        // Also search in original content (union of both)
        $original_ids = $this->search_in_original($search_term, $query);

        // Merge results
        $all_ids = array_unique(array_merge($post_ids, $original_ids));

        if (!empty($all_ids)) {
            // Set post__in to limit results to found IDs
            $query->set('post__in', $all_ids);
            // Clear search term to prevent double filtering
            $query->set('s', '');
            // Mark as filtered
            $query->set('lingua_search_filtered', true);
            // Store original search term
            $query->set('lingua_original_search', $search_term);
        } elseif (!empty($post_ids) || empty($original_ids)) {
            // No results in translations or original - return empty
            $query->set('post__in', array(0));
            $query->set('s', '');
            $query->set('lingua_search_filtered', true);
            $query->set('lingua_original_search', $search_term);
        }

        // Log for debugging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[Lingua Search] Query: '{$search_term}', Language: {$language}, Found: " . count($all_ids) . " posts");
        }
    }

    /**
     * Search in translations table
     *
     * @param string $search_term
     * @param string $language
     * @return array Post IDs
     */
    public function search_in_translations($search_term, $language) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'lingua_string_translations';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
            return array();
        }

        // Parse search terms (support multiple words)
        $search_terms = $this->parse_search_terms($search_term);

        if (empty($search_terms)) {
            return array();
        }

        // Build WHERE clause for all search terms
        $where_clauses = array();
        $prepare_values = array($language);

        foreach ($search_terms as $term) {
            $where_clauses[] = "translated_text LIKE %s";
            $prepare_values[] = '%' . $wpdb->esc_like($term) . '%';
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Query translations table
        // Context format: post_XXXX_page_content or similar
        $query = $wpdb->prepare(
            "SELECT DISTINCT context
             FROM {$table_name}
             WHERE language_code = %s
             AND ({$where_sql})",
            ...$prepare_values
        );

        $contexts = $wpdb->get_col($query);

        // Extract post IDs from contexts
        $post_ids = array();
        foreach ($contexts as $context) {
            // Match patterns like: post_123_page_content, core_fields.title.post_123
            if (preg_match('/post[_.](\d+)/', $context, $matches)) {
                $post_ids[] = (int) $matches[1];
            }
        }

        // Remove duplicates
        $post_ids = array_unique($post_ids);

        // Verify posts exist and are published
        if (!empty($post_ids)) {
            $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
            $valid_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE ID IN ({$placeholders})
                 AND post_status = 'publish'",
                ...$post_ids
            ));
            $post_ids = array_map('intval', $valid_ids);
        }

        return $post_ids;
    }

    /**
     * Search in original content (standard WordPress search)
     *
     * @param string $search_term
     * @param WP_Query $query
     * @return array Post IDs
     */
    private function search_in_original($search_term, $query) {
        global $wpdb;

        $post_type = $query->get('post_type');
        if (empty($post_type)) {
            $post_type = 'any';
        }

        // Build post type clause
        if ($post_type === 'any') {
            $post_type_sql = "post_type IN ('post', 'page', 'product')";
        } elseif (is_array($post_type)) {
            $placeholders = implode(',', array_fill(0, count($post_type), '%s'));
            $post_type_sql = $wpdb->prepare("post_type IN ({$placeholders})", ...$post_type);
        } else {
            $post_type_sql = $wpdb->prepare("post_type = %s", $post_type);
        }

        // Search in post_title and post_content
        $like_term = '%' . $wpdb->esc_like($search_term) . '%';

        $results = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE (post_title LIKE %s OR post_content LIKE %s)
             AND post_status = 'publish'
             AND {$post_type_sql}
             LIMIT 100",
            $like_term,
            $like_term
        ));

        return array_map('intval', $results);
    }

    /**
     * Parse search terms
     *
     * @param string $search_query
     * @return array
     */
    private function parse_search_terms($search_query) {
        // Remove extra whitespace
        $search_query = preg_replace('/\s+/', ' ', trim($search_query));

        // Handle quoted phrases
        $terms = array();
        if (preg_match_all('/"([^"]+)"/', $search_query, $matches)) {
            $terms = $matches[1];
            $search_query = preg_replace('/"[^"]+"/', '', $search_query);
        }

        // Add remaining words
        $words = array_filter(explode(' ', trim($search_query)));
        $terms = array_merge($terms, $words);

        // Filter short terms (less than 2 chars)
        $terms = array_filter($terms, function($term) {
            return mb_strlen($term) >= 2;
        });

        return array_values($terms);
    }

    /**
     * Filter REST API search
     *
     * @param array $args
     * @param WP_REST_Request $request
     * @return array
     */
    public function filter_rest_search($args, $request) {
        if (!$this->should_filter_search()) {
            return $args;
        }

        if (empty($args['s'])) {
            return $args;
        }

        $search_term = $args['s'];
        $language = $this->get_current_language();

        $post_ids = $this->search_in_translations($search_term, $language);

        if (!empty($post_ids)) {
            $args['post__in'] = isset($args['post__in'])
                ? array_intersect($args['post__in'], $post_ids)
                : $post_ids;
            $args['s'] = '';
        }

        return $args;
    }

    /**
     * Filter WoodMart AJAX search
     *
     * @param array $args
     * @param string $post_type
     * @return array
     */
    public function filter_woodmart_search($args, $post_type) {
        $language = $this->get_current_language();

        // Skip if default language
        if ($language === $this->default_language) {
            return $args;
        }

        // Get search term
        $search_term = isset($args['s']) ? $args['s'] : '';
        if (empty($search_term)) {
            return $args;
        }

        // Search in translations
        $post_ids = $this->search_in_translations($search_term, $language);

        if (!empty($post_ids)) {
            // Set post__in to found IDs
            $args['post__in'] = $post_ids;
            // Clear search term to prevent double filtering
            $args['s'] = '';
            // Mark as filtered
            $args['lingua_search_filtered'] = true;
        }

        return $args;
    }

    /**
     * Restore search query for display
     *
     * @param string $s
     * @return string
     */
    public function restore_search_query($s) {
        global $wp_query;

        if (!$this->should_filter_search()) {
            return $s;
        }

        // Check if we have the original search stored
        if ($wp_query && $wp_query->get('lingua_original_search')) {
            return $wp_query->get('lingua_original_search');
        }

        // Fallback to GET parameter
        if (empty($s) && isset($_GET['s'])) {
            return sanitize_text_field(wp_unslash($_GET['s']));
        }

        return $s;
    }

    /**
     * Public method for external use
     * Allows themes/plugins to search in translations directly
     *
     * @param string $search_term
     * @param string $language Optional, uses current language if not specified
     * @return array Post IDs
     */
    public static function find_posts($search_term, $language = null) {
        $instance = self::get_instance();

        if ($language === null) {
            $language = $instance->get_current_language();
        }

        return $instance->search_in_translations($search_term, $language);
    }
}

// Initialize on plugins_loaded to ensure all hooks are available
add_action('plugins_loaded', function() {
    Lingua_Search::get_instance();
}, 20);
