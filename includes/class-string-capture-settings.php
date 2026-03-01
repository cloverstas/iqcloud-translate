<?php
/**
 * String Capture Settings
 * Configurable settings for universal string capture
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_String_Capture_Settings {
    
    /**
     * Get default settings
     */
    public static function get_defaults() {
        return array(
            'min_string_length' => 2,
            'max_string_length' => 500,
            'skip_admin_bar' => true,
            'skip_logged_in_strings' => true,
            'capture_attributes' => true,
            'capture_placeholders' => true,
            'auto_detect_admin_strings' => true
        );
    }
    
    /**
     * Get string capture settings
     */
    public static function get_settings() {
        $defaults = self::get_defaults();
        $settings = get_option('lingua_string_capture_settings', array());
        
        return wp_parse_args($settings, $defaults);
    }
    
    /**
     * Check if string should be captured based on settings
     */
    public static function should_capture_string($text, $context = 'text_node') {
        $settings = self::get_settings();
        
        // Length checks
        $length = strlen($text);
        if ($length < $settings['min_string_length'] || $length > $settings['max_string_length']) {
            return false;
        }
        
        // Context-specific checks
        if ($context === 'admin_bar' && $settings['skip_admin_bar']) {
            return false;
        }
        
        // Apply custom filters
        return apply_filters('lingua_should_capture_string', true, $text, $context);
    }
    
    /**
     * Get strings that should always be skipped
     */
    public static function get_skip_patterns() {
        $default_patterns = array(
            '/^[0-9\s\-\+\(\)]+$/', // Phone numbers
            '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', // Emails
            '/^https?:\/\//', // URLs
            '/^#[a-fA-F0-9]{3,6}$/', // Hex colors
            '/^\d+px$/', // CSS values
            '/^[\d\s\-\/]+$/', // Dates
        );
        
        return apply_filters('lingua_skip_string_patterns', $default_patterns);
    }
    
    /**
     * Check if string matches skip patterns
     */
    public static function matches_skip_pattern($text) {
        $patterns = self::get_skip_patterns();
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        
        return false;
    }
}

// Add settings to admin
add_action('admin_init', function() {
    register_setting('lingua_settings', 'lingua_string_capture_settings', array(
        'type' => 'array',
        'sanitize_callback' => function($input) {
            if (!is_array($input)) {
                return Lingua_String_Capture_Settings::get_defaults();
            }
            $defaults = Lingua_String_Capture_Settings::get_defaults();
            $sanitized = array();
            $sanitized['min_string_length'] = isset($input['min_string_length']) ? absint($input['min_string_length']) : $defaults['min_string_length'];
            $sanitized['max_string_length'] = isset($input['max_string_length']) ? absint($input['max_string_length']) : $defaults['max_string_length'];
            $sanitized['skip_admin_bar'] = !empty($input['skip_admin_bar']);
            $sanitized['skip_logged_in_strings'] = !empty($input['skip_logged_in_strings']);
            $sanitized['capture_attributes'] = !empty($input['capture_attributes']);
            $sanitized['capture_placeholders'] = !empty($input['capture_placeholders']);
            $sanitized['auto_detect_admin_strings'] = !empty($input['auto_detect_admin_strings']);
            return $sanitized;
        },
        'default' => Lingua_String_Capture_Settings::get_defaults(),
    ));
    
    add_settings_section(
        'lingua_string_capture',
        __('String Capture Settings', 'iqcloud-translate'),
        null,
        'lingua-settings'
    );
    
    add_settings_field(
        'min_string_length',
        __('Minimum String Length', 'iqcloud-translate'),
        function() {
            $settings = Lingua_String_Capture_Settings::get_settings();
            echo '<input type="number" name="lingua_string_capture_settings[min_string_length]" value="' . $settings['min_string_length'] . '" min="1" max="50">';
            echo '<p class="description">' . esc_html__('Minimum number of characters for a string to be captured', 'iqcloud-translate') . '</p>';
        },
        'lingua-settings',
        'lingua_string_capture'
    );
    
    add_settings_field(
        'max_string_length',
        __('Maximum String Length', 'iqcloud-translate'),
        function() {
            $settings = Lingua_String_Capture_Settings::get_settings();
            echo '<input type="number" name="lingua_string_capture_settings[max_string_length]" value="' . $settings['max_string_length'] . '" min="50" max="5000">';
            echo '<p class="description">' . esc_html__('Maximum number of characters for a string to be captured', 'iqcloud-translate') . '</p>';
        },
        'lingua-settings',
        'lingua_string_capture'
    );
    
    add_settings_field(
        'skip_admin_bar',
        __('Skip Admin Bar', 'iqcloud-translate'),
        function() {
            $settings = Lingua_String_Capture_Settings::get_settings();
            echo '<input type="checkbox" name="lingua_string_capture_settings[skip_admin_bar]" value="1" ' . checked($settings['skip_admin_bar'], true, false) . '>';
            echo '<p class="description">' . esc_html__('Do not capture strings from WordPress admin bar', 'iqcloud-translate') . '</p>';
        },
        'lingua-settings',
        'lingua_string_capture'
    );
});