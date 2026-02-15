/**
 * Lingua Language Switcher Fix v5.2.30
 * Universal solution for cached menu - detects language from URL directly
 *
 * Problem: Theme caches menu HTML, PHP data may be wrong
 * Solution: Detect language from browser URL + update menu on client-side
 * v3.0.55: CRITICAL FIX - Use visibility:visible (WoodMart uses visibility not display)
 * v3.0.57: CRITICAL FIX - Dynamically create missing language items when absent from cached HTML
 * v3.0.58: CRITICAL FIX - Force browser cache clear
 * v3.0.59: CRITICAL FIX - Update link text directly (WoodMart has no .nav-link-text)
 * v4.0: PHP backend now sets $LINGUA_LANGUAGE in constructor
 * v5.2.10: CRITICAL DEBUG - Added extensive logging to diagnose missing switcher issues
 * v5.2.16: CRITICAL FIX - Filter parent items from otherLangItems + hide old parents after restructure
 * v5.2.17: CRITICAL FIX - Force new parent visibility AFTER hiding old parents with !important
 * v5.2.18: DIAGNOSTIC - Deep CSS analysis to find root cause of invisibility
 * v5.2.19: ROOT CAUSE FIX - Change item-level-1 to item-level-0 when converting to parent (proper solution without !important)
 * v5.2.20: DIAGNOSTIC - Log which duplicate item is selected and check its parent menu visibility
 * v5.2.21: REAL ROOT CAUSE - Move element to top-level menu (was nested inside old parent's hidden dropdown)
 * v5.2.22: Removed diagnostic code that caused getComputedStyle error after detach()
 * v5.2.23: Select from LAST menu copy (desktop), not first (mobile hidden version)
 * v5.2.24: REAL FIX - Deduplicate otherLangItems to avoid duplicate languages in dropdown (select LAST copy of each)
 * v5.2.25: SMART FIX - Detect if PHP already generated correct content (check for data-no-translation), skip JS modification to prevent duplicates
 * v5.2.26: CRITICAL FIX - Include parent items in candidates, convert them to children (fixes missing Russian on /it/)
 * v5.2.27: FINAL FIX - Always update parent text when restructuring (even if PHP content exists - it's for child, not parent)
 * v5.2.28: BUGFIX - Find REAL top-level menu (not nested dropdown) using parents() instead of closest()
 * v5.2.29: BUGFIX - Search for top-level menu globally (item may be in orphan dropdown)
 * v5.2.30: CRITICAL FIX - Update URLs to current page during restructuring
 */

// v5.4.1: Ensure linguaDebug exists before use (may load before inline footer script)
if (typeof window.linguaDebug !== 'function') {
    window.linguaDebug = function() {};
}

window.linguaDebug('[Lingua Switcher Fix v5.2.30] ✅ Script file loaded successfully');
window.linguaDebug('[Lingua Switcher Fix v5.2.30] Timestamp:', new Date().toISOString());

(function($) {
    'use strict';

    window.linguaDebug('[Lingua Switcher Fix v5.2.10] jQuery wrapper initialized');

    /**
     * v4.0: Restructure flat language menu into parent/child dropdown structure
     * This handles case when menu has individual language items (not "Current Language" placeholder)
     */
    function restructureFlatLanguageMenu(switcherItems, currentLang, languages) {
        window.linguaDebug('[Lingua v4.0] Restructuring flat menu with', switcherItems.length, 'items');

        // Find which item represents current language
        var currentLangItem = null;

        // v5.2.24: CRITICAL FIX - Deduplicate other language items
        // WoodMart creates 3 menu copies - need to select LAST copy for each language
        var candidateItems = [];
        var otherLangCandidates = {}; // { 'en': [{index, item}, ...], 'de': [...], ... }

        switcherItems.each(function(index) {
            var $item = $(this);
            var $link = $item.find('> a');
            var href = $link.attr('href') || '';
            var isParent = $item.hasClass('menu-item-has-children');

            // Detect language from href
            var itemLang = null;
            var urlMatch = href.match(/\/([a-z]{2})(?:\/|\?|#|$)/);
            if (urlMatch) {
                itemLang = urlMatch[1];
            } else if (href.indexOf(window.location.origin) === 0 || href.indexOf('/') === 0) {
                // Default language (no prefix)
                itemLang = linguaSwitcher.defaultLang;
            }

            window.linguaDebug('[Lingua v4.0 DEBUG] Item', index, '- lang:', itemLang, 'current:', currentLang, 'hasChildren:', isParent);

            if (itemLang === currentLang && !isParent) {
                // Found current language item that is NOT a parent
                // Store all candidates, will use LAST one (desktop version)
                candidateItems.push({index: index, item: $item});
                window.linguaDebug('[Lingua v5.2.23] 📝 Found candidate at index', index);
            } else if (itemLang !== null && itemLang !== currentLang) {
                // v5.2.26: Store ALL candidates for each language (including parents!)
                // We'll convert parent items to child items later
                if (!otherLangCandidates[itemLang]) {
                    otherLangCandidates[itemLang] = [];
                }
                otherLangCandidates[itemLang].push({index: index, item: $item, isParent: isParent});
                window.linguaDebug('[Lingua v5.2.26] 📝 Storing', itemLang, 'at index', index, '(isParent:', isParent + ')');
            } else if (itemLang === currentLang && isParent) {
                window.linguaDebug('[Lingua v5.2.20] ⚠️ Item', index, 'matches current lang BUT is parent - skipping');
            }
        });

        // v5.2.23: Use LAST candidate (desktop menu)
        if (candidateItems.length > 0) {
            var lastCandidate = candidateItems[candidateItems.length - 1];
            currentLangItem = lastCandidate.item;
            window.linguaDebug('[Lingua v5.2.23] ✅ Selected LAST candidate at index', lastCandidate.index, '(desktop menu)');
            window.linguaDebug('[Lingua v5.2.23] Total candidates found:', candidateItems.length);
        }

        // v5.2.26: Deduplicate other languages - prefer child, fallback to parent
        var otherLangItems = [];
        for (var langCode in otherLangCandidates) {
            var candidates = otherLangCandidates[langCode];
            if (candidates.length === 0) continue;

            // Prefer child items (non-parent)
            var childCandidates = candidates.filter(function(c) { return !c.isParent; });
            var selectedCandidate;

            if (childCandidates.length > 0) {
                // Use LAST child version (desktop)
                selectedCandidate = childCandidates[childCandidates.length - 1];
                window.linguaDebug('[Lingua v5.2.26] 📝 Selected LAST child copy of', langCode, 'at index', selectedCandidate.index);
            } else {
                // Only parent versions exist - use LAST one and convert to child
                selectedCandidate = candidates[candidates.length - 1];
                window.linguaDebug('[Lingua v5.2.26] ⚠️ Only parent versions of', langCode, '- converting to child at index', selectedCandidate.index);

                // Convert parent to child: remove children class and dropdown
                selectedCandidate.item.removeClass('menu-item-has-children');
                selectedCandidate.item.find('> .sub-menu, > .wd-sub-menu').remove();
            }

            otherLangItems.push(selectedCandidate.item);
        }

        if (!currentLangItem) {
            console.warn('[Lingua v4.0] Current language item not found in menu');
            return;
        }

        window.linguaDebug('[Lingua v4.0] Found current lang item:', currentLang);
        window.linguaDebug('[Lingua v4.0] Found', otherLangItems.length, 'other language items');

        // v5.2.20: CRITICAL DEBUG - Check parent visibility of selected item
        var $parentMenu = currentLangItem.closest('ul.menu, ul.wd-sub-menu');
        if ($parentMenu.length > 0) {
            var parentMenu = $parentMenu[0];
            var parentComputed = window.getComputedStyle(parentMenu);
            window.linguaDebug('[Lingua v5.2.20] 🔍 Parent menu of selected item:', {
                display: parentComputed.display,
                visibility: parentComputed.visibility,
                opacity: parentComputed.opacity,
                classes: parentMenu.className
            });
        }

        // v5.2.29: CRITICAL FIX - Find top-level menu globally (not via parents)
        // The issue: Item may be in orphan dropdown not attached to main menu
        // Solution: Find ANY main menu on page (not dropdown) and use it
        window.linguaDebug('[Lingua v5.2.29] 🔧 Detaching from old parent and moving to top-level');

        // Find ALL parent <ul> elements of current item
        var $allParentMenus = currentLangItem.parents('ul');
        window.linguaDebug('[Lingua v5.2.29] Parent menus of current item:', $allParentMenus.length);

        var $topLevelMenu = null;

        // First try: find parent <ul> that is NOT a dropdown
        $allParentMenus.each(function() {
            var $menu = $(this);
            if (!$menu.hasClass('sub-menu') && !$menu.hasClass('wd-sub-menu')) {
                $topLevelMenu = $menu;
            }
        });

        // Second try: if no parent menu found, search globally for main menu
        if (!$topLevelMenu || $topLevelMenu.length === 0) {
            window.linguaDebug('[Lingua v5.2.29] No parent menu found, searching globally...');

            // Find ANY <ul> with class 'menu' that contains other switcher items
            $('ul.menu').each(function() {
                var $menu = $(this);
                // Check if this menu contains lingua switcher items
                if ($menu.find('.menu-item-object-lingua_switcher').length > 0) {
                    $topLevelMenu = $menu;
                    window.linguaDebug('[Lingua v5.2.29] Found global menu with switcher items');
                    return false; // break
                }
            });
        }

        if (!$topLevelMenu || $topLevelMenu.length === 0) {
            console.error('[Lingua v5.2.29] ❌ Could not find any top-level menu!');
            return;
        }

        window.linguaDebug('[Lingua v5.2.29] Found top-level menu:', $topLevelMenu[0].className);

        // Detach from current position
        currentLangItem.detach();

        // Append to top-level menu (will be at the end, but visible)
        $topLevelMenu.append(currentLangItem);
        window.linguaDebug('[Lingua v5.2.28] ✅ Moved to top-level menu');

        // v5.2.19: Change menu level from child (item-level-1) to parent (item-level-0)
        currentLangItem.removeClass('item-level-1 item-level-2 item-level-3');
        currentLangItem.addClass('item-level-0');

        // Transform current language item into parent dropdown
        currentLangItem.addClass('menu-item-has-children');

        // CRITICAL: Force visibility on parent item
        currentLangItem.css({
            'display': 'flex',
            'visibility': 'visible',
            'opacity': '1'
        });

        var $link = currentLangItem.find('> a');
        var langData = languages[currentLang];
        var newTitle = langData.flag + ' ' + langData.name;

        // v5.2.27: ALWAYS update text when restructuring (converting child to parent)
        // Even if PHP content exists - it's for child element, not parent!
        var $dataNoTranslation = $link.find('[data-no-translation]');

        // v5.2.176: Use country_code from PHP (centralized Lingua_Languages::get_country_code)
        var countryCode = langData.country_code || currentLang;

        if ($dataNoTranslation.length > 0) {
            // Update content inside data-no-translation span
            $dataNoTranslation.html(
                '<span class="fi fi-' + countryCode + '"></span> ' +
                '<span class="lingua-ls-language-name">' + langData.name + '</span>'
            );
            window.linguaDebug('[Lingua v5.2.27] Updated PHP-generated content for parent:', newTitle);
        } else {
            // No PHP content - create from scratch
            var $textSpan = $link.find('.nav-link-text');
            if ($textSpan.length > 0) {
                $textSpan.text(newTitle);
            } else {
                $link.text(newTitle);
            }
            window.linguaDebug('[Lingua v5.2.27] Created new content for parent:', newTitle);
        }

        // Create dropdown menu for other languages
        var dropdownClass = 'wd-sub-menu'; // WoodMart theme class
        var $dropdown = $('<ul class="' + dropdownClass + '" style="display: none;"></ul>');

        // Insert dropdown after current language link FIRST
        $link.after($dropdown);

        // Now move other language items into dropdown AND update their URLs
        $.each(otherLangItems, function(i, $item) {
            // CRITICAL: Check that this item is not a parent/ancestor of the dropdown
            if ($item[0].contains($dropdown[0])) {
                console.warn('[Lingua v4.0] Skipping item - it contains the dropdown!');
                return; // Skip this item
            }

            // v5.2.30: CRITICAL FIX - Detect language of this item and update URL
            var $itemLink = $item.find('> a');
            var itemHref = $itemLink.attr('href') || '';
            var itemLang = null;

            // Detect language from href
            var urlMatch = itemHref.match(/\/([a-z]{2})(?:\/|\?|#|$)/);
            if (urlMatch) {
                itemLang = urlMatch[1];
            } else if (itemHref.indexOf(window.location.origin) === 0 || itemHref.indexOf('/') === 0) {
                itemLang = linguaSwitcher.defaultLang;
            }

            // Update URL to point to CURRENT page in that language
            if (itemLang && languages[itemLang] && languages[itemLang].url) {
                $itemLink.attr('href', languages[itemLang].url);
                window.linguaDebug('[Lingua v5.2.30] Updated URL for', itemLang, 'to:', languages[itemLang].url);
            }

            // Detach from current position first
            $item.detach();

            // Update styles
            $item.removeClass('menu-item-has-children'); // Make them regular child items
            $item.css('visibility', 'visible'); // Ensure visible
            $item.css('display', 'block');

            // Append to dropdown
            $dropdown.append($item);
            window.linguaDebug('[Lingua v4.0] Moved item', i, 'to dropdown');
        });

        window.linguaDebug('[Lingua v4.0] ✓ Restructured menu - parent:', newTitle, 'children:', otherLangItems.length);

        // v5.2.16: CRITICAL FIX - Hide all old parent items (wrong language)
        // This fixes the issue where multiple menu copies exist and old parents remain visible
        $('.menu-item-object-lingua_switcher.menu-item-has-children').each(function() {
            var $oldParent = $(this);
            // Skip the one we just created
            if ($oldParent[0] === currentLangItem[0]) {
                return; // This is our new parent, keep it visible
            }

            // Check if this old parent is for wrong language
            var $oldLink = $oldParent.find('> a');
            var oldHref = $oldLink.attr('href') || '';
            var oldLang = null;
            var oldUrlMatch = oldHref.match(/\/([a-z]{2})(?:\/|\?|#|$)/);
            if (oldUrlMatch) {
                oldLang = oldUrlMatch[1];
            } else if (oldHref.indexOf(window.location.origin) === 0 || oldHref.indexOf('/') === 0) {
                oldLang = linguaSwitcher.defaultLang;
            }

            // Hide if it's for different language
            if (oldLang !== currentLang) {
                window.linguaDebug('[Lingua v5.2.16] Hiding old parent for language:', oldLang);
                $oldParent.css({
                    'display': 'none !important',
                    'visibility': 'hidden',
                    'opacity': '0'
                });
                $oldParent.attr('aria-hidden', 'true');
            }
        });

        // Setup hover behavior
        currentLangItem.hover(
            function() {
                $(this).find('> .' + dropdownClass).show();
            },
            function() {
                $(this).find('> .' + dropdownClass).hide();
            }
        );
    }

    // v4.0: Detect language from URL (client-side, bypasses all caching)
    function detectLanguageFromURL() {
        var path = window.location.pathname;
        var segments = path.split('/').filter(function(s) { return s !== ''; });
        var firstSegment = segments[0] || '';

        // Try linguaSwitcher data if available
        if (typeof linguaSwitcher !== 'undefined' && linguaSwitcher.languages) {
            var allLangs = Object.keys(linguaSwitcher.languages);
            allLangs.push(linguaSwitcher.defaultLang);

            if (allLangs.indexOf(firstSegment) !== -1 && firstSegment !== linguaSwitcher.defaultLang) {
                return firstSegment;
            }
        }

        // Fallback: hardcoded common languages
        var knownLangs = ['en', 'de', 'fr', 'es', 'it', 'zh', 'ja', 'ko', 'pt'];
        if (knownLangs.indexOf(firstSegment) !== -1) {
            return firstSegment;
        }

        // Default language (try from linguaSwitcher or fallback to 'ru')
        return (typeof linguaSwitcher !== 'undefined') ? linguaSwitcher.defaultLang : 'ru';
    }

    $(document).ready(function() {
        window.linguaDebug('[Lingua Switcher Fix v4.0] Initializing...');
        window.linguaDebug('[Lingua Switcher Fix v5.2.10] DOM ready - starting language switcher fix');

        // v5.2.10: CRITICAL DEBUG - Check if menu items exist in DOM
        var allMenuItems = $('.menu-item');
        var switcherItems = $('.menu-item-object-lingua_switcher');
        window.linguaDebug('[Lingua Switcher Fix v5.2.10] Total menu items in DOM:', allMenuItems.length);
        window.linguaDebug('[Lingua Switcher Fix v5.2.10] Lingua switcher items in DOM:', switcherItems.length);

        if (switcherItems.length > 0) {
            window.linguaDebug('[Lingua Switcher Fix v5.2.10] Switcher items found:');
            switcherItems.each(function(i) {
                var $item = $(this);
                var href = $item.find('a').attr('href');
                var text = $item.find('a').text().trim();
                window.linguaDebug('  Item ' + i + ': text="' + text + '", href="' + href + '", classes="' + this.className + '"');
            });
        } else {
            console.error('[Lingua Switcher Fix v5.2.10] ❌ NO SWITCHER ITEMS FOUND IN DOM!');
            console.error('[Lingua Switcher Fix v5.2.10] This means WordPress/theme did not output language switcher menu items');
            console.error('[Lingua Switcher Fix v5.2.10] Check: 1) Menu is assigned to location, 2) Lingua Switcher items added to menu, 3) No theme/plugin hiding menu');
            // Continue anyway to show debugging info
        }

        // Detect language from URL (most reliable)
        var currentLang = detectLanguageFromURL();
        window.linguaDebug('[Lingua Switcher Fix] Detected language from URL:', currentLang);

        // Get language data
        if (typeof linguaSwitcher === 'undefined' || !linguaSwitcher.languages) {
            console.warn('[Lingua Switcher Fix] linguaSwitcher data not available');
            console.warn('[Lingua Switcher Fix] window.linguaSwitcher:', window.linguaSwitcher);
            return;
        }

        window.linguaDebug('[Lingua Switcher Fix v5.2.9 DEBUG] linguaSwitcher.languages:', linguaSwitcher.languages);
        window.linguaDebug('[Lingua Switcher Fix v5.2.9 DEBUG] linguaSwitcher.currentLang:', linguaSwitcher.currentLang);
        window.linguaDebug('[Lingua Switcher Fix v5.2.9 DEBUG] linguaSwitcher.defaultLang:', linguaSwitcher.defaultLang);

        var languages = linguaSwitcher.languages;
        var langData = languages[currentLang];

        window.linguaDebug('[Lingua Switcher Fix v5.2.9 DEBUG] Looking for langData for:', currentLang);
        window.linguaDebug('[Lingua Switcher Fix v5.2.9 DEBUG] langData found:', langData);

        if (!langData) {
            console.error('[Lingua Switcher Fix] No data for language:', currentLang);
            console.error('[Lingua Switcher Fix] Available languages:', Object.keys(languages));
            return;
        }

        // Find switcher menu items
        var switcherItems = $('.menu-item-object-lingua_switcher');
        if (switcherItems.length === 0) {
            window.linguaDebug('[Lingua Switcher Fix] No switcher items found');
            return;
        }

        // v4.0 FIX: Check if there's already a parent dropdown structure
        var currentLanguageItems = switcherItems.filter('.menu-item-has-children');

        if (currentLanguageItems.length === 0) {
            // NO parent/child structure exists - this happens when menu has individual language items
            // We need to convert flat list into parent/child structure
            window.linguaDebug('[Lingua Switcher Fix v4.0] No dropdown structure found - will restructure menu');
            restructureFlatLanguageMenu(switcherItems, currentLang, languages);
            return;
        }

        // v4.0.1 FIX: Check which parent represents current language
        var correctParent = null;
        currentLanguageItems.each(function() {
            var $parent = $(this);
            var href = $parent.find('> a').attr('href') || '';

            // Detect language from href
            var parentLang = null;
            var urlMatch = href.match(/\/([a-z]{2})(?:\/|\?|#|$)/);
            if (urlMatch) {
                parentLang = urlMatch[1];
            } else if (href.indexOf(window.location.origin) === 0 || href.indexOf('/') === 0) {
                parentLang = linguaSwitcher.defaultLang;
            }

            if (parentLang === currentLang) {
                correctParent = $parent;
            }
        });

        // If no correct parent found, OR parent exists but for wrong language - restructure
        if (!correctParent) {
            window.linguaDebug('[Lingua Switcher Fix v4.0] Wrong parent language - restructuring');
            restructureFlatLanguageMenu(switcherItems, currentLang, languages);
            return;
        }

        // v4.0 FIX: Even if parent exists, check if dropdown is missing
        // This happens when menu has "Current Language" + individual languages as siblings
        var parentHasDropdown = correctParent.find('.sub-menu, .wd-sub-menu').length > 0;
        if (!parentHasDropdown) {
            window.linguaDebug('[Lingua Switcher Fix v4.0] Parent exists but no dropdown - restructuring');
            restructureFlatLanguageMenu(switcherItems, currentLang, languages);
            return;
        }

        window.linguaDebug('[Lingua Switcher Fix v4.0] Correct parent found, using existing structure');

        // v5.2.25: CRITICAL CHECK - If PHP already generated correct content (has data-no-translation span), DON'T MODIFY!
        // This prevents duplicate content on themes that don't aggressively cache menus
        var $link = correctParent.find('> a');
        var hasPhpContent = $link.find('[data-no-translation]').length > 0;

        if (hasPhpContent) {
            window.linguaDebug('[Lingua Switcher Fix v5.2.25] ✅ PHP already generated correct content - skipping JS modification');
            window.linguaDebug('[Lingua Switcher Fix v5.2.25] This theme does not cache menus aggressively (good!)');
            return; // Exit early - no need to modify anything
        }

        window.linguaDebug('[Lingua Switcher Fix v5.2.25] No PHP content found - applying JS fix for cached menu');

        // v4.0.1 FIX: Update ONLY the correct parent, not all parents
        var newTitle = langData.flag + ' ' + langData.name;

        // Try to find .nav-link-text first, fallback to .menu-link text
        var $textSpan = $link.find('.nav-link-text');
        if ($textSpan.length > 0) {
            $textSpan.text(newTitle);
        } else {
            // Fallback: update link text directly (preserving child elements like arrows)
            var linkHtml = $link.html();
            // Remove existing text nodes but keep HTML elements
            $link.contents().filter(function() {
                return this.nodeType === 3; // Text node
            }).remove();
            // Prepend new title
            $link.prepend(newTitle);
        }

        window.linguaDebug('[Lingua Switcher Fix] ✓ Updated parent to:', newTitle);

        // v4.0.1 FIX: Work only with correct parent, not all parents
        var $dropdown = correctParent.find('> .wd-sub-menu, > .sub-menu');

        if ($dropdown.length === 0) {
            console.warn('[Lingua Switcher Fix] No dropdown found for parent');
            return;
        }

        // Get existing language codes from child menu items
        var existingLangs = {};
        var $existingChildren = $dropdown.find('> .menu-item-object-lingua_switcher');

        $existingChildren.each(function() {
                var href = $(this).find('> a').attr('href');
                if (href) {
                    var urlLang = href.match(/\/([a-z]{2})(?:\/|\?|#|$)/);
                    if (urlLang) {
                        existingLangs[urlLang[1]] = $(this);
                    } else if (href.indexOf(window.location.origin) === 0 || href.indexOf('/') === 0) {
                        // This is default language (no prefix)
                        existingLangs[linguaSwitcher.defaultLang] = $(this);
                    }
                }
        });

        window.linguaDebug('[Lingua Switcher Fix] Existing languages in DOM:', Object.keys(existingLangs));

        // Check all configured languages and create missing ones
        var allLanguageCodes = Object.keys(languages);
        // Include default lang if not already in languages
        if (allLanguageCodes.indexOf(linguaSwitcher.defaultLang) === -1) {
            allLanguageCodes.push(linguaSwitcher.defaultLang);
        }

        var missingLangs = [];
        allLanguageCodes.forEach(function(langCode) {
            // Skip current language - it shouldn't be in dropdown (it's the parent)
            if (!existingLangs[langCode] && langCode !== currentLang) {
                missingLangs.push(langCode);
            }
        });

        if (missingLangs.length > 0) {
                window.linguaDebug('[Lingua Switcher Fix] ⚠️ Missing languages:', missingLangs);

                // Get a template child item to clone (use first existing child)
                var $template = $existingChildren.first();
                if ($template.length === 0) {
                    console.error('[Lingua Switcher Fix] Cannot create missing items - no template available');
                    return;
                }

                // Create each missing language item
                missingLangs.forEach(function(langCode) {
                    var langInfo = languages[langCode];
                    if (!langInfo && langCode === linguaSwitcher.defaultLang) {
                        // Get default language info from languages object
                        langInfo = languages[linguaSwitcher.defaultLang];
                    }

                    if (!langInfo) {
                        console.warn('[Lingua Switcher Fix] No language data for:', langCode);
                        return;
                    }

                    // Clone template and modify
                    var $newItem = $template.clone();

                    // Update href - build correct URL for the TARGET language (langCode)
                    // Get current URL without language prefix to use as base
                    var currentPath = window.location.pathname;
                    var basePath = currentPath;

                    // Remove current language prefix if exists
                    if (currentLang !== linguaSwitcher.defaultLang) {
                        basePath = currentPath.replace(/^\/[a-z]{2}(\/|$)/, '/');
                    }

                    // Build URL for target language
                    var newUrl;
                    if (langCode === linguaSwitcher.defaultLang) {
                        // Default language - use basePath without prefix
                        newUrl = basePath;
                    } else {
                        // Other language - add its prefix
                        newUrl = '/' + langCode + basePath;
                    }

                    // Clean up URL
                    newUrl = newUrl.replace(/\/+/g, '/'); // Remove double slashes

                    var $link = $newItem.find('> a');
                    $link.attr('href', newUrl);

                    // Update text - check if .nav-link-text exists (some themes) or update link directly
                    var $navText = $link.find('.nav-link-text');
                    if ($navText.length > 0) {
                        $navText.text(langInfo.flag + ' ' + langInfo.name);
                    } else {
                        $link.text(langInfo.flag + ' ' + langInfo.name);
                    }

                    // Update ID and classes to avoid conflicts
                    $newItem.attr('id', 'menu-item-lingua-' + langCode + '-dynamic');

                    // Add to dropdown
                    $dropdown.append($newItem);

                    window.linguaDebug('[Lingua Switcher Fix] ✓ Created missing item for:', langCode, '->', newUrl);
                });
        }

        // v3.0.54: Fix visibility - SHOW all items first (they may be hidden by cached HTML from wrong language)
        // Then HIDE only the duplicate of current language
        // Re-query to include dynamically created items
        switcherItems = $('.menu-item-object-lingua_switcher');
        window.linguaDebug('[Lingua Switcher Fix] Processing', switcherItems.length, 'items (including dynamic), currentLang:', currentLang);

        switcherItems.each(function(index) {
            var $item = $(this);
            var $link = $item.find('> a');
            var href = $link.attr('href');
            var text = $link.text().trim();

            // Skip parent (dropdown) items - they should always be visible
            if ($item.hasClass('menu-item-has-children')) {
                window.linguaDebug('[Lingua Switcher Fix]', index, 'PARENT (skipped):', text);
                return; // continue to next iteration
            }

            window.linguaDebug('[Lingua Switcher Fix]', index, 'CHILD:', text, 'href:', href);

            if (href) {
                // Match language code in URL: /en/ or /en? or /en# or /en at end
                var urlLang = href.match(/\/([a-z]{2})(?:\/|\?|#|$)/);
                var isCurrentLang = false;

                if (urlLang && urlLang[1] === currentLang) {
                    isCurrentLang = true;
                    window.linguaDebug('[Lingua Switcher Fix]   → Match! urlLang:', urlLang[1], '=== currentLang:', currentLang);
                } else if (!urlLang && currentLang === linguaSwitcher.defaultLang) {
                    isCurrentLang = true;
                    window.linguaDebug('[Lingua Switcher Fix]   → Match! Default language');
                }

                // CRITICAL FIX v3.0.54: Reset visibility for all items
                // WoodMart uses visibility:hidden, not display:none
                if (isCurrentLang) {
                    window.linguaDebug('[Lingua Switcher Fix]   → HIDING:', text);
                    $item.css({
                        'display': 'none',
                        'visibility': 'hidden'
                    });
                    $item.attr('aria-hidden', 'true');
                } else {
                    window.linguaDebug('[Lingua Switcher Fix]   → SHOWING:', text);
                    $item.css({
                        'display': '',
                        'visibility': 'visible'
                    });
                    $item.attr('aria-hidden', 'false');
                }
            }
        });

        // v3.0.59: Final check - verify created elements are still in DOM and visible
        setTimeout(function() {
            var dynamicItems = $('.menu-item-object-lingua_switcher[id*="dynamic"]');
            if (dynamicItems.length > 0) {
                window.linguaDebug('[Lingua Switcher Fix] ✓ Final check: Found ' + dynamicItems.length + ' dynamic items');
                dynamicItems.each(function() {
                    var $item = $(this);
                    var text = $item.find('a').text().trim();
                    var computed = window.getComputedStyle($item[0]);
                    var isVisible = computed.display !== 'none' && computed.visibility !== 'hidden';
                    var rect = $item[0].getBoundingClientRect();
                    var parentDropdown = $item.closest('.wd-sub-menu');
                    var parentComputed = parentDropdown.length > 0 ? window.getComputedStyle(parentDropdown[0]) : null;

                    window.linguaDebug('[Lingua Switcher Fix]   - ' + text + ' (visible: ' + isVisible + ', display: ' + computed.display + ', visibility: ' + computed.visibility + ')');
                    window.linguaDebug('[Lingua Switcher Fix]     Position: top=' + rect.top + ', left=' + rect.left + ', width=' + rect.width + ', height=' + rect.height);
                    window.linguaDebug('[Lingua Switcher Fix]     HTML: ' + $item[0].outerHTML.substring(0, 200));
                    if (parentComputed) {
                        window.linguaDebug('[Lingua Switcher Fix]     Parent dropdown: display=' + parentComputed.display + ', visibility=' + parentComputed.visibility + ', opacity=' + parentComputed.opacity);
                    }
                });
            }
        }, 500);
    });

})(jQuery);
