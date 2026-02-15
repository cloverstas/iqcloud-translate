<?php
/**
 * Lingua HTML DOM Parser Wrapper
 *
 * Обертка для Simple HTML DOM Parser
 * Обеспечивает удобный интерфейс для работы с DOM в Lingua
 *
 * @package Lingua
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Загружаем Simple HTML DOM Parser
require_once __DIR__ . '/simple_html_dom.php';

// Constants are now defined in simple_html_dom.php with LINGUA_ prefix directly.
// No additional definitions needed here - they are loaded via require_once above.

/**
 * Функция-обертка для создания HTML DOM
 *
 * @param string $str HTML string
 * @param bool $lowercase Convert tags to lowercase
 * @param bool $forceTagsClosed Force tags to be closed
 * @param string $target_charset Target charset
 * @param bool $stripRN Strip \r and \n
 * @param string $defaultBRText Default BR text
 * @param string $defaultSpanText Default SPAN text
 * @return simple_html_dom|false
 */
function lingua_str_get_html(
    $str,
    $lowercase = true,
    $forceTagsClosed = true,
    $target_charset = LINGUA_DEFAULT_TARGET_CHARSET,
    $stripRN = true,
    $defaultBRText = LINGUA_DEFAULT_BR_TEXT,
    $defaultSpanText = LINGUA_DEFAULT_SPAN_TEXT
) {
    return \Lingua\str_get_html(
        $str,
        $lowercase,
        $forceTagsClosed,
        $target_charset,
        $stripRN,
        $defaultBRText,
        $defaultSpanText
    );
}

/**
 * Класс-обертка для Simple HTML DOM в стиле Lingua
 */
class Lingua_HTML_DOM {

    private $dom;

    public function __construct($html = null) {
        if ($html) {
            $this->load($html);
        }
    }

    /**
     * Загрузить HTML
     */
    public function load($html) {
        $this->dom = lingua_str_get_html($html, true, true, LINGUA_DEFAULT_TARGET_CHARSET, false);
        return $this->dom !== false;
    }

    /**
     * Найти элементы по селектору
     */
    public function find($selector) {
        if (!$this->dom) {
            return array();
        }
        return $this->dom->find($selector);
    }

    /**
     * Сохранить HTML
     */
    public function save() {
        if (!$this->dom) {
            return '';
        }
        return $this->dom->save();
    }

    /**
     * Получить весь HTML
     */
    public function outertext() {
        if (!$this->dom) {
            return '';
        }
        return $this->dom->outertext;
    }

    /**
     * Очистить память
     */
    public function clear() {
        if ($this->dom) {
            $this->dom->clear();
            $this->dom = null;
        }
    }

    /**
     * Деструктор
     */
    public function __destruct() {
        $this->clear();
    }
}