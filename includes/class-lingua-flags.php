<?php
/**
 * Flags helper class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Flags {
    
    /**
     * Get flag HTML for display
     */
    public static function get_flag_html($country_code, $type = 'emoji') {
        $country_code = strtolower($country_code);
        
        if ($type === 'emoji') {
            // v5.2.64: Use centralized language list (single source of truth)
            if (class_exists('Lingua_Languages')) {
                $all_languages = Lingua_Languages::get_all();
                if (isset($all_languages[$country_code]['flag'])) {
                    return $all_languages[$country_code]['flag'];
                }
            }
            
            return '🌐';
        } elseif ($type === 'image') {
            // Use local flag SVG images bundled with the plugin
            $country_iso = self::get_country_iso($country_code);
            $flag_url = plugins_url('public/css/flags/4x3/' . $country_iso . '.svg', dirname(__FILE__));
            return '<img src="' . esc_url($flag_url) . '" alt="' . esc_attr($country_code) . '" class="lingua-flag-img" width="16" height="12" />';
        } elseif ($type === 'css') {
            // CSS class for flag sprites
            return '<span class="lingua-flag lingua-flag-' . esc_attr($country_code) . '"></span>';
        }
        
        return '';
    }
    
    /**
     * Convert language code to country ISO code
     */
    private static function get_country_iso($lang_code) {
        // Basic mapping for most common cases
        $basic_map = array(
            'en' => 'us',
            'zh' => 'cn',
            'ja' => 'jp',
            'ko' => 'kr'
        );
        
        return isset($basic_map[$lang_code]) ? $basic_map[$lang_code] : $lang_code;
    }
    
    /**
     * Get flag for select option (text-based)
     */
    public static function get_flag_text($country_code) {
        $country_code = strtolower($country_code);
        
        // Convert to uppercase country code
        $iso = strtoupper(self::get_country_iso($country_code));
        
        return '[' . $iso . ']';
    }
}