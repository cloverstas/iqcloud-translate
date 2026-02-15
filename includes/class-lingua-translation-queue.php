<?php
/**
 * Translation Queue Manager
 * Manages automatic translation queue for posts/pages
 *
 * @package Lingua
 * @since 5.2.179
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Translation_Queue {

    // Queue status constants
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_SKIPPED = 'skipped';

    /**
     * Get table name
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'lingua_translation_queue';
    }

    /**
     * Create queue table
     */
    public static function create_table() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = self::get_table_name();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            post_type varchar(50) NOT NULL DEFAULT 'post',
            language_code varchar(10) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            priority int(11) NOT NULL DEFAULT 10,
            strings_total int(11) DEFAULT 0,
            strings_translated int(11) DEFAULT 0,
            error_message text,
            attempts int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_post_lang (post_id, language_code),
            KEY idx_status (status),
            KEY idx_priority (priority),
            KEY idx_post_type (post_type),
            KEY idx_language (language_code),
            KEY idx_created (created_at)
        ) $charset_collate;";

        dbDelta($sql);
    }

    /**
     * Add post to translation queue for all enabled languages
     *
     * @param int $post_id Post ID
     * @param int $priority Priority (lower = higher priority)
     * @return int Number of queue items added
     */
    public static function add_post($post_id, $priority = 10) {
        $post = get_post($post_id);
        if (!$post) {
            return 0;
        }

        $languages = get_option('lingua_languages', array());
        $default_language = get_option('lingua_default_language', 'ru');

        // Remove default language from targets
        unset($languages[$default_language]);

        if (empty($languages)) {
            return 0;
        }

        $added = 0;
        foreach (array_keys($languages) as $lang_code) {
            if (self::add_to_queue($post_id, $lang_code, $post->post_type, $priority)) {
                $added++;
            }
        }

        return $added;
    }

    /**
     * Add single item to queue
     *
     * @param int $post_id Post ID
     * @param string $language_code Language code
     * @param string $post_type Post type
     * @param int $priority Priority
     * @return bool Success
     */
    public static function add_to_queue($post_id, $language_code, $post_type = 'post', $priority = 10) {
        global $wpdb;

        // Check if already in queue and not failed
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM " . self::get_table_name() . "
             WHERE post_id = %d AND language_code = %s",
            $post_id, $language_code
        ));

        // Skip if currently processing
        if ($existing === self::STATUS_PROCESSING) {
            return false;
        }

        // v5.5.1: If completed, re-queue for translation (content may have changed)
        if ($existing === self::STATUS_COMPLETED) {
            return $wpdb->update(
                self::get_table_name(),
                array(
                    'status' => self::STATUS_PENDING,
                    'error_message' => null,
                    'strings_total' => 0,
                    'strings_translated' => 0,
                    'created_at' => current_time('mysql')
                ),
                array('post_id' => $post_id, 'language_code' => $language_code),
                array('%s', '%s', '%d', '%d', '%s'),
                array('%d', '%s')
            ) !== false;
        }

        // If failed, update to pending for retry
        if ($existing === self::STATUS_FAILED) {
            return $wpdb->update(
                self::get_table_name(),
                array(
                    'status' => self::STATUS_PENDING,
                    'error_message' => null,
                    'attempts' => 0,
                    'created_at' => current_time('mysql')
                ),
                array('post_id' => $post_id, 'language_code' => $language_code),
                array('%s', '%s', '%d', '%s'),
                array('%d', '%s')
            ) !== false;
        }

        // If pending, skip (already in queue)
        if ($existing === self::STATUS_PENDING) {
            return false;
        }

        // Insert new item
        $result = $wpdb->insert(
            self::get_table_name(),
            array(
                'post_id' => $post_id,
                'post_type' => $post_type,
                'language_code' => $language_code,
                'status' => self::STATUS_PENDING,
                'priority' => $priority,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s')
        );

        return $result !== false;
    }

    /**
     * Get next item from queue for processing
     *
     * @return object|null Queue item or null
     */
    public static function get_next() {
        global $wpdb;

        return $wpdb->get_row(
            "SELECT * FROM " . self::get_table_name() . "
             WHERE status = '" . self::STATUS_PENDING . "'
             ORDER BY priority ASC, created_at ASC
             LIMIT 1"
        );
    }

    /**
     * Mark item as processing
     *
     * @param int $queue_id Queue item ID
     * @return bool Success
     */
    public static function mark_processing($queue_id) {
        global $wpdb;

        return $wpdb->update(
            self::get_table_name(),
            array(
                'status' => self::STATUS_PROCESSING,
                'started_at' => current_time('mysql'),
                'attempts' => $wpdb->get_var($wpdb->prepare(
                    "SELECT attempts FROM " . self::get_table_name() . " WHERE id = %d",
                    $queue_id
                )) + 1
            ),
            array('id' => $queue_id),
            array('%s', '%s', '%d'),
            array('%d')
        ) !== false;
    }

    /**
     * Mark item as completed
     *
     * @param int $queue_id Queue item ID
     * @param int $strings_total Total strings
     * @param int $strings_translated Translated strings
     * @return bool Success
     */
    public static function mark_completed($queue_id, $strings_total = 0, $strings_translated = 0) {
        global $wpdb;

        return $wpdb->update(
            self::get_table_name(),
            array(
                'status' => self::STATUS_COMPLETED,
                'strings_total' => $strings_total,
                'strings_translated' => $strings_translated,
                'completed_at' => current_time('mysql')
            ),
            array('id' => $queue_id),
            array('%s', '%d', '%d', '%s'),
            array('%d')
        ) !== false;
    }

    /**
     * Mark item as failed
     *
     * @param int $queue_id Queue item ID
     * @param string $error_message Error message
     * @return bool Success
     */
    public static function mark_failed($queue_id, $error_message = '') {
        global $wpdb;

        return $wpdb->update(
            self::get_table_name(),
            array(
                'status' => self::STATUS_FAILED,
                'error_message' => $error_message,
                'completed_at' => current_time('mysql')
            ),
            array('id' => $queue_id),
            array('%s', '%s', '%s'),
            array('%d')
        ) !== false;
    }

    /**
     * Get queue statistics
     *
     * @return array Statistics
     */
    public static function get_stats() {
        global $wpdb;

        $table = self::get_table_name();

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array(
                'total' => 0,
                'pending' => 0,
                'processing' => 0,
                'completed' => 0,
                'failed' => 0,
                'by_language' => array(),
                'by_post_type' => array()
            );
        }

        // Overall stats
        $stats = array(
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table"),
            'pending' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = %s",
                self::STATUS_PENDING
            )),
            'processing' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = %s",
                self::STATUS_PROCESSING
            )),
            'completed' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = %s",
                self::STATUS_COMPLETED
            )),
            'failed' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE status = %s",
                self::STATUS_FAILED
            ))
        );

        // Stats by language
        $by_language = $wpdb->get_results(
            "SELECT language_code, status, COUNT(*) as count
             FROM $table
             GROUP BY language_code, status",
            ARRAY_A
        );

        $stats['by_language'] = array();
        foreach ($by_language as $row) {
            if (!isset($stats['by_language'][$row['language_code']])) {
                $stats['by_language'][$row['language_code']] = array(
                    'pending' => 0,
                    'processing' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'total' => 0
                );
            }
            $stats['by_language'][$row['language_code']][$row['status']] = (int) $row['count'];
            $stats['by_language'][$row['language_code']]['total'] += (int) $row['count'];
        }

        // Stats by post type
        $by_post_type = $wpdb->get_results(
            "SELECT post_type, COUNT(*) as count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
             FROM $table
             GROUP BY post_type",
            ARRAY_A
        );

        $stats['by_post_type'] = array();
        foreach ($by_post_type as $row) {
            $stats['by_post_type'][$row['post_type']] = array(
                'total' => (int) $row['count'],
                'completed' => (int) $row['completed']
            );
        }

        return $stats;
    }

    /**
     * Get detailed status for each language
     * Includes total posts count for percentage calculation
     * v5.3.16: Added taxonomy terms to total count
     *
     * @return array Language status details
     */
    public static function get_language_status() {
        global $wpdb;

        $languages = get_option('lingua_languages', array());
        $default_language = get_option('lingua_default_language', 'ru');
        unset($languages[$default_language]);

        if (empty($languages)) {
            return array();
        }

        // v5.3.17: Use post types from settings
        $post_types = lingua_get_translatable_post_types();
        $post_types_placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $total_posts = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type IN ($post_types_placeholders)",
            ...$post_types
        ));

        // v5.3.16: Add taxonomy terms to total (product_cat, product_tag)
        $total_terms = self::get_total_terms_count(array('product_cat', 'product_tag'));
        $total_items = $total_posts + $total_terms;

        $table = self::get_table_name();
        $status = array();

        foreach ($languages as $code => $lang_data) {
            // Get queue stats for this language
            $completed = 0;
            $pending = 0;
            $failed = 0;

            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $completed = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE language_code = %s AND status = %s",
                    $code, self::STATUS_COMPLETED
                ));
                $pending = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE language_code = %s AND status IN (%s, %s)",
                    $code, self::STATUS_PENDING, self::STATUS_PROCESSING
                ));
                $failed = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table WHERE language_code = %s AND status = %s",
                    $code, self::STATUS_FAILED
                ));
            }

            $status[$code] = array(
                'name' => $lang_data['name'] ?? $code,
                'flag' => $lang_data['flag'] ?? '🌐',
                'total_posts' => $total_items, // v5.3.16: now includes terms
                'completed' => $completed,
                'pending' => $pending,
                'failed' => $failed,
                'percentage' => $total_items > 0 ? round(($completed / $total_items) * 100) : 0
            );
        }

        return $status;
    }

    /**
     * Get recent errors for display
     *
     * @param int $limit Number of errors to return
     * @return array Recent errors
     */
    public static function get_recent_errors($limit = 5) {
        global $wpdb;

        $table = self::get_table_name();

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return array();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, p.post_title
             FROM $table q
             LEFT JOIN {$wpdb->posts} p ON q.post_id = p.ID
             WHERE q.status = %s AND q.error_message IS NOT NULL
             ORDER BY q.completed_at DESC
             LIMIT %d",
            self::STATUS_FAILED,
            $limit
        ), ARRAY_A);
    }

    /**
     * Retry all failed items
     *
     * @return int Number of items reset
     */
    public static function retry_failed() {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "UPDATE " . self::get_table_name() . "
             SET status = %s, error_message = NULL, attempts = 0, created_at = %s
             WHERE status = %s",
            self::STATUS_PENDING,
            current_time('mysql'),
            self::STATUS_FAILED
        ));
    }

    /**
     * Clear completed items older than X days
     *
     * @param int $days Days to keep
     * @return int Number of items deleted
     */
    public static function cleanup($days = 30) {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::get_table_name() . "
             WHERE status = %s AND completed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::STATUS_COMPLETED,
            $days
        ));
    }

    /**
     * Populate queue with all existing posts
     * Used for initial "Translate Entire Site" action
     *
     * @param array $post_types Post types to include
     * @return int Number of items added
     */
    public static function populate_all_posts($post_types = array('post', 'page')) {
        global $wpdb;

        $languages = get_option('lingua_languages', array());
        $default_language = get_option('lingua_default_language', 'ru');
        unset($languages[$default_language]);

        if (empty($languages)) {
            return 0;
        }

        // Get all published posts
        $post_types_placeholders = implode(',', array_fill(0, count($post_types), '%s'));

        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type IN ($post_types_placeholders)
             ORDER BY post_date DESC",
            ...$post_types
        ));

        $added = 0;
        foreach ($posts as $post) {
            foreach (array_keys($languages) as $lang_code) {
                if (self::add_to_queue($post->ID, $lang_code, $post->post_type)) {
                    $added++;
                }
            }
        }

        return $added;
    }

    /**
     * Check if queue is paused
     *
     * @return bool Is paused
     */
    public static function is_paused() {
        return (bool) get_option('lingua_queue_paused', false);
    }

    /**
     * Pause queue processing
     */
    public static function pause() {
        update_option('lingua_queue_paused', true);
    }

    /**
     * Resume queue processing
     */
    public static function resume() {
        update_option('lingua_queue_paused', false);
    }

    /**
     * Check if auto-translate is enabled
     *
     * @return bool Is enabled
     */
    public static function is_auto_translate_enabled() {
        return (bool) get_option('lingua_auto_translate_website', false);
    }

    /**
     * v5.3.16: Populate queue with all taxonomy terms
     * Used for "Translate Entire Site" action to include product categories, tags, etc.
     *
     * @param array $taxonomies Taxonomies to include (e.g., ['product_cat', 'product_tag'])
     * @return int Number of items added
     */
    public static function populate_all_terms($taxonomies = array('product_cat')) {
        global $wpdb;

        $languages = get_option('lingua_languages', array());
        $default_language = get_option('lingua_default_language', 'ru');
        unset($languages[$default_language]);

        if (empty($languages)) {
            return 0;
        }

        $added = 0;

        foreach ($taxonomies as $taxonomy) {
            // Check if taxonomy exists
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            // Get all terms for this taxonomy
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids'
            ));

            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }

            // Use 'term_' prefix to distinguish from posts
            $post_type = 'term_' . $taxonomy;

            foreach ($terms as $term_id) {
                foreach (array_keys($languages) as $lang_code) {
                    if (self::add_to_queue($term_id, $lang_code, $post_type)) {
                        $added++;
                    }
                }
            }
        }

        return $added;
    }

    /**
     * v5.3.16: Check if queue item is a taxonomy term
     *
     * @param string $post_type The post_type field from queue
     * @return bool True if this is a term
     */
    public static function is_term_item($post_type) {
        return strpos($post_type, 'term_') === 0;
    }

    /**
     * v5.3.16: Get taxonomy from post_type field
     *
     * @param string $post_type The post_type field (e.g., 'term_product_cat')
     * @return string Taxonomy name (e.g., 'product_cat')
     */
    public static function get_taxonomy_from_type($post_type) {
        return substr($post_type, 5); // Remove 'term_' prefix
    }

    /**
     * v5.3.16: Get term count for language status
     *
     * @param array $taxonomies Taxonomies to count
     * @return int Total term count
     */
    public static function get_total_terms_count($taxonomies = array('product_cat')) {
        $total = 0;

        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }

            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'count'
            ));

            if (!is_wp_error($terms)) {
                $total += (int) $terms;
            }
        }

        return $total;
    }
}
