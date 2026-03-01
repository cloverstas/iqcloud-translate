<?php
/**
 * Translation Manager
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Translation_Manager {

    private $table_name;
    private $meta_table_name;
    private $string_table_name;
    private $legacy_table_exists = null; // v5.3.13: Cache for legacy table check

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'lingua_translations';
        $this->meta_table_name = $wpdb->prefix . 'lingua_translation_meta';
        $this->string_table_name = $wpdb->prefix . 'lingua_string_translations'; // UNIFIED TABLE
    }

    /**
     * v5.3.13: Check if legacy table exists (with caching)
     * @return bool
     */
    private function legacy_table_exists() {
        if ($this->legacy_table_exists === null) {
            global $wpdb;
            $this->legacy_table_exists = ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name);
        }
        return $this->legacy_table_exists;
    }
    
    /**
     * Get translation for a post - UNIFIED PIPELINE VERSION
     * Returns translations from unified string table with proper grouping
     */
    public function get_translation($post_id, $language) {
        global $wpdb;

        // PHASE 2.1: DEBUG - Log incoming load request
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA LOAD] === LOAD REQUEST START ===");
            lingua_debug_log("[LINGUA LOAD] post_id={$post_id}, target_lang={$language}");

            // Log current global language state
            global $LINGUA_LANGUAGE;
            lingua_debug_log("[LINGUA LOAD] global_LINGUA_LANGUAGE=" . ($LINGUA_LANGUAGE ?? 'undefined'));
        }

        try {
            // PHASE 2: Get from unified string translations table
            $unified_data = $this->get_unified_translation_data($post_id, $language);

            // PHASE 2.1: DEBUG - Log unified data retrieval results
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA LOAD] unified_data_found=" . (!empty($unified_data) ? 'YES' : 'NO'));
                if (!empty($unified_data)) {
                    lingua_debug_log("[LINGUA LOAD] unified_data_groups=" . wp_json_encode(array_keys($unified_data)));
                    lingua_debug_log("[LINGUA LOAD] unified_data_content=" . wp_json_encode($unified_data));
                }
            }

            if (!empty($unified_data)) {
                // Build translation object in expected format
                $translation = new stdClass();
                $translation->original_id = $post_id;
                $translation->language_code = $language;
                $translation->translation_status = 'published';

                // Extract core fields
                $translation->translated_title = $this->get_unified_translation('core_fields.title', $post_id, '', $language);
                $translation->translated_excerpt = $this->get_unified_translation('core_fields.excerpt', $post_id, '', $language);

                // CRITICAL FIX v2.0.4: Also check postmeta for core fields if not in unified
                if (empty($translation->translated_title)) {
                    $translation->translated_title = get_post_meta($post_id, "lingua_{$language}_title", true);
                }
                if (empty($translation->translated_excerpt)) {
                    $translation->translated_excerpt = get_post_meta($post_id, "lingua_{$language}_excerpt", true);
                }

                // PHASE 2.1: DEBUG - Log core field retrieval
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log("[LINGUA LOAD] core_fields_title=" . ($translation->translated_title ?? 'NULL'));
                    lingua_debug_log("[LINGUA LOAD] core_fields_excerpt=" . ($translation->translated_excerpt ?? 'NULL'));
                }

                // Build structured content from unified data
                $translation->translated_content = wp_json_encode($unified_data);

                // Get meta data from unified structure
                $translation->meta = $this->extract_meta_from_unified($unified_data);

                // v2 unified: page_strings now loaded from unified table only

                // PHASE 2.1: DEBUG - Log successful unified load
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log("[LINGUA LOAD] unified_load=SUCCESS, content_size=" . strlen($translation->translated_content));
                    lingua_debug_log("[LINGUA LOAD] meta_keys=" . wp_json_encode(array_keys($translation->meta ?? array())));
                    lingua_debug_log("[LINGUA LOAD] === LOAD REQUEST SUCCESS (UNIFIED) ===");
                }

                return $translation;
            }

            // PHASE 2.1: DEBUG - Log fallback to legacy
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA LOAD] unified_not_found, trying_postmeta_and_legacy_format");
            }

            // CRITICAL FIX v2.0.4: Try postmeta first before legacy table
            $postmeta_data = $this->get_postmeta_translations($post_id, $language);

            if (!empty($postmeta_data)) {
                // Build translation object from postmeta
                $translation = new stdClass();
                $translation->original_id = $post_id;
                $translation->language_code = $language;
                $translation->translation_status = 'published';
                $translation->translated_title = $postmeta_data['title'] ?? '';
                $translation->translated_excerpt = $postmeta_data['excerpt'] ?? '';
                $translation->translated_content = ''; // postmeta doesn't store structured content
                $translation->meta = $postmeta_data;

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log("[LINGUA LOAD v2.0.4] Found postmeta translations: " . wp_json_encode(array_keys($postmeta_data)));
                    lingua_debug_log("[LINGUA LOAD] === LOAD REQUEST SUCCESS (POSTMETA) ===");
                }

                return $translation;
            }

            // FALLBACK: Try legacy format (v5.3.13: only if table exists)
            $translation = null;
            if ($this->legacy_table_exists()) {
                $translation = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE original_id = %d AND language_code = %s",
                    $post_id,
                    $language
                ));

                // PHASE 2.1: DEBUG - Log legacy query results
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log("[LINGUA LOAD] legacy_query_result=" . ($translation ? 'FOUND' : 'NOT_FOUND'));
                    if ($translation) {
                        lingua_debug_log("[LINGUA LOAD] legacy_translation_id=" . $translation->id);
                        lingua_debug_log("[LINGUA LOAD] legacy_content_size=" . strlen($translation->translated_content ?? ''));
                    }
                }

                if ($translation) {
                    // Get meta data
                    $meta_data = $this->get_translation_meta($translation->id);
                    $translation->meta = $meta_data;

                    // PHASE 2.1: DEBUG - Log legacy load success
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        lingua_debug_log("[LINGUA LOAD] legacy_meta_keys=" . wp_json_encode(array_keys($meta_data ?? array())));
                        lingua_debug_log("[LINGUA LOAD] === LOAD REQUEST SUCCESS (LEGACY) ===");
                    }
                }
            }

            if (!$translation) {
                // PHASE 2.1: DEBUG - Log complete load failure
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log("[LINGUA LOAD] === LOAD REQUEST FAILED - NO DATA FOUND ===");
                }
            }

            return $translation;

        } catch (Exception $e) {
            // FALLBACK PROTECTION: Log error and try legacy
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA ERROR] get_translation failed: " . $e->getMessage());
                lingua_debug_log("[LINGUA ERROR] error_trace=" . $e->getTraceAsString());
            }

            // Try legacy format as fallback (v5.3.13: only if table exists)
            $translation = null;
            if ($this->legacy_table_exists()) {
                $translation = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE original_id = %d AND language_code = %s",
                    $post_id,
                    $language
                ));

                if ($translation) {
                    $meta_data = $this->get_translation_meta($translation->id);
                    $translation->meta = $meta_data;

                    // PHASE 2.1: DEBUG - Log emergency legacy success
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        lingua_debug_log("[LINGUA LOAD] === EMERGENCY LEGACY SUCCESS ===");
                    }
                }
            }

            if (!$translation) {
                // PHASE 2.1: DEBUG - Log complete failure
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log("[LINGUA LOAD] === COMPLETE LOAD FAILURE ===");
                }
            }

            return $translation;
        }
    }
    
    /**
     * Save or update translation - UNIFIED PIPELINE VERSION
     * Saves translations to wp_lingua_string_translations with proper context grouping
     */
    public function save_translation($post_id, $language, $data) {
        global $wpdb;

        // PHASE 2.1: DEBUG - Log incoming save request
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA SAVE] === SAVE REQUEST START ===");
            lingua_debug_log("[LINGUA SAVE] post_id={$post_id}, target_lang={$language}");
            lingua_debug_log("[LINGUA SAVE] data_structure=" . wp_json_encode(array_keys($data)));

            // Log current global language state
            global $LINGUA_LANGUAGE;
            lingua_debug_log("[LINGUA SAVE] global_LINGUA_LANGUAGE=" . ($LINGUA_LANGUAGE ?? 'undefined'));
            lingua_debug_log("[LINGUA SAVE] current_url=" . sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')));

            // PHASE 2.1: VALIDATION - Check target language correctness
            $available_languages = get_option('lingua_languages', array());
            if (empty($available_languages[$language])) {
                lingua_debug_log("[LINGUA SAVE WARNING] Target language '{$language}' not found in available languages: " . wp_json_encode(array_keys($available_languages)));
            } else {
                lingua_debug_log("[LINGUA SAVE] Target language '{$language}' validated successfully");
            }

            // Check if target language matches global language
            if (isset($LINGUA_LANGUAGE) && $LINGUA_LANGUAGE !== $language) {
                lingua_debug_log("[LINGUA SAVE WARNING] Mismatch: global_LINGUA_LANGUAGE='{$LINGUA_LANGUAGE}' vs target_lang='{$language}'");
            } else {
                lingua_debug_log("[LINGUA SAVE] Language consistency check passed");
            }

            // Check if default language is trying to be saved (should not happen)
            $default_language = get_option('lingua_default_language', lingua_get_site_language());
            if ($language === $default_language) {
                lingua_debug_log("[LINGUA SAVE WARNING] Attempting to save default language '{$language}' as translation!");
            }
        }

        try {
            // PHASE 2.1: DEBUG - Verify table exists and log sample records
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->string_table_name}'");
                lingua_debug_log("[LINGUA DB] table_exists=" . ($table_exists ? 'YES' : 'NO'));

                if ($table_exists) {
                    // Log table structure
                    $columns = $wpdb->get_results("DESCRIBE {$this->string_table_name}");
                    $column_names = array_map(function($col) { return $col->Field; }, $columns);
                    lingua_debug_log("[LINGUA DB] table_columns=" . wp_json_encode($column_names));

                    // Log total record count
                    $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$this->string_table_name}");
                    lingua_debug_log("[LINGUA DB] total_records_in_table=" . $total_records);

                    // Log records for this post if any exist
                    $post_records = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->string_table_name} WHERE context LIKE %s",
                        "%.post_{$post_id}"
                    ));
                    lingua_debug_log("[LINGUA DB] existing_records_for_post_{$post_id}=" . $post_records);

                    // Log sample of existing contexts for this post
                    if ($post_records > 0) {
                        $sample_contexts = $wpdb->get_col($wpdb->prepare(
                            "SELECT DISTINCT context FROM {$this->string_table_name}
                             WHERE context LIKE %s LIMIT 10",
                            "%.post_{$post_id}"
                        ));
                        lingua_debug_log("[LINGUA DB] sample_existing_contexts=" . wp_json_encode($sample_contexts));
                    }
                } else {
                    lingua_debug_log("[LINGUA DB ERROR] Table {$this->string_table_name} does not exist!");
                }
            }

            // PHASE 2: Save to unified string translations table
            $saved_count = 0;

            // Process core_fields group
            if (isset($data['core_fields'])) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log("[LINGUA SAVE] Processing core_fields group, count=" . count($data['core_fields']));
                }
                foreach ($data['core_fields'] as $field_key => $translation) {
                    if (!empty($translation['original']) && !empty($translation['translated'])) {
                        $context = "core_fields.{$field_key}.post_{$post_id}";

                        // PHASE 2.1: DEBUG - Log each save operation
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            lingua_debug_log("[LINGUA SAVE] post_id={$post_id}, lang={$language}, group=core_fields, field={$field_key}, context={$context}");
                            lingua_debug_log("[LINGUA SAVE] original=" . substr($translation['original'], 0, 50) . "...");
                            lingua_debug_log("[LINGUA SAVE] translated=" . substr($translation['translated'], 0, 50) . "...");
                        }

                        $result = $this->save_unified_string(
                            $translation['original'],
                            $translation['translated'],
                            $language,
                            $context
                        );

                        if ($result) {
                            $saved_count++;
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                lingua_debug_log("[LINGUA SAVE] SUCCESS: Saved core_fields.{$field_key}");
                            }
                        } else {
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                lingua_debug_log("[LINGUA SAVE] FAILED: Could not save core_fields.{$field_key}");
                            }
                        }
                    } else {
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            lingua_debug_log("[LINGUA SAVE] SKIPPED: core_fields.{$field_key} - missing original or translated text");
                        }
                    }
                }
            }

            // Process seo_fields group
            if (isset($data['seo_fields'])) {
                foreach ($data['seo_fields'] as $field_key => $translation) {
                    if (!empty($translation['original']) && !empty($translation['translated'])) {
                        $this->save_unified_string(
                            $translation['original'],
                            $translation['translated'],
                            $language,
                            "seo_fields.{$field_key}.post_{$post_id}"
                        );
                        $saved_count++;
                    }
                }
            }

            // Process meta_fields group (WooCommerce, ACF, etc.)
            if (isset($data['meta_fields'])) {
                foreach ($data['meta_fields'] as $field_key => $translation) {
                    if (!empty($translation['original']) && !empty($translation['translated'])) {
                        $this->save_unified_string(
                            $translation['original'],
                            $translation['translated'],
                            $language,
                            "meta_fields.{$field_key}.post_{$post_id}"
                        );
                        $saved_count++;
                    }
                }
            }

            // Process taxonomy_terms group
            if (isset($data['taxonomy_terms'])) {
                foreach ($data['taxonomy_terms'] as $field_key => $translation) {
                    if (!empty($translation['original']) && !empty($translation['translated'])) {
                        $this->save_unified_string(
                            $translation['original'],
                            $translation['translated'],
                            $language,
                            "taxonomy_terms.{$field_key}.post_{$post_id}"
                        );
                        $saved_count++;
                    }
                }
            }

            // Process content_blocks group (v5.2.42: pass source and gettext_domain)
            if (isset($data['content_blocks'])) {
                foreach ($data['content_blocks'] as $block_index => $translation) {
                    if (!empty($translation['original']) && !empty($translation['translated'])) {
                        $this->save_unified_string(
                            $translation['original'],
                            $translation['translated'],
                            $language,
                            "content_blocks.block_{$block_index}.post_{$post_id}",
                            $translation['source'] ?? 'custom',
                            $translation['gettext_domain'] ?? null
                        );
                        $saved_count++;
                    }
                }
            }

            // Process page_strings group (UI elements) (v5.2.42: pass source and gettext_domain)
            if (isset($data['page_strings'])) {
                foreach ($data['page_strings'] as $string_index => $translation) {
                    if (!empty($translation['original']) && !empty($translation['translated'])) {
                        $this->save_unified_string(
                            $translation['original'],
                            $translation['translated'],
                            $language,
                            "page_strings.ui_{$string_index}.post_{$post_id}",
                            $translation['source'] ?? 'custom',
                            $translation['gettext_domain'] ?? null
                        );
                        $saved_count++;
                    }
                }
            }

            // v2 unified save only - no legacy compatibility

            // Clear cache
            $this->clear_translation_cache($post_id, $language);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA UNIFIED] Saved {$saved_count} string translations for post {$post_id}, language {$language}");
            }

            return $saved_count;

        } catch (Exception $e) {
            // FALLBACK PROTECTION: Log error but don't break
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA ERROR] save_translation failed: " . $e->getMessage());
            }

            return false; // v2 only - no legacy fallback
        }
    }
    
    /**
     * Get translation meta
     */
    private function get_translation_meta($translation_id) {
        global $wpdb;
        
        $meta_data = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$this->meta_table_name} WHERE translation_id = %d",
            $translation_id
        ));
        
        $meta = array();
        foreach ($meta_data as $item) {
            $meta[$item->meta_key] = maybe_unserialize($item->meta_value);
        }
        
        return $meta;
    }
    
    /**
     * Save translation meta
     */
    private function save_translation_meta($translation_id, $meta_key, $meta_value) {
        global $wpdb;
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$this->meta_table_name} WHERE translation_id = %d AND meta_key = %s",
            $translation_id,
            $meta_key
        ));
        
        $data = array(
            'translation_id' => $translation_id,
            'meta_key' => $meta_key,
            'meta_value' => maybe_serialize($meta_value)
        );
        
        if ($existing) {
            $wpdb->update(
                $this->meta_table_name,
                array('meta_value' => maybe_serialize($meta_value)),
                array('meta_id' => $existing)
            );
        } else {
            $wpdb->insert($this->meta_table_name, $data);
        }
    }
    
    /**
     * Delete translation - UNIFIED PIPELINE VERSION
     * Deletes all string translations for a post from unified table
     */
    public function delete_translation($post_id, $language) {
        global $wpdb;

        try {
            // PHASE 2: Delete from unified string translations table
            $deleted_unified = $wpdb->delete(
                $this->string_table_name,
                array(
                    'language_code' => $language
                ),
                array('%s')
            );

            // Also delete entries that match context pattern for this post
            $deleted_context = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->string_table_name}
                 WHERE context LIKE %s AND language_code = %s",
                "%.post_{$post_id}",
                $language
            ));

            // FALLBACK: Also delete from legacy format for completeness
            // v5.3.13: Only if legacy table exists
            if ($this->legacy_table_exists()) {
                $translation = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE original_id = %d AND language_code = %s",
                    $post_id,
                    $language
                ));

                if ($translation) {
                    // Delete meta data
                    $wpdb->delete(
                        $this->meta_table_name,
                        array('translation_id' => $translation->id),
                        array('%d')
                    );

                    // Delete legacy translation
                    $wpdb->delete(
                        $this->table_name,
                        array('id' => $translation->id),
                        array('%d')
                    );
                }
            }

            // Clear cache
            $this->clear_translation_cache($post_id, $language);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $total_deleted = $deleted_unified + $deleted_context;
                lingua_debug_log("[LINGUA UNIFIED] Deleted {$total_deleted} string translations for post {$post_id}, language {$language}");
            }

            return true;

        } catch (Exception $e) {
            // FALLBACK PROTECTION: Log error but try legacy delete
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA ERROR] delete_translation failed: " . $e->getMessage());
            }

            // Try legacy delete as fallback (v5.3.13: only if table exists)
            if ($this->legacy_table_exists()) {
                $translation = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE original_id = %d AND language_code = %s",
                    $post_id,
                    $language
                ));

                if ($translation) {
                    $wpdb->delete($this->meta_table_name, array('translation_id' => $translation->id));
                    $wpdb->delete($this->table_name, array('id' => $translation->id));
                    $this->clear_translation_cache($post_id, $language);
                }
            }

            return false;
        }
    }
    
    /**
     * Get all translations for a post
     * v5.3.13: Check if legacy table exists before querying
     */
    public function get_post_translations($post_id) {
        global $wpdb;

        // v5.3.13: Check if legacy table exists (it may have been removed)
        if (!$this->legacy_table_exists()) {
            return array(); // Return empty array if legacy table doesn't exist
        }

        $translations = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE original_id = %d",
            $post_id
        ));

        foreach ($translations as $translation) {
            $translation->meta = $this->get_translation_meta($translation->id);
        }

        return $translations;
    }
    
    /**
     * Get posts with translations in a language
     * v5.3.13: Check if legacy table exists before querying
     */
    public function get_posts_with_translations($language, $post_type = 'any', $limit = -1) {
        global $wpdb;

        // v5.3.13: Check if legacy table exists
        if (!$this->legacy_table_exists()) {
            return array();
        }

        $query = "SELECT DISTINCT t.original_id, t.*, p.post_type, p.post_status
                  FROM {$this->table_name} t
                  JOIN {$wpdb->posts} p ON t.original_id = p.ID
                  WHERE t.language_code = %s";

        $params = array($language);

        if ($post_type !== 'any') {
            $query .= " AND p.post_type = %s";
            $params[] = $post_type;
        }

        $query .= " AND p.post_status = 'publish'";

        if ($limit > 0) {
            $query .= " LIMIT %d";
            $params[] = $limit;
        }

        return $wpdb->get_results($wpdb->prepare($query, $params));
    }
    
    /**
     * Get translation statistics
     * v5.3.13: Check if legacy table exists before querying
     */
    public function get_translation_stats() {
        global $wpdb;

        $stats = array(
            'total' => 0,
            'by_language' => array(),
            'by_status' => array(),
            'by_post_type' => array()
        );

        // v5.3.13: Check if legacy table exists
        if (!$this->legacy_table_exists()) {
            return $stats;
        }

        // Total translations
        $stats['total'] = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");

        // By language
        $by_language = $wpdb->get_results(
            "SELECT language_code, COUNT(*) as count FROM {$this->table_name} GROUP BY language_code"
        );
        foreach ($by_language as $row) {
            $stats['by_language'][$row->language_code] = $row->count;
        }

        // By status
        $by_status = $wpdb->get_results(
            "SELECT translation_status, COUNT(*) as count FROM {$this->table_name} GROUP BY translation_status"
        );
        foreach ($by_status as $row) {
            $stats['by_status'][$row->translation_status] = $row->count;
        }

        // By post type
        $by_post_type = $wpdb->get_results(
            "SELECT p.post_type, COUNT(*) as count
             FROM {$this->table_name} t
             JOIN {$wpdb->posts} p ON t.original_id = p.ID
             GROUP BY p.post_type"
        );
        foreach ($by_post_type as $row) {
            $stats['by_post_type'][$row->post_type] = $row->count;
        }

        return $stats;
    }
    
    /**
     * Get string translation (for universal HTML processing)
     * v3.0.11: Returns FALSE when no translation found (not original text)
     */
    public function get_string_translation($original_string, $language) {
        global $wpdb;

        // v5.0.14: Debug for Лавка
        $is_lavka = (strpos($original_string, 'Лавка') !== false);
        if ($is_lavka) {
            lingua_debug_log("[Lingua v5.0.14] get_string_translation: searching for '{$original_string}' in lang '{$language}'");
        }

        // v3.2: Skip technical content (WooCommerce JSON, etc)
        if ($this->is_technical_content($original_string)) {
            if ($is_lavka) {
                lingua_debug_log("[Lingua v5.0.14] ⚠️ SKIPPED as technical content!");
            }
            return false; // Don't translate technical strings
        }

        // v5.3.44: Trim whitespace BEFORE creating cache key
        // Example: "Rattan armchairs<br />\n" should match "Rattan armchairs<br />"
        $trimmed_string = trim($original_string);

        // Check if we have this string in our translations
        $cache_key = 'lingua_string_' . md5($trimmed_string) . '_' . $language;
        $cached = wp_cache_get($cache_key);

        if ($cached !== false) {
            if ($is_lavka) {
                lingua_debug_log("[Lingua v5.0.14] Found in cache: '{$cached}'");
            }
            return $cached;
        }

        // Look for translation in database (v3.0: status is now INT)
        // IMPORTANT: Uses exact match (original_text = %s) to prevent partial translations
        // v5.3.34: Added ORDER BY updated_at DESC to get most recent translation when duplicates exist
        // v5.4.0: Use TRIM() on DB column for whitespace-tolerant matching
        // Some strings were saved with trailing whitespace (e.g. "Что такое токены? " instead of "Что такое токены?")
        $translation = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text FROM {$wpdb->prefix}lingua_string_translations
             WHERE TRIM(original_text) = %s AND language_code = %s AND status IN (1, 2)
             ORDER BY updated_at DESC LIMIT 1",
            $trimmed_string,
            $language
        ));

        if ($is_lavka) {
            if ($translation) {
                lingua_debug_log("[Lingua v5.0.14] Found in DB: '{$translation}'");
            } else {
                lingua_debug_log("[Lingua v5.0.14] ⚠️ NOT FOUND in DB!");
            }
        }

        if ($translation) {
            // v3.2: Double-check translation is not technical content before returning
            if ($this->is_technical_content($translation)) {
                lingua_debug_log("[LINGUA v3.2] Skipping technical translation: " . substr($translation, 0, 100));
                return false;
            }
            wp_cache_set($cache_key, $translation, '', 3600);
            return $translation;
        }

        // If no translation found, check if it's from a post/page
        $post_translation = $this->find_string_in_post_translations($original_string, $language);
        if ($post_translation) {
            wp_cache_set($cache_key, $post_translation, '', 3600);
            return $post_translation;
        }

        // v3.0.11 CRITICAL FIX: Return FALSE when no translation found
        // This prevents apply_existing_translations() from treating original text as translation
        return false;
    }

    /**
     * v5.2: Get translation by context (for media items)
     * Used to find translations for specific media elements by their unique context
     */
    public function get_translation_by_context($context, $language) {
        global $wpdb;

        $table = $wpdb->prefix . 'lingua_string_translations';

        $translation = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text FROM $table
             WHERE context = %s AND language_code = %s AND status IN (1, 2)
             LIMIT 1",
            $context,
            $language
        ));

        return $translation ? $translation : false;
    }

    /**
     * v5.2.98: Get plural translation for specific plural form
     * Used by gettext ngettext() to retrieve correct plural form based on count
     *
     * @param string $original Original text (msgid - singular form)
     * @param string $language Language code
     * @param int $plural_form_index Plural form index (0, 1, 2, etc.)
     * @param string $domain Text domain (optional)
     * @return string|false Translation or false if not found
     */
    public function get_plural_translation($original, $language, $plural_form_index, $domain = '') {
        global $wpdb;

        // Build cache key including plural_form
        $cache_key = 'lingua_plural_' . md5($original . $language . $plural_form_index . $domain);
        $cached = wp_cache_get($cache_key, 'lingua');

        if ($cached !== false) {
            return $cached;
        }

        // SQL query WITH plural_form in WHERE clause
        $translation = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text
             FROM {$wpdb->prefix}lingua_string_translations
             WHERE original_text = %s
             AND language_code = %s
             AND plural_form = %d
             AND status >= 1
             LIMIT 1",
            $original,
            $language,
            $plural_form_index
        ));

        if (defined('WP_DEBUG') && WP_DEBUG && $translation) {
            lingua_debug_log("[Lingua v5.2.98 Plural] Found translation: '{$original}' (plural_form={$plural_form_index}) → '{$translation}'");
        }

        // Cache result (even if false)
        wp_cache_set($cache_key, $translation ?: false, 'lingua', 3600);

        return $translation ?: false;
    }

    /**
     * Find string in post translations - v3.0.10 FIXED to use new database structure
     */
    private function find_string_in_post_translations($string, $language) {
        global $wpdb;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[Lingua Translation Manager v3.0.10] find_string_in_post_translations called");
            lingua_debug_log("[Lingua Translation Manager v3.0.10] Searching for: " . substr($string, 0, 50) . "...");
            lingua_debug_log("[Lingua Translation Manager v3.0.10] Language: {$language}");
        }

        // v3.0.10: Search in unified string_translations table with NEW v3.0 structure
        $translation = $wpdb->get_var($wpdb->prepare(
            "SELECT translated_text FROM {$this->string_table_name}
             WHERE original_text = %s AND language_code = %s AND status >= %d
             LIMIT 1",
            $string,
            $language,
            1 // status >= 1 means MACHINE_TRANSLATED or HUMAN_REVIEWED
        ));

        if ($translation) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[Lingua Translation Manager v3.0.10] FOUND translation in unified table");
            }
            return $translation;
        }

        // CRITICAL FIX v2.0.4: Search in page_strings from postmeta
        $page_strings_match = $this->find_string_in_page_strings($string, $language);
        if ($page_strings_match) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[Lingua Translation Manager v3.0.10] FOUND in page_strings");
            }
            return $page_strings_match;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[Lingua Translation Manager v3.0.10] NOT FOUND - returning FALSE");
        }

        return false;
    }
    
    /**
     * Apply translation filters
     */
    public function apply_translation_filters() {
        // v5.0.13: Use priority 9999 to run AFTER WooCommerce/WoodMart
        add_filter('the_title', array($this, 'translate_title'), 9999, 2);

        // v5.0.14: CRITICAL FIX - WooCommerce uses get_the_title() directly without the_title filter!
        // woocommerce_template_single_title() calls get_the_title() which bypasses the_title filter
        // We need to hook into 'get_the_title' which is called BEFORE the_title filter
        add_filter('get_the_title', array($this, 'translate_get_title'), 9999, 2);
        add_filter('wp_title', array($this, 'translate_wp_title'), 10, 2);
        add_filter('single_post_title', array($this, 'translate_single_post_title'), 10, 2);
        add_filter('the_content', array($this, 'translate_content'));
        add_filter('the_excerpt', array($this, 'translate_excerpt'));
        add_filter('get_the_excerpt', array($this, 'translate_excerpt'));
        
        // SEO fields translation filters - интеграция с Yoast SEO
        // v5.2.137: Use high priority (999) to run AFTER all other filters
        add_filter('wpseo_title', array($this, 'translate_seo_title'), 999, 1);
        add_filter('wpseo_metadesc', array($this, 'translate_seo_description'), 999, 1);
        add_filter('wpseo_opengraph_title', array($this, 'translate_og_title'), 999, 1);
        add_filter('wpseo_opengraph_desc', array($this, 'translate_og_description'), 999, 1);
        
        // Временно отключаем проблемный фильтр - будем использовать только wpseo_ фильтры
        // add_filter('get_post_metadata', array($this, 'translate_yoast_meta_override'), 10, 4);
        
        // WordPress core title filters
        add_filter('pre_get_document_title', array($this, 'translate_document_title'), 15, 1);
        add_filter('wp_title', array($this, 'translate_wp_title_early'), 15, 2);
        add_filter('document_title_parts', array($this, 'translate_document_title_parts'), 15, 1);
        
        // Старый фильтр удален - метод не существует
        
        // Yoast specific early filters 
        add_filter('option__yoast_wpseo_titles', array($this, 'translate_yoast_options'), 10, 1);
        
        // Добавляем прямой вывод SEO мета-тегов в head с высоким приоритетом (рано)
        add_action('wp_head', array($this, 'add_translated_meta_tags'), 1);
        
        // JavaScript fallback for title
        // v5.5: Changed from wp_footer to wp_enqueue_scripts for wp_add_inline_script() compatibility (WP review)
        add_action('wp_enqueue_scripts', array($this, 'add_title_fallback_script'), 999);
        
        // Force title replacement with highest priority
        add_filter('wp_title', array($this, 'force_translate_title'), 9999, 2);
        add_filter('pre_get_document_title', array($this, 'force_translate_title_simple'), 9999, 1);

        // v5.0.13: Term/Taxonomy translation filters (for category/tag names)
        add_filter('get_term', array($this, 'translate_term'), 10, 2);
        add_filter('get_terms', array($this, 'translate_terms'), 10, 4);
        add_filter('single_term_title', array($this, 'translate_single_term_title'), 10, 1);
        add_filter('get_the_archive_title', array($this, 'translate_archive_title'), 10, 1);
        add_filter('term_name', array($this, 'translate_term_name'), 10, 3);

        // v5.0.13: WooCommerce product title filter (runs very late)
        add_filter('woocommerce_product_title', array($this, 'translate_product_title'), 9999, 2);
        add_filter('single_product_title', array($this, 'translate_product_title'), 9999, 2);
    }
    
    /**
     * v5.0.14: Translate get_the_title (called directly by WooCommerce/themes)
     * This hook fires BEFORE the_title filter
     */
    public function translate_get_title($title, $post_id = null) {
        global $LINGUA_LANGUAGE;

        // v5.0.14: ALWAYS log to see if this is called at all
        lingua_debug_log("[Lingua v5.0.14] 🔔 translate_get_title CALLED: title='{$title}', post_id={$post_id}, lang={$LINGUA_LANGUAGE}");

        if (!$post_id || empty($LINGUA_LANGUAGE)) {
            lingua_debug_log("[Lingua v5.0.14] ⛔ Skipped: no post_id or language");
            return $title;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            lingua_debug_log("[Lingua v5.0.14] ⛔ Skipped: default language");
            return $title;
        }

        // v5.0.14: Debug logging
        if (strpos($title, 'Лавка') !== false || strpos($title, 'приятная') !== false) {
            lingua_debug_log("[Lingua v5.0.14] 🎯 Processing title with Лавка: '{$title}', post_id={$post_id}, lang={$LINGUA_LANGUAGE}");
        }

        // Use string translation table
        $translation = $this->get_string_translation($title, $LINGUA_LANGUAGE);

        if (strpos($title, 'Лавка') !== false) {
            lingua_debug_log("[Lingua v5.0.14] translate_get_title result: translation='" . ($translation ?: 'NULL') . "'");
        }

        if ($translation && $translation !== $title) {
            return $translation;
        }

        // Fallback: return original title
        return $title;
    }

    /**
     * Translate title with configurable fallback to original
     */
    public function translate_title($title, $post_id = null) {
        global $LINGUA_LANGUAGE;

        if (!$post_id || empty($LINGUA_LANGUAGE)) {
            return $title;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title;
        }

        // v5.0.14: Debug logging for "Лавка интерьерная"
        if (strpos($title, 'Лавка') !== false) {
            lingua_debug_log("[Lingua v5.0.14] translate_title called: title='{$title}', post_id={$post_id}, lang={$LINGUA_LANGUAGE}");
        }

        // v5.0.13: Use string translation table (new architecture)
        $translation = $this->get_string_translation($title, $LINGUA_LANGUAGE);

        // v5.0.14: Debug logging for translation result
        if (strpos($title, 'Лавка') !== false) {
            lingua_debug_log("[Lingua v5.0.14] translate_title result: translation='" . ($translation ?: 'NULL') . "'");
        }

        if ($translation && $translation !== $title) {
            return $translation;
        }

        // Fallback: return original title
        return $title;
    }
    
    /**
     * Translate wp_title
     */
    public function translate_wp_title($title, $sep = null) {
        global $post;
        if ($post) {
            return $this->translate_title($title, $post->ID);
        }
        return $title;
    }
    
    /**
     * Translate single_post_title
     */
    public function translate_single_post_title($title, $post = null) {
        if (!$post) {
            global $post;
        }
        if ($post) {
            return $this->translate_title($title, $post->ID);
        }
        return $title;
    }
    
    /**
     * Translate content with configurable fallback to original
     */
    public function translate_content($content) {
        global $post, $LINGUA_LANGUAGE;
        
        if (!$post || empty($LINGUA_LANGUAGE)) {
            return $content;
        }
        
        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $content;
        }
        
        lingua_debug_log('Lingua: Translating content for post ' . $post->ID . ' to language ' . $LINGUA_LANGUAGE);
        
        $translation = $this->get_translation($post->ID, $LINGUA_LANGUAGE);
        
        // Return translated content if available - reconstruct from blocks
        if ($translation && !empty($translation->translated_content)) {
            // Try to decode as JSON first (new format)
            $translated_blocks = json_decode($translation->translated_content, true);
            if (is_array($translated_blocks) && !empty($translated_blocks)) {
                // Use content processor to reconstruct content with proper HTML structure
                $content_processor = new Lingua_Content_Processor();
                $reconstructed = $content_processor->reconstruct_content($content, $translated_blocks);
                return $reconstructed;
            } else {
                // Old format (plain text) - use as-is, let user decide if it's good
                return $translation->translated_content;
            }
        }
        
        // Check fallback setting
        $show_original_fallback = get_option('lingua_show_original_fallback', true);
        if ($show_original_fallback) {
            // Fallback: return original content
            return $content;
        }
        
        // No fallback: return empty
        return '';
    }
    
    /**
     * Translate excerpt with configurable fallback to original
     */
    public function translate_excerpt($excerpt) {
        global $post, $LINGUA_LANGUAGE;

        lingua_debug_log("[LINGUA SAVE] translate_excerpt: Using language: " . ($LINGUA_LANGUAGE ?? 'undefined'));

        if (!$post || empty($LINGUA_LANGUAGE)) {
            return $excerpt;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $excerpt;
        }

        $translation = $this->get_translation($post->ID, $LINGUA_LANGUAGE);
        
        // Return translated excerpt if available
        if ($translation && !empty($translation->translated_excerpt)) {
            return $translation->translated_excerpt;
        }
        
        // Check fallback setting
        $show_original_fallback = get_option('lingua_show_original_fallback', true);
        if ($show_original_fallback) {
            // Fallback: return original excerpt
            return $excerpt;
        }
        
        // No fallback: return empty
        return '';
    }
    
    /**
     * Translate SEO title
     * v5.2.137: Updated to use new string translations architecture
     */
    public function translate_seo_title($title) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || empty($title)) {
            return $title;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title;
        }

        // Use new string translation lookup
        $translation = $this->get_string_translation($title, $LINGUA_LANGUAGE);

        if ($translation && $translation !== $title) {
            lingua_debug_log("[Lingua SEO v5.2.137] Translated title: '{$title}' → '{$translation}'");
            return $translation;
        }

        return $title;
    }

    /**
     * Translate SEO description
     * v5.2.137: Updated to use new string translations architecture
     */
    public function translate_seo_description($description) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || empty($description)) {
            return $description;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $description;
        }

        // Use new string translation lookup
        $translation = $this->get_string_translation($description, $LINGUA_LANGUAGE);

        if ($translation && $translation !== $description) {
            lingua_debug_log("[Lingua SEO v5.2.137] Translated description: '" . mb_substr($description, 0, 50) . "' → '" . mb_substr($translation, 0, 50) . "'");
            return $translation;
        }

        return $description;
    }
    
    /**
     * Translate OpenGraph title
     * v5.2.137: Updated to use new string translations architecture
     */
    public function translate_og_title($title) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || empty($title)) {
            return $title;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title;
        }

        // Use new string translation lookup
        $translation = $this->get_string_translation($title, $LINGUA_LANGUAGE);

        if ($translation && $translation !== $title) {
            return $translation;
        }

        return $title;
    }

    /**
     * Translate OpenGraph description
     * v5.2.137: Updated to use new string translations architecture
     */
    public function translate_og_description($description) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || empty($description)) {
            return $description;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $description;
        }

        // Use new string translation lookup
        $translation = $this->get_string_translation($description, $LINGUA_LANGUAGE);

        if ($translation && $translation !== $description) {
            return $translation;
        }

        return $description;
    }
    
    /**
     * Translate document title
     */
    public function translate_document_title($title) {
        global $post, $LINGUA_LANGUAGE;
        if (!$post || empty($LINGUA_LANGUAGE)) {
            return $title;
        }
        
        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title;
        }
        
        $translation = $this->get_translation($post->ID, $LINGUA_LANGUAGE);
        if ($translation && !empty($translation->meta['seo_title'])) {
            return $translation->meta['seo_title'];
        }
        
        return $title;
    }
    
    /**
     * Принудительное замещение Yoast SEO meta полей переводами
     */
    public function translate_yoast_meta_override($value, $object_id, $meta_key, $single) {
        // Работаем только на фронтенде и для не-админов
        if (is_admin() || !empty($LINGUA_LANGUAGE)) {
            return $value;
        }
        
        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $value;
        }
        
        // Yoast SEO мета поля для замены
        $yoast_meta_fields = array(
            '_yoast_wpseo_title' => 'seo_title',
            '_yoast_wpseo_metadesc' => 'seo_description',
            '_yoast_wpseo_opengraph-title' => 'og_title',
            '_yoast_wpseo_opengraph-description' => 'og_description'
        );
        
        if (isset($yoast_meta_fields[$meta_key])) {
            $translation = $this->get_translation($object_id, $LINGUA_LANGUAGE);
            if ($translation && !empty($translation->meta[$yoast_meta_fields[$meta_key]])) {
                // ПРИНУДИТЕЛЬНО заменяем значение переводом
                $translated_value = $translation->meta[$yoast_meta_fields[$meta_key]];
                
                // Возвращаем в правильном формате
                if ($single) {
                    return $translated_value;
                } else {
                    return array($translated_value);
                }
            }
            
            // Если перевода нет - даем WordPress получить оригинальное значение из базы
            // Возвращаем null чтобы WordPress продолжил обычную обработку
            return null;
        }
        
        return null;
    }
    
    /**
     * Get current post ID, handling front page case
     */
    private function get_current_post_id() {
        global $post;
        
        // Debug: Check the request URI
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

        // If we have a post object, use it FIRST (prioritize actual post)
        if ($post && $post->ID) {
            return $post->ID;
        }
        
        // Handle language prefixed front page specifically (only as fallback)
        if ($request_uri === '/en/' || $request_uri === '/fr/' || preg_match('#^/(en|fr)/?$#', $request_uri)) {
            return 17; // Known front page ID
        }
        
        // Try queried object first
        $queried_object = get_queried_object();
        if ($queried_object && isset($queried_object->ID)) {
            return $queried_object->ID;
        }
        
        // Handle front page
        $front_page_id = get_option('page_on_front');
        if ($front_page_id) {
            return $front_page_id;
        }
        
        // If no static front page set, try to find latest page
        $latest_page = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'menu_order',
            'order' => 'ASC'
        ));
        
        if (!empty($latest_page)) {
            return $latest_page[0]->ID;
        }
        
        // Final fallback: hardcoded front page ID (temporary)
        if (is_home() || is_front_page() || sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')) === '/') {
            return 17; // Known front page ID
        }
        
        return false;
    }
    
    /**
     * Early wp_title filter
     */
    public function translate_wp_title_early($title, $sep = '') {
        if (!empty($LINGUA_LANGUAGE)) {
            return $title;
        }
        
        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title;
        }
        
        $post_id = $this->get_current_post_id();
        if (!$post_id) {
            return $title;
        }
        
        $translation = $this->get_translation($post_id, $LINGUA_LANGUAGE);
        if ($translation && !empty($translation->meta['seo_title'])) {
            return $translation->meta['seo_title'];
        }
        
        return $title;
    }
    
    /**
     * Translate document title parts
     */
    public function translate_document_title_parts($title_parts) {
        global $LINGUA_LANGUAGE;
        
        if (empty($LINGUA_LANGUAGE)) {
            return $title_parts;
        }
        
        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title_parts;
        }
        
        $post_id = $this->get_current_post_id();
        if (!$post_id) {
            return $title_parts;
        }
        
        $translation = $this->get_translation($post_id, $LINGUA_LANGUAGE);
        if ($translation && !empty($translation->meta['seo_title'])) {
            // Split translated title back into parts
            $translated_title = $translation->meta['seo_title'];
            $site_name = get_bloginfo('name');
            
            // If the translated title contains the site name, split it
            if (strpos($translated_title, $site_name) !== false) {
                $title_parts['title'] = trim(str_replace(array(' - ' . $site_name, ' — ' . $site_name, ' | ' . $site_name), '', $translated_title));
                $title_parts['site'] = $site_name;
            } else {
                $title_parts['title'] = $translated_title;
            }
        }
        
        return $title_parts;
    }
    
    /**
     * Translate Yoast options on the fly
     */
    public function translate_yoast_options($option) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || !is_array($option)) {
            return $option;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $option;
        }
        
        $post_id = $this->get_current_post_id();
        if (!$post_id) {
            return $option;
        }
        
        $translation = $this->get_translation($post_id, $LINGUA_LANGUAGE);
        if (!$translation) {
            return $option;
        }
        
        // Override Yoast title templates with translated values
        if (!empty($translation->meta['seo_title'])) {
            $post_type = get_post_type($post_id);
            $option['title-' . $post_type] = $translation->meta['seo_title'];
        }
        
        if (!empty($translation->meta['seo_description'])) {
            $post_type = get_post_type($post_id);
            $option['metadesc-' . $post_type] = $translation->meta['seo_description'];
        }
        
        return $option;
    }
    
    
    
    /**
     * Force translate title with highest priority
     */
    public function force_translate_title($title, $sep = '') {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE)) {
            return $title;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title;
        }
        
        $post_id = $this->get_current_post_id();
        if (!$post_id) {
            return $title;
        }
        
        $translation = $this->get_translation($post_id, $LINGUA_LANGUAGE);
        if ($translation && !empty($translation->meta['seo_title'])) {
            return $translation->meta['seo_title'];
        }
        
        return $title;
    }
    
    /**
     * Force translate title simple version
     */
    public function force_translate_title_simple($title) {
        global $LINGUA_LANGUAGE;

        lingua_debug_log("[LINGUA SAVE] force_translate_title_simple: Using language: " . ($LINGUA_LANGUAGE ?? 'undefined'));

        if (empty($LINGUA_LANGUAGE)) {
            return $title;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title;
        }

        $post_id = $this->get_current_post_id();
        if (!$post_id) {
            return $title;
        }

        $translation = $this->get_translation($post_id, $LINGUA_LANGUAGE);
        if ($translation && !empty($translation->meta['seo_title'])) {
            return $translation->meta['seo_title'];
        }
        
        return $title;
    }
    
    /**
     * Add translated meta tags directly to head (working implementation from 0fed9f3)
     */
    public function add_translated_meta_tags() {
        global $LINGUA_LANGUAGE;

        lingua_debug_log("[LINGUA SAVE] add_translated_meta_tags: Using language: " . ($LINGUA_LANGUAGE ?? 'undefined'));

        if (empty($LINGUA_LANGUAGE)) {
            return;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return;
        }

        $post_id = $this->get_current_post_id();
        if (!$post_id) {
            return;
        }

        $translation = $this->get_translation($post_id, $LINGUA_LANGUAGE);
        if (!$translation) {
            return;
        }
        
        // Output translated meta description if available
        if (!empty($translation->meta['seo_description'])) {
            echo '<meta name="description" content="' . esc_attr($translation->meta['seo_description']) . '" class="lingua-translated-meta" />' . "\n";
        }
        
        // Output translated OpenGraph tags if available
        if (!empty($translation->meta['og_title'])) {
            echo '<meta property="og:title" content="' . esc_attr($translation->meta['og_title']) . '" class="lingua-translated-meta" />' . "\n";
        }
        
        if (!empty($translation->meta['og_description'])) {
            echo '<meta property="og:description" content="' . esc_attr($translation->meta['og_description']) . '" class="lingua-translated-meta" />' . "\n";
        }
    }
    
    /**
     * Add JavaScript fallback for title translation
     */
    public function add_title_fallback_script() {
        global $LINGUA_LANGUAGE;

        lingua_debug_log("[LINGUA SAVE] add_title_fallback_script: Using language: " . ($LINGUA_LANGUAGE ?? 'undefined'));

        if (empty($LINGUA_LANGUAGE)) {
            return;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return;
        }

        $post_id = $this->get_current_post_id();
        if (!$post_id) {
            return;
        }

        $translation = $this->get_translation($post_id, $LINGUA_LANGUAGE);
        if (!$translation || empty($translation->meta['seo_title'])) {
            return;
        }
        
        $translated_title = esc_js($translation->meta['seo_title']);

        // v5.5: Use wp_add_inline_script() instead of inline <script> tag (WP review compliance)
        $inline_js = "(function() {\n"
            . "if (document.title === 'IQCloud Translate') {\n"
            . "    document.title = '{$translated_title}';\n"
            . "}\n"
            . "})();";

        wp_add_inline_script('lingua-dynamic-translation', $inline_js, 'after');
    }
    
    
    /**
     * UNIFIED PIPELINE: Get unified translation data for a post
     */
    private function get_unified_translation_data($post_id, $language) {
        global $wpdb;

        try {
            // PHASE 2.1: DEBUG - Log unified data retrieval start
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA KEYS] === GET_UNIFIED_DATA START ===");
                lingua_debug_log("[LINGUA KEYS] post_id={$post_id}, language={$language}");
                lingua_debug_log("[LINGUA KEYS] search_pattern=%.post_{$post_id}");
            }

            // Get all translations for this post
            // v5.3.36: Use integer status check instead of string 'published'
            $translations = $wpdb->get_results($wpdb->prepare(
                "SELECT original_text, translated_text, context
                 FROM {$this->string_table_name}
                 WHERE context LIKE %s AND language_code = %s AND status >= 1",
                "%.post_{$post_id}",
                $language
            ));

            // PHASE 2.1: DEBUG - Log SQL results and keys found
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA KEYS] sql_results_count=" . count($translations));
                if (!empty($translations)) {
                    $found_contexts = array_map(function($t) { return $t->context; }, $translations);
                    lingua_debug_log("[LINGUA KEYS] found_contexts=" . wp_json_encode($found_contexts));
                }
            }

            if (empty($translations)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log("[LINGUA KEYS] === NO DATA FOUND ===");
                }
                return array();
            }

            // Group by field groups
            $grouped = array(
                'core_fields' => array(),
                'seo_fields' => array(),
                'meta_fields' => array(),
                'taxonomy_terms' => array(),
                'content_blocks' => array(),
                'page_strings' => array()
            );

            $context_parsing_issues = array();

            foreach ($translations as $translation) {
                // Parse context to extract group and field
                $context_parts = explode('.', $translation->context);
                if (count($context_parts) >= 2) {
                    $group = $context_parts[0];
                    $field = $context_parts[1];

                    // PHASE 2.1: DEBUG - Log each key parsing
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        lingua_debug_log("[LINGUA KEYS] parsing_context={$translation->context} -> group={$group}, field={$field}");
                    }

                    if (isset($grouped[$group])) {
                        $grouped[$group][$field] = array(
                            'original' => $translation->original_text,
                            'translated' => $translation->translated_text
                        );
                    } else {
                        // PHASE 2.1: DEBUG - Log unknown groups
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            lingua_debug_log("[LINGUA KEYS WARNING] Unknown group '{$group}' in context: {$translation->context}");
                        }
                        $context_parsing_issues[] = $translation->context;
                    }
                } else {
                    // PHASE 2.1: DEBUG - Log malformed contexts
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        lingua_debug_log("[LINGUA KEYS ERROR] Malformed context: {$translation->context}");
                    }
                    $context_parsing_issues[] = $translation->context;
                }
            }

            // PHASE 2.1: DEBUG - Log final grouped structure
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $group_summary = array();
                foreach ($grouped as $group => $fields) {
                    if (!empty($fields)) {
                        $group_summary[$group] = array_keys($fields);
                    }
                }
                lingua_debug_log("[LINGUA KEYS] grouped_structure=" . wp_json_encode($group_summary));

                if (!empty($context_parsing_issues)) {
                    lingua_debug_log("[LINGUA KEYS WARNING] context_issues=" . wp_json_encode($context_parsing_issues));
                }
                lingua_debug_log("[LINGUA KEYS] === GET_UNIFIED_DATA SUCCESS ===");
            }

            return $grouped;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA ERROR] get_unified_translation_data failed: " . $e->getMessage());
                lingua_debug_log("[LINGUA ERROR] error_trace=" . $e->getTraceAsString());
            }
            return array();
        }
    }

    /**
     * UNIFIED PIPELINE: Extract meta from unified data
     */
    private function extract_meta_from_unified($unified_data) {
        $meta = array();

        // Extract SEO fields as meta
        if (isset($unified_data['seo_fields']) && is_array($unified_data['seo_fields'])) {
            foreach ($unified_data['seo_fields'] as $field_key => $translation) {
                if (isset($translation['translated'])) {
                    $meta[$field_key] = $translation['translated'];
                }
            }
        }

        // Extract meta fields
        if (isset($unified_data['meta_fields']) && is_array($unified_data['meta_fields'])) {
            foreach ($unified_data['meta_fields'] as $field_key => $translation) {
                if (isset($translation['translated'])) {
                    $meta[$field_key] = $translation['translated'];
                }
            }
        }

        return $meta;
    }

    /**
     * PUBLIC: Save string translation (for queue processing and external calls)
     * v5.2.180: Added for auto-translation queue
     *
     * @param string $original_text Original text
     * @param string $translated_text Translated text
     * @param string $language Target language code
     * @param string $context Context identifier (e.g., 'core_fields.title.post_123')
     * @param string $source Source of translation (default 'auto')
     * @return bool Success status
     */
    public function save_string_translation($original_text, $translated_text, $language, $context, $source = 'auto') {
        global $wpdb;

        if (empty($original_text) || empty($translated_text) || empty($language)) {
            return false;
        }

        // For HTML content, use wp_kses_post; for plain text, use sanitize_text_field
        $is_html = ($original_text !== strip_tags($original_text));

        if ($is_html) {
            $original_clean = wp_kses_post($original_text);
            $translated_clean = wp_kses_post($translated_text);
        } else {
            $original_clean = sanitize_text_field($original_text);
            $translated_clean = sanitize_text_field($translated_text);
        }

        $language = sanitize_text_field($language);
        $context = sanitize_text_field($context);
        $source = sanitize_text_field($source);

        // Generate hash for original text
        $text_hash = md5($original_clean);

        try {
            // v5.3.36: Use integer status constant instead of string 'published'
            $result = $wpdb->replace(
                $this->string_table_name,
                array(
                    'original_text' => $original_clean,
                    'original_text_hash' => $text_hash,
                    'translated_text' => $translated_clean,
                    'language_code' => $language,
                    'context' => $context,
                    'source' => $source,
                    'status' => Lingua_Database::HUMAN_REVIEWED, // Integer: 2
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
            );

            // v5.3.38: CRITICAL FIX - Synchronize with gettext records
            // Same fix as in save_unified_string() for consistency
            if ($result !== false && $source !== 'gettext') {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->string_table_name}
                     SET translated_text = %s, status = %d, updated_at = %s
                     WHERE original_text_hash = %s AND language_code = %s AND source = 'gettext' AND context != %s",
                    $translated_clean,
                    Lingua_Database::MACHINE_TRANSLATED, // Integer: 1
                    current_time('mysql'),
                    $text_hash,
                    $language,
                    $context
                ));
            }

            return $result !== false;
        } catch (Exception $e) {
            lingua_debug_log("[Lingua] save_string_translation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * UNIFIED PIPELINE: Save individual string translation
     */
    private function save_unified_string($original_text, $translated_text, $language, $context, $source = 'custom', $gettext_domain = null) {
        global $wpdb;

        // PHASE 2.1: DEBUG - Log SQL operation details
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA SQL v5.2.42] === SQL SAVE START ===");
            lingua_debug_log("[LINGUA SQL] table={$this->string_table_name}");
            lingua_debug_log("[LINGUA SQL] language={$language}, context={$context}");
            lingua_debug_log("[LINGUA SQL] source={$source}, gettext_domain=" . ($gettext_domain ?? 'null'));
            lingua_debug_log("[LINGUA SQL] original_length=" . strlen($original_text));
            lingua_debug_log("[LINGUA SQL] translated_length=" . strlen($translated_text));
        }

        // Sanitize inputs
        $original_text = sanitize_text_field($original_text);
        $translated_text = sanitize_text_field($translated_text);
        $language = sanitize_text_field($language);
        $context = sanitize_text_field($context);
        $source = sanitize_text_field($source);
        $gettext_domain = $gettext_domain ? sanitize_text_field($gettext_domain) : null;

        // Generate hash for original text
        $text_hash = md5($original_text);

        // PHASE 2.1: DEBUG - Log sanitized data
        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA SQL] text_hash={$text_hash}");
            lingua_debug_log("[LINGUA SQL] sanitized_original=" . substr($original_text, 0, 30) . "...");
            lingua_debug_log("[LINGUA SQL] sanitized_translated=" . substr($translated_text, 0, 30) . "...");
        }

        try {
            // Check if table exists first
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->string_table_name}'");
            if (!$table_exists) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    lingua_debug_log("[LINGUA SQL ERROR] Table {$this->string_table_name} does not exist!");
                }
                return false;
            }

            // Use REPLACE for atomic upsert operation (v5.2.42: added source and gettext_domain)
            // v5.3.36: Use integer status constant instead of string 'published'
            $result = $wpdb->replace(
                $this->string_table_name,
                array(
                    'original_text' => $original_text,
                    'original_text_hash' => $text_hash,
                    'translated_text' => $translated_text,
                    'language_code' => $language,
                    'context' => $context,
                    'source' => $source,
                    'gettext_domain' => $gettext_domain,
                    'status' => Lingua_Database::HUMAN_REVIEWED, // Integer: 2
                    'updated_at' => current_time('mysql')
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
            );

            // v5.3.38: CRITICAL FIX - Synchronize with gettext records
            // When saving a translation from modal/auto-translate, also update any existing
            // gettext records with the same original_text and language_code.
            // This ensures String Translations page shows correct translation status.
            if ($result !== false && $source !== 'gettext') {
                $gettext_updated = $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->string_table_name}
                     SET translated_text = %s, status = %d, updated_at = %s
                     WHERE original_text_hash = %s AND language_code = %s AND source = 'gettext' AND context != %s",
                    $translated_text,
                    Lingua_Database::MACHINE_TRANSLATED, // Integer: 1 (mark as machine translated)
                    current_time('mysql'),
                    $text_hash,
                    $language,
                    $context // Don't update the same record we just inserted
                ));

                if (defined('WP_DEBUG') && WP_DEBUG && $gettext_updated > 0) {
                    lingua_debug_log("[LINGUA SQL v5.3.38] ✅ Synchronized {$gettext_updated} gettext record(s) for original_text_hash={$text_hash}");
                }
            }

            // PHASE 2.1: DEBUG - Log SQL result and potential errors
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA SQL] wpdb->replace result: " . ($result !== false ? $result : 'FALSE'));

                if ($wpdb->last_error) {
                    lingua_debug_log("[LINGUA SQL ERROR] " . $wpdb->last_error);
                    lingua_debug_log("[LINGUA SQL ERROR] Last query: " . $wpdb->last_query);
                }

                if ($result !== false) {
                    lingua_debug_log("[LINGUA SQL] SUCCESS: Inserted/Updated row, affected_rows=" . $wpdb->rows_affected);

                    // Verify the record was actually saved
                    $verify = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$this->string_table_name} WHERE original_text_hash = %s AND language_code = %s AND context = %s",
                        $text_hash, $language, $context
                    ));
                    lingua_debug_log("[LINGUA SQL] VERIFY: Found {$verify} matching records");
                } else {
                    lingua_debug_log("[LINGUA SQL ERROR] REPLACE operation failed");
                }
            }

            return $result !== false;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA SQL ERROR] Exception in save_unified_string: " . $e->getMessage());
                lingua_debug_log("[LINGUA SQL ERROR] Exception trace: " . $e->getTraceAsString());
                if ($wpdb->last_error) {
                    lingua_debug_log("[LINGUA SQL ERROR] wpdb last_error: " . $wpdb->last_error);
                }
            }
            return false;
        }
    }

    /**
     * UNIFIED PIPELINE: Get single string translation
     */
    public function get_unified_translation($field_group, $post_id, $original_text, $language_code, $context = 'general') {
        global $wpdb;

        try {
            // Sanitize inputs
            $original_text = sanitize_text_field($original_text);
            $language_code = sanitize_text_field($language_code);
            $context = sanitize_text_field($context);

            // Build specific context if provided
            if ($field_group && $post_id) {
                $context = sanitize_text_field("{$field_group}.post_{$post_id}");
            }

            // Try exact context match first
            // v5.3.36: Use integer status check instead of string 'published'
            $translation = $wpdb->get_var($wpdb->prepare(
                "SELECT translated_text FROM {$this->string_table_name}
                 WHERE original_text = %s AND language_code = %s AND context = %s AND status >= 1
                 LIMIT 1",
                $original_text,
                $language_code,
                $context
            ));

            if ($translation) {
                return $translation;
            }

            // Fallback: try general context or similar context
            // v5.3.36: Use integer status check instead of string 'published'
            $translation = $wpdb->get_var($wpdb->prepare(
                "SELECT translated_text FROM {$this->string_table_name}
                 WHERE original_text = %s AND language_code = %s AND status >= 1
                 ORDER BY
                    CASE WHEN context = %s THEN 1
                         WHEN context LIKE %s THEN 2
                         ELSE 3 END
                 LIMIT 1",
                $original_text,
                $language_code,
                'general',
                $field_group . '%'
            ));

            // FALLBACK PROTECTION: Return original if no translation found
            return $translation ? $translation : $original_text;

        } catch (Exception $e) {
            // FALLBACK PROTECTION: Log error and return original
            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA ERROR] get_unified_translation failed: " . $e->getMessage());
            }
            return $original_text;
        }
    }


    /**
     * Clear translation cache (v5.2.124: COMPREHENSIVE cache clearing)
     */
    private function clear_translation_cache($post_id, $language) {
        lingua_debug_log("[Lingua v5.2.124] 🔥 CLEARING ALL CACHES for post {$post_id}, language {$language}");

        // 1. Object cache
        $cache_key = 'lingua_translation_' . $post_id . '_' . $language;
        wp_cache_delete($cache_key);
        wp_cache_flush_group('lingua'); // Plural forms

        // 2. HTML transient cache (CRITICAL: prevents old content display)
        delete_transient('lingua_html_cache_' . $post_id);

        // 3. Extracted content cache (all languages)
        $languages = get_option('lingua_languages', array());
        foreach ($languages as $lang_code => $lang_data) {
            delete_transient('lingua_extracted_content_' . $post_id . '_' . $lang_code);
        }
        delete_transient('lingua_extracted_content_' . $post_id);

        // 4. CRITICAL: Unload and reload gettext domain to clear PHP memory cache
        // This fixes the issue where admin shows old translations but incognito shows new ones
        $default_language = get_option('lingua_default_language', lingua_get_site_language());
        if ($language !== $default_language) {
            // Unload domain
            unload_textdomain('lingua-' . $language);

            // ULTRA CRITICAL: Clear global $l10n cache (loaded translations in PHP memory)
            global $l10n;
            if (isset($l10n['lingua-' . $language])) {
                unset($l10n['lingua-' . $language]);
                lingua_debug_log("[Lingua v5.2.124] Cleared \$l10n cache for lingua-{$language}");
            }

            // Force WordPress to reload gettext domain on next translation call
            // This ensures fresh .mo files are loaded from disk
            lingua_debug_log("[Lingua v5.2.124] Unloaded gettext domain: lingua-{$language}");
        }

        // 5. WordPress post cache clear
        clean_post_cache($post_id);

        // 6. String translation cache (wp_cache)
        wp_cache_delete('lingua_string_' . md5('*') . '_' . $language); // Clear all string caches

        // 7. CRITICAL v5.2.125: Clear Output Buffer HTML caches (for both logged_in and guest users)
        // These caches use different keys for logged_in vs guest users, causing admin to see old translations
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_original_html_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lingua_original_html_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_lingua_translated_html_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_lingua_translated_html_%'");
        lingua_debug_log("[Lingua v5.2.125] ✅ Cleared ALL Output Buffer HTML caches (logged_in + guest)");

        lingua_debug_log("[Lingua v5.2.125] ✅ COMPREHENSIVE cache clear complete: HTML, extracted, object, gettext, Output Buffer, WordPress post cache");
    }

    /**
     * CRITICAL FIX v2.0.4: Get translations from postmeta (new method)
     * Retrieves all translations stored in wp_postmeta with lingua_{language}_ prefix
     */
    private function get_postmeta_translations($post_id, $language) {
        global $wpdb;

        $translations = array();

        // Get all postmeta with lingua_{language}_ prefix
        $meta_key_pattern = "lingua_{$language}_%";
        $meta_results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key LIKE %s",
            $post_id,
            $meta_key_pattern
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA LOAD v2.0.4] Postmeta query found " . count($meta_results) . " entries for pattern: {$meta_key_pattern}");
        }

        foreach ($meta_results as $meta) {
            // Extract field name from meta_key (remove lingua_{language}_ prefix)
            $field_name = str_replace("lingua_{$language}_", '', $meta->meta_key);

            // Handle different field types
            if ($field_name === 'page_strings' && is_string($meta->meta_value)) {
                // page_strings is stored as serialized array
                $page_strings = maybe_unserialize($meta->meta_value);
                if (is_array($page_strings)) {
                    $translations['page_strings'] = $page_strings;
                }
            } else {
                // Regular fields (title, excerpt, seo_title, etc.)
                $translations[$field_name] = $meta->meta_value;
            }

            if (defined('WP_DEBUG') && WP_DEBUG) {
                lingua_debug_log("[LINGUA LOAD v2.0.4] Loaded postmeta field: {$field_name} = " . substr($meta->meta_value, 0, 100) . "...");
            }
        }

        return $translations;
    }

    /**
     * CRITICAL FIX v2.0.4: Find string in page_strings from postmeta
     * This enables frontend gettext translation for saved page_strings
     */
    private function find_string_in_page_strings($string, $language) {
        global $wpdb;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA GETTEXT v2.0.4] Searching for string: '{$string}' in language: {$language}");
        }

        // Search all posts that have lingua_{language}_page_strings
        $meta_key = "lingua_{$language}_page_strings";
        $posts_with_translations = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = %s",
            $meta_key
        ));

        foreach ($posts_with_translations as $post_meta) {
            $page_strings = maybe_unserialize($post_meta->meta_value);

            if (is_array($page_strings)) {
                foreach ($page_strings as $string_data) {
                    if (isset($string_data['original'], $string_data['translated']) &&
                        $string_data['original'] === $string) {

                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            lingua_debug_log("[LINGUA GETTEXT v2.0.4] FOUND MATCH: '{$string}' → '{$string_data['translated']}' in post {$post_meta->post_id}");
                        }

                        return $string_data['translated'];
                    }
                }
            }
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            lingua_debug_log("[LINGUA GETTEXT v2.0.4] NO MATCH FOUND for: '{$string}'");
        }

        return false;
    }

    /**
     * v3.2: Check if text is technical content (WooCommerce JSON, HTML fragments, etc)
     * This prevents translating data-attributes, JSON, and other technical strings
     */
    private function is_technical_content($text) {
        if (empty($text) || !is_string($text)) {
            return true;
        }

        // JSON detection
        if (json_decode($text) !== null) {
            return true;
        }

        // JSON-like patterns (even if malformed)
        if (preg_match('/[\{\[].*["\']:\s*["\'].*[\}\]]/', $text)) {
            return true;
        }

        // WooCommerce price HTML fragments (escaped spans, bdi tags)
        if (preg_match('#<\\\\/span>|<\\\\/bdi>|<\\\\/ins>#', $text)) {
            return true;
        }

        // Variation data patterns (sku, variation_description, etc.)
        if (preg_match('/"(sku|variation_id|variation_description|price_html|availability_html)"/', $text)) {
            return true;
        }

        // URLs
        if (preg_match('/^https?:\/\//', $text)) {
            return true;
        }

        // Email
        if (filter_var($text, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        // Too short
        if (strlen($text) < 2) {
            return true;
        }

        // Technical IDs (contains only alphanumeric + dashes/underscores)
        // v1.2.10: Only flag as technical if LOWERCASE (technical IDs like post-123, btn_primary)
        //          Allow hyphenated words with capitals like "Single-stage", "Two-stage"
        if (preg_match('/^[a-z0-9_-]+$/', $text)) { // Removed 'i' flag - must be all lowercase
            if (strpos($text, '-') !== false || strpos($text, '_') !== false) {
                return true;
            }
        }

        // File extensions
        if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|mp4)$/i', $text)) {
            return true;
        }

        return false;
    }

    /**
     * v5.0.13: Translate term object (get_term filter)
     */
    public function translate_term($term, $taxonomy) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || !is_object($term)) {
            return $term;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $term;
        }

        // Get translation for term name
        $translation = $this->get_string_translation($term->name, $LINGUA_LANGUAGE);

        if ($translation && $translation !== $term->name) {
            $term->name = $translation;
        }

        // Also translate description if exists
        if (!empty($term->description)) {
            $desc_translation = $this->get_string_translation($term->description, $LINGUA_LANGUAGE);
            if ($desc_translation && $desc_translation !== $term->description) {
                $term->description = $desc_translation;
            }
        }

        return $term;
    }

    /**
     * v5.0.13: Translate array of terms (get_terms filter)
     */
    public function translate_terms($terms, $taxonomies, $args, $term_query) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || !is_array($terms)) {
            return $terms;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $terms;
        }

        foreach ($terms as &$term) {
            if (is_object($term)) {
                $term = $this->translate_term($term, $term->taxonomy ?? '');
            }
        }

        return $terms;
    }

    /**
     * v5.0.13: Translate single term title (single_term_title filter)
     */
    public function translate_single_term_title($term_name) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || empty($term_name)) {
            return $term_name;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $term_name;
        }

        $translation = $this->get_string_translation($term_name, $LINGUA_LANGUAGE);

        return $translation ?: $term_name;
    }

    /**
     * v5.0.13: Translate archive title (get_the_archive_title filter)
     */
    public function translate_archive_title($title) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || empty($title)) {
            return $title;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title;
        }

        // Extract term name from title (e.g., "Category: Furniture" -> "Furniture")
        // Common patterns: "Category: X", "Tag: X", "Archive: X", or just "X"
        $patterns = array(
            '/^([^:]+):\s*(.+)$/',  // "Category: Name" -> captures "Category" and "Name"
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title, $matches)) {
                $prefix = $matches[1];  // "Category"
                $term_name = $matches[2];  // "Мебель для дома"

                // Translate the term name
                $translation = $this->get_string_translation($term_name, $LINGUA_LANGUAGE);

                if ($translation && $translation !== $term_name) {
                    // Also translate prefix if possible
                    $prefix_translation = $this->get_string_translation($prefix, $LINGUA_LANGUAGE);
                    $translated_prefix = $prefix_translation ?: $prefix;

                    return $translated_prefix . ': ' . $translation;
                }
            }
        }

        // No pattern matched, try translating the whole title
        $translation = $this->get_string_translation($title, $LINGUA_LANGUAGE);
        return $translation ?: $title;
    }

    /**
     * v5.0.13: Translate term name (term_name filter)
     * v5.3.43: Made $term and $taxonomy optional - WP_Terms_List_Table passes only 2 params
     */
    public function translate_term_name($name, $term = null, $taxonomy = null) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || empty($name)) {
            return $name;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $name;
        }

        $translation = $this->get_string_translation($name, $LINGUA_LANGUAGE);

        return $translation ?: $name;
    }

    /**
     * v5.0.13: Translate WooCommerce product title
     */
    public function translate_product_title($title, $product = null) {
        global $LINGUA_LANGUAGE;

        if (empty($LINGUA_LANGUAGE) || empty($title)) {
            return $title;
        }

        $default_lang = get_option('lingua_default_language', lingua_get_site_language());
        if ($LINGUA_LANGUAGE === $default_lang) {
            return $title;
        }

        $translation = $this->get_string_translation($title, $LINGUA_LANGUAGE);

        return $translation ?: $title;
    }
}