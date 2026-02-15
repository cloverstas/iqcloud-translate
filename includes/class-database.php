<?php
/**
 * Database handler for Lingua plugin
 *
 * @package Lingua
 * @version 2.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Database {

    // Translation status constants
    const NOT_TRANSLATED = 0;
    const MACHINE_TRANSLATED = 1;
    const HUMAN_REVIEWED = 2;
    const SIMILAR_TRANSLATED = 3;

    // Block type constants
    const BLOCK_TYPE_REGULAR_STRING = 0;
    const BLOCK_TYPE_ACTIVE = 1;
    const BLOCK_TYPE_DEPRECATED = 2;

    /**
     * Create database tables
     * v5.2.76: Added plural_form and original_plural columns for plural handling
     * v5.2.2: Removed legacy tables (lingua_translations, lingua_translation_meta) - now using unified architecture only
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // String translations table (v2.0 architecture)
        $string_table_name = $wpdb->prefix . 'lingua_string_translations';

        $sql = "CREATE TABLE $string_table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            original_text text NOT NULL,
            original_text_hash varchar(32) NOT NULL,
            translated_text text,
            language_code varchar(5) NOT NULL,
            context varchar(255) DEFAULT 'general',
            source varchar(20) DEFAULT 'custom' COMMENT 'gettext or custom',
            gettext_domain varchar(50) DEFAULT NULL COMMENT 'woodmart, woocommerce, etc',
            plural_form int(20) DEFAULT NULL COMMENT 'NULL for single strings, 0/1/2 for plural forms',
            original_plural text DEFAULT NULL COMMENT 'Plural counterpart msgid (e.g. %d items)',
            block_type int(20) DEFAULT 0 COMMENT '0=REGULAR_STRING, 1=ACTIVE_BLOCK, 2=DEPRECATED_BLOCK',
            original_id bigint(20) DEFAULT NULL COMMENT 'FK to original_strings table',
            status int(20) DEFAULT 0 COMMENT '0=NOT_TRANSLATED, 1=MACHINE, 2=HUMAN_REVIEWED, 3=SIMILAR',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_string (original_text_hash, language_code, context, plural_form),
            KEY idx_language (language_code),
            KEY idx_context (context),
            KEY idx_source (source),
            KEY idx_gettext_domain (gettext_domain),
            KEY idx_plural_form (plural_form),
            KEY idx_status (status),
            KEY idx_block_type (block_type),
            KEY idx_original_id (original_id),
            FULLTEXT KEY lingua_original_fulltext (original_text)
        ) $charset_collate;";

        dbDelta($sql);

        // v5.2.76: Fix UNIQUE KEY for plural forms - dbDelta doesn't handle KEY changes well
        // Check if old 3-column UNIQUE KEY exists and replace with 4-column version
        $indexes = $wpdb->get_results("SHOW INDEX FROM $string_table_name WHERE Key_name = 'unique_string'", ARRAY_A);
        if (!empty($indexes)) {
            $has_plural_form_in_key = false;
            foreach ($indexes as $index) {
                if ($index['Column_name'] === 'plural_form') {
                    $has_plural_form_in_key = true;
                    break;
                }
            }

            // If plural_form is NOT in the UNIQUE KEY, recreate it
            if (!$has_plural_form_in_key) {
                $wpdb->query("ALTER TABLE $string_table_name DROP INDEX unique_string");
                $wpdb->query("ALTER TABLE $string_table_name ADD UNIQUE KEY unique_string (original_text_hash, language_code, context, plural_form)");
            }
        }

        // Original strings table (for deduplication)
        $original_table_name = $wpdb->prefix . 'lingua_original_strings';

        $sql = "CREATE TABLE $original_table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            original text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY lingua_idx_original (original(100))
        ) $charset_collate;";

        dbDelta($sql);

        // Update version
        update_option('lingua_db_version', '2.3.0');  // v5.2.76: Added plural_form and original_plural columns
    }

    /**
     * Drop database tables
     * v5.2.2: Removed legacy tables - only drop active unified tables
     */
    public static function drop_tables() {
        global $wpdb;

        $tables = array(
            $wpdb->prefix . 'lingua_string_translations',
            $wpdb->prefix . 'lingua_original_strings'
        );

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS `$table`");
        }

        delete_option('lingua_db_version');
    }

    /**
     * Check if tables exist
     */
    public static function tables_exist() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'lingua_string_translations';

        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
    }

    /**
     * Get status constant name for display
     */
    public static function get_status_name($status) {
        switch ($status) {
            case self::NOT_TRANSLATED:
                return 'Not Translated';
            case self::MACHINE_TRANSLATED:
                return 'Machine Translated';
            case self::HUMAN_REVIEWED:
                return 'Human Reviewed';
            case self::SIMILAR_TRANSLATED:
                return 'Similar Translation';
            default:
                return 'Unknown';
        }
    }
}