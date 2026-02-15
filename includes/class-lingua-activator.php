<?php
/**
 * Fired during plugin activation
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Activator {
    
    public static function activate() {
        // Create database tables
        require_once LINGUA_PLUGIN_DIR . 'includes/class-database.php';
        Lingua_Database::create_tables();
        
        // Set default options
        self::set_default_options();
        
        // Clear permalinks
        flush_rewrite_rules();
    }
    
    private static function set_default_options() {
        // Basic settings
        add_option('lingua_version', LINGUA_VERSION);
        add_option('lingua_default_language', 'ru');
        add_option('lingua_languages', array(
            'ru' => array(
                'name' => 'Русский',
                'native_name' => 'Русский',
                'flag' => '🇷🇺'
            )
        ));
        
        // v5.2.174: Yandex API removed - using Middleware API only
        // API key is now lingua_middleware_api_key

        // Translation settings
        add_option('lingua_auto_translate_posts', false);
        add_option('lingua_auto_translate_pages', false);
        add_option('lingua_auto_translate_seo', false);
        add_option('lingua_translation_quality', 'balanced');
        
        // Language switcher settings
        add_option('lingua_switcher_display_format', 'flags_with_full_names');
        add_option('lingua_switcher_show_current', true);
        add_option('lingua_switcher_enable_shortcode', true);
        add_option('lingua_switcher_enable_menu', true);
        add_option('lingua_switcher_enable_floating', false);
    }
}