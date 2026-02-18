<?php
/**
 * Gettext Scan Class
 *
 * Scans PHP files in themes and plugins for translatable gettext strings
 *
 * @package Lingua
 * @since 5.2.187
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Gettext_Scan {

    /**
     * Global variable for discovered strings
     */
    private $strings_discovered = array();

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        add_action('wp_ajax_lingua_scan_gettext', array($this, 'ajax_scan_gettext'));
        add_action('wp_ajax_lingua_rescan_gettext', array($this, 'ajax_rescan_gettext'));
    }

    /**
     * AJAX handler for scanning gettext strings
     */
    public function ajax_scan_gettext() {
        if (!check_ajax_referer('lingua_admin_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $result = $this->scan();
        wp_send_json_success($result);
    }

    /**
     * AJAX handler for rescanning (clear and rescan)
     */
    public function ajax_rescan_gettext() {
        if (!check_ajax_referer('lingua_admin_nonce', 'nonce', false)) {
            wp_send_json_error('Security check failed');
            return;
        }

        if (!current_user_can(lingua_settings_capability())) {
            wp_send_json_error('Unauthorized');
            return;
        }

        // Clear existing gettext strings
        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';
        $wpdb->query("DELETE FROM $table WHERE source = 'gettext'");

        // Reset scan progress
        delete_option('lingua_gettext_scan_progress');

        $result = $this->scan();
        wp_send_json_success($result);
    }

    /**
     * Main scan function - processes files incrementally
     *
     * @return array Status of scan operation
     */
    public function scan() {
        global $lingua_gettext_strings_discovered;

        // Load potx library
        require_once LINGUA_PLUGIN_DIR . 'includes/lib/potx/potx.php';

        $start_time = microtime(true);
        $scan_progress = get_option('lingua_gettext_scan_progress', array(
            'paths_completed' => 0,
            'current_filename' => null
        ));

        $paths_to_scan = apply_filters('lingua_paths_to_scan_for_gettext',
            array_merge($this->get_active_plugins_paths(), $this->get_active_theme_paths())
        );

        $lingua_gettext_strings_discovered = array();
        $filename = '';

        foreach ($paths_to_scan as $path_key => $path) {
            // Skip already completed paths
            if ($path_key < $scan_progress['paths_completed']) {
                continue;
            }

            $interrupted_in_recursive_scan = false;

            if (is_file($path)) {
                lingua_potx_process_file(realpath($path), 0, 'lingua_save_gettext_string');
            } elseif (is_dir($path)) {
                try {
                    $iterator = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);

                    foreach (new RecursiveIteratorIterator($iterator) as $filename => $current_file) {
                        // Resume from interrupted position
                        if ($scan_progress['current_filename']) {
                            if ($filename == $scan_progress['current_filename']) {
                                $scan_progress['current_filename'] = null;
                            }
                            continue;
                        }

                        if (isset($current_file)) {
                            $pathinfo = pathinfo($current_file);

                            if (!empty($pathinfo['extension']) && $pathinfo['extension'] === 'php') {
                                if (file_exists($current_file)) {
                                    lingua_potx_process_file(realpath($current_file), 0, 'lingua_save_gettext_string');

                                    // Timeout check - break every 2 seconds to prevent request timeout
                                    if ((microtime(true) - $start_time) > 2) {
                                        $path_key--;
                                        $interrupted_in_recursive_scan = true;
                                        break;
                                    }
                                }
                            }
                        }
                    }
                } catch (Exception $e) {
                    lingua_debug_log('[Lingua Gettext Scan] Error scanning path: ' . $path . ' - ' . $e->getMessage());
                }
            }

            if ((microtime(true) - $start_time) > 2) {
                $filename = $interrupted_in_recursive_scan ? $filename : '';
                break;
            }
        }

        // Insert discovered strings into database
        $this->insert_gettext_in_db($lingua_gettext_strings_discovered);

        $paths_completed = $path_key + 1;
        $total_paths = count($paths_to_scan);

        $return_array = array(
            'completed' => false,
            'progress' => round(($paths_completed / $total_paths) * 100),
            'progress_message' => sprintf(
                __('Scanning item %1$d of %2$d...', 'linguateq'),
                $paths_completed,
                $total_paths
            ),
            'strings_found' => count($lingua_gettext_strings_discovered)
        );

        if ($paths_completed >= $total_paths) {
            delete_option('lingua_gettext_scan_progress');
            $return_array['completed'] = true;
            $return_array['progress_message'] = __('Scan completed!', 'linguateq');
        } else {
            update_option('lingua_gettext_scan_progress', array(
                'paths_completed' => $paths_completed,
                'current_filename' => $filename
            ));
        }

        return $return_array;
    }

    /**
     * Get paths to active plugins
     *
     * @return array
     */
    public function get_active_plugins_paths() {
        $active_plugins = get_option('active_plugins', array());
        $folders = array();

        foreach ($active_plugins as $plugin) {
            $parts = explode('/', $plugin);
            if (isset($parts[0])) {
                $plugin_path = trailingslashit(WP_PLUGIN_DIR) . $parts[0];
                if (is_dir($plugin_path)) {
                    $folders[] = $plugin_path;
                }
            }
        }

        return $folders;
    }

    /**
     * Get paths to active themes (child + parent)
     *
     * @return array
     */
    public function get_active_theme_paths() {
        $folders = array();

        // Child theme (or main theme if no child)
        $child_theme_dir = get_stylesheet_directory();
        $folders[] = $child_theme_dir;

        // Parent theme (if different from child)
        $parent_theme_dir = get_template_directory();
        if ($parent_theme_dir !== $child_theme_dir) {
            $folders[] = $parent_theme_dir;
        }

        return $folders;
    }

    /**
     * Insert discovered gettext strings into database
     * v5.2.188: Improved plural forms handling - creates all plural form rows per language
     *
     * @param array $strings_discovered
     */
    public function insert_gettext_in_db($strings_discovered) {
        global $wpdb;

        if (empty($strings_discovered)) {
            return;
        }

        $table = $wpdb->prefix . 'lingua_string_translations';
        $languages = get_option('lingua_languages', array());
        $default_lang = get_option('lingua_default_language', 'ru');

        // Get plural forms handler
        $plural_handler = class_exists('Lingua_Plural_Forms') ? Lingua_Plural_Forms::get_instance() : null;

        // Email path patterns for detection
        $email_paths = apply_filters('lingua_email_paths', array(
            'templates/emails/',
            'includes/emails/',
            'woocommerce/emails/',
            '/emails/',
            'email-templates/'
        ));

        // Windows paths
        $reverse_paths = array();
        foreach ($email_paths as $path) {
            $reverse_paths[] = str_replace('/', '\\', $path);
        }
        $email_paths = array_merge($email_paths, $reverse_paths);

        $inserted = 0;

        foreach ($strings_discovered as $key => $string) {
            if (empty($string['original'])) {
                continue;
            }

            $original_text = $string['original'];
            $hash = md5($original_text);
            $domain = !empty($string['domain']) ? $string['domain'] : 'default';
            $file = !empty($string['file']) ? $string['file'] : '';
            $has_plural = !empty($string['original_plural']);
            $plural_text = $has_plural ? $string['original_plural'] : null;

            // Detect if string is in email template
            $is_email = false;
            foreach ($email_paths as $email_path) {
                if (strpos($file, $email_path) !== false) {
                    $is_email = true;
                    break;
                }
            }

            $context_base = 'gettext.' . $domain . ($is_email ? '.email' : '');

            // Insert for each target language (not default)
            foreach (array_keys($languages) as $lang_code) {
                if ($lang_code === $default_lang) {
                    continue;
                }

                if ($has_plural && $plural_handler) {
                    // This is a plural string - create rows for all plural forms
                    // v5.2.198: Use simple hash md5($original_text) - UNIQUE KEY already includes plural_form
                    $nplurals = $plural_handler->get_number_of_plural_forms($lang_code);

                    for ($form = 0; $form < $nplurals; $form++) {
                        // original_text is ALWAYS the singular (msgid) - same for all forms!
                        // plural_form distinguishes between forms (0, 1, 2...)
                        // original_plural stores the plural text (msgid_plural)
                        // v5.2.198: CRITICAL FIX - Use simple hash to match modal save
                        // UNIQUE KEY is (original_text_hash, language_code, context, plural_form)
                        // No need to include plural_form in hash - it's already in UNIQUE KEY

                        // Check if already exists using simple hash
                        $exists = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM $table WHERE original_text_hash = %s AND language_code = %s AND context = %s AND plural_form = %d",
                            $hash,
                            $lang_code,
                            $context_base,
                            $form
                        ));

                        if (!$exists) {
                            $wpdb->insert(
                                $table,
                                array(
                                    'original_text' => $original_text,      // ALWAYS singular (msgid)!
                                    'original_text_hash' => $hash,          // v5.2.198: Simple hash!
                                    'translated_text' => null,
                                    'language_code' => $lang_code,
                                    'context' => $context_base,             // No .plural suffix - use plural_form column
                                    'source' => 'gettext',
                                    'gettext_domain' => $domain,
                                    'plural_form' => $form,                 // 0, 1, 2... distinguishes forms
                                    'original_plural' => $plural_text,      // ALWAYS the plural text (msgid_plural)
                                    'status' => 0
                                ),
                                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d')
                            );
                            $inserted++;
                        }
                    }
                } else {
                    // Regular non-plural string
                    $exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM $table WHERE original_text_hash = %s AND language_code = %s AND context = %s AND plural_form IS NULL",
                        $hash,
                        $lang_code,
                        $context_base
                    ));

                    if (!$exists) {
                        $wpdb->insert(
                            $table,
                            array(
                                'original_text' => $original_text,
                                'original_text_hash' => $hash,
                                'translated_text' => null,
                                'language_code' => $lang_code,
                                'context' => $context_base,
                                'source' => 'gettext',
                                'gettext_domain' => $domain,
                                'plural_form' => null,
                                'status' => 0
                            ),
                            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d')
                        );
                        $inserted++;
                    }
                }
            }
        }

        lingua_debug_log('[Lingua Gettext Scan] Inserted ' . $inserted . ' new strings');
    }

    /**
     * Get all unique domains from scanned strings
     *
     * @return array
     */
    public static function get_all_domains() {
        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';

        $domains = $wpdb->get_col("
            SELECT DISTINCT gettext_domain
            FROM $table
            WHERE source = 'gettext' AND gettext_domain IS NOT NULL
            ORDER BY gettext_domain
        ");

        return $domains;
    }

    /**
     * Get strings count by domain
     *
     * @return array
     */
    public static function get_domains_with_counts() {
        global $wpdb;
        $table = $wpdb->prefix . 'lingua_string_translations';

        $results = $wpdb->get_results("
            SELECT
                gettext_domain,
                COUNT(DISTINCT original_text_hash) as string_count,
                SUM(CASE WHEN translated_text IS NOT NULL AND translated_text != '' THEN 1 ELSE 0 END) as translated_count
            FROM $table
            WHERE source = 'gettext' AND gettext_domain IS NOT NULL
            GROUP BY gettext_domain
            ORDER BY string_count DESC
        ");

        return $results;
    }
}

/**
 * Callback function for potx parser to save discovered strings
 *
 * @param string $original Original string
 * @param string $domain Text domain
 * @param string $context Translation context
 * @param string $file Source file path
 * @param int $line Line number
 * @param int $string_mode String mode
 * @param string $text_plural Plural form text
 */
function lingua_save_gettext_string($original, $domain, $context, $file, $line, $string_mode, $text_plural = false) {
    global $lingua_gettext_strings_discovered;

    if (!empty($original)) {
        $domain = empty($domain) ? 'default' : $domain;
        $context = empty($context) ? 'lingua_context' : $context;
        $text_plural = empty($text_plural) ? '' : $text_plural;

        $key = $context . '::' . $domain . '::' . $original;

        if (!isset($lingua_gettext_strings_discovered[$key])) {
            $lingua_gettext_strings_discovered[$key] = array(
                'original' => $original,
                'domain' => $domain,
                'context' => $context,
                'original_plural' => $text_plural,
                'file' => $file
            );
        }
    }
}

// Initialize
add_action('init', function() {
    Lingua_Gettext_Scan::get_instance();
});
