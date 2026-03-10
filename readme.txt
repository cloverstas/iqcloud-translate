=== IQCloud Translate ===
Contributors: iqcloud
Tags: translation, multilingual, translate, localization, i18n
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Manual multilingual translation toolkit with visual editor and WooCommerce support.

== Description ==

IQCloud Translate is a lightweight manual translation plugin that makes your WordPress site multilingual with ease. Translate any content directly from the frontend using a convenient visual editor — no external API required.

= Key Features =

* **Visual Translation Editor** - Translate content directly on your page with a slide panel interface
* **DOM-based Extraction** - Intelligent extraction of all content types including text, attributes, SEO fields, and forms
* **AJAX Content Support** - Real-time translation of dynamically loaded content (perfect for themes like WoodMart)
* **WooCommerce Ready** - Full support for product descriptions, buttons, checkout fields, and more
* **Global Translations** - Reuse identical translations across your entire site automatically
* **Media Translation** - Replace images per language and translate alt/title attributes
* **SEO Optimization** - Translate meta descriptions, Open Graph tags, and other SEO fields
* **Language Switcher** - Built-in customizable language switcher for navigation menus
* **RTL Support** - Full support for right-to-left languages like Arabic and Hebrew
* **100+ Languages** - Support for over 100 languages with native names and flags

= How It Works =

1. Install and activate the plugin
2. Add your target languages in Settings > IQCloud Translate
3. Visit any page on the frontend while logged in as admin
4. Click "Translate Page" in the admin bar
5. Translate content in the visual editor panel
6. Save and your translations are live!

= Requirements =

* WordPress 5.0 or higher
* PHP 7.4 or higher
* MySQL 5.6+ or MariaDB 10.0+

== Installation ==

1. Upload the `iqcloud-translate` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > IQCloud Translate to configure your languages
4. Add a Language Switcher to your navigation menu (Appearance > Menus)
5. Start translating your content!

== Frequently Asked Questions ==

= Does this plugin work with WooCommerce? =

Yes! IQCloud Translate has full WooCommerce support including product translations, cart, checkout, and all e-commerce strings.

= Can I translate theme strings? =

Yes, the plugin automatically detects translatable strings from your theme and plugins using gettext integration.

= Does it support RTL languages? =

Yes, full support for right-to-left languages including Arabic, Hebrew, Persian, and others.

= Will it slow down my site? =

No, translations are cached and served efficiently. The visual editor only loads when you click "Translate Page".

= Can I use automatic translation? =

The free version supports manual translation only. Automatic translation is available as a separate add-on: IQCloud Translate Pro.

= How does the language switcher work? =

The plugin adds a "Language Switcher" option to your WordPress menus. Go to Appearance > Menus, and you'll see the IQCloud Translate language switcher available to add to any menu.

= Does it work with page builders? =

Yes, IQCloud Translate works with Elementor, WPBakery, Gutenberg, and other page builders. The DOM-based extraction captures all visible content regardless of how it was created.

= Can I translate custom post types? =

Yes, you can configure which post types to translate in the plugin settings.

= What happens to my translations if I deactivate the plugin? =

Your translations are stored in the database and will be preserved. If you reactivate the plugin, all translations will still be there.

== Screenshots ==

1. Visual translation editor panel - translate content with a convenient slide panel
2. Language settings page - add and configure your target languages
3. Language switcher in navigation menu - let visitors switch languages easily
4. WooCommerce product translation - translate products, prices, and checkout
5. SEO fields translation - translate meta descriptions and Open Graph tags

== Changelog ==

= 1.1.0 =
* Major: Separated into free and Pro versions for WordPress.org compliance
* Fixed: All output escaping issues (esc_html, esc_attr, esc_url, wp_kses_post)
* Removed: Auto-translation features moved to Pro add-on
* Removed: Language limits — unlimited languages in free version
* Added: Extensibility hooks for Pro add-on integration
* Improved: Reduced memory footprint by removing unused Pro dependencies

= 1.0.10 =
* Fix gettext scanner (Full Rescan) — admin components now load correctly for plugin AJAX requests
* Production cleanup: version sync, debug code gating, LICENSE file

= 1.0.9 =
* Fix mixed content translation — nodes containing both text and HTML tags now translate correctly
* Fix HTML structure preservation — inline tags (strong, em, a, span) no longer break during translation

= 1.0.8 =
* Fix stale translation cache — translated HTML cache now clears automatically after saving new translations

= 1.0.7 =
* Fix lazy loading for AJAX content extraction — frontend translation components load for plugin AJAX requests

= 1.0.6 =
* Fix memory exhaustion on low-resource servers — lazy loading of admin components
* Fix infinite recursion in locale filter

= 1.0.5 =
* WordPress.org review compliance: sanitization, escaping, local assets, inline scripts

= 1.0.4 =
* Renamed plugin to IQCloud Translate — Site Translation Toolkit

= 1.0.3 =
* Fix URL attributes being translated in image filenames

= 1.0.2 =
* Removed deprecated load_plugin_textdomain() call (handled by WordPress.org since WP 4.6)
* Added sanitization callback for register_setting() to comply with WordPress Plugin Check

= 1.0.0 =
* Initial public release
* Visual translation editor with slide panel
* DOM-based content extraction
* WooCommerce full support
* SEO fields translation (meta, Open Graph)
* Built-in language switcher
* RTL language support
* 100+ languages with flags
* Global translations feature
* AJAX content translation
* Media translation support

== Upgrade Notice ==

= 1.1.0 =
Major update: free/Pro split. Auto-translation moved to Pro add-on. All escaping issues fixed. Unlimited languages.

= 1.0.10 =
Fix gettext scanner and production cleanup. Recommended update.

= 1.0.9 =
Critical fix for mixed content translation and HTML structure preservation.

= 1.0.8 =
Fix stale translation cache after saving.

= 1.0.7 =
Fix AJAX content extraction on frontend.

= 1.0.6 =
Fix memory exhaustion on low-resource servers.

= 1.0.5 =
WordPress.org review compliance fixes.

= 1.0.4 =
Plugin renamed to IQCloud Translate — Site Translation Toolkit.

= 1.0.3 =
Updated compatibility with WordPress 6.9.

= 1.0.0 =
Initial release of IQCloud Translate - make your WordPress site multilingual!

== Third Party Services ==

This plugin does NOT connect to any external services. All translations are created manually and stored locally in your WordPress database. No data is sent to third-party servers.

Auto-translation via external API is available as a separate add-on: IQCloud Translate Pro.
