<?php
/**
 * Lingua Custom Capabilities Management
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Capabilities {
    
    /**
     * Initialize capabilities
     */
    public static function init() {
        register_activation_hook(LINGUA_PLUGIN_BASENAME, array(__CLASS__, 'add_capabilities'));
        register_deactivation_hook(LINGUA_PLUGIN_BASENAME, array(__CLASS__, 'remove_capabilities'));
        
        // Add capabilities on plugin update/activation if they don't exist
        add_action('plugins_loaded', array(__CLASS__, 'maybe_add_capabilities'));
    }
    
    /**
     * Add capabilities if they don't exist (for existing installations)
     */
    public static function maybe_add_capabilities() {
        $admin_role = get_role('administrator');
        if ($admin_role && !$admin_role->has_cap('manage_lingua_settings')) {
            self::add_capabilities();
        }
    }
    
    /**
     * Add custom capabilities on plugin activation
     */
    public static function add_capabilities() {
        $capabilities = self::get_lingua_capabilities();
        
        // Add capabilities to Administrator role
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach ($capabilities as $cap) {
                $admin_role->add_cap($cap);
            }
        }
        
        // Add translation capabilities to Editor role
        $editor_role = get_role('editor');
        if ($editor_role) {
            $editor_role->add_cap('translate_content');
            $editor_role->add_cap('manage_translations');
        }
        
        // Add basic translation capability to Author role
        $author_role = get_role('author');
        if ($author_role) {
            $author_role->add_cap('translate_content');
        }
    }
    
    /**
     * Remove capabilities on plugin deactivation
     */
    public static function remove_capabilities() {
        $capabilities = self::get_lingua_capabilities();
        
        // Remove from all roles
        $roles = wp_roles()->roles;
        foreach ($roles as $role_name => $role_info) {
            $role = get_role($role_name);
            if ($role) {
                foreach ($capabilities as $cap) {
                    $role->remove_cap($cap);
                }
            }
        }
    }
    
    /**
     * Get all Lingua capabilities
     */
    public static function get_lingua_capabilities() {
        return array(
            'translate_content',        // Basic translation capability
            'manage_translations',      // Manage existing translations
            'manage_lingua_settings',   // Access plugin settings
            'manage_lingua_api',        // Manage API credentials
            'bulk_translate',          // Bulk translation operations
            'delete_translations'      // Delete translations
        );
    }
    
    /**
     * Check if user can translate content
     */
    public static function can_translate() {
        return current_user_can('translate_content');
    }
    
    /**
     * Check if user can manage translations
     */
    public static function can_manage_translations() {
        return current_user_can('manage_translations');
    }
    
    /**
     * Check if user can access settings
     */
    public static function can_manage_settings() {
        return current_user_can('manage_lingua_settings');
    }
    
    /**
     * Check if user can manage API
     */
    public static function can_manage_api() {
        return current_user_can('manage_lingua_api');
    }
    
    /**
     * Check if user can bulk translate
     */
    public static function can_bulk_translate() {
        return current_user_can('bulk_translate');
    }
    
    /**
     * Check if user can delete translations
     */
    public static function can_delete_translations() {
        return current_user_can('delete_translations');
    }
}