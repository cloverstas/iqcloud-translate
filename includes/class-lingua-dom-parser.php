<?php
/**
 * Lingua DOM Parser
 * Intelligent HTML parsing for extracting translatable strings
 *
 * @package Lingua
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_DOM_Parser {

    /**
     * DOM document instance
     *
     * @var DOMDocument
     */
    private $dom;

    /**
     * XPath instance for DOM queries
     *
     * @var DOMXPath
     */
    private $xpath;

    /**
     * Translatable attributes
     *
     * @var array
     */
    private $translatable_attributes = array(
        'title',
        'alt',
        'placeholder',
        'aria-label',
        'aria-description',
        'data-title',
        'data-caption',
        'data-alt'
    );

    /**
     * Elements to exclude from text extraction
     *
     * @var array
     */
    private $exclude_elements = array(
        'script',
        'style',
        'code',
        'pre',
        'noscript'
    );

    /**
     * Constructor
     */
    public function __construct() {
        $this->init();
    }

    /**
     * Initialize the parser
     */
    private function init() {
        // Allow filtering of translatable attributes
        $this->translatable_attributes = apply_filters('lingua_translatable_attributes', $this->translatable_attributes);

        // Allow filtering of excluded elements
        $this->exclude_elements = apply_filters('lingua_exclude_elements', $this->exclude_elements);
    }

    /**
     * Parse HTML string into DOM document
     *
     * @param string $html HTML content to parse
     * @return DOMDocument|false DOM document or false on failure
     */
    public function parse_html($html) {
        if (!is_string($html) || empty($html)) {
            return false;
        }

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->encoding = 'UTF-8';

        // Suppress HTML5 warnings and errors
        libxml_use_internal_errors(true);

        // Wrap content to ensure proper UTF-8 handling and valid HTML structure
        $wrapped = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'
                  . $html . '</body></html>';

        // Load HTML with proper flags
        $loaded = $this->dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Clear any parsing errors
        libxml_clear_errors();

        if (!$loaded) {
            return false;
        }

        // Initialize XPath for efficient querying
        $this->xpath = new DOMXPath($this->dom);

        return $this->dom;
    }

    /**
     * Get all translatable content from DOM
     *
     * @param DOMDocument $dom DOM document to process
     * @return array Array of translatable elements
     */
    public function get_translatable_content($dom) {
        if (!$dom instanceof DOMDocument) {
            return array();
        }

        $elements = array();

        // Set working DOM and XPath
        $this->dom = $dom;
        if (!$this->xpath || $this->xpath->document !== $dom) {
            $this->xpath = new DOMXPath($dom);
        }

        // STEP 1: Mark no-translate containers
        $this->mark_no_translate_selectors();

        // STEP 2: Extract text nodes
        $text_elements = $this->extract_text_nodes();
        $elements = array_merge($elements, $text_elements);

        // STEP 3: Extract translatable attributes
        $attribute_elements = $this->extract_translatable_attributes();
        $elements = array_merge($elements, $attribute_elements);

        return $elements;
    }

    /**
     * Extract text nodes from DOM
     *
     * @return array Array of text elements
     */
    private function extract_text_nodes() {
        $elements = array();

        // Build XPath query to exclude unwanted elements
        $exclude_xpath = '';
        if (!empty($this->exclude_elements)) {
            $exclude_conditions = array();
            foreach ($this->exclude_elements as $element) {
                $exclude_conditions[] = "not(ancestor::{$element})";
            }
            $exclude_xpath = ' and ' . implode(' and ', $exclude_conditions);
        }

        // Query for text nodes with content, excluding unwanted ancestors
        $query = "//text()[normalize-space(.) != ''{$exclude_xpath}]";

        try {
            $text_nodes = $this->xpath->query($query);

            if ($text_nodes === false) {
                return $elements;
            }

            foreach ($text_nodes as $node) {
                $text = trim($node->nodeValue);

                if (!empty($text) && $this->is_valid_text_node($node, $text)) {
                    $elements[] = array(
                        'type' => 'text',
                        'text' => $text,
                        'node' => $node,
                        'context' => $this->get_node_context($node)
                    );
                }
            }

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log('Lingua DOM Parser: Error extracting text nodes - ' . $e->getMessage());
            }
        }

        return $elements;
    }

    /**
     * Extract translatable attributes from DOM
     *
     * @return array Array of attribute elements
     */
    private function extract_translatable_attributes() {
        $elements = array();

        foreach ($this->translatable_attributes as $attr) {
            $attr = sanitize_key($attr); // Sanitize attribute name

            // Query for all elements with this attribute
            $query = "//*[@{$attr}]";

            try {
                $attr_elements = $this->xpath->query($query);

                if ($attr_elements === false) {
                    continue;
                }

                foreach ($attr_elements as $element) {
                    // Skip if ancestor has data-no-translation attribute
                    if ($this->has_ancestor_attribute($element, 'data-no-translation')) {
                        continue;
                    }

                    // Skip if ancestor has data-lingua-skip attribute
                    if ($this->has_ancestor_attribute($element, 'data-lingua-skip')) {
                        continue;
                    }

                    // Skip global containers for attributes too
                    if ($this->is_inside_global_container($element)) {
                        continue;
                    }

                    $value = $element->getAttribute($attr);

                    if (!empty($value) && $this->is_valid_attribute_value($value)) {
                        $elements[] = array(
                            'type' => 'attribute',
                            'text' => $value,
                            'element' => $element,
                            'attribute' => $attr,
                            'context' => 'attribute_' . $attr
                        );
                    }
                }

            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log('Lingua DOM Parser: Error extracting attribute ' . $attr . ' - ' . $e->getMessage());
                }
            }
        }

        return $elements;
    }

    /**
     * Check if text node is valid for translation
     *
     * @param DOMNode $node The DOM node
     * @param string $text The text content
     * @return bool
     */
    private function is_valid_text_node($node, $text) {
        // Check minimum length
        if (strlen($text) < 2) {
            return false;
        }

        // Skip if parent is in exclude list (additional safety check)
        $parent = $node->parentNode;
        if ($parent && in_array(strtolower($parent->nodeName), $this->exclude_elements)) {
            return false;
        }

        // Skip admin bar elements
        if ($this->is_admin_bar_node($node)) {
            return false;
        }

        // Skip if ancestor has data-no-translation attribute
        if ($this->has_ancestor_attribute($node, 'data-no-translation')) {
            return false;
        }

        // Skip if ancestor has data-lingua-skip attribute
        if ($this->has_ancestor_attribute($node, 'data-lingua-skip')) {
            return false;
        }

        // Skip global containers (header, footer, nav) to prevent duplication
        if ($this->is_inside_global_container($node)) {
            return false;
        }

        // Skip if only whitespace or numbers
        if (!preg_match('/[a-zA-Z\p{L}]/u', $text)) {
            return false;
        }

        return apply_filters('lingua_is_valid_text_node', true, $node, $text);
    }

    /**
     * Check if attribute value is valid for translation
     *
     * @param string $value Attribute value
     * @return bool
     */
    private function is_valid_attribute_value($value) {
        // Check minimum length
        if (strlen($value) < 2) {
            return false;
        }

        // Skip if only numbers or special characters
        if (!preg_match('/[a-zA-Z\p{L}]/u', $value)) {
            return false;
        }

        // Skip URLs and email addresses
        if (filter_var($value, FILTER_VALIDATE_URL) || filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return apply_filters('lingua_is_valid_attribute_value', true, $value);
    }

    /**
     * Check if node is part of admin bar
     *
     * @param DOMNode $node DOM node to check
     * @return bool
     */
    private function is_admin_bar_node($node) {
        $current = $node;

        // Walk up the DOM tree to check for admin bar elements
        while ($current && $current->nodeType === XML_ELEMENT_NODE) {
            if ($current->hasAttribute('id')) {
                $id = $current->getAttribute('id');
                if (strpos($id, 'wpadminbar') !== false || strpos($id, 'wp-admin-bar') !== false) {
                    return true;
                }
            }

            if ($current->hasAttribute('class')) {
                $class = $current->getAttribute('class');
                if (strpos($class, 'admin-bar') !== false || strpos($class, 'wp-admin') !== false) {
                    return true;
                }
            }

            $current = $current->parentNode;
        }

        return false;
    }

    /**
     * Check if node has an ancestor with a specific attribute
     * Prevents extraction from marked containers
     *
     * @param DOMNode $node DOM node to check
     * @param string $attribute Attribute name to look for
     * @return bool True if ancestor has the attribute
     */
    public function has_ancestor_attribute($node, $attribute) {
        if (!$node || !$attribute) {
            return false;
        }

        $current = $node;
        $max_depth = 50; // Prevent infinite loops
        $depth = 0;

        // Walk up the DOM tree
        while ($current && $depth < $max_depth) {
            $current = $current->parentNode;

            // Stop at document root
            if (!$current || $current->nodeType !== XML_ELEMENT_NODE) {
                break;
            }

            // Check if this ancestor has the attribute
            if ($current->hasAttribute($attribute)) {
                return true;
            }

            // Stop at html tag
            if (strtolower($current->nodeName) === 'html') {
                break;
            }

            $depth++;
        }

        return false;
    }

    /**
     * Check if node has an ancestor with a specific class
     * Additional filtering by CSS class
     *
     * @param DOMNode $node DOM node to check
     * @param string $class_name Class name to look for
     * @return bool True if ancestor has the class
     */
    public function has_ancestor_class($node, $class_name) {
        if (!$node || !$class_name) {
            return false;
        }

        $current = $node;
        $max_depth = 50;
        $depth = 0;

        while ($current && $depth < $max_depth) {
            $current = $current->parentNode;

            if (!$current || $current->nodeType !== XML_ELEMENT_NODE) {
                break;
            }

            // Check classes
            if ($current->hasAttribute('class')) {
                $classes = explode(' ', $current->getAttribute('class'));
                if (in_array($class_name, $classes)) {
                    return true;
                }
            }

            if (strtolower($current->nodeName) === 'html') {
                break;
            }

            $depth++;
        }

        return false;
    }

    /**
     * Check if node is inside a global container (header, footer, nav, sidebar)
     * Helps filter out elements that appear on every page
     *
     * @param DOMNode $node DOM node to check
     * @return bool True if inside global container
     */
    public function is_inside_global_container($node) {
        if (!$node) {
            return false;
        }

        $global_tags = array('header', 'footer', 'nav');
        $global_ids = array('masthead', 'colophon', 'header', 'footer', 'sidebar');
        $global_classes = array('site-header', 'site-footer', 'sidebar', 'widget-area', 'navigation', 'menu');

        $current = $node;
        $max_depth = 50;
        $depth = 0;

        while ($current && $depth < $max_depth) {
            $current = $current->parentNode;

            if (!$current || $current->nodeType !== XML_ELEMENT_NODE) {
                break;
            }

            // Check tag name
            $tag_name = strtolower($current->nodeName);
            if (in_array($tag_name, $global_tags)) {
                return true;
            }

            // Check ID
            if ($current->hasAttribute('id')) {
                $id = $current->getAttribute('id');
                if (in_array($id, $global_ids) || strpos($id, 'header') !== false || strpos($id, 'footer') !== false) {
                    return true;
                }
            }

            // Check class
            if ($current->hasAttribute('class')) {
                $classes = explode(' ', $current->getAttribute('class'));
                foreach ($global_classes as $global_class) {
                    if (in_array($global_class, $classes)) {
                        return true;
                    }
                }
            }

            if (strtolower($current->nodeName) === 'html') {
                break;
            }

            $depth++;
        }

        return false;
    }

    /**
     * Get CSS selectors for elements that should not be translated
     * Mark containers before extraction
     *
     * @return array Array of CSS selectors
     */
    private function get_no_translate_selectors() {
        $selectors = array(
            // WordPress admin
            '#wpadminbar',
            '.wp-admin-bar',
            '.screen-reader-text',

            // Global site containers
            'header',
            '.site-header',
            '#masthead',
            'footer',
            '.site-footer',
            '#colophon',
            'nav',
            '.navigation',
            '.nav-menu',
            '.menu',
            '.sidebar',
            '.widget-area',
            '.widget',

            // Lingua-specific
            '.lingua-language-switcher',
            '.lingua-no-translate',
            '[data-lingua-skip]',

            // Common theme patterns
            '#header',
            '#footer',
            '#sidebar',
            '.header',
            '.footer',

            // WooCommerce breadcrumbs (appear on every product)
            '.woocommerce-breadcrumb',

            // Skip elements
            'script',
            'style',
            'code',
            'pre',
            'noscript'
        );

        return apply_filters('lingua_no_translate_selectors', $selectors);
    }

    /**
     * Mark no-translate containers with data-no-translation attribute
     * Pre-mark containers before extraction
     */
    private function mark_no_translate_selectors() {
        if (!$this->xpath) {
            return;
        }

        $selectors = $this->get_no_translate_selectors();

        foreach ($selectors as $selector) {
            try {
                // Convert CSS selector to XPath
                $xpath_query = $this->css_to_xpath($selector);
                if (!$xpath_query) {
                    continue;
                }

                $elements = $this->xpath->query($xpath_query);
                if ($elements === false || $elements->length === 0) {
                    continue;
                }

                // Mark each element with data-no-translation attribute
                foreach ($elements as $element) {
                    if ($element->nodeType === XML_ELEMENT_NODE) {
                        $element->setAttribute('data-no-translation', '1');
                    }
                }
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log('Lingua DOM Parser: Error marking no-translate selector "' . $selector . '" - ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Convert CSS selector to XPath query
     * Simplified converter for common selectors
     *
     * @param string $css CSS selector
     * @return string|false XPath query or false if cannot convert
     */
    private function css_to_xpath($css) {
        $css = trim($css);

        // ID selector
        if (strpos($css, '#') === 0) {
            $id = substr($css, 1);
            return "//*[@id='{$id}']";
        }

        // Class selector
        if (strpos($css, '.') === 0) {
            $class = substr($css, 1);
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
        }

        // Attribute selector
        if (preg_match('/^\[([a-z-]+)\]$/i', $css, $matches)) {
            return "//*[@{$matches[1]}]";
        }

        // Simple tag selector
        if (preg_match('/^[a-z]+$/i', $css)) {
            return "//{$css}";
        }

        // For complex selectors, fall back to tag name
        // (We don't need full CSS selector support for this use case)
        return false;
    }

    /**
     * Get context for a node based on its position and attributes
     *
     * @param DOMNode $node DOM node
     * @return string Context identifier
     */
    private function get_node_context($node) {
        $parent = $node->parentNode;

        if (!$parent) {
            return 'text_node';
        }

        $tag_name = strtolower($parent->nodeName);

        // Special contexts for different elements
        switch ($tag_name) {
            case 'title':
                return 'page_title';
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                return 'heading';
            case 'p':
                return 'paragraph';
            case 'li':
                return 'list_item';
            case 'button':
                return 'button_text';
            case 'label':
                return 'form_label';
            case 'td':
            case 'th':
                return 'table_cell';
            default:
                return 'text_node';
        }
    }

    /**
     * Apply translations to DOM
     *
     * @param DOMDocument $dom DOM document
     * @param array $translations Array of translations to apply
     * @return bool Success status
     */
    public function apply_translations($dom, $translations) {
        if (!$dom instanceof DOMDocument || empty($translations)) {
            return false;
        }

        foreach ($translations as $translation) {
            if (!isset($translation['element']) || !isset($translation['translation'])) {
                continue;
            }

            $element_data = $translation['element'];
            $new_text = $translation['translation'];

            // Sanitize translation based on context
            if ($element_data['type'] === 'text') {
                // For text nodes, allow basic HTML but sanitize
                $new_text = wp_kses_post($new_text);

                if (isset($element_data['node']) && $element_data['node'] instanceof DOMText) {
                    $element_data['node']->nodeValue = $new_text;
                }

            } elseif ($element_data['type'] === 'attribute') {
                // For attributes, escape and sanitize
                $new_text = esc_attr($new_text);

                if (isset($element_data['element']) && isset($element_data['attribute'])) {
                    $element_data['element']->setAttribute($element_data['attribute'], $new_text);
                }
            }
        }

        return true;
    }

    /**
     * Get HTML string from DOM document
     *
     * @param DOMDocument $dom DOM document
     * @return string HTML content
     */
    public function get_html_from_dom($dom) {
        if (!$dom instanceof DOMDocument) {
            return '';
        }

        try {
            // Get body content only (exclude the wrapper we added)
            $body = $dom->getElementsByTagName('body')->item(0);

            if (!$body) {
                return $dom->saveHTML();
            }

            $html = '';
            foreach ($body->childNodes as $child) {
                $html .= $dom->saveHTML($child);
            }

            return $html;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log('Lingua DOM Parser: Error getting HTML from DOM - ' . $e->getMessage());
            }
            return '';
        }
    }

    /**
     * Clean up DOM resources
     */
    public function cleanup() {
        $this->dom = null;
        $this->xpath = null;
    }

    /**
     * Get DOM statistics for debugging
     *
     * @param DOMDocument $dom DOM document
     * @return array Statistics array
     */
    public function get_dom_stats($dom) {
        if (!$dom instanceof DOMDocument) {
            return array();
        }

        $stats = array(
            'total_elements' => 0,
            'text_nodes' => 0,
            'translatable_attributes' => 0,
            'excluded_elements' => 0
        );

        try {
            // Count all elements
            $all_elements = $dom->getElementsByTagName('*');
            $stats['total_elements'] = $all_elements->length;

            // Count excluded elements
            foreach ($this->exclude_elements as $tag) {
                $excluded = $dom->getElementsByTagName($tag);
                $stats['excluded_elements'] += $excluded->length;
            }

            // Count text nodes and attributes would require XPath queries
            // which are expensive, so we skip them in basic stats

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log('Lingua DOM Parser: Error getting DOM stats - ' . $e->getMessage());
            }
        }

        return $stats;
    }
}