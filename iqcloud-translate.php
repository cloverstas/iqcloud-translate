<?php
/**
 * Plugin Name: IQCloud Translate
 * Description: Powerful multilingual translation toolkit with visual editor, auto-translation, and WooCommerce support.
 * Version: 1.0.10
 * Author: YourNewSite
 * Author URI: https://yournewsite.ru
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: iqcloud-translate
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
// v1.0.0: Initial public release with HTML structure preservation, WooCommerce support, and visual editor
define('LINGUA_VERSION', trim(file_get_contents(__DIR__ . '/VERSION')));
define('LINGUA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LINGUA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LINGUA_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('LINGUA_MIDDLEWARE_URL', 'https://translate.yournewsite.ru'); // Middleware API URL (hardcoded)

/**
 * v5.3.20: Debug logging control
 * Priority order:
 * 1. LINGUA_DEBUG constant (if defined in wp-config.php) - always respected
 * 2. lingua_debug_mode WordPress option (toggled in admin settings)
 *
 * This allows developers to override via wp-config.php, while admins can toggle in UI.
 */
function lingua_is_debug_enabled() {
    // If LINGUA_DEBUG constant is explicitly defined (e.g., in wp-config.php), use it
    if (defined('LINGUA_DEBUG')) {
        return LINGUA_DEBUG;
    }

    // Otherwise, check WordPress option (default: false)
    // Use static cache to avoid repeated DB queries
    static $debug_enabled = null;
    if ($debug_enabled === null) {
        $debug_enabled = (bool) get_option('lingua_debug_mode', false);
    }
    return $debug_enabled;
}

/**
 * v5.3.4: Conditional debug logging to prevent nginx "upstream sent too big header" error
 * v5.3.20: Now reads setting from WordPress option (Admin Settings > Translation > Debug Mode)
 */
function lingua_debug_log($message) {
    if (lingua_is_debug_enabled()) {
        error_log($message);
    }
}

// Define default capabilities with filters
/**
 * Get the site's default language code (2-letter ISO 639-1) from WordPress locale.
 * Used as fallback when lingua_default_language option is not set.
 *
 * @return string Language code (e.g. 'en', 'ru', 'de')
 */
function lingua_get_site_language() {
    $locale = get_locale();
    return substr($locale, 0, 2);
}

function lingua_settings_capability()
{
    return apply_filters('lingua_settings_capability', 'manage_options');
}

function lingua_translating_capability()
{
    return apply_filters('lingua_translating_capability', 'edit_posts');
}

/**
 * v5.3.17: Get translatable post types from settings
 * Returns array of post type names that should be translated
 *
 * @return array Post type names
 */
function lingua_get_translatable_post_types()
{
    $saved = get_option('lingua_translatable_post_types', array());

    // Default to post, page, product if not set
    if (empty($saved)) {
        $saved = array('post', 'page', 'product');
    }

    return apply_filters('lingua_translatable_post_types', $saved);
}

/**
 * v5.3.17: Get available post types for settings checkboxes
 * Filters out internal WordPress types
 *
 * @return array Post type objects
 */
function lingua_get_available_post_types()
{
    $all_types = get_post_types(array('public' => true), 'objects');

    // Exclude internal types
    $exclude = array(
        'attachment',
        'revision',
        'nav_menu_item',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
        'wp_template',
        'wp_template_part',
        'wp_global_styles',
        'wp_navigation',
        'wp_font_family',
        'wp_font_face',
        'acf-field',
        'acf-field-group',
        'lingua_switcher'
    );

    $available = array();
    foreach ($all_types as $name => $type) {
        if (!in_array($name, $exclude)) {
            $available[$name] = $type;
        }
    }

    return $available;
}

// v1.0.6: CORE FILES ONLY - loaded on every request (lightweight)
require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-languages.php'; // v1.0.5: Load early so Lingua_Languages is available during activation

// v1.0.6: Defer loading class-lingua.php for non-lingua AJAX to save ~30MB memory
// On a 1GB VPS, this prevents heartbeat/WooCommerce AJAX from loading unnecessary code
if (!(defined('DOING_AJAX') && DOING_AJAX) || lingua_is_our_ajax_request_early()) {
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua.php';
}

/**
 * v1.0.6: Early check for lingua AJAX - called before plugins_loaded
 * Uses raw $_REQUEST since sanitize functions may not be available yet
 */
function lingua_is_our_ajax_request_early() {
    if (!(defined('DOING_AJAX') && DOING_AJAX)) {
        return false;
    }
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    return (strpos($action, 'lingua_') === 0);
}

/**
 * v1.0.6: Lazy loading system for heavy components
 * These files are loaded ON DEMAND to reduce memory footprint.
 * On weak servers (256MB PHP memory_limit), loading all ~37 files at once
 * combined with WooCommerce + themes exceeds the memory limit.
 *
 * Files are grouped by when they're needed:
 * - Frontend rendering: HTML DOM parser, output buffer, DOM extractor, content filter
 * - Admin features: gettext scan, string capture settings, plural forms
 * - Auto-translation: auto-translator (only during cron/queue processing)
 * - Search: search integration (only when search query detected)
 */

/**
 * Load v2.0 frontend rendering components (heavy files)
 * Called only on template_redirect (frontend page rendering)
 */
function lingua_load_frontend_components() {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-cache-manager.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-dom-parser.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-string-filter-engine.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-string-capture.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/lib/lingua-html-dom.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-full-dom-extractor.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-output-buffer.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-content-filter.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-media-replacer.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-search.php';

    lingua_debug_log('[Lingua v1.0.6] Frontend components loaded (lazy)');
}

/**
 * Load admin-specific heavy components
 * Called only on admin pages (not AJAX)
 */
function lingua_load_admin_components() {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-plural-forms.php';
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-gettext-scan.php';
    // Load string capture + cache manager for admin settings
    if (!class_exists('Lingua_Cache_Manager')) {
        require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-cache-manager.php';
    }
    if (!class_exists('Lingua_String_Capture')) {
        require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-string-capture.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-dom-parser.php';
        require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-string-filter-engine.php';
    }

    lingua_debug_log('[Lingua v1.0.6] Admin components loaded (lazy)');
}

/**
 * Load auto-translator component
 * Called only when queue processing is needed
 */
function lingua_load_auto_translator() {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;

    // Auto-translator needs DOM components
    lingua_load_frontend_components();
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-auto-translator.php';

    lingua_debug_log('[Lingua v1.0.6] Auto-translator loaded (lazy)');
}

// v5.2.179: Add custom cron interval for translation queue
add_filter('cron_schedules', function($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display' => 'Every Minute'
    );
    return $schedules;
});

/**
 * v1.0.6: Check if current AJAX request is related to our plugin
 * If not, we can skip heavy initialization entirely.
 */
function lingua_is_our_ajax_request() {
    if (!(defined('DOING_AJAX') && DOING_AJAX)) {
        return false; // Not AJAX at all
    }
    $action = isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '';
    // Our AJAX actions all start with 'lingua_'
    return (strpos($action, 'lingua_') === 0);
}

// Initialize the plugin - Architecture v2.0
function lingua_init()
{
    global $lingua;

    // v1.0.6: For non-lingua AJAX requests (heartbeat, WooCommerce, etc.),
    // skip ALL plugin initialization to save memory.
    // On a 1GB VPS with WooCommerce + WoodMart, every MB counts.
    if (defined('DOING_AJAX') && DOING_AJAX && !lingua_is_our_ajax_request()) {
        lingua_debug_log('[Lingua v1.0.6] Skipping init for non-lingua AJAX: ' . ($_REQUEST['action'] ?? 'unknown'));
        return;
    }

    // v1.0.6: For WP-CLI, skip initialization entirely
    if (defined('WP_CLI') && WP_CLI) {
        return;
    }

    $url = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));
    lingua_debug_log("[Lingua v1.0.6] ⚡ lingua_init() called for URL: {$url}");

    $lingua = new Lingua();
    $lingua->run();

    // v1.0.6: Auto-enable v2.0 pipeline ONCE (not on every request)
    if (!get_option('lingua_enable_v2_pipeline', false)) {
        update_option('lingua_enable_v2_pipeline', true);
        lingua_debug_log('[Lingua v1.0.6] v2.0 pipeline enabled (one-time)');
    }

    lingua_debug_log('[Lingua v1.0.6] Plugin initialized');
}
add_action('plugins_loaded', 'lingua_init');

// Force upgrade check
function lingua_force_upgrade()
{
    $current_version = get_option('lingua_version', '1.0.0');
    if (version_compare($current_version, LINGUA_VERSION, '<')) {
        // Run upgrade process - recreate tables
        require_once LINGUA_PLUGIN_DIR . 'includes/class-database.php';
        Lingua_Database::create_tables();

        // Update version
        update_option('lingua_version', LINGUA_VERSION);

        // Clear any cached data
        wp_cache_flush();

        // Force admin menu refresh
        delete_transient('lingua_admin_menu_cache');

        lingua_debug_log("Lingua: Force upgrade completed to version " . LINGUA_VERSION);
    }
}
add_action('admin_init', 'lingua_force_upgrade');

// Activation hook
register_activation_hook(__FILE__, 'lingua_activate');
function lingua_activate()
{
    // Create necessary database tables or options
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-activator.php';
    Lingua_Activator::activate();
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'lingua_deactivate');
function lingua_deactivate()
{
    require_once LINGUA_PLUGIN_DIR . 'includes/class-lingua-deactivator.php';
    Lingua_Deactivator::deactivate();
}