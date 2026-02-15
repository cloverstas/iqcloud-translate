<?php
/**
 * Lingua Full DOM Extractor
 *
 * Извлечение всего переводимого контента из полного HTML страницы
 * Использует Simple HTML DOM Parser для DOM-based extraction
 *
 * @package Lingua
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Загружаем Simple HTML DOM Parser
require_once LINGUA_PLUGIN_DIR . 'includes/lib/lingua-html-dom.php';

class Lingua_Full_Dom_Extractor {

    private $content_filter;
    private $no_translate_attribute = 'data-no-translation';
    private $no_auto_translate_attribute = 'data-no-auto-translation';

    public function __construct() {
        // Инициализируем фильтр контента при его наличии
        if (class_exists('Lingua_Content_Filter')) {
            $this->content_filter = new Lingua_Content_Filter();
        }
    }

    /**
     * Главный метод извлечения контента из полного HTML
     *
     * @param string $html Полный HTML страницы
     * @param int $post_id ID поста для контекста
     * @return array Структурированный контент по категориям
     */
    public function extract_from_full_html($html, $post_id = null) {
        // v5.2.197: Wrap debug logs in WP_DEBUG for performance
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log('[Lingua DOM] extract_from_full_html START for post ' . $post_id . ', HTML length: ' . strlen($html ?? ''));
        }

        if (empty($html)) {
            lingua_debug_log('[Lingua DOM] ERROR: HTML is empty!');
            return $this->get_empty_structure();
        }

        // Создаем DOM с помощью Simple HTML DOM Parser
        $dom = lingua_str_get_html($html, true, true, LINGUA_DEFAULT_TARGET_CHARSET, false);
        if ($dom === false) {
            lingua_debug_log('[Lingua DOM] ERROR: Failed to create DOM object!');
            return $this->get_empty_structure();
        }

        // v3.1: Применяем фильтры пропуска
        $this->apply_no_translate_filters($dom);

        $translateable_strings = array();
        $translateable_nodes = array();

        // РЕФАКТОРИНГ v3.1: Разделили extraction на 3 типа

        // v5.0.14: КРИТИЧНО - Extract breadcrumbs FIRST (before general text nodes)
        // Breadcrumbs often get missed because they're in <a> and <span> tags
        $this->extract_breadcrumbs($dom, $translateable_strings, $translateable_nodes);

        // ТИП 1: Content Blocks (большие блоки контента - параграфы, заголовки, div с текстом)
        $this->extract_content_blocks($dom, $translateable_strings, $translateable_nodes);

        // ТИП 2: Text Nodes (чистые текстовые узлы без вложенных block-level элементов)
        $this->extract_text_nodes($dom, $translateable_strings, $translateable_nodes);

        // v5.4.0: ТИП 2.5: Bare text nodes в mixed-content элементах
        // Извлекает чистый текст из элементов вида: <div><span class="icon"></span> Текст</div>
        // Где "Текст" — это bare textNode, не обёрнутый ни в какой тег
        $this->extract_bare_text_from_mixed_content($dom, $translateable_strings, $translateable_nodes);

        // v5.1: MEDIA - Images with all attributes (src, alt, title, srcset)
        $this->extract_media_elements($dom, $translateable_strings, $translateable_nodes);

        // ТИП 3: HTML Attributes (alt, title, placeholder, aria-label, data-tooltip)
        $this->extract_html_attributes($dom, $translateable_strings, $translateable_nodes);

        // ДОПОЛНИТЕЛЬНО: Form Elements (кнопки, инпуты, селекты)
        $this->extract_form_elements($dom, $translateable_strings, $translateable_nodes);

        // v3.6: CRITICAL FIX - Temporarily disable all translation filters for SEO extraction
        // Yoast/RankMath may apply translation filters to get_post_meta() when on /en/ URL
        // We need to extract ORIGINAL SEO values, not translated ones
        $this->disable_all_translation_filters();

        // v3.2: SEO мета-данные напрямую из WordPress
        $this->extract_seo_metadata($post_id, $translateable_strings, $translateable_nodes);

        // v3.6: Restore translation filters
        $this->restore_all_translation_filters();

        // ДОПОЛНИТЕЛЬНО: Meta Tags из DOM (для дополнительных тегов)
        $this->extract_meta_tags($dom, $translateable_strings, $translateable_nodes);

        // Очищаем память
        $dom->clear();

        // Классифицируем и структурируем результат
        $structured_content = $this->classify_extracted_content($translateable_strings, $translateable_nodes, $post_id);

        // Логируем статистику
        $this->log_extraction_stats($post_id, count($translateable_strings), $structured_content);

        return $structured_content;
    }

    /**
     * v3.1: Применяем фильтры пропуска (data-no-translation selectors)
     * Маркируем элементы которые НЕ нужно переводить атрибутом data-no-translation
     */
    private function apply_no_translate_filters($dom) {

        // Селекторы элементов которые НЕ нужно переводить (как в TP)
        $no_translate_selectors = apply_filters('lingua_no_translate_selectors', array(
            '#wpadminbar',              // WordPress admin bar
            'script',                   // JavaScript
            'style',                    // CSS
            'noscript',                 // NoScript fallback
            'code',                     // Code blocks
            'pre',                      // Preformatted text
            '[data-no-translation]',    // Explicitly marked
            '.notranslate',             // Google Translate standard
            '[translate="no"]',         // HTML5 translate attribute
            '.screen-reader-text',      // Hidden accessibility text
            '.wp-block-code',           // Gutenberg code blocks
            'head',                     // HTML head
            // v5.0.12: WoodMart/WooCommerce technical elements (admin only - no user-facing content)
            '.create-nav-msg'           // WoodMart admin messages (not visible to users)
            // v5.2.4: REMOVED ALL widgets and UI elements that may contain GETTEXT:
            // - .woodmart-wishlist-info-widget ("No items in wishlist" - WoodMart GETTEXT)
            // - .shop-view ("Grid view", "List view" - theme GETTEXT)
            // - [class*="variation-swatch"] ("Small", "Medium", "Red" - WooCommerce/theme GETTEXT)
            // - Counters like .mini-cart-count ("3 items" - WooCommerce GETTEXT)
            // - .woocommerce-result-count ("Showing 1-16 of 20 results" - WooCommerce GETTEXT)
            // - .woocommerce-ordering (Sort dropdown options - WooCommerce GETTEXT)
            // - .reset_variations ("Clear" button - WooCommerce GETTEXT)
            // - Add to cart buttons (user-facing GETTEXT strings)
            //
            // Pure technical content (numbers, icons) will be filtered by is_numeric() and is_technical_content()
        ));

        $marked_elements = 0;

        foreach ($no_translate_selectors as $selector) {
            $elements = $dom->find($selector);
            if (!$elements) continue;

            foreach ($elements as $element) {
                // Проверка что элемент существует и это объект
                if (!is_object($element)) continue;

                // Simple HTML DOM: проверяем атрибут через hasAttribute
                if (!$element->hasAttribute($this->no_translate_attribute)) {
                    $element->setAttribute($this->no_translate_attribute, '');
                    $marked_elements++;
                }
            }
        }

    }

    /**
     * Получение списка Top Parents тегов
     *
     * @return array Теги, которые маркируют границы блоков перевода
     */
    private function get_top_parents() {
        return array(
            'div', 'p', 'span', 'section', 'article', 'aside', 'header', 'footer', 'main',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'li', 'td', 'th', 'blockquote', 'figcaption',
            'label', 'button', 'a', 'strong', 'b', 'em', 'i'
        );
    }

    /**
     * ТИП 1: Извлечение Content Blocks     *
     * Извлекаем БОЛЬШИЕ блоки контента:
     * - Параграфы (<p>)
     * - Заголовки (h1-h6)
     * - Div с текстом
     * - Блоки с inline HTML (например: "Текст <br> еще текст")
     *
     * Находит block-level элементы для перевода
     */
    private function extract_content_blocks($dom, &$translateable_strings, &$translateable_nodes) {
        $found_blocks = 0;

        // v3.1: ФОКУС на block-level elements
        $content_block_selectors = array('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'li', 'td', 'th', 'blockquote', 'figcaption');

        foreach ($content_block_selectors as $tag) {
            $elements = $dom->find($tag);

            foreach ($elements as $element) {
                // 1. Пропускаем исключенные блоки
                if ($this->should_exclude_block($element)) {
                    continue;
                }

                // 2. v3.1: Пропускаем элементы с block-level дочерними элементами (это контейнеры)
                if ($this->has_block_level_children($element)) {
                    continue;
                }

                // 3. Извлекаем текст
                $text = $this->get_direct_text_only($element);
                $trimmed_text = $this->trim_translation_block($text);

                // 4. Валидация
                if (strlen($trimmed_text) < 2) {
                    continue;
                }

                if ($this->is_technical_content($trimmed_text)) {
                    continue;
                }

                // 5. Дедупликация
                if (in_array($trimmed_text, $translateable_strings)) {
                    continue;
                }

                // v5.2.43: Get English gettext with domain info
                $gettext_info = $this->get_english_gettext($trimmed_text, 'woodmart');

                // v5.2.43: CRITICAL - Use msgid as original_text for gettext strings
                $original_text = $gettext_info ? $gettext_info['msgid'] : $trimmed_text;

                // 6. Добавляем content block
                $translateable_strings[] = $original_text;

                $translateable_nodes[] = array(
                    'type' => 'content_block',
                    'context' => 'block',
                    'element' => $element->tag,
                    'parent_tag' => $element->parent() ? $element->parent()->tag : '',
                    // v5.2.43: New architecture - msgid as original
                    'source' => $gettext_info ? 'gettext' : 'custom',
                    'gettext_domain' => $gettext_info['domain'] ?? null,
                    'english_original' => $gettext_info['msgid'] ?? null,
                    'is_plural' => $gettext_info['is_plural'] ?? false,
                    'plural_pair' => $gettext_info['plural_pair'] ?? null,  // v5.2.43: For grouping in UI
                    'russian_forms' => $gettext_info['russian_forms'] ?? null  // v5.2.50: All 3 Russian forms for Default editing
                );
                $found_blocks++;

            }
        }
    }

    /**
     * v5.0.14: Extract breadcrumbs specifically (WooCommerce/Yoast breadcrumbs)
     * These are often missed by general extraction
     */
    private function extract_breadcrumbs($dom, &$translateable_strings, &$translateable_nodes) {
        $found_breadcrumbs = 0;

        // WooCommerce and theme breadcrumb selectors
        $breadcrumb_selectors = array(
            '.woocommerce-breadcrumb a',      // WooCommerce breadcrumb links
            '.woocommerce-breadcrumb span',   // WooCommerce breadcrumb current item
            '.wd-breadcrumbs a',               // WoodMart breadcrumb links
            '.wd-breadcrumbs span',            // WoodMart breadcrumb spans
            '.breadcrumb a',                   // Generic breadcrumb links
            '.breadcrumb span',                // Generic breadcrumb spans
            'nav[aria-label="Breadcrumb"] a',  // Semantic breadcrumb links
            'nav[aria-label="Breadcrumb"] span' // Semantic breadcrumb spans
        );

        foreach ($breadcrumb_selectors as $selector) {
            $elements = $dom->find($selector);

            foreach ($elements as $element) {
                // Skip if excluded
                if ($this->should_exclude_block($element)) {
                    continue;
                }

                // Get text
                $text = trim($element->innertext);

                // Skip if has block-level children (container)
                if ($this->has_block_level_children($element)) {
                    continue;
                }

                // Clean text
                $trimmed_text = $this->trim_translation_block($text);

                // Validation
                if (strlen($trimmed_text) < 2) {
                    continue;
                }

                if ($this->is_technical_content($trimmed_text)) {
                    continue;
                }

                // Deduplication
                if (in_array($trimmed_text, $translateable_strings)) {
                    continue;
                }

                // Add breadcrumb item
                $translateable_strings[] = $trimmed_text;
                $translateable_nodes[] = array(
                    'type' => 'breadcrumb_item',
                    'context' => 'block',  // Goes to content_blocks
                    'element' => $element->tag,
                    'parent_tag' => $element->parent() ? $element->parent()->tag : ''
                );
                $found_breadcrumbs++;
            }
        }
    }

    /**
     * ТИП 2: Извлечение Text Nodes     *
     * Извлекаем ЧИСТЫЕ текстовые узлы без вложенных block-level элементов:
     * - Span с текстом
     * - Button text
     * - Link text
     * - Текст внутри inline элементов
     *
     * Извлекает текстовые ноды через find('linguatext')
     */
    private function extract_text_nodes($dom, &$translateable_strings, &$translateable_nodes) {
        $found_text_nodes = 0;

        // v3.1: Inline и text-level элементы
        $text_node_selectors = array('span', 'a', 'strong', 'b', 'em', 'i', 'small', 'mark', 'del', 'ins', 'code', 'kbd', 'samp', 'var');

        foreach ($text_node_selectors as $tag) {
            $elements = $dom->find($tag);

            foreach ($elements as $element) {
                // 1. Пропускаем если это внутри исключенного блока
                if ($this->should_exclude_block($element)) {
                    continue;
                }

                // 1.5 v5.2.183: Пропускаем inline элементы внутри content blocks (p, li, h1-h6, etc.)
                // Это предотвращает двойную экстракцию: <p><strong>Toyota</strong> is...</p>
                // Весь параграф уже извлечён в extract_content_blocks(), strong не нужен отдельно
                if ($this->is_inside_content_block($element)) {
                    continue;
                }

                // 2. Пропускаем если уже обработан как content_block
                if ($this->has_block_level_children($element)) {
                    continue;
                }

                // 3. Получаем текст
                $text = trim($element->innertext);
                $trimmed_text = $this->trim_translation_block($text);

                // 4. Валидация
                if (strlen($trimmed_text) < 2) {
                    continue;
                }

                if ($this->is_technical_content($trimmed_text)) {
                    continue;
                }

                // 5. Пропускаем если это только HTML теги без текста
                if (strip_tags($trimmed_text) === '') {
                    continue;
                }

                // 6. Дедупликация
                if (in_array($trimmed_text, $translateable_strings)) {
                    continue;
                }

                // v5.2.43: Get English gettext with domain info
                $gettext_info = $this->get_english_gettext($trimmed_text, 'woodmart');

                // v5.2.43: CRITICAL - Use msgid as original_text for gettext strings
                $original_text = $gettext_info ? $gettext_info['msgid'] : $trimmed_text;

                // 7. Добавляем text node
                $translateable_strings[] = $original_text;

                $translateable_nodes[] = array(
                    'type' => 'text_node',
                    'context' => 'block',  // Идет в content_blocks
                    'element' => $element->tag,
                    'parent_tag' => $element->parent() ? $element->parent()->tag : '',
                    // v5.2.43: New architecture - msgid as original
                    'source' => $gettext_info ? 'gettext' : 'custom',
                    'gettext_domain' => $gettext_info['domain'] ?? null,
                    'english_original' => $gettext_info['msgid'] ?? null,
                    'is_plural' => $gettext_info['is_plural'] ?? false,
                    'plural_pair' => $gettext_info['plural_pair'] ?? null,  // v5.2.45: CRITICAL FIX - was missing for text_nodes!
                    'russian_forms' => $gettext_info['russian_forms'] ?? null  // v5.2.50: All 3 Russian forms for Default editing
                );
                $found_text_nodes++;
            }
        }
    }

    /**
     * v5.4.0: Извлечение bare text nodes из mixed-content элементов
     *
     * Проблема: extract_content_blocks() извлекает innerHTML целиком, включая HTML теги дочерних элементов.
     * Например: <div><span class="dot"></span> Российский продукт</div>
     * → извлекается: "<span class="dot"></span> Российский продукт"
     *
     * Но rendering (DOMDocument XPath) находит только bare text nodes: "Российский продукт"
     * → не совпадает с сохранённой строкой → перевод не применяется!
     *
     * Решение: дополнительно извлекаем ЧИСТЫЙ текст (без HTML тегов) из таких элементов.
     * Это гарантирует, что rendering найдёт совпадение в БД.
     */
    private function extract_bare_text_from_mixed_content($dom, &$translateable_strings, &$translateable_nodes) {
        $found_bare = 0;

        // Те же селекторы что и в extract_content_blocks + extract_text_nodes
        $all_selectors = array('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'li', 'td', 'th', 'blockquote', 'figcaption', 'span', 'a', 'strong', 'b', 'em', 'i', 'small', 'label', 'button');

        foreach ($all_selectors as $tag) {
            $elements = $dom->find($tag);

            foreach ($elements as $element) {
                // 1. Пропускаем исключенные
                if ($this->should_exclude_block($element)) {
                    continue;
                }

                // 2. Пропускаем контейнеры с block-level детьми
                if ($this->has_block_level_children($element)) {
                    continue;
                }

                $children = $element->children();

                // 3. Нет детей = нет mixed content, уже обработан как обычный текст
                if (empty($children)) {
                    continue;
                }

                // 4. Получаем bare text: убираем outertext всех дочерних элементов из innertext
                $remaining = $element->innertext;
                foreach ($children as $child) {
                    if (is_object($child) && isset($child->outertext)) {
                        $pos = strpos($remaining, $child->outertext);
                        if ($pos !== false) {
                            $remaining = substr_replace($remaining, ' ', $pos, strlen($child->outertext));
                        }
                    }
                }

                // 5. Очищаем: убираем оставшиеся HTML теги (br и пр.), нормализуем пробелы
                $bare_text = strip_tags($remaining);
                $bare_text = preg_replace('/\s+/u', ' ', $bare_text);
                $bare_text = trim($bare_text);

                // 6. Валидация
                if (strlen($bare_text) < 2) {
                    continue;
                }

                if ($this->is_technical_content($bare_text)) {
                    continue;
                }

                // 7. Проверяем что это НЕ совпадает с полной строкой (иначе дубль)
                // Если full innerHTML (после trim) === bare_text, значит нет mixed content
                $full_text = $this->get_direct_text_only($element);
                $full_trimmed = $this->trim_translation_block($full_text);
                if ($bare_text === $full_trimmed) {
                    continue; // Уже извлечено как полная строка
                }

                // v5.5.1: If the full string (with inline HTML) was already extracted as a content block,
                // don't extract the bare text portion separately — it's already part of the unified string
                if (in_array($full_trimmed, $translateable_strings) && $this->contains_inline_html($full_trimmed)) {
                    continue; // Full string with inline HTML already extracted, skip bare portion
                }

                // 8. Дедупликация
                if (in_array($bare_text, $translateable_strings)) {
                    continue;
                }

                // 9. Добавляем bare text node
                $translateable_strings[] = $bare_text;

                $translateable_nodes[] = array(
                    'type' => 'bare_text_node',
                    'context' => 'block',
                    'element' => $element->tag,
                    'parent_tag' => $element->parent() ? $element->parent()->tag : '',
                    'source' => 'custom'
                );
                $found_bare++;
            }
        }

        // v5.4.1: Also extract text from inline children of content blocks (mixed content)
        // Problem: <h1>Text<br><span class="highlight">more text</span></h1>
        // extract_content_blocks() extracts full HTML: "Text<br><span>more text</span>"
        // extract_text_nodes() skips span because is_inside_content_block() = true
        // extract_bare_text_from_mixed_content() skips span because empty($children)
        // Result: "more text" is NEVER extracted separately → auto-translate can't handle it
        // Fix: extract inline children text from mixed-content content blocks
        $inline_tags = array('span', 'strong', 'b', 'em', 'i', 'u', 'a', 'small', 'mark', 'del', 'ins', 'sub', 'sup');
        $content_block_tags = array('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'li', 'td', 'th', 'blockquote', 'figcaption');

        foreach ($content_block_tags as $block_tag) {
            $blocks = $dom->find($block_tag);

            foreach ($blocks as $block) {
                if ($this->should_exclude_block($block)) {
                    continue;
                }

                $block_children = $block->children();
                if (empty($block_children)) {
                    continue; // No children = pure text, already handled
                }

                // Check if this block has mixed content (both text nodes and inline children)
                $has_inline_children = false;
                foreach ($block_children as $child) {
                    if (is_object($child) && in_array(strtolower($child->tag), $inline_tags)) {
                        $has_inline_children = true;
                        break;
                    }
                }

                if (!$has_inline_children) {
                    continue;
                }

                // v5.5.1: If the full block text (with inline HTML) was already extracted
                // as a unified content_block, skip extracting inline children separately.
                // This prevents splitting "Переведите сайт на <span>любой язык</span>"
                // into separate "любой язык" when the full string is already in the list.
                $block_full_text = $this->get_direct_text_only($block);
                $block_full_trimmed = $this->trim_translation_block($block_full_text);
                if (in_array($block_full_trimmed, $translateable_strings) && $this->contains_inline_html($block_full_trimmed)) {
                    continue; // Full string with inline HTML already extracted, skip inline children
                }

                // Extract text from each inline child separately
                foreach ($block_children as $child) {
                    if (!is_object($child)) continue;
                    if (!in_array(strtolower($child->tag), $inline_tags)) continue;

                    $child_text = trim(strip_tags($child->innertext));
                    $child_text = preg_replace('/\s+/u', ' ', $child_text);
                    $child_text = trim($child_text);

                    if (strlen($child_text) < 2) continue;
                    if ($this->is_technical_content($child_text)) continue;
                    if (in_array($child_text, $translateable_strings)) continue;

                    $translateable_strings[] = $child_text;
                    $translateable_nodes[] = array(
                        'type' => 'inline_child_text',
                        'context' => 'block',
                        'element' => $child->tag,
                        'parent_tag' => $block->tag,
                        'source' => 'custom'
                    );
                    $found_bare++;
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG && $found_bare > 0) {
            lingua_debug_log("[Lingua DOM v5.4.1] Extracted {$found_bare} bare/inline text nodes from mixed-content elements");
        }
    }

    /**
     * v3.1: Проверяет, есть ли у элемента block-level дочерние элементы
     * Используется чтобы не извлекать контейнеры
     */
    private function has_block_level_children($element) {
        $block_level_tags = array('div', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'section', 'article', 'header', 'footer', 'nav', 'aside', 'blockquote', 'form');

        $children = $element->children();
        if (empty($children)) {
            return false;
        }

        foreach ($children as $child) {
            if (in_array(strtolower($child->tag), $block_level_tags)) {
                return true;  // Найден block-level дочерний элемент
            }
        }

        return false;
    }

    /**
     * v5.2.183: Проверяет, находится ли inline элемент внутри block-level контент блока
     *
     * Используется для предотвращения двойной экстракции:
     * - <p><strong>Bold</strong> text</p> - strong внутри p, НЕ извлекаем strong отдельно
     * - <div><a href="#">Link</a></div> - a внутри div-контейнера, можно извлечь
     *
     * @param simple_html_dom_node $element
     * @return bool True если элемент внутри content block (p, li, h1-h6 и т.д.)
     */
    private function is_inside_content_block($element) {
        // Block-level элементы, которые являются единицами перевода
        // Должны совпадать с $content_block_selectors в extract_content_blocks()
        // НО без div - div это контейнер, inline элементы внутри div нужно извлекать
        $content_block_tags = array('p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'td', 'th', 'blockquote', 'figcaption', 'dt', 'dd', 'caption', 'label');

        $parent = $element->parent();
        $depth = 0;
        $max_depth = 10; // Защита от бесконечного цикла

        while ($parent && $depth < $max_depth) {
            $parent_tag = strtolower($parent->tag);

            // Если родитель - content block, проверяем есть ли у него прямой текст
            if (in_array($parent_tag, $content_block_tags)) {
                // v5.2.194: Улучшенная логика - пропускаем только если родитель содержит прямой текст
                // Примеры:
                // <p><strong>Bold</strong> text</p> - есть прямой текст " text", пропускаем strong
                // <li><a><span>Text</span></a></li> - нет прямого текста в li, НЕ пропускаем
                if ($this->has_direct_text_content($parent)) {
                    return true;
                }
                // Если content block не имеет прямого текста, продолжаем проверку
                // НО прерываем поиск - дальше искать не нужно
                return false;
            }

            // Останавливаемся на root или body
            if ($parent_tag === 'root' || $parent_tag === 'body' || $parent_tag === 'html') {
                break;
            }

            $parent = $parent->parent();
            $depth++;
        }

        return false;
    }

    /**
     * v5.2.197: Оптимизированная проверка прямого текста
     * Проверяет есть ли у элемента текст напрямую (не только внутри дочерних тегов)
     *
     * @param simple_html_dom_node $element
     * @return bool True если есть прямой текст
     */
    private function has_direct_text_content($element) {
        // Быстрая проверка: если нет innertext - нет прямого текста
        $innertext = $element->innertext ?? '';
        if (empty($innertext)) {
            return false;
        }

        // Удаляем outertext всех прямых детей из innertext
        // Если после этого остался текст (не пробелы) - значит есть прямой текст
        $remaining = $innertext;
        foreach ($element->children() as $child) {
            if (is_object($child) && isset($child->outertext)) {
                // Удаляем первое вхождение outertext ребёнка
                $pos = strpos($remaining, $child->outertext);
                if ($pos !== false) {
                    $remaining = substr_replace($remaining, '', $pos, strlen($child->outertext));
                }
            }
        }

        // Проверяем остался ли текст (не только пробелы/переносы)
        return trim($remaining) !== '';
    }

    /**
     * v3.0.28: Определяет, содержит ли текст inline HTML теги
     */
    private function contains_inline_html($text) {
        $inline_tags = array('span', 'br', 'strong', 'b', 'em', 'i', 'u', 'a', 'small', 'mark', 'del', 'ins', 'sub', 'sup', 'code');

        foreach ($inline_tags as $tag) {
            if (stripos($text, "<{$tag}") !== false || stripos($text, "<{$tag}>") !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получение ТОЛЬКО прямого текста элемента (без дочерних элементов)
     * Аналог linguatext nodes из DOM парсера
     *
     * v3.0.26: ИСПРАВЛЕНИЕ innertext - он сжимает пробелы вокруг тегов!
     * Используем outertext и удаляем только wrapper теги
     */
    private function get_direct_text_only($element) {
        $children = $element->children();

        // Если нет дочерних элементов - возвращаем весь innertext
        if (empty($children)) {
            $text = $element->innertext;
            // v3.0.29: Нормализуем HTML теги
            return $this->normalize_html_tags($text);
        }

        // v3.0.26: Список inline тегов, которые НУЖНО СОХРАНИТЬ в тексте
        $inline_tags = array('br', 'span', 'strong', 'b', 'em', 'i', 'u', 'a', 'small', 'mark', 'del', 'ins', 'sub', 'sup', 'code');

        // v3.0.26: Проверяем, есть ли block-level дочерние элементы
        $has_block_children = false;
        foreach ($children as $child) {
            if (!in_array(strtolower($child->tag), $inline_tags)) {
                $has_block_children = true;
                break;
            }
        }

        // v3.0.26: Если все дочерние элементы inline - используем outertext и удаляем wrapper!
        // innertext сжимает пробелы - это проблема Simple HTML DOM
        if (!$has_block_children) {
            $content = $element->outertext;
            $tag = $element->tag;

            // Удаляем открывающий и закрывающий теги wrapper элемента
            // Например: <p class="foo">text</p> -> text
            $content = preg_replace('/^<' . preg_quote($tag, '/') . '[^>]*>/i', '', $content);
            $content = preg_replace('/<\/' . preg_quote($tag, '/') . '>$/i', '', $content);

            // v3.0.29: Нормализуем HTML теги
            $content = $this->normalize_html_tags($content);

            return trim($content);
        }

        // v3.0.26: Если есть block-level элементы - удаляем их, но сохраняем inline
        $inner_html = $element->outertext;

        // Сначала удаляем wrapper element
        $tag = $element->tag;
        $inner_html = preg_replace('/^<' . preg_quote($tag, '/') . '[^>]*>/i', '', $inner_html);
        $inner_html = preg_replace('/<\/' . preg_quote($tag, '/') . '>$/i', '', $inner_html);

        // Затем удаляем block-level child элементы
        foreach ($children as $child) {
            if (!in_array(strtolower($child->tag), $inline_tags)) {
                // Заменяем block-level элементы на ПРОБЕЛ
                $inner_html = str_replace($child->outertext, ' ', $inner_html);
            }
        }

        // v3.0.29: Нормализуем HTML теги для соответствия WordPress wpautop()
        $inner_html = $this->normalize_html_tags($inner_html);

        return trim($inner_html);
    }

    /**
     * v3.0.29: Нормализует HTML теги для соответствия WordPress wpautop()
     * WordPress конвертирует <br> в <br />, поэтому нужно нормализовать перед сохранением
     */
    private function normalize_html_tags($text) {
        // Нормализуем <br> -> <br />
        $text = preg_replace('/<br\s*>/i', '<br />', $text);

        return $text;
    }

    /**
     * Рекурсивная проверка наличия дочерних top_parent тегов
     *
     * @param object $element Элемент для проверки
     * @param array $tags Массив top_parent тегов
     * @return bool TRUE если найдены дочерние top_parents (= это контейнер, нужно пропустить)
     */
    private function check_children_for_tags($element, $tags) {
        // Получаем дочерние элементы
        $children = $element->children();

        if (empty($children)) {
            return false; // Нет детей - это финальный элемент
        }

        // Проверяем каждого ребенка
        foreach ($children as $child) {
            // Если тег ребенка находится в списке top_parents
            if (in_array($child->tag, $tags)) {
                return true; // НАЙДЕН дочерний top_parent - это контейнер!
            }

            // Рекурсивно проверяем детей этого ребенка
            if ($this->check_children_for_tags($child, $tags)) {
                return true; // В глубине найден top_parent
            }
        }

        return false; // Не найдено дочерних top_parents - это финальный блок
    }

    /**
     * Нормализация текста блока перевода (v3.0.19: PRESERVE HTML tags like <br>, <span>)
     *
     * КРИТИЧЕСКОЕ ИЗМЕНЕНИЕ v3.0.19:
     * - НЕ удаляем HTML теги! Yandex API с format="HTML" их сохраняет!
     * - Только декодируем entities и нормализуем пробелы
     * - Это позволяет переводить "Текст<br>Еще" → "Translation<br>More"
     */
    private function trim_translation_block($html) {
        // v3.0.19: НЕ удаляем HTML теги! Сохраняем <br>, <span> и т.д.
        // Старый код: $text = wp_strip_all_tags($html);  ← УДАЛЯЛ ТЕГИ!

        // 1. Декодируем HTML entities (но сохраняем теги)
        $text = htmlspecialchars_decode($html, ENT_QUOTES);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Нормализуем пробельные символы (все пробелы → один пробел)
        $text = preg_replace('/\s+/u', ' ', $text);

        // 3. Удаляем пробелы в начале и конце
        $text = trim($text);

        return $text;
    }

    /**
     * Извлечение Form Elements (v3.0 + Top Parents check)
     */
    private function extract_form_elements($dom, &$translateable_strings, &$translateable_nodes) {
        // Top Parents для проверки контейнеров
        $top_parents = $this->get_top_parents();

        // 1. Submit buttons, buttons, reset buttons
        $buttons = $dom->find('input[type=submit], input[type=button], input[type=reset], button');
        foreach ($buttons as $button) {
            if ($this->has_ancestor_attribute($button, $this->no_translate_attribute)) {
                continue;
            }

            // КРИТИЧНО v3.0 TP: Пропускаем если содержит дочерние top_parents
            if ($this->check_children_for_tags($button, $top_parents)) {
                continue;
            }

            $value = $button->value ?: $button->innertext;

            // v5.5.1: Strip SVG, img and other non-text elements from button innertext
            // FAQ buttons contain SVG icons that cause is_technical_content() to reject the text
            $value = preg_replace('/<svg[\s>].*?<\/svg>/is', '', $value);
            $value = preg_replace('/<img\s[^>]*>/is', '', $value);

            $text_content = $this->trim_translation_block($value);

            if (strlen($text_content) > 0 && !$this->is_technical_content($text_content)) {
                // v5.2.58: Get English gettext with domain info
                $gettext_info = $this->get_english_gettext($text_content, 'woocommerce');

                // v5.2.58: Use msgid as original_text for gettext strings
                $original_text = $gettext_info ? $gettext_info['msgid'] : $text_content;

                $translateable_strings[] = $original_text;

                $node = array(
                    'type' => 'button_text',
                    'context' => 'form_element',
                    'element' => $button->tag,
                    'original' => $original_text
                );

                // v5.2.58: Add gettext metadata if found
                if ($gettext_info) {
                    $node['source'] = 'gettext';
                    $node['gettext_domain'] = $gettext_info['domain'];
                    $node['english_original'] = $gettext_info['msgid'];
                    if (!empty($gettext_info['plural_pair'])) {
                        $node['plural_pair'] = $gettext_info['plural_pair'];
                    }
                }

                $translateable_nodes[] = $node;
            }
        }

        // 2. Option elements in select
        $options = $dom->find('option');
        foreach ($options as $option) {
            if ($this->has_ancestor_attribute($option, $this->no_translate_attribute)) {
                continue;
            }

            $text_content = trim($this->get_clean_text($option->innertext));
            if (strlen($text_content) > 0 && !$this->is_technical_content($text_content)) {
                // v5.2.58: Get English gettext with domain info
                $gettext_info = $this->get_english_gettext($text_content, 'woocommerce');

                // v5.2.58: Use msgid as original_text for gettext strings
                $original_text = $gettext_info ? $gettext_info['msgid'] : $text_content;

                $translateable_strings[] = $original_text;

                $node = array(
                    'type' => 'select_option',
                    'context' => 'form_element',
                    'element' => 'option',
                    'original' => $original_text
                );

                // v5.2.58: Add gettext metadata if found
                if ($gettext_info) {
                    $node['source'] = 'gettext';
                    $node['gettext_domain'] = $gettext_info['domain'];
                    $node['english_original'] = $gettext_info['msgid'];
                    if (!empty($gettext_info['plural_pair'])) {
                        $node['plural_pair'] = $gettext_info['plural_pair'];
                    }
                }

                $translateable_nodes[] = $node;
            }
        }

        // 3. Labels (включая WooCommerce variations)
        $label_selectors = array(
            'label',
            '.variations th.label label', // WooCommerce вариации
            '.wd-attr-name-label',        // Woodmart theme
            '.attribute-label'            // Общий селектор для атрибутов
        );

        foreach ($label_selectors as $label_selector) {
            $labels = $dom->find($label_selector);
            foreach ($labels as $label) {
                if ($this->has_ancestor_attribute($label, $this->no_translate_attribute)) {
                    continue;
                }

                $text_content = trim($this->get_clean_text($label->innertext));
                if (strlen($text_content) > 0 && !$this->is_technical_content($text_content)) {
                    // v5.2.58: Get English gettext with domain info
                    $gettext_info = $this->get_english_gettext($text_content, 'woocommerce');

                    // v5.2.58: Use msgid as original_text for gettext strings
                    $original_text = $gettext_info ? $gettext_info['msgid'] : $text_content;

                    $translateable_strings[] = $original_text;

                    $node = array(
                        'type' => 'form_label',
                        'context' => 'form_element',
                        'element' => 'label',
                        'selector' => $label_selector,
                        'original' => $original_text
                    );

                    // v5.2.58: Add gettext metadata if found
                    if ($gettext_info) {
                        $node['gettext_domain'] = $gettext_info['domain'];
                        $node['english_original'] = $gettext_info['msgid'];
                        if (!empty($gettext_info['plural_pair'])) {
                            $node['plural_pair'] = $gettext_info['plural_pair'];
                        }
                    }

                    $translateable_nodes[] = $node;
                }
            }
        }

        // 4. WooCommerce специфичные элементы
        if ($this->is_woocommerce_active()) {
            $this->extract_woocommerce_elements($dom, $translateable_strings, $translateable_nodes);
        }
    }


    /**
     * ТИП 3: Извлечение HTML Attributes     *
     * Извлекаем переводимые атрибуты:
     * - alt (изображения)
     * - title (подсказки)
     * - placeholder (формы)
     * - aria-label (доступность)
     * - data-tooltip (кастомные подсказки)
     *
     * Node accessors extraction
     */
    private function extract_html_attributes($dom, &$translateable_strings, &$translateable_nodes) {
        $found_attributes = 0;

        // v3.2: WooCommerce data-attributes to exclude (contain JSON/technical data)
        $excluded_woo_attributes = array(
            'data-product_variations',
            'data-product',
            'data-product_id',
            'data-variation',
            'data-attributes',
            'data-product-attributes',
            'data-availability_html',
            'data-quantity',
            'data-price_html'
        );

        // v3.2: Расширенный список атрибутов
        $translatable_attributes = array(
            'alt' => 'img, area',
            'title' => '*',
            'placeholder' => 'input, textarea',
            'aria-label' => '*',
            'aria-describedby' => '*',
            'aria-labelledby' => '*',
            'data-title' => '*',
            'data-original-title' => '*',
            'data-tooltip' => '*',
            'data-hint' => '*',
            'data-placeholder' => '*'
        );

        foreach ($translatable_attributes as $attribute => $elements) {
            $selector = ($elements === '*') ? "*[$attribute]" : str_replace(', ', "[$attribute], ", $elements) . "[$attribute]";
            $found_elements = $dom->find($selector);

            foreach ($found_elements as $element) {
                // v5.0.12: Check if element should be excluded (data-no-translation, etc.)
                if ($this->should_exclude_block($element)) {
                    continue;
                }

                if ($this->has_ancestor_attribute($element, $this->no_translate_attribute . '-' . $attribute)) {
                    continue;
                }

                // v3.2: Skip if element has any WooCommerce data-attributes with JSON
                $skip_element = false;
                foreach ($excluded_woo_attributes as $excluded_attr) {
                    if ($element->hasAttribute($excluded_attr)) {
                        $skip_element = true;
                        break;
                    }
                }
                if ($skip_element) {
                    continue;
                }

                $attr_value = $element->getAttribute($attribute);
                $text_content = trim($attr_value);

                if (strlen($text_content) > 2 && !$this->is_technical_content($text_content)) {
                    // Дедупликация
                    if (in_array($text_content, $translateable_strings)) {
                        continue;
                    }

                    $translateable_strings[] = $text_content;
                    $translateable_nodes[] = array(
                        'type' => 'attribute_' . $attribute,
                        'context' => 'html_attribute',
                        'element' => $element->tag,
                        'attribute' => $attribute
                    );
                    $found_attributes++;
                }
            }
        }
    }

    /**
     * v5.1: Extract MEDIA elements (images) with all translatable attributes
     * Extract src, alt, title, srcset as separate translatable items
     * Uses src hash as identifier to avoid index shift issues
     */
    private function extract_media_elements($dom, &$translateable_strings, &$translateable_nodes) {
        $found_images = 0;
        $img_elements = $dom->find('img[src]');

        foreach ($img_elements as $img) {
            // Skip if marked as no-translation
            if ($this->should_exclude_block($img)) {
                continue;
            }

            // Extract all translatable attributes
            $src = $img->getAttribute('src');
            $alt = $img->getAttribute('alt');
            $title = $img->getAttribute('title');
            $srcset = $img->getAttribute('srcset');
            $data_src = $img->getAttribute('data-src');

            if (empty($src)) {
                continue;
            }

            // v5.2: Normalize URL for consistent hashing (convert relative to absolute)
            $normalized_src = $this->normalize_media_url($src);

            // Use src hash as unique identifier (solves index shift problem)
            $src_hash = md5($normalized_src);

            // 1. Add SRC as translatable (for image replacement)
            $translateable_strings[] = $normalized_src;
            $translateable_nodes[] = array(
                'type' => 'media_src',
                'context' => 'media',
                'src_hash' => $src_hash,
                'attribute' => 'src',
                'element' => 'img'
            );

            // 2. Add ALT if exists
            if (!empty($alt) && strlen(trim($alt)) > 0) {
                $translateable_strings[] = trim($alt);
                $translateable_nodes[] = array(
                    'type' => 'media_alt',
                    'context' => 'media',
                    'src_hash' => $src_hash,
                    'attribute' => 'alt',
                    'element' => 'img',
                    'parent_src' => $src  // For reference
                );
            }

            // 3. Add TITLE if exists
            if (!empty($title) && strlen(trim($title)) > 0) {
                $translateable_strings[] = trim($title);
                $translateable_nodes[] = array(
                    'type' => 'media_title',
                    'context' => 'media',
                    'src_hash' => $src_hash,
                    'attribute' => 'title',
                    'element' => 'img',
                    'parent_src' => $src
                );
            }

            // Store srcset for reference (will be auto-generated on frontend)
            // Not directly translatable, but useful metadata

            $found_images++;
        }
    }

    /**
     * v3.2: Извлечение SEO мета-данных напрямую из WordPress (Yoast/RankMath/default)
     */
    private function extract_seo_metadata($post_id, &$translateable_strings, &$translateable_nodes) {
        $post = get_post($post_id);

        // 1. Извлекаем SEO Title (NOT page title!) из SEO плагинов
        // v3.6: Проверяем напрямую мета-поля без проверки на существование функций
        // (при AJAX запросе плагины могут не загружаться полностью)
        $seo_title = '';

        // Yoast SEO title
        $seo_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
        if (!empty($seo_title)) {
            // v3.6: Replace Yoast variables with actual values
            $seo_title = $this->replace_yoast_variables($seo_title, $post_id);
        }

        // RankMath SEO title (если Yoast пустой)
        if (empty($seo_title)) {
            $seo_title = get_post_meta($post_id, 'rank_math_title', true);
        }

        // All in One SEO title (если оба предыдущих пустые)
        if (empty($seo_title)) {
            $seo_title = get_post_meta($post_id, '_aioseo_title', true);
        }

        // v3.7: Auto-generate SEO title from Yoast template if no manual title is set
        if (empty($seo_title) && defined('WPSEO_VERSION')) {
            $post = get_post($post_id);
            $seo_title = $this->generate_yoast_seo_title($post);
        }

        if (!empty($seo_title)) {
            $translateable_strings[] = $seo_title;
            $translateable_nodes[] = array(
                'type' => 'seo_title',
                'context' => 'meta_information',
                'element' => 'meta'
            );
        }

        // 2. Извлекаем meta description из SEO плагинов
        $meta_description = '';

        // Yoast SEO
        if (function_exists('YoastSEO')) {
            $meta_description = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        }

        // RankMath
        if (empty($meta_description) && class_exists('RankMath')) {
            $meta_description = get_post_meta($post_id, 'rank_math_description', true);
        }

        // All in One SEO
        if (empty($meta_description) && function_exists('aioseo')) {
            $meta_description = get_post_meta($post_id, '_aioseo_description', true);
        }

        // Fallback: используем excerpt (v3.6: RAW from DB without filters)
        if (empty($meta_description) && $post) {
            // v3.6: Use RAW fields directly to avoid translation filters
            $meta_description = !empty($post->post_excerpt) ? $post->post_excerpt : wp_trim_words($post->post_content, 20);
        }

        if (!empty($meta_description)) {
            $translateable_strings[] = $meta_description;
            $translateable_nodes[] = array(
                'type' => 'meta_description',
                'context' => 'meta_information',
                'element' => 'meta'
            );
        }

        // 3. Извлекаем OG tags (если есть)
        $og_title = get_post_meta($post_id, '_yoast_wpseo_opengraph-title', true);
        if (empty($og_title)) {
            $og_title = get_post_meta($post_id, 'rank_math_facebook_title', true);
        }
        if (!empty($og_title)) {
            $translateable_strings[] = $og_title;
            $translateable_nodes[] = array(
                'type' => 'og_title',
                'context' => 'meta_information',
                'element' => 'meta'
            );
        }

        $og_description = get_post_meta($post_id, '_yoast_wpseo_opengraph-description', true);
        if (empty($og_description)) {
            $og_description = get_post_meta($post_id, 'rank_math_facebook_description', true);
        }
        if (!empty($og_description)) {
            $translateable_strings[] = $og_description;
            $translateable_nodes[] = array(
                'type' => 'og_description',
                'context' => 'meta_information',
                'element' => 'meta'
            );
        }
    }

    /**
     * Извлечение Meta Tags     * v5.0.5: Extract only FIRST meta tag of each type (avoid WooCommerce product schema duplicates)
     */
    private function extract_meta_tags($dom, &$translateable_strings, &$translateable_nodes) {
        // SEO meta tags (как в TP)
        // v3.2: Removed 'title' => 'page_title' - already extracted in extract_seo_metadata()
        $meta_selectors = array(
            'meta[name="description"]' => 'meta_description',
            'meta[property="og:title"]' => 'og_title',
            'meta[property="og:description"]' => 'og_description',
            'meta[property="og:site_name"]' => 'og_site_name',
            'meta[name="twitter:title"]' => 'twitter_title',
            'meta[name="twitter:description"]' => 'twitter_description'
        );

        foreach ($meta_selectors as $selector => $type) {
            $elements = $dom->find($selector);

            // v5.0.5: Only take FIRST meta tag (page-level meta, not product schema)
            if (!empty($elements)) {
                $element = $elements[0];
                $content = '';

                if ($element->tag === 'title') {
                    $content = trim($element->innertext);
                } else {
                    $content = trim($element->getAttribute('content'));
                }

                if (strlen($content) > 0 && !$this->is_technical_content($content)) {
                    $translateable_strings[] = $content;
                    $translateable_nodes[] = array(
                        'type' => $type,
                        'context' => 'meta_information',
                        'element' => $element->tag
                    );
                }
            }
        }
    }

    /**
     * Проверка на исключение блока из перевода
     */
    private function should_exclude_block($block) {
        // Проверяем data-no-translation атрибут
        if ($this->has_ancestor_attribute($block, $this->no_translate_attribute)) {
            return true;
        }

        // v3.0.39: Минимальный список исключений (только технический контент)
        // Переводим ВСЕ видимые строки, включая sidebar, footer, nav
        $exclude_classes = array(
            'translation-block',  // Наш собственный маркер
            'notranslate',        // Стандартный Google Translate атрибут
            'wp-admin-bar',       // WordPress admin bar
            'screen-reader-text', // Скрытый текст для screen readers (accessibility)
            'create-nav-msg'      // WoodMart admin messages (not visible to users)
            // v5.2.4: REMOVED ALL user-facing widgets and UI elements:
            // - shop-view (may contain "Grid"/"List" text - theme GETTEXT)
            // - All counters (may contain text like "3 items" - WooCommerce GETTEXT)
            // - woocommerce-result-count, woocommerce-ordering (WooCommerce GETTEXT)
            // - Action buttons (user-facing GETTEXT strings)
            //
            // Pure technical content (numbers, icons) auto-filtered by is_numeric() and is_technical_content()
        );
        foreach ($exclude_classes as $class) {
            if ($this->has_ancestor_class($block, $class)) {
                return true;
            }
        }

        // Проверяем родительские теги
        if ($this->has_ancestor_tag($block, array('script', 'style', 'noscript', 'head'))) {
            return true;
        }

        return false;
    }

    /**
     * Проверка наличия атрибута у элемента или его предков
     */
    private function has_ancestor_attribute($element, $attribute) {
        if (!is_object($element)) {
            return false;
        }

        $current = $element;
        while ($current && is_object($current)) {
            // Simple HTML DOM: используем hasAttribute
            if (method_exists($current, 'hasAttribute') && $current->hasAttribute($attribute)) {
                return true;
            }
            $current = $current->parent();
        }
        return false;
    }

    /**
     * Проверка наличия класса у элемента или его предков
     * v3.0.38: FIX - ищем полное совпадение класса, а не подстроку
     */
    private function has_ancestor_class($element, $class) {
        $current = $element;
        while ($current) {
            $classes = $current->getAttribute('class');
            if ($classes) {
                // v3.0.38: Разбиваем на массив классов и ищем точное совпадение
                $class_array = preg_split('/\s+/', trim($classes));
                if (in_array($class, $class_array)) {
                    return true;
                }
            }
            $current = $current->parent();
        }
        return false;
    }

    /**
     * Проверка наличия тега у предков
     */
    private function has_ancestor_tag($element, $tags) {
        $current = $element;
        while ($current) {
            if (in_array($current->tag, $tags)) {
                return true;
            }
            $current = $current->parent();
        }
        return false;
    }

    /**
     * Проверка на технический контент (v3.2: расширенная с WooCommerce)
     */
    private function is_technical_content($text) {
        // v3.2: JSON detection (improved)
        if (json_decode($text) !== null) {
            return true;
        }

        // v3.2: Detect JSON-like patterns (even if malformed)
        // Look for JSON object/array patterns with typical JSON syntax
        if (preg_match('/[\{\[].*["\']:\s*["\'].*[\}\]]/', $text)) {
            return true; // Contains "key":"value" pattern
        }

        // v5.5.1: SMART inline HTML handling (replaces v3.2 crude </span> filter)
        // Allow inline HTML tags (span, strong, em, a, br, etc.) in translatable content
        // but still block WooCommerce price fragments and technical markup.
        // Approach from output buffer's is_technical_content() (v5.3.44):
        // Strip known inline tags first, then check if any block/technical HTML remains.
        $inline_tags_pattern = '/<\/?(?:br|span|strong|b|em|i|u|a|small|mark|del|ins|sub|sup|code|kbd|samp|var)(?:\s[^>]*)?>/i';
        $text_without_inline = preg_replace($inline_tags_pattern, '', $text);

        // If there are still HTML closing tags after removing inline tags → technical content
        if (preg_match('#</[a-z]#i', $text_without_inline)) {
            return true; // Contains non-inline HTML closing tags (e.g. </bdi>, </div>)
        }
        // If there are still HTML opening tags after removing inline tags → technical content
        if (preg_match('/<[a-z][a-z0-9]*[\s>]/i', $text_without_inline)) {
            return true; // Contains non-inline HTML opening tags
        }

        // v3.2: Variation data patterns (sku, variation_description, etc.)
        if (preg_match('/"(sku|variation_id|variation_description|price_html|availability_html)"/', $text)) {
            return true;
        }

        // URLs
        if (preg_match('/^https?:\/\//', $text)) {
            return true;
        }

        // CSS/JS - filter content starting with { or [
        // v5.2.183: Allow inline HTML tags at start (strong, em, a, span, etc.)
        // Only filter: {json}, [array], <script>, <style>, <svg>, <!--
        if (preg_match('/^[\{\[]/', $text)) {
            return true;
        }
        // Filter only technical HTML tags, not inline content tags
        if (preg_match('/^<(script|style|svg|!--)/i', $text)) {
            return true;
        }

        // v5.5.1: Filter standalone HTML wrapper elements
        // Example: <a href="...">Link</a> or <span>Text</span> — the whole thing is outertext artifact
        // But allow MIXED content like "Текст <span class='x'>продолжение</span>"
        // Pattern: entire string is ONE tag (with or without attributes) wrapping content
        if (preg_match('/^<([a-z]+)(?:\s[^>]*)?>.*<\/\1>$/is', $text)) {
            // The ENTIRE string is wrapped in a single HTML tag — outertext artifact
            // Filter it regardless of whether the tag is inline or block-level
            return true;
        }

        // Числа и проценты
        if (is_numeric($text) || preg_match('/^\d+%$/', $text)) {
            return true;
        }

        // Email
        if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        // Слишком короткие строки
        if (strlen($text) < 2) {
            return true;
        }

        // v3.2: Технические идентификаторы и классы
        // Строки типа "tab-title-description", "some_technical_id", "module-name"
        if (preg_match('/^[a-z0-9_-]+$/i', $text)) {
            // Если строка состоит ТОЛЬКО из латиницы, цифр, подчеркиваний и дефисов
            // И содержит дефис или подчёркивание = технический ID
            if (strpos($text, '-') !== false || strpos($text, '_') !== false) {
                return true;
            }
        }

        // v3.2: Имена файлов (содержат расширения или длинные цифровые последовательности)
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|mp4)$/i', $text)) {
            return true;
        }
        // Файлы без расширения но с техническими паттернами
        if (preg_match('/[0-9]{8,}/', $text)) {
            // Строки с 8+ цифрами подряд = вероятно ID файла или timestamp
            return true;
        }

        // v3.2: Технические меню и ARIA labels (screen reader only strings)
        // v5.0.14: REMOVED 'Breadcrumb' - it was preventing breadcrumb links from being translated!
        // v5.2.3: Cleaned up - removed potentially translatable strings
        $technical_strings = array(
            // Screen reader / ARIA labels (not visible text)
            'Open mobile menu',
            'Close mobile menu',
            'Toggle navigation',
            'Skip to content',
            'Skip to main content',
            'Scroll to top',
            'Close search',
            'Site logo',
            // Social media ARIA labels
            'Facebook social link',
            'X social link',
            'Twitter social link',
            'Pinterest social link',
            'Linkedin social link',
            'Telegram social link',
            'Instagram social link',
            // Technical formats
            'JSON',
            'RSS',
            'Atom'
            // v5.2.3: REMOVED visible user-facing strings that may be GETTEXT:
            // - 'Read more' (theme/WooCommerce GETTEXT)
            // - 'Quick view' (WooCommerce GETTEXT)
            // - 'Previous product', 'Next product' (WooCommerce GETTEXT)
            // - 'My cart', 'My wishlist' (WooCommerce/WoodMart GETTEXT)
        );
        $text_lower = strtolower(trim($text));
        foreach ($technical_strings as $item) {
            if ($text_lower === strtolower($item)) {
                return true;
            }
        }

        // v3.1: CDATA sections
        if (strpos($text, '<![CDATA[') === 0 || strpos($text, '&lt;![CDATA[') === 0) {
            return true;
        }

        // v3.1: iCalendar format
        if (strpos($text, 'BEGIN:VCALENDAR') === 0) {
            return true;
        }

        // v3.1: Minified JavaScript detection
        // Проверяем на наличие ключевых слов JS в одной строке
        $js_keywords = array('function', 'return', 'if', '==', 'var', 'const', 'let');
        $keyword_count = 0;
        foreach ($js_keywords as $keyword) {
            if (stripos($text, $keyword) !== false) {
                $keyword_count++;
            }
        }
        // Если найдено 3+ ключевых слова JS - это скорее всего код
        if ($keyword_count >= 3) {
            return true;
        }

        // КРИТИЧЕСКОЕ v3.0: Фильтрация WordPress административных строк и мета-данных
        $wordpress_admin_patterns = array(
            '/^oEmbed\s*\(/i',                    // oEmbed (JSON), oEmbed (XML)
            '/^Focus keyphrase/i',                 // Focus keyphrase not set (Yoast SEO)
            '/^Your task is to/i',                 // AI-generated prompts
            '/^SEO title/i',                       // SEO title meta
            '/^Meta description/i',                // Meta description
            '/^Schema markup/i',                   // Schema.org metadata
            '/^\[lingua_/i',                       // Lingua internal shortcodes
            '/^wp-/i',                             // WordPress internal classes/IDs
            '/^woocommerce-/i',                    // WooCommerce internal
            '/^data-[\w-]+=/i',                    // HTML data attributes
            '/^aria-[\w-]+=/i',                    // ARIA attributes
            '/^Click to share on/i',               // Social sharing buttons text
            '/^Share via/i',                       // Social sharing
            '/^(function|var|const|let)\s+\w+/i', // JavaScript code
            '/^\d{4}-\d{2}-\d{2}/',               // ISO dates (2025-09-30)
            '/^#[0-9a-f]{3,6}$/i',                // Hex colors
            '/woocommerce_loop/i',                // WooCommerce loop fields
            '/describedby_/i',                    // ARIA describedby attributes
            '/^Main navigation/i',                // Navigation menu items
            '/^RSD$/i',                           // Really Simple Discovery
            '/Заполнитель/i',                     // Russian "placeholder"
            '/^placeholder$/i',                   // English placeholder
            '/^Placeholder$/i',                   // Capitalized placeholder
        );

        foreach ($wordpress_admin_patterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        // Фильтрация строк содержащих только HTML entities
        if (preg_match('/^(&[a-z]+;|\&#\d+;)+$/i', $text)) {
            return true;
        }

        return false;
    }


    /**
     * Определение типа текстового узла
     */
    private function get_text_node_type($element) {
        $tag = $element->tag;

        $type_mapping = array(
            'h1' => 'heading_h1', 'h2' => 'heading_h2', 'h3' => 'heading_h3',
            'h4' => 'heading_h4', 'h5' => 'heading_h5', 'h6' => 'heading_h6',
            'p' => 'paragraph',
            'li' => 'list_item',
            'a' => 'link_text',
            'span' => 'span_text',
            'div' => 'div_text',
            'td' => 'table_cell',
            'th' => 'table_header'
        );

        return $type_mapping[$tag] ?? 'text_node';
    }

    /**
     * Очистка текста от HTML тегов
     */
    private function get_clean_text($html) {
        // Удаляем HTML теги, но сохраняем содержимое
        $text = strip_tags($html);

        // Декодируем HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Нормализуем пробелы
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Классификация извлеченного контента
     */
    private function classify_extracted_content($translateable_strings, $translateable_nodes, $post_id) {
        $structured = $this->get_empty_structure();

        for ($i = 0; $i < count($translateable_strings); $i++) {
            if (!isset($translateable_nodes[$i])) continue;

            $string = $translateable_strings[$i];
            $node = $translateable_nodes[$i];
            $context = $node['context'];

            $item = array(
                'id' => 'lingua_' . md5($string . $context . $i),
                'original' => $string,
                'translated' => '',
                'context' => $context,
                'type' => $node['type'],
                'status' => 'pending',
                'field_group' => $this->map_context_to_field_group($context),
                'post_id' => $post_id,
                // v5.2.43: New architecture with source, domain, and plural support
                'source' => $node['source'] ?? 'custom',
                'gettext_domain' => $node['gettext_domain'] ?? null,
                'english_original' => $node['english_original'] ?? null,
                'is_plural' => $node['is_plural'] ?? false,
                'plural_pair' => $node['plural_pair'] ?? null,  // v5.2.44: CRITICAL - needed for grouping in UI
                'russian_forms' => $node['russian_forms'] ?? null,  // v5.2.50: Parsed but unused in UI after v5.2.52 rollback
                // v5.2.57: CRITICAL FIX - Add alias fields for apply_existing_translations() compatibility
                'msgid' => $node['english_original'] ?? null,  // Alias for english_original (used by apply_existing_translations)
                'domain' => $node['gettext_domain'] ?? null   // Alias for gettext_domain (used by apply_existing_translations)
            );

            // Классифицируем по группам
            switch ($context) {
                case 'meta_information':
                    $structured['seo_fields'][] = $item;
                    break;
                case 'block':
                    $structured['content_blocks'][] = $item;
                    break;
                case 'form_element':
                    $structured['page_strings'][] = $item;
                    break;
                case 'html_attribute':
                    $structured['attributes'][] = $item;
                    break;
                case 'media':
                    // v5.1: Media elements go to separate group
                    $item['src_hash'] = $node['src_hash'] ?? '';
                    $item['attribute'] = $node['attribute'] ?? '';
                    $item['parent_src'] = $node['parent_src'] ?? '';
                    $structured['media'][] = $item;
                    break;
                case 'woocommerce':
                    // WooCommerce элементы идут в page_strings но с особой меткой
                    $item['woo_element'] = true;
                    $structured['page_strings'][] = $item;
                    break;
                default:
                    $structured['page_strings'][] = $item;
                    break;
            }
        }

        // v3.7: SYSTEMATIC DEDUPLICATION FOR ALL FIELD GROUPS
        // Remove duplicate strings across all groups (content_blocks, page_strings, attributes, etc.)
        // Priority: Content Blocks > Page Strings > Attributes > WooCommerce
        // Keep most specific context, remove less specific duplicates

        $structured = $this->deduplicate_all_fields($structured);

        return $structured;
    }

    /**
     * v3.7: Systematic deduplication across ALL field groups
     *
     * Strategy:
     * 1. Build global hash map of all original texts
     * 2. For each duplicate text, keep ONLY the highest priority instance
     * 3. Priority: content_blocks > seo_fields > page_strings > attributes
     * 4. Within same group, keep first occurrence
     *
     * @param array $structured Structured content from classify_extracted_content
     * @return array Deduplicated structured content
     */
    private function deduplicate_all_fields($structured) {
        // Priority order (higher = keep this one)
        $priority_order = array(
            'content_blocks' => 4,
            'seo_fields' => 3,
            'page_strings' => 2,
            'attributes' => 1,
            'meta_fields' => 1,
            'taxonomy_terms' => 1,
            'core_fields' => 3
        );

        // Step 1: Build a map of all original texts with their locations
        $text_map = array(); // text_hash => array of [group, index, priority]

        foreach ($priority_order as $group => $priority) {
            if (empty($structured[$group])) {
                continue;
            }

            foreach ($structured[$group] as $index => $item) {
                $original_text = isset($item['original']) ? trim($item['original']) : '';

                if (empty($original_text)) {
                    continue;
                }

                // Create hash from text only (ignore context for deduplication)
                $text_hash = md5($original_text);

                if (!isset($text_map[$text_hash])) {
                    $text_map[$text_hash] = array();
                }

                $text_map[$text_hash][] = array(
                    'group' => $group,
                    'index' => $index,
                    'priority' => $priority,
                    'text' => $original_text,
                    'context' => isset($item['context']) ? $item['context'] : '',
                    'type' => isset($item['type']) ? $item['type'] : ''
                );
            }
        }

        // Step 2: Identify duplicates and mark items to remove
        $items_to_remove = array(); // group => [index1, index2, ...]

        foreach ($text_map as $text_hash => $occurrences) {
            if (count($occurrences) <= 1) {
                continue; // Not a duplicate
            }

            // Sort by priority DESC (highest priority first)
            usort($occurrences, function($a, $b) {
                if ($a['priority'] !== $b['priority']) {
                    return $b['priority'] - $a['priority']; // Higher priority first
                }
                // Same priority - keep first occurrence (lower index first)
                return $a['index'] - $b['index'];
            });

            // Keep first (highest priority), mark rest for removal
            $keep = array_shift($occurrences); // Remove and keep first item

            // v5.2.165: Track which SEO types we've kept (don't deduplicate different SEO types)
            $kept_seo_types = array();
            if ($keep['group'] === 'seo_fields' && !empty($keep['type'])) {
                $kept_seo_types[$keep['type']] = true;
            }

            // Mark all other occurrences for removal
            foreach ($occurrences as $occurrence) {
                // v5.2.165: Don't remove SEO fields with different types
                // (meta_description and og_description can have same text but should both be kept)
                if ($occurrence['group'] === 'seo_fields' && !empty($occurrence['type'])) {
                    if (!isset($kept_seo_types[$occurrence['type']])) {
                        $kept_seo_types[$occurrence['type']] = true;
                        continue; // Don't mark for removal - different SEO type
                    }
                }

                if (!isset($items_to_remove[$occurrence['group']])) {
                    $items_to_remove[$occurrence['group']] = array();
                }
                $items_to_remove[$occurrence['group']][] = $occurrence['index'];
            }
        }

        // Step 3: Remove marked items from structured array
        foreach ($items_to_remove as $group => $indices) {
            if (empty($structured[$group])) {
                continue;
            }

            // Sort indices in reverse order to remove from end to start (avoid index shifting)
            rsort($indices);

            foreach ($indices as $index) {
                if (isset($structured[$group][$index])) {
                    array_splice($structured[$group], $index, 1);
                }
            }
        }

        return $structured;
    }

    /**
     * Маппинг контекста на field_group
     */
    private function map_context_to_field_group($context) {
        $mapping = array(
            'meta_information' => 'seo_fields',
            'block' => 'content_blocks',
            'form_element' => 'page_strings',
            'html_attribute' => 'attributes',
            'text_content' => 'page_strings',
            'woocommerce' => 'page_strings'
        );

        return $mapping[$context] ?? 'page_strings';
    }

    /**
     * Получение пустой структуры
     */
    private function get_empty_structure() {
        return array(
            'content_blocks' => array(),
            'core_fields' => array(),
            'seo_fields' => array(),
            'meta_fields' => array(),
            'taxonomy_terms' => array(),
            'attributes' => array(),
            'page_strings' => array(),
            'media' => array()  // v5.1: Media elements (images with src, alt, title)
        );
    }

    /**
     * Логирование статистики извлечения (v3.1)
     */
    private function log_extraction_stats($post_id, $total_strings, $structured_content) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        // Подсчитываем по группам
        $stats = array();
        foreach ($structured_content as $group => $items) {
            $stats[$group] = count($items);
        }

        // v3.1: Подсчитываем по типам (как в TP)
        $type_stats = array(
            'content_blocks' => 0,
            'text_nodes' => 0,
            'attributes' => 0,
            'form_elements' => 0,
            'meta_tags' => 0
        );

        foreach ($structured_content as $group => $items) {
            if (is_array($items)) {
                foreach ($items as $item) {
                    $type = $item['type'] ?? 'unknown';

                    if ($type === 'content_block' || $type === 'html_block') {
                        $type_stats['content_blocks']++;
                    } elseif ($type === 'text_node' || $type === 'text_node_sentence') {
                        $type_stats['text_nodes']++;
                    } elseif (strpos($type, 'attribute_') === 0) {
                        $type_stats['attributes']++;
                    } elseif (strpos($type, 'button') !== false || strpos($type, 'form') !== false || strpos($type, 'select') !== false) {
                        $type_stats['form_elements']++;
                    } elseif (strpos($type, 'meta_') === 0 || strpos($type, 'og_') === 0 || strpos($type, 'twitter_') === 0) {
                        $type_stats['meta_tags']++;
                    }
                }
            }
        }

        lingua_debug_log("[Lingua v3.1 Extractor] Post {$post_id} Statistics:");
        lingua_debug_log("  Total strings: {$total_strings}");
        lingua_debug_log("  By group: " . json_encode($stats));
        lingua_debug_log("  By type: " . json_encode($type_stats));
        lingua_debug_log("  Extraction method: 3-type architecture (content_blocks + text_nodes + attributes)");
    }

    /**
     * Извлечение WooCommerce специфичных элементов (v3.0 TP с Top Parents check)
     * Поддержка товаров, корзины, оформления заказа
     */
    private function extract_woocommerce_elements($dom, &$translateable_strings, &$translateable_nodes) {
        try {
            // КРИТИЧНО v3.0 TP: Получаем Top Parents для проверки контейнеров
            $top_parents = $this->get_top_parents();

            // 1. Кнопки корзины и покупки
            $cart_button_selectors = array(
                '.add_to_cart_button',
                '.single_add_to_cart_button',
                '.checkout-button',
                '.wc-proceed-to-checkout',
                '.place-order',
                '.wc-backward',
                'form.cart button[type=submit]',
                '.cart_totals .button',
                '.return-to-shop a'
            );

            foreach ($cart_button_selectors as $selector) {
                $buttons = $dom->find($selector);
                foreach ($buttons as $button) {
                    if ($this->has_ancestor_attribute($button, $this->no_translate_attribute)) {
                        continue;
                    }

                    // v3.0 TP: Пропускаем контейнеры с дочерними top_parents
                    if ($this->check_children_for_tags($button, $top_parents)) {
                        continue;
                    }

                    $text_content = $this->trim_translation_block($button->innertext);
                    if (strlen($text_content) > 0 && strlen($text_content) < 500 && !$this->is_technical_content($text_content)) {
                        $translateable_strings[] = $text_content;
                        $translateable_nodes[] = array(
                            'type' => 'woo_button',
                            'context' => 'woocommerce',
                            'element' => $button->tag,
                            'woo_type' => 'cart_button'
                        );
                    }
                }
            }

            // 2. Табы продуктов
            $tab_selectors = array(
                '.wc-tabs li a',
                '.woocommerce-tabs .tabs li a',
                '.wc-tab'
            );

            foreach ($tab_selectors as $selector) {
                $tabs = $dom->find($selector);
                foreach ($tabs as $tab) {
                    if ($this->has_ancestor_attribute($tab, $this->no_translate_attribute)) {
                        continue;
                    }

                    // v3.0 TP: Пропускаем контейнеры
                    if ($this->check_children_for_tags($tab, $top_parents)) {
                        continue;
                    }

                    $text_content = $this->trim_translation_block($tab->innertext);
                    if (strlen($text_content) > 0 && strlen($text_content) < 500 && !$this->is_technical_content($text_content)) {
                        $translateable_strings[] = $text_content;
                        $translateable_nodes[] = array(
                            'type' => 'woo_tab',
                            'context' => 'woocommerce',
                            'element' => $tab->tag,
                            'woo_type' => 'product_tab'
                        );
                    }
                }
            }

            // 3. Сообщения и уведомления
            $message_selectors = array(
                '.woocommerce-message',
                '.woocommerce-info',
                '.woocommerce-error',
                '.wc-block-components-notice-banner',
                '.cart-empty',
                '.woocommerce-cart-notice'
            );

            foreach ($message_selectors as $selector) {
                $messages = $dom->find($selector);
                foreach ($messages as $message) {
                    if ($this->has_ancestor_attribute($message, $this->no_translate_attribute)) {
                        continue;
                    }

                    // v3.0 TP: Пропускаем контейнеры
                    if ($this->check_children_for_tags($message, $top_parents)) {
                        continue;
                    }

                    $text_content = $this->trim_translation_block($message->innertext);
                    if (strlen($text_content) > 0 && strlen($text_content) < 500 && !$this->is_technical_content($text_content)) {
                        $translateable_strings[] = $text_content;
                        $translateable_nodes[] = array(
                            'type' => 'woo_message',
                            'context' => 'woocommerce',
                            'element' => $message->tag,
                            'woo_type' => 'notice'
                        );
                    }
                }
            }

            // 4. Лейблы полей оформления заказа
            $checkout_label_selectors = array(
                '.woocommerce-checkout .form-row label',
                '.woocommerce-billing-fields label',
                '.woocommerce-shipping-fields label',
                '.checkout .form-row label'
            );

            foreach ($checkout_label_selectors as $selector) {
                $labels = $dom->find($selector);
                foreach ($labels as $label) {
                    if ($this->has_ancestor_attribute($label, $this->no_translate_attribute)) {
                        continue;
                    }

                    // v3.0 TP: Пропускаем контейнеры
                    if ($this->check_children_for_tags($label, $top_parents)) {
                        continue;
                    }

                    $text_content = $this->trim_translation_block($label->innertext);
                    if (strlen($text_content) > 0 && strlen($text_content) < 500 && !$this->is_technical_content($text_content)) {
                        $translateable_strings[] = $text_content;
                        $translateable_nodes[] = array(
                            'type' => 'woo_checkout_label',
                            'context' => 'woocommerce',
                            'element' => 'label',
                            'woo_type' => 'checkout_field'
                        );
                    }
                }
            }

            // 5. Заголовки секций WooCommerce
            $heading_selectors = array(
                '.cart-collaterals h2',
                '.woocommerce-checkout h3',
                '.cross-sells h2',
                '.related.products h2',
                '.upsells h2',
                '.cart_totals h2'
            );

            foreach ($heading_selectors as $selector) {
                $headings = $dom->find($selector);
                foreach ($headings as $heading) {
                    if ($this->has_ancestor_attribute($heading, $this->no_translate_attribute)) {
                        continue;
                    }

                    // v3.0 TP: Пропускаем контейнеры
                    if ($this->check_children_for_tags($heading, $top_parents)) {
                        continue;
                    }

                    $text_content = $this->trim_translation_block($heading->innertext);
                    if (strlen($text_content) > 0 && strlen($text_content) < 500 && !$this->is_technical_content($text_content)) {
                        $translateable_strings[] = $text_content;
                        $translateable_nodes[] = array(
                            'type' => 'woo_section_heading',
                            'context' => 'woocommerce',
                            'element' => $heading->tag,
                            'woo_type' => 'section_title'
                        );
                    }
                }
            }

            // 6. Атрибуты продуктов (КРИТИЧНО v3.0 TP: с проверкой контейнеров!)
            $product_attribute_selectors = array(
                // Стандартные атрибуты
                '.shop_attributes th',
                '.shop_attributes td',
                '.woocommerce-product-attributes-item__label',
                '.woocommerce-product-attributes-item__value',

                // Специфичные селекторы для темы Woodmart и других тем
                '.wd-attr-name-label',
                '.wd-attr-term',
                '.wd-attr-name',

                // Вариации продуктов
                '.variations .label label',
                '.variations .value select option',
                '.variations tr th label',
                '.variations tr td select option',
                'table.variations th.label label',
                'table.variations td.value',

                // Общие селекторы для всех атрибутов
                '.woocommerce-product-attributes th',
                '.woocommerce-product-attributes td',
                '.product_attributes th',
                '.product_attributes td'
            );

            foreach ($product_attribute_selectors as $selector) {
                $attributes = $dom->find($selector);
                foreach ($attributes as $attribute) {
                    if ($this->has_ancestor_attribute($attribute, $this->no_translate_attribute)) {
                        continue;
                    }

                    // КРИТИЧНО v3.0 TP: Пропускаем контейнеры с дочерними top_parents
                    // ЭТО ГЛАВНАЯ ПРИЧИНА СЛИПАНИЯ ОПИСАНИЙ ТОВАРОВ!
                    if ($this->check_children_for_tags($attribute, $top_parents)) {
                        continue;
                    }

                    $text_content = $this->trim_translation_block($attribute->innertext);
                    if (strlen($text_content) > 0 && strlen($text_content) < 500 && !$this->is_technical_content($text_content)) {
                        $translateable_strings[] = $text_content;
                        $translateable_nodes[] = array(
                            'type' => 'woo_product_attribute',
                            'context' => 'woocommerce',
                            'element' => $attribute->tag,
                            'woo_type' => 'product_attr'
                        );
                    }
                }
            }

        } catch (Exception $e) {
            lingua_debug_log('[Lingua DOM Extractor] Error extracting WooCommerce elements: ' . $e->getMessage());
        }
    }

    /**
     * Проверка активности WooCommerce
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * v3.6: Temporarily disable ALL translation filters
     * Needed when extracting SEO fields - Yoast/RankMath may apply Lingua filters to get_post_meta()
     */
    private $disabled_filters = array();

    private function disable_all_translation_filters() {
        global $wp_filter;

        // Disable Lingua's own filters
        if (class_exists('Lingua_Translation_Manager')) {
            $tm = new Lingua_Translation_Manager();
            $this->disabled_filters[] = array('the_content', array($tm, 'translate_content'), 10);
            $this->disabled_filters[] = array('the_title', array($tm, 'translate_title'), 10);
            $this->disabled_filters[] = array('the_excerpt', array($tm, 'translate_excerpt'), 10);

            remove_filter('the_content', array($tm, 'translate_content'), 10);
            remove_filter('the_title', array($tm, 'translate_title'), 10);
            remove_filter('the_excerpt', array($tm, 'translate_excerpt'), 10);
        }

        // Disable Yoast SEO translation filters (if they exist)
        if (has_filter('wpseo_title')) {
            $this->disabled_filters[] = array('wpseo_title', '__return_false', 999);
            add_filter('wpseo_title', '__return_false', 999);
        }
        if (has_filter('wpseo_metadesc')) {
            $this->disabled_filters[] = array('wpseo_metadesc', '__return_false', 999);
            add_filter('wpseo_metadesc', '__return_false', 999);
        }
    }

    private function restore_all_translation_filters() {
        // Restore Lingua's filters
        if (class_exists('Lingua_Translation_Manager')) {
            $tm = new Lingua_Translation_Manager();
            add_filter('the_content', array($tm, 'translate_content'), 10);
            add_filter('the_title', array($tm, 'translate_title'), 10);
            add_filter('the_excerpt', array($tm, 'translate_excerpt'), 10);
        }

        // Remove our blocking filters
        if (has_filter('wpseo_title', '__return_false')) {
            remove_filter('wpseo_title', '__return_false', 999);
        }
        if (has_filter('wpseo_metadesc', '__return_false')) {
            remove_filter('wpseo_metadesc', '__return_false', 999);
        }

        $this->disabled_filters = array();
    }

    /**
     * v3.6: Replace Yoast SEO variables with actual values
     */
    /**
     * v3.7: Auto-generate SEO title from Yoast template when no manual title is set
     *
     * @param WP_Post $post The post object
     * @return string The auto-generated SEO title
     */
    private function generate_yoast_seo_title($post) {
        if (!$post) {
            return '';
        }

        // Get Yoast title options
        $title_options = get_option('wpseo_titles');
        if (!$title_options) {
            return '';
        }

        // Get template for this post type
        $post_type = get_post_type($post);
        $template_key = "title-{$post_type}";

        if (!isset($title_options[$template_key])) {
            return '';
        }

        $template = $title_options[$template_key];

        // Replace Yoast variables in template
        $generated_title = $this->replace_yoast_variables($template, $post->ID);

        return $generated_title;
    }

    private function replace_yoast_variables($text, $post_id) {
        if (empty($text)) {
            return $text;
        }

        $post = get_post($post_id);
        if (!$post) {
            return $text;
        }

        // Common Yoast variables
        $replacements = array(
            '%%sitename%%' => get_bloginfo('name'),
            '%%sitedesc%%' => get_bloginfo('description'),
            '%%title%%' => $post->post_title,
            '%%excerpt%%' => $post->post_excerpt,
            '%%currentdate%%' => date_i18n(get_option('date_format')),
            '%%currenttime%%' => date_i18n(get_option('time_format')),
            '%%currentyear%%' => date('Y'),
            '%%currentmonth%%' => date_i18n('F'),
            '%%currentday%%' => date_i18n('j'),
            '%%sep%%' => '-',
            '%%page%%' => '',
            '%%pagetotal%%' => '',
            '%%pagenumber%%' => '',
        );

        // Replace all variables
        foreach ($replacements as $var => $value) {
            $text = str_replace($var, $value, $text);
        }

        return $text;
    }

    /**
     * v5.2: Normalize media URL for consistent hashing
     * Converts relative URLs to absolute URLs
     *
     * @param string $url Original URL
     * @return string Normalized absolute URL
     * v5.2.160: Removes WordPress size suffixes (-600x600, -300x300, etc.) for consistent matching
     */
    private function normalize_media_url($url) {
        // Already absolute URL
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            // v5.2.160: Remove WordPress size suffixes before hashing
            return $this->remove_media_size_suffix($url);
        }

        // Protocol-relative URL
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
            // v5.2.160: Remove WordPress size suffixes
            return $this->remove_media_size_suffix($url);
        }

        // Get site URL
        $site_url = get_site_url();

        // Absolute path
        if (strpos($url, '/') === 0) {
            $url = $site_url . $url;
            // v5.2.160: Remove WordPress size suffixes
            return $this->remove_media_size_suffix($url);
        }

        // Relative path - add site URL
        $url = $site_url . '/' . ltrim($url, '/');
        // v5.2.160: Remove WordPress size suffixes
        return $this->remove_media_size_suffix($url);
    }

    /**
     * v5.2.160: Remove WordPress image size suffixes for consistent hashing
     *
     * WordPress generates multiple sizes: image-600x600.jpg, image-300x300.jpg, etc.
     * We need to normalize all sizes to the base filename for consistent hash matching.
     *
     * @param string $url Image URL
     * @return string URL without size suffix
     */
    private function remove_media_size_suffix($url) {
        // v5.2.160: Remove ALL WordPress size suffixes from filename
        // Pattern: -123x456 (can appear multiple times) before file extension
        // Example: image-800x800-1-300x300.jpg → image.jpg

        // First, separate the extension
        if (preg_match('/^(.+)(\.(jpe?g|png|gif|webp|bmp|svg))$/i', $url, $matches)) {
            $base = $matches[1];
            $ext = $matches[2];

            // Remove all -NNNxNNN patterns from base (can be multiple)
            $base = preg_replace('/-\d+x\d+/', '', $base);

            // Also remove trailing -1, -2 etc that WordPress adds for duplicates
            $base = preg_replace('/-\d+$/', '', $base);

            return $base . $ext;
        }

        return $url;
    }

    /**
     * v5.2.39: Get English gettext msgid for Russian string (FIXED: Parse .po files)
     *
     * CORRECT APPROACH: Parse Russian .po files to find msgid (English) for msgstr (Russian)
     *
     * Example from woodmart-ru_RU.po:
     *   msgid "Shop"          ← English original (what we need)
     *   msgstr "Каталог"      ← Russian translation (what we have)
     *
     * @param string $text Russian text to look up
     * @param string $domain Gettext domain hint (woocommerce, woodmart, default, etc.)
     * @return string|null English msgid or null if not found
     */
    /**
     * v5.2.62: CRITICAL FIX - Full support for English as default language
     *
     * Key change: When default=English, text is ALREADY msgid (no reverse mapping needed)
     * - English default: Searches non-English .po files (ru_RU, de_DE, etc.) for plural_pairs
     * - Non-English default: Uses default language .po file for reverse msgstr→msgid mapping
     *
     * Strategies:
     * 1. Non-English default: Direct lookup msgstr→msgid in default locale .po
     * 2. Non-English default: Extract from "number + word" pattern (e.g., "17 товаров")
     * 3. BOTH defaults: Check msgid/msgid_plural pairs (key for English default)
     *
     * @param string $text Text to lookup in .po files
     * @param string $domain Domain hint (woodmart, woocommerce, etc)
     * @return array|null Array with msgid, domain, plural_pair, russian_forms OR null if not found
     */
    private function get_english_gettext($text, $domain = 'default') {
        static $po_cache = array(); // Cache parsed .po files

        if (empty($text)) {
            return null;
        }

        // v5.2.71: UNIVERSAL APPROACH - Check ALL .po files of ALL languages
        // Text can be:
        //   1) English msgid "Add to cart" → found as msgid in any .po file
        //   2) Translated msgstr "In den Warenkorb legen" → found as msgstr in de_DE.po
        // This works universally on ANY page (/en/, /de/, /ru/, etc.) without complex logic!

        // v5.2.58: Handle numeric prefixes like "17 products" → try "products"
        $original_text = $text;
        $text_without_number = preg_replace('/^\d+\s+/', '', $text);
        $has_numeric_prefix = ($text_without_number !== $text);

        // v5.2.71: UNIVERSAL - Check ALL .po files of ALL languages
        // This ensures we find the text whether it's English msgid or translated msgstr
        $locales_to_check = array('ru_RU', 'de_DE', 'fr_FR', 'es_ES', 'it_IT', 'zh_CN', 'ja', 'ko_KR');

        // Build list of .po files to check
        $po_files = array();

        // Priority order: domain hint first, then common domains
        $domains_to_check = array();
        if (!empty($domain) && $domain !== 'default') {
            $domains_to_check[] = $domain;
        }
        $domains_to_check = array_merge($domains_to_check, array('woodmart', 'woocommerce'));

        // Map domains to .po file paths
        foreach ($domains_to_check as $check_domain) {
            foreach ($locales_to_check as $locale) {
                $possible_paths = array(
                    // Loco Translate locations
                    WP_CONTENT_DIR . "/languages/loco/themes/{$check_domain}-{$locale}.po",
                    WP_CONTENT_DIR . "/languages/loco/plugins/{$check_domain}-{$locale}.po",
                    // Standard WordPress locations
                    WP_CONTENT_DIR . "/languages/themes/{$check_domain}-{$locale}.po",
                    WP_CONTENT_DIR . "/languages/plugins/{$check_domain}-{$locale}.po",
                    // Theme/plugin directories
                    WP_CONTENT_DIR . "/themes/{$check_domain}/languages/{$check_domain}-{$locale}.po",
                    WP_CONTENT_DIR . "/plugins/{$check_domain}/languages/{$check_domain}-{$locale}.po",
                );

                foreach ($possible_paths as $path) {
                    if (file_exists($path) && !in_array($path, $po_files)) {
                        $po_files[] = $path;
                    }
                }
            }
        }

        // Search in each .po file
        foreach ($po_files as $po_file) {
            // Check cache first
            if (!isset($po_cache[$po_file])) {
                $po_cache[$po_file] = $this->parse_po_file($po_file);
            }

            $po_data = $po_cache[$po_file];
            $translations = $po_data['map'];  // v5.2.43: Extract map from new structure
            $plural_pairs = $po_data['plural_pairs'];  // v5.2.43: Extract plural pairs
            $plural_forms = $po_data['plural_forms'] ?? array();  // v5.2.50: Extract Russian forms

            // v5.2.71: Strategy 1 - UNIVERSAL reverse mapping (check msgstr → msgid)
            // Text might be a translation (msgstr), find English msgid
            if (isset($translations[$text])) {
                $english_msgid = $translations[$text];

                // v5.2.42: Extract domain from .po filename
                // Example: "woodmart-de_DE.po" → "woodmart"
                $filename = basename($po_file, '.po');
                $domain = preg_replace('/-[a-z]{2}_[A-Z]{2}$/', '', $filename);

                // v5.2.43: Check if this has a plural pair
                $plural_pair = isset($plural_pairs[$english_msgid]) ? $plural_pairs[$english_msgid] : null;

                // v5.2.50: Get Russian plural forms if available
                $russian_forms = isset($plural_forms[$english_msgid]) ? $plural_forms[$english_msgid] : null;

                return array(
                    'msgid' => $english_msgid,
                    'domain' => $domain,
                    'is_plural' => !empty($plural_pair),
                    'plural_pair' => $plural_pair,
                    'russian_forms' => $russian_forms
                );
            }

            // v5.2.67: Strategy 2 - UNIVERSAL "number + word" extraction (works for ANY default language)
            // Pattern: "17 products", "17 товаров", "17 produits" → extract word → lookup
            // Best practice: no hardcoded language checks, works universally
            if (preg_match('/^\d+\s+(.+)$/u', $text, $matches)) {
                $word_only = $matches[1];  // Extract word without number

                // Try reverse mapping first (for non-English default languages)
                if (isset($translations[$word_only])) {
                    $english_msgid = $translations[$word_only];

                    $filename = basename($po_file, '.po');
                    $domain = preg_replace('/-[a-z]{2}_[A-Z]{2}$/', '', $filename);

                    // Check if this has a plural pair
                    $plural_pair = isset($plural_pairs[$english_msgid]) ? $plural_pairs[$english_msgid] : null;

                    // Get Russian plural forms if available
                    $russian_forms = isset($plural_forms[$english_msgid]) ? $plural_forms[$english_msgid] : null;

                    return array(
                        'msgid' => $english_msgid,
                        'domain' => $domain,
                        'is_plural' => true,
                        'plural_pair' => $plural_pair,
                        'russian_forms' => $russian_forms,
                        'original_with_number' => $text  // Keep original for context
                    );
                }

                // If reverse mapping failed, try direct plural_pairs check (for English default or untranslated)
                if (isset($plural_pairs[$word_only])) {
                    $filename = basename($po_file, '.po');
                    $domain = preg_replace('/-[a-z]{2}_[A-Z]{2}$/', '', $filename);

                    // Get Russian plural forms if available
                    $russian_forms = isset($plural_forms[$word_only]) ? $plural_forms[$word_only] : null;

                    return array(
                        'msgid' => $word_only,  // Extracted word is already msgid
                        'domain' => $domain,
                        'is_plural' => true,
                        'plural_pair' => $plural_pairs[$word_only],
                        'russian_forms' => $russian_forms,
                        'original_with_number' => $text  // Keep original for context
                    );
                }
            }

            // v5.2.62: Strategy 3 - Direct lookup in msgid/msgid_plural
            // CRITICAL for English default: text is already msgid, check plural_pairs directly
            // Also works for non-English default with untranslated English text
            if (isset($plural_pairs[$text])) {
                // Text is either a msgid or msgid_plural - has a pair!
                $filename = basename($po_file, '.po');
                $domain = preg_replace('/-[a-z]{2}_[A-Z]{2}$/', '', $filename);

                // v5.2.50: Get Russian plural forms if available
                $russian_forms = isset($plural_forms[$text]) ? $plural_forms[$text] : null;

                return array(
                    'msgid' => $text,  // Already in English
                    'domain' => $domain,
                    'is_plural' => true,  // Part of plural pair
                    'plural_pair' => $plural_pairs[$text],  // Link to counterpart
                    'russian_forms' => $russian_forms  // v5.2.50: Include all 3 Russian forms
                );
            }

            // v5.2.67: Strategy 4 - UNIVERSAL check if text is msgid (non-plural gettext)
            // For strings like "Add to cart", "Categories" - works for ANY default language
            // Check if text is a VALUE in translations map (meaning it's an English msgid)
            if (in_array($text, $translations, true)) {
                // Text exists as msgid in .po file!
                $filename = basename($po_file, '.po');
                $domain = preg_replace('/-[a-z]{2}_[A-Z]{2}$/', '', $filename);

                return array(
                    'msgid' => $text,  // Already in English
                    'domain' => $domain,
                    'is_plural' => false,  // Not a plural form
                    'plural_pair' => null,
                    'russian_forms' => null
                );
            }
        }

        // v5.2.74: FALLBACK - Check Lingua database for gettext strings saved on OTHER languages
        // Example: "In den Warenkorb legen" (DE) might not have de_DE.po, but if "Add to cart"
        // was already saved as gettext from ru_RU.po, we can use that msgid!
        global $wpdb;
        $string_table = $wpdb->prefix . 'lingua_string_translations';

        // Step 1: Find original_text for this translated_text (ANY source)
        $find_original = $wpdb->get_var($wpdb->prepare("
            SELECT original_text
            FROM {$string_table}
            WHERE translated_text = %s
            LIMIT 1
        ", $text));

        // Step 2: If found, check if this original_text is gettext on ANY language
        if ($find_original) {
            $gettext_record = $wpdb->get_row($wpdb->prepare("
                SELECT original_text, gettext_domain
                FROM {$string_table}
                WHERE original_text = %s
                AND source = 'gettext'
                LIMIT 1
            ", $find_original));

            if ($gettext_record) {
                $msgid = $gettext_record->original_text;
                $domain = $gettext_record->gettext_domain ?: 'default';

                // v5.2.75: Try to load plural metadata from .po files of OTHER languages
                // Example: no de_DE.po, but ru_RU.po has plural info for "Add to cart"
                $plural_pair = null;
                $russian_forms = null;
                $is_plural = false;

                // Check .po files we already parsed
                foreach ($po_files as $po_file) {
                    if (!isset($po_cache[$po_file])) {
                        $po_cache[$po_file] = $this->parse_po_file($po_file);
                    }

                    $po_data = $po_cache[$po_file];
                    $plural_pairs = $po_data['plural_pairs'] ?? array();
                    $plural_forms = $po_data['plural_forms'] ?? array();

                    // Check if this msgid has plural info
                    if (isset($plural_pairs[$msgid])) {
                        $plural_pair = $plural_pairs[$msgid];
                        $is_plural = true;
                    }
                    if (isset($plural_forms[$msgid])) {
                        $russian_forms = $plural_forms[$msgid];
                    }

                    // If found plural info, break
                    if ($is_plural) {
                        break;
                    }
                }

                return array(
                    'msgid' => $msgid,
                    'domain' => $domain,
                    'is_plural' => $is_plural,
                    'plural_pair' => $plural_pair,
                    'russian_forms' => $russian_forms
                );
            }
        }

        // Step 3: Direct check - maybe text IS the msgid (English original)
        $direct_gettext = $wpdb->get_row($wpdb->prepare("
            SELECT original_text, gettext_domain
            FROM {$string_table}
            WHERE original_text = %s
            AND source = 'gettext'
            LIMIT 1
        ", $text));

        if ($direct_gettext) {
            $msgid = $direct_gettext->original_text;
            $domain = $direct_gettext->gettext_domain ?: 'default';

            // v5.2.75: Try to load plural metadata from .po files
            $plural_pair = null;
            $russian_forms = null;
            $is_plural = false;

            // Check .po files we already parsed
            foreach ($po_files as $po_file) {
                if (!isset($po_cache[$po_file])) {
                    $po_cache[$po_file] = $this->parse_po_file($po_file);
                }

                $po_data = $po_cache[$po_file];
                $plural_pairs = $po_data['plural_pairs'] ?? array();
                $plural_forms = $po_data['plural_forms'] ?? array();

                // Check if this msgid has plural info
                if (isset($plural_pairs[$msgid])) {
                    $plural_pair = $plural_pairs[$msgid];
                    $is_plural = true;
                }
                if (isset($plural_forms[$msgid])) {
                    $russian_forms = $plural_forms[$msgid];
                }

                // If found plural info, break
                if ($is_plural) {
                    break;
                }
            }

            return array(
                'msgid' => $msgid,
                'domain' => $domain,
                'is_plural' => $is_plural,
                'plural_pair' => $plural_pair,
                'russian_forms' => $russian_forms
            );
        }

        // Not found in any .po file OR Lingua database
        return null;
    }

    /**
     * v5.2.59: Helper method - Convert language code to WordPress locale
     * Maps 2-letter codes (en, ru, de) to WordPress locales (en_US, ru_RU, de_DE)
     *
     * @param string $lang_code 2-letter language code
     * @return string WordPress locale
     */
    private function get_locale_from_lang_code($lang_code) {
        $locale_map = array(
            'ru' => 'ru_RU',
            'en' => 'en_US',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'es' => 'es_ES',
            'it' => 'it_IT',
            'pt' => 'pt_PT',
            'zh' => 'zh_CN',
            'ja' => 'ja',
            'ko' => 'ko_KR',
            'ar' => 'ar'
        );

        return $locale_map[$lang_code] ?? 'en_US';  // Default to en_US if not found
    }

    /**
     * v5.2.43: Parse .po file and build msgstr → msgid reverse map (with plural support)
     *
     * @param string $po_file Path to .po file
     * @return array Array with 'map' (msgstr → msgid) and 'plural_pairs' (singular ↔ plural)
     */
    private function parse_po_file($po_file) {
        $map = array();
        $plural_pairs = array();  // v5.2.43: Track singular ↔ plural relationships
        $plural_forms = array();  // v5.2.50: Store all 3 Russian plural forms for Default editing

        if (!file_exists($po_file) || !is_readable($po_file)) {
            return array('map' => $map, 'plural_pairs' => $plural_pairs, 'plural_forms' => $plural_forms);
        }

        $content = file_get_contents($po_file);
        if ($content === false) {
            return array('map' => $map, 'plural_pairs' => $plural_pairs, 'plural_forms' => $plural_forms);
        }

        // v5.2.43: FIRST - Parse PLURAL forms (msgid_plural)
        // Pattern: msgid "singular"\nmsgid_plural "plural"\nmsgstr[0] "форма0"\nmsgstr[1] "форма1"\nmsgstr[2] "форма2"
        preg_match_all('/msgid\s+"([^"]+)"\s+msgid_plural\s+"([^"]+)"\s+msgstr\[0\]\s+"([^"]*)"\s+msgstr\[1\]\s+"([^"]*)"\s+msgstr\[2\]\s+"([^"]*)"/s', $content, $plural_matches, PREG_SET_ORDER);

        $plural_count = 0;
        foreach ($plural_matches as $match) {
            $msgid = $match[1];           // English singular: "product"
            $msgid_plural = $match[2];    // English plural: "products"
            $msgstr_0 = $match[3];        // Russian form 0: "товар"
            $msgstr_1 = $match[4];        // Russian form 1: "товара"
            $msgstr_2 = $match[5];        // Russian form 2: "товаров"

            // v5.2.43: Store plural pair relationship
            $plural_pairs[$msgid] = $msgid_plural;
            $plural_pairs[$msgid_plural] = $msgid;

            // v5.2.50: Store all 3 Russian plural forms for Default editing UI
            $plural_forms[$msgid] = array(
                'msgid_plural' => $msgid_plural,
                'msgstr_0' => $msgstr_0,  // форма для 1 (товар)
                'msgstr_1' => $msgstr_1,  // форма для 2-4 (товара)
                'msgstr_2' => $msgstr_2   // форма для 5+ (товаров)
            );
            $plural_forms[$msgid_plural] = array(
                'msgid_singular' => $msgid,
                'msgstr_0' => $msgstr_0,
                'msgstr_1' => $msgstr_1,
                'msgstr_2' => $msgstr_2
            );

            // Map singular form to msgid
            if (!empty($msgstr_0) && $msgstr_0 !== $msgid) {
                $map[$msgstr_0] = $msgid;
            }

            // Map all plural forms to msgid_plural
            if (!empty($msgstr_1) && $msgstr_1 !== $msgid_plural) {
                $map[$msgstr_1] = $msgid_plural;
            }
            if (!empty($msgstr_2) && $msgstr_2 !== $msgid_plural) {
                $map[$msgstr_2] = $msgid_plural;
            }

            $plural_count++;
        }

        // v5.2.43: SECOND - Parse SINGULAR forms (regular msgid/msgstr)
        // Pattern: msgid "English"\nmsgstr "Russian"
        preg_match_all('/msgid\s+"([^"]+)"\s+msgstr\s+"([^"]+)"/s', $content, $matches, PREG_SET_ORDER);

        $singular_count = 0;
        foreach ($matches as $match) {
            $msgid = $match[1];   // English original
            $msgstr = $match[2];  // Russian translation

            // Only add if msgstr is not empty and different from msgid
            // Skip if already added as plural form
            if (!empty($msgstr) && $msgstr !== $msgid && !isset($map[$msgstr])) {
                $map[$msgstr] = $msgid;  // Reverse map: Russian → English
                $singular_count++;
            }
        }

        return array(
            'map' => $map,
            'plural_pairs' => $plural_pairs,
            'plural_forms' => $plural_forms  // v5.2.50: All 3 Russian forms for Default editing
        );
    }

    /**
     * v5.2.53: Load gettext translations FOR target language (msgid → msgstr map)
     * Used when translating FROM default language TO target language with .po files
     *
     * @param string $target_language Target language code (e.g., 'ru', 'en', 'de')
     * @return array Array with 'translations' (msgid → msgstr) and 'plural_forms' (msgid → array of forms)
     */
    public function load_target_language_translations($target_language) {
        $translations = array();  // msgid → msgstr (for singular strings)
        $plural_forms = array();  // msgid → array('msgid_plural' => ..., 'forms' => [0,1,2])

        // Map language code to WordPress locale
        $locale_map = array(
            'ru' => 'ru_RU',
            'en' => 'en_US',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'es' => 'es_ES',
            'it' => 'it_IT',
            'pt' => 'pt_PT',
            'zh' => 'zh_CN',
            'ja' => 'ja',
            'ko' => 'ko_KR'
        );

        $locale = $locale_map[$target_language] ?? $target_language;

        // Find all .po files for this locale
        $po_directories = array(
            WP_LANG_DIR . '/plugins',
            WP_LANG_DIR . '/themes',
            WP_LANG_DIR . '/loco/plugins',
            WP_LANG_DIR . '/loco/themes'
        );

        $po_files = array();
        foreach ($po_directories as $dir) {
            if (is_dir($dir)) {
                $files = glob($dir . '/*-' . $locale . '.po');
                if ($files) {
                    $po_files = array_merge($po_files, $files);
                }
            }
        }

        // Parse each .po file
        foreach ($po_files as $po_file) {
            $parsed = $this->parse_target_po_file($po_file);
            $translations = array_merge($translations, $parsed['translations']);
            $plural_forms = array_merge($plural_forms, $parsed['plural_forms']);
        }

        // v5.2.76: Load translations from DATABASE (plural forms as multiple rows)
        global $wpdb;
        $table_name = $wpdb->prefix . 'lingua_string_translations';

        // Query ALL translations for this language, ordered by plural_form to group them
        $db_results = $wpdb->get_results($wpdb->prepare(
            "SELECT original_text, translated_text, plural_form, original_plural
             FROM `{$table_name}`
             WHERE language_code = %s
             AND source = 'gettext'
             ORDER BY original_text, plural_form",
            $target_language
        ), ARRAY_A);

        if ($db_results) {
            $grouped_plurals = array();  // Group plural forms by original_text

            foreach ($db_results as $row) {
                $original = $row['original_text'];
                $translated = $row['translated_text'];
                $plural_form = $row['plural_form'];
                $original_plural = $row['original_plural'];

                // PLURAL FORM (plural_form is NOT NULL)
                if ($plural_form !== null) {
                    // v5.2.76 FIX: Skip incomplete plural entries (missing original_plural)
                    // Don't override .po file data with incomplete DB data
                    if (empty($original_plural)) {
                        continue;
                    }

                    // Initialize group if first time seeing this original
                    if (!isset($grouped_plurals[$original])) {
                        $grouped_plurals[$original] = array(
                            'original_plural' => $original_plural,
                            'forms' => array()
                        );
                    }

                    // Add this form to the group (index by plural_form: 0, 1, 2)
                    $grouped_plurals[$original]['forms'][intval($plural_form)] = $translated;
                } else {
                    // SINGLE STRING (plural_form = NULL)
                    // Override .po translation with database version (Lingua has priority)
                    $translations[$original] = $translated;
                }
            }

            // Convert grouped plurals to the same format as .po parsing
            foreach ($grouped_plurals as $msgid => $data) {
                $msgid_plural = $data['original_plural'];
                $forms = $data['forms'];

                // Store plural forms for both singular and plural msgid
                $plural_forms[$msgid] = array(
                    'msgid_plural' => $msgid_plural,
                    'forms' => $forms  // Array: 0 => "товар", 1 => "товара", 2 => "товаров"
                );

                if ($msgid_plural) {
                    $plural_forms[$msgid_plural] = array(
                        'msgid_singular' => $msgid,
                        'forms' => $forms
                    );
                }

                // Also add as singular translation for simple lookups (use form 0)
                if (!empty($forms[0])) {
                    $translations[$msgid] = $forms[0];
                }

                // v5.2.113: CRITICAL FIX - Use last available form for plural (supports 2-form AND 3-form languages)
                // Italian/French/German: forms[0,1] → use forms[1] for plural
                // Russian/Polish: forms[0,1,2] → use forms[2] for plural
                if ($msgid_plural) {
                    // Find the highest plural form index that has a value
                    $last_form_index = max(array_keys($forms));
                    if (isset($forms[$last_form_index]) && !empty($forms[$last_form_index])) {
                        $translations[$msgid_plural] = $forms[$last_form_index];
                    }
                }
            }
        }

        return array(
            'translations' => $translations,
            'plural_forms' => $plural_forms
        );
    }

    /**
     * v5.2.53: Parse .po file to create msgid → msgstr map (direct translation)
     *
     * @param string $po_file Path to .po file
     * @return array Array with 'translations' and 'plural_forms'
     */
    private function parse_target_po_file($po_file) {
        $translations = array();
        $plural_forms = array();

        if (!file_exists($po_file) || !is_readable($po_file)) {
            return array('translations' => $translations, 'plural_forms' => $plural_forms);
        }

        $content = file_get_contents($po_file);
        if ($content === false) {
            return array('translations' => $translations, 'plural_forms' => $plural_forms);
        }

        // FIRST: Parse PLURAL forms (msgid + msgid_plural → msgstr[0], msgstr[1], msgstr[2])
        preg_match_all('/msgid\s+"([^"]+)"\s+msgid_plural\s+"([^"]+)"\s+msgstr\[0\]\s+"([^"]*)"\s+msgstr\[1\]\s+"([^"]*)"\s+msgstr\[2\]\s+"([^"]*)"/s', $content, $plural_matches, PREG_SET_ORDER);

        foreach ($plural_matches as $match) {
            $msgid = $match[1];           // English singular: "product"
            $msgid_plural = $match[2];    // English plural: "products"
            $msgstr_0 = $match[3];        // Russian form 0: "товар"
            $msgstr_1 = $match[4];        // Russian form 1: "товара"
            $msgstr_2 = $match[5];        // Russian form 2: "товаров"

            // Skip empty translations
            if (empty($msgstr_0) && empty($msgstr_1) && empty($msgstr_2)) {
                continue;
            }

            // Store plural forms for both singular and plural msgid
            $plural_forms[$msgid] = array(
                'msgid_plural' => $msgid_plural,
                'forms' => array($msgstr_0, $msgstr_1, $msgstr_2)
            );
            $plural_forms[$msgid_plural] = array(
                'msgid_singular' => $msgid,
                'forms' => array($msgstr_0, $msgstr_1, $msgstr_2)
            );

            // Also add as singular translations for simple lookups
            if (!empty($msgstr_0)) {
                $translations[$msgid] = $msgstr_0;  // "product" → "товар"
            }
            if (!empty($msgstr_2)) {
                $translations[$msgid_plural] = $msgstr_2;  // "products" → "товаров" (default to form 2)
            }
        }

        // SECOND: Parse SINGULAR forms (msgid → msgstr)
        preg_match_all('/msgid\s+"([^"]+)"\s+msgstr\s+"([^"]+)"/s', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $msgid = $match[1];   // English original
            $msgstr = $match[2];  // Target language translation

            // Only add if msgstr is not empty and different from msgid
            // Skip if already added as plural form
            if (!empty($msgstr) && $msgstr !== $msgid && !isset($translations[$msgid])) {
                $translations[$msgid] = $msgstr;
            }
        }

        return array(
            'translations' => $translations,
            'plural_forms' => $plural_forms
        );
    }
}