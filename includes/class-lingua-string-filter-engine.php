<?php
/**
 * Lingua String Filter Engine
 * Advanced filtering system for translatable strings
 *
 * @package Lingua
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_String_Filter_Engine {

    /**
     * CSS selectors to include for translation
     *
     * @var array
     */
    private $include_selectors;

    /**
     * CSS selectors to exclude from translation
     *
     * @var array
     */
    private $exclude_selectors;

    /**
     * Regular expression patterns to skip
     *
     * @var array
     */
    private $skip_patterns;

    /**
     * Known admin strings to skip
     *
     * @var array
     */
    private $admin_strings;

    /**
     * Minimum string length
     *
     * @var int
     */
    private $min_length = 2;

    /**
     * Maximum string length
     *
     * @var int
     */
    private $max_length = 500;

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_default_settings();
    }

    /**
     * Initialize default filter settings
     */
    private function init_default_settings() {
        // Default include selectors
        $this->include_selectors = array(
            'p', 'span', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'li', 'td', 'th', 'label', 'button', 'a', 'strong', 'em',
            'blockquote', 'figcaption', 'caption'
        );

        // Default exclude selectors
        $this->exclude_selectors = array(
            'script', 'style', 'code', 'pre', 'noscript',
            '.wp-admin-bar', '#wpadminbar', '.screen-reader-text',
            '[data-lingua-skip]', '.lingua-no-translate',
            // Language switcher exclusions
            '.lingua-language-switcher', '.lingua-language-switcher *',
            '.language-switcher', '.language-selector', '.lang-switcher'
        );

        // Skip patterns for different content types
        $this->skip_patterns = array(
            '/^[0-9\s\-\+\(\)]+$/',  // Phone numbers and numbers only
            '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', // Email addresses
            '/^https?:\/\//', // URLs
            '/^#[a-fA-F0-9]{3,6}$/', // CSS hex colors
            '/^\d+px$/', // CSS pixel values
            '/^\d+em$/', // CSS em values
            '/^\d+rem$/', // CSS rem values
            '/^\d+%$/', // CSS percentages
            '/^[\d\s\-\/\.]+$/', // Dates and similar patterns
            '/^[A-Z0-9_]+$/', // Constants (all caps with underscores)
            '/^\/[^\/\s]*\/[gimuy]*$/', // Regular expressions
        );

        // WordPress admin strings to skip
        $this->admin_strings = array(
            // Dashboard
            'Dashboard', 'At a Glance', 'Activity', 'Quick Draft',

            // Menu items
            'Posts', 'Media', 'Pages', 'Comments', 'Appearance', 'Plugins',
            'Users', 'Tools', 'Settings',

            // Actions
            'Edit', 'View', 'Delete', 'Trash', 'Restore', 'Publish', 'Update',
            'Add New', 'Upload', 'Insert', 'Cancel', 'Save Changes',

            // User related
            'Log Out', 'Profile', 'Howdy', 'Hello', 'Welcome',
            'Administrator', 'Editor', 'Author', 'Contributor', 'Subscriber',

            // Status
            'Draft', 'Published', 'Pending', 'Private', 'Scheduled',

            // Common UI elements
            'Search', 'Filter', 'Sort', 'Previous', 'Next', 'Submit',
            'Yes', 'No', 'OK', 'Apply', 'Reset', 'Clear',

            // Russian admin strings
            'Консоль', 'Записи', 'Страницы', 'Комментарии', 'Внешний вид',
            'Плагины', 'Пользователи', 'Инструменты', 'Настройки',
            'Добавить новую', 'Изменить', 'Просмотр', 'Удалить', 'Выйти',

            // Language switcher duplicate strings (common language names)
            'English', 'Русский', 'Français', 'Deutsch', 'Español', 'Italiano',
            'Português', 'Nederlands', 'Polski', 'Svenska', 'Dansk', 'Norsk',
            'Suomi', 'Čeština', 'Slovenčina', 'Magyar', 'Română', 'Български',
            'Ελληνικά', 'Türkçe', 'العربية', '中文', '日本語', '한국어',

            // ISO language codes
            'en', 'ru', 'fr', 'de', 'es', 'it', 'pt', 'nl', 'pl', 'sv',
            'da', 'no', 'fi', 'cs', 'sk', 'hu', 'ro', 'bg', 'el', 'tr',
            'ar', 'zh', 'ja', 'ko',

            // Common flag icons content (alt text)
            '🇺🇸', '🇬🇧', '🇷🇺', '🇫🇷', '🇩🇪', '🇪🇸', '🇮🇹', '🇵🇹',
            '🇳🇱', '🇵🇱', '🇸🇪', '🇩🇰', '🇳🇴', '🇫🇮', '🇨🇿', '🇸🇰'
        );

        // Get settings from options
        $settings = get_option('lingua_string_capture_settings', array());
        if (isset($settings['min_string_length'])) {
            $this->min_length = absint($settings['min_string_length']);
        }
        if (isset($settings['max_string_length'])) {
            $this->max_length = absint($settings['max_string_length']);
        }

        // Allow filtering of all settings
        $this->include_selectors = apply_filters('lingua_include_selectors', $this->include_selectors);
        $this->exclude_selectors = apply_filters('lingua_exclude_selectors', $this->exclude_selectors);
        $this->skip_patterns = apply_filters('lingua_skip_patterns', $this->skip_patterns);
        $this->admin_strings = apply_filters('lingua_admin_strings', $this->admin_strings);
        $this->min_length = apply_filters('lingua_min_string_length', $this->min_length);
        $this->max_length = apply_filters('lingua_max_string_length', $this->max_length);
    }

    /**
     * Filter array of translatable elements
     *
     * @param array $elements Array of elements to filter
     * @return array Filtered elements
     */
    public function filter_elements($elements) {
        if (!is_array($elements)) {
            return array();
        }

        $filtered = array();

        foreach ($elements as $element) {
            if ($this->should_process_element($element)) {
                $filtered[] = $element;
            }
        }

        return $filtered;
    }

    /**
     * Check if element should be processed for translation
     *
     * @param array $element Element data
     * @return bool
     */
    private function should_process_element($element) {
        if (!is_array($element) || !isset($element['text'])) {
            return false;
        }

        $text = $element['text'];
        $context = isset($element['context']) ? $element['context'] : 'general';

        // Length checks
        if (!$this->check_length($text)) {
            return false;
        }

        // Pattern checks
        if ($this->matches_skip_pattern($text)) {
            return false;
        }

        // Admin string checks
        if ($this->is_admin_string($text)) {
            return false;
        }

        // Content type checks
        if (!$this->is_valid_content($text)) {
            return false;
        }

        // Context-specific checks
        if (!$this->check_context($text, $context)) {
            return false;
        }

        // Final WordPress filter
        return apply_filters('lingua_should_translate_string', true, $text, $context);
    }

    /**
     * Check string length against configured limits
     *
     * @param string $text Text to check
     * @return bool
     */
    private function check_length($text) {
        $length = mb_strlen($text, 'UTF-8');
        return ($length >= $this->min_length && $length <= $this->max_length);
    }

    /**
     * Check if text matches any skip patterns
     *
     * @param string $text Text to check
     * @return bool
     */
    private function matches_skip_pattern($text) {
        foreach ($this->skip_patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if text is a known admin string
     *
     * @param string $text Text to check
     * @return bool
     */
    private function is_admin_string($text) {
        $trimmed_text = trim($text);

        // Exact matches
        foreach ($this->admin_strings as $admin_string) {
            if (strcasecmp($trimmed_text, $admin_string) === 0) {
                return true;
            }
        }

        // Partial matches for longer admin strings
        foreach ($this->admin_strings as $admin_string) {
            if (strlen($admin_string) > 3 && stripos($text, $admin_string) !== false) {
                return true;
            }
        }

        // Check for username-like patterns
        if ($this->looks_like_username($text)) {
            return true;
        }

        // Check for technical strings
        if ($this->is_technical_string($text)) {
            return true;
        }

        return false;
    }

    /**
     * Check if text looks like a username
     *
     * @param string $text Text to check
     * @return bool
     */
    private function looks_like_username($text) {
        // Pattern: alphanumeric with dots, dashes, underscores
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $text) && strlen($text) < 30) {
            return true;
        }

        // Pattern: single word that could be a name
        if (preg_match('/^[A-Z][a-z]+$/', $text) && strlen($text) < 15) {
            return true;
        }

        return false;
    }

    /**
     * Check if text is a technical string (CSS classes, IDs, etc.)
     *
     * @param string $text Text to check
     * @return bool
     */
    private function is_technical_string($text) {
        // CSS class patterns
        if (preg_match('/^[a-z-]+(-[a-z0-9]+)*$/', $text) && strlen($text) > 5) {
            return true;
        }

        // ID patterns
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $text) && strlen($text) > 8) {
            return true;
        }

        // File paths
        if (preg_match('/[\/\\\\.]/', $text) && !preg_match('/\s/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Check if content is valid for translation
     *
     * @param string $text Text to check
     * @return bool
     */
    private function is_valid_content($text) {
        // Must contain letters
        if (!preg_match('/[a-zA-Z\p{L}]/u', $text)) {
            return false;
        }

        // Skip if mostly numbers
        $letter_count = preg_match_all('/[a-zA-Z\p{L}]/u', $text);
        $total_length = mb_strlen($text, 'UTF-8');

        if ($total_length > 0 && ($letter_count / $total_length) < 0.3) {
            return false;
        }

        // Skip HTML tags
        if (strip_tags($text) !== $text) {
            return false;
        }

        // Skip if it looks like encoded content
        if (preg_match('/[&%;][a-zA-Z0-9]{2,8};/', $text)) {
            return false;
        }

        return true;
    }

    /**
     * Context-specific filtering
     *
     * @param string $text Text to check
     * @param string $context Context identifier
     * @return bool
     */
    private function check_context($text, $context) {
        // Handle different contexts
        switch ($context) {
            case 'admin_bar':
                // Skip admin bar strings entirely
                return false;

            case 'page_title':
                // Always translate page titles
                return true;

            case 'heading':
                // Always translate headings if they pass other filters
                return true;

            case 'button_text':
                // Skip common button texts
                $common_buttons = array('OK', 'Cancel', 'Submit', 'Reset', 'Go', 'Send');
                return !in_array(trim($text), $common_buttons);

            case 'form_label':
                // Skip if it looks like a field name
                if (preg_match('/^[a-z_]+$/', $text)) {
                    return false;
                }
                return true;

            case 'attribute_alt':
            case 'attribute_title':
                // More lenient for alt and title attributes
                return mb_strlen($text, 'UTF-8') >= 3;

            case 'attribute_placeholder':
                // Skip placeholder values that look like field names
                if (preg_match('/^[a-z_]+$/', $text)) {
                    return false;
                }
                return true;

            default:
                return true;
        }
    }

    /**
     * Get filter statistics
     *
     * @return array Filter statistics
     */
    public function get_filter_stats() {
        return array(
            'min_length' => $this->min_length,
            'max_length' => $this->max_length,
            'include_selectors_count' => count($this->include_selectors),
            'exclude_selectors_count' => count($this->exclude_selectors),
            'skip_patterns_count' => count($this->skip_patterns),
            'admin_strings_count' => count($this->admin_strings)
        );
    }

    /**
     * Add custom skip pattern
     *
     * @param string $pattern Regular expression pattern
     */
    public function add_skip_pattern($pattern) {
        if (is_string($pattern) && !in_array($pattern, $this->skip_patterns)) {
            $this->skip_patterns[] = $pattern;
        }
    }

    /**
     * Add custom admin string
     *
     * @param string $string Admin string to skip
     */
    public function add_admin_string($string) {
        if (is_string($string) && !in_array($string, $this->admin_strings)) {
            $this->admin_strings[] = sanitize_text_field($string);
        }
    }

    /**
     * Remove skip pattern
     *
     * @param string $pattern Pattern to remove
     */
    public function remove_skip_pattern($pattern) {
        $key = array_search($pattern, $this->skip_patterns);
        if ($key !== false) {
            unset($this->skip_patterns[$key]);
        }
    }

    /**
     * Check if string was already translated (to avoid retranslating)
     *
     * @param string $text Text to check
     * @param string $language Target language
     * @return bool
     */
    public function is_already_translated($text, $language) {
        global $wpdb;

        // Check if this text exists as a translation in the database
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lingua_string_translations
             WHERE translated_text = %s AND language_code = %s",
            $text,
            $language
        ));

        return ($exists > 0);
    }

    /**
     * Batch filter elements for performance
     *
     * @param array $elements Large array of elements
     * @param int $batch_size Batch size for processing
     * @return array Filtered elements
     */
    public function batch_filter_elements($elements, $batch_size = 100) {
        if (!is_array($elements) || count($elements) <= $batch_size) {
            return $this->filter_elements($elements);
        }

        $filtered = array();
        $batches = array_chunk($elements, $batch_size);

        foreach ($batches as $batch) {
            $batch_filtered = $this->filter_elements($batch);
            $filtered = array_merge($filtered, $batch_filtered);
        }

        return $filtered;
    }
}