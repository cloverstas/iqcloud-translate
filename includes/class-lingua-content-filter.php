<?php
/**
 * Lingua Content Filter
 *
 * Продвинутая фильтрация нежелательного контента
 * Исключение технических строк, UI элементов, и системного контента
 *
 * @package Lingua
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Content_Filter {

    private $excluded_selectors;
    private $excluded_patterns;
    private $excluded_classes;
    private $excluded_ids;
    private $excluded_attributes;

    public function __construct() {
        $this->init_exclusion_rules();
    }

    /**
     * Инициализация правил исключения
     */
    private function init_exclusion_rules() {
        // CSS селекторы для исключения
        $this->excluded_selectors = apply_filters('lingua_excluded_selectors', array(
            // WordPress административные элементы
            '#wpadminbar', '.admin-bar', '.wp-admin',

            // Скрипты и стили
            'script', 'style', 'noscript', 'head',

            // Технические атрибуты
            '[data-no-translate]', '[data-lingua-ignore]',

            // Общие технические элементы
            '.screen-reader-text', '.skip-link', '.sr-only',
            '.visually-hidden', '.hidden', '.invisible',

            // Элементы page builders
            '[data-element-type*="builder"]',
            '[data-element-type*="widget"]',
            '[data-setting*="mobile"]',
            '[data-setting*="header"]',
            '[data-setting*="footer"]',

            // WooCommerce технические элементы
            '.woocommerce-error', '.woocommerce-message',
            '.woocommerce-info', '.cart-collaterals',

            // SEO плагины технические элементы
            '.yoast', '.rank-math', '.seopress',

            // Форматирование и структура
            '.clearfix', '.clear', '.spacer',

            // Социальные кнопки (часто содержат технический текст)
            '.social-share', '.sharing-buttons', '.addtoany'
        ));

        // Паттерны текста для исключения
        $this->excluded_patterns = apply_filters('lingua_excluded_patterns', array(
            // Настройки тем и builders
            '/^Set your .+ menu in .+ builder/i',
            '/^ADD ANYTHING HERE OR JUST REMOVE IT/i',
            '/^Choose .+ from .+ settings/i',
            '/^Configure .+ in .+ panel/i',
            '/^Edit .+ in .+ customizer/i',

            // Технические сообщения
            '/^Posted by\s*$/i',
            '/^Back to list\s*$/i',
            '/^Read more\s*$/i',
            '/^Continue reading\s*$/i',
            '/^Share this\s*$/i',
            '/^Related posts\s*$/i',

            // Даты и время
            '/^\d{1,2}:\d{2}(:\d{2})?\s*(AM|PM)?\s*$/i',
            '/^\d{1,2}\/\d{1,2}\/\d{2,4}$/',
            '/^\d{4}-\d{2}-\d{2}$/',

            // URL и email
            '/^https?:\/\/[^\s]+$/i',
            '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/i',

            // Только цифры или символы
            '/^\d+$/',
            '/^[^\w\s]*$/',
            '/^[\s\-_=+]*$/',

            // Copyright и юридическая информация
            '/^©\s*\d{4}/i',
            '/^Copyright\s*\d{4}/i',
            '/^All rights reserved/i',
            '/^Privacy Policy$/i',
            '/^Terms of Service$/i',
            '/^Cookie Policy$/i',
            '/^GDPR/i',

            // Пагинация
            '/^Previous$/i',
            '/^Next$/i',
            '/^Page \d+ of \d+$/i',
            '/^\d+\s*\/\s*\d+$/i',

            // Валюты и цены (если очень короткие)
            '/^\$\d+(\.\d{2})?$/',
            '/^\€\d+(\.\d{2})?$/',
            '/^\£\d+(\.\d{2})?$/',

            // Рейтинги и оценки
            '/^\d+\/\d+\s*stars?$/i',
            '/^\d+\.\d+\s*\/\s*\d+$/i',

            // Технические коды
            '/^[A-Z0-9]{5,}$/', // Коды товаров, SKU
            '/^#[a-fA-F0-9]{3,6}$/', // Цветовые коды

            // Пустые или мусорные строки
            '/^[\s\xA0\x00-\x1F]*$/', // Только пробелы и невидимые символы
            '/^([\s]*[|][\s]*)+$/', // Только разделители |
        ));

        // Исключенные классы (точные совпадения и подстроки)
        $this->excluded_classes = apply_filters('lingua_excluded_classes', array(
            // WordPress базовые
            'admin-bar', 'wp-admin', 'screen-reader-text', 'sr-only',
            'skip-link', 'lingua-technical', 'no-translate',

            // Accessibility
            'visually-hidden', 'hidden', 'invisible', 'display-none',

            // Технические классы
            'debug', 'dev-tools', 'developer', 'admin-only',

            // Page builders
            'elementor', 'vc_', 'fusion-', 'et_pb_', 'fl-',
            'beaver-', 'oxygen-', 'divi-', 'gutenberg-',

            // Плагины
            'yoast', 'rankmath', 'seo-', 'cookie-', 'gdpr-',
            'analytics-', 'tracking-', 'pixel-',

            // Социальные кнопки
            'social-', 'share-', 'sharing-', 'addtoany-',
            'facebook-', 'twitter-', 'instagram-',

            // Реклама
            'advertisement', 'ad-', 'adsense-', 'adsbygoogle',
            'banner-', 'promo-',

            // Техническая навигация
            'breadcrumb-', 'pagination-', 'page-numbers',
            'nav-links', 'post-navigation',

            // Форматирование
            'clearfix', 'clear', 'spacer', 'separator',
        ));

        // Исключенные ID
        $this->excluded_ids = apply_filters('lingua_excluded_ids', array(
            'wpadminbar', 'wp-admin', 'adminbar',
            'cookie-notice', 'cookie-banner',
            'google-analytics', 'gtag', 'facebook-pixel',
            'debug-bar', 'query-monitor'
        ));

        // Исключенные атрибуты
        $this->excluded_attributes = apply_filters('lingua_excluded_attributes', array(
            'data-no-translate' => true,
            'data-lingua-ignore' => true,
            'data-skip-translation' => true,
            'translate' => 'no',
            'aria-hidden' => 'true'
        ));
    }

    /**
     * Основной метод проверки - должен ли узел быть исключен
     */
    public function should_exclude_node($node, $text_content) {
        // Базовые проверки текста
        if ($this->is_excluded_by_text($text_content)) {
            return true;
        }

        // Проверки DOM элемента
        if ($this->is_excluded_by_dom($node)) {
            return true;
        }

        // Проверки родительских элементов
        if ($this->is_excluded_by_ancestry($node)) {
            return true;
        }

        // Дополнительные эвристики
        if ($this->is_excluded_by_heuristics($node, $text_content)) {
            return true;
        }

        return false;
    }

    /**
     * Проверка исключения по тексту
     */
    private function is_excluded_by_text($text) {
        $text = trim($text);

        // Слишком короткий текст
        if (strlen($text) < 2) {
            return true;
        }

        // Слишком длинный текст (вероятно spam или код)
        if (strlen($text) > 2000) {
            return true;
        }

        // Проверка паттернов
        foreach ($this->excluded_patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        // Проверка на технический контент
        if ($this->is_technical_content($text)) {
            return true;
        }

        // Проверка на структурированные данные
        if ($this->is_structured_data($text)) {
            return true;
        }

        return false;
    }

    /**
     * Проверка на технический контент
     */
    private function is_technical_content($text) {
        // Пустой или очень короткий текст
        if (empty($text) || strlen(trim($text)) < 2) {
            return true;
        }

        // Только цифры
        if (is_numeric($text)) {
            return true;
        }

        // Проценты
        if (preg_match('/^\d+%$/', $text)) {
            return true;
        }

        // URLs
        if (preg_match('/^https?:\/\//', $text) || filter_var($text, FILTER_VALIDATE_URL)) {
            return true;
        }

        // Email адреса
        if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        // CSS селекторы и свойства
        if (preg_match('/^[\.\#][a-zA-Z0-9\-_]+$/', $text) ||
            preg_match('/^[a-zA-Z\-]+\s*:\s*[^;]+;?$/', $text)) {
            return true;
        }

        // JavaScript код
        if (preg_match('/^(function|var|let|const|if|for|while)\s*[\(\{]/', $text)) {
            return true;
        }

        // HTML entities в сыром виде
        if (preg_match('/&[a-zA-Z0-9]+;/', $text) && strip_tags($text) !== $text) {
            return true;
        }

        // Base64 данные
        if (preg_match('/^[A-Za-z0-9+\/]+=*$/', $text) && strlen($text) > 20) {
            return true;
        }

        // Хэши (MD5, SHA)
        if (preg_match('/^[a-fA-F0-9]{32,}$/', $text)) {
            return true;
        }

        // GUID/UUID
        if (preg_match('/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Проверка на структурированные данные (JSON, XML, и т.д.)
     */
    private function is_structured_data($text) {
        $text = trim($text);

        // JSON данные
        if ((substr($text, 0, 1) === '{' && substr($text, -1) === '}') ||
            (substr($text, 0, 1) === '[' && substr($text, -1) === ']')) {
            $json = json_decode($text);
            if (json_last_error() === JSON_ERROR_NONE) {
                return true;
            }
        }

        // XML данные
        if (substr($text, 0, 1) === '<' && substr($text, -1) === '>') {
            // Простая проверка на XML структуру
            if (preg_match('/^<[^>]+>.*<\/[^>]+>$/s', $text)) {
                return true;
            }
        }

        // Сериализованные PHP данные
        if (preg_match('/^[aOs]:\d+:/', $text)) {
            return true;
        }

        // Query strings
        if (preg_match('/^[a-zA-Z0-9_]+=.*(&[a-zA-Z0-9_]+=.*)*$/', $text)) {
            return true;
        }

        // Schema.org JSON-LD
        if (strpos($text, '@context') !== false && strpos($text, 'schema.org') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Проверка исключения по DOM элементу
     */
    private function is_excluded_by_dom($node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }

        // Исключенные теги
        $excluded_tags = array('script', 'style', 'noscript', 'head', 'meta', 'link');
        if (in_array(strtolower($node->nodeName), $excluded_tags)) {
            return true;
        }

        // Проверка атрибутов
        if ($this->has_excluded_attributes($node)) {
            return true;
        }

        // Проверка классов
        if ($this->has_excluded_classes($node)) {
            return true;
        }

        // Проверка ID
        if ($this->has_excluded_id($node)) {
            return true;
        }

        return false;
    }

    /**
     * Проверка исключения по родительским элементам
     */
    private function is_excluded_by_ancestry($node) {
        $current = $node->parentNode;
        $depth = 0;
        $max_depth = 8; // Проверяем максимум 8 уровней вверх

        while ($current && $depth < $max_depth) {
            if ($current->nodeType === XML_ELEMENT_NODE) {
                // Исключенные родительские теги
                $excluded_parent_tags = array('script', 'style', 'noscript', 'head');
                if (in_array(strtolower($current->nodeName), $excluded_parent_tags)) {
                    return true;
                }

                // Родительские атрибуты
                if ($this->has_excluded_attributes($current)) {
                    return true;
                }

                // Родительские классы
                if ($this->has_excluded_classes($current)) {
                    return true;
                }

                // Родительские ID
                if ($this->has_excluded_id($current)) {
                    return true;
                }

                // Специальные роли
                $role = $current->getAttribute('role');
                if (in_array($role, array('presentation', 'none', 'complementary'))) {
                    return true;
                }
            }

            $current = $current->parentNode;
            $depth++;
        }

        return false;
    }

    /**
     * Проверка эвристиками
     */
    private function is_excluded_by_heuristics($node, $text) {
        // Если текст повторяется много раз на странице (вероятно navigation)
        if ($this->is_repetitive_text($text)) {
            return true;
        }

        // Если элемент имеет определенные стили (display: none, etc.)
        if ($this->has_hidden_styles($node)) {
            return true;
        }

        // Если это похоже на метаданные
        if ($this->looks_like_metadata($text, $node)) {
            return true;
        }

        return false;
    }

    /**
     * Проверка исключенных атрибутов
     */
    private function has_excluded_attributes($node) {
        foreach ($this->excluded_attributes as $attr => $value) {
            if ($node->hasAttribute($attr)) {
                if ($value === true || $node->getAttribute($attr) === $value) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Проверка исключенных классов
     */
    private function has_excluded_classes($node) {
        if (!$node->hasAttribute('class')) {
            return false;
        }

        $classes = explode(' ', $node->getAttribute('class'));

        foreach ($classes as $class) {
            $class = trim($class);
            if (empty($class)) continue;

            // Точное совпадение
            if (in_array($class, $this->excluded_classes)) {
                return true;
            }

            // Проверка подстрок
            foreach ($this->excluded_classes as $excluded_class) {
                if (strpos($class, $excluded_class) !== false) {
                    return true;
                }
            }

            // Паттерны классов
            if ($this->matches_class_pattern($class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверка паттернов классов
     */
    private function matches_class_pattern($class) {
        $patterns = array(
            '/^wp-/',        // WordPress классы
            '/^admin-/',     // Административные классы
            '/-admin$/',     // Классы заканчивающиеся на admin
            '/^hidden-/',    // Скрытые элементы
            '/-hidden$/',    // Скрытые элементы
            '/^no-/',        // Отрицательные классы
            '/-debug$/',     // Отладочные классы
            '/^dev-/',       // Разработческие классы
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверка исключенных ID
     */
    private function has_excluded_id($node) {
        if (!$node->hasAttribute('id')) {
            return false;
        }

        $id = $node->getAttribute('id');
        return in_array($id, $this->excluded_ids);
    }

    /**
     * Проверка числовых кодов
     */
    private function is_numeric_code($text) {
        // SKU, артикулы, коды товаров
        if (preg_match('/^[A-Z0-9]{5,}$/', $text)) {
            return true;
        }

        // Телефонные номера в некоторых форматах
        if (preg_match('/^\+?[\d\s\-\(\)]{7,}$/', $text) && strlen($text) > 10) {
            return true;
        }

        return false;
    }

    /**
     * Проверка повторяющегося текста
     */
    private function is_repetitive_text($text) {
        // Кэш для подсчета частоты текста
        static $text_frequency = array();

        $normalized_text = trim(strtolower($text));
        if (strlen($normalized_text) < 3) {
            return false;
        }

        if (!isset($text_frequency[$normalized_text])) {
            $text_frequency[$normalized_text] = 0;
        }

        $text_frequency[$normalized_text]++;

        // Если текст встречается более 5 раз, вероятно это navigation
        return $text_frequency[$normalized_text] > 5;
    }

    /**
     * Проверка скрытых стилей
     */
    private function has_hidden_styles($node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return false;
        }

        $style = $node->getAttribute('style');
        if (empty($style)) {
            return false;
        }

        $hidden_patterns = array(
            '/display\s*:\s*none/i',
            '/visibility\s*:\s*hidden/i',
            '/opacity\s*:\s*0/i',
            '/height\s*:\s*0/i',
            '/width\s*:\s*0/i',
        );

        foreach ($hidden_patterns as $pattern) {
            if (preg_match($pattern, $style)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Проверка на метаданные
     */
    private function looks_like_metadata($text, $node) {
        // Форматы даты
        if (preg_match('/\d{1,2}\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)/i', $text)) {
            return true;
        }

        // Автор поста
        if (preg_match('/^(By|Author|Written by)\s+/i', $text)) {
            return true;
        }

        // Категории и теги
        if (preg_match('/^(Category|Categories|Tag|Tags|Filed under):/i', $text)) {
            return true;
        }

        // Время чтения
        if (preg_match('/\d+\s+(min|minute|minutes?)\s+(read|reading)/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Получение статистики фильтрации
     */
    public function get_filter_stats() {
        return array(
            'excluded_selectors_count' => count($this->excluded_selectors),
            'excluded_patterns_count' => count($this->excluded_patterns),
            'excluded_classes_count' => count($this->excluded_classes),
            'excluded_ids_count' => count($this->excluded_ids),
            'excluded_attributes_count' => count($this->excluded_attributes)
        );
    }

    /**
     * Добавление пользовательских правил исключения
     */
    public function add_exclusion_rule($type, $rule) {
        switch ($type) {
            case 'pattern':
                $this->excluded_patterns[] = $rule;
                break;
            case 'class':
                $this->excluded_classes[] = $rule;
                break;
            case 'id':
                $this->excluded_ids[] = $rule;
                break;
            case 'attribute':
                if (is_array($rule) && count($rule) === 2) {
                    $this->excluded_attributes[$rule[0]] = $rule[1];
                }
                break;
        }
    }

    /**
     * Тестирование фильтра на конкретном тексте
     * Для отладки и настройки правил
     */
    public function test_filter($text, $mock_node = null) {
        $result = array(
            'text' => $text,
            'should_exclude' => false,
            'reasons' => array()
        );

        if ($this->is_excluded_by_text($text)) {
            $result['should_exclude'] = true;
            $result['reasons'][] = 'excluded_by_text';
        }

        if ($mock_node && $this->is_excluded_by_dom($mock_node)) {
            $result['should_exclude'] = true;
            $result['reasons'][] = 'excluded_by_dom';
        }

        return $result;
    }
}