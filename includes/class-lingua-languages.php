<?php
/**
 * Centralized language list for Lingua
 *
 * v5.2.131: Full Yandex Translate API language support (100+ languages)
 * Single source of truth for all available languages
 *
 * @package Lingua
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lingua_Languages {

    /**
     * Get all available languages
     *
     * @return array Language data with name, native name, and flag
     */
    public static function get_all() {
        return array(
            // Original 10 languages (keep first for backward compatibility)
            'ru' => array('name' => 'Russian', 'native' => 'Русский', 'flag' => '🇷🇺'),
            'en' => array('name' => 'English', 'native' => 'English', 'flag' => '🇺🇸'),
            'fr' => array('name' => 'French', 'native' => 'Français', 'flag' => '🇫🇷'),
            'de' => array('name' => 'German', 'native' => 'Deutsch', 'flag' => '🇩🇪'),
            'es' => array('name' => 'Spanish', 'native' => 'Español', 'flag' => '🇪🇸'),
            'it' => array('name' => 'Italian', 'native' => 'Italiano', 'flag' => '🇮🇹'),
            'zh' => array('name' => 'Chinese', 'native' => '中文', 'flag' => '🇨🇳'),
            'ja' => array('name' => 'Japanese', 'native' => '日本語', 'flag' => '🇯🇵'),
            'ko' => array('name' => 'Korean', 'native' => '한국어', 'flag' => '🇰🇷'),
            'pt' => array('name' => 'Portuguese', 'native' => 'Português', 'flag' => '🇵🇹'),

            // Slavic languages
            'uk' => array('name' => 'Ukrainian', 'native' => 'Українська', 'flag' => '🇺🇦'),
            'be' => array('name' => 'Belarusian', 'native' => 'Беларуская', 'flag' => '🇧🇾'),
            'pl' => array('name' => 'Polish', 'native' => 'Polski', 'flag' => '🇵🇱'),
            'cs' => array('name' => 'Czech', 'native' => 'Čeština', 'flag' => '🇨🇿'),
            'sk' => array('name' => 'Slovak', 'native' => 'Slovenčina', 'flag' => '🇸🇰'),
            'bg' => array('name' => 'Bulgarian', 'native' => 'Български', 'flag' => '🇧🇬'),
            'sr' => array('name' => 'Serbian', 'native' => 'Српски', 'flag' => '🇷🇸'),
            'hr' => array('name' => 'Croatian', 'native' => 'Hrvatski', 'flag' => '🇭🇷'),
            'sl' => array('name' => 'Slovenian', 'native' => 'Slovenščina', 'flag' => '🇸🇮'),
            'mk' => array('name' => 'Macedonian', 'native' => 'Македонски', 'flag' => '🇲🇰'),
            'bs' => array('name' => 'Bosnian', 'native' => 'Bosanski', 'flag' => '🇧🇦'),

            // Baltic languages
            'lt' => array('name' => 'Lithuanian', 'native' => 'Lietuvių', 'flag' => '🇱🇹'),
            'lv' => array('name' => 'Latvian', 'native' => 'Latviešu', 'flag' => '🇱🇻'),

            // Nordic languages
            'da' => array('name' => 'Danish', 'native' => 'Dansk', 'flag' => '🇩🇰'),
            'sv' => array('name' => 'Swedish', 'native' => 'Svenska', 'flag' => '🇸🇪'),
            'no' => array('name' => 'Norwegian', 'native' => 'Norsk', 'flag' => '🇳🇴'),
            'fi' => array('name' => 'Finnish', 'native' => 'Suomi', 'flag' => '🇫🇮'),
            'is' => array('name' => 'Icelandic', 'native' => 'Íslenska', 'flag' => '🇮🇸'),

            // Other European languages
            'ro' => array('name' => 'Romanian', 'native' => 'Română', 'flag' => '🇷🇴'),
            'hu' => array('name' => 'Hungarian', 'native' => 'Magyar', 'flag' => '🇭🇺'),
            'nl' => array('name' => 'Dutch', 'native' => 'Nederlands', 'flag' => '🇳🇱'),
            'et' => array('name' => 'Estonian', 'native' => 'Eesti', 'flag' => '🇪🇪'),
            'el' => array('name' => 'Greek', 'native' => 'Ελληνικά', 'flag' => '🇬🇷'),
            'tr' => array('name' => 'Turkish', 'native' => 'Türkçe', 'flag' => '🇹🇷'),
            'sq' => array('name' => 'Albanian', 'native' => 'Shqip', 'flag' => '🇦🇱'),
            'mt' => array('name' => 'Maltese', 'native' => 'Malti', 'flag' => '🇲🇹'),
            'lb' => array('name' => 'Luxembourgish', 'native' => 'Lëtzebuergesch', 'flag' => '🇱🇺'),

            // Celtic languages
            'ga' => array('name' => 'Irish', 'native' => 'Gaeilge', 'flag' => '🇮🇪'),
            'cy' => array('name' => 'Welsh', 'native' => 'Cymraeg', 'flag' => '🏴󠁧󠁢󠁷󠁬󠁳󠁿'),
            'gd' => array('name' => 'Scottish Gaelic', 'native' => 'Gàidhlig', 'flag' => '🏴󠁧󠁢󠁳󠁣󠁴󠁿'),
            'fy' => array('name' => 'Frisian', 'native' => 'Frysk', 'flag' => '🇳🇱'),

            // Iberian languages
            'ca' => array('name' => 'Catalan', 'native' => 'Català', 'flag' => '🇪🇸'),
            'gl' => array('name' => 'Galician', 'native' => 'Galego', 'flag' => '🇪🇸'),
            'eu' => array('name' => 'Basque', 'native' => 'Euskara', 'flag' => '🇪🇸'),
            'co' => array('name' => 'Corsican', 'native' => 'Corsu', 'flag' => '🇫🇷'),

            // Caucasian languages
            'az' => array('name' => 'Azerbaijani', 'native' => 'Azərbaycan', 'flag' => '🇦🇿'),
            'ka' => array('name' => 'Georgian', 'native' => 'ქართული', 'flag' => '🇬🇪'),
            'hy' => array('name' => 'Armenian', 'native' => 'Հdelays', 'flag' => '🇦🇲'),

            // Russian Federation regional languages
            'tt' => array('name' => 'Tatar', 'native' => 'Татарча', 'flag' => '🇷🇺'),
            'ba' => array('name' => 'Bashkir', 'native' => 'Башҡортса', 'flag' => '🇷🇺'),
            'cv' => array('name' => 'Chuvash', 'native' => 'Чӑвашла', 'flag' => '🇷🇺'),
            'mhr' => array('name' => 'Mari', 'native' => 'Марий йылме', 'flag' => '🇷🇺'),
            'mrj' => array('name' => 'Hill Mari', 'native' => 'Кырык мары', 'flag' => '🇷🇺'),
            'sah' => array('name' => 'Yakut', 'native' => 'Саха тыла', 'flag' => '🇷🇺'),
            'udm' => array('name' => 'Udmurt', 'native' => 'Удмурт кыл', 'flag' => '🇷🇺'),

            // Semitic languages (RTL)
            'ar' => array('name' => 'Arabic', 'native' => 'العربية', 'flag' => '🇸🇦', 'rtl' => true),
            'he' => array('name' => 'Hebrew', 'native' => 'עברית', 'flag' => '🇮🇱', 'rtl' => true),
            'am' => array('name' => 'Amharic', 'native' => 'አማርኛ', 'flag' => '🇪🇹'),

            // Iranian languages
            'fa' => array('name' => 'Persian', 'native' => 'فارسی', 'flag' => '🇮🇷', 'rtl' => true),
            'ku' => array('name' => 'Kurdish', 'native' => 'Kurdî', 'flag' => '🇮🇶', 'rtl' => true),
            'ps' => array('name' => 'Pashto', 'native' => 'پښتو', 'flag' => '🇦🇫', 'rtl' => true),
            'tg' => array('name' => 'Tajik', 'native' => 'Тоҷикӣ', 'flag' => '🇹🇯'),

            // Indo-Aryan languages
            'hi' => array('name' => 'Hindi', 'native' => 'हिन्दी', 'flag' => '🇮🇳'),
            'bn' => array('name' => 'Bengali', 'native' => 'বাংলা', 'flag' => '🇧🇩'),
            'pa' => array('name' => 'Punjabi', 'native' => 'ਪੰਜਾਬੀ', 'flag' => '🇮🇳'),
            'gu' => array('name' => 'Gujarati', 'native' => 'ગુજરાતી', 'flag' => '🇮🇳'),
            'mr' => array('name' => 'Marathi', 'native' => 'मराठी', 'flag' => '🇮🇳'),
            'ne' => array('name' => 'Nepali', 'native' => 'नेपाली', 'flag' => '🇳🇵'),
            'si' => array('name' => 'Sinhala', 'native' => 'සිංහල', 'flag' => '🇱🇰'),
            'ur' => array('name' => 'Urdu', 'native' => 'اردو', 'flag' => '🇵🇰', 'rtl' => true),
            'sd' => array('name' => 'Sindhi', 'native' => 'سنڌي', 'flag' => '🇵🇰', 'rtl' => true),

            // Dravidian languages
            'ta' => array('name' => 'Tamil', 'native' => 'தமிழ்', 'flag' => '🇮🇳'),
            'te' => array('name' => 'Telugu', 'native' => 'తెలుగు', 'flag' => '🇮🇳'),
            'kn' => array('name' => 'Kannada', 'native' => 'ಕನ್ನಡ', 'flag' => '🇮🇳'),
            'ml' => array('name' => 'Malayalam', 'native' => 'മലയാളം', 'flag' => '🇮🇳'),

            // Turkic languages
            'kk' => array('name' => 'Kazakh', 'native' => 'Қазақша', 'flag' => '🇰🇿'),
            'ky' => array('name' => 'Kyrgyz', 'native' => 'Кыргызча', 'flag' => '🇰🇬'),
            'uz' => array('name' => 'Uzbek', 'native' => 'Oʻzbekcha', 'flag' => '🇺🇿'),
            'tk' => array('name' => 'Turkmen', 'native' => 'Türkmen', 'flag' => '🇹🇲'),

            // Southeast Asian languages
            'th' => array('name' => 'Thai', 'native' => 'ไทย', 'flag' => '🇹🇭'),
            'vi' => array('name' => 'Vietnamese', 'native' => 'Tiếng Việt', 'flag' => '🇻🇳'),
            'id' => array('name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'flag' => '🇮🇩'),
            'ms' => array('name' => 'Malay', 'native' => 'Bahasa Melayu', 'flag' => '🇲🇾'),
            'tl' => array('name' => 'Filipino', 'native' => 'Filipino', 'flag' => '🇵🇭'),
            'km' => array('name' => 'Khmer', 'native' => 'ខ្មែរ', 'flag' => '🇰🇭'),
            'lo' => array('name' => 'Lao', 'native' => 'ລາວ', 'flag' => '🇱🇦'),
            'my' => array('name' => 'Myanmar', 'native' => 'မြန်မာ', 'flag' => '🇲🇲'),
            'jv' => array('name' => 'Javanese', 'native' => 'Basa Jawa', 'flag' => '🇮🇩'),
            'su' => array('name' => 'Sundanese', 'native' => 'Basa Sunda', 'flag' => '🇮🇩'),
            'ceb' => array('name' => 'Cebuano', 'native' => 'Cebuano', 'flag' => '🇵🇭'),

            // East Asian
            'mn' => array('name' => 'Mongolian', 'native' => 'Монгол', 'flag' => '🇲🇳'),

            // African languages
            'af' => array('name' => 'Afrikaans', 'native' => 'Afrikaans', 'flag' => '🇿🇦'),
            'sw' => array('name' => 'Swahili', 'native' => 'Kiswahili', 'flag' => '🇰🇪'),
            'zu' => array('name' => 'Zulu', 'native' => 'isiZulu', 'flag' => '🇿🇦'),
            'xh' => array('name' => 'Xhosa', 'native' => 'isiXhosa', 'flag' => '🇿🇦'),
            'yo' => array('name' => 'Yoruba', 'native' => 'Yorùbá', 'flag' => '🇳🇬'),
            'ig' => array('name' => 'Igbo', 'native' => 'Igbo', 'flag' => '🇳🇬'),
            'ha' => array('name' => 'Hausa', 'native' => 'Hausa', 'flag' => '🇳🇬'),
            'ny' => array('name' => 'Chichewa', 'native' => 'Chichewa', 'flag' => '🇲🇼'),
            'sn' => array('name' => 'Shona', 'native' => 'chiShona', 'flag' => '🇿🇼'),
            'so' => array('name' => 'Somali', 'native' => 'Soomaali', 'flag' => '🇸🇴'),
            'st' => array('name' => 'Sesotho', 'native' => 'Sesotho', 'flag' => '🇱🇸'),
            'mg' => array('name' => 'Malagasy', 'native' => 'Malagasy', 'flag' => '🇲🇬'),
            'rw' => array('name' => 'Kinyarwanda', 'native' => 'Ikinyarwanda', 'flag' => '🇷🇼'),

            // Pacific languages
            'mi' => array('name' => 'Maori', 'native' => 'Te Reo Māori', 'flag' => '🇳🇿'),
            'haw' => array('name' => 'Hawaiian', 'native' => 'ʻŌlelo Hawaiʻi', 'flag' => '🇺🇸'),
            'sm' => array('name' => 'Samoan', 'native' => 'Gagana Samoa', 'flag' => '🇼🇸'),

            // Creole languages
            'ht' => array('name' => 'Haitian Creole', 'native' => 'Kreyòl Ayisyen', 'flag' => '🇭🇹'),

            // Other languages
            'hmn' => array('name' => 'Hmong', 'native' => 'Hmoob', 'flag' => '🇱🇦'),
            'la' => array('name' => 'Latin', 'native' => 'Latina', 'flag' => '🇻🇦'),
            'eo' => array('name' => 'Esperanto', 'native' => 'Esperanto', 'flag' => '🌍'),
        );
    }

    /**
     * Get a specific language by code
     *
     * @param string $code Language code
     * @return array|null Language data or null if not found
     */
    public static function get($code) {
        $languages = self::get_all();
        return isset($languages[$code]) ? $languages[$code] : null;
    }

    /**
     * Check if a language code is valid
     *
     * @param string $code Language code
     * @return bool
     */
    public static function is_valid($code) {
        $languages = self::get_all();
        return isset($languages[$code]);
    }

    /**
     * Get language codes only
     *
     * @return array List of language codes
     */
    public static function get_codes() {
        return array_keys(self::get_all());
    }

    /**
     * Check if a language is RTL (Right-to-Left)
     *
     * @param string $code Language code
     * @return bool True if RTL language
     */
    public static function is_rtl($code) {
        $lang = self::get($code);
        return $lang && !empty($lang['rtl']);
    }

    /**
     * Get all RTL language codes
     *
     * @return array List of RTL language codes
     */
    public static function get_rtl_codes() {
        $rtl_codes = array();
        foreach (self::get_all() as $code => $lang) {
            if (!empty($lang['rtl'])) {
                $rtl_codes[] = $code;
            }
        }
        return $rtl_codes;
    }

    /**
     * Get country code for flag-icons library (ISO 3166-1 alpha-2)
     * v5.2.176: Centralized mapping for SVG flags in language switcher
     *
     * @param string $lang_code Language code
     * @return string Country code for flag-icons
     */
    public static function get_country_code($lang_code) {
        $lang_to_country = array(
            // Major languages
            'en' => 'us', 'ru' => 'ru', 'de' => 'de', 'fr' => 'fr', 'es' => 'es',
            'it' => 'it', 'pt' => 'pt', 'zh' => 'cn', 'ja' => 'jp', 'ko' => 'kr',

            // Slavic languages
            'uk' => 'ua', 'be' => 'by', 'pl' => 'pl', 'cs' => 'cz', 'sk' => 'sk',
            'bg' => 'bg', 'sr' => 'rs', 'hr' => 'hr', 'sl' => 'si', 'mk' => 'mk', 'bs' => 'ba',

            // Baltic
            'lt' => 'lt', 'lv' => 'lv',

            // Nordic
            'da' => 'dk', 'sv' => 'se', 'no' => 'no', 'fi' => 'fi', 'is' => 'is',

            // Other European
            'ro' => 'ro', 'hu' => 'hu', 'nl' => 'nl', 'et' => 'ee', 'el' => 'gr',
            'tr' => 'tr', 'sq' => 'al', 'mt' => 'mt', 'lb' => 'lu',

            // Celtic
            'ga' => 'ie', 'cy' => 'gb', 'gd' => 'gb', 'fy' => 'nl',

            // Iberian
            'ca' => 'es', 'gl' => 'es', 'eu' => 'es', 'co' => 'fr',

            // Caucasian
            'az' => 'az', 'ka' => 'ge', 'hy' => 'am',

            // Russian Federation regional
            'tt' => 'ru', 'ba' => 'ru', 'cv' => 'ru', 'mhr' => 'ru',
            'mrj' => 'ru', 'sah' => 'ru', 'udm' => 'ru',

            // Semitic
            'ar' => 'sa', 'he' => 'il', 'am' => 'et',

            // Iranian
            'fa' => 'ir', 'ku' => 'iq', 'ps' => 'af', 'tg' => 'tj',

            // Indo-Aryan
            'hi' => 'in', 'bn' => 'bd', 'pa' => 'in', 'gu' => 'in', 'mr' => 'in',
            'ne' => 'np', 'si' => 'lk', 'ur' => 'pk', 'sd' => 'pk',

            // Dravidian
            'ta' => 'in', 'te' => 'in', 'kn' => 'in', 'ml' => 'in',

            // Turkic
            'kk' => 'kz', 'ky' => 'kg', 'uz' => 'uz', 'tk' => 'tm',

            // Southeast Asian
            'th' => 'th', 'vi' => 'vn', 'id' => 'id', 'ms' => 'my', 'tl' => 'ph',
            'km' => 'kh', 'lo' => 'la', 'my' => 'mm', 'jv' => 'id', 'su' => 'id', 'ceb' => 'ph',

            // East Asian
            'mn' => 'mn',

            // African
            'af' => 'za', 'sw' => 'ke', 'zu' => 'za', 'xh' => 'za', 'yo' => 'ng',
            'ig' => 'ng', 'ha' => 'ng', 'ny' => 'mw', 'sn' => 'zw', 'so' => 'so',
            'st' => 'ls', 'mg' => 'mg', 'rw' => 'rw',

            // Pacific
            'mi' => 'nz', 'haw' => 'us', 'sm' => 'ws',

            // Creole & Other
            'ht' => 'ht', 'hmn' => 'la', 'la' => 'va', 'eo' => 'eu',
        );

        return isset($lang_to_country[$lang_code]) ? $lang_to_country[$lang_code] : $lang_code;
    }
}
