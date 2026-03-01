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
$middleware_api_key = get_option('lingua_middleware_api_key', '');
$default_language = get_option('lingua_default_language', lingua_get_site_language());
$languages = get_option('lingua_languages', array());
$auto_translate_website = get_option('lingua_auto_translate_website', false);

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
    // v5.3: Get API status if Pro is active
    $api_key = get_option('lingua_middleware_api_key', '');
    $show_status_block = !empty($api_key);
    
    if ($show_status_block) {
        $middleware_api = new Lingua_Middleware_API();
        $api_status = $middleware_api->get_api_status();
        
        if (!is_wp_error($api_status) && isset($api_status['isActive']) && $api_status['isActive']): ?>
            <div class="lingua-api-status-block" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; border-radius: 4px;">
                <h2 style="margin-top: 0;"><?php esc_html_e('API Status', 'iqcloud-translate'); ?></h2>
                
                <div class="lingua-status-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
                    <!-- Тариф -->
                    <div class="status-item">
                        <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php esc_html_e('Current Plan', 'iqcloud-translate'); ?></h3>
                        <p style="margin: 0; font-size: 18px; font-weight: 600;">
                            <?php echo esc_html($api_status['plan']['displayName']); ?>
                        </p>
                        <?php if (!empty($api_status['plan']['description'])): ?>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;">
                                <?php echo esc_html($api_status['plan']['description']); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Токены -->
                    <div class="status-item">
                        <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php esc_html_e('Tokens Usage', 'iqcloud-translate'); ?></h3>
                        <p style="margin: 0; font-size: 18px; font-weight: 600;">
                            <?php echo number_format($api_status['tokens']['used']); ?> / <?php echo number_format($api_status['tokens']['limit']); ?>
                        </p>
                        <div style="background: #f0f0f1; height: 8px; border-radius: 4px; margin: 10px 0 0; overflow: hidden;">
                            <div style="background: <?php echo $api_status['tokens']['percentage'] > 80 ? '#dc3232' : ($api_status['tokens']['percentage'] > 50 ? '#ffb900' : '#46b450'); ?>; width: <?php echo min(100, $api_status['tokens']['percentage']); ?>%; height: 100%;"></div>
                        </div>
                        <p style="margin: 5px 0 0; font-size: 12px; color: #666;">
                            <?php echo number_format($api_status['tokens']['remaining']); ?> <?php esc_html_e('tokens remaining', 'iqcloud-translate'); ?> (<?php echo $api_status['tokens']['percentage']; ?>%)
                        </p>
                    </div>

                    <!-- Языки -->
                    <div class="status-item">
                        <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php esc_html_e('Active Languages', 'iqcloud-translate'); ?></h3>
                        <p style="margin: 0; font-size: 18px; font-weight: 600;">
                            <?php 
                            if ($api_status['languages']['unlimited']) {
                                echo '∞ ' . esc_html__('Unlimited', 'iqcloud-translate');
                            } else {
                                echo $api_status['languages']['activeCount'] . ' / ' . $api_status['languages']['maxLanguages'];
                            }
                            ?>
                        </p>
                        <?php if (!empty($api_status['languages']['active'])): ?>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;">
                                <?php echo implode(', ', array_map('strtoupper', $api_status['languages']['active'])); ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <!-- Подписка -->
                    <?php if (!empty($api_status['subscription']['expiresAt'])): ?>
                        <div class="status-item">
                            <h3 style="margin: 0 0 10px 0; font-size: 14px; color: #666;"><?php esc_html_e('Subscription', 'iqcloud-translate'); ?></h3>
                            <?php 
                            $expires_date = new DateTime($api_status['subscription']['expiresAt']);
                            $days_remaining = $api_status['subscription']['daysRemaining'];
                            ?>
                            <p style="margin: 0; font-size: 18px; font-weight: 600;">
                                <?php 
                                if ($days_remaining !== null) {
                                    if ($days_remaining > 0) {
                                        printf(esc_html__('%d days left', 'iqcloud-translate'), $days_remaining);
                                    } else if ($days_remaining === 0) {
                                        echo esc_html__('Expires today', 'iqcloud-translate');
                                    } else {
                                        echo '<span style="color: #dc3232;">' . esc_html__('Expired', 'iqcloud-translate') . '</span>';
                                    }
                                }
                                ?>
                            </p>
                            <p style="margin: 5px 0 0; font-size: 12px; color: #666;">
                                <?php echo $expires_date->format('d.m.Y'); ?>
                                <?php if ($api_status['subscription']['autoRenewal']): ?>
                                    <span style="color: #46b450;">● <?php esc_html_e('Auto-renewal ON', 'iqcloud-translate'); ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($api_status['tokens']['percentage'] > 80): ?>
                    <div class="notice notice-warning inline" style="margin: 15px 0 0;">
                        <p><strong><?php esc_html_e('Warning:', 'iqcloud-translate'); ?></strong> <?php esc_html_e('You are running low on tokens. Consider upgrading your plan or purchasing additional tokens.', 'iqcloud-translate'); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        <?php elseif (is_wp_error($api_status)): ?>
            <div class="notice notice-error" style="margin: 20px 0;">
                <p><strong><?php esc_html_e('API Status Error:', 'iqcloud-translate'); ?></strong> <?php echo esc_html($api_status->get_error_message()); ?></p>
            </div>
        <?php endif;
    }
    ?>

    <!-- v5.3.35: Tab navigation moved outside form to prevent reload issues -->
    <div class="lingua-admin-tabs">
        <nav class="nav-tab-wrapper">
            <a href="#language-settings" class="nav-tab nav-tab-active"><?php esc_html_e('Languages', 'iqcloud-translate'); ?></a>
            <a href="#translation-settings" class="nav-tab"><?php esc_html_e('Auto Translate', 'iqcloud-translate'); ?></a>
            <a href="#switcher-settings" class="nav-tab"><?php esc_html_e('Switcher', 'iqcloud-translate'); ?></a>
            <a href="#api-settings" class="nav-tab"><?php esc_html_e('License', 'iqcloud-translate'); ?></a>
        </nav>

    <form method="post" action="">
        <?php wp_nonce_field('lingua_save_settings', 'lingua_settings_nonce'); ?>
        <input type="hidden" name="active_tab" id="lingua-active-tab" value="language-settings" />

            <!-- License Tab -->
            <div id="api-settings" class="tab-content">
                <?php
                // v5.2.1: Check Pro status - simple check only
                $api_key = get_option('lingua_middleware_api_key', '');

                // Simple presence check (no API calls on page load)
                $credentials_configured = !empty($api_key);

                // Check permanent status (stored forever in wp_options, no expiration)
                // v5.2.64: URL захардкожен в константе LINGUA_MIDDLEWARE_URL
                $cache_key = 'lingua_pro_status_' . md5($api_key . LINGUA_MIDDLEWARE_URL);
                $cached_status = get_option($cache_key, false);
                $is_pro = ($cached_status == 1); // Нестрогое сравнение для совместимости со строкой/числом

                // Debug logging
                lingua_debug_log('[Lingua Settings] API Key present: ' . (!empty($api_key) ? 'yes' : 'no') . ', URL: ' . LINGUA_MIDDLEWARE_URL . ' (hardcoded)');
                lingua_debug_log('[Lingua Settings] Cache key: ' . $cache_key);
                lingua_debug_log('[Lingua Settings] Cached status: ' . var_export($cached_status, true));
                lingua_debug_log('[Lingua Settings] Is Pro: ' . ($is_pro ? 'yes' : 'no'));

                // Get cached error if exists
                $status_error = get_transient('lingua_last_api_error');
                ?>

                <!-- v5.3.35: Added id and data-status for language-independent JS detection -->
                <?php if ($is_pro): ?>
                    <div id="lingua-pro-status-notice" data-status="active" class="notice notice-success inline" style="margin-bottom: 20px;">
                        <p><strong>✅ <?php esc_html_e('Pro Version Active', 'iqcloud-translate'); ?></strong></p>
                        <p><?php esc_html_e('Auto-translation and advanced features are enabled.', 'iqcloud-translate'); ?></p>
                    </div>
                <?php elseif ($credentials_configured && $cached_status == 0): ?>
                    <div id="lingua-pro-status-notice" data-status="failed" class="notice notice-error inline" style="margin-bottom: 20px;">
                        <p><strong>❌ <?php esc_html_e('Activation Failed', 'iqcloud-translate'); ?></strong></p>
                        <p><?php esc_html_e('Please check your API key and try again.', 'iqcloud-translate'); ?></p>
                        <?php if ($status_error): ?>
                            <p><code><?php echo esc_html($status_error); ?></code></p>
                        <?php endif; ?>
                    </div>
                <?php elseif ($credentials_configured): ?>
                    <div id="lingua-pro-status-notice" data-status="pending" class="notice notice-info inline" style="margin-bottom: 20px;">
                        <p><strong>⏳ <?php esc_html_e('Activation Required', 'iqcloud-translate'); ?></strong></p>
                        <p><?php esc_html_e('Click "Activate" to verify your license.', 'iqcloud-translate'); ?></p>
                    </div>
                <?php else: ?>
                    <div id="lingua-pro-status-notice" data-status="none" class="notice notice-warning inline" style="margin-bottom: 20px;">
                        <p><strong>🔒 <?php esc_html_e('Pro Version Not Active', 'iqcloud-translate'); ?></strong></p>
                        <p><?php esc_html_e('Enter your API key and click Activate to unlock Pro features.', 'iqcloud-translate'); ?></p>
                    </div>
                <?php endif; ?>

                <div class="lingua-api-key-section">
                    <label for="middleware_api_key" class="lingua-api-label"><?php esc_html_e('API Key', 'iqcloud-translate'); ?></label>
                    <div class="lingua-api-key-row">
                        <input type="text" id="middleware_api_key" name="middleware_api_key" value="<?php echo esc_attr($middleware_api_key); ?>" class="regular-text" placeholder="<?php esc_html_e('Enter your API key...', 'iqcloud-translate'); ?>" <?php echo $is_pro ? 'readonly' : ''; ?> />
                        <?php if ($is_pro): ?>
                            <button type="button" class="button" id="disconnect-license" style="color: #d63638;">
                                <span class="disconnect-text"><?php esc_html_e('Disconnect', 'iqcloud-translate'); ?></span>
                                <span class="disconnect-spinner" style="display:none;"><span class="spinner is-active" style="float:none;margin:0;"></span></span>
                            </button>
                        <?php else: ?>
                            <button type="button" class="button button-primary" id="activate-license">
                                <span class="activate-text"><?php esc_html_e('Activate', 'iqcloud-translate'); ?></span>
                                <span class="activate-spinner" style="display:none;"><span class="spinner is-active" style="float:none;margin:0;"></span></span>
                            </button>
                        <?php endif; ?>
                    </div>
                    <div id="api-activation-result" style="margin-top: 8px;">
                        <?php if ($is_pro): ?>
                            <span class="success">✓ <?php esc_html_e('License activated', 'iqcloud-translate'); ?></span>
                        <?php elseif ($credentials_configured && $cached_status == 0): ?>
                            <span class="error">✗ <?php esc_html_e('Activation failed - please check your key', 'iqcloud-translate'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

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
                    
                    <?php
                    // v5.2.173: Get language limit from API status
                    // Freemium: 2 languages total (1 default + 1 translation)
                    // Pro: from API or unlimited
                    $language_limit = 2; // Freemium default: 1 default + 1 translation
                    $is_unlimited = false;
                    $is_freemium = true;

                    if (!empty($api_key) && $is_pro) {
                        $middleware_api = new Lingua_Middleware_API();
                        $api_status = $middleware_api->get_api_status();

                        if (!is_wp_error($api_status) && isset($api_status['languages'])) {
                            $is_freemium = false;
                            if (!empty($api_status['languages']['unlimited'])) {
                                $is_unlimited = true;
                            } elseif (isset($api_status['languages']['maxLanguages'])) {
                                $language_limit = intval($api_status['languages']['maxLanguages']);
                            }
                        }
                    }

                    $current_count = count($languages);
                    $can_add_more = $is_unlimited || $current_count < $language_limit;
                    ?>

                    <?php if ($can_add_more): ?>
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
                        <?php if (!$is_unlimited): ?>
                            <span class="description" style="margin-left: 10px;">
                                <?php printf(esc_html__('(%d of %d languages)', 'iqcloud-translate'), $current_count, $language_limit); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="notice notice-warning inline" style="margin-top: 10px;">
                        <p>
                            <?php printf(esc_html__('You have reached your plan limit of %d languages. Upgrade your plan to add more languages.', 'iqcloud-translate'), $language_limit); ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Translation Settings Tab -->
            <div id="translation-settings" class="tab-content">
                <h3><?php esc_html_e('Automatic Translation', 'iqcloud-translate'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Auto-translate Website', 'iqcloud-translate'); ?></th>
                        <td>
                            <fieldset>
                                <label>
                                    <input type="checkbox" name="auto_translate_website" value="1" <?php checked($auto_translate_website); ?> />
                                    <?php esc_html_e('Enable automatic translation for the entire website', 'iqcloud-translate'); ?>
                                </label>
                            </fieldset>
                            <p class="description">
                                <?php esc_html_e('When enabled, new content will be automatically translated to all enabled languages using AI. This includes posts, pages, and dynamic content. Manual translation is always available in the translation modal.', 'iqcloud-translate'); ?>
                                <br><strong><?php esc_html_e('Note:', 'iqcloud-translate'); ?></strong> <?php esc_html_e('This is a Pro feature that requires an active API key.', 'iqcloud-translate'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <!-- v5.3.17: Post Types to Translate -->
                <?php
                $available_post_types = lingua_get_available_post_types();
                $selected_post_types = lingua_get_translatable_post_types();
                ?>

                <div id="lingua-post-types-section" class="lingua-post-types-section" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Post Types to Translate', 'iqcloud-translate'); ?></h3>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php esc_html_e('Select which post types should be included in automatic translation. These types will be translated when using "Translate All Posts".', 'iqcloud-translate'); ?>
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
                                        <span style="color: #666; font-size: 11px; margin-left: 5px;">(<?php echo $count; ?>)</span>
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

                <!-- v5.3.0: Gettext Domains Whitelist -->
                <?php
                $selected_domains = get_option('lingua_auto_translate_domains', array());
                $available_domains = array();

                // Load domains if class exists
                if (class_exists('Lingua_Auto_Translator')) {
                    $available_domains = Lingua_Auto_Translator::get_available_domains();
                }
                ?>

                <div id="lingua-domains-section" class="lingua-domains-section" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Gettext Domains', 'iqcloud-translate'); ?></h3>
                    <p class="description" style="margin-bottom: 15px;">
                        <?php esc_html_e('Select which plugin/theme text domains to auto-translate. Domains with existing .mo translations are marked. Unchecked domains will use their .mo files instead.', 'iqcloud-translate'); ?>
                    </p>

                    <?php if (!empty($available_domains)): ?>
                        <div class="lingua-domains-list" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px;">
                            <?php foreach ($available_domains as $domain => $info): ?>
                                <label class="lingua-domain-item" style="display: flex; align-items: center; padding: 8px 12px; background: <?php echo $info['has_mo'] ? '#f0f7f0' : '#f9f9f9'; ?>; border: 1px solid #ddd; border-radius: 4px; cursor: pointer;">
                                    <input type="checkbox"
                                           name="auto_translate_domains[]"
                                           value="<?php echo esc_attr($domain); ?>"
                                           <?php checked(in_array($domain, $selected_domains)); ?>
                                           style="margin-right: 10px;" />
                                    <span style="flex: 1;">
                                        <strong><?php echo esc_html($info['label']); ?></strong>
                                        <?php if ($info['has_mo']): ?>
                                            <span style="color: #46b450; font-size: 11px; margin-left: 5px;" title="<?php esc_html_e('Has .mo translations', 'iqcloud-translate'); ?>">✓ .mo</span>
                                        <?php endif; ?>
                                    </span>
                                    <span style="color: #666; font-size: 11px;">
                                        <?php echo esc_html($info['source']); ?>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <div style="margin-top: 15px; display: flex; gap: 10px;">
                            <button type="button" class="button" id="lingua-select-all-domains">
                                <?php esc_html_e('Select All', 'iqcloud-translate'); ?>
                            </button>
                            <button type="button" class="button" id="lingua-deselect-all-domains">
                                <?php esc_html_e('Deselect All', 'iqcloud-translate'); ?>
                            </button>
                            <button type="button" class="button" id="lingua-select-no-mo-domains">
                                <?php esc_html_e('Select Without .mo', 'iqcloud-translate'); ?>
                            </button>
                        </div>

                        <?php
                        wp_print_inline_script_tag('
                            jQuery(document).ready(function($) {
                                $("#lingua-select-all-domains").on("click", function() {
                                    $(".lingua-domains-list input[type=\\"checkbox\\"]").prop("checked", true);
                                });
                                $("#lingua-deselect-all-domains").on("click", function() {
                                    $(".lingua-domains-list input[type=\\"checkbox\\"]").prop("checked", false);
                                });
                                $("#lingua-select-no-mo-domains").on("click", function() {
                                    $(".lingua-domains-list input[type=\\"checkbox\\"]").prop("checked", false);
                                    $(".lingua-domain-item").each(function() {
                                        if ($(this).find(".has-mo").length === 0 && $(this).text().indexOf(".mo") === -1) {
                                            $(this).find("input").prop("checked", true);
                                        }
                                    });
                                    $(".lingua-domain-item:not(:has(span[title]))").find("input").prop("checked", true);
                                });
                            });
                        ');
                        ?>
                    <?php else: ?>
                        <p style="color: #666; font-style: italic;">
                            <?php esc_html_e('No gettext domains detected. Domains will appear after the first page load.', 'iqcloud-translate'); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- v5.2.179: Translation Queue Status -->
                <div id="lingua-queue-status" class="lingua-queue-section" style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Translation Status', 'iqcloud-translate'); ?></h3>

                    <div id="lingua-queue-loading" style="text-align: center; padding: 20px;">
                        <span class="spinner is-active" style="float: none;"></span>
                        <?php esc_html_e('Loading status...', 'iqcloud-translate'); ?>
                    </div>

                    <div id="lingua-queue-content" style="display: none;">
                        <!-- Language Progress Bars -->
                        <div id="lingua-language-progress" style="margin-bottom: 20px;"></div>

                        <!-- Queue Stats -->
                        <div id="lingua-queue-stats" style="display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap;">
                            <div class="lingua-stat-box" style="flex: 1; min-width: 120px; padding: 15px; background: #f0f0f1; border-radius: 4px; text-align: center;">
                                <div style="font-size: 24px; font-weight: bold;" id="stat-pending">0</div>
                                <div style="color: #646970;"><?php esc_html_e('Pending', 'iqcloud-translate'); ?></div>
                            </div>
                            <div class="lingua-stat-box" style="flex: 1; min-width: 120px; padding: 15px; background: #f0f0f1; border-radius: 4px; text-align: center;">
                                <div style="font-size: 24px; font-weight: bold;" id="stat-processing">0</div>
                                <div style="color: #646970;"><?php esc_html_e('Processing', 'iqcloud-translate'); ?></div>
                            </div>
                            <div class="lingua-stat-box" style="flex: 1; min-width: 120px; padding: 15px; background: #d4edda; border-radius: 4px; text-align: center;">
                                <div style="font-size: 24px; font-weight: bold; color: #155724;" id="stat-completed">0</div>
                                <div style="color: #155724;"><?php esc_html_e('Completed', 'iqcloud-translate'); ?></div>
                            </div>
                            <div class="lingua-stat-box" style="flex: 1; min-width: 120px; padding: 15px; background: #f8d7da; border-radius: 4px; text-align: center;">
                                <div style="font-size: 24px; font-weight: bold; color: #721c24;" id="stat-failed">0</div>
                                <div style="color: #721c24;"><?php esc_html_e('Failed', 'iqcloud-translate'); ?></div>
                            </div>
                        </div>

                        <!-- Control Buttons -->
                        <div id="lingua-queue-controls" style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <button type="button" id="btn-translate-all" class="button button-primary">
                                <span class="dashicons dashicons-translation" style="vertical-align: middle; margin-right: 5px;"></span>
                                <?php esc_html_e('Translate All Posts', 'iqcloud-translate'); ?>
                            </button>
                            <button type="button" id="btn-pause-queue" class="button" style="display: none;">
                                <span class="dashicons dashicons-controls-pause" style="vertical-align: middle; margin-right: 5px;"></span>
                                <?php esc_html_e('Pause', 'iqcloud-translate'); ?>
                            </button>
                            <button type="button" id="btn-resume-queue" class="button" style="display: none;">
                                <span class="dashicons dashicons-controls-play" style="vertical-align: middle; margin-right: 5px;"></span>
                                <?php esc_html_e('Resume', 'iqcloud-translate'); ?>
                            </button>
                            <button type="button" id="btn-retry-failed" class="button" style="display: none;">
                                <span class="dashicons dashicons-update" style="vertical-align: middle; margin-right: 5px;"></span>
                                <?php esc_html_e('Retry Failed', 'iqcloud-translate'); ?>
                            </button>
                        </div>

                        <!-- Recent Errors -->
                        <div id="lingua-queue-errors" style="display: none; margin-top: 20px;">
                            <h4 style="color: #721c24;"><?php esc_html_e('Recent Errors', 'iqcloud-translate'); ?></h4>
                            <div id="lingua-errors-list" style="background: #f8d7da; padding: 10px; border-radius: 4px; max-height: 150px; overflow-y: auto;"></div>
                        </div>

                        <!-- Processing Status -->
                        <div id="lingua-processing-status" style="display: none; margin-top: 15px; padding: 10px; background: #fff3cd; border-radius: 4px;">
                            <span class="spinner is-active" style="float: none; margin-right: 10px;"></span>
                            <span id="processing-text"><?php esc_html_e('Processing...', 'iqcloud-translate'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- v5.3.20: Debug Mode Setting -->
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
                                                (<?php echo size_format($log_size); ?>)
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

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
.lingua-api-key-section { margin: 15px 0; }
.lingua-api-label { display: block; font-weight: 600; margin-bottom: 8px; font-size: 14px; }
.lingua-api-key-row { display: flex; align-items: center; gap: 8px; }
.lingua-api-key-row #middleware_api_key { flex: 0 0 350px; max-width: 350px; }
.lingua-api-key-row #middleware_api_key[readonly] { background: #f0f0f1; cursor: not-allowed; }
.lingua-api-key-row #activate-license, .lingua-api-key-row #disconnect-license { min-width: 100px; height: 30px; }
.lingua-api-key-row .spinner { margin: 0 !important; }
#api-activation-result { font-weight: 600; font-size: 13px; }
#api-activation-result .success { color: #46b450; }
#api-activation-result .error { color: #dc3232; }
#api-activation-result .retrying { color: #0073aa; }
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
    'middlewareUrl'          => defined('LINGUA_MIDDLEWARE_URL') ? esc_js(LINGUA_MIDDLEWARE_URL) : '',
    'credentialsConfigured'  => !empty($credentials_configured),
    'availableLanguages'     => $langs_with_cc,
    'languageLimit'          => $is_unlimited ? 0 : $language_limit,
    'isUnlimited'            => (bool) $is_unlimited,
    'currentDefault'         => esc_js(get_option('lingua_default_language', lingua_get_site_language())),
    'i18n' => array(
        'licenseExpired'        => __('License Expired or Invalid', 'iqcloud-translate'),
        'reactivateLicense'     => __('Please re-activate your license.', 'iqcloud-translate'),
        'licenseInvalid'        => __('License invalid', 'iqcloud-translate'),
        'pleaseEnterKey'        => __('Please enter your API key', 'iqcloud-translate'),
        'retrying'              => __('Retrying...', 'iqcloud-translate'),
        'licenseActivated'      => __('License activated! Reloading...', 'iqcloud-translate'),
        'activationFailed'      => __('Activation failed', 'iqcloud-translate'),
        'connectionFailed'      => __('Connection failed. Please try again.', 'iqcloud-translate'),
        'failedSaveSettings'    => __('Failed to save settings. Please try again.', 'iqcloud-translate'),
        'disconnectConfirm'     => __('Are you sure you want to disconnect this license?', 'iqcloud-translate'),
        'licenseDisconnected'   => __('License disconnected. Reloading...', 'iqcloud-translate'),
        'failedDisconnect'      => __('Failed to disconnect', 'iqcloud-translate'),
        'connectionError'       => __('Connection error', 'iqcloud-translate'),
        'defaultLabel'          => __('Default', 'iqcloud-translate'),
        'removeLabel'           => __('Remove', 'iqcloud-translate'),
        'langLimitReached'      => sprintf(__('You have reached your plan limit of %d languages. Upgrade your plan to add more languages.', 'iqcloud-translate'), $is_unlimited ? 0 : $language_limit),
        'removeConfirm'         => __('Are you sure you want to remove this language?', 'iqcloud-translate'),
        'warningChangeLang'     => __('WARNING: Changing the default language will invalidate existing translations.', 'iqcloud-translate'),
        'searchLanguages'       => __('Search languages...', 'iqcloud-translate'),
        'searchAndSelect'       => __('Search and select language to add...', 'iqcloud-translate'),
        'translateAllConfirm'   => __('This will add all published posts to the translation queue and start translating. Continue?', 'iqcloud-translate'),
        'populatingQueue'       => __('Populating queue...', 'iqcloud-translate'),
        'translateAllPosts'     => __('Translate All Posts', 'iqcloud-translate'),
        'networkError'          => __('Network error. Please try again.', 'iqcloud-translate'),
        'stop'                  => __('Stop', 'iqcloud-translate'),
        'starting'              => __('Starting...', 'iqcloud-translate'),
        'stopped'               => __('Stopped.', 'iqcloud-translate'),
        'processed'             => __('processed', 'iqcloud-translate'),
        'errors'                => __('errors', 'iqcloud-translate'),
        'pause'                 => __('Pause', 'iqcloud-translate'),
        'translating'           => __('Translating...', 'iqcloud-translate'),
        'done'                  => __('done', 'iqcloud-translate'),
        'complete'              => __('Complete!', 'iqcloud-translate'),
        'itemsTranslated'       => __('items translated', 'iqcloud-translate'),
        'queueEmpty'            => __('Queue is empty', 'iqcloud-translate'),
        'unknownError'          => __('Unknown error', 'iqcloud-translate'),
    ),
);
wp_print_inline_script_tag(
    'var linguaSettingsData = ' . wp_json_encode($lingua_settings_data) . ';'
);

$settings_js = <<<'JSEOF'
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

    // v5.2.169: Auto-check Pro status on page load if API key is configured
    // v5.3.35: Use data attribute instead of text selector to support localization
    if (_sd.credentialsConfigured) {
    (function checkProStatus() {
        var apiKey = $('#middleware_api_key').val();
        if (!apiKey) return;

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'lingua_check_pro_status',
                nonce: _sd.nonce
            },
            success: function(response) {
                if (response.success) {
                    var isPro = response.data.is_pro;
                    // v5.3.35: Use data attribute for language-independent detection
                    var $notice = $('#lingua-pro-status-notice');
                    var $result = $('#api-activation-result');

                    // Check current displayed status via data attribute
                    var currentStatus = $notice.data('status');

                    if (isPro && currentStatus !== 'active') {
                        // Became Pro - reload to show updated UI
                        window.location.reload();
                    } else if (!isPro && currentStatus === 'active') {
                        // Lost Pro status - update UI
                        $notice.removeClass('notice-success').addClass('notice-error')
                            .attr('data-status', 'expired')
                            .html('<p><strong>' + _i18n.licenseExpired + '</strong></p><p>' + _i18n.reactivateLicense + '</p>');
                        $result.html('<span class="error">' + _i18n.licenseInvalid + '</span>');
                    }
                }
            }
        });
    })();
    }

    // v5.2.172: Hide Save Settings on License tab
    function toggleSaveButton() {
        var activeTab = $('.tab-content.active').attr('id');
        if (activeTab === 'api-settings') {
            $('#submit').hide();
        } else {
            $('#submit').show();
        }
    }
    toggleSaveButton(); // Run on load

    // Tab switching - v5.3.35: Added stopPropagation and return false for reliability
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

        // v5.2.130: Save active tab to hidden field
        var tabId = target.replace('#', '');
        $('#lingua-active-tab').val(tabId);

        // v5.2.172: Toggle Save button visibility
        toggleSaveButton();

        return false;
    });

    // v5.2.167: Activate License with retry logic
    $('#activate-license').on('click', function() {
        var $button = $(this);
        var $result = $('#api-activation-result');
        var $text = $button.find('.activate-text');
        var $spinner = $button.find('.activate-spinner');

        var middlewareUrl = _sd.middlewareUrl;
        var apiKey = $('#middleware_api_key').val().trim();

        // Validate API key
        if (!apiKey) {
            $result.html('<span class="error">' + _i18n.pleaseEnterKey + '</span>');
            $('#middleware_api_key').focus();
            return;
        }

        var maxRetries = 3;
        var currentRetry = 0;

        function setLoading(loading) {
            $button.prop('disabled', loading);
            $text.toggle(!loading);
            $spinner.toggle(loading);
        }

        function attemptActivation() {
            currentRetry++;

            if (currentRetry > 1) {
                $result.html('<span class="retrying">' + _i18n.retrying + ' (' + currentRetry + '/' + maxRetries + ')</span>');
            }

            // Step 1: Save API settings
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 15000,
                data: {
                    action: 'lingua_save_api_settings',
                    nonce: _sd.nonce,
                    middleware_url: middlewareUrl,
                    api_key: apiKey
                },
                success: function(saveResponse) {
                    // Step 2: Test connection
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        timeout: 15000,
                        data: {
                            action: 'lingua_test_api',
                            nonce: _sd.nonce,
                            middleware_url: middlewareUrl,
                            api_key: apiKey
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.html('<span class="success">' + _i18n.licenseActivated + '</span>');
                                setTimeout(function() {
                                    window.location.reload();
                                }, 1000);
                            } else {
                                // Check if we should retry
                                if (currentRetry < maxRetries && isRetryableError(response.data)) {
                                    setTimeout(attemptActivation, 1000);
                                } else {
                                    setLoading(false);
                                    $result.html('<span class="error">' + (response.data || _i18n.activationFailed) + '</span>');
                                }
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Activation error:', status, error);
                            // Retry on network errors
                            if (currentRetry < maxRetries) {
                                setTimeout(attemptActivation, 1500);
                            } else {
                                setLoading(false);
                                $result.html('<span class="error">' + _i18n.connectionFailed + '</span>');
                            }
                        }
                    });
                },
                error: function(xhr, status, error) {
                    console.error('Save error:', status, error);
                    if (currentRetry < maxRetries) {
                        setTimeout(attemptActivation, 1500);
                    } else {
                        setLoading(false);
                        $result.html('<span class="error">' + _i18n.failedSaveSettings + '</span>');
                    }
                }
            });
        }

        function isRetryableError(errorMsg) {
            if (!errorMsg) return true;
            var retryable = ['timeout', 'network', 'connection', 'temporarily', '503', '502', '504'];
            var lowerMsg = errorMsg.toLowerCase();
            return retryable.some(function(term) {
                return lowerMsg.indexOf(term) !== -1;
            });
        }

        setLoading(true);
        $result.html('');
        attemptActivation();
    });

    // Allow Enter key to activate
    $('#middleware_api_key').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#activate-license').click();
        }
    });

    // v5.2.171: Disconnect license
    $('#disconnect-license').on('click', function() {
        if (!confirm(_i18n.disconnectConfirm)) {
            return;
        }

        var $button = $(this);
        var $result = $('#api-activation-result');
        var $text = $button.find('.disconnect-text');
        var $spinner = $button.find('.disconnect-spinner');

        $button.prop('disabled', true);
        $text.hide();
        $spinner.show();
        $result.html('');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'lingua_disconnect_license',
                nonce: _sd.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span class="success">' + _i18n.licenseDisconnected + '</span>');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    $text.show();
                    $spinner.hide();
                    $button.prop('disabled', false);
                    $result.html('<span class="error">' + (response.data || _i18n.failedDisconnect) + '</span>');
                }
            },
            error: function() {
                $text.show();
                $spinner.hide();
                $button.prop('disabled', false);
                $result.html('<span class="error">' + _i18n.connectionError + '</span>');
            }
        });
    });

    // Language table management
    // v5.5.2: Add country_code to each language for SVG flag rendering
    var availableLanguages = _sd.availableLanguages;
    var languageLimit = _sd.isUnlimited ? null : _sd.languageLimit; // null = unlimited

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

        // v5.2.133: Check language limit and hide add row if reached
        if (languageLimit !== null && $('.lingua-language-row').length >= languageLimit) {
            $('.lingua-add-language-row').hide();
            $('.lingua-add-language-row').after('<div class="notice notice-warning inline lingua-limit-notice" style="margin-top: 10px;"><p>' + _i18n.langLimitReached + '</p></div>');
        }
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

        // v5.2.133: Show add row if below limit after removal
        if (languageLimit === null || $('.lingua-language-row').length < languageLimit) {
            $('.lingua-limit-notice').remove();
            $('.lingua-add-language-row').show();
        }
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

            // v5.2.133: Check language limit and hide add row if reached
            if (languageLimit !== null && $('.lingua-language-row').length >= languageLimit) {
                $('.lingua-add-language-row').hide();
                $('.lingua-add-language-row').after('<div class="notice notice-warning inline lingua-limit-notice" style="margin-top: 10px;"><p>' + _i18n.langLimitReached + '</p></div>');
            }
        });
    }

    // =========================================================================
    // v5.2.179: Translation Queue Management
    // =========================================================================

    var translateAllBtnHtml = '<span class="dashicons dashicons-translation" style="vertical-align: middle; margin-right: 5px;"></span>' + _i18n.translateAllPosts;

    var linguaQueue = {
        refreshInterval: null,
        isProcessing: false,

        init: function() {
            this.bindEvents();
            this.loadStatus();
        },

        bindEvents: function() {
            var self = this;

            $('#btn-translate-all').on('click', function() {
                self.translateAll();
            });

            $('#btn-pause-queue').on('click', function() {
                if (self.isProcessing) {
                    // Stop AJAX processing
                    self.stopProcessing();
                } else {
                    // Pause cron processing
                    self.pauseQueue();
                }
            });

            $('#btn-resume-queue').on('click', function() {
                // Resume by starting AJAX processing immediately
                self.startProcessing();
            });

            $('#btn-retry-failed').on('click', function() {
                self.retryFailed();
            });
        },

        loadStatus: function() {
            var self = this;

            $.post(ajaxurl, {
                action: 'lingua_get_queue_status',
                nonce: lingua_admin.nonce
            }, function(response) {
                $('#lingua-queue-loading').hide();
                $('#lingua-queue-content').show();

                if (response.success) {
                    self.updateUI(response.data);
                }
            });
        },

        updateUI: function(data) {
            var stats = data.stats;

            // Update stat boxes
            $('#stat-pending').text(stats.pending);
            $('#stat-processing').text(stats.processing);
            $('#stat-completed').text(stats.completed);
            $('#stat-failed').text(stats.failed);

            // Update buttons visibility
            if (stats.pending > 0 || stats.processing > 0) {
                if (data.paused) {
                    $('#btn-pause-queue').hide();
                    $('#btn-resume-queue').show();
                } else {
                    $('#btn-pause-queue').show();
                    $('#btn-resume-queue').hide();
                }
            } else {
                $('#btn-pause-queue').hide();
                $('#btn-resume-queue').hide();
            }

            if (stats.failed > 0) {
                $('#btn-retry-failed').show();
            } else {
                $('#btn-retry-failed').hide();
            }

            // Update language progress bars
            this.updateLanguageProgress(data.by_language);

            // Update errors list
            this.updateErrors(data.recent_errors);

            // Auto-refresh if processing
            if (stats.processing > 0 || (stats.pending > 0 && !data.paused)) {
                this.startAutoRefresh();
            } else {
                this.stopAutoRefresh();
            }
        },

        updateLanguageProgress: function(languages) {
            var html = '';

            for (var code in languages) {
                var lang = languages[code];
                var percentage = lang.percentage || 0;
                var barColor = percentage >= 100 ? '#28a745' : '#007cba';

                html += '<div style="margin-bottom: 10px;">';
                html += '<div style="display: flex; justify-content: space-between; margin-bottom: 5px;">';
                html += '<span>' + lang.flag + ' ' + lang.name + '</span>';
                html += '<span>' + lang.completed + '/' + lang.total_posts + ' (' + percentage + '%)</span>';
                html += '</div>';
                html += '<div style="background: #e0e0e0; border-radius: 4px; height: 8px; overflow: hidden;">';
                html += '<div style="background: ' + barColor + '; height: 100%; width: ' + percentage + '%; transition: width 0.3s;"></div>';
                html += '</div>';

                // Show pending/failed counts
                if (lang.pending > 0 || lang.failed > 0) {
                    html += '<div style="font-size: 12px; color: #666; margin-top: 3px;">';
                    if (lang.pending > 0) {
                        html += '<span style="margin-right: 10px;">' + lang.pending + ' pending</span>';
                    }
                    if (lang.failed > 0) {
                        html += '<span style="color: #dc3545;">' + lang.failed + ' failed</span>';
                    }
                    html += '</div>';
                }

                html += '</div>';
            }

            $('#lingua-language-progress').html(html || '<p style="color: #666;">No target languages configured.</p>');
        },

        updateErrors: function(errors) {
            if (!errors || errors.length === 0) {
                $('#lingua-queue-errors').hide();
                return;
            }

            var html = '';
            for (var i = 0; i < errors.length; i++) {
                var err = errors[i];
                html += '<div style="padding: 5px 0; border-bottom: 1px solid #f5c6cb;">';
                html += '<strong>' + (err.post_title || 'Post #' + err.post_id) + '</strong> ' + err.language_code;
                html += '<br><small>' + err.error_message + '</small>';
                html += '</div>';
            }

            $('#lingua-errors-list').html(html);
            $('#lingua-queue-errors').show();
        },

        translateAll: function() {
            var self = this;

            if (!confirm(_i18n.translateAllConfirm)) {
                return;
            }

            $('#btn-translate-all').prop('disabled', true).text(_i18n.populatingQueue);

            // v5.3.17: Post types are now taken from saved settings
            $.post(ajaxurl, {
                action: 'lingua_translate_all',
                nonce: lingua_admin.nonce
            }, function(response) {
                if (response.success) {
                    // Queue populated, now start AJAX processing
                    $('#btn-translate-all').prop('disabled', false);
                    self.startProcessing();
                } else {
                    $('#btn-translate-all').prop('disabled', false).html(translateAllBtnHtml);
                    alert('Error: ' + (response.data || 'Unknown error'));
                }
            }).fail(function() {
                $('#btn-translate-all').prop('disabled', false).html(translateAllBtnHtml);
                alert(_i18n.networkError);
            });
        },

        pauseQueue: function() {
            var self = this;

            $.post(ajaxurl, {
                action: 'lingua_pause_queue',
                nonce: lingua_admin.nonce
            }, function(response) {
                if (response.success) {
                    self.loadStatus();
                }
            });
        },

        resumeQueue: function() {
            var self = this;

            $.post(ajaxurl, {
                action: 'lingua_resume_queue',
                nonce: lingua_admin.nonce
            }, function(response) {
                if (response.success) {
                    self.loadStatus();
                }
            });
        },

        retryFailed: function() {
            var self = this;

            $.post(ajaxurl, {
                action: 'lingua_retry_failed',
                nonce: lingua_admin.nonce
            }, function(response) {
                if (response.success) {
                    alert(response.data.message);
                    self.loadStatus();
                }
            });
        },

        // v5.2.179: Real-time AJAX processing
        startProcessing: function() {
            var self = this;
            this.isProcessing = true;
            this.processedCount = 0;
            this.errorCount = 0;
            this.lastError = ''; // v5.3.32: Reset error message

            // Hide other buttons, show stop button
            $('#btn-translate-all').hide();
            $('#btn-resume-queue').hide();
            $('#btn-retry-failed').hide();
            $('#btn-pause-queue').show().html(
                '<span class="dashicons dashicons-controls-pause" style="vertical-align: middle; margin-right: 5px;"></span>' + _i18n.stop
            );
            $('#lingua-processing-status').show();
            $('#processing-text').text(_i18n.starting);

            this.processNext();
        },

        stopProcessing: function() {
            this.isProcessing = false;
            var msg = _i18n.stopped + ' ' + this.processedCount + ' ' + _i18n.processed;
            if (this.errorCount > 0) {
                msg += ', ' + this.errorCount + ' ' + _i18n.errors;
                // v5.3.32: Show last error message
                if (this.lastError) {
                    msg += ' (' + this.lastError + ')';
                }
            }
            $('#processing-text').text(msg);

            // Restore buttons after brief delay
            var self = this;
            setTimeout(function() {
                $('#btn-pause-queue').hide().html(
                    '<span class="dashicons dashicons-controls-pause" style="vertical-align: middle; margin-right: 5px;"></span>' + _i18n.pause
                );
                $('#lingua-processing-status').hide();
                $('#btn-translate-all').show().html(translateAllBtnHtml);
                self.loadStatus();
            }, 1500);
        },

        // v5.3.32: Store last error for debugging
        lastError: '',

        processNext: function() {
            var self = this;

            if (!this.isProcessing) {
                this.stopProcessing();
                return;
            }

            var statusText = _i18n.translating + ' (' + this.processedCount + ' ' + _i18n.done;
            if (this.errorCount > 0) {
                statusText += ', ' + this.errorCount + ' ' + _i18n.errors;
                // v5.3.32: Show last error message
                if (this.lastError) {
                    statusText += ' - ' + this.lastError;
                }
            }
            statusText += ')';
            $('#processing-text').text(statusText);

            $.post(ajaxurl, {
                action: 'lingua_process_queue_item',
                nonce: lingua_admin.nonce
            }, function(response) {
                if (response.success) {
                    if (response.data.processed) {
                        self.processedCount++;

                        // Update stats immediately
                        self.updateStatsFromResponse(response.data.stats);

                        // Update progress bars
                        if (response.data.by_language) {
                            self.updateLanguageProgress(response.data.by_language);
                        }

                        // Continue if more items
                        if (response.data.has_more && self.isProcessing) {
                            // Small delay to not overwhelm the server
                            setTimeout(function() {
                                self.processNext();
                            }, 100);
                        } else {
                            // All done
                            self.isProcessing = false;
                            $('#processing-text').text(_i18n.complete + ' ' + self.processedCount + ' ' + _i18n.itemsTranslated);
                            setTimeout(function() {
                                $('#lingua-processing-status').hide();
                                $('#btn-pause-queue').hide();
                                $('#btn-translate-all').show().html(translateAllBtnHtml);
                                self.loadStatus();
                            }, 2000);
                        }
                    } else {
                        // No more items in queue
                        self.isProcessing = false;
                        $('#processing-text').text(response.data.message || _i18n.queueEmpty);
                        setTimeout(function() {
                            $('#lingua-processing-status').hide();
                            $('#btn-pause-queue').hide();
                            $('#btn-translate-all').show().html(translateAllBtnHtml);
                            self.loadStatus();
                        }, 1500);
                    }
                } else {
                    self.errorCount++;
                    // v5.3.37: Extract error message from response object
                    if (response.data && typeof response.data === 'object') {
                        self.lastError = response.data.message || _i18n.unknownError;
                        // Update stats from error response too
                        if (response.data.stats) {
                            self.updateStatsFromResponse(response.data.stats);
                        }
                        if (response.data.by_language) {
                            self.updateLanguageProgress(response.data.by_language);
                        }
                    } else {
                        self.lastError = response.data || _i18n.unknownError;
                    }
                    console.log('Queue item error:', self.lastError);
                    // Continue to next item if there are more
                    if (self.isProcessing && response.data && response.data.has_more) {
                        setTimeout(function() {
                            self.processNext();
                        }, 500);
                    } else if (self.isProcessing) {
                        // No more items, stop processing
                        self.stopProcessing();
                    }
                }
            }).fail(function(xhr, status, error) {
                self.errorCount++;
                // v5.3.32: Store and display AJAX error details
                self.lastError = status + (error ? ': ' + error : '');
                console.log('AJAX fail:', status, error, xhr.responseText);
                if (self.isProcessing) {
                    setTimeout(function() {
                        self.processNext();
                    }, 1000);
                }
            });
        },

        updateStatsFromResponse: function(stats) {
            if (!stats) return;
            $('#stat-pending').text(stats.pending);
            $('#stat-processing').text(stats.processing);
            $('#stat-completed').text(stats.completed);
            $('#stat-failed').text(stats.failed);
        },

        startAutoRefresh: function() {
            var self = this;

            if (this.refreshInterval) return;

            this.refreshInterval = setInterval(function() {
                self.loadStatus();
            }, 5000); // Refresh every 5 seconds
        },

        stopAutoRefresh: function() {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
        }
    };

    // Initialize queue management when on Translation tab
    if ($('#lingua-queue-status').length) {
        linguaQueue.init();
    }
});
JSEOF;
wp_print_inline_script_tag($settings_js);
?>