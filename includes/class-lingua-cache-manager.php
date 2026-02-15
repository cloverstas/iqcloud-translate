<?php
/**
 * Lingua Cache Manager
 *
 * Manages caching for translations
 *
 * @package Lingua
 * @version 5.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Cache_Manager {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Initialize cache manager
    }

    /**
     * Get cached translation
     */
    public function get_translation($key, $language) {
        return get_transient("lingua_cache_{$language}_{$key}");
    }

    /**
     * Set cached translation
     */
    public function set_translation($key, $language, $value, $expiration = HOUR_IN_SECONDS) {
        return set_transient("lingua_cache_{$language}_{$key}", $value, $expiration);
    }

    /**
     * Clear cache
     */
    public function clear_cache($language = null) {
        global $wpdb;

        if ($language) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_lingua_cache_' . $language . '_%'
            ));
        } else {
            $wpdb->query(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_cache_%'"
            );
        }

        return true;
    }

    /**
     * Clear all Lingua transients
     */
    public function clear_all_transients() {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_lingua_%'
             OR option_name LIKE '_transient_timeout_lingua_%'"
        );

        return true;
    }
}
