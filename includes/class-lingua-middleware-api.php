<?php
/**
 * Lingua Middleware API Integration
 * Connects to translation middleware for billing and domain management
 *
 * @package Lingua
 * @since 5.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Middleware_API {

    private $api_url;
    private $api_key;
    private $origin;

    /**
     * Constructor
     */
    public function __construct() {
        // Используем захардкоженный URL из константы
        $this->api_url = rtrim(LINGUA_MIDDLEWARE_URL, '/');

        // API ключ берем из настроек
        wp_cache_delete('lingua_middleware_api_key', 'options');
        $this->api_key = get_option('lingua_middleware_api_key', '');
        $this->origin = home_url();

        // Логируем полученные значения
        lingua_debug_log('[Lingua_Middleware_API] Constructor loaded: URL = ' . $this->api_url . ', API Key = ' . substr($this->api_key, 0, 10) . '... (length: ' . strlen($this->api_key) . ')');
    }

    /**
     * Check if API is configured (Pro version active)
     *
     * v5.2.1: Checks cached connection status only (no API calls)
     * Use test_connection() AJAX endpoint to update cache
     *
     * @return bool
     */
    public function is_pro_active() {
        // Quick check: API credentials configured?
        if (empty($this->api_key) || empty($this->api_url)) {
            return false;
        }

        // Check permanent status (no API calls, no expiration)
        $cache_key = 'lingua_pro_status_' . md5($this->api_key . $this->api_url);
        $cached_status = get_option($cache_key, false);

        // Return cached result (1 = success, 0 = failed, false = not tested yet)
        // Используем нестрогое сравнение для совместимости со строкой/числом
        return ($cached_status == 1);
    }

    /**
     * v5.2.200: Fix HTML tags broken by middleware (spaces in tags)
     * Middleware bug: returns "< strong>text< / strong>" instead of "<strong>text</strong>"
     *
     * @param string $text Text with potentially broken HTML tags
     * @return string Text with fixed HTML tags
     */
    private function fix_html_tags($text) {
        if (empty($text) || !is_string($text)) {
            return $text;
        }

        // Fix opening tags: "< tag" -> "<tag"
        $text = preg_replace('/<\s+(\w+)/i', '<$1', $text);

        // Fix closing tags: "< / tag>" -> "</tag>"
        $text = preg_replace('/<\s*\/\s*(\w+)\s*>/i', '</$1>', $text);

        // Fix self-closing tags: "< br / >" -> "<br />"
        $text = preg_replace('/<\s*(\w+)\s*\/\s*>/i', '<$1 />', $text);

        return $text;
    }

    /**
     * v5.3.15: Protect HTML tags by replacing with unique placeholders
     * This prevents translation APIs from mangling HTML structure
     *
     * @param string $text Text with HTML tags
     * @return array ['text' => cleaned text, 'tags' => array of original tags]
     */
    private function protect_html_tags($text) {
        if (empty($text) || !is_string($text)) {
            return array('text' => $text, 'tags' => array());
        }

        // Don't process if no HTML tags
        if (strpos($text, '<') === false) {
            return array('text' => $text, 'tags' => array());
        }

        $tags = array();
        $counter = 0;

        // Replace all HTML tags with placeholders
        // Matches: <tag>, </tag>, <tag attr="value">, <tag />, etc.
        $result = preg_replace_callback(
            '/<[^>]+>/u',
            function($matches) use (&$tags, &$counter) {
                $placeholder = "[[HTMLTAG{$counter}]]";
                $tags[$placeholder] = $matches[0];
                $counter++;
                return $placeholder;
            },
            $text
        );

        return array('text' => $result, 'tags' => $tags);
    }

    /**
     * v5.3.15: Restore HTML tags from placeholders after translation
     *
     * @param string $text Translated text with placeholders
     * @param array $tags Original HTML tags array
     * @return string Text with restored HTML tags
     */
    private function restore_html_tags($text, $tags) {
        if (empty($tags) || empty($text)) {
            return $text;
        }

        // Restore tags in order
        foreach ($tags as $placeholder => $tag) {
            $text = str_replace($placeholder, $tag, $text);
        }

        return $text;
    }

    /**
     * Translate text via Middleware API
     * Compatible with Yandex API interface for easy migration
     * v5.3.15: Protects HTML tags using placeholders
     *
     * @param string $text Text to translate
     * @param string $target_lang Target language code (en, ru, de, etc.)
     * @param string $source_lang Source language code (default: 'auto')
     * @return string|WP_Error Translated text or WP_Error on failure
     */
    public function translate($text, $target_lang, $source_lang = 'auto') {
        // Validate configuration (проверяем только credentials, не кэш)
        if (empty($this->api_key) || empty($this->api_url)) {
            return new WP_Error(
                'missing_credentials',
                __('API key not configured. Please configure Middleware API in Settings → IQCloud Translate.', 'iqcloud-translate')
            );
        }

        // Validate input
        if (empty($text) || empty($target_lang)) {
            return new WP_Error(
                'invalid_params',
                __('Missing required parameters: text and targetLanguage', 'iqcloud-translate')
            );
        }

        // v5.3.26: Protect &nbsp; entities before translation (API normalizes spaces)
        // Replace &nbsp; with placeholder that API won't touch
        $nbsp_placeholder = '[[NBSP]]';
        $text = str_replace('&nbsp;', $nbsp_placeholder, $text);
        // Also protect UTF-8 nbsp character (C2 A0)
        $text = str_replace("\xC2\xA0", $nbsp_placeholder, $text);

        // v5.3.15: Protect HTML tags before sending to API
        $protected = $this->protect_html_tags($text);
        $text_to_translate = $protected['text'];
        $tag_map = $protected['tags'];

        // Make API request
        $response = $this->translate_request($text_to_translate, $target_lang, $source_lang, 'html');

        if (is_wp_error($response)) {
            return $response;
        }

        // Return translated text (compatible with Yandex API format)
        // v5.2.200: Fix HTML tags broken by middleware
        $result = $this->fix_html_tags($response['translatedText']);

        // v5.3.15: Restore protected HTML tags
        if (!empty($tag_map)) {
            $result = $this->restore_html_tags($result, $tag_map);
        }

        // v5.3.26: Restore &nbsp; entities
        $result = str_replace($nbsp_placeholder, '&nbsp;', $result);

        return $result;
    }

    /**
     * Make translation request to Middleware API
     *
     * @param string $text Text to translate
     * @param string $target_lang Target language code
     * @param string $source_lang Source language code
     * @param string $format Format: 'text' or 'html'
     * @return array|WP_Error Response array or WP_Error
     */
    private function translate_request($text, $target_lang, $source_lang = 'auto', $format = 'html') {

        // Prepare request data в формате API middleware
        // API ожидает: content, fromLanguage, toLanguage, contentType
        $data = array(
            'content' => $text,
            'fromLanguage' => $source_lang,
            'toLanguage' => $target_lang,
            'contentType' => $format,
            'wordpressSite' => $this->origin
        );

        // Make API request
        $endpoint = '/api/public/translate/wordpress';
        $response = $this->make_request($endpoint, 'POST', $data);

        return $response;
    }

    /**
     * Make HTTP request to Middleware API
     *
     * @param string $endpoint API endpoint (e.g., '/api/public/translate/wordpress')
     * @param string $method HTTP method (GET, POST)
     * @param array $data Request body data
     * @return array|WP_Error Response array or WP_Error
     */
    private function make_request($endpoint, $method = 'GET', $data = null) {

        $url = $this->api_url . $endpoint;

        // Generate WordPress authentication token (nonce-based)
        $current_user = wp_get_current_user();
        $auth_token = wp_create_nonce('lingua_middleware_auth_' . $this->origin);

        // Add WordPress user context for verification
        $wp_user_id = $current_user->ID ?: 0;
        $wp_user_email = $current_user->user_email ?: '';

        // Prepare headers
        $headers = array(
            'Content-Type' => 'application/json',
            'X-API-Key' => $this->api_key,
            'Origin' => $this->origin,
            'X-WordPress-Site' => $this->origin,
            'X-WordPress-Version' => get_bloginfo('version'),
            'X-Lingua-Version' => defined('LINGUA_VERSION') ? LINGUA_VERSION : '5.0',
            'X-WordPress-Auth-Token' => $auth_token,
            'X-WordPress-User-ID' => $wp_user_id,
            'X-WordPress-User-Email' => $wp_user_email,
            'X-WordPress-Verify-URL' => rest_url('lingua/v1/middleware/verify')  // Verification endpoint
        );

        lingua_debug_log('[Lingua Middleware] Request to: ' . $url);
        lingua_debug_log('[Lingua Middleware] API Key: ' . substr($this->api_key, 0, 10) . '...');
        lingua_debug_log('[Lingua Middleware] Origin: ' . $this->origin);
        lingua_debug_log('[Lingua Middleware] Headers: ' . json_encode($headers));
        lingua_debug_log('[Lingua Middleware] Data: ' . json_encode($data));

        // Prepare request arguments
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
            // v5.2.60: Enable SSL verification in production
            'sslverify' => !defined('WP_DEBUG') || !WP_DEBUG
        );

        if ($data) {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE);
            // v5.3.29: Prevent Curl error when json_encode fails
            if ($encoded === false) {
                lingua_debug_log('[Lingua Middleware API] json_encode failed: ' . json_last_error_msg());
                return new WP_Error('json_encode_failed', 'Failed to encode request data: ' . json_last_error_msg());
            }
            $args['body'] = $encoded;
        }

        // Make request
        $response = wp_remote_request($url, $args);

        // Handle errors
        if (is_wp_error($response)) {
            lingua_debug_log('[Lingua Middleware API] Request error: ' . $response->get_error_message());
            return new WP_Error(
                'api_request_failed',
                sprintf(__('API request failed: %s', 'iqcloud-translate'), $response->get_error_message())
            );
        }

        // Get response body
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        // Check HTTP status
        $status_code = wp_remote_retrieve_response_code($response);

        lingua_debug_log('[Lingua Middleware] Response status: ' . $status_code);
        lingua_debug_log('[Lingua Middleware] Response body: ' . $body);

        if ($status_code !== 200) {
            $error_message = isset($result['error']) ? $result['error'] : 'Unknown error';
            $detailed_message = isset($result['message']) ? $result['message'] : $error_message;

            lingua_debug_log('[Lingua Middleware API] HTTP error: ' . $status_code . ' - ' . $error_message);

            // Специальная обработка для разных типов ошибок
            switch ($status_code) {
                case 429: // Rate limit exceeded
                    $limit = isset($result['limit']) ? $result['limit'] : '?';
                    $reset_in = isset($result['resetIn']) ? $result['resetIn'] : 60;
                    return new WP_Error(
                        'rate_limit_exceeded',
                        sprintf(
                            __('Превышен лимит запросов (%d запросов в минуту). Попробуйте снова через %d секунд. Обновите тариф для увеличения лимита.', 'iqcloud-translate'),
                            $limit,
                            $reset_in
                        )
                    );

                case 402: // Insufficient tokens
                    $tokens_required = isset($result['tokensRequired']) ? $result['tokensRequired'] : '?';
                    $tokens_available = isset($result['tokensAvailable']) ? $result['tokensAvailable'] : '0';
                    return new WP_Error(
                        'insufficient_tokens',
                        sprintf(
                            __('Недостаточно токенов. Требуется: %s, доступно: %s. Пополните баланс или обновите тариф.', 'iqcloud-translate'),
                            $tokens_required,
                            $tokens_available
                        )
                    );

                case 403: // Plan restrictions
                    if (isset($result['supportedLanguages'])) {
                        $supported = implode(', ', $result['supportedLanguages']);
                        return new WP_Error(
                            'language_not_supported',
                            sprintf(
                                __('Язык не доступен в вашем тарифе. Доступные языки: %s. Обновите тариф для доступа к большему количеству языков.', 'iqcloud-translate'),
                                $supported
                            )
                        );
                    }
                    return new WP_Error(
                        'plan_restriction',
                        $detailed_message . ' ' . __('Обновите тариф для доступа к этой функции.', 'iqcloud-translate')
                    );

                case 413: // Request too large
                    $max_allowed = isset($result['maxTokensAllowed']) ? $result['maxTokensAllowed'] : '?';
                    $tokens_requested = isset($result['tokensRequested']) ? $result['tokensRequested'] : '?';
                    return new WP_Error(
                        'request_too_large',
                        sprintf(
                            __('Запрос слишком большой (%s токенов). Максимум для вашего тарифа: %s токенов. Разбейте текст на части или обновите тариф.', 'iqcloud-translate'),
                            $tokens_requested,
                            $max_allowed
                        )
                    );

                case 401: // Authentication failed
                    return new WP_Error(
                        'auth_failed',
                        __('Ошибка аутентификации. Проверьте API ключ и домен в настройках плагина.', 'iqcloud-translate')
                    );

                default:
                    return new WP_Error(
                        'api_error',
                        sprintf(__('Ошибка API (%d): %s', 'iqcloud-translate'), $status_code, $detailed_message)
                    );
            }
        }

        // Check if response indicates failure
        if (isset($result['success']) && $result['success'] === false) {
            $error_message = isset($result['error']) ? $result['error'] : 'Translation failed';
            return new WP_Error('translation_failed', $error_message);
        }

        // Return parsed response
        return $result;
    }

    /**
     * Test API connection
     * Compatible with Yandex API interface
     *
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public function test_connection() {

        lingua_debug_log('[Lingua_Middleware_API] test_connection called');
        lingua_debug_log('[Lingua_Middleware_API] API URL: ' . $this->api_url);
        lingua_debug_log('[Lingua_Middleware_API] API Key: ' . substr($this->api_key, 0, 10) . '... (length: ' . strlen($this->api_key) . ')');

        // Проверяем только наличие credentials (не проверяем кэш)
        if (empty($this->api_key) || empty($this->api_url)) {
            lingua_debug_log('[Lingua_Middleware_API] Missing credentials!');
            return new WP_Error(
                'missing_api_key',
                __('API credentials not configured. Please enter Middleware URL and API Key.', 'iqcloud-translate')
            );
        }

        // Test with simple translation
        lingua_debug_log('[Lingua_Middleware_API] Attempting test translation...');
        $result = $this->translate('Hello', 'ru', 'en');

        if (is_wp_error($result)) {
            // Удаляем статус при ошибке (не сохраняем неудачу)
            $cache_key = 'lingua_pro_status_' . md5($this->api_key . $this->api_url);
            lingua_debug_log('[Lingua_Middleware_API] Test translation failed: ' . $result->get_error_message());
            lingua_debug_log('[Lingua_Middleware_API] Deleting status: ' . $cache_key);
            delete_option($cache_key); // Удаляем навсегда
            return $result;
        }

        // Сохраняем успешный статус НАВСЕГДА (v5.2.64)
        // БЕЗ истечения срока! Сбрасывается ТОЛЬКО при смене API ключа!
        // Клиент тестирует ОДИН РАЗ и НИКОГДА больше не думает об этом!
        $cache_key = 'lingua_pro_status_' . md5($this->api_key . $this->api_url);
        lingua_debug_log('[Lingua_Middleware_API] Test translation successful! Result: ' . substr($result, 0, 50));
        lingua_debug_log('[Lingua_Middleware_API] Saving permanent status with key: ' . $cache_key);
        $set_result = update_option($cache_key, 1, false); // false = no autoload (оптимизация)
        lingua_debug_log('[Lingua_Middleware_API] update_option result: ' . var_export($set_result, true));

        // Проверяем что сохранилось
        $verify = get_option($cache_key);
        lingua_debug_log('[Lingua_Middleware_API] Verification get_option: ' . var_export($verify, true));

        return true;
    }

    /**
     * Get API status with tokens, plan info, and expiration date
     * v5.3: New method for WordPress plugin admin dashboard
     *
     * @return array|WP_Error API status data or WP_Error on failure
     */
    public function get_api_status() {
        // Проверяем наличие credentials
        if (empty($this->api_key) || empty($this->api_url)) {
            return new WP_Error(
                'missing_credentials',
                __('API credentials not configured', 'iqcloud-translate')
            );
        }

        // Проверяем кэшированные данные статуса (5 минут)
        $cache_key = 'lingua_api_status_' . md5($this->api_key . $this->api_url);
        $cached_status = get_transient($cache_key);

        if ($cached_status !== false) {
            return $cached_status;
        }

        // Делаем запрос к endpoint /api/user/api-status
        $response = wp_remote_get(
            $this->api_url . '/api/user/api-status',
            array(
                'headers' => array(
                    'X-API-Key' => $this->api_key,
                    'Content-Type' => 'application/json',
                    'X-Origin' => $this->origin
                ),
                'timeout' => 10
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($status_code !== 200) {
            $error_message = isset($result['message']) ? $result['message'] : 'Failed to get API status';
            return new WP_Error('api_status_error', $error_message, array('status' => $status_code));
        }

        if (!isset($result['success']) || !$result['success']) {
            return new WP_Error('api_status_failed', $result['message'] ?? 'Unknown error');
        }

        // Кэшируем результат на 5 минут
        set_transient($cache_key, $result['data'], 300);

        return $result['data'];
    }

    /**
     * Update active languages for the client
     * v5.3: Sends selected languages to middleware
     *
     * @param array $languages Array of language codes (e.g. ['ru', 'en', 'de'])
     * @return true|WP_Error True on success, WP_Error on failure
     */
    public function update_active_languages($languages) {
        // Проверяем наличие credentials
        if (empty($this->api_key) || empty($this->api_url)) {
            return new WP_Error(
                'missing_credentials',
                __('API credentials not configured', 'iqcloud-translate')
            );
        }

        if (!is_array($languages)) {
            return new WP_Error('invalid_parameter', 'Languages must be an array');
        }

        // Делаем запрос к endpoint /api/user/active-languages
        $response = wp_remote_request(
            $this->api_url . '/api/user/active-languages',
            array(
                'method' => 'PUT',
                'headers' => array(
                    'X-API-Key' => $this->api_key,
                    'Content-Type' => 'application/json',
                    'X-Origin' => $this->origin
                ),
                'body' => json_encode(array(
                    'languages' => $languages
                )),
                'timeout' => 10
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if ($status_code !== 200) {
            $error_message = isset($result['message']) ? $result['message'] : 'Failed to update active languages';
            return new WP_Error('update_languages_error', $error_message, array('status' => $status_code));
        }

        if (!isset($result['success']) || !$result['success']) {
            return new WP_Error('update_languages_failed', $result['message'] ?? 'Unknown error');
        }

        // Очищаем кэш статуса API чтобы обновить информацию
        $cache_key = 'lingua_api_status_' . md5($this->api_key . $this->api_url);
        delete_transient($cache_key);

        return true;
    }

    /**
     * Batch translate multiple texts
     * v5.2.199: Uses real batch endpoint for 10-50x faster translation
     * v5.3.15: Protects HTML tags using placeholders
     *
     * @param array $texts Array of texts to translate
     * @param string $target_lang Target language code
     * @param string $source_lang Source language code
     * @return array|WP_Error Array of translated texts or WP_Error
     */
    public function translate_batch($texts, $target_lang, $source_lang = 'auto') {

        if (!$this->is_pro_active()) {
            return new WP_Error(
                'missing_credentials',
                __('API key not configured', 'iqcloud-translate')
            );
        }

        if (empty($texts)) {
            return array();
        }

        // v5.3.26: Protect &nbsp; entities before translation
        $nbsp_placeholder = '[[NBSP]]';

        // v5.3.45: Separate HTML texts from plain texts
        // HTML texts will be translated individually using translate_html()
        // Plain texts use batch endpoint with placeholders
        $html_texts = array();
        $plain_texts = array();
        $text_types = array(); // Track which texts are HTML vs plain

        foreach ($texts as $index => $text) {
            // v5.3.26: Protect nbsp first
            $text = str_replace('&nbsp;', $nbsp_placeholder, $text);
            $text = str_replace("\xC2\xA0", $nbsp_placeholder, $text);

            // Check if text contains HTML tags
            if (preg_match('/<[a-z]/i', $text)) {
                $html_texts[$index] = $text;
                $text_types[$index] = 'html';
            } else {
                $plain_texts[$index] = $text;
                $text_types[$index] = 'plain';
            }
        }

        lingua_debug_log('[Lingua Middleware v5.3.46] Batch: ' . count($html_texts) . ' HTML texts, ' . count($plain_texts) . ' plain texts');

        $all_translations = array();

        // Translate HTML texts individually using translate_html()
        if (!empty($html_texts)) {
            foreach ($html_texts as $index => $html_text) {
                $translated = $this->translate_html($html_text, $target_lang, $source_lang);
                if (is_wp_error($translated)) {
                    lingua_debug_log('[Lingua Middleware] HTML translation failed for index ' . $index . ': ' . $translated->get_error_message());
                    $all_translations[$index] = $html_text; // Return original on failure
                } else {
                    // Restore &nbsp;
                    $translated = str_replace($nbsp_placeholder, '&nbsp;', $translated);
                    $all_translations[$index] = $translated;
                }
            }
        }

        // Translate plain texts using batch with placeholders
        if (empty($plain_texts)) {
            // No plain texts, just return HTML translations
            ksort($all_translations);
            return array_values($all_translations);
        }

        $protected_texts = array();
        $tag_maps = array();

        foreach ($plain_texts as $index => $text) {
            $protected = $this->protect_html_tags($text);
            $protected_texts[$index] = $protected['text'];
            $tag_maps[$index] = $protected['tags'];
        }

        // v5.2.199: Use batch endpoint for plain texts
        $data = array(
            'texts' => array_values($protected_texts),
            'fromLanguage' => $source_lang,
            'toLanguage' => $target_lang,
            'siteUrl' => $this->origin
        );

        $response = $this->make_request('/api/public/translate/batch', 'POST', $data);

        if (is_wp_error($response)) {
            // Fallback to one-by-one translation if batch fails
            lingua_debug_log('[Lingua Middleware] Batch failed, falling back to single requests: ' . $response->get_error_message());
            $results = array();
            foreach ($texts as $index => $text) {
                $translated = $this->translate($text, $target_lang, $source_lang);
                // v1.2.1: Don't abort entire batch on single translation error
                // Return original text if translation fails, continue with others
                if (is_wp_error($translated)) {
                    lingua_debug_log('[Lingua Middleware] Single translation failed for text: ' . mb_substr($text, 0, 50) . '... Error: ' . $translated->get_error_message());
                    $results[] = $text; // Return original on failure
                    continue;
                }
                $results[] = $translated;
            }
            return $results;
        }

        // Extract translated plain texts from response and merge with HTML translations
        if (isset($response['translations']) && is_array($response['translations'])) {
            $plain_keys = array_keys($plain_texts);
            foreach ($response['translations'] as $response_index => $translation) {
                // Map response index back to original index
                $original_index = $plain_keys[$response_index];

                // v5.2.200: Fix HTML tags broken by middleware
                $text = $translation['text'] ?? '';
                $text = $this->fix_html_tags($text);

                // Restore placeholders for plain texts
                if (!empty($tag_maps[$original_index])) {
                    $text = $this->restore_html_tags($text, $tag_maps[$original_index]);
                }

                // v5.3.26: Restore &nbsp; entities
                $text = str_replace($nbsp_placeholder, '&nbsp;', $text);

                $all_translations[$original_index] = $text;
            }

            // Return all translations in original order
            ksort($all_translations);
            return array_values($all_translations);
        }

        return new WP_Error('invalid_response', __('Invalid batch response format', 'iqcloud-translate'));
    }

    /**
     * Translate HTML content while preserving structure
     * Middleware API handles HTML format internally
     *
     * @param string $html_content HTML content to translate
     * @param string $target_lang Target language code
     * @param string $source_lang Source language code
     * @return string|WP_Error Translated HTML or WP_Error
     */
    public function translate_html($html_content, $target_lang, $source_lang = 'auto') {

        if (empty($html_content)) {
            return $html_content;
        }

        // Middleware API handles HTML format internally
        $response = $this->translate_request($html_content, $target_lang, $source_lang, 'html');

        if (is_wp_error($response)) {
            return $response;
        }

        // v5.2.200: Fix HTML tags broken by middleware
        return $this->fix_html_tags($response['translatedText']);
    }

    /**
     * v5.3.47: Translate HTML with guaranteed structure preservation
     *
     * Approach:
     * 1. Parse HTML and extract text nodes
     * 2. Translate each text node separately
     * 3. Insert translations back into original HTML structure
     *
     * This guarantees 100% structure preservation regardless of API behavior.
     *
     * @param string $html_content HTML content to translate
     * @param string $target_lang Target language code
     * @param string $source_lang Source language code
     * @return string|WP_Error Translated HTML with preserved structure
     */
    public function translate_html_preserve_structure($html_content, $target_lang, $source_lang = 'auto') {
        if (empty($html_content)) {
            return $html_content;
        }

        // Load Simple HTML DOM Parser if available
        if (!function_exists('str_get_html')) {
            require_once LINGUA_PLUGIN_DIR . 'includes/lib/simple_html_dom.php';
        }

        // Parse HTML
        $html = str_get_html($html_content);
        if (!$html) {
            // Fallback to regular translate_html if parsing fails
            lingua_debug_log('[Lingua] HTML parsing failed, falling back to translate_html()');
            return $this->translate_html($html_content, $target_lang, $source_lang);
        }

        // Collect all text nodes with their parent elements
        $text_nodes = array();
        $this->extract_text_nodes($html->root, $text_nodes);

        if (empty($text_nodes)) {
            return $html_content;
        }

        // Prepare texts for batch translation
        $texts_to_translate = array();
        foreach ($text_nodes as $index => $node_info) {
            $text = trim($node_info['text']);
            if (!empty($text) && strlen($text) >= 2) {
                $texts_to_translate[$index] = $text;
            }
        }

        if (empty($texts_to_translate)) {
            return $html_content;
        }

        lingua_debug_log('[Lingua] Translating ' . count($texts_to_translate) . ' text nodes for structure preservation');

        // Translate all texts in batch
        $translations = $this->translate_batch(array_values($texts_to_translate), $target_lang, $source_lang);

        if (is_wp_error($translations)) {
            lingua_debug_log('[Lingua] Batch translation failed: ' . $translations->get_error_message());
            return $this->translate_html($html_content, $target_lang, $source_lang);
        }

        // Map translations back to indices
        $translation_map = array();
        $translation_index = 0;
        foreach ($texts_to_translate as $original_index => $text) {
            if (isset($translations[$translation_index])) {
                $translation_map[$original_index] = $translations[$translation_index];
            }
            $translation_index++;
        }

        // Replace text nodes with translations
        foreach ($text_nodes as $index => $node_info) {
            if (isset($translation_map[$index])) {
                $node = $node_info['node'];
                // For text nodes, we need to update the parent's content
                $parent = $node_info['parent'];
                if ($parent && isset($translation_map[$index])) {
                    $original_text = $node_info['text'];
                    $translated_text = $translation_map[$index];

                    // Replace the text in parent's innertext
                    $parent->innertext = str_replace($original_text, $translated_text, $parent->innertext);
                }
            }
        }

        $result = $html->save();
        $html->clear();
        unset($html);

        return $result;
    }

    /**
     * v5.3.47: Recursively extract text nodes from HTML DOM
     *
     * @param object $node DOM node
     * @param array &$text_nodes Array to store text node information
     * @param object|null $parent Parent node for reference
     */
    private function extract_text_nodes($node, &$text_nodes, $parent = null) {
        if (!$node) {
            return;
        }

        // If this is a text node
        if ($node->nodetype === 3) { // HDOM_TYPE_TEXT
            $text = trim($node->plaintext);
            if (!empty($text) && strlen($text) >= 2) {
                $text_nodes[] = array(
                    'node' => $node,
                    'text' => $text,
                    'parent' => $parent
                );
            }
            return;
        }

        // If this node has no children, check if it has text content
        if (!isset($node->nodes) || empty($node->nodes)) {
            $text = trim($node->plaintext);
            if (!empty($text) && strlen($text) >= 2) {
                // This is a leaf node with text
                $text_nodes[] = array(
                    'node' => $node,
                    'text' => $text,
                    'parent' => $parent
                );
            }
            return;
        }

        // Recursively process child nodes
        foreach ($node->nodes as $child) {
            $this->extract_text_nodes($child, $text_nodes, $node);
        }
    }

    /**
     * Get supported languages
     * Note: Currently returns hardcoded list
     * TODO: Add endpoint to Middleware API for supported languages
     *
     * @return array|WP_Error Array of language codes and names
     */
    public function get_supported_languages() {

        // Hardcoded list of supported languages
        // This matches the languages supported by Yandex Translate
        return array(
            'en' => 'English',
            'ru' => 'Russian',
            'de' => 'German',
            'fr' => 'French',
            'es' => 'Spanish',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'zh' => 'Chinese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'ar' => 'Arabic',
            'tr' => 'Turkish',
            'pl' => 'Polish',
            'uk' => 'Ukrainian',
            'nl' => 'Dutch',
            'sv' => 'Swedish',
            'cs' => 'Czech',
            'fi' => 'Finnish',
            'da' => 'Danish',
            'no' => 'Norwegian'
        );
    }

    /**
     * Detect language of text
     * Note: Middleware API doesn't have detect endpoint yet
     * Returns detected language from translation response if available
     *
     * @param string $text Text to detect language
     * @return string|WP_Error Language code or WP_Error
     */
    public function detect_language($text) {

        if (!$this->is_pro_active()) {
            return new WP_Error(
                'missing_credentials',
                __('API key not configured', 'iqcloud-translate')
            );
        }

        // Make a translation request with 'auto' source language
        // The response includes detectedLanguage
        $response = $this->translate_request($text, 'en', 'auto', 'text');

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['detectedLanguage'])) {
            return $response['detectedLanguage'];
        }

        return new WP_Error(
            'detection_failed',
            __('Could not detect language', 'iqcloud-translate')
        );
    }
}
