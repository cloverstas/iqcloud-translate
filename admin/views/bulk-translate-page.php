<?php
/**
 * Bulk Translation Page
 */

if (!defined('ABSPATH')) {
    exit;
}

$languages = get_option('lingua_languages', array());
$default_language = get_option('lingua_default_language', 'ru');

// Remove default language from target options
$target_languages = $languages;
unset($target_languages[$default_language]);

// Get post types
$post_types = get_post_types(array('public' => true), 'objects');
?>

<div class="wrap">
    <h1><?php _e('Bulk Translation', 'iqcloud-translate'); ?></h1>
    
    <?php settings_errors('lingua_bulk'); ?>
    
    <div class="card">
        <h2><?php _e('Auto-translate Multiple Posts', 'iqcloud-translate'); ?></h2>
        <p><?php _e('Select posts and languages for bulk auto-translation. Translations will be processed in the background.', 'iqcloud-translate'); ?></p>
        
        <form method="post" action="">
            <?php wp_nonce_field('lingua_bulk_translate', 'lingua_bulk_nonce'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Post Types', 'iqcloud-translate'); ?></th>
                    <td>
                        <?php foreach ($post_types as $post_type): ?>
                            <label>
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($post_type->name); ?>" />
                                <?php echo esc_html($post_type->label); ?>
                            </label><br />
                        <?php endforeach; ?>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Target Languages', 'iqcloud-translate'); ?></th>
                    <td>
                        <?php if (empty($target_languages)): ?>
                            <p><?php _e('No target languages available. Please configure languages in Settings first.', 'iqcloud-translate'); ?></p>
                        <?php else: ?>
                            <?php foreach ($target_languages as $code => $lang_data): ?>
                                <label>
                                    <input type="checkbox" name="target_languages[]" value="<?php echo esc_attr($code); ?>" />
                                    <?php echo esc_html($lang_data['flag'] . ' ' . $lang_data['name']); ?>
                                </label><br />
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Post Status', 'iqcloud-translate'); ?></th>
                    <td>
                        <select name="post_status">
                            <option value="publish"><?php _e('Published', 'iqcloud-translate'); ?></option>
                            <option value="draft"><?php _e('Draft', 'iqcloud-translate'); ?></option>
                            <option value="any"><?php _e('Any Status', 'iqcloud-translate'); ?></option>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Limit', 'iqcloud-translate'); ?></th>
                    <td>
                        <input type="number" name="limit" value="10" min="1" max="100" />
                        <p class="description"><?php _e('Maximum number of posts to translate (1-100)', 'iqcloud-translate'); ?></p>
                    </td>
                </tr>
            </table>
            
            <?php if (!empty($target_languages)): ?>
                <p class="submit">
                    <input type="submit" name="bulk_translate" class="button-primary" value="<?php _e('Start Bulk Translation', 'iqcloud-translate'); ?>" />
                </p>
            <?php endif; ?>
        </form>
    </div>
    
    <div class="card">
        <h2><?php _e('Translation Status', 'iqcloud-translate'); ?></h2>
        <?php
        // Show translation statistics
        $translation_manager = new Lingua_Translation_Manager();
        $stats = $translation_manager->get_translation_stats();
        ?>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Metric', 'iqcloud-translate'); ?></th>
                    <th><?php _e('Count', 'iqcloud-translate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php _e('Total Translations', 'iqcloud-translate'); ?></td>
                    <td><?php echo intval($stats['total']); ?></td>
                </tr>
                <?php if (!empty($stats['by_language'])): ?>
                    <?php foreach ($stats['by_language'] as $lang => $count): ?>
                        <tr>
                            <td><?php echo sprintf(__('Translations to %s', 'iqcloud-translate'), $lang); ?></td>
                            <td><?php echo intval($count); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if (!empty($stats['by_status'])): ?>
                    <?php foreach ($stats['by_status'] as $status => $count): ?>
                        <tr>
                            <td><?php echo sprintf(__('Status: %s', 'iqcloud-translate'), ucfirst($status)); ?></td>
                            <td><?php echo intval($count); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <h3><?php _e('Recent Translation Jobs', 'iqcloud-translate'); ?></h3>
        <?php
        // Show scheduled cron jobs
        $cron_jobs = _get_cron_array();
        $lingua_jobs = array();
        
        foreach ($cron_jobs as $timestamp => $jobs) {
            if (isset($jobs['lingua_auto_translate_post'])) {
                foreach ($jobs['lingua_auto_translate_post'] as $job) {
                    $lingua_jobs[] = array(
                        'timestamp' => $timestamp,
                        'post_id' => $job['args'][0] ?? 0,
                        'language' => $job['args'][1] ?? 'unknown'
                    );
                }
            }
        }
        ?>
        
        <?php if (empty($lingua_jobs)): ?>
            <p><?php _e('No translation jobs scheduled.', 'iqcloud-translate'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Post ID', 'iqcloud-translate'); ?></th>
                        <th><?php _e('Language', 'iqcloud-translate'); ?></th>
                        <th><?php _e('Scheduled Time', 'iqcloud-translate'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($lingua_jobs, 0, 10) as $job): ?>
                        <tr>
                            <td><?php echo intval($job['post_id']); ?></td>
                            <td><?php echo esc_html($job['language']); ?></td>
                            <td><?php echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $job['timestamp']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>