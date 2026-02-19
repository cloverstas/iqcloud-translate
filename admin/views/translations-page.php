<?php
/**
 * Translations management page template
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

// Initialize translation manager
$translation_manager = new Lingua_Translation_Manager();

// Handle bulk actions
if (isset($_POST['action']) && $_POST['action'] !== '-1') {
    $action = sanitize_text_field($_POST['action']);
    $selected_posts = isset($_POST['selected_posts']) ? array_map('intval', $_POST['selected_posts']) : array();
    
    if (!empty($selected_posts)) {
        switch ($action) {
            case 'bulk_translate':
                // Handle bulk translation
                break;
            case 'bulk_delete':
                // Handle bulk deletion
                break;
        }
    }
}

// Get translation statistics
$stats = $translation_manager->get_translation_stats();

// Get posts for the table
$posts_per_page = 20;
$paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$post_type_filter = isset($_GET['post_type']) ? sanitize_text_field($_GET['post_type']) : 'any';
$language_filter = isset($_GET['language']) ? sanitize_text_field($_GET['language']) : '';

$args = array(
    'post_type' => $post_type_filter === 'any' ? array('post', 'page') : $post_type_filter,
    'post_status' => 'publish',
    'posts_per_page' => $posts_per_page,
    'paged' => $paged,
    'meta_query' => array(
        array(
            'key' => '_lingua_has_translations',
            'compare' => 'EXISTS'
        )
    )
);

$posts_query = new WP_Query($args);

// Get available languages
$languages = get_option('lingua_languages', array());
$default_language = get_option('lingua_default_language', 'ru');
?>

<div class="wrap">
    <h1 class="wp-heading-inline"><?php _e('Manage Translations', 'iqcloud-translate'); ?></h1>
    <a href="#" class="page-title-action" id="bulk-translate-posts"><?php _e('Bulk Translate', 'iqcloud-translate'); ?></a>
    
    <!-- Statistics Cards -->
    <div class="lingua-stats-row">
        <div class="lingua-stat-card">
            <div class="lingua-stat-number"><?php echo esc_html($stats['total']); ?></div>
            <div class="lingua-stat-label"><?php _e('Total Translations', 'iqcloud-translate'); ?></div>
        </div>
        
        <?php foreach ($stats['by_language'] as $lang => $count): ?>
            <?php if (isset($languages[$lang])): ?>
                <div class="lingua-stat-card">
                    <div class="lingua-stat-number"><?php echo esc_html($count); ?></div>
                    <div class="lingua-stat-label">
                        <?php echo isset($languages[$lang]['flag']) ? $languages[$lang]['flag'] : ''; ?>
                        <?php echo esc_html($languages[$lang]['name']); ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="tablenav top">
        <div class="alignleft actions">
            <select name="post_type" id="filter-by-post-type">
                <option value="any" <?php selected($post_type_filter, 'any'); ?>><?php _e('All Post Types', 'iqcloud-translate'); ?></option>
                <option value="post" <?php selected($post_type_filter, 'post'); ?>><?php _e('Posts', 'iqcloud-translate'); ?></option>
                <option value="page" <?php selected($post_type_filter, 'page'); ?>><?php _e('Pages', 'iqcloud-translate'); ?></option>
            </select>
            
            <select name="language" id="filter-by-language">
                <option value=""><?php _e('All Languages', 'iqcloud-translate'); ?></option>
                <?php foreach ($languages as $code => $lang): ?>
                    <option value="<?php echo esc_attr($code); ?>" <?php selected($language_filter, $code); ?>>
                        <?php echo isset($lang['flag']) ? $lang['flag'] . ' ' : ''; ?>
                        <?php echo esc_html($lang['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <button type="button" class="button" id="filter-posts"><?php _e('Filter', 'iqcloud-translate'); ?></button>
        </div>
    </div>

    <!-- Posts Table -->
    <form method="post" id="posts-filter">
        <table class="wp-list-table widefat fixed striped posts">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all" />
                    </td>
                    <th class="manage-column column-title"><?php _e('Title', 'iqcloud-translate'); ?></th>
                    <th class="manage-column column-type"><?php _e('Type', 'iqcloud-translate'); ?></th>
                    <th class="manage-column column-date"><?php _e('Date', 'iqcloud-translate'); ?></th>
                    <th class="manage-column column-translations"><?php _e('Translations', 'iqcloud-translate'); ?></th>
                    <th class="manage-column column-actions"><?php _e('Actions', 'iqcloud-translate'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($posts_query->have_posts()): ?>
                    <?php while ($posts_query->have_posts()): $posts_query->the_post(); ?>
                        <?php
                        $post_id = get_the_ID();
                        $post_translations = $translation_manager->get_post_translations($post_id);
                        $translation_languages = array();
                        foreach ($post_translations as $translation) {
                            $translation_languages[] = $translation->language_code;
                        }
                        ?>
                        <tr>
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="selected_posts[]" value="<?php echo esc_attr($post_id); ?>" />
                            </th>
                            <td class="column-title">
                                <strong>
                                    <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>">
                                        <?php echo esc_html(get_the_title()); ?>
                                    </a>
                                </strong>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>"><?php _e('Edit', 'iqcloud-translate'); ?></a> |
                                    </span>
                                    <span class="view">
                                        <a href="<?php echo esc_url(get_permalink($post_id)); ?>" target="_blank"><?php _e('View', 'iqcloud-translate'); ?></a> |
                                    </span>
                                    <span class="translate">
                                        <a href="#" class="lingua-translate-post" data-post-id="<?php echo esc_attr($post_id); ?>"><?php _e('Translate', 'iqcloud-translate'); ?></a>
                                    </span>
                                </div>
                            </td>
                            <td class="column-type">
                                <?php echo esc_html(get_post_type_object(get_post_type())->labels->singular_name); ?>
                            </td>
                            <td class="column-date">
                                <?php echo esc_html(get_the_date()); ?>
                            </td>
                            <td class="column-translations">
                                <div class="lingua-translation-flags">
                                    <?php foreach ($languages as $code => $lang): ?>
                                        <?php if ($code === $default_language): continue; endif; ?>
                                        <span class="lingua-flag <?php echo in_array($code, $translation_languages) ? 'translated' : 'not-translated'; ?>" 
                                              title="<?php echo esc_attr($lang['name']); ?>">
                                            <?php echo isset($lang['flag']) ? $lang['flag'] : $code; ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="column-actions">
                                <button type="button" class="button button-small lingua-translate-post" data-post-id="<?php echo esc_attr($post_id); ?>">
                                    <?php _e('Translate', 'iqcloud-translate'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    <?php wp_reset_postdata(); ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 50px;">
                            <p><?php _e('No posts found.', 'iqcloud-translate'); ?></p>
                            <p><a href="<?php echo esc_url(admin_url('post-new.php')); ?>" class="button button-primary"><?php _e('Create your first post', 'iqcloud-translate'); ?></a></p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Bulk Actions -->
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <select name="action">
                    <option value="-1"><?php _e('Bulk Actions', 'iqcloud-translate'); ?></option>
                    <option value="bulk_translate"><?php _e('Translate Selected', 'iqcloud-translate'); ?></option>
                    <option value="bulk_delete"><?php _e('Delete Translations', 'iqcloud-translate'); ?></option>
                </select>
                <input type="submit" class="button action" value="<?php _e('Apply', 'iqcloud-translate'); ?>" />
            </div>
        </div>
    </form>

    <!-- Pagination -->
    <?php
    $pagination_args = array(
        'base' => add_query_arg('paged', '%#%'),
        'format' => '',
        'prev_text' => __('&laquo;'),
        'next_text' => __('&raquo;'),
        'total' => $posts_query->max_num_pages,
        'current' => $paged
    );
    $pagination_links = paginate_links($pagination_args);
    if ($pagination_links) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . $pagination_links . '</div></div>';
    }
    ?>
</div>

<style>
.lingua-stats-row {
    display: flex;
    gap: 20px;
    margin: 20px 0;
    flex-wrap: wrap;
}

.lingua-stat-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    min-width: 120px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.lingua-stat-number {
    font-size: 32px;
    font-weight: bold;
    color: #0073aa;
    line-height: 1;
}

.lingua-stat-label {
    margin-top: 8px;
    font-size: 14px;
    color: #666;
}

.lingua-translation-flags {
    display: flex;
    gap: 5px;
    flex-wrap: wrap;
}

.lingua-flag {
    display: inline-block;
    font-size: 18px;
    padding: 2px;
    border-radius: 2px;
    cursor: help;
}

.lingua-flag.translated {
    opacity: 1;
}

.lingua-flag.not-translated {
    opacity: 0.3;
    filter: grayscale(100%);
}

.column-translations {
    width: 120px;
}

.column-actions {
    width: 100px;
}

.column-type {
    width: 80px;
}

.column-date {
    width: 100px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Handle filter button
    $('#filter-posts').on('click', function() {
        var post_type = $('#filter-by-post-type').val();
        var language = $('#filter-by-language').val();
        
        var url = new URL(window.location);
        url.searchParams.set('post_type', post_type);
        url.searchParams.set('language', language);
        url.searchParams.delete('paged');
        
        window.location.href = url.toString();
    });
    
    // Handle select all checkbox
    $('#cb-select-all').on('change', function() {
        $('input[name="selected_posts[]"]').prop('checked', this.checked);
    });
    
    // Handle translate post buttons
    $('.lingua-translate-post').on('click', function(e) {
        e.preventDefault();
        var postId = $(this).data('post-id');
        
        // This will be implemented in the next step with the modal
        alert('Translation modal will open here for post ID: ' + postId);
    });
    
    // Handle bulk translate button
    $('#bulk-translate-posts').on('click', function(e) {
        e.preventDefault();
        alert('Bulk translation functionality will be implemented');
    });
});
</script>