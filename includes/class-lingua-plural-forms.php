<?php
/**
 * Lingua Plural Forms Handler
 *
 * Handles gettext plural forms calculation based on language-specific rules
 *
 * @package Lingua
 * @version 5.2.98
 * @since 5.2.98
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class Lingua_Plural_Forms
 *
 * Manages plural forms for different languages using expressions from .mo files
 * Provides universal plural form calculation for all languages
 */
class Lingua_Plural_Forms {

    /**
     * Singleton instance
     * @var Lingua_Plural_Forms
     */
    private static $instance = null;

    /**
     * Cached plural forms function
     * @var callable
     */
    protected $gettext_select_plural_form;

    /**
     * Number of plural forms for current language
     * @var int
     */
    protected $nplurals;

    /**
     * Plural forms headers loaded from .mo files
     * Format: ['ru' => 'nplurals=3; plural=(...)', 'en' => 'nplurals=2; plural=(...)']
     * @var array
     */
    protected $plural_forms_headers = array();

    /**
     * Currently cached language
     * @var string
     */
    protected $cached_language = null;

    /**
     * Get singleton instance
     *
     * @return Lingua_Plural_Forms
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - loads plural forms headers
     */
    public function __construct() {
        $this->plural_forms_headers = $this->load_plural_forms_headers();
    }

    /**
     * Get plural form index for a given count and language
     *
     * Main method for determining which plural form to use
     *
     * @param int $count Number of items
     * @param string $language Language code (e.g., 'ru', 'en', 'de')
     * @return int Plural form index (0, 1, 2, etc.)
     */
    public function get_plural_form($count, $language) {
        if ($count === null) {
            return 0;
        }

        // Get header for this language
        if (!isset($this->plural_forms_headers[$language])) {
            // Load header if not cached
            $this->plural_forms_headers[$language] = $this->get_plural_forms_header($language);
        }

        $header = $this->plural_forms_headers[$language];

        // Calculate plural form using header expression
        return $this->calculate_plural_form($count, $header, $language);
    }

    /**
     * Get number of plural forms for a language
     *
     * @param string $language Language code
     * @return int Number of plural forms (e.g., 2 for English, 3 for Russian)
     */
    public function get_number_of_plural_forms($language) {
        if (!isset($this->plural_forms_headers[$language])) {
            $this->plural_forms_headers[$language] = $this->get_plural_forms_header($language);
        }

        $header = $this->plural_forms_headers[$language];
        list($nplurals, $expression) = $this->parse_header($header);

        return $nplurals;
    }

    /**
     * Load all plural forms headers from WordPress option
     * If not exists, will be populated on first language use
     *
     * @return array Plural forms headers
     */
    protected function load_plural_forms_headers() {
        $stored = get_option('lingua_plural_forms_headers', array());

        if (defined('WP_DEBUG') && WP_DEBUG && !empty($stored)) {
            lingua_debug_log('[Lingua Plural Forms] Loaded headers from option: ' . count($stored) . ' languages');
        }

        return $stored;
    }

    /**
     * Get plural forms header for a specific language
     * Loads from .mo file if not cached, then saves to option
     *
     * @param string $language_code Language code (e.g., 'ru', 'en')
     * @return string Plural forms header (e.g., 'nplurals=3; plural=(...)')
     */
    protected function get_plural_forms_header($language_code) {
        // Check if already in option
        $stored = get_option('lingua_plural_forms_headers', array());

        if (isset($stored[$language_code])) {
            return $stored[$language_code];
        }

        // Load from .mo file
        $header = $this->load_header_from_mo_file($language_code);

        // Save to option for future use
        $stored[$language_code] = $header;
        update_option('lingua_plural_forms_headers', $stored);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[Lingua Plural Forms] Loaded header for '{$language_code}': {$header}");
        }

        return $header;
    }

    /**
     * Load plural forms header from WordPress .mo file
     *
     * @param string $language_code Language code
     * @return string Plural forms header
     */
    protected function load_header_from_mo_file($language_code) {
        global $l10n;

        // v5.2.102: Override for languages with NO plural forms (always use same form)
        // Chinese, Japanese, Korean, Thai, Vietnamese, Turkish have no grammatical plural
        $no_plural_languages = array(
            'zh' => 'nplurals=1; plural=0;',  // Chinese
            'ja' => 'nplurals=1; plural=0;',  // Japanese
            'ko' => 'nplurals=1; plural=0;',  // Korean
            'th' => 'nplurals=1; plural=0;',  // Thai
            'vi' => 'nplurals=1; plural=0;',  // Vietnamese
            'tr' => 'nplurals=1; plural=0;',  // Turkish
        );

        if (isset($no_plural_languages[$language_code])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[Lingua Plural Forms v5.2.102] Language '{$language_code}' has NO plural forms - using single form");
            }
            return $no_plural_languages[$language_code];
        }

        // v5.2.135: Override for languages with special plural forms (3, 4, 5, 6 forms)
        // These languages often don't have WordPress .mo files or load incorrect fallback
        $special_plural_languages = array(
            // 3 forms: Slavic languages (like Russian)
            // v5.2.193: Added Serbian and other Slavic languages that fallback to English (2 forms)
            'sr' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
            'hr' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
            'bs' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
            'uk' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
            'be' => 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);',
            'pl' => 'nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);',
            'cs' => 'nplurals=3; plural=(n==1 ? 0 : n>=2 && n<=4 ? 1 : 2);',
            'sk' => 'nplurals=3; plural=(n==1 ? 0 : n>=2 && n<=4 ? 1 : 2);',

            // 4 forms: Slovenian, Scottish Gaelic
            'sl' => 'nplurals=4; plural=(n%100==1 ? 0 : n%100==2 ? 1 : n%100==3 || n%100==4 ? 2 : 3);',
            'gd' => 'nplurals=4; plural=(n==1 || n==11) ? 0 : (n==2 || n==12) ? 1 : (n > 2 && n < 20) ? 2 : 3;',

            // 5 forms: Irish, Breton
            'ga' => 'nplurals=5; plural=n==1 ? 0 : n==2 ? 1 : (n>2 && n<7) ? 2 : (n>6 && n<11) ? 3 : 4;',
            'br' => 'nplurals=5; plural=n==1 ? 0 : n==2 ? 1 : n%10==1 ? 2 : n%10==2 ? 3 : 4;',

            // 6 forms: Arabic
            'ar' => 'nplurals=6; plural=(n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 ? 4 : 5);',
        );

        if (isset($special_plural_languages[$language_code])) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[Lingua Plural Forms v5.2.135] Language '{$language_code}' has SPECIAL plural forms");
            }
            return $special_plural_languages[$language_code];
        }

        // v5.2.101: Map short language codes to full WordPress locales
        $locale_map = array(
            'ru' => 'ru_RU',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'es' => 'es_ES',
            'it' => 'it_IT',
            'pt' => 'pt_BR',
            'pl' => 'pl_PL',
            'nl' => 'nl_NL',
            'ja' => 'ja',
            'ko' => 'ko_KR',
            'zh' => 'zh_CN',
            'en' => 'en_US',
        );

        $locale = isset($locale_map[$language_code]) ? $locale_map[$language_code] : $language_code;

        // Save current locale
        $current_locale = get_locale();

        // Load textdomain for target language
        load_default_textdomain($locale);

        $header = null;

        // Try to get plural forms from loaded textdomain
        if (isset($l10n['default']) && is_object($l10n['default']) && isset($l10n['default']->headers['Plural-Forms'])) {
            $header = $l10n['default']->headers['Plural-Forms'];
        }

        // Restore original locale
        if ($current_locale !== $locale) {
            load_default_textdomain($current_locale);
        }

        // Fallback to English-style plural if no header found
        if (empty($header)) {
            $header = 'nplurals=2; plural=n != 1;';

            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[Lingua Plural Forms] WARNING: No plural forms header found for '{$language_code}' (locale: {$locale}), using English fallback");
            }
        }

        return $header;
    }

    /**
     * Calculate plural form index using header expression
     *
     * @param int $count Number of items
     * @param string $header Plural forms header
     * @param string $language Language code (for caching)
     * @return int Plural form index
     */
    protected function calculate_plural_form($count, $header, $language) {
        // Re-compile function only if language changed
        if (!isset($this->gettext_select_plural_form) || $this->cached_language !== $language) {
            list($nplurals, $expression) = $this->parse_header($header);

            $this->nplurals = $nplurals;
            $this->gettext_select_plural_form = $this->compile_plural_form_function($nplurals, $expression);
            $this->cached_language = $language;
        }

        // Call compiled function
        return call_user_func($this->gettext_select_plural_form, $count);
    }

    /**
     * Parse plural forms header into nplurals and expression
     *
     * Example: "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 ? 1 : 2);"
     * Returns: [3, '(n%10==1 && n%100!=11 ? 0 : n%10>=2 ? 1 : 2)']
     *
     * @param string $header Plural forms header
     * @return array [nplurals, expression]
     */
    protected function parse_header($header) {
        if (preg_match('/^\s*nplurals\s*=\s*(\d+)\s*;\s+plural\s*=\s*(.+)$/i', $header, $matches)) {
            $nplurals = (int) $matches[1];
            $expression = trim($matches[2]);
            return array($nplurals, $expression);
        }

        // Fallback to English
        return array(2, 'n != 1');
    }

    /**
     * Compile plural form expression into PHP function
     * Uses WordPress built-in Plural_Forms class
     *
     * @param int $nplurals Number of plural forms
     * @param string $expression Plural form expression
     * @return callable Function that returns plural form index
     */
    protected function compile_plural_form_function($nplurals, $expression) {
        // Use WordPress built-in Plural_Forms class
        // Located in wp-includes/pomo/plural-forms.php
        if (!class_exists('Plural_Forms')) {
            require_once ABSPATH . WPINC . '/pomo/plural-forms.php';
        }

        try {
            // Create Plural_Forms handler
            $handler = new Plural_Forms(rtrim($expression, ';'));
            return array($handler, 'get');
        } catch (Exception $e) {
            // Fallback to English on error
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log('[Lingua Plural Forms] ERROR compiling expression: ' . $e->getMessage() . ', using English fallback');
            }

            return $this->compile_plural_form_function(2, 'n != 1');
        }
    }

    /**
     * Get all stored plural forms headers
     * Useful for debugging
     *
     * @return array
     */
    public function get_all_headers() {
        return $this->plural_forms_headers;
    }

    /**
     * Clear cached headers (useful for testing)
     */
    public function clear_cache() {
        delete_option('lingua_plural_forms_headers');
        $this->plural_forms_headers = array();
        $this->cached_language = null;
        $this->gettext_select_plural_form = null;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log('[Lingua Plural Forms] Cache cleared');
        }
    }
}
