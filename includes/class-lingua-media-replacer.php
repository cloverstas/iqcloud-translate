<?php
/**
 * Lingua Media Replacer
 *
 * Replaces image src, alt, and title attributes in HTML output
 *
 * @package Lingua
 * @version 5.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Media_Replacer {

    private $translation_manager;
    private $current_language;

    public function __construct() {
        $this->translation_manager = new Lingua_Translation_Manager();

        // Get current language
        global $LINGUA_LANGUAGE;
        $this->current_language = $LINGUA_LANGUAGE ?? 'ru';
    }

    /**
     * Replace media attributes (src, alt, title) in HTML
     *
     * @param string $html HTML content
     * @return string Modified HTML
     */
    public function replace_media_in_html($html) {
        // v5.2.158: Skip if default language (use 'en' as fallback to match other classes)
        $default_language = get_option('lingua_default_language', lingua_get_site_language());

        lingua_debug_log("[Lingua Media Replacer v5.2.158] Current language: {$this->current_language}, Default: {$default_language}");

        if ($this->current_language === $default_language) {
            lingua_debug_log("[Lingua Media Replacer v5.2.158] Skipping - is default language");
            return $html;
        }

        // Find all img tags
        if (preg_match_all('/<img[^>]+>/i', $html, $matches)) {
            foreach ($matches[0] as $img_tag) {
                $modified_tag = $this->replace_img_attributes($img_tag);
                if ($modified_tag !== $img_tag) {
                    $html = str_replace($img_tag, $modified_tag, $html);
                }
            }
        }

        return $html;
    }

    /**
     * Replace attributes in a single img tag
     *
     * @param string $img_tag Original img tag
     * @return string Modified img tag
     */
    private function replace_img_attributes($img_tag) {
        // Extract src attribute
        if (!preg_match('/src=["\']([^"\']+)["\']/', $img_tag, $src_match)) {
            return $img_tag;
        }

        $original_src = $src_match[1];

        // v5.2: Normalize URL before hashing (convert relative to absolute)
        $normalized_src = $this->normalize_url($original_src);
        $src_hash = md5($normalized_src);

        lingua_debug_log("[Lingua Media Replacer v5.2.158] Processing image: " . substr($normalized_src, 0, 80) . "...");
        lingua_debug_log("[Lingua Media Replacer v5.2.158] Hash: {$src_hash}, Context: media.src[{$src_hash}].src");

        // Check for translated src
        $translated_src = $this->translation_manager->get_translation_by_context(
            "media.src[{$src_hash}].src",
            $this->current_language
        );

        lingua_debug_log("[Lingua Media Replacer v5.2.158] Translation found: " . ($translated_src ? 'YES' : 'NO'));

        if ($translated_src) {
            lingua_debug_log("[Lingua Media Replacer v5.2.161] Replacing src with: " . substr($translated_src, 0, 80));

            // Replace src attribute
            $img_tag = preg_replace(
                '/(\ssrc=)["\'][^"\']+["\']/',
                '$1"' . esc_url($translated_src) . '"',
                $img_tag
            );

            // v5.2.161: Replace data-src (used by lazy loading)
            if (preg_match('/data-src=["\']/', $img_tag)) {
                $img_tag = preg_replace(
                    '/data-src=["\'][^"\']+["\']/',
                    'data-src="' . esc_url($translated_src) . '"',
                    $img_tag
                );
            }

            // v5.2.161: Replace data-large_image (used by WooCommerce zoom)
            if (preg_match('/data-large_image=["\']/', $img_tag)) {
                $img_tag = preg_replace(
                    '/data-large_image=["\'][^"\']+["\']/',
                    'data-large_image="' . esc_url($translated_src) . '"',
                    $img_tag
                );
                lingua_debug_log("[Lingua Media Replacer v5.2.161] Replaced data-large_image for zoom");
            }

            // v5.2.161: Replace data-zoom-image (used by some themes)
            if (preg_match('/data-zoom-image=["\']/', $img_tag)) {
                $img_tag = preg_replace(
                    '/data-zoom-image=["\'][^"\']+["\']/',
                    'data-zoom-image="' . esc_url($translated_src) . '"',
                    $img_tag
                );
            }

            // v5.2.161: Replace srcset (responsive images)
            // We need to replace all URLs in srcset that match the original image base
            if (preg_match('/srcset=["\']([^"\']+)["\']/', $img_tag, $srcset_match)) {
                $original_srcset = $srcset_match[1];
                // Replace all occurrences of the original image (with any size suffix) with translated
                $original_base = $this->normalize_url($original_src);
                $new_srcset = $this->replace_srcset_urls($original_srcset, $original_base, $translated_src);
                $img_tag = str_replace($original_srcset, $new_srcset, $img_tag);
                lingua_debug_log("[Lingua Media Replacer v5.2.161] Replaced srcset");
            }
        }

        // Check for translated alt
        $translated_alt = $this->translation_manager->get_translation_by_context(
            "media.src[{$src_hash}].alt",
            $this->current_language
        );

        if ($translated_alt) {
            // Replace or add alt attribute
            if (preg_match('/alt=["\']/', $img_tag)) {
                $img_tag = preg_replace(
                    '/alt=["\'][^"\']*["\']/',
                    'alt="' . esc_attr($translated_alt) . '"',
                    $img_tag
                );
            } else {
                // Add alt if it doesn't exist
                $img_tag = str_replace('<img ', '<img alt="' . esc_attr($translated_alt) . '" ', $img_tag);
            }
        }

        // Check for translated title
        $translated_title = $this->translation_manager->get_translation_by_context(
            "media.src[{$src_hash}].title",
            $this->current_language
        );

        if ($translated_title) {
            // Replace or add title attribute
            if (preg_match('/title=["\']/', $img_tag)) {
                $img_tag = preg_replace(
                    '/title=["\'][^"\']*["\']/',
                    'title="' . esc_attr($translated_title) . '"',
                    $img_tag
                );
            } else {
                // Add title if it doesn't exist
                $img_tag = str_replace('<img ', '<img title="' . esc_attr($translated_title) . '" ', $img_tag);
            }
        }

        return $img_tag;
    }

    /**
     * Normalize URL to absolute form for consistent hashing
     * Converts relative URLs to absolute URLs
     * v5.2.160: Removes WordPress size suffixes (-600x600, -300x300, etc.) for consistent matching
     *
     * @param string $url Original URL
     * @return string Normalized absolute URL
     */
    private function normalize_url($url) {
        // Already absolute URL
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            // v5.2.160: Remove WordPress size suffixes before hashing
            $url = $this->remove_size_suffix($url);
            return $url;
        }

        // Protocol-relative URL
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
            // v5.2.160: Remove WordPress size suffixes
            $url = $this->remove_size_suffix($url);
            return $url;
        }

        // Get site URL
        $site_url = get_site_url();

        // Absolute path
        if (strpos($url, '/') === 0) {
            $url = $site_url . $url;
            // v5.2.160: Remove WordPress size suffixes
            $url = $this->remove_size_suffix($url);
            return $url;
        }

        // Relative path - add site URL
        $url = $site_url . '/' . ltrim($url, '/');
        // v5.2.160: Remove WordPress size suffixes
        return $this->remove_size_suffix($url);
    }

    /**
     * v5.2.160: Remove WordPress image size suffixes for consistent hashing
     *
     * WordPress generates multiple sizes: image-600x600.jpg, image-300x300.jpg, etc.
     * We need to normalize all sizes to the base filename for consistent hash matching.
     *
     * @param string $url Image URL
     * @return string URL without size suffix
     */
    private function remove_size_suffix($url) {
        // v5.2.160: Remove ALL WordPress size suffixes from filename
        // Pattern: -123x456 (can appear multiple times) before file extension
        // Example: image-800x800-1-300x300.jpg → image-1.jpg → image.jpg
        // Also handles: image-600x600.jpg → image.jpg

        // First, separate the extension
        if (preg_match('/^(.+)(\.(jpe?g|png|gif|webp|bmp|svg))$/i', $url, $matches)) {
            $base = $matches[1];
            $ext = $matches[2];

            // Remove all -NNNxNNN patterns from base (can be multiple)
            $base = preg_replace('/-\d+x\d+/', '', $base);

            // Also remove trailing -1, -2 etc that WordPress adds for duplicates
            $base = preg_replace('/-\d+$/', '', $base);

            $normalized = $base . $ext;
        } else {
            $normalized = $url;
        }

        if ($normalized !== $url) {
            lingua_debug_log("[Lingua Media Replacer v5.2.160] Normalized URL: {$url} → {$normalized}");
        }

        return $normalized;
    }

    /**
     * v5.2.161: Replace URLs in srcset attribute
     *
     * srcset contains multiple URLs with size descriptors like:
     * "image-600x600.jpg 600w, image-300x300.jpg 300w, image.jpg 1600w"
     *
     * We need to replace all URLs that match the original image base with the translated URL
     *
     * @param string $srcset Original srcset value
     * @param string $original_base Normalized original image URL (without size suffix)
     * @param string $translated_url Translated image URL
     * @return string Modified srcset
     */
    private function replace_srcset_urls($srcset, $original_base, $translated_url) {
        // Split srcset by comma
        $entries = explode(',', $srcset);
        $new_entries = array();

        foreach ($entries as $entry) {
            $entry = trim($entry);
            // Each entry is "URL size" like "image-600x600.jpg 600w"
            if (preg_match('/^(\S+)(\s+\S+)?$/', $entry, $match)) {
                $url = $match[1];
                $size_descriptor = isset($match[2]) ? $match[2] : '';

                // Normalize this URL and check if it matches our original
                $normalized_entry_url = $this->normalize_url($url);

                if ($normalized_entry_url === $original_base) {
                    // Replace with translated URL
                    $new_entries[] = $translated_url . $size_descriptor;
                } else {
                    // Keep original
                    $new_entries[] = $entry;
                }
            } else {
                $new_entries[] = $entry;
            }
        }

        return implode(', ', $new_entries);
    }
}
