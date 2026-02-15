<?php
/**
 * Fired during plugin deactivation
 */
class Lingua_Deactivator {
    
    public static function deactivate() {
        // Clear any scheduled events
        wp_clear_scheduled_hook('lingua_daily_cleanup');
        
        // Flush cache
        wp_cache_flush();
        
        // Clear permalinks
        flush_rewrite_rules();
    }
}