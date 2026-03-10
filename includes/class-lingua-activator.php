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
        $site_lang = lingua_get_site_language();
        add_option('lingua_default_language', $site_lang);

        // Build default language entry from WP locale
        $lang_info = Lingua_Languages::get($site_lang);
        $default_lang_name = !empty($lang_info['name']) ? $lang_info['name'] : $site_lang;
        $default_lang_native = !empty($lang_info['native']) ? $lang_info['native'] : $default_lang_name;
        add_option('lingua_languages', array(
            $site_lang => array(
                'name' => $default_lang_name,
                'native_name' => $default_lang_native,
                'flag' => ''
            )
        ));
        
        // Translation settings
        add_option('lingua_translation_quality', 'balanced');
        
        // Language switcher settings
        add_option('lingua_switcher_display_format', 'flags_with_full_names');
        add_option('lingua_switcher_show_current', true);
        add_option('lingua_switcher_enable_shortcode', true);
        add_option('lingua_switcher_enable_menu', true);
        add_option('lingua_switcher_enable_floating', false);
    }
}