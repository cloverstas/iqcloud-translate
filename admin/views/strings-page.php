<?php
/**
 * String Translations Page - v5.2.187 with Gettext Scanning
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

$languages = get_option('lingua_languages', array());
$default_lang = get_option('lingua_default_language', 'ru');

// Get all domains for filter
$domains = Lingua_Gettext_Scan::get_all_domains();
$domains_with_counts = Lingua_Gettext_Scan::get_domains_with_counts();

// Current filter
$current_filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
$current_domain = isset($_GET['domain']) ? sanitize_text_field($_GET['domain']) : '';
$current_search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$current_lang = isset($_GET['lang']) ? sanitize_text_field($_GET['lang']) : '';
?>

<div class="wrap">
    <h1><?php esc_html_e('String Translations', 'iqcloud-translate'); ?></h1>

    <div class="lingua-strings-header">
        <p class="description">
            <?php esc_html_e('Scan your themes and plugins for translatable gettext strings. Translate strings used in your website content.', 'iqcloud-translate'); ?>
        </p>

        <!-- Scan Controls -->
        <div class="lingua-scan-controls">
            <button type="button" id="lingua-scan-btn" class="button button-primary">
                <span class="dashicons dashicons-search" style="line-height: 1.3;"></span>
                <?php esc_html_e('Scan for Strings', 'iqcloud-translate'); ?>
            </button>
            <button type="button" id="lingua-rescan-btn" class="button button-secondary">
                <span class="dashicons dashicons-update" style="line-height: 1.3;"></span>
                <?php esc_html_e('Full Rescan', 'iqcloud-translate'); ?>
            </button>
            <span id="lingua-scan-status" style="margin-left: 15px;"></span>
        </div>

        <!-- Progress Bar -->
        <div id="lingua-scan-progress" style="display: none; margin-top: 15px;">
            <div class="lingua-progress-bar">
                <div class="lingua-progress-fill" style="width: 0%;"></div>
            </div>
            <p id="lingua-progress-text"></p>
        </div>
    </div>

    <!-- Filters -->
    <div class="lingua-strings-filters" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccc;">
        <form method="get" action="">
            <input type="hidden" name="page" value="lingua-strings">

            <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                <!-- Source Filter -->
                <div>
                    <label><strong><?php esc_html_e('Source:', 'iqcloud-translate'); ?></strong></label><br>
                    <select name="filter" onchange="this.form.submit()">
                        <option value="all" <?php selected($current_filter, 'all'); ?>><?php esc_html_e('All Strings', 'iqcloud-translate'); ?></option>
                        <option value="gettext" <?php selected($current_filter, 'gettext'); ?>><?php esc_html_e('Gettext (Themes/Plugins)', 'iqcloud-translate'); ?></option>
                        <option value="custom" <?php selected($current_filter, 'custom'); ?>><?php esc_html_e('Custom (Page Content)', 'iqcloud-translate'); ?></option>
                        <option value="email" <?php selected($current_filter, 'email'); ?>><?php esc_html_e('Email Templates', 'iqcloud-translate'); ?></option>
                    </select>
                </div>

                <!-- Domain Filter -->
                <?php if (!empty($domains)): ?>
                <div>
                    <label><strong><?php esc_html_e('Text Domain:', 'iqcloud-translate'); ?></strong></label><br>
                    <select name="domain" onchange="this.form.submit()">
                        <option value=""><?php esc_html_e('All Domains', 'iqcloud-translate'); ?></option>
                        <?php foreach ($domains as $domain): ?>
                            <option value="<?php echo esc_attr($domain); ?>" <?php selected($current_domain, $domain); ?>>
                                <?php echo esc_html($domain); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Language Filter -->
                <div>
                    <label><strong><?php esc_html_e('Language:', 'iqcloud-translate'); ?></strong></label><br>
                    <select name="lang" onchange="this.form.submit()">
                        <option value=""><?php esc_html_e('All Languages', 'iqcloud-translate'); ?></option>
                        <?php foreach ($languages as $code => $lang_data): ?>
                            <?php if ($code !== $default_lang): ?>
                            <?php $lang_name = is_array($lang_data) ? ($lang_data['native'] ?? $lang_data['name'] ?? $code) : $lang_data; ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($current_lang, $code); ?>>
                                <?php echo esc_html($lang_name); ?>
                            </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Search -->
                <div style="flex-grow: 1;">
                    <label><strong><?php esc_html_e('Search:', 'iqcloud-translate'); ?></strong></label><br>
                    <input type="text" name="search" value="<?php echo esc_attr($current_search); ?>"
                           placeholder="<?php esc_html_e('Search strings...', 'iqcloud-translate'); ?>" style="width: 100%;">
                </div>

                <div style="align-self: flex-end;">
                    <button type="submit" class="button"><?php esc_html_e('Filter', 'iqcloud-translate'); ?></button>
                    <a href="<?php echo admin_url('admin.php?page=lingua-strings'); ?>" class="button"><?php esc_html_e('Reset', 'iqcloud-translate'); ?></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Domain Statistics -->
    <?php if (!empty($domains_with_counts)): ?>
    <div class="lingua-domain-stats" style="margin-bottom: 20px;">
        <h3><?php esc_html_e('Text Domains', 'iqcloud-translate'); ?></h3>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <?php foreach ($domains_with_counts as $domain_stat): ?>
                <?php
                $percentage = $domain_stat->string_count > 0
                    ? round(($domain_stat->translated_count / $domain_stat->string_count) * 100)
                    : 0;
                ?>
                <a href="<?php echo admin_url('admin.php?page=lingua-strings&filter=gettext&domain=' . urlencode($domain_stat->gettext_domain)); ?>"
                   class="lingua-domain-badge <?php echo $current_domain === $domain_stat->gettext_domain ? 'active' : ''; ?>">
                    <strong><?php echo esc_html($domain_stat->gettext_domain); ?></strong>
                    <span class="count"><?php echo number_format($domain_stat->string_count); ?></span>
                    <span class="progress" style="background: linear-gradient(to right, #46b450 <?php echo $percentage; ?>%, #ddd <?php echo $percentage; ?>%);">
                        <?php echo $percentage; ?>%
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Strings Table -->
    <?php if (empty($strings)): ?>
        <div class="notice notice-info">
            <p>
                <?php if ($current_filter === 'gettext'): ?>
                    <?php esc_html_e('No gettext strings found. Click "Scan for Strings" to scan your themes and plugins.', 'iqcloud-translate'); ?>
                <?php else: ?>
                    <?php esc_html_e('No strings found matching your filters.', 'iqcloud-translate'); ?>
                <?php endif; ?>
            </p>
        </div>
    <?php else: ?>

        <p class="description">
            <?php printf(esc_html__('Showing %d strings', 'iqcloud-translate'), count($strings)); ?>
        </p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 5%">#</th>
                    <th style="width: 35%"><?php esc_html_e('Original String', 'iqcloud-translate'); ?></th>
                    <th style="width: 20%"><?php esc_html_e('Domain / Context', 'iqcloud-translate'); ?></th>
                    <th style="width: 15%"><?php esc_html_e('Language', 'iqcloud-translate'); ?></th>
                    <th style="width: 15%"><?php esc_html_e('Status', 'iqcloud-translate'); ?></th>
                    <th style="width: 10%"><?php esc_html_e('Actions', 'iqcloud-translate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $counter = 1; foreach ($strings as $string): ?>
                    <tr data-string-id="<?php echo esc_attr($string->id); ?>">
                        <td><?php echo $counter++; ?></td>
                        <td>
                            <div class="string-original" title="<?php echo esc_attr($string->original_text); ?>">
                                <?php echo esc_html(mb_substr($string->original_text, 0, 80)); ?>
                                <?php if (mb_strlen($string->original_text) > 80): ?>...<?php endif; ?>
                            </div>
                            <?php if (!empty($string->translated_text)): ?>
                                <div class="string-translation" style="color: #0073aa; font-style: italic; margin-top: 5px;">
                                    &rarr; <?php echo esc_html(mb_substr($string->translated_text, 0, 80)); ?>
                                    <?php if (mb_strlen($string->translated_text) > 80): ?>...<?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($string->source === 'gettext'): ?>
                                <span class="lingua-badge gettext"><?php echo esc_html($string->gettext_domain ?: 'default'); ?></span>
                            <?php else: ?>
                                <span class="lingua-badge custom"><?php esc_html_e('custom', 'iqcloud-translate'); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($string->is_plural_group)): ?>
                                <span class="lingua-badge plural" title="<?php echo esc_attr($string->original_plural); ?>">
                                    <?php printf(esc_html__('plural (%d forms)', 'iqcloud-translate'), $string->plural_forms_count); ?>
                                </span>
                            <?php endif; ?>
                            <?php if (strpos($string->context, '.email') !== false): ?>
                                <span class="lingua-badge email"><?php esc_html_e('email', 'iqcloud-translate'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="lingua-lang-badge"><?php echo esc_html(strtoupper($string->language_code)); ?></span>
                        </td>
                        <td>
                            <?php if (!empty($string->is_plural_group)): ?>
                                <?php if ($string->plural_forms_translated === $string->plural_forms_count): ?>
                                    <span class="lingua-status translated">
                                        <?php printf(esc_html__('%d/%d translated', 'iqcloud-translate'), $string->plural_forms_translated, $string->plural_forms_count); ?>
                                    </span>
                                <?php elseif ($string->plural_forms_translated > 0): ?>
                                    <span class="lingua-status partial">
                                        <?php printf(esc_html__('%d/%d translated', 'iqcloud-translate'), $string->plural_forms_translated, $string->plural_forms_count); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="lingua-status untranslated">
                                        <?php printf(esc_html__('%d/%d translated', 'iqcloud-translate'), 0, $string->plural_forms_count); ?>
                                    </span>
                                <?php endif; ?>
                            <?php elseif (!empty($string->translated_text)): ?>
                                <span class="lingua-status translated"><?php esc_html_e('Translated', 'iqcloud-translate'); ?></span>
                            <?php else: ?>
                                <span class="lingua-status untranslated"><?php esc_html_e('Untranslated', 'iqcloud-translate'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="button button-small lingua-edit-string-btn"
                                    data-id="<?php echo esc_attr($string->id); ?>"
                                    data-original="<?php echo esc_attr($string->original_text); ?>"
                                    data-translation="<?php echo esc_attr($string->translated_text); ?>"
                                    data-lang="<?php echo esc_attr($string->language_code); ?>"
                                    data-is-plural-group="<?php echo esc_attr(!empty($string->is_plural_group) ? '1' : ''); ?>"
                                    data-plural-forms-count="<?php echo esc_attr($string->plural_forms_count ?? 0); ?>"
                                    data-original-plural="<?php echo esc_attr($string->original_plural ?? ''); ?>"
                                    data-context="<?php echo esc_attr($string->context); ?>"
                                    data-gettext-domain="<?php echo esc_attr($string->gettext_domain ?? ''); ?>">
                                <?php esc_html_e('Edit', 'iqcloud-translate'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if (isset($total_strings) && $total_strings > 100): ?>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
                $total_pages = ceil($total_strings / 100);

                $page_links = paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total' => $total_pages,
                    'current' => $current_page
                ));

                if ($page_links) {
                    echo '<span class="displaying-num">' . sprintf(esc_html__('%d items', 'iqcloud-translate'), $total_strings) . '</span>';
                    echo '<span class="pagination-links">' . $page_links . '</span>';
                }
                ?>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<!-- Edit String Modal -->
<div id="lingua-edit-string-modal" class="lingua-modal" style="display: none;">
    <div class="lingua-modal-overlay"></div>
    <div class="lingua-modal-content">
        <div class="lingua-modal-header">
            <h2><?php esc_html_e('Edit Translation', 'iqcloud-translate'); ?></h2>
            <button type="button" class="lingua-modal-close">&times;</button>
        </div>
        <div class="lingua-modal-body">
            <!-- Regular string editing -->
            <div id="edit-regular-string">
                <div class="lingua-form-group">
                    <label><?php esc_html_e('Original String:', 'iqcloud-translate'); ?></label>
                    <div id="edit-original-text" class="lingua-original-display"></div>
                </div>
                <div class="lingua-form-group">
                    <label for="edit-translation-text"><?php esc_html_e('Translation:', 'iqcloud-translate'); ?></label>
                    <textarea id="edit-translation-text" rows="4" style="width: 100%;" placeholder="<?php esc_html_e('Enter translation...', 'iqcloud-translate'); ?>"></textarea>
                </div>
            </div>

            <!-- Plural forms editing (dynamically populated) -->
            <div id="edit-plural-forms" style="display: none;">
                <div class="lingua-plural-info">
                    <span class="dashicons dashicons-info"></span>
                    <?php esc_html_e('This string has plural forms. Enter translation for each form.', 'iqcloud-translate'); ?>
                </div>
                <div id="plural-forms-container"></div>
            </div>

            <input type="hidden" id="edit-string-id">
            <input type="hidden" id="edit-string-lang">
            <input type="hidden" id="edit-is-plural" value="0">
        </div>
        <div class="lingua-modal-footer">
            <button type="button" class="button lingua-modal-close"><?php esc_html_e('Cancel', 'iqcloud-translate'); ?></button>
            <button type="button" id="lingua-save-string-btn" class="button button-primary"><?php esc_html_e('Save Translation', 'iqcloud-translate'); ?></button>
        </div>
    </div>
</div>

<?php
wp_add_inline_style('lingua-admin', '
.lingua-strings-header {
    background: #fff;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #ccc;
}

.lingua-scan-controls {
    margin-top: 15px;
}

.lingua-progress-bar {
    width: 100%;
    height: 20px;
    background: #ddd;
    border-radius: 10px;
    overflow: hidden;
}

.lingua-progress-fill {
    height: 100%;
    background: linear-gradient(to right, #0073aa, #00a0d2);
    transition: width 0.3s ease;
}

.lingua-domain-badge {
    display: inline-flex;
    flex-direction: column;
    padding: 10px 15px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 5px;
    text-decoration: none;
    color: inherit;
    min-width: 100px;
    text-align: center;
}

.lingua-domain-badge:hover {
    background: #f0f0f0;
    border-color: #0073aa;
}

.lingua-domain-badge.active {
    border-color: #0073aa;
    background: #e7f3ff;
}

.lingua-domain-badge .count {
    font-size: 18px;
    font-weight: bold;
    color: #0073aa;
}

.lingua-domain-badge .progress {
    display: block;
    height: 4px;
    border-radius: 2px;
    margin-top: 5px;
}

.lingua-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 500;
    margin-right: 5px;
}

.lingua-badge.gettext {
    background: #e7f3ff;
    color: #0073aa;
}

.lingua-badge.custom {
    background: #f0f0f0;
    color: #666;
}

.lingua-badge.email {
    background: #fff3cd;
    color: #856404;
}

.lingua-lang-badge {
    display: inline-block;
    padding: 3px 8px;
    background: #f0f0f0;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}

.lingua-status {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
}

.lingua-status.translated {
    background: #d4edda;
    color: #155724;
}

.lingua-status.untranslated {
    background: #f8d7da;
    color: #721c24;
}

.lingua-status.partial {
    background: #fff3cd;
    color: #856404;
}

/* Modal Styles */
.lingua-modal {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 100000;
}

.lingua-modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
}

.lingua-modal-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 5px;
    width: 600px;
    max-width: 90%;
    max-height: 80vh;
    overflow: auto;
    box-shadow: 0 5px 30px rgba(0, 0, 0, 0.3);
}

.lingua-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #ddd;
}

.lingua-modal-header h2 {
    margin: 0;
}

.lingua-modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #666;
}

.lingua-modal-body {
    padding: 20px;
}

.lingua-modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #ddd;
    text-align: right;
}

.lingua-form-group {
    margin-bottom: 15px;
}

.lingua-form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 5px;
}

.lingua-original-display {
    padding: 10px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.string-original {
    word-break: break-word;
}

/* Plural forms styles */
.lingua-plural-info {
    background: #e7f3ff;
    border: 1px solid #0073aa;
    border-radius: 3px;
    padding: 10px;
    margin-bottom: 15px;
    color: #0073aa;
}

.lingua-plural-info .dashicons {
    margin-right: 5px;
}

.lingua-plural-form-group {
    margin-bottom: 20px;
    padding: 15px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 5px;
}

.lingua-plural-form-group .form-label {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}

.lingua-plural-form-group .form-label strong {
    color: #23282d;
}

.lingua-plural-form-group .form-example {
    font-size: 12px;
    color: #666;
    font-style: italic;
}

.lingua-plural-form-group .original-text {
    background: #fff;
    padding: 8px;
    border: 1px solid #e0e0e0;
    border-radius: 3px;
    margin-bottom: 10px;
    font-size: 13px;
    color: #555;
}

.lingua-plural-form-group textarea {
    width: 100%;
    min-height: 60px;
}

.lingua-badge.plural {
    background: #d4edda;
    color: #155724;
}

.lingua-plural-originals {
    background: #f0f0f0;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 10px;
}

.lingua-plural-originals div {
    margin-bottom: 5px;
}

.lingua-plural-originals div:last-child {
    margin-bottom: 0;
}
');
?>

<?php
// Localize data for strings page JavaScript
wp_localize_script('jquery', 'linguaStringsData', array(
    'nonce' => wp_create_nonce('lingua_admin_nonce'),
    'i18n' => array(
        'rescanConfirm'      => __('This will clear all existing gettext strings and rescan from scratch. Continue?', 'iqcloud-translate'),
        'preparingScan'      => __('Preparing scan...', 'iqcloud-translate'),
        'stringsFound'       => __('strings found', 'iqcloud-translate'),
        'scanCompleted'      => __('Scan completed!', 'iqcloud-translate'),
        'scanFailed'         => __('Scan failed:', 'iqcloud-translate'),
        'errorLabel'         => __('Error:', 'iqcloud-translate'),
        'loadingPlural'      => __('Loading plural forms...', 'iqcloud-translate'),
        'singular'           => __('Singular:', 'iqcloud-translate'),
        'plural'             => __('Plural:', 'iqcloud-translate'),
        'enterTranslation'   => __('Enter translation...', 'iqcloud-translate'),
        'saving'             => __('Saving...', 'iqcloud-translate'),
        'saveTranslation'    => __('Save Translation', 'iqcloud-translate'),
        'errorSaving'        => __('Error saving translation:', 'iqcloud-translate'),
        'connectionError'    => __('Connection error', 'iqcloud-translate'),
        'translated'         => __('Translated', 'iqcloud-translate'),
    ),
));

$strings_js = <<<'JSEOF'
jQuery(document).ready(function($) {
    var _sd = window.linguaStringsData || {};
    var _i18n = _sd.i18n || {};
    var scanInProgress = false;

    // Scan button click
    $('#lingua-scan-btn').on('click', function() {
        if (scanInProgress) return;
        startScan(false);
    });

    // Rescan button click
    $('#lingua-rescan-btn').on('click', function() {
        if (scanInProgress) return;
        if (!confirm(_i18n.rescanConfirm)) {
            return;
        }
        startScan(true);
    });

    function startScan(rescan) {
        scanInProgress = true;
        $('#lingua-scan-progress').show();
        $('#lingua-scan-btn, #lingua-rescan-btn').prop('disabled', true);
        $('#lingua-scan-status').text(_i18n.preparingScan);

        doScan(rescan);
    }

    function doScan(rescan) {
        var action = rescan ? 'lingua_rescan_gettext' : 'lingua_scan_gettext';

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: action,
                nonce: _sd.nonce
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;

                    // Update progress bar
                    $('.lingua-progress-fill').css('width', data.progress + '%');
                    $('#lingua-progress-text').text(data.progress_message);
                    $('#lingua-scan-status').text(data.progress_message + ' (' + data.strings_found + ' ' + _i18n.stringsFound + ')');

                    if (data.completed) {
                        scanInProgress = false;
                        $('#lingua-scan-btn, #lingua-rescan-btn').prop('disabled', false);
                        $('#lingua-scan-status').html('<span style="color: green;">' + _i18n.scanCompleted + '</span>');

                        // Reload page after 1 second
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        // Continue scanning
                        doScan(false);
                    }
                } else {
                    scanInProgress = false;
                    $('#lingua-scan-btn, #lingua-rescan-btn').prop('disabled', false);
                    $('#lingua-scan-status').html('<span style="color: red;">' + _i18n.scanFailed + ' ' + (response.data || 'Unknown error') + '</span>');
                }
            },
            error: function(xhr, status, error) {
                scanInProgress = false;
                $('#lingua-scan-btn, #lingua-rescan-btn').prop('disabled', false);
                $('#lingua-scan-status').html('<span style="color: red;">' + _i18n.errorLabel + ' ' + error + '</span>');
            }
        });
    }

    // Edit string modal
    var $modal = $('#lingua-edit-string-modal');

    // v5.2.196: Plural form example numbers like Loco Translate
    // Each form shows example numbers that trigger it: "1, 21, 31", "2, 3, 4", "0, 5, 6"
    var pluralExamples = {
        // Russian/Slavic (3 forms): singular, few, many
        'ru': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
        'uk': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
        'be': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
        'sr': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
        'hr': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
        'bs': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
        // Polish/Czech/Slovak (3 forms)
        'pl': ['1', '2, 3, 4', '0, 5, 6'],
        'cs': ['1', '2, 3, 4', '0, 5, 6'],
        'sk': ['1', '2, 3, 4', '0, 5, 6'],
        // English/Germanic (2 forms): singular, plural
        'en': ['1', '0, 2, 3'],
        'de': ['1', '0, 2, 3'],
        'fr': ['0, 1', '2, 3, 4'],
        'es': ['1', '0, 2, 3'],
        'it': ['1', '0, 2, 3'],
        // Slovenian (4 forms)
        'sl': ['1, 101', '2, 102', '3, 4, 103', '0, 5, 6'],
        // Arabic (6 forms)
        'ar': ['0', '1', '2', '3, 4, 5', '11, 12', '100, 101'],
        // Irish (5 forms)
        'ga': ['1', '2', '3, 4, 5', '7, 8, 9', '11, 12'],
        // Default fallback
        'default': ['1', '0, 2', '3, 4', '5, 6', '11, 12', '100']
    };

    // v5.2.196: Get example numbers as label like Loco Translate
    function getPluralFormLabel(lang, formIndex, totalForms) {
        var examples = pluralExamples[lang] || pluralExamples['default'];
        return examples[formIndex] || formIndex;
    }

    $('.lingua-edit-string-btn').on('click', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var stringId = $btn.data('id');
        var lang = $btn.data('lang');
        var original = $btn.data('original');
        var translation = $btn.data('translation') || '';
        var isPluralGroup = $btn.data('is-plural-group') === '1' || $btn.data('is-plural-group') === 1;
        var pluralFormsCount = parseInt($btn.data('plural-forms-count')) || 0;
        var originalPlural = $btn.data('original-plural');
        var context = $btn.data('context');
        var gettextDomain = $btn.data('gettext-domain');

        console.log('[Lingua Strings] Edit clicked:', {
            stringId: stringId,
            lang: lang,
            original: original,
            isPluralGroup: isPluralGroup,
            pluralFormsCount: pluralFormsCount,
            originalPlural: originalPlural,
            context: context
        });

        $('#edit-string-id').val(stringId);
        $('#edit-string-lang').val(lang);

        // v5.2.191: Simplified plural detection - use is_plural_group flag from PHP
        var isPlural = isPluralGroup && pluralFormsCount > 0;

        console.log('[Lingua Strings] isPlural:', isPlural, 'forms:', pluralFormsCount);

        if (isPlural) {
            // Load all plural forms via AJAX
            $('#edit-regular-string').hide();
            $('#edit-plural-forms').show();
            $('#edit-is-plural').val('1');
            $('#plural-forms-container').html('<p>' + _i18n.loadingPlural + '</p>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'lingua_get_plural_forms',
                    nonce: _sd.nonce,
                    string_id: stringId,
                    language: lang,
                    original: original,
                    original_plural: originalPlural
                },
                success: function(response) {
                    if (response.success && response.data.forms) {
                        var html = '';

                        // Show singular and plural original text at the top
                        html += '<div class="lingua-plural-originals">';
                        html += '<div><strong>' + _i18n.singular + '</strong> ' + original + '</div>';
                        if (originalPlural) {
                            html += '<div><strong>' + _i18n.plural + '</strong> ' + originalPlural + '</div>';
                        }
                        html += '</div>';
                        html += '<hr style="margin: 15px 0;">';

                        // v5.2.196: Labels are now example numbers like Loco ("1, 21, 31")
                        response.data.forms.forEach(function(form, idx) {
                            var formLabel = getPluralFormLabel(lang, idx, response.data.forms.length);
                            html += '<div class="lingua-plural-form-group">';
                            html += '<div class="form-label">';
                            html += '<strong>' + formLabel + '</strong>';
                            html += '</div>';
                            html += '<textarea class="plural-form-textarea" data-form-id="' + form.id + '" data-form-index="' + idx + '" placeholder="' + _i18n.enterTranslation + '">' + (form.translation || '') + '</textarea>';
                            html += '</div>';
                        });

                        $('#plural-forms-container').html(html);
                    } else {
                        // Fallback to regular editing
                        $('#edit-regular-string').show();
                        $('#edit-plural-forms').hide();
                        $('#edit-is-plural').val('0');
                        $('#edit-original-text').text(original);
                        $('#edit-translation-text').val(translation);
                    }
                },
                error: function() {
                    // Fallback to regular editing
                    $('#edit-regular-string').show();
                    $('#edit-plural-forms').hide();
                    $('#edit-is-plural').val('0');
                    $('#edit-original-text').text(original);
                    $('#edit-translation-text').val(translation);
                }
            });
        } else {
            // Regular string
            $('#edit-regular-string').show();
            $('#edit-plural-forms').hide();
            $('#edit-is-plural').val('0');
            $('#edit-original-text').text(original);
            $('#edit-translation-text').val(translation);
        }

        $modal.show();
    });

    $modal.on('click', '.lingua-modal-close, .lingua-modal-overlay', function() {
        $modal.hide();
    });

    // Save translation
    $('#lingua-save-string-btn').on('click', function() {
        var $btn = $(this);
        var isPlural = $('#edit-is-plural').val() === '1';

        $btn.prop('disabled', true).text(_i18n.saving);

        if (isPlural) {
            // Collect all plural form translations
            var forms = [];
            $('.plural-form-textarea').each(function() {
                forms.push({
                    id: $(this).data('form-id'),
                    translation: $(this).val()
                });
            });

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'lingua_save_plural_translations',
                    nonce: _sd.nonce,
                    forms: JSON.stringify(forms)
                },
                success: function(response) {
                    $btn.prop('disabled', false).text(_i18n.saveTranslation);
                    if (response.success) {
                        $modal.hide();
                        location.reload();
                    } else {
                        alert(_i18n.errorSaving + ' ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text(_i18n.saveTranslation);
                    alert(_i18n.connectionError);
                }
            });
        } else {
            // Regular string save
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'lingua_save_string_translation',
                    nonce: _sd.nonce,
                    string_id: $('#edit-string-id').val(),
                    translation: $('#edit-translation-text').val()
                },
                success: function(response) {
                    $btn.prop('disabled', false).text(_i18n.saveTranslation);

                    if (response.success) {
                        $modal.hide();

                        // Update table row
                        var $row = $('tr[data-string-id="' + $('#edit-string-id').val() + '"]');
                        var translation = $('#edit-translation-text').val();

                        if (translation) {
                            $row.find('.string-translation').remove();
                            $row.find('.string-original').after('<div class="string-translation" style="color: #0073aa; font-style: italic; margin-top: 5px;">&rarr; ' + translation.substring(0, 80) + (translation.length > 80 ? '...' : '') + '</div>');
                            $row.find('.lingua-status').removeClass('untranslated').addClass('translated').text(_i18n.translated);
                            $row.find('.lingua-edit-string-btn').data('translation', translation);
                        }
                    } else {
                        alert(_i18n.errorSaving + ' ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text(_i18n.saveTranslation);
                    alert(_i18n.connectionError);
                }
            });
        }
    });
});
JSEOF;
wp_add_inline_script('jquery', $strings_js);
?>
