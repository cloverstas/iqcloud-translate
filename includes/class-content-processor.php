<?php
/**
 * Content Processor for translations
 *
 * @package Lingua
 * @version 2.0.0 - Full DOM Parsing Migration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Content_Processor {

    private $dom_extractor;
    private $output_buffer;
    private $compatibility_mode;

    public function __construct() {
        // Инициализируем новые компоненты архитектуры
        if (class_exists('Lingua_Full_Dom_Extractor')) {
            $this->dom_extractor = new Lingua_Full_Dom_Extractor();
        }

        if (class_exists('Lingua_Output_Buffer')) {
            $this->output_buffer = new Lingua_Output_Buffer();
        }

        // Режим совместимости для плавной миграции
        $this->compatibility_mode = get_option('lingua_compatibility_mode', false);
    }

    /**
     * НОВЫЙ ОСНОВНОЙ МЕТОД: Извлечение контента через полный DOM парсинг
     * Заменяет старый гибридный подход (WordPress API + ограниченный DOM)
     */
    public function extract_translatable_content($content = null, $post_id = null) {
        // Пытаемся использовать новую архитектуру полного DOM парсинга
        $dom_extracted = $this->extract_via_full_dom($content, $post_id);

        if ($dom_extracted !== false) {
            return $this->convert_to_unified_structure($dom_extracted);
        }

        // Fallback на старый метод если DOM парсинг недоступен
        if ($this->compatibility_mode) {
            return $this->generate_unified_json_structure_legacy($content, $post_id);
        }

        return array();
    }

    /**
     * НОВЫЙ ГЛАВНЫЙ МЕТОД: Полный DOM парсинг вместо WordPress API
     * Извлечение из полного HTML
     */
    public function generate_unified_json_structure($content = '', $post_id = null) {
        try {
            // Получаем ID поста
            if (!$post_id) {
                global $post;
                $post_id = $post ? $post->ID : 0;
            }
            if (!$post_id) {
                return array();
            }

            // НОВАЯ АРХИТЕКТУРА: Получаем полный HTML страницы
            $full_html = $this->get_full_page_html($post_id);

            if (!$full_html) {
                // Fallback на старый метод если не можем получить HTML
                return $this->generate_unified_json_structure_legacy($content, $post_id);
            }

            // Извлекаем контент через новый DOM экстрактор
            if (!$this->dom_extractor) {
                return $this->generate_unified_json_structure_legacy($content, $post_id);
            }

            $dom_extracted = $this->dom_extractor->extract_from_full_html($full_html, $post_id);

            // Конвертируем в формат совместимый с текущим UI
            $unified_structure = $this->convert_to_unified_structure($dom_extracted);

            // Добавляем контекст для предотвращения дубликатов
            $unified_structure = $this->add_context_to_json($unified_structure, $post_id);

            // Удаляем пустые группы
            $unified_structure = array_filter($unified_structure, function($group) {
                return !empty($group);
            });

            // Логируем статистику
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $total_strings = 0;
                foreach ($unified_structure as $group) {
                    $total_strings += count($group);
                }
                lingua_debug_log("[LINGUA DOM v2.0] Extracted {$total_strings} strings via full DOM parsing for post {$post_id}");
            }

            return $unified_structure;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA ERROR] DOM extraction failed: " . $e->getMessage());
            }

            // Fallback на старый метод при ошибке
            return $this->generate_unified_json_structure_legacy($content, $post_id);
        }
    }

    /**
     * Получение полного HTML страницы для DOM парсинга
     */
    private function get_full_page_html($post_id) {
        // Сначала пытаемся получить из кэша буферизации
        if ($this->output_buffer) {
            $cached_content = $this->output_buffer->get_cached_content($post_id);
            if ($cached_content) {
                return $cached_content;
            }
        }

        // Принудительное извлечение через HTTP запрос
        if ($this->output_buffer) {
            $forced_content = $this->output_buffer->force_extract_content($post_id);
            if ($forced_content) {
                return $forced_content;
            }
        }

        // Последний fallback - получаем HTML через wp_remote_get
        return $this->fetch_page_html_fallback($post_id);
    }

    /**
     * Fallback получение HTML страницы
     */
    private function fetch_page_html_fallback($post_id) {
        $page_url = get_permalink($post_id);
        if (!$page_url) {
            return false;
        }

        $response = wp_remote_get($page_url, array(
            'timeout' => 30,
            'user-agent' => 'Lingua DOM Extractor/2.0',
            'headers' => array(
                'Accept' => 'text/html,application/xhtml+xml'
            )
        ));

        if (is_wp_error($response)) {
            lingua_debug_log('[Lingua] HTTP fallback failed: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            lingua_debug_log("[Lingua] HTTP fallback returned status {$status_code}");
            return false;
        }

        return wp_remote_retrieve_body($response);
    }

    /**
     * Извлечение через полный DOM парсинг
     */
    private function extract_via_full_dom($content, $post_id) {
        if (!$this->dom_extractor) {
            return false;
        }

        // Если есть готовый HTML контент, используем его
        if (!empty($content) && strlen($content) > 200) {
            return $this->dom_extractor->extract_from_full_html($content, $post_id);
        }

        // Иначе получаем полный HTML страницы
        $full_html = $this->get_full_page_html($post_id);
        if (!$full_html) {
            return false;
        }

        return $this->dom_extractor->extract_from_full_html($full_html, $post_id);
    }

    /**
     * Конвертация новой DOM структуры в формат совместимый с текущим UI
     */
    private function convert_to_unified_structure($dom_extracted) {
        if (empty($dom_extracted)) {
            return array();
        }

        return array(
            'core_fields' => $this->extract_core_fields_from_dom($dom_extracted),
            'seo_fields' => $this->format_seo_fields($dom_extracted['meta_information'] ?? array()),
            'meta_fields' => array(), // Упразднено - все через DOM
            'taxonomy_terms' => array(), // Упразднено - все через DOM
            'content_blocks' => $this->format_content_blocks($dom_extracted['main_content'] ?? array()),
            'page_strings' => $this->format_page_strings(array_merge(
                $dom_extracted['navigation'] ?? array(),
                $dom_extracted['ui_elements'] ?? array()
            ))
        );
    }

    /**
     * Извлечение core fields из DOM структуры
     */
    private function extract_core_fields_from_dom($dom_structure) {
        $core_fields = array();

        // Ищем title в мета информации
        foreach ($dom_structure['meta_information'] ?? array() as $item) {
            if (isset($item['dom_info']['tag']) && $item['dom_info']['tag'] === 'title') {
                $core_fields[] = array(
                    'id' => 'title',
                    'type' => 'title',
                    'original' => $item['original'],
                    'translated' => $item['translated'] ?? '',
                    'context' => 'core_fields.title'
                );
                break;
            }
        }

        // Ищем H1 как заголовок страницы если title не найден
        if (empty($core_fields)) {
            foreach ($dom_structure['main_content'] ?? array() as $item) {
                if (isset($item['dom_info']['tag']) && $item['dom_info']['tag'] === 'h1') {
                    $core_fields[] = array(
                        'id' => 'title',
                        'type' => 'title',
                        'original' => $item['original'],
                        'translated' => $item['translated'] ?? '',
                        'context' => 'core_fields.title'
                    );
                    break;
                }
            }
        }

        return $core_fields;
    }

    /**
     * Форматирование SEO полей для совместимости с UI
     */
    private function format_seo_fields($meta_items) {
        $seo_fields = array();
        $counter = 1;

        foreach ($meta_items as $item) {
            $seo_fields[] = array(
                'id' => 'seo_field_' . $counter,
                'type' => $item['type'] ?? 'seo_field',
                'original' => $item['original'],
                'translated' => $item['translated'] ?? '',
                'context' => 'seo_fields.' . ($item['context'] ?? 'general')
            );
            $counter++;
        }

        return $seo_fields;
    }

    /**
     * Форматирование content blocks для совместимости с UI
     */
    private function format_content_blocks($content_items) {
        $content_blocks = array();
        $counter = 1;

        foreach ($content_items as $item) {
            $block = array(
                'id' => 'content_block_' . $counter,
                'type' => 'content_block',
                'original' => $item['original'],
                'translated' => $item['translated'] ?? '',
                'context' => 'content_blocks.' . $counter,
                'word_count' => $item['word_count'] ?? str_word_count($item['original'])
            );

            // v5.2.65: Preserve gettext metadata for plural grouping in UI
            if (isset($item['source'])) {
                $block['source'] = $item['source'];
            }
            if (isset($item['gettext_domain'])) {
                $block['gettext_domain'] = $item['gettext_domain'];
            }
            if (isset($item['is_plural'])) {
                $block['is_plural'] = $item['is_plural'];
            }
            if (isset($item['plural_pair'])) {
                $block['plural_pair'] = $item['plural_pair'];
            }
            if (isset($item['russian_forms']) && is_array($item['russian_forms'])) {
                $block['russian_forms'] = $item['russian_forms'];
            }

            $content_blocks[] = $block;
            $counter++;
        }

        return $content_blocks;
    }

    /**
     * Форматирование page strings для совместимости с UI
     */
    private function format_page_strings($ui_items) {
        $page_strings = array();
        $counter = 1;

        foreach ($ui_items as $item) {
            // Пропускаем слишком короткие строки
            if (strlen(trim($item['original'])) < 2) {
                continue;
            }

            $string = array(
                'id' => 'page_string_' . $counter,
                'type' => 'page_string',
                'original' => $item['original'],
                'translated' => $item['translated'] ?? '',
                'context' => 'page_strings.' . ($item['context'] ?? 'ui'),
                'category' => $item['context'] ?? 'ui_elements'
            );

            // v5.2.65: Preserve gettext metadata for plural grouping in UI
            if (isset($item['source'])) {
                $string['source'] = $item['source'];
            }
            if (isset($item['gettext_domain'])) {
                $string['gettext_domain'] = $item['gettext_domain'];
            }
            if (isset($item['is_plural'])) {
                $string['is_plural'] = $item['is_plural'];
            }
            if (isset($item['plural_pair'])) {
                $string['plural_pair'] = $item['plural_pair'];
            }
            if (isset($item['russian_forms']) && is_array($item['russian_forms'])) {
                $string['russian_forms'] = $item['russian_forms'];
            }

            $page_strings[] = $string;
            $counter++;
        }

        return $page_strings;
    }

    /**
     * LEGACY: Старый метод для режима совместимости
     * Сохранен для плавной миграции
     */
    public function generate_unified_json_structure_legacy($content = '', $post_id = null) {
        try {
            // Get current post if not provided
            if (!$post_id) {
                global $post;
                $post_id = $post ? $post->ID : 0;
            }
            if (!$post_id) {
                return array();
            }

            $unified_structure = array(
                'core_fields' => $this->extract_core_fields_legacy($post_id),
                'seo_fields' => $this->extract_seo_fields_legacy($post_id),
                'meta_fields' => $this->extract_meta_fields_legacy($post_id),
                'taxonomy_terms' => $this->extract_taxonomy_terms_legacy($post_id),
                'content_blocks' => $this->extract_content_blocks_legacy($content ?: get_post_field('post_content', $post_id)),
                'page_strings' => $this->extract_page_strings_legacy($content ?: get_post_field('post_content', $post_id))
            );

            // Add context to prevent duplicates
            $unified_structure = $this->add_context_to_json($unified_structure, $post_id);

            // Remove empty groups
            $unified_structure = array_filter($unified_structure, function($group) {
                return !empty($group);
            });

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $total_strings = 0;
                foreach ($unified_structure as $group) {
                    $total_strings += count($group);
                }
                lingua_debug_log("[LINGUA LEGACY] Generated JSON structure with {$total_strings} translatable strings for post {$post_id}");
            }

            return $unified_structure;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA ERROR] generate_unified_json_structure_legacy failed: " . $e->getMessage());
            }
            return array();
        }
    }

    /**
     * LEGACY: Извлечение core fields через WordPress API
     */
    private function extract_core_fields_legacy($post_id) {
        $post = get_post($post_id);
        if (!$post) return array();

        $core_fields = array();

        // Title
        if (!empty($post->post_title)) {
            $core_fields[] = array(
                'id' => 'title',
                'type' => 'title',
                'original' => $post->post_title,
                'translated' => '',
                'context' => 'core_fields.title'
            );
        }

        // Excerpt
        if (!empty($post->post_excerpt)) {
            $core_fields[] = array(
                'id' => 'excerpt',
                'type' => 'excerpt',
                'original' => $post->post_excerpt,
                'translated' => '',
                'context' => 'core_fields.excerpt'
            );
        }

        return $core_fields;
    }

    /**
     * LEGACY: Извлечение SEO полей через WordPress API
     */
    private function extract_seo_fields_legacy($post_id) {
        $seo_fields = array();

        // Yoast SEO fields
        $yoast_fields = array(
            'seo_title' => '_yoast_wpseo_title',
            'seo_description' => '_yoast_wpseo_metadesc',
            'og_title' => '_yoast_wpseo_opengraph-title',
            'og_description' => '_yoast_wpseo_opengraph-description'
        );

        foreach ($yoast_fields as $field_key => $meta_key) {
            $value = get_post_meta($post_id, $meta_key, true);
            if (!empty($value)) {
                $seo_fields[] = array(
                    'id' => $field_key,
                    'type' => 'seo_field',
                    'original' => $value,
                    'translated' => '',
                    'context' => 'seo_fields.' . $field_key
                );
            }
        }

        return $seo_fields;
    }

    /**
     * LEGACY: Заглушки для остальных legacy методов
     */
    private function extract_meta_fields_legacy($post_id) { return array(); }
    private function extract_taxonomy_terms_legacy($post_id) { return array(); }
    private function extract_content_blocks_legacy($content) { return array(); }
    private function extract_page_strings_legacy($content) { return array(); }

    /**
     * Добавление контекста для предотвращения дубликатов
     */
    private function add_context_to_json($structure, $post_id) {
        foreach ($structure as $group_name => &$items) {
            foreach ($items as &$item) {
                if (!isset($item['context'])) {
                    $item['context'] = $group_name . '.item_' . wp_generate_uuid4();
                }
                // Добавляем post_id в контекст
                $item['post_id'] = $post_id;
            }
        }
        return $structure;
    }

    /**
     * Apply translations to content
     */
    public function apply_translations($content, $translations) {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->encoding = 'UTF-8';

        libxml_use_internal_errors(true);

        $wrapped_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $content . '</body></html>';
        $dom->loadHTML($wrapped_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        libxml_clear_errors();

        foreach ($translations as $translation) {
            if ($translation['type'] === 'text' && isset($translation['node'])) {
                // Sanitize translated text to prevent XSS
                $safe_text = wp_kses_post($translation['translated']);
                $translation['node']->nodeValue = $safe_text;
            } elseif ($translation['type'] === 'attribute' && isset($translation['element'])) {
                // Sanitize attribute values to prevent XSS
                $safe_attribute = esc_attr($translation['translated']);
                $translation['element']->setAttribute($translation['attribute'], $safe_attribute);
            }
        }

        // Get body content only
        $body = $dom->getElementsByTagName('body')->item(0);
        $html = '';

        if ($body) {
            foreach ($body->childNodes as $child) {
                $html .= $dom->saveHTML($child);
            }
        }

        return $html;
    }

    /**
     * Process shortcodes in content
     */
    public function process_shortcodes($content) {
        // Temporarily replace shortcodes with placeholders
        $placeholders = array();
        $index = 0;

        $content = preg_replace_callback('/\[[^\]]+\]/', function($matches) use (&$placeholders, &$index) {
            $placeholder = "<!--LINGUA_SHORTCODE_{$index}-->";
            $placeholders[$placeholder] = $matches[0];
            $index++;
            return $placeholder;
        }, $content);

        return array(
            'content' => $content,
            'placeholders' => $placeholders
        );
    }

    /**
     * Restore shortcodes in content
     */
    public function restore_shortcodes($content, $placeholders) {
        foreach ($placeholders as $placeholder => $shortcode) {
            $content = str_replace($placeholder, $shortcode, $content);
        }
        return $content;
    }

    /**
     * Check if text is a shortcode
     */
    private function is_shortcode($text) {
        return preg_match('/^\[[^\]]+\]$/', trim($text));
    }

    /**
     * Получение статистики производительности
     */
    public function get_performance_stats() {
        return array(
            'dom_extractor_available' => !empty($this->dom_extractor),
            'output_buffer_available' => !empty($this->output_buffer),
            'compatibility_mode' => $this->compatibility_mode,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        );
    }

    /**
     * Включение/выключение режима совместимости
     */
    public function set_compatibility_mode($enabled) {
        $this->compatibility_mode = $enabled;
        update_option('lingua_compatibility_mode', $enabled);
    }

    /**
     * Восстановление контента с применением переводов
     * Реализация для полноценной архитектуры v2.0
     */
    public function reconstruct_content($original_content, $translated_blocks) {
        if (empty($translated_blocks) || !is_array($translated_blocks)) {
            return $original_content;
        }

        $reconstructed_content = $original_content;

        // Применяем переводы блоков контента
        if (isset($translated_blocks['content_blocks'])) {
            foreach ($translated_blocks['content_blocks'] as $block) {
                if (!empty($block['original']) && !empty($block['translated'])) {
                    $reconstructed_content = str_replace(
                        $block['original'],
                        $block['translated'],
                        $reconstructed_content
                    );
                }
            }
        }

        // Применяем переводы элементов страницы
        if (isset($translated_blocks['page_strings'])) {
            foreach ($translated_blocks['page_strings'] as $string) {
                if (!empty($string['original']) && !empty($string['translated'])) {
                    $reconstructed_content = str_replace(
                        $string['original'],
                        $string['translated'],
                        $reconstructed_content
                    );
                }
            }
        }

        // Применяем переводы атрибутов
        if (isset($translated_blocks['attributes'])) {
            foreach ($translated_blocks['attributes'] as $attr) {
                if (!empty($attr['original']) && !empty($attr['translated'])) {
                    $reconstructed_content = str_replace(
                        $attr['original'],
                        $attr['translated'],
                        $reconstructed_content
                    );
                }
            }
        }

        return $reconstructed_content;
    }
}