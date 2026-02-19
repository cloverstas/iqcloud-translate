<?php
/**
 * Translation modal template v2.1.3
 * Updated: Comprehensive unified save system with full WooCommerce support
 * Features: Context grouping, element type display, modern UI, persistent translations
 *
 * @package Lingua
 * @version 2.1.3-unified-save-fix
 */
if (!defined('ABSPATH')) {
    exit;
}

// Debug: Add timestamp to verify this template is loaded
$debug_timestamp = date('Y-m-d H:i:s');
?>

<!-- LINGUA v2.1.3-unified-save-fix TEMPLATE LOADED: <?php echo $debug_timestamp; ?> -->

<div id="lingua-translation-modal" class="lingua-modal" style="display: none;">
    <div class="lingua-modal-content">
        <div class="lingua-resize-handle" title="<?php _e('Drag to resize panel', 'iqcloud-translate'); ?>"></div>
        <div class="lingua-modal-header">
            <h2><?php _e('Translate Content', 'iqcloud-translate'); ?></h2>
            <button type="button" class="lingua-modal-close">&times;</button>
        </div>
        
        <div class="lingua-modal-body">
            <div class="lingua-translation-controls">
                <div class="lingua-language-selector">
                    <div class="lingua-source-lang-display">
                        <label><?php _e('From:', 'iqcloud-translate'); ?></label>
                        <span class="lingua-source-lang-name">
                            <?php 
                            $languages = get_option('lingua_languages', array());
                            $default_lang = get_option('lingua_default_language', 'ru');
                            $default_lang_data = isset($languages[$default_lang]) ? $languages[$default_lang] : array('name' => ucfirst($default_lang), 'flag' => '');
                            ?>
                            <span class="lingua-flag"><?php echo esc_html($default_lang_data['flag'] ?? '🌐'); ?></span>
                            <?php echo esc_html($default_lang_data['name']); ?>
                        </span>
                    </div>
                    
                    <span class="lingua-arrow">→</span>
                    
                    <label for="lingua-target-lang"><?php _e('To:', 'iqcloud-translate'); ?></label>
                    <select id="lingua-target-lang" name="target_lang">
                        <?php
                        foreach ($languages as $code => $data) {
                            if ($code !== $default_lang) {
                                $flag = '[' . strtoupper($code) . '] ';
                                echo '<option value="' . esc_attr($code) . '">' . esc_html($flag . $data['name']) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>

                <!-- Full-width Live Search -->
                <div class="lingua-search-container-full">
                    <input type="text" id="lingua-live-search" placeholder="<?php _e('Search strings...', 'iqcloud-translate'); ?>" class="lingua-search-input-full">
                    <div class="lingua-search-results-info"></div>
                </div>

                <div class="lingua-modal-actions">
                    <button type="button" id="lingua-auto-translate-all" class="button button-secondary">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m5 8 6 6"/>
                            <path d="m4 14 6-6 2-3"/>
                            <path d="M2 5h12"/>
                            <path d="M7 2h1"/>
                            <path d="m22 22-5-10-5 10"/>
                            <path d="M14 18h6"/>
                        </svg>
                        <?php _e('Auto-translate Page', 'iqcloud-translate'); ?>
                    </button>
                    <button type="button" id="lingua-extract-content" class="button button-secondary" title="<?php _e('Re-extract page content', 'iqcloud-translate'); ?>">
                        🔄 <?php _e('Refresh', 'iqcloud-translate'); ?>
                    </button>
                </div>
            </div>
            
            <div class="lingua-content-sections">
                <!-- СКРЫТА в v3.0: SEO Section - Moved to the top -->
                <div class="lingua-section lingua-seo-section" style="display: none !important;">
                    <h3><?php _e('SEO Fields', 'iqcloud-translate'); ?></h3>
                    
                    <!-- SEO Title -->
                    <div class="lingua-translation-pair lingua-seo-field">
                        <div class="lingua-original">
                            <label><?php _e('SEO Title', 'iqcloud-translate'); ?></label>
                            <input type="text" id="lingua-original-seo-title" readonly />
                        </div>
                        <div class="lingua-translated">
                            <label><?php _e('Translation', 'iqcloud-translate'); ?></label>
                            <input type="text" id="lingua-translated-seo-title" />
                            <button type="button" class="lingua-translate-single" data-field="seo_title">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m5 8 6 6"/>
                                    <path d="m4 14 6-6 2-3"/>
                                    <path d="M2 5h12"/>
                                    <path d="M7 2h1"/>
                                    <path d="m22 22-5-10-5 10"/>
                                    <path d="M14 18h6"/>
                                </svg>
                                <?php _e('Translate', 'iqcloud-translate'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <!-- SEO Description -->
                    <div class="lingua-translation-pair lingua-seo-field">
                        <div class="lingua-original">
                            <label><?php _e('SEO Description', 'iqcloud-translate'); ?></label>
                            <textarea id="lingua-original-seo-description" readonly rows="3"></textarea>
                        </div>
                        <div class="lingua-translated">
                            <label><?php _e('Translation', 'iqcloud-translate'); ?></label>
                            <textarea id="lingua-translated-seo-description" rows="3"></textarea>
                            <button type="button" class="lingua-translate-single" data-field="seo_description">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m5 8 6 6"/>
                                    <path d="m4 14 6-6 2-3"/>
                                    <path d="M2 5h12"/>
                                    <path d="M7 2h1"/>
                                    <path d="m22 22-5-10-5 10"/>
                                    <path d="M14 18h6"/>
                                </svg>
                                <?php _e('Translate', 'iqcloud-translate'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- СКРЫТА в v3.0: Title Section -->
                <div class="lingua-section" style="display: none !important;">
                    <h3><?php _e('Title', 'iqcloud-translate'); ?></h3>
                    <div class="lingua-translation-pair">
                        <div class="lingua-original">
                            <label><?php _e('Original', 'iqcloud-translate'); ?></label>
                            <input type="text" id="lingua-original-title" readonly />
                        </div>
                        <div class="lingua-translated">
                            <label><?php _e('Translation', 'iqcloud-translate'); ?></label>
                            <input type="text" id="lingua-translated-title" />
                            <button type="button" class="lingua-translate-single" data-field="title">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m5 8 6 6"/>
                                    <path d="m4 14 6-6 2-3"/>
                                    <path d="M2 5h12"/>
                                    <path d="M7 2h1"/>
                                    <path d="m22 22-5-10-5 10"/>
                                    <path d="M14 18h6"/>
                                </svg>
                                <?php _e('Translate', 'iqcloud-translate'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- WooCommerce Short Description Section -->
                <div class="lingua-section" id="lingua-woo-short-desc-section" style="display: none;">
                    <h3><?php _e('WooCommerce Short Description', 'iqcloud-translate'); ?></h3>
                    <div class="lingua-translation-pair">
                        <div class="lingua-original">
                            <label><?php _e('Original', 'iqcloud-translate'); ?></label>
                            <textarea id="lingua-original-woo-short-desc" readonly rows="3"></textarea>
                        </div>
                        <div class="lingua-translated">
                            <label><?php _e('Translation', 'iqcloud-translate'); ?></label>
                            <textarea id="lingua-translated-woo-short-desc" rows="3"></textarea>
                            <button type="button" class="lingua-translate-single" data-field="woo_short_desc">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="m5 8 6 6"/>
                                    <path d="m4 14 6-6 2-3"/>
                                    <path d="M2 5h12"/>
                                    <path d="M7 2h1"/>
                                    <path d="m22 22-5-10-5 10"/>
                                    <path d="M14 18h6"/>
                                </svg>
                                <?php _e('Translate', 'iqcloud-translate'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- СКРЫТА в v3.0: Excerpt Section -->
                <div class="lingua-section" style="display: none !important;">
                    <h3><?php _e('Excerpt', 'iqcloud-translate'); ?></h3>
                    <div class="lingua-translation-pair">
                        <div class="lingua-original">
                            <label><?php _e('Original', 'iqcloud-translate'); ?></label>
                            <textarea id="lingua-original-excerpt" readonly rows="3"></textarea>
                        </div>
                        <div class="lingua-translated">
                            <label><?php _e('Translation', 'iqcloud-translate'); ?></label>
                            <textarea id="lingua-translated-excerpt" rows="3"></textarea>
                            <button type="button" class="lingua-translate-single" data-field="excerpt">
                                <?php _e('Translate', 'iqcloud-translate'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- v3.2 Tabbed Interface for Translation Content -->
                <div class="lingua-section" id="lingua-tabbed-section">
                    <!-- Tab Navigation -->
                    <div class="lingua-tab-navigation">
                        <button class="lingua-tab-button active" data-tab="seo">
                            <span class="lingua-tab-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <path d="m21 21-4.35-4.35"></path>
                                </svg>
                            </span>
                            <span class="lingua-tab-label"><?php _e('SEO', 'iqcloud-translate'); ?></span>
                            <span class="lingua-tab-count" id="seo-count">0</span>
                        </button>
                        <button class="lingua-tab-button" data-tab="content">
                            <span class="lingua-tab-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                    <line x1="10" y1="9" x2="8" y2="9"></line>
                                </svg>
                            </span>
                            <span class="lingua-tab-label"><?php _e('Page Content', 'iqcloud-translate'); ?></span>
                            <span class="lingua-tab-count" id="content-count">0</span>
                        </button>
                        <button class="lingua-tab-button" data-tab="media">
                            <span class="lingua-tab-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                    <polyline points="21 15 16 10 5 21"></polyline>
                                </svg>
                            </span>
                            <span class="lingua-tab-label"><?php _e('Media', 'iqcloud-translate'); ?></span>
                            <span class="lingua-tab-count" id="media-count">0</span>
                        </button>
                    </div>

                    <!-- Tab Panels -->
                    <div class="lingua-tab-panels">
                        <!-- SEO Tab Panel -->
                        <div class="lingua-tab-panel active" id="lingua-seo-panel" data-tab="seo">
                            <div class="lingua-seo-header">
                                <h4><?php _e('SEO Optimization', 'iqcloud-translate'); ?></h4>
                                <p class="lingua-seo-description"><?php _e('Translate your page title and meta description for better search engine visibility', 'iqcloud-translate'); ?></p>
                            </div>
                            <div id="lingua-seo-content">
                                <!-- SEO fields will be dynamically added here -->
                            </div>
                        </div>

                        <!-- Page Content Tab Panel -->
                        <div class="lingua-tab-panel" id="lingua-content-panel" data-tab="content">
                            <div class="lingua-strings-summary" id="lingua-content-summary" style="display: none;">
                                <strong><?php _e('Extracted Elements:', 'iqcloud-translate'); ?></strong>
                                <span id="lingua-total-content-count">0</span> <?php _e('translatable elements found', 'iqcloud-translate'); ?>
                            </div>
                            <div id="lingua-unified-content">
                                <!-- Page content will be dynamically added here -->
                            </div>
                        </div>

                        <!-- Media Tab Panel -->
                        <div class="lingua-tab-panel" id="lingua-media-panel" data-tab="media">
                            <div class="lingua-media-header">
                                <h4><?php _e('Media Translation', 'iqcloud-translate'); ?></h4>
                                <p class="lingua-media-description"><?php _e('Translate image alt text, captions, and replace images for different languages', 'iqcloud-translate'); ?></p>
                            </div>
                            <div id="lingua-media-content">
                                <!-- Media items will be dynamically added here -->
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- СКРЫТА в v3.0: Meta Fields Section -->
                <div class="lingua-section" id="lingua-meta-section" style="display: none !important;">
                    <h3><?php _e('Meta Fields', 'iqcloud-translate'); ?></h3>
                    <div id="lingua-meta-fields">
                        <!-- Meta fields will be dynamically added here -->
                    </div>
                </div>
            </div>
        </div>
        
        <div class="lingua-modal-footer">
            <div class="lingua-progress">
                <div class="lingua-progress-bar">
                    <div class="lingua-progress-fill" style="width: 0%;"></div>
                </div>
                <span class="lingua-progress-text"><?php _e('Ready to translate', 'iqcloud-translate'); ?></span>
            </div>
            
            <div class="lingua-footer-actions">
                <button type="button" id="lingua-save-translation" class="button button-primary" disabled>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                        <polyline points="17,21 17,13 7,13 7,21"/>
                        <polyline points="7,3 7,8 15,8"/>
                    </svg>
                    <?php _e('Save Translation', 'iqcloud-translate'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Custom Notification -->
<div id="lingua-notification" class="lingua-notification hidden">
    <div class="lingua-notification-content">
        <div class="lingua-notification-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 12l2 2 4-4"/>
                <path d="M21 12c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9 9 4.03 9 9z"/>
            </svg>
        </div>
        <div class="lingua-notification-message">
            Auto-translate all content?
        </div>
        <div class="lingua-notification-actions">
            <button id="lingua-notification-confirm" class="lingua-notification-btn primary">Confirm</button>
            <button id="lingua-notification-cancel" class="lingua-notification-btn secondary">Cancel</button>
        </div>
    </div>
</div>

<!-- Template for content blocks -->
<script type="text/template" id="lingua-content-block-template">
    <div class="lingua-translation-pair lingua-content-block" data-block-id="{block_id}">
        <div class="lingua-original">
            <label><?php _e('Original', 'iqcloud-translate'); ?> #{block_id}</label>
            <div class="lingua-content-preview">{original_content}</div>
            <textarea class="lingua-original-text" readonly>{original_text}</textarea>
        </div>
        <div class="lingua-translated">
            <label><?php _e('Translation', 'iqcloud-translate'); ?> #{block_id}</label>
            <textarea class="lingua-translated-text" data-field="content-{block_id}">{translated_text}</textarea>
            <button type="button" class="lingua-translate-single" data-field="content-{block_id}">
                <?php _e('Translate', 'iqcloud-translate'); ?>
            </button>
        </div>
    </div>
</script>

<!-- Template for meta fields -->
<script type="text/template" id="lingua-meta-field-template">
    <div class="lingua-translation-pair lingua-meta-field" data-meta-key="{meta_key}">
        <div class="lingua-original">
            <label>{meta_label}</label>
            <input type="text" class="lingua-original-meta" value="{original_value}" readonly />
        </div>
        <div class="lingua-translated">
            <label><?php _e('Translation', 'iqcloud-translate'); ?></label>
            <input type="text" class="lingua-translated-meta" data-field="meta-{meta_key}" value="{translated_value}" />
            <button type="button" class="lingua-translate-single" data-field="meta-{meta_key}">
                <?php _e('Translate', 'iqcloud-translate'); ?>
            </button>
        </div>
    </div>
</script>