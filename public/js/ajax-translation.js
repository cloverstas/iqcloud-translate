/**
 * Lingua Dynamic Content Translation Handler
 * Uses MutationObserver to detect and translate dynamically added content
 *
 * @package Lingua
 * @version 5.2.12 - Skip language switcher elements to prevent interference
 */

(function($) {
    'use strict';

    function LinguaDynamicTranslator() {
        var self = this;
        var observer = null;
        var currentLanguage = null;
        var observerConfig = {
            attributes: true,
            childList: true,
            characterData: false,
            subtree: true
        };
        var processingMutations = false;

        /**
         * Detect current language from URL
         */
        this.detectLanguage = function() {
            var path = window.location.pathname;
            var match = path.match(/^\/([a-z]{2})\//);
            if (match) {
                currentLanguage = match[1];
                window.linguaDebug('🌍 Lingua Dynamic: Detected language:', currentLanguage);
            }
            return currentLanguage;
        };

        /**
         * Check if we should skip this node
         */
        this.shouldSkipNode = function(node) {
            if (!node || !node.nodeType) return true;

            // Skip script, style, head elements
            if (node.nodeType === 1) {
                var tagName = node.tagName ? node.tagName.toLowerCase() : '';
                if (['script', 'style', 'head', 'noscript', 'meta', 'link'].indexOf(tagName) !== -1) {
                    return true;
                }

                // Skip elements with data-no-translation attribute
                if ($(node).attr('data-no-translation') || $(node).closest('[data-no-translation]').length > 0) {
                    return true;
                }

                // Skip Lingua admin elements
                if ($(node).hasClass('lingua-modal') || $(node).closest('.lingua-modal').length > 0) {
                    return true;
                }

                // Skip admin bar
                if ($(node).attr('id') === 'wpadminbar' || $(node).closest('#wpadminbar').length > 0) {
                    return true;
                }

                // v5.2.12: Skip language switcher elements (to prevent interference with switcher-fix.js)
                if ($(node).hasClass('lingua-language-switcher-container') ||
                    $(node).closest('.lingua-language-switcher-container').length > 0) {
                    return true;
                }
            }

            return false;
        };

        /**
         * Extract translatable strings from a node
         */
        this.extractTranslatableStrings = function(node) {
            var strings = [];
            var nodes = [];

            if (self.shouldSkipNode(node)) {
                return { strings: strings, nodes: nodes };
            }

            // Get all text nodes
            var $node = $(node);
            var $allNodes = $node.find('*').addBack();

            $allNodes.contents().each(function() {
                if (this.nodeType === 3 && /\S/.test(this.nodeValue)) {
                    // v5.2.184: Don't trim - preserve &nbsp; characters
                    var text = this.nodeValue;
                    var trimmedText = text.replace(/^[\s\u00A0]+|[\s\u00A0]+$/g, '');
                    if (trimmedText.length > 0 && !self.shouldSkipNode(this.parentNode)) {
                        strings.push(text);
                        nodes.push(this);

                        // Hide content until translated
                        if (lingua_dynamic.hide_until_translated) {
                            this.nodeValue = '';
                        }
                    }
                }
            });

            // Get translatable attributes (alt, title, placeholder)
            $allNodes.each(function() {
                if (self.shouldSkipNode(this)) return;

                var attrs = ['alt', 'title', 'placeholder', 'aria-label'];
                for (var i = 0; i < attrs.length; i++) {
                    var attrValue = $(this).attr(attrs[i]);
                    if (attrValue && attrValue.trim().length > 0) {
                        strings.push(attrValue.trim());
                        nodes.push({ element: this, attribute: attrs[i], original: attrValue });

                        // Hide attribute content until translated
                        if (lingua_dynamic.hide_until_translated && attrs[i] !== 'src' && attrs[i] !== 'href') {
                            $(this).attr(attrs[i], '');
                        }
                    }
                }
            });

            return { strings: strings, nodes: nodes };
        };

        /**
         * Get translations via AJAX
         */
        this.getTranslations = function(strings, nodes) {
            if (strings.length === 0) {
                window.linguaDebug('⚠️ Lingua Dynamic: No strings to translate');
                return;
            }

            window.linguaDebug('🔄 Lingua Dynamic: Requesting translations for', strings.length, 'strings');

            $.ajax({
                url: lingua_dynamic.ajax_url,
                type: 'POST',
                data: {
                    action: 'lingua_get_dynamic_translations',
                    nonce: lingua_dynamic.nonce,
                    strings: JSON.stringify(strings),
                    language: currentLanguage
                },
                success: function(response) {
                    if (response.success && response.data.translations) {
                        window.linguaDebug('✅ Lingua Dynamic: Received', Object.keys(response.data.translations).length, 'translations');
                        self.applyTranslations(response.data.translations, strings, nodes);
                    } else {
                        console.warn('⚠️ Lingua Dynamic: Translation request failed:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Lingua Dynamic: AJAX error:', error);
                }
            });
        };

        /**
         * Apply translations to nodes
         */
        this.applyTranslations = function(translations, strings, nodes) {
            self.pauseObserver();

            for (var i = 0; i < strings.length; i++) {
                var original = strings[i];
                var node = nodes[i];
                var translation = translations[original];

                if (node.nodeType === 3) {
                    // Text node
                    if (translation && translation.trim().length > 0) {
                        node.nodeValue = translation;
                    } else {
                        // No translation found - restore original
                        node.nodeValue = original;
                    }
                } else if (node.element && node.attribute) {
                    // Attribute
                    if (translation && translation.trim().length > 0) {
                        $(node.element).attr(node.attribute, translation);
                    } else {
                        // No translation found - restore original
                        $(node.element).attr(node.attribute, node.original || original);
                    }
                }
            }

            window.linguaDebug('✅ Lingua Dynamic: Applied translations');
            self.resumeObserver();
        };

        /**
         * Process mutations detected by observer
         */
        this.processMutations = function(mutations) {
            if (processingMutations) {
                return;
            }

            processingMutations = true;
            var allStrings = [];
            var allNodes = [];

            mutations.forEach(function(mutation) {
                // Handle added nodes
                for (var i = 0; i < mutation.addedNodes.length; i++) {
                    var node = mutation.addedNodes[i];
                    var extracted = self.extractTranslatableStrings(node);
                    allStrings = allStrings.concat(extracted.strings);
                    allNodes = allNodes.concat(extracted.nodes);
                }

                // Handle attribute changes
                if (mutation.type === 'attributes' && mutation.attributeName) {
                    var attrs = ['alt', 'title', 'placeholder', 'aria-label'];
                    if (attrs.indexOf(mutation.attributeName) !== -1) {
                        var attrValue = $(mutation.target).attr(mutation.attributeName);
                        if (attrValue && attrValue.trim().length > 0 && !self.shouldSkipNode(mutation.target)) {
                            allStrings.push(attrValue.trim());
                            allNodes.push({ element: mutation.target, attribute: mutation.attributeName });
                        }
                    }
                }
            });

            if (allStrings.length > 0) {
                self.getTranslations(allStrings, allNodes);
            }

            processingMutations = false;
        };

        /**
         * Pause observer
         */
        this.pauseObserver = function() {
            if (observer) {
                observer.disconnect();
            }
        };

        /**
         * Resume observer
         */
        this.resumeObserver = function() {
            if (observer && currentLanguage) {
                observer.observe(document.body, observerConfig);
            }
        };

        /**
         * Initialize
         */
        this.initialize = function() {
            window.linguaDebug('🚀 Lingua Dynamic Translator initializing...');

            // Detect language
            self.detectLanguage();

            // Only activate if we're on a translated page
            if (!currentLanguage) {
                window.linguaDebug('ℹ️ Lingua Dynamic: Not on a translated page, handler inactive');
                return;
            }

            // Create MutationObserver
            observer = new MutationObserver(function(mutations) {
                self.processMutations(mutations);
            });

            // Start observing
            self.resumeObserver();

            // v1.2.3 FIX: DISABLED translateExistingContent() - causes flickering!
            // Server-side output buffer should handle all initial translations.
            // MutationObserver above will still catch dynamically added content (AJAX filters, etc.)
            // OLD CODE that caused flickering:
            // window.linguaDebug('🔄 Lingua: Translating existing page content...');
            // self.translateExistingContent();

            window.linguaDebug('✅ Lingua Dynamic Translator initialized for language:', currentLanguage);
            window.linguaDebug('ℹ️ Server-side translation enabled. Client-side only for dynamic content.');
        };

        /**
         * v5.0.13: Translate all existing content on page load
         * This handles elements that exist before MutationObserver starts
         * v5.2.8: Fixed method call - use getTranslations instead of getTranslationsFromServer
         */
        this.translateExistingContent = function() {
            // Find all text nodes on the page
            var textNodesToTranslate = [];
            var nodesToTranslate = [];
            var walker = document.createTreeWalker(
                document.body,
                NodeFilter.SHOW_TEXT,
                {
                    acceptNode: function(node) {
                        // Skip if parent is script, style, etc
                        var parent = node.parentElement;
                        if (!parent) return NodeFilter.FILTER_REJECT;

                        var tagName = parent.tagName.toLowerCase();
                        if (['script', 'style', 'noscript'].indexOf(tagName) !== -1) {
                            return NodeFilter.FILTER_REJECT;
                        }

                        // Skip if in lingua modal or admin bar
                        if (parent.closest('.lingua-modal') || parent.closest('#wpadminbar')) {
                            return NodeFilter.FILTER_REJECT;
                        }

                        // v5.2.12: Skip language switcher (to prevent interference with switcher-fix.js)
                        if (parent.closest('.lingua-language-switcher-container')) {
                            return NodeFilter.FILTER_REJECT;
                        }

                        var text = node.nodeValue.trim();
                        if (text.length > 0) {
                            return NodeFilter.FILTER_ACCEPT;
                        }

                        return NodeFilter.FILTER_REJECT;
                    }
                },
                false
            );

            var node;
            while (node = walker.nextNode()) {
                // v5.2.184: Don't use trim() - it removes &nbsp; characters!
                // Instead, check if there's meaningful content
                var text = node.nodeValue;
                var trimmedText = text.replace(/^[\s\u00A0]+|[\s\u00A0]+$/g, ''); // Custom trim that preserves nbsp for check
                if (trimmedText.length > 0) {
                    // Store original nodeValue (with nbsp preserved)
                    textNodesToTranslate.push(text);
                    nodesToTranslate.push(node);
                }
            }

            if (textNodesToTranslate.length > 0) {
                window.linguaDebug('📝 Lingua: Found ' + textNodesToTranslate.length + ' text nodes to translate');
                // v5.2.8: Fixed - call getTranslations with both strings and nodes
                self.getTranslations(textNodesToTranslate, nodesToTranslate);
            }
        };

        // Auto-initialize
        self.initialize();
    }

    // Initialize when DOM is ready
    $(document).ready(function() {
        if (typeof lingua_dynamic !== 'undefined') {
            new LinguaDynamicTranslator();
        }
    });

})(jQuery);
