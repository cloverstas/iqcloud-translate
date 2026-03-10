<?php
/**
 * Settings page template
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current settings
$default_language = get_option('lingua_default_language', lingua_get_site_language());
$languages = get_option('lingua_languages', array());

// v5.2.128: Use centralized language list
$available_languages = Lingua_Languages::get_all();
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Settings saved successfully!', 'iqcloud-translate'); ?></p>
        </div>
    <?php endif; ?>

    <?php
    // Allow Pro add-on to show API status dashboard
    do_action('lingua_settings_before_tabs');
    ?>

    <!-- v5.3.35: Tab navigation moved outside form to prevent reload issues -->
    <div class="lingua-admin-tabs">
        <nav class="nav-tab-wrapper">
            <a href="#language-settings" class="nav-tab nav-tab-active"><?php esc_html_e('Languages', 'iqcloud-translate'); ?></a>
            <a href="#switcher-settings" class="nav-tab"><?php esc_html_e('Switcher', 'iqcloud-translate'); ?></a>
            <?php
            // Allow Pro add-on to add extra tabs
            $extra_tabs = apply_filters('lingua_settings_tabs', array());
            foreach ($extra_tabs as $tab_id => $tab_label) {
                echo '<a href="#' . esc_attr($tab_id) . '" class="nav-tab">' . esc_html($tab_label) . '</a>';
            }
            ?>
        </nav>

    <form method="post" action="">
        <?php wp_nonce_field('lingua_save_settings', 'lingua_settings_nonce'); ?>
        <input type="hidden" name="active_tab" id="lingua-active-tab" value="language-settings" />

            <!-- Language Settings Tab -->
            <div id="language-settings" class="tab-content active">
                <h3><?php esc_html_e('Website Languages', 'iqcloud-translate'); ?></h3>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="default_language"><?php esc_html_e('Default Language', 'iqcloud-translate'); ?></label>
                        </th>
                        <td>
                            <select id="default_language" name="default_language" class="regular-text">
                                <?php foreach ($available_languages as $code => $lang): ?>
                                    <option value="<?php echo esc_attr($code); ?>" data-country-code="<?php echo esc_attr(Lingua_Languages::get_country_code($code)); ?>" <?php selected($default_language, $code); ?>>
                                        <?php echo esc_html($lang['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e('Select the original language of your content.', 'iqcloud-translate'); ?></p>
                            <?php if ($default_language != get_option('lingua_default_language', lingua_get_site_language())): ?>
                            <p class="lingua-warning" style="color: #d63638; font-weight: bold;">
                                <?php esc_html_e('WARNING: Changing the default language will invalidate existing translations.', 'iqcloud-translate'); ?>
                            </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <h3><?php esc_html_e('Translation Languages', 'iqcloud-translate'); ?></h3>
                <p class="description"><?php esc_html_e('Select the languages you wish to make your website available in.', 'iqcloud-translate'); ?></p>
                
                <div class="lingua-languages-table-wrapper">
                    <table id="lingua-languages-table" class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Language', 'iqcloud-translate'); ?></th>
                                <th><?php esc_html_e('Code', 'iqcloud-translate'); ?></th>
                                <th><?php esc_html_e('Slug', 'iqcloud-translate'); ?></th>
                                <th><?php esc_html_e('Action', 'iqcloud-translate'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="lingua-sortable-languages">
                            <?php 
                            // Ensure default language is always in the languages array
                            if (!isset($languages[$default_language])) {
                                $languages[$default_language] = $available_languages[$default_language];
                            }
                            
                            foreach ($languages as $code => $lang): 
                                $is_default = ($code == $default_language);
                            ?>
                            <tr class="lingua-language-row" data-language="<?php echo esc_attr($code); ?>">
                                <td>
                                    <span class="lingua-sortable-handle" style="cursor: move;">☰</span>
                                    <span class="lingua-flag-display"><span class="fi fi-<?php echo esc_attr(Lingua_Languages::get_country_code($code)); ?>"></span></span>
                                    <?php echo esc_html($lang['name']); ?>
                                    <?php if ($is_default): ?>
                                        <span style="color: #666; font-style: italic;">(<?php esc_html_e('Default', 'iqcloud-translate'); ?>)</span>
                                    <?php endif; ?>
                                    <input type="hidden" name="translation_languages[]" value="<?php echo esc_attr($code); ?>" />
                                </td>
                                <td><?php echo esc_html($code); ?></td>
                                <td>
                                    <input type="text" name="url_slugs[<?php echo esc_attr($code); ?>]" 
                                           value="<?php echo esc_attr(substr($code, 0, 2)); ?>" 
                                           class="small-text" 
                                           style="width: 60px;" />
                                </td>
                                <td>
                                    <?php if (!$is_default): ?>
                                        <a href="#" class="lingua-remove-language" style="color: #d63638;"><?php esc_html_e('Remove', 'iqcloud-translate'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="lingua-add-language-row" style="margin-top: 10px;">
                        <select id="lingua-select-language" class="regular-text">
                            <option value=""><?php esc_html_e('Select language to add...', 'iqcloud-translate'); ?></option>
                            <?php foreach ($available_languages as $code => $lang): ?>
                                <?php if (!isset($languages[$code])): ?>
                                <option value="<?php echo esc_attr($code); ?>" data-country-code="<?php echo esc_attr(Lingua_Languages::get_country_code($code)); ?>">
                                    <?php echo esc_html($lang['name']); ?>
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="lingua-add-language" class="button"><?php esc_html_e('Add Language', 'iqcloud-translate'); ?></button>
                    </div>
                </div>

                <!-- Post Types to Translate -->
                <?php
                $available_post_types = lingua_get_available_post_types();
                $selected_post_types = lingua_get_translatable_post_types();
                ?>

                <div id="lingua-post-types-section" class="lingua-post-types-section" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Post Types to Translate', 'iqcloud-translate'); ?></h3>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php esc_html_e('Select which post types should be available for translation.', 'iqcloud-translate'); ?>
                    </p>

                    <?php if (!empty($available_post_types)): ?>
                        <div class="lingua-post-types-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px;">
                            <?php foreach ($available_post_types as $name => $type): ?>
                                <?php
                                $post_count = wp_count_posts($name);
                                $count = isset($post_count->publish) ? (int)$post_count->publish : 0;
                                ?>
                                <label class="lingua-post-type-item" style="display: flex; align-items: center; padding: 8px 12px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">
                                    <input type="checkbox"
                                           name="translatable_post_types[]"
                                           value="<?php echo esc_attr($name); ?>"
                                           <?php checked(in_array($name, $selected_post_types)); ?>
                                           style="margin-right: 10px;" />
                                    <span style="flex: 1;">
                                        <strong><?php echo esc_html($type->labels->singular_name); ?></strong>
                                        <span style="color: #666; font-size: 11px; margin-left: 5px;">(<?php echo esc_html($count); ?>)</span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top: 15px; display: flex; gap: 10px;">
                            <button type="button" class="button" id="lingua-select-all-types">
                                <?php esc_html_e('Select All', 'iqcloud-translate'); ?>
                            </button>
                            <button type="button" class="button" id="lingua-deselect-all-types">
                                <?php esc_html_e('Deselect All', 'iqcloud-translate'); ?>
                            </button>
                        </div>

                        <?php
                        wp_print_inline_script_tag('
                            jQuery(document).ready(function($) {
                                $("#lingua-select-all-types").on("click", function() {
                                    $(".lingua-post-types-list input[type=\\"checkbox\\"]").prop("checked", true);
                                });
                                $("#lingua-deselect-all-types").on("click", function() {
                                    $(".lingua-post-types-list input[type=\\"checkbox\\"]").prop("checked", false);
                                });
                            });
                        ');
                        ?>
                    <?php else: ?>
                        <p><?php esc_html_e('No post types available.', 'iqcloud-translate'); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Debug Mode Setting -->
                <?php $debug_mode = get_option('lingua_debug_mode', false); ?>
                <div id="lingua-debug-section" class="lingua-debug-section" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Debug Mode', 'iqcloud-translate'); ?></h3>
                    <table class="form-table" style="margin: 0;">
                        <tr>
                            <th scope="row"><?php esc_html_e('Enable Debug Logging', 'iqcloud-translate'); ?></th>
                            <td>
                                <fieldset>
                                    <label>
                                        <input type="checkbox" name="debug_mode" value="1" <?php checked($debug_mode); ?> />
                                        <?php esc_html_e('Write debug logs to wp-content/debug.log', 'iqcloud-translate'); ?>
                                    </label>
                                </fieldset>
                                <p class="description">
                                    <?php esc_html_e('Enable this only when troubleshooting issues. Debug logging may slow down your site and create large log files.', 'iqcloud-translate'); ?>
                                    <?php if (defined('LINGUA_DEBUG')): ?>
                                        <br><strong style="color: #d63638;"><?php esc_html_e('Note: LINGUA_DEBUG constant is defined in wp-config.php and takes priority over this setting.', 'iqcloud-translate'); ?></strong>
                                    <?php endif; ?>
                                </p>
                                <?php if ($debug_mode || (defined('LINGUA_DEBUG') && LINGUA_DEBUG)): ?>
                                    <p style="margin-top: 10px;">
                                        <code style="background: #f0f0f1; padding: 5px 10px; display: inline-block;">
                                            <?php echo esc_html(WP_CONTENT_DIR . '/debug.log'); ?>
                                        </code>
                                        <?php if (file_exists(WP_CONTENT_DIR . '/debug.log')): ?>
                                            <?php $log_size = filesize(WP_CONTENT_DIR . '/debug.log'); ?>
                                            <span style="color: #666; margin-left: 10px;">
                                                (<?php echo esc_html(size_format($log_size)); ?>)
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <?php
            // Allow Pro add-on to add tab content
            do_action('lingua_settings_tab_content');
            ?>

            <!-- Language Switcher Tab -->
            <div id="switcher-settings" class="tab-content">
                <h3><?php esc_html_e('Display Format', 'iqcloud-translate'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Language Switcher Format', 'iqcloud-translate'); ?></th>
                        <td>
                            <?php 
                            $switcher_format = get_option('lingua_switcher_format', 'flags_full');
                            ?>
                            <fieldset>
                                <label>
                                    <input type="radio" name="switcher_format" value="flags_full" <?php checked($switcher_format, 'flags_full'); ?> />
                                    <span class="fi fi-us"></span> English, <span class="fi fi-ru"></span> Русский - <?php esc_html_e('Flags with Full Language Names', 'iqcloud-translate'); ?>
                                </label><br><br>
                                <label>
                                    <input type="radio" name="switcher_format" value="full" <?php checked($switcher_format, 'full'); ?> />
                                    English, Русский - <?php esc_html_e('Full Language Names', 'iqcloud-translate'); ?>
                                </label><br><br>
                                <label>
                                    <input type="radio" name="switcher_format" value="short" <?php checked($switcher_format, 'short'); ?> />
                                    EN, RU - <?php esc_html_e('Short Language Names', 'iqcloud-translate'); ?>
                                </label><br><br>
                                <label>
                                    <input type="radio" name="switcher_format" value="flags_short" <?php checked($switcher_format, 'flags_short'); ?> />
                                    <span class="fi fi-us"></span> EN, <span class="fi fi-ru"></span> RU - <?php esc_html_e('Flags with Short Language Names', 'iqcloud-translate'); ?>
                                </label><br><br>
                                <label>
                                    <input type="radio" name="switcher_format" value="flags_only" <?php checked($switcher_format, 'flags_only'); ?> />
                                    <span class="fi fi-us"></span> <span class="fi fi-ru"></span> - <?php esc_html_e('Only Flags', 'iqcloud-translate'); ?>
                                </label>
                            </fieldset>
                            <p class="description"><?php esc_html_e('Choose how language names are displayed in all language switchers (menu, shortcode, floating).', 'iqcloud-translate'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Advanced Options', 'iqcloud-translate'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Display Options', 'iqcloud-translate'); ?></th>
                        <td>
                            <?php
                            $native_names = get_option('lingua_native_names', false);
                            ?>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="native_names" value="1" <?php checked($native_names); ?> />
                                    <?php esc_html_e('Use native language names', 'iqcloud-translate'); ?>
                                    <span class="description">(English, Русский, Français)</span>
                                </label>
                            </fieldset>
                            <p class="description"><?php esc_html_e('When enabled, language names will be shown in their native language instead of the current site language.', 'iqcloud-translate'); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- v5.2.178: Removed non-functional switcher options (Shortcode, Floating) -->
                <!-- Menu Integration is always enabled by default -->
            </div>

        <?php submit_button(__('Save Settings', 'iqcloud-translate')); ?>
    </form>
    </div><!-- /.lingua-admin-tabs -->
</div><!-- /.wrap -->

<?php
// v5.5: Use wp_add_inline_style() instead of inline <style> tag (WP review compliance)
$settings_css = '
.lingua-admin-tabs .nav-tab-wrapper { margin-bottom: 20px; }
.lingua-admin-tabs .tab-content { display: none; }
.lingua-admin-tabs .tab-content.active { display: block; }
.lingua-languages-table-wrapper { margin-top: 20px; }
#lingua-languages-table { margin-top: 10px; }
#lingua-languages-table th { font-weight: 600; }
.lingua-language-row { background: #fff; }
.lingua-language-row:hover { background: #f6f7f7; }
.lingua-sortable-handle { color: #999; margin-right: 10px; }
.lingua-add-language-row { padding: 10px; background: #f0f0f1; border: 1px solid #c3c4c7; }
.lingua-warning { animation: pulse 2s infinite; }
@keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }
.lingua-flag-display { font-size: 18px; margin-right: 8px; display: inline-block; min-width: 25px; }
.lingua-flag-img { width: 16px; height: 12px; margin-right: 5px; vertical-align: middle; }
.language-flag { font-size: 16px; margin-right: 8px; }
.language-name { font-weight: 500; margin-right: 5px; }
.language-native { color: #666; font-style: italic; }
.required { color: red; }
.optional { color: #666; font-weight: normal; }
';
wp_add_inline_style('lingua-admin', $settings_css);
?>

<?php
// Prepare localized data for settings page JavaScript
$langs_with_cc = $available_languages;
foreach ($langs_with_cc as $code => &$lang_data) {
    $lang_data['country_code'] = Lingua_Languages::get_country_code($code);
}
unset($lang_data);

$lingua_settings_data = array(
    'nonce'                  => wp_create_nonce('lingua_admin_nonce'),
    'availableLanguages'     => $langs_with_cc,
    'currentDefault'         => esc_js(get_option('lingua_default_language', lingua_get_site_language())),
    'i18n' => array(
        'defaultLabel'          => __('Default', 'iqcloud-translate'),
        'removeLabel'           => __('Remove', 'iqcloud-translate'),
        'removeConfirm'         => __('Are you sure you want to remove this language?', 'iqcloud-translate'),
        'warningChangeLang'     => __('WARNING: Changing the default language will invalidate existing translations.', 'iqcloud-translate'),
        'searchLanguages'       => __('Search languages...', 'iqcloud-translate'),
        'searchAndSelect'       => __('Search and select language to add...', 'iqcloud-translate'),
    ),
);
wp_print_inline_script_tag(
    'var linguaSettingsData = ' . wp_json_encode($lingua_settings_data) . ';'
);

ob_start();
?>
jQuery(document).ready(function($) {
    var _sd = window.linguaSettingsData || {};
    var _i18n = _sd.i18n || {};

    // v5.2.130: Restore active tab from URL parameter
    var urlParams = new URLSearchParams(window.location.search);
    var savedTab = urlParams.get('tab');
    if (savedTab) {
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        $('a[href="#' + savedTab + '"]').addClass('nav-tab-active');
        $('#' + savedTab).addClass('active');
        $('#lingua-active-tab').val(savedTab);
    }

    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        // Remove active class from all tabs and content
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');

        // Add active class to clicked tab
        $(this).addClass('nav-tab-active');

        // Show corresponding content
        var target = $(this).attr('href');
        $(target).addClass('active');

        // Save active tab to hidden field
        var tabId = target.replace('#', '');
        $('#lingua-active-tab').val(tabId);

        return false;
    });

    // Language table management
    // v5.5.2: Add country_code to each language for SVG flag rendering
    var availableLanguages = _sd.availableLanguages;

    // Add language functionality
    $('#lingua-add-language').on('click', function() {
        var selectedCode = $('#lingua-select-language').val();
        if (!selectedCode) return;

        var language = availableLanguages[selectedCode];
        var isDefault = selectedCode === $('#default_language').val();

        var newRow = '<tr class="lingua-language-row" data-language="' + selectedCode + '">' +
            '<td>' +
                '<span class="lingua-sortable-handle" style="cursor: move;">&#9776;</span>' +
                '<span class="lingua-flag-display"><span class="fi fi-' + (language.country_code || selectedCode) + '"></span></span> ' + language.name +
                (isDefault ? ' <span style="color: #666; font-style: italic;">(' + _i18n.defaultLabel + ')</span>' : '') +
                '<input type="hidden" name="translation_languages[]" value="' + selectedCode + '" />' +
            '</td>' +
            '<td>' + selectedCode + '</td>' +
            '<td>' +
                '<input type="text" name="url_slugs[' + selectedCode + ']" value="' + selectedCode.substring(0, 2) + '" class="small-text" style="width: 60px;" />' +
            '</td>' +
            '<td>' +
                (isDefault ? '' : '<a href="#" class="lingua-remove-language" style="color: #d63638;">' + _i18n.removeLabel + '</a>') +
            '</td>' +
        '</tr>';

        $('#lingua-sortable-languages').append(newRow);

        // Remove from select and reset
        $('#lingua-select-language option[value="' + selectedCode + '"]').remove();
        $('#lingua-select-language').val('');
    });

    // Remove language functionality
    $(document).on('click', '.lingua-remove-language', function(e) {
        e.preventDefault();

        if (!confirm(_i18n.removeConfirm)) {
            return;
        }

        var $row = $(this).closest('.lingua-language-row');
        var langCode = $row.data('language');
        var language = availableLanguages[langCode];

        // Add back to select
        var flagText = '[' + langCode.toUpperCase() + ']';
        var $option = $('<option value="' + langCode + '">' + flagText + ' ' + language.name + '</option>');
        $('#lingua-select-language').append($option);

        // Sort options
        var $options = $('#lingua-select-language option');
        $options.sort(function(a, b) {
            if (a.value === '') return -1;
            if (b.value === '') return 1;
            return a.text.localeCompare(b.text);
        });
        $('#lingua-select-language').html($options);

        // Remove row
        $row.remove();
    });

    // Update default language warning
    $('#default_language').on('change', function() {
        var currentDefault = _sd.currentDefault;
        var newDefault = $(this).val();

        if (newDefault !== currentDefault) {
            if (!$('.lingua-warning').length) {
                $(this).closest('td').append('<p class="lingua-warning" style="color: #d63638; font-weight: bold;">' + _i18n.warningChangeLang + '</p>');
            }
        } else {
            $('.lingua-warning').remove();
        }

        // Update table to reflect new default
        $('.lingua-language-row').each(function() {
            var $row = $(this);
            var langCode = $row.data('language');
            var isNewDefault = langCode === newDefault;

            // Update default label
            var $firstTd = $row.find('td:first');
            var content = $firstTd.html();

            if (isNewDefault && content.indexOf('(Default)') === -1) {
                content = content.replace('</span>', '</span> <span style="color: #666; font-style: italic;">(' + _i18n.defaultLabel + ')</span>');
            } else if (!isNewDefault) {
                content = content.replace(/ <span style="color: #666; font-style: italic;">\(.*?\)<\/span>/, '');
            }

            $firstTd.html(content);

            // Update remove button
            var $lastTd = $row.find('td:last');
            if (isNewDefault) {
                $lastTd.html('');
            } else if ($lastTd.find('.lingua-remove-language').length === 0) {
                $lastTd.html('<a href="#" class="lingua-remove-language" style="color: #d63638;">' + _i18n.removeLabel + '</a>');
            }
        });

        // Ensure new default is in the table
        if ($('.lingua-language-row[data-language="' + newDefault + '"]').length === 0) {
            $('#lingua-select-language').val(newDefault);
            $('#lingua-add-language').click();
        }
    });

    // Make language table sortable (if jQuery UI is available)
    if ($.fn.sortable) {
        $('#lingua-sortable-languages').sortable({
            handle: '.lingua-sortable-handle',
            update: function(event, ui) {
                // Languages order updated
            }
        });
    }

    // v5.2.129: Initialize Select2 for language selects with search
    // v5.5.2: Add SVG flag icons to Select2 dropdowns
    if ($.fn.select2) {
        // Helper: render option with SVG flag
        function linguaFormatLanguage(option) {
            if (!option.id) return option.text; // placeholder
            var countryCode = $(option.element).data('country-code') || option.id;
            return $('<span><span class="fi fi-' + countryCode + '" style="margin-right: 6px;"></span>' + option.text + '</span>');
        }

        $('#default_language').select2({
            placeholder: _i18n.searchLanguages,
            allowClear: false,
            width: '300px',
            templateResult: linguaFormatLanguage,
            templateSelection: linguaFormatLanguage
        });

        $('#lingua-select-language').select2({
            placeholder: _i18n.searchAndSelect,
            allowClear: true,
            width: '300px',
            templateResult: linguaFormatLanguage,
            templateSelection: linguaFormatLanguage
        });

        // Fix: When adding language, reinitialize Select2 after DOM update
        var originalAddHandler = $('#lingua-add-language').data('events');
        $('#lingua-add-language').off('click').on('click', function() {
            var selectedCode = $('#lingua-select-language').val();
            if (!selectedCode) return;

            var language = availableLanguages[selectedCode];
            var isDefault = selectedCode === $('#default_language').val();

            var newRow = '<tr class="lingua-language-row" data-language="' + selectedCode + '">' +
                '<td>' +
                    '<span class="lingua-sortable-handle" style="cursor: move;">&#9776;</span>' +
                    '<span class="lingua-flag-display"><span class="fi fi-' + (language.country_code || selectedCode) + '"></span></span> ' + language.name +
                    (isDefault ? ' <span style="color: #666; font-style: italic;">(' + _i18n.defaultLabel + ')</span>' : '') +
                    '<input type="hidden" name="translation_languages[]" value="' + selectedCode + '" />' +
                '</td>' +
                '<td>' + selectedCode + '</td>' +
                '<td>' +
                    '<input type="text" name="url_slugs[' + selectedCode + ']" value="' + selectedCode.substring(0, 2) + '" class="small-text" style="width: 60px;" />' +
                '</td>' +
                '<td>' +
                    (isDefault ? '' : '<a href="#" class="lingua-remove-language" style="color: #d63638;">' + _i18n.removeLabel + '</a>') +
                '</td>' +
            '</tr>';

            $('#lingua-sortable-languages').append(newRow);

            // Remove option from Select2
            $('#lingua-select-language option[value="' + selectedCode + '"]').remove();
            $('#lingua-select-language').val(null).trigger('change');
        });
    }

});
<?php
$settings_js = ob_get_clean();
wp_print_inline_script_tag($settings_js);
?>