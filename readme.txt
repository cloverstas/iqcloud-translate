=== IQCloud Translate ===
Contributors: iqcloud
Tags: translation, multilingual, language, translate, localization, woocommerce, seo, rtl, multi-language
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Powerful multilingual translation toolkit with visual editor and WooCommerce support.

== Description ==

IQCloud Translate is a modern translation plugin that makes your WordPress site multilingual with ease. Translate any content directly from the frontend using a convenient visual editor.

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

= Pro Features =

Upgrade to Pro for additional features:

* **Auto-Translation** - Automatic translation via API integration
* **Bulk Translation** - Translate your entire website with one click
* **Translation Queue** - Background processing for large sites
* **Priority Support** - Get help when you need it

[Get IQCloud Translate Pro](https://translate.yournewsite.ru)

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

Yes, with IQCloud Translate Pro you get access to automatic translation via API.

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

= 1.0.4 =
Plugin renamed to IQCloud Translate — Site Translation Toolkit.

= 1.0.3 =
Updated compatibility with WordPress 6.9.

= 1.0.0 =
Initial release of IQCloud Translate - make your WordPress site multilingual!

== Third Party Services ==

This plugin connects to external services in the following cases:

= YourNewSite Translation API (Pro Feature) =

When using the Pro version's automatic translation feature, text is sent to the YourNewSite API for translation.

* **Service URL:** https://translate.yournewsite.ru
* **When used:** Only when you explicitly click "Auto-translate" button (Pro feature)
* **Data sent:** Text content for translation, target language code
* **Data NOT sent:** Personal user data, passwords, or sensitive information
* **Privacy Policy:** [https://translate.yournewsite.ru/privacy](https://translate.yournewsite.ru/privacy)
* **Terms of Service:** [https://translate.yournewsite.ru/terms](https://translate.yournewsite.ru/terms)

The free version of this plugin does NOT connect to any external services. All translations are stored locally in your WordPress database.
