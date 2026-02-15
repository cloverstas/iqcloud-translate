/**
 * Lingua Language Switcher Fix v4.0
 * Universal solution for cached menu - detects language from URL directly
 *
 * Problem: Theme caches menu HTML, PHP data may be wrong
 * Solution: Detect language from browser URL + update menu on client-side
 * v3.0.55: CRITICAL FIX - Use visibility:visible (WoodMart uses visibility not display)
 * v3.0.57: CRITICAL FIX - Dynamically create missing language items when absent from cached HTML
 * v3.0.58: CRITICAL FIX - Force browser cache clear
 * v3.0.59: CRITICAL FIX - Update link text directly (WoodMart has no .nav-link-text)
 * v4.0: PHP backend now sets $LINGUA_LANGUAGE in constructor
 */

(function($) {
    'use strict';

    /**
     * v4.0: Restructure flat language menu into parent/child dropdown structure
     * This handles case when menu has individual language items (not "Current Language" placeholder)
     */
    function restructureFlatLanguageMenu(switcherItems, currentLang, languages) {
        window.linguaDebug('[Lingua v4.0] Restructuring flat menu with', switcherItems.length, 'items');

        // Find which item represents current language
        var currentLangItem = null;
        var otherLangItems = [];

        switcherItems.each(function(index) {
            var $item = $(this);
            var $link = $item.find('> a');
            var href = $link.attr('href') || '';

            // Detect language from href
            var itemLang = null;
            var urlMatch = href.match(/\/([a-z]{2})(?:\/|\?|#|$)/);
            if (urlMatch) {
                itemLang = urlMatch[1];
            } else if (href.indexOf(window.location.origin) === 0 || href.indexOf('/') === 0) {
                // Default language (no prefix)
                itemLang = linguaSwitcher.defaultLang;
            }

            window.linguaDebug('[Lingua v4.0 DEBUG] Item', index, '- lang:', itemLang, 'current:', currentLang, 'hasChildren:', $item.hasClass('menu-item-has-children'));

            if (itemLang === currentLang) {
                currentLangItem = $item;
            } else if (itemLang !== null) {
                // Only add items that are NOT the current language
                otherLangItems.push($item);
            }
        });

        if (!currentLangItem) {
            console.warn('[Lingua v4.0] Current language item not found in menu');
            return;
        }

        window.linguaDebug('[Lingua v4.0] Found current lang item:', currentLang);
        window.linguaDebug('[Lingua v4.0] Found', otherLangItems.length, 'other language items');

        // Transform current language item into parent dropdown
        currentLangItem.addClass('menu-item-has-children');

        // CRITICAL: Force visibility on parent item
        currentLangItem.css({
            'display': 'flex',
            'visibility': 'visible',
            'opacity': '1'
        });

        var $link = currentLangItem.find('> a');

        // Update text to show current language
        var langData = languages[currentLang];
        var newTitle = langData.flag + ' ' + langData.name;

        // Try to find .nav-link-text first, fallback to direct link text
        var $textSpan = $link.find('.nav-link-text');
        if ($textSpan.length > 0) {
            $textSpan.text(newTitle);
        } else {
            $link.text(newTitle);
        }

        window.linguaDebug('[Lingua v4.0] Updated current language to:', newTitle);

        // Create dropdown menu for other languages
        var dropdownClass = 'wd-sub-menu'; // WoodMart theme class
        var $dropdown = $('<ul class="' + dropdownClass + '" style="display: none;"></ul>');

        // Insert dropdown after current language link FIRST
        $link.after($dropdown);

        // Now move other language items into dropdown
        $.each(otherLangItems, function(i, $item) {
            // CRITICAL: Check that this item is not a parent/ancestor of the dropdown
            if ($item[0].contains($dropdown[0])) {
                console.warn('[Lingua v4.0] Skipping item - it contains the dropdown!');
                return; // Skip this item
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

        // Detect language from URL (most reliable)
        var currentLang = detectLanguageFromURL();
        window.linguaDebug('[Lingua Switcher Fix] Detected language from URL:', currentLang);

        // Get language data
        if (typeof linguaSwitcher === 'undefined' || !linguaSwitcher.languages) {
            console.warn('[Lingua Switcher Fix] linguaSwitcher data not available');
            return;
        }

        var languages = linguaSwitcher.languages;
        var langData = languages[currentLang];

        if (!langData) {
            console.error('[Lingua Switcher Fix] No data for language:', currentLang);
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

        // v4.0.1: Check if inline fix already updated the parent
        if (window.linguaInlineFixApplied) {
            window.linguaDebug('[Lingua Switcher Fix] Skipping parent update - inline fix already applied');
        } else {
            // v4.0.1 FIX: Update ONLY the correct parent, not all parents
            var newTitle = langData.flag + ' ' + langData.name;
            var $link = correctParent.find('> a');

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
        }

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
