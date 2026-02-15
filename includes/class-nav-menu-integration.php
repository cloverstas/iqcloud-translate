<?php
/**
 * Navigation Menu Integration for Language Switcher
 * Language switcher integration for WordPress nav menus
 *
 * @package Lingua
 * @version 5.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Nav_Menu_Integration {

    private $url_rewriter;

    public function __construct() {
        add_action('init', array($this, 'register_lingua_menu_switcher'));
        add_filter('get_user_option_metaboxhidden_nav-menus', array($this, 'cpt_always_visible_in_menus'), 10, 3);
        add_filter('wp_get_nav_menu_items', array($this, 'lingua_menu_permalinks'), 10, 3);

        // Update language switcher posts when settings change
        add_action('update_option_lingua_languages', array($this, 'update_lingua_menu_items'));
        add_action('update_option_lingua_default_language', array($this, 'update_lingua_menu_items'));
    }

    /**
     * Register Custom Post Type for Language Switcher
     *register_ls_menu_switcher()
     */
    public function register_lingua_menu_switcher() {
        $args = array(
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'show_ui'               => true,
            'show_in_nav_menus'     => true,    // CRITICAL: Makes it available in menu UI
            'show_in_menu'          => false,
            'show_in_admin_bar'     => false,
            'can_export'            => false,
            'public'                => false,
            'label'                 => __('Language Switcher', 'yourtranslater')
        );
        register_post_type('lingua_switcher', $args);

        // v5.3.39: Only update menu items in admin context (not on every page load!)
        // This was causing 502 errors due to wp_update_post triggering save_post -> clear_page_cache
        // on EVERY request, resulting in heavy DB queries
        if (is_admin() || (defined('DOING_CRON') && DOING_CRON)) {
            $this->update_lingua_menu_items();
        }
    }

    /**
     * Keep CPT always visible in menu admin
     *cpt_always_visible_in_menus()
     */
    public function cpt_always_visible_in_menus( $result, $option, $user ) {
        $key = 'add-post-type-lingua_switcher';
        if( is_array($result) && in_array( $key, $result ) ) {
            $result = array_diff( $result, array( $key ) );
        }
        return $result;
    }

    /**
     * Create/Update Language Switcher Posts
     * Update language switcher menu items
     */
    public function update_lingua_menu_items() {
        $languages = get_option('lingua_languages', array());
        $default_language = get_option('lingua_default_language', 'ru');

        // v5.4.0: Only add the default language as fallback (not ALL languages)
        // Previously this added 100+ languages which cluttered the menu switcher
        if (empty($languages) || !isset($languages[$default_language])) {
            $available_languages = Lingua_Languages::get_all();

            // Add default language if missing
            if (!isset($languages[$default_language]) && isset($available_languages[$default_language])) {
                $languages[$default_language] = $available_languages[$default_language];
            }

            lingua_debug_log('[Lingua Nav Menu v5.4.0] Added fallback default language: ' . $default_language);
        }

        // Build language names array
        $published_languages = array();
        foreach ($languages as $code => $lang) {
            $published_languages[$code] = $lang['name'];
        }

        // Add "Current Language" placeholder
        $published_languages['current_language'] = __('Current Language', 'yourtranslater');
        $language_codes = array_keys($published_languages);

        // Get existing posts
        $posts = get_posts( array(
            'post_type' => 'lingua_switcher',
            'posts_per_page' => -1,
            'post_status' => 'any'
        ));

        // v5.4.0: Clean up duplicates first — group posts by post_content
        $posts_by_content = array();
        foreach ( $posts as $post ) {
            $content = $post->post_content;
            if ( !isset($posts_by_content[$content]) ) {
                $posts_by_content[$content] = array();
            }
            $posts_by_content[$content][] = $post;
        }

        // Delete duplicate posts (keep only the first one per language code)
        foreach ( $posts_by_content as $content => $content_posts ) {
            if ( count($content_posts) > 1 ) {
                lingua_debug_log('[Lingua Nav Menu v5.4.0] Found ' . count($content_posts) . ' duplicates for: ' . $content);
                for ( $i = 1; $i < count($content_posts); $i++ ) {
                    wp_delete_post( $content_posts[$i]->ID, true );
                }
            }
        }

        // Update or create posts (one per language)
        foreach ( $published_languages as $language_code => $language_name ) {
            $existing = isset($posts_by_content[$language_code]) ? $posts_by_content[$language_code][0] : null;

            $ls_post = array(
                'post_title' => $language_name,
                'post_content' => $language_code,
                'post_status' => 'publish',
                'post_type' => 'lingua_switcher'
            );

            if ( $existing ) {
                if ($existing->post_title !== $language_name || $existing->post_status !== 'publish') {
                    $ls_post['ID'] = $existing->ID;
                    wp_update_post( $ls_post );
                }
            } else {
                wp_insert_post( $ls_post );
            }
        }

        // Delete posts for removed languages
        foreach ( $posts_by_content as $content => $content_posts ) {
            if ( ! in_array( $content, $language_codes ) ) {
                foreach ( $content_posts as $post ) {
                    wp_delete_post( $post->ID, true );
                }
            }
        }
    }

    /**
     * Process menu items - THE CORE LOGIC
     * Process language switcher menu permalinks
     *
     * @param array $items Menu items
     * @param object $menu Menu object
     * @param array $args Menu args
     * @return array Modified menu items
     */
    public function lingua_menu_permalinks( $items, $menu, $args ) {
        global $LINGUA_LANGUAGE;

        if (!$items || !is_array($items)) {
            return $items;
        }

        // Get current language (set in Lingua constructor)
        $current_language = $LINGUA_LANGUAGE;

        if (empty($current_language)) {
            lingua_debug_log('[Lingua Menu] WARNING: $LINGUA_LANGUAGE not set!');
            return $items;
        }

        // v5.0.1: Add language-specific suffix to prevent cross-language cache pollution
        // This is done by WordPress internally via object cache keys

        $item_key_to_unset = false;
        $current_language_set = false;

        foreach ( $items as $key => $item ) {
            // Check if this is a language switcher menu item
            if ( $item->object == 'lingua_switcher' ) {
                // Get the language switcher post
                $ls_id = get_post_meta( $item->ID, '_menu_item_object_id', true );
                $ls_post = get_post( $ls_id );

                if ( $ls_post == null || $ls_post->post_type != 'lingua_switcher' ) {
                    continue;
                }

                // Extract language code from post_content
                $language_code = $ls_post->post_content;

                // Handle "Current Language" placeholder FIRST
                // 423-426
                if ( $language_code == 'current_language' ) {
                    $language_code = $current_language;
                    $current_language_set = true;
                } else {
                    // Mark current language item for removal (to avoid duplicates)
                    // 419-421
                    // BUT only if it's NOT the "current_language" placeholder
                    if ( $language_code == $current_language && ! is_admin() ) {
                        $item_key_to_unset = $key;
                    }
                }

                // Get language display name
                $language_name = $this->get_language_name( $language_code );

                // Generate language-specific URL for current page
                // 431
                $items[$key]->url = $this->get_url_for_language( $language_code );

                // Add CSS class
                $items[$key]->classes[] = 'lingua-language-switcher-container';

                // Build title with flags/names
                //s 432-445
                $new_title = $this->build_menu_item_title( $language_code, $language_name );
                $items[$key]->title = $new_title;
            }
        }

        // Remove duplicate current language if "Current Language" placeholder exists
        // 449-453
        if ( $current_language_set && $item_key_to_unset !== false ) {
            unset( $items[$item_key_to_unset] );
            $items = array_values( $items );  // Re-index array (CRITICAL!)
            lingua_debug_log("[Lingua Menu] Removed duplicate language item at key {$item_key_to_unset}");
        }

        return $items;
    }

    /**
     * Build menu item title with flags and names
     * Get language URL for menu item
     */
    private function build_menu_item_title( $language_code, $language_name ) {
        $settings = $this->get_menu_switcher_settings();

        // Use data-no-translation to prevent Lingua from translating language names
        $title = '<span data-no-translation>';

        if ( $settings['show_flags'] ) {
            $title .= $this->get_flag_html( $language_code, $language_name );
            // Add space after flag if there's text coming
            if ( $settings['show_short_names'] || $settings['show_full_names'] ) {
                $title .= ' ';
            }
        }

        if ( $settings['show_short_names'] ) {
            $short_name = strtoupper( $this->get_language_short_code( $language_code ) );
            $title .= '<span class="lingua-ls-language-name">' . esc_html( $short_name ) . '</span>';
        }

        if ( $settings['show_full_names'] ) {
            $title .= '<span class="lingua-ls-language-name">' . esc_html( $language_name ) . '</span>';
        }

        $title .= '</span>';

        return apply_filters( 'lingua_menu_language_switcher', $title, $language_name, $language_code, $settings );
    }

    /**
     * Get flag HTML for language (using SVG flag-icons)
     * v5.2.176: Use centralized Lingua_Languages::get_country_code()
     */
    private function get_flag_html( $language_code, $language_name ) {
        // Get country code from centralized mapping
        $country_code = Lingua_Languages::get_country_code($language_code);

        // Return SVG flag using flag-icons library
        return '<span class="fi fi-' . esc_attr($country_code) . '"></span>';
    }

    /**
     * Get language display name
     * Supports Native Names option
     */
    private function get_language_name( $language_code ) {
        $languages = get_option('lingua_languages', array());
        $default_language = get_option('lingua_default_language', 'ru');
        $use_native_names = get_option('lingua_native_names', false);

        // v5.2.64: Use centralized language list (single source of truth)
        if (empty($languages) || !isset($languages[$language_code])) {
            // Use centralized language class instead of hardcoded array
            if (class_exists('Lingua_Languages')) {
                $available_languages = Lingua_Languages::get_all();
                
                if (isset($available_languages[$language_code])) {
                    $languages[$language_code] = $available_languages[$language_code];
                }
            }
        }

        // Check if language exists in settings
        if (isset($languages[$language_code])) {
            // Use native name if option is enabled and available
            if ($use_native_names && isset($languages[$language_code]['native'])) {
                return $languages[$language_code]['native'];
            }
            // Otherwise use regular name
            if (isset($languages[$language_code]['name'])) {
                return $languages[$language_code]['name'];
            }
        }

        return ucfirst($language_code);
    }

    /**
     * Get short language code (2 letters)
     */
    private function get_language_short_code( $language_code ) {
        // Extract first 2 letters
        return substr($language_code, 0, 2);
    }

    /**
     * Get URL for language (use existing URL Rewriter)
     */
    private function get_url_for_language( $language_code ) {
        if (!$this->url_rewriter) {
            $this->url_rewriter = new Lingua_URL_Rewriter();
        }

        return $this->url_rewriter->get_url_for_language( $language_code );
    }

    /**
     * Get menu switcher display settings
     * Maps lingua_switcher_format to display flags
     */
    private function get_menu_switcher_settings() {
        $format = get_option('lingua_switcher_format', 'flags_full');

        // Map format to display settings
        $format_map = array(
            'flags_full' => array(
                'show_flags' => true,
                'show_full_names' => true,
                'show_short_names' => false
            ),
            'full' => array(
                'show_flags' => false,
                'show_full_names' => true,
                'show_short_names' => false
            ),
            'short' => array(
                'show_flags' => false,
                'show_full_names' => false,
                'show_short_names' => true
            ),
            'flags_short' => array(
                'show_flags' => true,
                'show_full_names' => false,
                'show_short_names' => true
            ),
            'flags_only' => array(
                'show_flags' => true,
                'show_full_names' => false,
                'show_short_names' => false
            )
        );

        // Return mapped settings or default
        return isset($format_map[$format]) ? $format_map[$format] : $format_map['flags_full'];
    }
}
