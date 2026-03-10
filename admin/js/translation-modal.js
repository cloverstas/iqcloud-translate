/**
 * Lingua Translation Modal JavaScript v2.1.3-unified-save-fix
 * Управляет модальным окном для ручного перевода контента
 * Updated: v2.1.3 comprehensive unified save system with WooCommerce support
 */

// v5.4.1: Ensure linguaDebug exists before use (may load before inline footer script)
if (typeof window.linguaDebug !== 'function') {
    window.linguaDebug = function() {};
}

// 🔥 CRITICAL DEBUG: Check if this file loads at all
window.linguaJsTestGlobal = true;
window.linguaDebug('🔥🔥🔥 LINGUA CRITICAL: translation-modal.js starting to load!');
// Debug message removed - v3.0 production ready

// Immediate error catching wrapper
try {

// LINGUA v2.1.4-unified-final
window.linguaDebug('🚀 Lingua Translation Modal v2.1.4-unified-final JavaScript loaded');
window.linguaDebug('🔧 LINGUA v2.1.4: Enhanced DOM injection debugging enabled');
window.linguaDebug('📅 Last updated:', new Date().toISOString());

// CRITICAL: Check if script loads without errors
window.linguaDebugLoaded = true;
window.linguaDebug('✅ LINGUA DEBUG: translation-modal.js loaded successfully');
try {
    window.linguaDebug('✅ LINGUA DEBUG: jQuery available:', typeof jQuery);
    window.linguaDebug('✅ LINGUA DEBUG: lingua_admin object:', window.lingua_admin);
} catch (e) {
    console.error('❌ LINGUA DEBUG: Error checking dependencies:', e);
}

// CRITICAL CACHE DEBUG: Verify this file loads with new changes
window.linguaDebug('🔥 LINGUA v2.1.4 CACHE TEST: translation-modal.js SUCCESSFULLY LOADED WITH NEW CHANGES!');
window.linguaDebug('🔥 LINGUA v2.1.4 CACHE TEST: Save button debugging should work now!');
window.linguaDebug('🔧 Features: Enhanced context labeling, string grouping, v2.0 architecture support');

jQuery(document).ready(function($) {
    window.linguaDebug('✅ Lingua Translation Modal v2.0 initialized');

    // v5.2.110: Listen for nonce updates from iframe via postMessage
    // Set flag when nonce is updated to signal that extractContent can proceed
    window.linguaNonceReady = false;
    window.addEventListener('message', function(event) {
        if (event.data && event.data.type === 'lingua_nonce_update') {
            if (window.lingua_admin) {
                var oldNonce = window.lingua_admin.nonce ? window.lingua_admin.nonce.substr(0, 10) : 'undefined';
                window.lingua_admin.nonce = event.data.nonce;
                var newNonce = event.data.nonce.substr(0, 10);
                window.linguaDebug('[Lingua v5.2.110 PARENT] ✅ Received nonce update via postMessage');
                window.linguaDebug('[Lingua v5.2.110 PARENT] Old nonce: ' + oldNonce + '...');
                window.linguaDebug('[Lingua v5.2.110 PARENT] New nonce: ' + newNonce + '...');

                // Set flag that nonce is ready
                window.linguaNonceReady = true;
                window.linguaDebug('[Lingua v5.2.110 PARENT] ✅ Nonce ready flag set to TRUE');
            } else {
                console.error('[Lingua v5.2.110 PARENT] ❌ Cannot update nonce - lingua_admin object not found');
            }
        }
    });
    window.linguaDebug('[Lingua v5.2.110 PARENT] postMessage listener registered for nonce updates');

    // Глобальные переменные для модального окна (делаем объект глобальным)
    window.translationModal = {
        currentPostId: null,
        currentLanguage: null,
        translatedBlocks: {},
        translatedElements: {}, // v3.7: Track translated DOM elements for re-editing
        
        /**
         * Инициализация модального окна
         */
        init: function() {
            this.bindEvents();
            this.createModalHTML();
        },
        
        /**
         * Привязка событий к элементам
         */
        bindEvents: function() {
            // НЕ привязываем обработчик клика здесь - он уже есть в admin.js
            // $(document).on('click', '.lingua-translate-button', this.openModal.bind(this));
            
            // Закрытие слайд-панели (только по кнопке ✖️)
            $(document).on('click', '.lingua-modal-close', this.closeModal.bind(this));
            // Убираем закрытие кликом вне панели - слайд-панель закрывается только кнопкой
            
            // Кнопка извлечения контента
            $(document).on('click', '#lingua-extract-content', this.extractContent.bind(this));
            
            // Auto-translate buttons removed (Pro feature removed)
            
            // Кнопка сохранения переводов
            $(document).on('click', '#lingua-save-translation', this.saveTranslation.bind(this));
            window.linguaDebug('🔍 LINGUA v2.1.4 EVENT BINDING: Save translation button event bound');

            // v3.2: Кнопка очистки плохих переводов
            $(document).on('click', '#lingua-clear-bad-translations', this.clearBadTranslations.bind(this));

            // v5.0.13: Кнопка очистки всех переводов страницы
            $(document).on('click', '#lingua-clear-translations', this.clearPageTranslations.bind(this));

            // UNIVERSAL CLICK DIAGNOSTICS: Catch ALL clicks on save button
            $(document).on('click', 'button', function(e) {
                if (this.id === 'lingua-save-translation') {
                    window.linguaDebug('🔍 LINGUA v2.1.4 UNIVERSAL CLICK: Save button clicked via universal handler', this);
                }
                // Log ALL button clicks to find the actual save button
                if ($(this).text().toLowerCase().includes('save') || $(this).text().toLowerCase().includes('сохранить')) {
                    window.linguaDebug('🔍 LINGUA v2.1.4 BUTTON CLICK: Possible save button clicked', {
                        id: this.id,
                        class: this.className,
                        text: $(this).text(),
                        element: this
                    });
                }
            });

            // Изменение целевого языка
            $(document).on('change', '#lingua-target-lang', this.onLanguageChange.bind(this));

            // Live search functionality
            $(document).on('input', '#lingua-live-search', this.debounce(this.performLiveSearch.bind(this), 300));
            $(document).on('keydown', '#lingua-live-search', this.handleSearchKeydown.bind(this));

            // Drag resize functionality
            $(document).on('mousedown', '.lingua-resize-handle', this.startResize.bind(this));

            // Auto-translate modern button handler removed (Pro feature removed)

            // v3.2: Tab navigation
            $(document).on('click', '.lingua-tab-button', this.switchTab.bind(this));
        },
        
        /**
         * Создание HTML модального окна, если его нет на странице
         */
        createModalHTML: function() {
            // v5.2.41: Modal template is now included in footer via PHP
            // No AJAX loading needed - template should already exist in DOM
            if ($('#lingua-translation-modal').length === 0) {
                console.warn('[Lingua v5.2.41] Modal template not found in DOM. Waiting for footer to load...');
                // Modal will be available after DOMContentLoaded (footer renders last)
            } else {
                window.linguaDebug('[Lingua v5.2.41] Modal template found in DOM');
            }
        },

        /**
         * v3.2: Switch between tabs
         */
        switchTab: function(e) {
            e.preventDefault();
            var $button = $(e.currentTarget);
            var targetTab = $button.data('tab');

            window.linguaDebug('🔄 LINGUA v3.2: Switching to tab:', targetTab);

            // Update active state on buttons
            $('.lingua-tab-button').removeClass('active');
            $button.addClass('active');

            // Update active state on panels
            $('.lingua-tab-panel').removeClass('active');
            var $targetPanel = $('.lingua-tab-panel[data-tab="' + targetTab + '"]');
            $targetPanel.addClass('active');

            window.linguaDebug('🔄 LINGUA v3.2: Active panels count:', $('.lingua-tab-panel.active').length);
            window.linguaDebug('🔄 LINGUA v3.2: Target panel ID:', $targetPanel.attr('id'));

            // Scroll to top of modal body when switching tabs
            $('.lingua-modal-body').scrollTop(0);
        },
        
        /**
         * Открытие модального окна
         */
        openModal: function(postIdOrEvent) {
            window.linguaDebug('🚪 LINGUA DEBUG: openModal called with:', postIdOrEvent);
            window.linguaDebug('🚪 LINGUA DEBUG: Current state before opening:', {
                currentPostId: this.currentPostId,
                linguaDebugLoaded: window.linguaDebugLoaded,
                jQueryAvailable: typeof jQuery,
                lingua_admin: window.lingua_admin
            });

            // v5.0.6: Read page context from button attributes
            var $button = $('#wp-admin-bar-lingua-translate-page a.ab-item');
            this.currentPageType = $button.attr('data-page-type') || 'post';
            this.currentTermId = $button.attr('data-term-id') || '0';
            this.currentTaxonomy = $button.attr('data-taxonomy') || '';

            window.linguaDebug('🔍 LINGUA v5.0.6: Page context in openModal:', {
                pageType: this.currentPageType,
                termId: this.currentTermId,
                taxonomy: this.currentTaxonomy
            });

            // Если передан event объект
            if (postIdOrEvent && typeof postIdOrEvent === 'object' && postIdOrEvent.preventDefault) {
                window.linguaDebug('🚪 LINGUA DEBUG: Processing event object');
                postIdOrEvent.preventDefault();
                var $eventButton = $(postIdOrEvent.currentTarget);
                var eventPostId = $eventButton.data('post-id');
                window.linguaDebug('🚪 LINGUA DEBUG: Button post-id:', eventPostId);
                if (eventPostId) {
                    this.currentPostId = eventPostId;
                }
            }
            // Если передан напрямую postId
            else if (postIdOrEvent) {
                window.linguaDebug('🚪 LINGUA DEBUG: Using direct post ID:', postIdOrEvent);
                this.currentPostId = postIdOrEvent;
            }
            // Если ничего не передано, но currentPostId уже установлен - используем его
            else if (this.currentPostId) {
                window.linguaDebug('🚪 LINGUA DEBUG: Using existing post ID:', this.currentPostId);
            }

            // v5.0.6: Allow post_id=0 for taxonomy pages
            // v5.5.0: Allow post_id=0 for post_type_archive pages (e.g. /docs/)
            if (this.currentPageType === 'taxonomy') {
                // For taxonomy, use term_id instead of post_id
                if (!this.currentTermId || this.currentTermId === '0') {
                    alert('Invalid taxonomy context');
                    return;
                }
                this.currentPostId = 0; // Taxonomy pages don't have post_id
                window.linguaDebug('🔍 LINGUA v5.0.6: Taxonomy page detected, using term_id:', this.currentTermId);
            } else if (this.currentPageType === 'post_type_archive') {
                // v5.5.0: Post type archive pages (like /docs/) don't have post_id
                this.currentPostId = 0;
                window.linguaDebug('🔍 LINGUA v5.5.0: Post type archive page detected, will use URL-based extraction');
            } else if (!this.currentPostId || this.currentPostId === '0' || this.currentPostId === 0) {
                alert(lingua_admin.strings.invalid_post_id || 'Invalid post ID: ' + this.currentPostId);
                return;
            }
            
            // Показываем слайд-панель
            $('#lingua-translation-modal').show().addClass('lingua-modal-open');

            // Добавляем класс к body для сдвига контента
            $('body').addClass('lingua-panel-open');

            // Восстанавливаем сохраненную ширину панели
            this.restorePanelWidth();

            // Сбрасываем состояние, но сохраняем post ID
            this.resetModal(true);

            // v3.0.2: Автоматический выбор языка по текущему URL
            this.autoSelectLanguageFromUrl();

            // v5.2: Ensure currentLanguage is set before creating iframe
            if (!this.currentLanguage) {
                this.currentLanguage = $('#lingua-target-lang').val();
            }

            // v5.2: Create and show preview iframe with selected language
            this.createPreviewIframe();
            this.updatePreviewIframe(this.currentLanguage);

            // v5.2: Sync iframe position with current panel width
            var $modal = $('.lingua-modal');
            var currentPanelWidth = ($modal.width() / $(window).width()) * 100;
            this.syncIframeResize(currentPanelWidth);

            // Pro restrictions removed - all features unlocked

            // v5.2.41: Skip Media Library auto-load to avoid console errors
            // Media Library will be loaded on-demand when user clicks "Add Media"
            // This prevents mediaelementplayer errors and improves performance

            // v5.2.106: MOVED auto-extract to iframe load event to wait for fresh nonce
            // Content extraction now happens AFTER iframe loads and injects new nonce
            // This fixes "Security check failed - invalid nonce" error
        },

        // applyProRestrictions removed - all features unlocked

        /**
         * v5.2.7: Ensure Media Library scripts are loaded before opening modal
         * Lazy loading reduces initial page load by ~300-400KB
         * Scripts are loaded only when user clicks "Translate Page" button
         */
        ensureMediaLibraryLoaded: function(callback) {
            // Check if Media Library is already loaded
            if (typeof wp !== 'undefined' && typeof wp.media !== 'undefined') {
                window.linguaDebug('[LINGUA v5.2.7] Media Library already loaded, proceeding...');
                callback();
                return;
            }

            window.linguaDebug('[LINGUA v5.2.7] Loading Media Library scripts on demand...');

            // Show loading indicator
            var $modal = $('#lingua-translation-modal');
            var $body = $modal.find('.lingua-modal-body');
            $body.prepend('<div class="lingua-media-loading" style="background: #f0f0f1; padding: 16px; border-radius: 8px; margin-bottom: 16px; text-align: center;">' +
                '<span class="dashicons dashicons-update" style="animation: rotation 2s infinite linear; font-size: 24px;"></span>' +
                '<p style="margin: 8px 0 0 0;">Loading Media Library scripts...</p>' +
                '</div>');

            // Make AJAX request to load scripts
            $.ajax({
                url: lingua_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'lingua_load_media_library_scripts',
                    nonce: lingua_admin.nonce,
                    post_id: this.currentPostId || 0
                },
                success: function(response) {
                    if (response.success) {
                        window.linguaDebug('[LINGUA v5.2.7] Media Library scripts loaded successfully');

                        // Inject styles into <head>
                        if (response.data.styles) {
                            $('head').append(response.data.styles);
                        }

                        // Inject scripts into <head>
                        if (response.data.scripts) {
                            // Create a temporary container to parse HTML
                            var $tempContainer = $('<div>').html(response.data.scripts);
                            var scriptsToLoad = [];

                            // Collect all script URLs
                            $tempContainer.find('script').each(function() {
                                var src = $(this).attr('src');
                                if (src) {
                                    scriptsToLoad.push(src);
                                }
                            });

                            // Load scripts sequentially to maintain dependencies
                            var loadNextScript = function(index) {
                                if (index >= scriptsToLoad.length) {
                                    // All scripts loaded, initialize wp.media
                                    if (response.data.settings) {
                                        // Set up wp.media settings
                                        if (typeof wp !== 'undefined' && typeof wp.media !== 'undefined') {
                                            window.linguaDebug('[LINGUA v5.2.7] Initializing wp.media with settings');
                                        }
                                    }

                                    // Remove loading indicator
                                    $('.lingua-media-loading').fadeOut(300, function() {
                                        $(this).remove();
                                    });

                                    window.linguaDebug('[LINGUA v5.2.7] All Media Library scripts loaded, proceeding with callback');
                                    callback();
                                    return;
                                }

                                var script = document.createElement('script');
                                script.type = 'text/javascript';
                                script.src = scriptsToLoad[index];
                                script.onload = function() {
                                    loadNextScript(index + 1);
                                };
                                script.onerror = function() {
                                    console.error('[LINGUA v5.2.7] Failed to load script:', scriptsToLoad[index]);
                                    loadNextScript(index + 1); // Continue anyway
                                };
                                document.head.appendChild(script);
                            };

                            loadNextScript(0);
                        } else {
                            // No scripts to load, just proceed
                            $('.lingua-media-loading').remove();
                            callback();
                        }
                    } else {
                        console.error('[LINGUA v5.2.7] Failed to load Media Library scripts:', response.data);
                        $('.lingua-media-loading').html('<div style="color: #d63638;">' +
                            '<span class="dashicons dashicons-warning"></span> ' +
                            'Failed to load Media Library. Some features may not work.' +
                            '</div>');
                        setTimeout(function() {
                            $('.lingua-media-loading').fadeOut(300, function() {
                                $(this).remove();
                            });
                        }, 3000);
                        // Proceed anyway - translation still works without Media Library
                        callback();
                    }
                }.bind(this),
                error: function(xhr, status, error) {
                    console.error('[LINGUA v5.2.7] AJAX error loading Media Library:', error);
                    $('.lingua-media-loading').html('<div style="color: #d63638;">' +
                        '<span class="dashicons dashicons-warning"></span> ' +
                        'Network error loading Media Library. Please try again.' +
                        '</div>');
                    setTimeout(function() {
                        $('.lingua-media-loading').fadeOut(300, function() {
                            $(this).remove();
                        });
                    }, 3000);
                    // Proceed anyway
                    callback();
                }
            });
        },

        /**
         * Закрытие слайд-панели
         */
        closeModal: function() {
            var $modal = $('#lingua-translation-modal');
            var $body = $('body');

            $modal.removeClass('lingua-modal-open');

            // Убираем класс с body для возврата контента на место и сбрасываем margin-left
            $body.removeClass('lingua-panel-open lingua-panel-resized').css('margin-left', '');

            // v5.2: Reload page if on translated page (has language prefix in URL)
            var currentPath = window.location.pathname;
            var hasLanguagePrefix = /^\/(en|de|fr|zh|es|it|pt|ja|ko|ar)\//.test(currentPath);

            if (hasLanguagePrefix) {
                window.linguaDebug('[LINGUA v5.2] Closing modal on translated page, reloading...');
                window.location.reload();
                return; // Don't continue with animation
            }

            // Скрываем панель после анимации
            setTimeout(function() {
                $modal.hide();
            }, 300);
            this.resetModal();
        },
        
        /**
         * Сброс состояния модального окна
         */
        resetModal: function(preservePostId) {
            // Сохраняем post ID, если не указано обратное
            var savedPostId = preservePostId ? this.currentPostId : null;
            
            this.currentPostId = savedPostId;
            this.currentLanguage = null;
            this.translatedBlocks = {};
            
            // Очищаем поля
            $('#lingua-original-seo-title, #lingua-translated-seo-title').val('');
            $('#lingua-original-seo-description, #lingua-translated-seo-description').val('');
            $('#lingua-original-title, #lingua-translated-title').val('');
            $('#lingua-original-excerpt, #lingua-translated-excerpt').val('');
            $('#lingua-original-woo-short-desc, #lingua-translated-woo-short-desc').val('');
            // v2.1 unified: Clear unified content container instead of separate containers
            $('#lingua-unified-content').empty();
            $('#lingua-meta-fields').empty();

            // Reset v2.1 summary counters
            $('#lingua-total-content-count').text('0');
            $('#lingua-content-blocks-count').text('0');
            $('#lingua-page-strings-count').text('0');
            $('#lingua-content-summary').hide();

            // Hide sections (updated for v2.1 unified)
            $('#lingua-meta-section').hide();
            $('#lingua-page-content-section').hide();
            $('#lingua-woo-short-desc-section').hide();
            
            // Отключаем кнопку сохранения
            $('#lingua-save-translation').prop('disabled', true);
        },

        /**
         * v3.0.2: Автоматический выбор языка по URL
         * Если страница открыта на /en/, /de/, /fr/ и т.д. - автоматически выбираем этот язык
         * v5.2.56: Если на дефолтном языке, выбираем первый доступный язык для iframe
         */
        autoSelectLanguageFromUrl: function() {
            var currentPath = window.location.pathname;
            window.linguaDebug('🌐 LINGUA v3.0.2: Auto-selecting language from URL:', currentPath);
            window.linguaDebug('🌐 LINGUA v5.2.63 DEBUG: lingua_admin.default_language =', lingua_admin.default_language);

            var $langSelect = $('#lingua-target-lang');

            // Извлекаем код языка из URL (паттерн: /en/, /de/, /fr/ и т.д.)
            var langMatch = currentPath.match(/^\/([a-z]{2})\//);

            if (langMatch && langMatch[1]) {
                var detectedLang = langMatch[1];
                window.linguaDebug('🌐 LINGUA v3.0.2: Detected language from URL:', detectedLang);

                // Проверяем, есть ли такой язык в селекторе
                var $langOption = $langSelect.find('option[value="' + detectedLang + '"]');

                if ($langOption.length > 0) {
                    $langSelect.val(detectedLang);
                    this.currentLanguage = detectedLang;
                    window.linguaDebug('✅ LINGUA v3.0.2: Auto-selected language:', detectedLang);
                } else {
                    // v5.2.56: Язык из URL это дефолтный язык (не найден в селекторе)
                    // Выбираем первый доступный язык
                    window.linguaDebug('⚠️ LINGUA v5.2.56: Language ' + detectedLang + ' is default language (not in selector)');
                    var firstLang = $langSelect.find('option:first').val();
                    if (firstLang) {
                        $langSelect.val(firstLang);
                        this.currentLanguage = firstLang;
                        window.linguaDebug('✅ LINGUA v5.2.56: Auto-selected first available language:', firstLang);
                    }
                }
            } else {
                // v5.2.56: Нет языка в URL значит дефолтный язык
                // Выбираем первый доступный язык из селектора
                window.linguaDebug('ℹ️ LINGUA v5.2.56: No language code in URL (default language), selecting first available');

                // v5.2.63: DEBUG - check all options in selector
                var allOptions = [];
                $langSelect.find('option').each(function() {
                    allOptions.push($(this).val() + ' = ' + $(this).text());
                });
                window.linguaDebug('🌐 LINGUA v5.2.63 DEBUG: Available language options:', allOptions);

                var firstLang = $langSelect.find('option:first').val();
                window.linguaDebug('🌐 LINGUA v5.2.63 DEBUG: First language in selector:', firstLang);

                if (firstLang) {
                    $langSelect.val(firstLang);
                    this.currentLanguage = firstLang;
                    window.linguaDebug('✅ LINGUA v5.2.56: Auto-selected first available language:', firstLang);
                } else {
                    console.error('❌ LINGUA v5.2.63: No languages available in selector!');
                }
            }
        },

        /**
         * Извлечение контента для перевода
         */
        extractContent: function(e) {
            // LINGUA v2.0.3 diagnostics: Check auto-extract trigger
            window.linguaDebug("🔍 LINGUA v2.0.3 DIAGNOSTICS: Auto-extract triggered", e);

            // LINGUA v2.0.3 diagnostics: Protect from undefined e in auto-extract
            if (e && e.preventDefault) {
                e.preventDefault();
            }

            // v5.0.6: Allow currentPostId=0 for taxonomy pages
            // v5.5.0: Allow currentPostId=0 for post_type_archive pages
            if (this.currentPageType === 'taxonomy') {
                if (!this.currentTermId || this.currentTermId === '0') {
                    alert('No taxonomy term selected');
                    return;
                }
            } else if (this.currentPageType === 'post_type_archive') {
                // v5.5.0: Post type archive pages use URL-based extraction, post_id=0 is expected
                window.linguaDebug('🔍 LINGUA v5.5.0: extractContent for post_type_archive, using URL-based extraction');
            } else if (!this.currentPostId || this.currentPostId === '0' || this.currentPostId === 0) {
                alert(lingua_admin.strings.no_post_selected || 'No post selected. Current post ID: ' + this.currentPostId);
                return;
            }
            
            var targetLang = $('#lingua-target-lang').val();
            if (!targetLang) {
                alert(lingua_admin.strings.select_target_language || 'Please select target language');
                return;
            }
            
            this.currentLanguage = targetLang;
            this.updateProgress(10, lingua_admin.strings.extracting_content || 'Extracting content...');
            
            // УЛУЧШЕННОЕ определение страницы (поддержка переведенных URL)
            var pathname = window.location.pathname;
            var isFrontPage = pathname === '/' || pathname === '' ||
                             pathname.match(/^\/[a-z]{2}\/?$/); // Поддержка /en/, /ru/ и т.д.

            var ajaxData = {
                action: 'lingua_get_translatable_content',
                nonce: lingua_admin.nonce,
                target_language: targetLang,
                // КРИТИЧЕСКИ ВАЖНО: Передаем URL для серверного анализа
                current_url: window.location.href,
                request_uri: pathname
            };

            // v3.8: Get page context from button attributes
            // v5.0.6: Fixed selector - WordPress uses <a class="ab-item">, not custom class
            var $button = $('#wp-admin-bar-lingua-translate-page a.ab-item');
            var pageType = $button.attr('data-page-type') || 'post';
            var termId = $button.attr('data-term-id') || '0';
            var taxonomy = $button.attr('data-taxonomy') || '';

            window.linguaDebug('🔍 LINGUA v5.0.6: Reading button attributes:', {
                pageType: pageType,
                termId: termId,
                taxonomy: taxonomy,
                buttonFound: $button.length > 0
            });

            ajaxData.page_type = pageType;
            ajaxData.term_id = termId;
            ajaxData.taxonomy = taxonomy;

            if (isFrontPage) {
                ajaxData.is_front_page = 'true';
            } else if (this.currentPostId && this.currentPostId > 0) {
                ajaxData.post_id = this.currentPostId;
            }
            // Если post_id не найден, сервер попытается определить его из URL
            
            
            // v5.4.1: Refresh nonce before extraction to fix stale nonce on cached pages
            // Fetch a fresh nonce first, then proceed with content extraction
            var self = this;
            window.linguaDebug('🔑 LINGUA v5.4.1: Refreshing nonce before extraction...');

            $.ajax({
                url: lingua_admin.ajax_url,
                type: 'POST',
                data: { action: 'lingua_refresh_nonce' },
                cache: false
            }).done(function(nonceResponse) {
                if (nonceResponse && nonceResponse.success && nonceResponse.data && nonceResponse.data.nonce) {
                    var oldNonce = lingua_admin.nonce ? lingua_admin.nonce.substr(0, 10) : 'none';
                    lingua_admin.nonce = nonceResponse.data.nonce;
                    window.linguaDebug('🔑 LINGUA v5.4.1: Nonce refreshed: ' + oldNonce + '... → ' + lingua_admin.nonce.substr(0, 10) + '...');
                } else {
                    window.linguaDebug('⚠️ LINGUA v5.4.1: Nonce refresh failed, using existing nonce');
                }

                // Update ajaxData with fresh nonce
                ajaxData.nonce = lingua_admin.nonce;

                self._doExtractContent(ajaxData);
            }).fail(function() {
                window.linguaDebug('⚠️ LINGUA v5.4.1: Nonce refresh request failed, using existing nonce');
                self._doExtractContent(ajaxData);
            });
        },

        /**
         * v5.4.1: Internal method - performs actual content extraction AJAX call
         * Separated from extractContent to allow nonce refresh before call
         */
        _doExtractContent: function(ajaxData) {
            // 🔍 DETAILED AJAX DIAGNOSTICS - Added for debugging
            window.linguaDebug('🚀 LINGUA AJAX DEBUG: Starting content extraction request');
            window.linguaDebug('🔧 LINGUA AJAX DEBUG: Request URL:', lingua_admin.ajax_url);
            window.linguaDebug('🔧 LINGUA AJAX DEBUG: Request data:', ajaxData);
            window.linguaDebug('🔧 LINGUA AJAX DEBUG: Nonce value:', lingua_admin.nonce);
            window.linguaDebug('🔧 LINGUA AJAX DEBUG: Admin object:', lingua_admin);

            // AJAX запрос для извлечения контента
            // v5.2.89: FORCE CACHE BYPASS - add timestamp to prevent cached response
            ajaxData._timestamp = Date.now();

            $.ajax({
                url: lingua_admin.ajax_url,
                type: 'POST',
                data: ajaxData,
                cache: false  // Disable cache
            })
            .done(function(response) {
                window.linguaDebug('✅ LINGUA AJAX DEBUG: AJAX request succeeded');
                window.linguaDebug('📦 LINGUA AJAX DEBUG: Raw response:', response);
                window.linguaDebug('📦 LINGUA AJAX DEBUG: Response type:', typeof response);

                if (response && response.success) {
                    window.linguaDebug('✅ LINGUA AJAX DEBUG: Response indicates success');
                    window.linguaDebug('📋 LINGUA AJAX DEBUG: Response data:', response.data);

                    // КРИТИЧЕСКАЯ ПРОВЕРКА: Есть ли вообще контент для перевода
                    var hasContent = false;
                    if (response.data.content_blocks && response.data.content_blocks.length > 0) hasContent = true;
                    if (response.data.page_strings && response.data.page_strings.length > 0) hasContent = true;
                    if (response.data.attributes && response.data.attributes.length > 0) hasContent = true;

                    if (hasContent) {
                        translationModal.populateContent(response.data);
                        translationModal.updateProgress(100, lingua_admin.strings.content_extracted || 'Content extracted successfully', 'success');
                    } else {
                        console.warn('⚠️ LINGUA: No translatable content found in response');
                        alert('No translatable content found on this page. The page may be using complex layouts or dynamic content that is difficult to extract.');
                        translationModal.closeModal();
                    }
                } else {
                    console.error('❌ LINGUA AJAX DEBUG: Response indicates failure');
                    console.error('❌ LINGUA AJAX DEBUG: Error data:', response.data);
                    console.error('❌ LINGUA AJAX DEBUG: Full response:', response);
                    alert(lingua_admin.strings.extraction_failed + ': ' + (response.data || response || 'Unknown error'));
                    translationModal.updateProgress(0, lingua_admin.strings.extraction_failed || 'Content extraction failed', 'error');
                }
            })
            .fail(function(xhr, status, error) {
                console.error('💥 LINGUA AJAX DEBUG: AJAX request failed');
                console.error('💥 LINGUA AJAX DEBUG: XHR object:', xhr);
                console.error('💥 LINGUA AJAX DEBUG: Status:', status);
                console.error('💥 LINGUA AJAX DEBUG: Error:', error);
                console.error('💥 LINGUA AJAX DEBUG: XHR status:', xhr.status);
                console.error('💥 LINGUA AJAX DEBUG: XHR statusText:', xhr.statusText);
                console.error('💥 LINGUA AJAX DEBUG: XHR responseText:', xhr.responseText);
                console.error('💥 LINGUA AJAX DEBUG: XHR responseJSON:', xhr.responseJSON);

                var detailedError = 'Status: ' + xhr.status + ', Error: ' + error + ', Response: ' + xhr.responseText;
                alert(lingua_admin.strings.ajax_error || 'AJAX request failed: ' + detailedError);
                translationModal.updateProgress(0, lingua_admin.strings.ajax_error || 'Request failed', 'error');
            });
        },
        
        /**
         * НОВАЯ АРХИТЕКТУРА v3.0: Заполнение модального окна - только современные элементы
         */
        populateContent: function(data) {
            window.linguaDebug('🚀 LINGUA v3.0: Modern populateContent called');
            window.linguaDebug('📊 Content blocks:', data.content_blocks ? data.content_blocks.length : 0);
            window.linguaDebug('🔗 Page strings:', data.page_strings ? data.page_strings.length : 0);
            window.linguaDebug('⚙️ Attributes:', data.attributes ? data.attributes.length : 0);

            // Скрываем все legacy секции
            $('.lingua-legacy-section').hide();
            $('#lingua-seo-section').hide();
            $('#lingua-meta-section').hide();
            $('#lingua-woo-short-desc-section').hide();

            // Очищаем контейнер для нового контента
            $('#lingua-unified-content').empty();

            this.renderModernStructure(data);

            window.linguaDebug('✅ LINGUA v3.0: Modern modal populated successfully');

            // v5.0.7: Auto-resize all textareas after content is loaded (multiple attempts for reliability)
            var autoResize = function() {
                if (window.translationModal && window.translationModal.autoResizeAllTextareas) {
                    window.translationModal.autoResizeAllTextareas();
                }
            };

            // Run immediately
            autoResize();

            // Run again after DOM updates
            setTimeout(autoResize, 10);
            setTimeout(autoResize, 50);
            setTimeout(autoResize, 150);

            window.linguaDebug('✅ LINGUA v5.0.7: Auto-resize scheduled');

            // Hide all legacy sections to prevent artifacts (ИСПРАВЛЕНО: не скрываем unified контейнер)
            $('#lingua-seo-section').hide();
            $('#lingua-woo-short-desc-section').hide();
            $('#lingua-meta-section').hide();
            // НЕ СКРЫВАЕМ $('#lingua-page-content-section') - это наш современный контейнер!
            $('#lingua-content-summary').hide();

            // КРИТИЧЕСКИ ВАЖНО: Показываем unified секцию
            $('#lingua-page-content-section').show();

            // Включаем кнопку сохранения
            $('#lingua-save-translation').prop('disabled', false);

            // v3.7: Восстанавливаем маркеры для уже переведённых элементов
            this.restoreTranslationMarkers(data);

            // Pro restrictions removed - all features unlocked
        },
        
        /**
         * Создание HTML блока контента
         */
        createContentBlock: function(index, block) {
            var template = `
                <div class="lingua-translation-pair lingua-content-block" data-block-id="${index}">
                    <div class="lingua-original">
                        <label>${lingua_admin.strings.original || 'Original'} #${index + 1}</label>
                        <div class="lingua-original-text lingua-original-block">${this.escapeHtml(block.original || block.text)}</div>
                    </div>
                    <div class="lingua-translated">
                        <label>${lingua_admin.strings.translation || 'Translation'} #${index + 1}</label>
                        <textarea class="lingua-translated-text" data-field="content-${index}" rows="2" placeholder="${lingua_admin.strings.enter_translation || 'Enter translation...'}">${this.escapeHtml(block.translated || '')}</textarea>
                    </div>
                </div>
            `;
            
            return template;
        },
        
        /**
         * Создание HTML поля для строк страницы
         */
        createPageStringField: function(index, stringData) {
            window.linguaDebug('Lingua Debug: createPageStringField called with:', index, stringData);
            var contextLabel = this.formatContextLabel(stringData.context);
            window.linguaDebug('Lingua Debug: Context label:', contextLabel);
            var statusClass = stringData.status === 'translated' ? 'lingua-string-translated' : 'lingua-string-pending';

            // Add type information if available
            var typeInfo = '';
            if (stringData.type) {
                var typeLabels = {
                    'text': '📝 Text',
                    'attribute': '🏷️ Attribute'
                };
                var typeLabel = typeLabels[stringData.type] || stringData.type;
                typeInfo = `<span class="lingua-element-type">${typeLabel}</span>`;
            }

            // Determine input type based on text length
            var inputElement = stringData.original_text && stringData.original_text.length > 50
                ? `<textarea class="lingua-original-string" readonly rows="2">${this.escapeHtml(stringData.original_text)}</textarea>`
                : `<input type="text" class="lingua-original-string" value="${this.escapeHtml(stringData.original_text)}" readonly />`;

            var translatedElement = stringData.original_text && stringData.original_text.length > 50
                ? `<textarea class="lingua-translated-string" data-field="string-${index}" rows="2" placeholder="${lingua_admin.strings.enter_translation || 'Enter translation...'}">${this.escapeHtml(stringData.translated_text || '')}</textarea>`
                : `<input type="text" class="lingua-translated-string" data-field="string-${index}" value="${this.escapeHtml(stringData.translated_text || '')}" placeholder="${lingua_admin.strings.enter_translation || 'Enter translation...'}" />`;

            var template = `
                <div class="lingua-translation-pair lingua-page-string ${statusClass}" data-string-id="${index}">
                    <div class="lingua-original">
                        <label>${contextLabel} ${typeInfo}</label>
                        ${inputElement}
                    </div>
                    <div class="lingua-translated">
                        <label>${lingua_admin.strings.translation || 'Translation'}</label>
                        ${translatedElement}
                    </div>
                </div>
            `;

            return template;
        },

        /**
         * v2.1.1 UNIFIED: Create HTML field for unified content item
         * @param {number|string} index - Index or ID of the item
         * @param {object} itemData - Item data object with {id, original, translated, context, type, field_group}
         * @param {string} itemType - Type of item: 'content-block', 'page-string', 'meta-field'
         * @returns {string} HTML template string
         */
        createUnifiedContentItem: function(index, itemData, itemType) {
            window.linguaDebug('🔍 LINGUA v2.1.1: createUnifiedContentItem called with:', index, itemData, itemType);

            // Determine field ID for v2.1 unified structure
            var fieldId = itemData.id || index;
            var fieldKey = '';
            var containerClass = '';
            var labelPrefix = '';

            // Configure based on item type
            switch (itemType) {
                case 'content-block':
                    fieldKey = `content-${fieldId}`;
                    containerClass = 'lingua-content-block';
                    labelPrefix = 'Content Block';
                    break;
                case 'page-string':
                    fieldKey = `string-${fieldId}`;
                    containerClass = 'lingua-page-string';
                    labelPrefix = 'Page Element';
                    break;
                case 'meta-field':
                    fieldKey = `meta-${fieldId}`;
                    containerClass = 'lingua-meta-field';
                    labelPrefix = 'Meta Field';
                    break;
                default:
                    fieldKey = `unified-${fieldId}`;
                    containerClass = 'lingua-unified-item';
                    labelPrefix = 'Content Item';
            }

            // Extract text content
            var originalText = itemData.original_text || itemData.original || itemData.text || '';
            var translatedText = itemData.translated_text || itemData.translated || '';

            // Add context and type information
            var contextInfo = '';
            if (itemData.context && itemData.context !== 'general') {
                contextInfo = `<span class="lingua-context-tag">${this.formatContextLabel(itemData.context)}</span>`;
            }

            var typeInfo = '';
            if (itemData.type) {
                var typeLabels = {
                    'seo_title': '🏷️ SEO Title',
                    'seo_description': '📝 SEO Description',
                    'title': '📑 Title',
                    'excerpt': '📄 Excerpt',
                    'content': '📝 Content',
                    'text': '📝 Text',
                    'attribute': '🏷️ Attribute',
                    'button_text': '🔘 Button',
                    'heading': '📍 Heading',
                    'paragraph': '📝 Paragraph',
                    'woo_short_desc': '🛍️ Product Description'
                };
                var typeLabel = typeLabels[itemData.type] || itemData.type;
                typeInfo = `<span class="lingua-type-tag">${typeLabel}</span>`;
            }

            // Determine input element type based on content length
            var isLongText = originalText.length > 80;
            var inputRows = isLongText ? Math.min(Math.ceil(originalText.length / 60), 6) : 2;

            var originalElement = isLongText
                ? `<textarea class="lingua-original-text" readonly rows="${inputRows}">${this.escapeHtml(originalText)}</textarea>`
                : `<input type="text" class="lingua-original-text" value="${this.escapeHtml(originalText)}" readonly />`;

            var translatedElement = isLongText
                ? `<textarea class="lingua-translated-text" data-field="${fieldKey}" rows="${inputRows}" placeholder="${lingua_admin.strings.enter_translation || 'Enter translation...'}">${this.escapeHtml(translatedText)}</textarea>`
                : `<input type="text" class="lingua-translated-text" data-field="${fieldKey}" value="${this.escapeHtml(translatedText)}" placeholder="${lingua_admin.strings.enter_translation || 'Enter translation...'}" />`;

            // Status class
            var statusClass = translatedText ? 'lingua-item-translated' : 'lingua-item-pending';

            var template = `
                <div class="lingua-translation-pair ${containerClass} ${statusClass}" data-unified-id="${fieldId}" data-unified-type="${itemType}">
                    <div class="lingua-original">
                        <label>
                            ${labelPrefix} #${index + 1}
                            ${contextInfo}
                            ${typeInfo}
                        </label>
                        ${originalElement}
                    </div>
                    <div class="lingua-translated">
                        <label>${lingua_admin.strings.translation || 'Translation'}</label>
                        ${translatedElement}
                    </div>
                </div>
            `;

            window.linguaDebug('🔍 LINGUA v2.1.1: Generated unified template for field:', fieldKey);
            return template;
        },

        /**
         * Создание HTML поля мета-данных
         */
        createMetaField: function(key, value) {
            var template = `
                <div class="lingua-translation-pair lingua-meta-field" data-meta-key="${key}">
                    <div class="lingua-original">
                        <label>${this.formatMetaLabel(key)}</label>
                        <input type="text" class="lingua-original-meta" value="${this.escapeHtml(value)}" readonly />
                    </div>
                    <div class="lingua-translated">
                        <label>${lingua_admin.strings.translation || 'Translation'}</label>
                        <input type="text" class="lingua-translated-meta" data-field="meta-${key}" placeholder="${lingua_admin.strings.enter_translation || 'Enter translation...'}" />
                    </div>
                </div>
            `;
            
            return template;
        },
        
        // autoTranslateAll removed (Pro feature removed)
        
        // processAutoTranslation removed (Pro feature removed)

        // processChunkedTranslation removed (Pro feature removed)
        
        // translateFieldsNew removed (Pro feature removed)

        // translateFields removed (Pro feature removed)
        
        // translateSingle removed (Pro feature removed)
        
        // translateFieldByName removed (Pro feature removed)
        
        /**
         * Получение исходного текста поля
         */
        getFieldSourceText: function(fieldName) {
            if (fieldName === 'seo_title') {
                return $('#lingua-original-seo-title').val();
            } else if (fieldName === 'seo_description') {
                return $('#lingua-original-seo-description').val();
            } else if (fieldName === 'title') {
                return $('#lingua-original-title').val();
            } else if (fieldName === 'excerpt') {
                return $('#lingua-original-excerpt').val();
            } else if (fieldName === 'woo_short_desc') {
                return $('#lingua-original-woo-short-desc').val();
            } else if (fieldName.startsWith('content-')) {
                var blockId = fieldName.replace('content-', '');
                return $(`.lingua-content-block[data-block-id="${blockId}"] .lingua-original-text`).val();
            } else if (fieldName.startsWith('meta-')) {
                var metaKey = fieldName.replace('meta-', '');
                return $(`.lingua-meta-field[data-meta-key="${metaKey}"] .lingua-original-meta`).val();
            } else if (fieldName.startsWith('string-')) {
                var stringId = fieldName.replace('string-', '');
                // LINGUA v2.1.3 FIX: Try multiple selector approaches for unified elements
                // Try unified container with data-unified-id
                var unifiedElement = $(`.lingua-translation-pair[data-unified-id="${stringId}"][data-unified-type="page-string"] .lingua-original-text`);
                if (unifiedElement.length) {
                    window.linguaDebug("🔍 LINGUA v2.1.3 AUTO: Found unified element by data-unified-id:", stringId);
                    return unifiedElement.val();
                }

                // Try by button's data-field attribute (more reliable)
                var buttonElement = $(`.lingua-translate-single[data-field="string-${stringId}"]`);
                if (buttonElement.length) {
                    var containerElement = buttonElement.closest('.lingua-translation-pair').find('.lingua-original-text');
                    if (containerElement.length) {
                        window.linguaDebug("🔍 LINGUA v2.1.3 AUTO: Found element by button data-field:", fieldName);
                        return containerElement.val();
                    }
                }

                // Fallback to old selectors
                return $(`.lingua-page-string[data-string-id="${stringId}"] .lingua-original input, .lingua-page-string[data-string-id="${stringId}"] .lingua-original textarea`).val();
            } else if (fieldName.startsWith('unified-')) {
                // Handle unified fields
                var unifiedId = fieldName.replace('unified-', '');
                return $(`.lingua-translation-pair[data-unified-id="${unifiedId}"] .lingua-original-text`).val();
            }

            return '';
        },
        
        /**
         * Установка перевода поля
         */
        setFieldTranslation: function(fieldName, translatedText) {
            if (fieldName === 'seo_title') {
                $('#lingua-translated-seo-title').val(translatedText);
            } else if (fieldName === 'seo_description') {
                $('#lingua-translated-seo-description').val(translatedText);
            } else if (fieldName === 'title') {
                $('#lingua-translated-title').val(translatedText);
            } else if (fieldName === 'excerpt') {
                $('#lingua-translated-excerpt').val(translatedText);
            } else if (fieldName === 'woo_short_desc') {
                $('#lingua-translated-woo-short-desc').val(translatedText);
            } else if (fieldName.startsWith('content-')) {
                var blockId = fieldName.replace('content-', '');
                $(`.lingua-content-block[data-block-id="${blockId}"] .lingua-translated-text`).val(translatedText);
            } else if (fieldName.startsWith('meta-')) {
                var metaKey = fieldName.replace('meta-', '');
                $(`.lingua-meta-field[data-meta-key="${metaKey}"] .lingua-translated-meta`).val(translatedText);
            } else if (fieldName.startsWith('string-')) {
                var stringId = fieldName.replace('string-', '');
                // LINGUA v2.1.3 FIX: Try multiple selector approaches for unified elements
                // Try unified container first
                var unifiedElement = $(`.lingua-translation-pair[data-unified-id="${stringId}"][data-unified-type="page-string"] .lingua-translated-text`);
                if (unifiedElement.length) {
                    window.linguaDebug("🔍 LINGUA v2.1.3 AUTO: Set translation via data-unified-id:", stringId);
                    unifiedElement.val(translatedText);
                    return;
                }

                // Try by button's data-field attribute (more reliable)
                var buttonElement = $(`.lingua-translate-single[data-field="string-${stringId}"]`);
                if (buttonElement.length) {
                    var containerElement = buttonElement.closest('.lingua-translation-pair').find('.lingua-translated-text');
                    if (containerElement.length) {
                        window.linguaDebug("🔍 LINGUA v2.1.3 AUTO: Set translation via button data-field:", fieldName);
                        containerElement.val(translatedText);
                        return;
                    }
                }

                // Fallback to old selectors
                $(`.lingua-page-string[data-string-id="${stringId}"] .lingua-translated input, .lingua-page-string[data-string-id="${stringId}"] .lingua-translated textarea`).val(translatedText);
            } else if (fieldName.startsWith('unified-')) {
                // Handle unified fields
                var unifiedId = fieldName.replace('unified-', '');
                $(`.lingua-translation-pair[data-unified-id="${unifiedId}"] .lingua-translated-text`).val(translatedText);
            }

            // Сохраняем в локальном состоянии
            this.translatedBlocks[fieldName] = translatedText;
        },
        
        /**
         * Сохранение переводов
         */
        saveTranslation: function(e) {
            e.preventDefault();

            // CRITICAL DIAGNOSTICS: Check if saveTranslation is called at all
            window.linguaDebug('🔍 LINGUA v2.1.4 SAVE BUTTON CLICKED: saveTranslation() function called');
            window.linguaDebug('🔍 LINGUA v2.1.4 SAVE STATE: currentPostId =', this.currentPostId, 'currentLanguage =', this.currentLanguage);

            // v5.0.6: Validate taxonomy context
            // v5.5.2: Allow post_type_archive pages (currentPostId=0 is expected)
            if (this.currentPageType === 'taxonomy') {
                if (!this.currentTermId || !this.currentLanguage) {
                    alert(lingua_admin.strings.invalid_state || 'Invalid state for saving taxonomy');
                    return;
                }
            } else if (this.currentPageType === 'post_type_archive') {
                if (!this.currentLanguage) {
                    alert(lingua_admin.strings.invalid_state || 'Invalid state for saving');
                    return;
                }
            } else if (!this.currentPostId || !this.currentLanguage) {
                alert(lingua_admin.strings.invalid_state || 'Invalid state for saving');
                return;
            }
            
            var translations = this.collectModernTranslations();

            // v5.2.112: CRITICAL DIAGNOSTICS - Parse and examine collected data
            window.linguaDebug('🔥🔥🔥 LINGUA v5.2.112 SAVE DIAGNOSTICS: collectModernTranslations() returned:', translations);

            if (translations.translation_strings) {
                try {
                    var parsedStrings = JSON.parse(translations.translation_strings);
                    window.linguaDebug('🔥🔥🔥 LINGUA v5.2.112: Total strings collected:', parsedStrings.length);

                    // Find and log ALL plural form items
                    var pluralItems = parsedStrings.filter(function(item) { return item.is_plural === true; });
                    window.linguaDebug('🔥🔥🔥 LINGUA v5.2.112: Found', pluralItems.length, 'plural form items:');
                    pluralItems.forEach(function(item, idx) {
                        window.linguaDebug('  🔢 Plural item #' + idx + ':', {
                            original: item.original,
                            translated: item.translated,
                            plural_form_index: item.plural_form_index,
                            plural_pair: item.plural_pair || item.russian_forms,
                            source: item.source,
                            gettext_domain: item.gettext_domain
                        });
                    });
                } catch (e) {
                    console.error('🔥🔥🔥 LINGUA v5.2.112: ERROR parsing translation_strings:', e);
                }
            }

            if (Object.keys(translations).length === 0) {
                alert(lingua_admin.strings.no_translations_to_save || 'No translations to save');
                return;
            }

            $('#lingua-save-translation').prop('disabled', true).text(lingua_admin.strings.saving || 'Saving...');
            this.updateProgress(90, lingua_admin.strings.saving_translations || 'Saving translations...');

            // AJAX запрос для сохранения
            window.linguaDebug('🔍 LINGUA SAVE v2.1.3: Sending AJAX request to save translations');
            window.linguaDebug('🔍 LINGUA SAVE v2.1.3: Translations data:', translations);
            window.linguaDebug('🔍 LINGUA SAVE v2.1.3: Post ID:', this.currentPostId);
            window.linguaDebug('🔍 LINGUA SAVE v2.1.3: Language:', this.currentLanguage);

            // v5.0.6: Add taxonomy context for taxonomy pages
            var saveData = {
                action: 'lingua_save_translation',
                nonce: lingua_admin.nonce,
                post_id: this.currentPostId,
                language: this.currentLanguage,
                translations: translations,
                use_global_translation: 'true' // Always use the admin setting
            };

            // v5.0.6: Add taxonomy parameters if available
            if (this.currentPageType) {
                saveData.page_type = this.currentPageType;
            }
            if (this.currentTermId) {
                saveData.term_id = this.currentTermId;
            }
            if (this.currentTaxonomy) {
                saveData.taxonomy = this.currentTaxonomy;
            }

            window.linguaDebug('🔍 LINGUA v5.0.6: Save data:', saveData);

            // Store reference for use in callback
            var self = this;

            // v5.4.1: Refresh nonce before save to prevent stale nonce errors
            $.ajax({
                url: lingua_admin.ajax_url,
                type: 'POST',
                data: { action: 'lingua_refresh_nonce' },
                cache: false
            }).always(function(nonceResponse) {
                if (nonceResponse && nonceResponse.success && nonceResponse.data && nonceResponse.data.nonce) {
                    lingua_admin.nonce = nonceResponse.data.nonce;
                    saveData.nonce = lingua_admin.nonce;
                    window.linguaDebug('🔑 LINGUA v5.4.1: Nonce refreshed before save');
                }
                // Proceed with save regardless of nonce refresh result
                self._doSaveTranslation(saveData);
            });
        },

        /**
         * v5.4.1: Internal method - performs actual save AJAX call
         */
        _doSaveTranslation: function(saveData) {
            var self = this;

            $.post(lingua_admin.ajax_url, saveData).done(function(response) {
                window.linguaDebug('🔍 LINGUA SAVE v2.1.3: AJAX Response received:', response);
                if (response.success) {
                    translationModal.updateProgress(100, lingua_admin.strings.translations_saved || 'Translations saved successfully', 'success');

                    // v5.2: Clear cache and reload iframe preview (no full page reload)
                    window.linguaDebug('✅ LINGUA v5.2: Translations saved successfully, reloading iframe preview...');

                    // Clear page cache first
                    self.clearPageCache();

                    // v5.2: Reload iframe preview instead of full page (reduced delay)
                    setTimeout(function() {
                        translationModal.reloadPreviewIframe();
                    }, 400);
                } else {
                    window.linguaDebug('🚨 LINGUA SAVE v2.1.3: Save failed - Response error:', response);

                    // Handle error message properly - extract string from object if needed
                    let errorMessage = 'Unknown error';
                    if (response.data) {
                        if (typeof response.data === 'string') {
                            errorMessage = response.data;
                        } else if (response.data.message) {
                            errorMessage = response.data.message;
                        } else if (response.data.errors && Array.isArray(response.data.errors)) {
                            errorMessage = response.data.errors.join(', ');
                        } else {
                            errorMessage = JSON.stringify(response.data);
                        }
                    }

                    alert(lingua_admin.strings.save_failed + ': ' + errorMessage);
                    translationModal.updateProgress(70, lingua_admin.strings.save_failed || 'Save failed', 'error');
                }
            }).fail(function(xhr, status, error) {
                window.linguaDebug('🚨 LINGUA SAVE v2.1.3: AJAX request failed:', status, error);
                window.linguaDebug('🚨 LINGUA SAVE v2.1.3: XHR Response:', xhr.responseText);
                alert(lingua_admin.strings.ajax_error || 'AJAX request failed');
                translationModal.updateProgress(70, lingua_admin.strings.ajax_error || 'Request failed', 'error');
            }).always(function() {
                $('#lingua-save-translation').prop('disabled', false).text(lingua_admin.strings.save_translation || 'Save Translation');
            });
        },

        /**
         * v3.2: Clear bad WooCommerce translations (JSON fragments)
         */
        clearBadTranslations: function(e) {
            if (e) e.preventDefault();

            if (!confirm('Clear bad translations with JSON fragments? This will only delete problematic WooCommerce data and won\'t affect your other translations.')) {
                return;
            }

            var postId = this.currentPostId;
            if (!postId) {
                alert('No post selected');
                return;
            }

            window.linguaDebug('[LINGUA v3.2] Clearing bad translations for post:', postId);

            $.ajax({
                url: lingua_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'lingua_clear_bad_translations',
                    post_id: postId,
                    nonce: lingua_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        window.linguaDebug('[LINGUA v3.2] Successfully cleared:', response.data);
                        alert('Cleared ' + response.data.deleted + ' bad translations. Please reload the page to see updated content.');
                        window.location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('Failed to clear translations');
                }
            });
        },

        /**
         * Сбор всех переводов с формы - LINGUA v2.1 unified pipeline
         * Все поля обрабатываются через единую JSON структуру
         */
        collectTranslations: function() {
            // LINGUA v2.1 unified pipeline: Start collecting all translations as JSON
            window.linguaDebug("🔍 LINGUA v2.1 UNIFIED: Starting collectTranslations()");
            var allStrings = [];
            var translationStats = {
                seo_fields: 0,
                core_fields: 0,
                page_strings: 0,
                meta_fields: 0,
                content_blocks: 0
            };

            // 1. SEO поля - теперь через unified v2 структуру
            var seoTitle = $('#lingua-translated-seo-title').val().trim();
            var originalSeoTitle = $('#lingua-original-seo-title').val().trim();
            if (seoTitle && originalSeoTitle) {
                allStrings.push({
                    id: 'seo_title',
                    original: originalSeoTitle,
                    translated: seoTitle,
                    context: 'seo',
                    type: 'seo_title',
                    field_group: 'seo_fields'
                });
                translationStats.seo_fields++;
            }

            var seoDescription = $('#lingua-translated-seo-description').val().trim();
            var originalSeoDescription = $('#lingua-original-seo-description').val().trim();
            if (seoDescription && originalSeoDescription) {
                allStrings.push({
                    id: 'seo_description',
                    original: originalSeoDescription,
                    translated: seoDescription,
                    context: 'seo',
                    type: 'seo_description',
                    field_group: 'seo_fields'
                });
                translationStats.seo_fields++;
            }

            // 2. Основные поля - unified v2 структура
            var title = $('#lingua-translated-title').val().trim();
            var originalTitle = $('#lingua-original-title').val().trim();
            if (title && originalTitle) {
                allStrings.push({
                    id: 'title',
                    original: originalTitle,
                    translated: title,
                    context: 'core',
                    type: 'title',
                    field_group: 'core_fields'
                });
                translationStats.core_fields++;
            }

            var excerpt = $('#lingua-translated-excerpt').val().trim();
            var originalExcerpt = $('#lingua-original-excerpt').val().trim();
            if (excerpt && originalExcerpt) {
                allStrings.push({
                    id: 'excerpt',
                    original: originalExcerpt,
                    translated: excerpt,
                    context: 'core',
                    type: 'excerpt',
                    field_group: 'core_fields'
                });
                translationStats.core_fields++;
            }

            // 3. WooCommerce Short Description - unified v2
            var wooShortDesc = $('#lingua-translated-woo-short-desc').val().trim();
            var originalWooShortDesc = $('#lingua-original-woo-short-desc').val().trim();
            if (wooShortDesc && originalWooShortDesc) {
                allStrings.push({
                    id: 'woo_short_desc',
                    original: originalWooShortDesc,
                    translated: wooShortDesc,
                    context: 'woocommerce',
                    type: 'woo_short_desc',
                    field_group: 'meta_fields'
                });
                translationStats.meta_fields++;
            }

            // 4. v2.1.1 UNIFIED: All content from unified container
            // v5.2.43: Updated to handle both regular items and plural groups
            $('#lingua-unified-content .lingua-translation-item').each(function() {
                var $item = $(this);

                // v5.2.43: Check if this is a plural group
                if ($item.hasClass('lingua-plural-group')) {
                    // Handle plural group - collect both singular and plural
                    var $singularTextarea = $item.find('.lingua-plural-singular');
                    var $pluralTextarea = $item.find('.lingua-plural-plural');

                    // v5.2.47: Use .attr() instead of .data() to read from HTML attribute directly
                    var singularOriginal = $singularTextarea.attr('data-original') || '';
                    var singularTranslated = $singularTextarea.val().trim();
                    var pluralOriginal = $pluralTextarea.attr('data-original') || '';
                    var pluralTranslated = $pluralTextarea.val().trim();

                    // v5.2.68: Extract gettext metadata from plural group container
                    var source = $item.attr('data-source') || 'custom';
                    var gettextDomain = $item.attr('data-gettext-domain') || null;

                    window.linguaDebug('🔢 LINGUA v5.2.68 DEBUG: Plural group save data with metadata:');
                    window.linguaDebug('  Singular original:', singularOriginal);
                    window.linguaDebug('  Singular translated:', singularTranslated);
                    window.linguaDebug('  Plural original:', pluralOriginal);
                    window.linguaDebug('  Plural translated:', pluralTranslated);
                    window.linguaDebug('  Source:', source, 'Domain:', gettextDomain);

                    // Save singular translation WITH gettext metadata
                    if (singularOriginal && singularTranslated) {
                        var singularData = {
                            id: 'plural_singular_' + allStrings.length,
                            original: singularOriginal,
                            translated: singularTranslated,
                            context: 'content',
                            type: 'content_block',
                            field_group: 'content_blocks',
                            source: source,
                            is_plural: true,
                            plural_pair: pluralOriginal  // Link to plural counterpart
                        };
                        if (gettextDomain) {
                            singularData.gettext_domain = gettextDomain;
                        }
                        allStrings.push(singularData);
                        translationStats.content_blocks++;
                        window.linguaDebug('  ✅ Saved singular translation WITH metadata');
                    } else {
                        window.linguaDebug('  ⚠️ Skipped singular (empty)');
                    }

                    // Save plural translation WITH gettext metadata
                    if (pluralOriginal && pluralTranslated) {
                        var pluralData = {
                            id: 'plural_plural_' + allStrings.length,
                            original: pluralOriginal,
                            translated: pluralTranslated,
                            context: 'content',
                            type: 'content_block',
                            field_group: 'content_blocks',
                            source: source,
                            is_plural: true,
                            plural_pair: singularOriginal  // Link to singular counterpart
                        };
                        if (gettextDomain) {
                            pluralData.gettext_domain = gettextDomain;
                        }
                        allStrings.push(pluralData);
                        translationStats.content_blocks++;
                        window.linguaDebug('  ✅ Saved plural translation WITH metadata');
                    } else {
                        window.linguaDebug('  ⚠️ Skipped plural (empty)');
                    }

                    window.linguaDebug('🔢 LINGUA v5.2.68: Collected plural group with metadata:', singularOriginal, '/', pluralOriginal);

                } else {
                    // Regular translation item (not a plural group)
                    var itemType = $item.data('unified-type') || 'unknown';
                    var itemId = $item.data('unified-id') || 'item-' + allStrings.length;

                    // Get original text from input/textarea/div
                    var originalText = '';
                    var $originalInput = $item.find('.lingua-original-text');
                    // v5.0.11: .lingua-original-text is now a DIV, not textarea
                    if ($originalInput.is('textarea') || $originalInput.is('input')) {
                        originalText = $originalInput.val().trim();
                    } else {
                        // DIV - use .text()
                        originalText = $originalInput.text().trim();
                    }

                    // Get translated text from input/textarea
                    var translatedText = '';
                    var $translatedInput = $item.find('.lingua-translated-text');
                    if ($translatedInput.is('textarea')) {
                        translatedText = $translatedInput.val().trim();
                    } else {
                        translatedText = $translatedInput.val().trim();
                    }

                    if (originalText && translatedText) {
                        var fieldGroup = '';
                        var type = '';
                        var context = 'general';

                        // Determine field_group and type based on unified-type
                        switch (itemType) {
                            case 'content-block':
                                fieldGroup = 'content_blocks';
                                type = 'content_block';
                                context = 'content';
                                translationStats.content_blocks++;
                                break;
                            case 'page-string':
                                fieldGroup = 'page_strings';
                                type = 'page_string';
                                context = 'page';
                                translationStats.page_strings++;
                                break;
                            case 'meta-field':
                                fieldGroup = 'meta_fields';
                                type = 'meta_field';
                                context = 'meta';
                                translationStats.meta_fields++;
                                break;
                            default:
                                fieldGroup = 'content_blocks';
                                type = 'content_item';
                                context = 'general';
                                translationStats.content_blocks++;
                        }

                        allStrings.push({
                            id: itemId,
                            original: originalText,
                            translated: translatedText,
                            context: context,
                            type: type,
                            field_group: fieldGroup
                        });

                        window.linguaDebug('🔍 LINGUA v2.1.1: Collected unified item:', itemType, itemId, originalText.substring(0, 50) + '...');
                    }
                }
            });

            // 5. Legacy Meta fields (outside unified container) - keeping for backward compatibility
            $('#lingua-meta-fields .lingua-meta-field').each(function() {
                var $meta = $(this);
                var metaKey = $meta.data('meta-key');
                var originalValue = $meta.find('.lingua-original-meta').val().trim();
                var translatedValue = $meta.find('.lingua-translated-meta').val().trim();

                if (metaKey && originalValue && translatedValue) {
                    allStrings.push({
                        id: 'meta-' + metaKey,
                        original: originalValue,
                        translated: translatedValue,
                        context: 'meta',
                        type: 'meta_field',
                        field_group: 'meta_fields',
                        meta_key: metaKey
                    });
                    translationStats.meta_fields++;
                }
            });

            // LINGUA v2.1 unified pipeline: Return single JSON structure for all fields
            var unifiedTranslations = {
                translation_strings: JSON.stringify(allStrings),
                statistics: translationStats,
                total_strings: allStrings.length
            };

            // LINGUA v2.1.3 diagnostics: Log unified structure with detailed analysis
            window.linguaDebug("🔍 LINGUA v2.1.3 COLLECT: Collected translations structure", unifiedTranslations);
            window.linguaDebug("🔍 LINGUA v2.1.3 COLLECT: Statistics by type", translationStats);
            window.linguaDebug("🔍 LINGUA v2.1.3 COLLECT: Total translation strings:", allStrings.length);
            window.linguaDebug("🔍 LINGUA v2.1.3 COLLECT: JSON payload size:", unifiedTranslations.translation_strings.length, "characters");

            // LINGUA v2.1.3 FIX: Remove duplicate strings based on original text
            var seen = new Set();
            var deduplicatedStrings = [];
            var duplicateCount = 0;

            allStrings.forEach(function(str) {
                var key = str.original.trim() + '|' + str.field_group;
                if (!seen.has(key)) {
                    seen.add(key);
                    deduplicatedStrings.push(str);
                } else {
                    duplicateCount++;
                    window.linguaDebug("🚫 LINGUA v2.1.3 DEDUP: Removed duplicate:", str.original.substring(0, 50) + '...', 'Context:', str.context);
                }
            });

            if (duplicateCount > 0) {
                window.linguaDebug("🔍 LINGUA v2.1.3 DEDUP: Removed " + duplicateCount + " duplicate strings");
                allStrings = deduplicatedStrings;
                unifiedTranslations.translation_strings = JSON.stringify(allStrings);
                unifiedTranslations.total_strings = allStrings.length;
            }

            // Log breakdown by field group for debugging
            var groupBreakdown = {};
            allStrings.forEach(function(str) {
                var group = str.field_group || 'undefined';
                if (!groupBreakdown[group]) groupBreakdown[group] = [];
                groupBreakdown[group].push({
                    id: str.id,
                    type: str.type,
                    original: str.original.substring(0, 50) + '...',
                    translated: str.translated.substring(0, 50) + '...',
                    context: str.context
                });
            });
            window.linguaDebug("🔍 LINGUA v2.1.3 COLLECT: Breakdown by field group:", groupBreakdown);

            return unifiedTranslations;
        },

        /**
         * NEW METHOD v3.0: Collect translations from modern modal structure
         * v3.2: Added SEO fields collection and OG tags auto-sync
         */
        collectModernTranslations: function() {
            window.linguaDebug("🚀 LINGUA v3.2: Starting collectModernTranslations()");
            var allStrings = [];
            var translationStats = {
                seo_fields: 0,
                content_blocks: 0,
                page_strings: 0,
                attributes: 0
            };

            // v3.2: Collect SEO fields from SEO tab
            window.linguaDebug("🔍 LINGUA v5.2.165: Collecting SEO fields from SEO tab");
            $('#lingua-seo-content .lingua-seo-field').each(function() {
                var $field = $(this);
                var type = $field.data('type');
                var context = $field.data('context') || 'meta_information';
                // v5.0.11 FIX: .lingua-original-text is DIV in SEO fields, use .text()
                var originalText = $field.find('.lingua-original-text').text().trim();
                var translatedText = $field.find('.lingua-translated-text').val().trim();

                // v5.2.165: Debug logging for SEO fields
                window.linguaDebug('📋 LINGUA v5.2.165 SEO field:', type, '| original:', originalText ? originalText.substring(0, 30) + '...' : 'EMPTY', '| translated:', translatedText ? translatedText.substring(0, 30) + '...' : 'EMPTY');

                // v5.2.165: Send SEO field if original exists (even if translated is empty - for deletion)
                if (originalText) {
                    allStrings.push({
                        id: 'seo_' + type,
                        original: originalText,
                        translated: translatedText,  // Can be empty for deletion
                        context: context,
                        field_group: 'seo_fields',
                        type: type,
                        source: 'v3.2_seo_tab'
                    });
                    if (translatedText) {
                        translationStats.seo_fields++;
                    }

                    // v3.2: Automatic OG tags synchronization
                    if (type === 'page_title' && window.translationModal.ogTags) {
                        // Sync with og:title and twitter:title
                        var ogTitleTag = window.translationModal.ogTags.find(tag => tag.type === 'og_title');
                        var twitterTitleTag = window.translationModal.ogTags.find(tag => tag.type === 'twitter_title');

                        if (ogTitleTag) {
                            allStrings.push({
                                id: 'seo_og_title',
                                original: ogTitleTag.original || ogTitleTag.original_text || originalText,
                                translated: translatedText,
                                context: context,
                                field_group: 'seo_fields',
                                type: 'og_title',
                                source: 'v3.2_auto_sync'
                            });
                            window.linguaDebug('✅ LINGUA v3.2: Auto-synced og:title from page_title');
                        }

                        if (twitterTitleTag) {
                            allStrings.push({
                                id: 'seo_twitter_title',
                                original: twitterTitleTag.original || twitterTitleTag.original_text || originalText,
                                translated: translatedText,
                                context: context,
                                field_group: 'seo_fields',
                                type: 'twitter_title',
                                source: 'v3.2_auto_sync'
                            });
                            window.linguaDebug('✅ LINGUA v3.2: Auto-synced twitter:title from page_title');
                        }
                    } else if (type === 'meta_description' && window.translationModal.ogTags) {
                        // Sync with og:description and twitter:description
                        var ogDescTag = window.translationModal.ogTags.find(tag => tag.type === 'og_description');
                        var twitterDescTag = window.translationModal.ogTags.find(tag => tag.type === 'twitter_description');

                        if (ogDescTag) {
                            allStrings.push({
                                id: 'seo_og_description',
                                original: ogDescTag.original || ogDescTag.original_text || originalText,
                                translated: translatedText,
                                context: context,
                                field_group: 'seo_fields',
                                type: 'og_description',
                                source: 'v3.2_auto_sync'
                            });
                            window.linguaDebug('✅ LINGUA v3.2: Auto-synced og:description from meta_description');
                        }

                        if (twitterDescTag) {
                            allStrings.push({
                                id: 'seo_twitter_description',
                                original: twitterDescTag.original || twitterDescTag.original_text || originalText,
                                translated: translatedText,
                                context: context,
                                field_group: 'seo_fields',
                                type: 'twitter_description',
                                source: 'v3.2_auto_sync'
                            });
                            window.linguaDebug('✅ LINGUA v3.2: Auto-synced twitter:description from meta_description');
                        }
                    }
                }
            });

            // Collect translations from all sections of modern modal (Page Content tab)
            $('#lingua-unified-content .lingua-translation-item').each(function(index) {
                var $item = $(this);

                // v5.2.48: Check if this is a plural group
                // v5.2.54: Support both 2-form (singular/plural) and 3-form (Russian) plural groups
                if ($item.hasClass('lingua-plural-group')) {
                    window.linguaDebug('🔢 LINGUA v5.2.54: Collecting plural group in collectModernTranslations()');

                    // v5.2.54: Check if this is a 3-form Russian plural (lingua-plural-form-0/1/2)
                    var $form0 = $item.find('.lingua-plural-form-0');
                    var $form1 = $item.find('.lingua-plural-form-1');
                    var $form2 = $item.find('.lingua-plural-form-2');
                    var $form3 = $item.find('.lingua-plural-form-3');
                    var $form4 = $item.find('.lingua-plural-form-4');
                    var $form5 = $item.find('.lingua-plural-form-5');

                    // v5.2.134: Check for 6-form Arabic plural first
                    if ($form0.length > 0 && $form5.length > 0) {
                        // Arabic 6-form plural
                        window.linguaDebug('  📦 LINGUA v5.2.134: Arabic 6-form plural detected');

                        var source = $item.attr('data-source') || 'custom';
                        var gettextDomain = $item.attr('data-gettext-domain') || null;

                        // Collect all 6 forms
                        var arabicFormsArray = [];
                        for (var i = 0; i <= 5; i++) {
                            var $formI = $item.find('.lingua-plural-form-' + i);
                            var formOriginal = $formI.attr('data-original') || '';
                            var formTranslated = $formI.val() ? $formI.val().trim() : '';
                            arabicFormsArray.push(formTranslated);

                            window.linguaDebug('🔢 LINGUA v5.2.134 COLLECT: Form ' + i + ':', formOriginal, '→', formTranslated);

                            // Save each form
                            if (formOriginal && formTranslated) {
                                var formItem = {
                                    id: 'modern_plural_form' + i + '_' + index,
                                    original: formOriginal,
                                    translated: formTranslated,
                                    context: 'content_block',
                                    field_group: 'content_blocks',
                                    type: 'content_block',
                                    plural_form_index: i,
                                    source: source,
                                    is_plural: true,
                                    arabic_forms: arabicFormsArray
                                };
                                if (gettextDomain) formItem.gettext_domain = gettextDomain;
                                allStrings.push(formItem);
                                translationStats.content_blocks++;
                            }
                        }

                        window.linguaDebug('  ✅ LINGUA v5.2.134: Saved 6 Arabic plural forms');
                    } else if ($form0.length > 0 && $form3.length > 0 && !$form4.length) {
                        // v5.2.134: 4-form plural (Slovenian, Scottish Gaelic)
                        window.linguaDebug('  📦 LINGUA v5.2.134: 4-form plural detected (Slovenian/Gaelic)');

                        var source = $item.attr('data-source') || 'custom';
                        var gettextDomain = $item.attr('data-gettext-domain') || null;

                        // Collect all 4 forms
                        var formsArray = [];
                        for (var i = 0; i <= 3; i++) {
                            var $formI = $item.find('.lingua-plural-form-' + i);
                            var formOriginal = $formI.attr('data-original') || '';
                            var formTranslated = $formI.val() ? $formI.val().trim() : '';
                            formsArray.push(formTranslated);

                            window.linguaDebug('🔢 LINGUA v5.2.134 COLLECT: Form ' + i + ':', formOriginal, '→', formTranslated);

                            // Save each form
                            if (formOriginal && formTranslated) {
                                var formItem = {
                                    id: 'modern_plural_form' + i + '_' + index,
                                    original: formOriginal,
                                    translated: formTranslated,
                                    context: 'content_block',
                                    field_group: 'content_blocks',
                                    type: 'content_block',
                                    plural_form_index: i,
                                    source: source,
                                    is_plural: true,
                                    slovenian_forms: formsArray
                                };
                                if (gettextDomain) formItem.gettext_domain = gettextDomain;
                                allStrings.push(formItem);
                                translationStats.content_blocks++;
                            }
                        }

                        window.linguaDebug('  ✅ LINGUA v5.2.134: Saved 4 Slovenian/Gaelic plural forms');
                    } else if ($form0.length > 0 && $form4.length > 0 && !$form5.length) {
                        // v5.2.136: 5-form plural (Irish, Breton)
                        window.linguaDebug('  📦 LINGUA v5.2.136: 5-form plural detected (Irish/Breton)');

                        var source = $item.attr('data-source') || 'custom';
                        var gettextDomain = $item.attr('data-gettext-domain') || null;

                        // Collect all 5 forms
                        var irishFormsArray = [];
                        for (var i = 0; i <= 4; i++) {
                            var $formI = $item.find('.lingua-plural-form-' + i);
                            var formOriginal = $formI.attr('data-original') || '';
                            var formTranslated = $formI.val() ? $formI.val().trim() : '';
                            irishFormsArray.push(formTranslated);

                            window.linguaDebug('🔢 LINGUA v5.2.136 COLLECT: Form ' + i + ':', formOriginal, '→', formTranslated);

                            // Save each form
                            if (formOriginal && formTranslated) {
                                var formItem = {
                                    id: 'modern_plural_form' + i + '_' + index,
                                    original: formOriginal,
                                    translated: formTranslated,
                                    context: 'content_block',
                                    field_group: 'content_blocks',
                                    type: 'content_block',
                                    plural_form_index: i,
                                    source: source,
                                    is_plural: true,
                                    irish_forms: irishFormsArray
                                };
                                if (gettextDomain) formItem.gettext_domain = gettextDomain;
                                allStrings.push(formItem);
                                translationStats.content_blocks++;
                            }
                        }

                        window.linguaDebug('  ✅ LINGUA v5.2.136: Saved 5 Irish/Breton plural forms');
                    } else if ($form0.length > 0 && $form1.length > 0 && $form2.length > 0) {
                        // Russian 3-form plural
                        window.linguaDebug('  📦 Russian 3-form plural detected');

                        // v5.2.57: Extract gettext info from plural group container
                        var source = $item.attr('data-source') || 'custom';
                        var gettextDomain = $item.attr('data-gettext-domain') || null;

                        var form0Original = $form0.attr('data-original') || '';
                        var form0Translated = $form0.val() ? $form0.val().trim() : '';
                        var form1Original = $form1.attr('data-original') || '';
                        var form1Translated = $form1.val() ? $form1.val().trim() : '';
                        var form2Original = $form2.attr('data-original') || '';
                        var form2Translated = $form2.val() ? $form2.val().trim() : '';

                        window.linguaDebug('🔢 LINGUA v5.2.85 COLLECT: Form 0:', form0Original, '→', form0Translated);
                        window.linguaDebug('🔢 LINGUA v5.2.85 COLLECT: Form 1:', form1Original, '→', form1Translated);
                        window.linguaDebug('🔢 LINGUA v5.2.85 COLLECT: Form 2:', form2Original, '→', form2Translated);

                        // v5.2.68: Collect all Russian forms for russian_forms array
                        var russianFormsArray = [form0Translated, form1Translated, form2Translated];

                        // Save form 0 (singular - товар) WITH is_plural and russian_forms
                        if (form0Original && form0Translated) {
                            var form0Item = {
                                id: 'modern_plural_form0_' + index,
                                original: form0Original,
                                translated: form0Translated,
                                context: 'content_block',
                                field_group: 'content_blocks',
                                type: 'content_block',
                                plural_form_index: 0,
                                source: source,
                                is_plural: true,
                                russian_forms: russianFormsArray  // All 3 forms
                            };
                            if (gettextDomain) form0Item.gettext_domain = gettextDomain;
                            allStrings.push(form0Item);
                            translationStats.content_blocks++;
                        }

                        // Save form 1 (few - товара) WITH is_plural and russian_forms
                        if (form1Original && form1Translated) {
                            var form1Item = {
                                id: 'modern_plural_form1_' + index,
                                original: form1Original,
                                translated: form1Translated,
                                context: 'content_block',
                                field_group: 'content_blocks',
                                type: 'content_block',
                                plural_form_index: 1,
                                source: source,
                                is_plural: true,
                                russian_forms: russianFormsArray  // All 3 forms
                            };
                            if (gettextDomain) form1Item.gettext_domain = gettextDomain;
                            allStrings.push(form1Item);
                            translationStats.content_blocks++;
                        }

                        // Save form 2 (many - товаров) WITH is_plural and russian_forms
                        if (form2Original && form2Translated) {
                            var form2Item = {
                                id: 'modern_plural_form2_' + index,
                                original: form2Original,
                                translated: form2Translated,
                                context: 'content_block',
                                field_group: 'content_blocks',
                                type: 'content_block',
                                plural_form_index: 2,
                                source: source,
                                is_plural: true,
                                russian_forms: russianFormsArray  // All 3 forms
                            };
                            if (gettextDomain) form2Item.gettext_domain = gettextDomain;
                            allStrings.push(form2Item);
                            translationStats.content_blocks++;
                        }

                        window.linguaDebug('  ✅ Saved 3 Russian plural forms');
                    } else if ($form0.length > 0 && $form1.length > 0) {
                        // v5.2.105: 2-form plural (Italian, French, German, etc) - use NEW .lingua-plural-form-N classes
                        window.linguaDebug('  📦 LINGUA v5.2.105: 2-form plural detected (Italian/French/etc)');

                        // v5.2.105: Extract gettext info from plural group container
                        var source = $item.attr('data-source') || 'custom';
                        var gettextDomain = $item.attr('data-gettext-domain') || null;

                        var form0Original = $form0.attr('data-original') || '';
                        var form0Translated = $form0.val() ? $form0.val().trim() : '';
                        var form1Original = $form1.attr('data-original') || '';
                        var form1Translated = $form1.val() ? $form1.val().trim() : '';

                        // v5.2.105: Get plural original for linking (stored in data-plural-original)
                        var pluralOriginal = $form1.attr('data-plural-original') || form1Original;

                        window.linguaDebug('  🔍 LINGUA v5.2.105: form0Original (singular):', form0Original, '→', form0Translated);
                        window.linguaDebug('  🔍 LINGUA v5.2.105: form1Original (plural):', form1Original, '→', form1Translated);
                        window.linguaDebug('  🔍 LINGUA v5.2.105: pluralOriginal (for linking):', pluralOriginal);

                        // v5.2.112: CRITICAL CHECK - Are both forms filled?
                        window.linguaDebug('🔥🔥🔥 LINGUA v5.2.112 COLLECT CHECK:');
                        window.linguaDebug('  Form 0 HAS translation?', (form0Original && form0Translated) ? 'YES' : 'NO');
                        window.linguaDebug('  Form 1 HAS translation?', (form1Original && form1Translated) ? 'YES' : 'NO');

                        // Save form 0 (singular: product → prodotto) WITH plural_form_index=0 and plural_pair
                        if (form0Original && form0Translated) {
                            var singularItem = {
                                id: 'modern_plural_form0_' + index,
                                original: form0Original,
                                translated: form0Translated,
                                context: 'content_block',
                                field_group: 'content_blocks',
                                type: 'content_block',
                                source: source,
                                is_plural: true,
                                plural_form_index: 0,  // Singular = form 0
                                plural_pair: pluralOriginal  // Link to plural counterpart
                            };
                            if (gettextDomain) singularItem.gettext_domain = gettextDomain;
                            allStrings.push(singularItem);
                            translationStats.content_blocks++;
                            window.linguaDebug('  ✅ LINGUA v5.2.105: Saved form 0 (singular) WITH metadata: plural_form_index=0, plural_pair=' + pluralOriginal);
                        }

                        // Save form 1 (plural: products → prodotti) WITH plural_form_index=1 and plural_pair
                        if (form1Original && form1Translated) {
                            var pluralItem = {
                                id: 'modern_plural_form1_' + index,
                                original: form1Original,
                                translated: form1Translated,
                                context: 'content_block',
                                field_group: 'content_blocks',
                                type: 'content_block',
                                source: source,
                                is_plural: true,
                                plural_form_index: 1,  // Plural = form 1
                                plural_pair: form0Original  // Link to singular counterpart
                            };
                            if (gettextDomain) pluralItem.gettext_domain = gettextDomain;
                            allStrings.push(pluralItem);
                            translationStats.content_blocks++;
                            window.linguaDebug('  ✅ LINGUA v5.2.105: Saved form 1 (plural) WITH metadata: plural_form_index=1, plural_pair=' + form0Original);
                        }

                        window.linguaDebug('  ✅ LINGUA v5.2.105: Saved 2 forms (singular + plural)');
                    } else if ($form0.length > 0) {
                        // v5.2.118: 1-form (no plurals): Chinese, Japanese, Korean, Thai, Vietnamese, Turkish
                        window.linguaDebug('  📦 LINGUA v5.2.118: 1-form (no plurals) detected (Chinese/Japanese/etc)');

                        // Extract gettext info from plural group container
                        var source = $item.attr('data-source') || 'custom';
                        var gettextDomain = $item.attr('data-gettext-domain') || null;

                        var form0Original = $form0.attr('data-original') || '';
                        var form0Translated = $form0.val() ? $form0.val().trim() : '';

                        window.linguaDebug('  🔍 LINGUA v5.2.118: form0Original:', form0Original, '→', form0Translated);

                        // Save single form WITH plural_form_index=0
                        if (form0Original && form0Translated) {
                            var singleFormItem = {
                                id: 'modern_plural_form0_' + index,
                                original: form0Original,
                                translated: form0Translated,
                                context: 'content_block',
                                field_group: 'content_blocks',
                                type: 'content_block',
                                source: source,
                                is_plural: true,
                                plural_form_index: 0  // Single form = form 0
                            };
                            if (gettextDomain) singleFormItem.gettext_domain = gettextDomain;
                            allStrings.push(singleFormItem);
                            translationStats.content_blocks++;
                            window.linguaDebug('  ✅ LINGUA v5.2.118: Saved single form WITH plural_form_index=0');
                        }

                        window.linguaDebug('  ✅ LINGUA v5.2.118: Saved 1 form (no plurals)');
                    }

                    return; // Skip regular processing for plural groups
                }

                // Regular item (not plural group)
                // v5.0.11 FIX: .lingua-original-text is DIV, use .text()
                var originalText = $item.find('.lingua-original-text').text().trim();
                var translatedText = $item.find('.lingua-translated-text').val().trim();

                // v5.2.163: Include items with empty translated for deletion
                if (originalText) {
                    // Determine content type by parent section
                    var $section = $item.closest('.lingua-content-section');
                    var sectionHeader = $section.find('.lingua-section-header').text();
                    var context = 'general';
                    var field_group = 'page_content';

                    if (sectionHeader.includes('📄')) {
                        context = 'content_block';
                        field_group = 'content_blocks';
                        if (translatedText) translationStats.content_blocks++;
                    } else if (sectionHeader.includes('🎯')) {
                        context = 'page_string';
                        field_group = 'page_strings';
                        if (translatedText) translationStats.page_strings++;
                    } else if (sectionHeader.includes('⚙️')) {
                        context = 'attribute';
                        field_group = 'attributes';
                        if (translatedText) translationStats.attributes++;
                    }

                    // v5.2.57: CRITICAL FIX - Extract source and gettext_domain from data attributes
                    var source = $item.attr('data-source') || 'custom';
                    var gettextDomain = $item.attr('data-gettext-domain') || null;

                    var translationItem = {
                        id: 'modern_' + index,
                        original: originalText,
                        translated: translatedText,  // v5.2.163: Can be empty for deletion
                        context: context,
                        field_group: field_group,
                        type: 'modern_v3_translation',
                        source: source
                    };

                    // v5.2.57: Add gettext_domain if present
                    if (gettextDomain) {
                        translationItem.gettext_domain = gettextDomain;
                    }

                    allStrings.push(translationItem);
                    window.linguaDebug('📝 LINGUA v5.2.163:', field_group, translatedText ? 'SAVE' : 'DELETE', originalText.substring(0, 30));
                }
            });

            // v5.2.163: Collect media translations from Media tab (including empty for deletion)
            window.linguaDebug("🖼️ LINGUA v5.2.163: Collecting media translations from Media tab");
            $('.lingua-media-card').each(function() {
                var $card = $(this);
                var srcHash = $card.data('src-hash');

                $card.find('.lingua-media-translated').each(function() {
                    var $input = $(this);
                    var attribute = $input.data('attribute');
                    var translated = $input.val().trim();

                    // Get original value
                    var $original;
                    if (attribute === 'src') {
                        $original = $input.closest('.lingua-media-input-wrapper').prev('.lingua-media-original');
                    } else {
                        $original = $input.prev('.lingua-media-original');
                    }
                    var original = $original.val() || '';

                    // v5.2.163: Skip only if both original and translated are empty
                    if (!original && !translated) {
                        return;
                    }

                    allStrings.push({
                        id: 'media_' + srcHash + '_' + attribute,
                        original: original,
                        translated: translated,  // Can be empty - server will delete
                        context: 'media',
                        field_group: 'media',
                        type: 'media_' + attribute,
                        src_hash: srcHash,
                        attribute: attribute,
                        source: 'v5.2_media_tab'
                    });

                    // Update media counter
                    if (!translationStats.media) translationStats.media = 0;
                    translationStats.media++;

                    window.linguaDebug('🖼️ LINGUA v5.2.163: Collected media:', attribute, 'hash:', srcHash, translated ? 'SAVE' : 'DELETE');
                });
            });

            var unifiedTranslations = {
                translation_strings: JSON.stringify(allStrings),
                statistics: translationStats,
                total_strings: allStrings.length,
                version: 'v3.2_tabbed_seo'
            };

            window.linguaDebug("✅ LINGUA v3.2: Collected", allStrings.length, "translations");
            window.linguaDebug("📊 LINGUA v3.2: Statistics:", translationStats);

            return unifiedTranslations;
        },

        /**
         * Обновление прогресс-бара с иконками статуса
         */
        updateProgress: function(percent, text, status) {
            var $progressFill = $('.lingua-progress-fill');
            var $progressText = $('.lingua-progress-text');
            
            // Обновляем ширину прогресс-бара
            $progressFill.css('width', percent + '%');
            
            // Убираем предыдущие классы статуса
            $progressFill.removeClass('success error');
            $progressText.removeClass('success error');
            
            // Создаем текст с иконкой
            var iconHtml = '';
            if (status === 'success') {
                iconHtml = '<span class="status-icon success">✓</span>';
                $progressFill.addClass('success');
                $progressText.addClass('success');
            } else if (status === 'error') {
                iconHtml = '<span class="status-icon error">✗</span>';
                $progressFill.addClass('error');
                $progressText.addClass('error');
            }
            
            // Обновляем текст с иконкой
            $progressText.html(iconHtml + text);
        },
        
        /**
         * Обработка изменения языка
         */
        onLanguageChange: function() {
            // v5.2: Update iframe preview with new language
            var selectedLang = $('#lingua-target-lang').val();
            if (selectedLang) {
                this.updatePreviewIframe(selectedLang);
            }

            this.resetModal(true);

            // v5.2: Auto-refresh content when language changes
            this.extractContent();
        },
        
        /**
         * Вспомогательные методы
         */
        
        // Обрезка текста
        truncateText: function(text, maxLength) {
            if (!text || typeof text !== 'string') return text || '';
            if (text.length <= maxLength) return text;
            return text.substr(0, maxLength) + '...';
        },
        
        // Экранирование HTML
        escapeHtml: function(text) {
            if (!text || typeof text !== 'string') return text || '';
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },
        
        // Форматирование названий мета-полей
        formatMetaLabel: function(key) {
            return key.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
                return l.toUpperCase();
            });
        },
        
        // Форматирование контекста строк (v2.0 Enhanced with 20+ context types)
        formatContextLabel: function(context) {
            window.linguaDebug('🏷️ LINGUA v2.0: Formatting context label for:', context);

            var contextLabels = {
                // Text contexts from v2.0 architecture
                'heading': '📝 Heading',
                'paragraph': '📄 Paragraph',
                'button_text': '🔘 Button',
                'form_label': '🏷️ Form Label',
                'list_item': '📋 List Item',
                'table_cell': '📊 Table Cell',
                'page_title': '🏷️ Page Title',
                'text_node': '📝 Text Content',

                // Attribute contexts from v2.0 architecture
                'attribute_title': '💬 Title Attribute',
                'attribute_alt': '🖼️ Alt Text',
                'attribute_placeholder': '📝 Placeholder',
                'attribute_aria-label': '♿ Aria Label',
                'attribute_aria-description': '♿ Aria Description',
                'attribute_data-title': '📋 Data Title',
                'attribute_data-caption': '📷 Data Caption',
                'attribute_data-alt': '🖼️ Data Alt',

                // Legacy contexts
                'general': '📄 General String',
                'menu': '🍔 Menu Item',
                'button': '🔘 Button Text',
                'widget': '🔧 Widget Text',
                'admin_bar': '⚙️ Admin Bar',

                // WooCommerce specific contexts
                'product_title': '🛍️ Product Title',
                'product_description': '📝 Product Description',
                'product_attributes': '🏷️ Product Attributes'
            };

            // Add icon and improved formatting for unknown contexts
            if (contextLabels[context]) {
                return contextLabels[context];
            }

            // Format unknown contexts nicely
            var formatted = context.replace(/_/g, ' ').replace(/\b\w/g, function(l) {
                return l.toUpperCase();
            });

            // Add appropriate icons based on context patterns
            if (context.includes('heading')) return '📝 ' + formatted;
            if (context.includes('button')) return '🔘 ' + formatted;
            if (context.includes('attribute')) return '🏷️ ' + formatted;
            if (context.includes('form')) return '📝 ' + formatted;
            if (context.includes('table')) return '📊 ' + formatted;
            if (context.includes('list')) return '📋 ' + formatted;

            return '📄 ' + formatted;
        },
        
        /**
         * Показать красивое уведомление
         */
        showNotification: function(message, onConfirm) {
            var $notification = $('#lingua-notification');
            var $messageEl = $notification.find('.lingua-notification-message');
            
            // Устанавливаем сообщение
            $messageEl.text(message);
            
            // Показываем уведомление
            $notification.removeClass('hidden');
            
            // Обработчики кнопок
            $('#lingua-notification-confirm').off('click').on('click', function() {
                translationModal.hideNotification();
                if (onConfirm) {
                    onConfirm();
                }
            });
            
            $('#lingua-notification-cancel').off('click').on('click', function() {
                translationModal.hideNotification();
            });
        },
        
        /**
         * Скрыть уведомление
         */
        hideNotification: function() {
            $('#lingua-notification').addClass('hidden');
        },

        /**
         * Live search functionality with highlighting and scroll-to-result
         */
        performLiveSearch: function(event) {
            const searchTerm = $(event.target).val().trim().toLowerCase();
            const $resultsInfo = $('.lingua-search-results-info');

            // Clear previous highlights and focus
            this.clearSearchHighlights();
            this.clearSearchFocus();

            if (searchTerm.length < 2) {
                // Show only v3.0 elements, keep legacy hidden
                $('#lingua-unified-content .lingua-translation-item').show();
                $('#lingua-page-content-section').show();

                // Keep legacy sections hidden
                $('.lingua-seo-section').hide();
                $('.lingua-core-fields-section').hide();
                $('.lingua-meta-fields-section').hide();

                $resultsInfo.removeClass('visible').text('');
                return;
            }

            // v5.0.12: Auto-open Page Content tab when searching
            var $contentTab = $('.lingua-tab-button[data-tab="content"]');
            if ($contentTab.length > 0 && !$contentTab.hasClass('active')) {
                window.linguaDebug('🔍 LINGUA Search: Opening Page Content tab');
                $contentTab.trigger('click');
            }

            const searchableElements = this.findSearchableElements();
            window.linguaDebug('🔍 LINGUA Search: Found', searchableElements.length, 'searchable elements');
            const matchedElements = [];

            // Hide all v3.0 unified content elements first
            $('#lingua-unified-content .lingua-translation-item').hide();

            // v5.2.43: Search through elements by original, english gettext, AND translated text
            searchableElements.forEach(element => {
                const $element = $(element.element);
                const originalText = element.text.toLowerCase();
                const englishOriginal = element.englishOriginal.toLowerCase();  // v5.2.39: English gettext hint
                const translatedText = element.translatedText.toLowerCase();  // v5.2.43: Translated text

                // v5.2.43: Match if searchTerm found in original, English gettext, OR translation
                const matchesOriginal = originalText.includes(searchTerm);
                const matchesEnglish = englishOriginal && englishOriginal.includes(searchTerm);
                const matchesTranslated = translatedText && translatedText.includes(searchTerm);  // v5.2.43: Reverse search

                if (matchesOriginal || matchesEnglish || matchesTranslated) {
                    matchedElements.push(element);
                    $element.show();

                    // v5.2.54: Always add search-focus class to entire item for better visibility
                    $element.addClass('lingua-search-match');

                    // Add highlighting to the v3.0 structure
                    if (matchesOriginal || matchesEnglish) {
                        this.highlightSearchTerm($element.find('.lingua-original-text'), searchTerm);
                    }
                    // v5.2.43: Also highlight in translation if matched there
                    if (matchesTranslated && element.$textarea && element.$textarea.length > 0) {
                        // Note: We can't highlight inside textarea, but we can indicate the match
                        element.$textarea.addClass('lingua-search-match');
                    }
                }
            });

            // Update results info
            this.updateSearchResults(matchedElements.length, searchTerm);

            // Auto-scroll to first result
            if (matchedElements.length > 0) {
                this.scrollToSearchResult($(matchedElements[0].element), true);
            }
        },

        /**
         * Handle keyboard navigation in search
         */
        handleSearchKeydown: function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.navigateSearchResults('next');
            } else if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.navigateSearchResults('next');
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.navigateSearchResults('prev');
            } else if (event.key === 'Escape') {
                this.clearSearch();
            }
        },

        /**
         * Find all searchable elements (v3.0 unified content only)
         * v5.2.39: Now also extracts english_original for gettext search
         * v5.2.43: Added translatedText for reverse search
         */
        findSearchableElements: function() {
            const elements = [];

            // Only search within the v3.0 unified content section
            $('#lingua-unified-content .lingua-translation-item').each(function() {
                const $item = $(this);
                const $originalText = $item.find('.lingua-original-text');
                const $translatedText = $item.find('.lingua-translated-text');  // v5.2.63: More specific selector

                if ($originalText.length > 0) {
                    const text = $originalText.text() || '';
                    const englishOriginal = $item.attr('data-english-original') || '';  // v5.2.39: Extract English gettext hint
                    const translated = $translatedText.length > 0 ? $translatedText.val() || '' : '';  // v5.2.43: Translated text

                    if (text.trim()) {
                        elements.push({
                            element: this,
                            text: text,
                            englishOriginal: englishOriginal,  // v5.2.39: For dual-language search
                            translatedText: translated,  // v5.2.43: For reverse search
                            $input: $originalText,
                            $textarea: $translatedText  // v5.2.43: For highlighting translations
                        });
                    }
                }
            });

            return elements;
        },

        /**
         * Highlight search term in text
         */
        highlightSearchTerm: function($inputs, searchTerm) {
            const self = this;
            $inputs.each(function() {
                const $input = $(this);
                const originalValue = $input.val();
                const regex = new RegExp(`(${self.escapeRegExp(searchTerm)})`, 'gi');

                // For input fields, we can't directly highlight, so we use a different approach
                // Add a class to indicate this input has a match
                $input.addClass('lingua-search-match');

                // Store original value for cleanup
                $input.data('original-value', originalValue);
            });
        },

        /**
         * Clear all search highlights
         */
        clearSearchHighlights: function() {
            $('.lingua-search-match').removeClass('lingua-search-match');
            $('.lingua-search-highlight').each(function() {
                const $this = $(this);
                $this.replaceWith($this.text());
            });
        },

        /**
         * Clear search focus styling (v3.0 unified content)
         */
        clearSearchFocus: function() {
            $('.lingua-translation-item').removeClass('search-focus');
        },

        /**
         * Update search results information
         */
        updateSearchResults: function(count, searchTerm) {
            const $resultsInfo = $('.lingua-search-results-info');

            if (count === 0) {
                $resultsInfo.addClass('visible').text(`No results for "${searchTerm}"`);

                // Show "no results" message in content area
                const $contentSections = $('.lingua-content-sections');
                if ($contentSections.find('.lingua-search-no-results').length === 0) {
                    $contentSections.append(`
                        <div class="lingua-search-no-results">
                            No strings found matching "${searchTerm}"<br>
                            <small>Try a different search term</small>
                        </div>
                    `);
                }
            } else {
                $resultsInfo.addClass('visible').text(`${count} result${count !== 1 ? 's' : ''} for "${searchTerm}"`);
                $('.lingua-search-no-results').remove();
            }
        },

        /**
         * Navigate through search results (v3.0 unified content)
         */
        navigateSearchResults: function(direction) {
            const $visibleItems = $('#lingua-unified-content .lingua-translation-item:visible');
            if ($visibleItems.length === 0) return;

            let $currentFocus = $('.lingua-translation-item.search-focus');
            let nextIndex = 0;

            if ($currentFocus.length > 0) {
                const currentIndex = $visibleItems.index($currentFocus);
                if (direction === 'next') {
                    nextIndex = (currentIndex + 1) % $visibleItems.length;
                } else {
                    nextIndex = currentIndex > 0 ? currentIndex - 1 : $visibleItems.length - 1;
                }
            }

            this.clearSearchFocus();
            const $nextElement = $visibleItems.eq(nextIndex);
            this.scrollToSearchResult($nextElement);
        },

        /**
         * Scroll to search result with highlighting
         */
        scrollToSearchResult: function($element, isFirst = false) {
            if ($element.length === 0) return;

            this.clearSearchFocus();
            $element.addClass('search-focus');

            // Scroll to element within the modal body
            const $modalBody = $('.lingua-modal-body');
            const elementTop = $element.position().top;
            const modalScrollTop = $modalBody.scrollTop();
            const modalHeight = $modalBody.height();
            const elementHeight = $element.outerHeight();

            // Calculate optimal scroll position (center the element)
            const targetScrollTop = modalScrollTop + elementTop - (modalHeight / 2) + (elementHeight / 2);

            $modalBody.animate({
                scrollTop: Math.max(0, targetScrollTop)
            }, isFirst ? 300 : 150);
        },

        /**
         * Clear search and show all elements (v3.0 compatible)
         */
        clearSearch: function() {
            $('#lingua-live-search').val('');
            this.clearSearchHighlights();
            this.clearSearchFocus();

            // Only show v3.0 compatible elements, keep legacy sections hidden
            $('#lingua-unified-content .lingua-translation-item').show();
            $('#lingua-page-content-section').show();

            // Keep legacy sections hidden (SEO, title, excerpt)
            $('.lingua-seo-section').hide();
            $('.lingua-core-fields-section').hide();
            $('.lingua-meta-fields-section').hide();

            $('.lingua-search-results-info').removeClass('visible');
            $('.lingua-search-no-results').remove();
        },

        /**
         * v5.0.13: Clear all translations for current page
         */
        clearPageTranslations: function(e) {
            if (e) e.preventDefault();

            var targetLang = $('#lingua-target-lang').val();
            if (!targetLang) {
                alert('Please select target language');
                return;
            }

            var confirmMsg = 'Are you sure you want to delete all translations for this page in ' + targetLang.toUpperCase() + ' language?\n\nThis action cannot be undone!';
            if (!confirm(confirmMsg)) {
                return;
            }

            var self = this;
            var data = {
                action: 'lingua_delete_page_translations',
                nonce: lingua_admin.nonce,
                language: targetLang
            };

            if (this.currentPageType === 'taxonomy') {
                data.term_id = this.currentTermId;
            } else {
                data.post_id = this.currentPostId;
            }

            window.linguaDebug('🗑️ LINGUA v5.0.13: Clearing translations:', data);

            $.post(lingua_admin.ajax_url, data)
                .done(function(response) {
                    window.linguaDebug('✅ LINGUA v5.0.13: Clear response:', response);
                    if (response.success) {
                        alert('Deleted ' + response.data.deleted + ' translations. Reloading...');
                        // Reload page to show cleared state
                        window.location.reload();
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error'));
                    }
                })
                .fail(function(xhr, status, error) {
                    console.error('❌ LINGUA v5.0.13: Clear failed:', error);
                    alert('Failed to clear translations: ' + error);
                });
        },

        /**
         * v5.0.12: Apply translations to current page immediately (live preview)
         */
        applyTranslationsToPage: function(translations) {
            window.linguaDebug('🎨 LINGUA v5.0.12: Applying', Object.keys(translations).length, 'translations to page');

            var appliedCount = 0;

            // Iterate through all text nodes on the page
            var walker = document.createTreeWalker(
                document.body,
                NodeFilter.SHOW_TEXT,
                {
                    acceptNode: function(node) {
                        // Skip script, style, and lingua modal
                        var parent = node.parentElement;
                        if (!parent) return NodeFilter.FILTER_REJECT;

                        var tagName = parent.tagName.toLowerCase();
                        if (tagName === 'script' || tagName === 'style' || tagName === 'noscript') {
                            return NodeFilter.FILTER_REJECT;
                        }

                        // Skip lingua modal itself
                        if (parent.closest('.lingua-modal')) {
                            return NodeFilter.FILTER_REJECT;
                        }

                        // Skip admin bar
                        if (parent.closest('#wpadminbar')) {
                            return NodeFilter.FILTER_REJECT;
                        }

                        // Only accept text nodes with content
                        if (node.nodeValue && node.nodeValue.trim().length > 0) {
                            return NodeFilter.FILTER_ACCEPT;
                        }

                        return NodeFilter.FILTER_REJECT;
                    }
                },
                false
            );

            var nodesToUpdate = [];
            var node;

            // Collect all text nodes first (to avoid issues with live DOM updates)
            while (node = walker.nextNode()) {
                nodesToUpdate.push(node);
            }

            // Now apply translations
            nodesToUpdate.forEach(function(textNode) {
                var originalText = textNode.nodeValue.trim();
                var replaced = false;

                // Try exact match first
                if (translations[originalText]) {
                    textNode.nodeValue = textNode.nodeValue.replace(originalText, translations[originalText]);
                    appliedCount++;
                    replaced = true;
                    window.linguaDebug('✅ Replaced:', originalText.substring(0, 50) + '...');
                } else {
                    // Try to find partial matches (for text that might have extra whitespace)
                    for (var original in translations) {
                        if (originalText.includes(original)) {
                            textNode.nodeValue = textNode.nodeValue.replace(original, translations[original]);
                            appliedCount++;
                            replaced = true;
                            window.linguaDebug('✅ Replaced (partial):', original.substring(0, 50) + '...');
                            break;
                        }
                    }
                }

                // Add highlight animation to parent element
                if (replaced && textNode.parentElement) {
                    var parent = textNode.parentElement;
                    parent.classList.add('lingua-live-preview-replaced');
                    setTimeout(function() {
                        parent.classList.remove('lingua-live-preview-replaced');
                    }, 1500);
                }
            });

            window.linguaDebug('✨ LINGUA v5.0.12: Applied', appliedCount, 'translations to page');

            if (appliedCount > 0) {
                // Show a brief notification
                this.showNotification('Live preview: ' + appliedCount + ' translations applied!', 'success', 3000);
            }
        },

        /**
         * v5.0.12: Clear page cache via AJAX
         */
        clearPageCache: function() {
            $.ajax({
                url: lingua_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'lingua_clear_page_cache',
                    nonce: lingua_admin.nonce,
                    url: window.location.href
                },
                success: function(response) {
                    if (response.success) {
                        window.linguaDebug('✅ Page cache cleared:', response.data);
                    } else {
                        console.warn('⚠️ Cache clear warning:', response.data);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('❌ Cache clear error:', error);
                }
            });
        },

        /**
         * Escape special characters for regex
         */
        escapeRegExp: function(string) {
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        },

        /**
         * Debounce function to limit search frequency
         */
        debounce: function(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func.apply(this, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },

        /**
         * Panel drag-resize functionality
         */
        isResizing: false,
        initialX: 0,
        initialWidth: 0,
        iframeResizeDebounceTimer: null,

        startResize: function(event) {
            event.preventDefault();
            this.isResizing = true;
            this.initialX = event.clientX;

            const $modal = $('.lingua-modal');
            this.initialWidth = parseInt($modal.css('width'), 10);

            $modal.addClass('resizing');
            $('body').addClass('lingua-resizing-cursor');

            // Bind mouse events
            $(document).on('mousemove.lingua-resize', this.performResize.bind(this));
            $(document).on('mouseup.lingua-resize', this.stopResize.bind(this));

            // Prevent text selection during resize
            $(document).on('selectstart.lingua-resize', function() {
                return false;
            });
        },

        performResize: function(event) {
            if (!this.isResizing) return;

            const deltaX = event.clientX - this.initialX;
            const newWidth = Math.max(300, Math.min(window.innerWidth * 0.8, this.initialWidth + deltaX));
            const newWidthPercent = (newWidth / window.innerWidth) * 100;

            const $modal = $('.lingua-modal');
            const $body = $('body');

            // Update panel width
            $modal.css('width', newWidthPercent + '%');

            // Push site content by updating body margin-left to match panel width
            $body.addClass('lingua-panel-resized').css('margin-left', newWidthPercent + '%');

            // v5.2: Debounced iframe resize - wait 1 second after resize stops
            if (this.iframeResizeDebounceTimer) {
                clearTimeout(this.iframeResizeDebounceTimer);
            }
            this.iframeResizeDebounceTimer = setTimeout(function() {
                this.syncIframeResize(newWidthPercent);
            }.bind(this), 1000);

            // Apply responsive classes based on width
            this.updateResponsiveClasses(newWidth);
        },

        stopResize: function() {
            if (!this.isResizing) return;

            this.isResizing = false;

            const $modal = $('.lingua-modal');
            const $body = $('body');

            $modal.removeClass('resizing');
            $body.removeClass('lingua-resizing-cursor lingua-panel-resized');

            // Re-enable transition
            $body.addClass('lingua-panel-open');

            // Unbind mouse events
            $(document).off('mousemove.lingua-resize');
            $(document).off('mouseup.lingua-resize');
            $(document).off('selectstart.lingua-resize');

            // Save the new width to localStorage
            const currentWidth = parseInt($modal.css('width'), 10);
            localStorage.setItem('lingua-panel-width', currentWidth);
        },

        updateResponsiveClasses: function(width) {
            const $modal = $('.lingua-modal');

            // Remove existing size classes
            $modal.removeClass('size-small size-medium size-large');

            // Apply new size class based on width
            if (width < 400) {
                $modal.addClass('size-small');
            } else if (width < 600) {
                $modal.addClass('size-medium');
            } else {
                $modal.addClass('size-large');
            }
        },

        restorePanelWidth: function() {
            const savedWidth = localStorage.getItem('lingua-panel-width');
            if (savedWidth) {
                const $modal = $('.lingua-modal');
                const $body = $('body');
                const widthPercent = (parseInt(savedWidth, 10) / window.innerWidth) * 100;

                $modal.css('width', widthPercent + '%');
                $body.css('margin-left', widthPercent + '%');

                this.updateResponsiveClasses(parseInt(savedWidth, 10));
            }
        },

        resetPanelWidth: function() {
            const $modal = $('.lingua-modal');
            const $body = $('body');

            $modal.css('width', '40%');
            $body.css('margin-left', '40%');
            $modal.removeClass('size-small size-medium size-large');
            localStorage.removeItem('lingua-panel-width');
        },

        /**
         * Update global translation status indicator
         */
        updateGlobalStatusIndicator: function(globalEnabled) {
            const $statusText = $('#lingua-global-status-text');
            const $indicator = $('#lingua-global-mode-indicator');

            if (globalEnabled === true || globalEnabled === 'true' || globalEnabled === 1 || globalEnabled === '1') {
                $statusText.text(lingua_admin.strings.enabled || 'Enabled').css('color', '#0f8a4e');
                $indicator.find('span:first-child').text('🌐');
                window.linguaDebug('Lingua: Global translation mode is enabled');
            } else {
                $statusText.text(lingua_admin.strings.disabled || 'Disabled').css('color', '#dc3545');
                $indicator.find('span:first-child').text('🔒');
                window.linguaDebug('Lingua: Global translation mode is disabled');
            }
        },

        /**
         * Apply translations to the current page without reload
         * v2.1 UNIFIED: Works with unified JSON structure from collectTranslations()
         */
        applyTranslationsLive: function(translations) {
            window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: applyTranslationsLive() called with:', translations);

            // Check if we have unified v2.1 structure (translation_strings JSON array)
            if (translations.translation_strings) {
                window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: Processing unified JSON structure');
                this.applyUnifiedTranslationsLive(translations.translation_strings);
                return;
            }

            // Fallback: Legacy processing for backward compatibility
            window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: Falling back to legacy processing for:', Object.keys(translations).length, 'fields');

            // Process different types of translations (legacy format)
            for (const fieldKey in translations) {
                const translatedText = translations[fieldKey];

                window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: Processing legacy field:', fieldKey, 'value:', translatedText);

                if (!translatedText || translatedText.trim() === '') {
                    continue;
                }

                // Handle different field types (legacy)
                if (fieldKey === 'title') {
                    this.applyTitleTranslation(translatedText);
                } else if (fieldKey === 'excerpt') {
                    this.applyExcerptTranslation(translatedText);
                } else if (fieldKey === 'seo_title') {
                    this.applySeoTitleTranslation(translatedText);
                } else if (fieldKey === 'seo_description') {
                    this.applySeoDescriptionTranslation(translatedText);
                } else if (fieldKey === 'woo_short_desc') {
                    this.applyWooShortDescTranslation(translatedText);
                } else if (fieldKey.startsWith('content-')) {
                    // Content blocks
                    window.linguaDebug('🔍 LINGUA v2.0.3 DIAGNOSTICS: Applying CONTENT-BLOCK translation');
                    const blockId = fieldKey.replace('content-', '');
                    this.applyContentBlockTranslation(blockId, translatedText);
                } else if (fieldKey.startsWith('string-')) {
                    // Page strings from v2.0 extraction
                    window.linguaDebug('🔍 LINGUA v2.0.3 DIAGNOSTICS: Applying PAGE STRING translation from v2.0');
                    const originalString = this.getOriginalStringByKey(fieldKey);
                    if (originalString) {
                        window.linguaDebug('🔍 LINGUA v2.0.3 DIAGNOSTICS: Found original string:', originalString);
                        this.applyStringTranslationToPage(originalString, translatedText);
                    } else {
                        window.linguaDebug('🔍 LINGUA v2.0.3 DIAGNOSTICS: Original string NOT FOUND for:', fieldKey);
                    }
                } else if (fieldKey.startsWith('meta-')) {
                    // Meta fields
                    window.linguaDebug('🔍 LINGUA v2.0.3 DIAGNOSTICS: Applying META-FIELD translation');
                    const metaKey = fieldKey.replace('meta-', '');
                    this.applyMetaFieldTranslation(metaKey, translatedText);
                } else {
                    window.linguaDebug('🔍 LINGUA v2.0.3 DIAGNOSTICS: UNHANDLED field type:', fieldKey);
                }
            }
        },

        /**
         * Apply unified v2.1 translations - works with JSON structure from collectTranslations()
         * @param {string} translationStringsJson - JSON string containing array of translation objects
         */
        applyUnifiedTranslationsLive: function(translationStringsJson) {
            window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: applyUnifiedTranslationsLive() called');

            try {
                // Parse JSON if it's a string, or use directly if already parsed
                let translationStrings;
                if (typeof translationStringsJson === 'string') {
                    translationStrings = JSON.parse(translationStringsJson);
                } else {
                    translationStrings = translationStringsJson;
                }

                window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: Processing', translationStrings.length, 'unified translation strings');

                // Process each translation string
                translationStrings.forEach((stringData, index) => {
                    const { id, original, translated, context, type, field_group } = stringData;

                    if (!translated || translated.trim() === '') {
                        return; // Skip empty translations
                    }

                    window.linguaDebug(`🔍 LINGUA v2.1 UNIFIED: [${index}] Applying ${field_group}.${type}:`, translated);

                    // Route to appropriate handler based on field_group and type
                    switch (field_group) {
                        case 'seo_fields':
                            if (type === 'seo_title') {
                                this.applySeoTitleTranslation(translated);
                            } else if (type === 'seo_description') {
                                this.applySeoDescriptionTranslation(translated);
                            }
                            break;

                        case 'core_fields':
                            if (type === 'title') {
                                this.applyTitleTranslation(translated);
                            } else if (type === 'excerpt') {
                                this.applyExcerptTranslation(translated);
                            }
                            break;

                        case 'content_blocks':
                            // v3.7.1: Check if this is a title in content_blocks
                            // Check by type OR by checking if h1.product_title exists with this text
                            const isTitle = type === 'title' || type === 'product_title' || type === 'page_title';
                            const isProductTitle = $('h1.product_title, h1.entry-title').text().trim() === original.trim();

                            if (isTitle || isProductTitle) {
                                window.linguaDebug('🔍 LINGUA v3.7.1: Detected title in content_blocks, using applyTitleTranslation()');
                                this.applyTitleTranslation(translated);
                            } else {
                                // v3.7: Content blocks also use original text matching (like page_strings)
                                this.applyStringTranslationToPage(original, translated);
                            }
                            break;

                        case 'page_strings':
                            // For page strings, use the original text to find and replace in DOM
                            this.applyStringTranslationToPage(original, translated);
                            break;

                        case 'attributes':
                            // v3.6: Apply attribute translations (alt text, titles, etc.)
                            this.applyStringTranslationToPage(original, translated);
                            break;

                        case 'meta_fields':
                            if (type === 'woo_short_desc') {
                                this.applyWooShortDescTranslation(translated);
                            } else {
                                this.applyMetaFieldTranslation(id, translated);
                            }
                            break;

                        default:
                            window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: Unhandled field_group:', field_group, 'type:', type);
                    }
                });

                window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: All unified translations applied successfully');

            } catch (error) {
                console.error('🚨 LINGUA v2.1 UNIFIED: Error applying unified translations:', error);
                console.error('🚨 LINGUA v2.1 UNIFIED: Input was:', translationStringsJson);
            }
        },

        /**
         * Apply title translation
         * v3.7: Enhanced with WooCommerce product title support
         */
        applyTitleTranslation: function(translatedText) {
            window.linguaDebug('🔍 LINGUA v3.7: Applying title translation:', translatedText.substring(0, 50));

            let updated = false;

            // WooCommerce product title (priority)
            if ($('h1.product_title').length) {
                $('h1.product_title').text(translatedText);
                window.linguaDebug('✓ Updated WooCommerce product_title');
                updated = true;
            } else if ($('h1.product-title').length) {
                $('h1.product-title').text(translatedText);
                window.linguaDebug('✓ Updated WooCommerce product-title');
                updated = true;
            }
            // Standard WordPress selectors
            else if ($('h1.entry-title').length) {
                $('h1.entry-title').text(translatedText);
                window.linguaDebug('✓ Updated entry-title');
                updated = true;
            } else if ($('h1.page-title').length) {
                $('h1.page-title').text(translatedText);
                window.linguaDebug('✓ Updated page-title');
                updated = true;
            }
            // Fallback to first h1
            else if ($('h1').first().length) {
                $('h1').first().text(translatedText);
                window.linguaDebug('✓ Updated first h1');
                updated = true;
            }

            if (!updated) {
                console.warn('⚠️ LINGUA v3.7: No title element found to update');
            }

            // Update document title
            document.title = translatedText + ' - ' + document.title.split(' - ').slice(1).join(' - ');
        },

        /**
         * Apply SEO title translation
         */
        applySeoTitleTranslation: function(translatedText) {
            // Update meta title tag
            $('meta[property="og:title"]').attr('content', translatedText);
            $('meta[name="twitter:title"]').attr('content', translatedText);

            // Update Yoast SEO title if present
            if ($('title').length && $('title').text() !== translatedText) {
                $('title').text(translatedText);
            }
        },

        /**
         * Apply SEO description translation
         */
        applySeoDescriptionTranslation: function(translatedText) {
            // Update meta description tags
            $('meta[name="description"]').attr('content', translatedText);
            $('meta[property="og:description"]').attr('content', translatedText);
            $('meta[name="twitter:description"]').attr('content', translatedText);
        },

        /**
         * Apply excerpt translation
         */
        applyExcerptTranslation: function(translatedText) {
            // Update excerpt in various common locations
            $('.entry-summary p, .excerpt p, .post-excerpt p').each(function() {
                $(this).text(translatedText);
            });
        },

        /**
         * Apply WooCommerce short description translation
         */
        applyWooShortDescTranslation: function(translatedText) {
            // Update WooCommerce short description
            $('.woocommerce-product-details__short-description p').text(translatedText);
            $('.product-short-description p').text(translatedText);
        },

        /**
         * Apply content block translation
         */
        applyContentBlockTranslation: function(blockId, translatedText) {
            window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: Applying content block translation for ID:', blockId, 'text:', translatedText.substring(0, 50) + '...');

            // Try to find and replace content blocks in the page
            // Content blocks are usually paragraphs, divs, or other text containers

            // Strategy 1: Find by exact text match in paragraphs and headings
            const $contentElements = $('body p, body h1, body h2, body h3, body h4, body h5, body h6, body div:not(#lingua-translation-modal *)')
                .filter(function() {
                    const originalText = $(this).text().trim();
                    // Find elements that might contain this content block
                    return originalText.length > 10 && originalText.length < 1000;
                });

            window.linguaDebug(`🔍 LINGUA v2.1 UNIFIED: Found ${$contentElements.length} potential content elements to check`);

            // For now, log what we would do rather than actually modifying
            // This prevents accidental page breakage during live translation
            window.linguaDebug('🔍 LINGUA v2.1 UNIFIED: Content block translation logged for debugging. Block ID:', blockId);

            // TODO: Implement intelligent content block replacement
            // - Match by similarity score
            // - Preserve HTML structure
            // - Handle WordPress blocks properly
        },

        /**
         * Apply string translation to page elements
         * v3.7: Enhanced with element tracking for re-editing support
         */
        applyStringTranslationToPage: function(originalString, translatedText) {
            const trimmedOriginal = originalString.trim();
            const trimmedTranslation = translatedText.trim();

            // v3.7: Normalize <br> tags for comparison
            // On page: <br> renders as newline, but in extraction we have "<br />" or "<br>"
            const normalizeText = (text) => {
                return text
                    .replace(/<br\s*\/?>/gi, '\n')  // <br> or <br /> -> newline
                    .replace(/\s+/g, ' ')            // Multiple spaces -> single space
                    .trim();
            };

            const normalizedOriginal = normalizeText(trimmedOriginal);
            const normalizedTranslation = normalizeText(trimmedTranslation);

            // LINGUA v3.7 diagnostics: String replacement details
            window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: applyStringTranslationToPage()');
            window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Original:', trimmedOriginal.substring(0, 80));
            window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Translation:', trimmedTranslation.substring(0, 80));
            window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Normalized original:', normalizedOriginal.substring(0, 80));

            if (!trimmedOriginal || !trimmedTranslation || trimmedOriginal === trimmedTranslation) {
                window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Skipping - invalid or identical strings');
                return;
            }

            // v3.7: Create unique hash for tracking this translation
            const textHash = this.simpleHash(trimmedOriginal);
            window.linguaDebug('🔍 LINGUA v3.7 TRACKING: Text hash for original "' + trimmedOriginal.substring(0, 30) + '...":', textHash);

            // v3.7: STEP 1 - Check if we already translated this text before (RE-EDIT scenario)
            const $existingElements = $(`[data-lingua-translated="${textHash}"]`).not('#lingua-translation-modal *');
            window.linguaDebug(`🔍 LINGUA v3.7 TRACKING: Looking for elements with hash "${textHash}"...`);
            window.linguaDebug(`🔍 LINGUA v3.7 TRACKING: Found ${$existingElements.length} previously translated elements`);

            if ($existingElements.length > 0) {
                window.linguaDebug(`🔄 LINGUA v3.7 RE-EDIT: Will update ${$existingElements.length} previously translated elements`);
                window.linguaDebug(`🔄 LINGUA v3.7 RE-EDIT: Current text on page:`, $existingElements.first().text().substring(0, 50));
                window.linguaDebug(`🔄 LINGUA v3.7 RE-EDIT: New translation:`, trimmedTranslation.substring(0, 50));

                $existingElements.each(function() {
                    const $el = $(this);
                    const elType = $el.attr('data-lingua-type');

                    switch(elType) {
                        case 'textNode':
                            // Update text node parent
                            if (trimmedTranslation.includes('<br')) {
                                $el.html(trimmedTranslation);
                            } else {
                                $el.text(trimmedTranslation);
                            }
                            break;
                        case 'htmlElement':
                            $el.html(trimmedTranslation);
                            break;
                        case 'label':
                            if (trimmedTranslation.includes('<br')) {
                                $el.html(trimmedTranslation);
                            } else {
                                $el.text(trimmedTranslation);
                            }
                            break;
                        case 'option':
                            $el.text(trimmedTranslation.replace(/<br\s*\/?>/gi, ' '));
                            break;
                        case 'attribute':
                            const attrName = $el.attr('data-lingua-attr');
                            $el.attr(attrName, trimmedTranslation.replace(/<br\s*\/?>/gi, ' '));
                            break;
                        case 'input':
                            $el.attr('value', trimmedTranslation.replace(/<br\s*\/?>/gi, ' '));
                            break;
                    }

                    window.linguaDebug('✅ LINGUA v3.7 RE-EDIT: Updated', elType, 'element');
                });

                window.linguaDebug(`✅ LINGUA v3.7 RE-EDIT: Applied ${$existingElements.length} updates to previously translated elements`);
                return; // Done - no need to search for original text
            }

            // v3.7: STEP 2 - First time translating this text - search for it and mark elements
            window.linguaDebug('🆕 LINGUA v3.7 FIRST-TIME: Searching for original text on page...');

            let replacementCount = 0;

            // v3.7: IMPROVED - Find elements containing the text (not just text nodes)
            // Strategy 1: Find text nodes with exact match (for simple strings)
            const $textNodes = $('body *')
                .contents()
                .filter(function() {
                    if (this.nodeType !== 3) return false; // Text nodes only
                    if ($(this).closest('#lingua-translation-modal').length) return false; // Exclude modal

                    const nodeText = this.textContent.trim();
                    return nodeText === trimmedOriginal || normalizeText(nodeText) === normalizedOriginal;
                });

            window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Found text nodes matching original:', $textNodes.length);

            $textNodes.each(function() {
                window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Replacing text node');
                const $parent = $(this.parentElement);

                // v3.7: If translation has <br>, replace parent element's HTML instead of just text
                if (trimmedTranslation.includes('<br')) {
                    $parent.html(trimmedTranslation);
                } else {
                    this.textContent = trimmedTranslation;
                }

                // v3.7: Mark parent element for re-editing
                $parent.attr('data-lingua-translated', textHash);
                $parent.attr('data-lingua-type', 'textNode');

                replacementCount++;
            });

            // v3.7: Strategy 2: Find elements whose innerHTML matches (for HTML content with <br>)
            if (trimmedOriginal.includes('<br')) {
                window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Searching for HTML elements with <br> tags...');

                // Find all paragraph-like elements
                const $candidates = $('p, div, span, li, td, th, label, button, a')
                    .not('#lingua-translation-modal *');

                $candidates.each(function() {
                    const $el = $(this);
                    const elementHtml = $el.html();
                    if (!elementHtml) return;

                    // Normalize both for comparison
                    const normalizedElement = normalizeText(elementHtml);

                    if (normalizedElement === normalizedOriginal) {
                        window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Found matching HTML element:', $el.prop('tagName'));
                        $el.html(trimmedTranslation);

                        // v3.7: Mark element for re-editing
                        $el.attr('data-lingua-translated', textHash);
                        $el.attr('data-lingua-type', 'htmlElement');

                        replacementCount++;
                    }
                });
            }

            // v3.7: Strategy 3: Handle form labels and options (where text is hard to find)
            const $labels = $('label').not('#lingua-translation-modal *').filter(function() {
                return normalizeText($(this).text()) === normalizedOriginal;
            });

            window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Found labels matching original:', $labels.length);
            $labels.each(function() {
                const $el = $(this);
                window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Replacing label text');
                if (trimmedTranslation.includes('<br')) {
                    $el.html(trimmedTranslation);
                } else {
                    $el.text(trimmedTranslation);
                }

                // v3.7: Mark element for re-editing
                $el.attr('data-lingua-translated', textHash);
                $el.attr('data-lingua-type', 'label');

                replacementCount++;
            });

            // v3.7: Strategy 4: Handle select options
            const $options = $('option').not('#lingua-translation-modal *').filter(function() {
                return normalizeText($(this).text()) === normalizedOriginal;
            });

            window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Found options matching original:', $options.length);
            $options.each(function() {
                const $el = $(this);
                window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Replacing option text');
                $el.text(trimmedTranslation.replace(/<br\s*\/?>/gi, ' ')); // Options can't have HTML

                // v3.7: Mark element for re-editing
                $el.attr('data-lingua-translated', textHash);
                $el.attr('data-lingua-type', 'option');

                replacementCount++;
            });

            // Handle common attributes that might contain translatable text (excluding modal)
            const attributesToCheck = ['title', 'alt', 'placeholder', 'aria-label', 'data-title'];
            attributesToCheck.forEach(attr => {
                const $elements = $(`[${attr}="${trimmedOriginal}"]`).not('#lingua-translation-modal *');
                if ($elements.length > 0) {
                    window.linguaDebug(`🔍 LINGUA v3.7 LIVE PREVIEW: Found ${$elements.length} elements with ${attr}="${trimmedOriginal.substring(0, 30)}"`);
                    // Attributes can't have HTML, so strip <br> tags
                    $elements.attr(attr, trimmedTranslation.replace(/<br\s*\/?>/gi, ' '));

                    // v3.7: Mark elements for re-editing
                    $elements.each(function() {
                        $(this).attr('data-lingua-translated', textHash);
                        $(this).attr('data-lingua-type', 'attribute');
                        $(this).attr('data-lingua-attr', attr); // Remember which attribute
                    });

                    replacementCount += $elements.length;
                }
            });

            // Handle input values (excluding modal inputs)
            const $inputs = $(`input[value="${trimmedOriginal}"]`).not('#lingua-translation-modal input');
            if ($inputs.length > 0) {
                window.linguaDebug('🔍 LINGUA v3.7 LIVE PREVIEW: Found inputs with matching value:', $inputs.length);
                $inputs.attr('value', trimmedTranslation.replace(/<br\s*\/?>/gi, ' '));

                // v3.7: Mark elements for re-editing
                $inputs.each(function() {
                    $(this).attr('data-lingua-translated', textHash);
                    $(this).attr('data-lingua-type', 'input');
                });

                replacementCount += $inputs.length;
            }

            window.linguaDebug(`✅ LINGUA v3.7 LIVE PREVIEW: Applied ${replacementCount} replacements to NEW elements (first-time translation)`);
        },

        /**
         * Apply meta field translation
         */
        applyMetaFieldTranslation: function(metaKey, translatedText) {
            // Update custom meta fields based on common patterns
            $(`[data-meta="${metaKey}"]`).text(translatedText);
            $(`.meta-${metaKey}`).text(translatedText);

            window.linguaDebug('Applied meta field translation:', metaKey, translatedText);
        },

        /**
         * v3.7: Restore translation markers for already translated elements
         * Called after modal is populated to mark elements that were translated in previous sessions
         */
        restoreTranslationMarkers: function(data) {
            window.linguaDebug('🔄 LINGUA v3.7: Restoring translation markers for already translated content');

            let markedCount = 0;
            const self = this;

            // Process all field groups
            const fieldGroups = ['content_blocks', 'page_strings', 'attributes', 'seo_fields'];

            fieldGroups.forEach(function(groupName) {
                const items = data[groupName];
                if (!items || items.length === 0) return;

                items.forEach(function(item) {
                    const original = item.original || item.text || '';
                    const translated = item.translated || '';

                    // Skip if no translation exists yet
                    if (!original || !translated || original === translated) {
                        return;
                    }

                    // Create hash from original text
                    const textHash = self.simpleHash(original.trim());

                    // Try to find the translated text on the page and mark it
                    const normalizeText = function(text) {
                        return text
                            .replace(/<br\s*\/?>/gi, '\n')
                            .replace(/\s+/g, ' ')
                            .trim();
                    };

                    const normalizedTranslated = normalizeText(translated);

                    // Strategy 1: Find LEAF elements containing the translation (avoid marking parent containers)
                    // Only mark elements that don't have child elements (to avoid destroying nested structures)
                    $('body *').not('#lingua-translation-modal *').each(function() {
                        if ($(this).attr('data-lingua-translated')) {
                            return; // Already marked
                        }

                        const $el = $(this);

                        // CRITICAL: Only mark leaf elements without child elements
                        // This prevents marking parent <div> that contains <select>, <button>, etc.
                        if ($el.children().length > 0) {
                            return; // Skip elements with children
                        }

                        const elText = $el.text().trim();

                        if (elText && normalizeText(elText) === normalizedTranslated) {
                            $el.attr('data-lingua-translated', textHash);
                            $el.attr('data-lingua-type', 'textNode');
                            markedCount++;
                            window.linguaDebug('✓ Marked LEAF element:', $el.prop('tagName'), 'with hash:', textHash);
                            return false; // Stop after first match for this text
                        }
                    });

                    // Strategy 2: Find elements with matching HTML (for <br> tags)
                    // CRITICAL: Also check for leaf elements only to avoid destroying parent structures
                    if (translated.includes('<br')) {
                        $('p, span, li, td, th, label').not('#lingua-translation-modal *').each(function() {
                            if ($(this).attr('data-lingua-translated')) {
                                return; // Already marked
                            }

                            const $el = $(this);

                            // For HTML elements with <br>, we can be a bit more lenient,
                            // but still avoid elements with complex children (like forms, selects, etc.)
                            const hasComplexChildren = $el.find('select, button, input, form').length > 0;
                            if (hasComplexChildren) {
                                return; // Skip elements with form controls inside
                            }

                            const elHtml = $el.html();

                            if (elHtml && normalizeText(elHtml) === normalizedTranslated) {
                                $el.attr('data-lingua-translated', textHash);
                                $el.attr('data-lingua-type', 'htmlElement');
                                markedCount++;
                                window.linguaDebug('✓ Marked HTML element:', $el.prop('tagName'), 'with hash:', textHash);
                                return false; // Stop after first match
                            }
                        });
                    }
                });
            });

            window.linguaDebug(`✅ LINGUA v3.7: Restored markers for ${markedCount} already translated elements`);
        },

        /**
         * v3.7: Simple hash function for tracking translated elements
         */
        simpleHash: function(str) {
            let hash = 0;
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash; // Convert to 32bit integer
            }
            return 'lingua_' + Math.abs(hash).toString(36);
        }

    };

    // Add new methods for modern architecture
    window.translationModal.renderModernStructure = function(data) {
        window.linguaDebug('🎨 LINGUA v3.2: Rendering tabbed structure with SEO');
        window.linguaDebug('🔍 LINGUA v5.0.5 DEBUG: data.seo_fields =', data.seo_fields);
        window.linguaDebug('🔍 LINGUA v5.0.5 DEBUG: seo_fields count =', data.seo_fields ? data.seo_fields.length : 0);
        if (data.seo_fields && data.seo_fields.length > 0) {
            window.linguaDebug('🔍 LINGUA v5.0.5 DEBUG: First SEO field:', data.seo_fields[0]);
        }

        // v3.2: Render SEO fields in SEO tab
        if (data.seo_fields && data.seo_fields.length > 0) {
            this.renderSEOFields(data.seo_fields);
            $('#seo-count').text(data.seo_fields.length);
        } else {
            console.warn('⚠️ LINGUA v5.0.5: No SEO fields in response!');
            $('#lingua-seo-content').html('<div class="lingua-empty-state"><div class="lingua-empty-state-icon">🔍</div><div class="lingua-empty-state-text">No SEO fields found</div></div>');
            $('#seo-count').text(0);
        }

        // v5.0.12: Render Page Content in HTML order (unified array)
        var $container = $('#lingua-unified-content');
        var allItems = [];
        var globalIndex = 0;

        // v5.2.43: Group plural pairs before rendering
        window.linguaDebug('🔢 LINGUA v5.2.44 DEBUG: Original content_blocks:', data.content_blocks);

        // v5.2.44: Check if plural_pair exists in data
        var hasPlural = false;
        if (data.content_blocks) {
            data.content_blocks.forEach(function(item, i) {
                if (item.plural_pair) {
                    window.linguaDebug('✅ Found plural_pair in item #' + i + ':', item.original, '→', item.plural_pair);
                    hasPlural = true;
                }
            });
        }
        if (!hasPlural) {
            console.warn('❌ NO plural_pair found in any content_blocks! Check PHP response.');
        }

        var groupedContentBlocks = window.translationModal.groupPluralPairs(data.content_blocks || []);
        window.linguaDebug('🔢 LINGUA v5.2.44 DEBUG: Grouped content_blocks:', groupedContentBlocks);

        // Collect all items with their type and render immediately to preserve order
        if (groupedContentBlocks && groupedContentBlocks.length > 0) {
            groupedContentBlocks.forEach(function(item) {
                // v5.2.43: Check if this is a grouped plural pair
                if (item.is_plural_group && item.singular && item.plural) {
                    var $item = window.translationModal.createPluralGroupCard(item, globalIndex);
                    $container.append($item);
                    globalIndex++;
                } else {
                    var $item = window.translationModal.createModernTranslationItem(item, globalIndex);
                    $container.append($item);
                    globalIndex++;
                }
            });
        }

        if (data.page_strings && data.page_strings.length > 0) {
            data.page_strings.forEach(function(item) {
                var $item = window.translationModal.createModernTranslationItem(item, globalIndex);
                $container.append($item);
                globalIndex++;
            });
        }

        if (data.attributes && data.attributes.length > 0) {
            data.attributes.forEach(function(item) {
                var $item = window.translationModal.createModernTranslationItem(item, globalIndex);
                $container.append($item);
                globalIndex++;
            });
        }

        // Update content tab count
        $('#content-count').text(globalIndex);

        // Add empty state if no content
        if (globalIndex === 0) {
            $container.html('<div class="lingua-empty-state"><div class="lingua-empty-state-icon">📄</div><div class="lingua-empty-state-text">No page content found</div></div>');
        } else {
            window.linguaDebug('✅ LINGUA v5.0.12: Rendered', globalIndex, 'items in HTML order');
        }

        // v5.1: Media tab - render images with src, alt, title
        var mediaItems = data.media || [];
        $('#media-count').text(mediaItems.length);

        if (mediaItems.length === 0) {
            $('#lingua-media-content').html('<div class="lingua-empty-state"><div class="lingua-empty-state-icon">🖼️</div><div class="lingua-empty-state-text">No images found</div><div class="lingua-empty-state-description">This page doesn\'t contain any images to translate</div></div>');
        } else {
            window.translationModal.renderMediaTab(mediaItems);
        }
    };

    window.translationModal.renderContentSection = function(items, title, emoji, $container) {
        var $section = $('<div class="lingua-content-section"></div>');
        var $header = $('<div class="lingua-section-header">' + emoji + ' ' + title + ' (' + items.length + ')</div>');
        $section.append($header);

        var self = this;
        var addedItems = 0;
        items.forEach(function(item, index) {
            var originalText = item.original || item.original_text || '';
            // DEBUG: Log what exactly comes in
            if (index < 3) { // Log only first 3 items to save space
                window.linguaDebug('🔍 LINGUA DEBUG item ' + index + ':', JSON.stringify({
                    original: item.original,
                    original_text: item.original_text,
                    finalText: originalText,
                    length: originalText ? originalText.length : 0,
                    fullItem: item
                }, null, 2));
            }

            // CRITICAL VALIDATION: Check that text is not empty and meaningful
            if (originalText && originalText.trim().length > 1) { // Lowered from 2 to 1
                var $item = self.createModernTranslationItem(item, index);
                $section.append($item);
                addedItems++;
            } else {
                if (index < 5) { // Log only first 5 skipped items
                    window.linguaDebug('🔍 LINGUA v3.0: Skipped empty item:', item);
                }
            }
        });

        // Update header with actual item count
        $header.text($header.text().replace(/\(\d+\)/, '(' + addedItems + ')'));

        $container.append($section);
    };

    /**
     * v3.2: Render SEO fields (title + meta description) in SEO tab
     */
    window.translationModal.renderSEOFields = function(seoFields) {
        window.linguaDebug('🔍 LINGUA v3.2: renderSEOFields called with', seoFields);
        window.linguaDebug('🔍 LINGUA v3.2: seoFields type:', typeof seoFields);
        window.linguaDebug('🔍 LINGUA v3.2: seoFields length:', seoFields ? seoFields.length : 'null');

        if (seoFields && seoFields.length > 0) {
            seoFields.forEach((field, index) => {
                window.linguaDebug(`🔍 LINGUA v3.2: SEO field [${index}]:`, {
                    type: field.type,
                    original: field.original ? field.original.substring(0, 50) : 'empty',
                    hasTranslation: !!field.translated
                });
            });
        }

        var $container = $('#lingua-seo-content');
        $container.empty();

        if (!seoFields || seoFields.length === 0) {
            $container.html('<div class="lingua-empty-state"><div class="lingua-empty-state-icon">🔍</div><div class="lingua-empty-state-text">No SEO fields found</div></div>');
            return;
        }

        // Separate fields by type
        // v3.6: Look for seo_title or og_title
        // v5.0.5: Also check for og_title as fallback for taxonomy pages
        var seoTitle = seoFields.find(f => f.type === 'seo_title' || f.type === 'og_title');
        var metaDesc = seoFields.find(f => f.type === 'meta_description' || f.type === 'og_description');

        // Store OG tags for auto-sync (don't render in UI)
        // v5.0.5: Don't include og_title/og_description if they're the only SEO fields (taxonomy case)
        this.ogTags = seoFields.filter(f =>
            (f.type === 'og_title' && seoTitle && seoTitle.type !== 'og_title') ||
            (f.type === 'og_description' && metaDesc && metaDesc.type !== 'og_description') ||
            f.type.startsWith('twitter:')
        );

        window.linguaDebug('🔍 LINGUA v3.2: Found', this.ogTags.length, 'OG tags to auto-sync');

        // Render SEO Title field (from Yoast/RankMath)
        // v5.3.35: Use localized strings
        var strings = lingua_admin.strings || {};
        if (seoTitle) {
            var $titleField = this.createSEOField(
                strings.seo_title || 'SEO Title',
                strings.seo_title_desc || 'SEO title from Yoast/RankMath (shown in search engine results)',
                seoTitle.original || seoTitle.original_text || '',
                seoTitle.translated || seoTitle.translated_text || '',
                'seo_title',
                seoTitle.context || 'meta_information',
                60 // recommended max length
            );
            $container.append($titleField);
        }

        // Render Meta Description field
        if (metaDesc) {
            var $metaField = this.createSEOField(
                strings.seo_description || 'Meta Description',
                strings.seo_description_desc || 'Brief description of the page shown in search results',
                metaDesc.original || metaDesc.original_text || '',
                metaDesc.translated || metaDesc.translated_text || '',
                'meta_description',
                metaDesc.context || 'meta_information',
                160 // recommended max length
            );
            $container.append($metaField);
        }

        // v5.2.137: Add separator for OG fields
        // v5.3.35: Use localized strings
        $container.append('<div class="lingua-seo-separator"><span>' + (strings.open_graph_social || 'Open Graph (Social Media)') + '</span></div>');

        // v5.2.137: Render OG Title field
        var ogTitle = seoFields.find(f => f.type === 'og_title');
        if (ogTitle) {
            var $ogTitleField = this.createSEOField(
                strings.og_title || 'OG Title',
                strings.og_title_desc || 'Title shown when shared on Facebook, LinkedIn, etc.',
                ogTitle.original || ogTitle.original_text || '',
                ogTitle.translated || ogTitle.translated_text || '',
                'og_title',
                ogTitle.context || 'meta_information',
                60
            );
            $container.append($ogTitleField);
        } else if (seoTitle) {
            // Create empty OG Title field with hint to use SEO Title
            var $ogTitleField = this.createSEOField(
                strings.og_title || 'OG Title',
                strings.og_title_desc_default || 'Title shown when shared on Facebook, LinkedIn, etc. (defaults to SEO Title if empty)',
                seoTitle.original || seoTitle.original_text || '',
                '',
                'og_title',
                'meta_information',
                60
            );
            $container.append($ogTitleField);
        }

        // v5.2.137: Render OG Description field
        var ogDesc = seoFields.find(f => f.type === 'og_description');
        if (ogDesc) {
            var $ogDescField = this.createSEOField(
                strings.og_description || 'OG Description',
                strings.og_description_desc || 'Description shown when shared on social media',
                ogDesc.original || ogDesc.original_text || '',
                ogDesc.translated || ogDesc.translated_text || '',
                'og_description',
                ogDesc.context || 'meta_information',
                200
            );
            $container.append($ogDescField);
        } else if (metaDesc) {
            // Create empty OG Description field with hint to use Meta Description
            var $ogDescField = this.createSEOField(
                strings.og_description || 'OG Description',
                strings.og_description_desc_default || 'Description shown when shared on social media (defaults to Meta Description if empty)',
                metaDesc.original || metaDesc.original_text || '',
                '',
                'og_description',
                'meta_information',
                200
            );
            $container.append($ogDescField);
        }

        // v5.2.137: Clear ogTags since we now render them directly
        this.ogTags = [];

        window.linguaDebug('✅ LINGUA v3.2: SEO fields rendered');
    };

    /**
     * v3.2: Create a single SEO field with original + translation + character counter
     * v5.3.35: Use localized strings
     */
    window.translationModal.createSEOField = function(label, description, originalText, translatedText, type, context, maxLength) {
        var strings = lingua_admin.strings || {};
        var translatedLength = translatedText.length;
        var lengthClass = '';
        var lengthStatus = '';

        if (maxLength) {
            if (translatedLength > maxLength) {
                lengthClass = 'error';
                lengthStatus = '⚠️ Too long';
            } else if (translatedLength > maxLength * 0.9) {
                lengthClass = 'warning';
                lengthStatus = '⚠️ Near limit';
            } else {
                lengthClass = 'success';
                lengthStatus = '✓ Optimal';
            }
        }

        var $field = $(`
            <div class="lingua-seo-field" data-type="${type}" data-context="${context}">
                <div class="lingua-seo-field-header">
                    <h5 class="lingua-seo-field-title">${label}</h5>
                    <p class="lingua-seo-field-description">${description}</p>
                </div>

                <div class="lingua-seo-field-original">
                    <label class="lingua-seo-label">
                        <span class="lingua-lang-badge">${strings.original || 'Original'}</span>
                    </label>
                    <div class="lingua-original-text lingua-original-block">${this.escapeHtml(originalText)}</div>
                </div>

                <div class="lingua-seo-field-translation">
                    <label class="lingua-seo-label">
                        <span class="lingua-lang-badge">${strings.translation || 'Translation'}</span>
                        ${maxLength ? `<span class="lingua-char-counter ${lengthClass}">
                            <span class="lingua-char-count">${translatedLength}</span>/<span class="lingua-char-max">${maxLength}</span>
                            <span class="lingua-char-status">${lengthStatus}</span>
                        </span>` : ''}
                    </label>
                    <textarea class="lingua-translated-text" placeholder="${strings.enter_translation || 'Enter translation...'}" rows="3" data-max-length="${maxLength || ''}">${this.escapeHtml(translatedText)}</textarea>
                </div>
            </div>
        `);

        // Add character counter update on input
        if (maxLength) {
            $field.find('.lingua-translated-text').on('input', function() {
                var length = $(this).val().length;
                var $counter = $field.find('.lingua-char-counter');
                var $count = $counter.find('.lingua-char-count');
                var $status = $counter.find('.lingua-char-status');

                $count.text(length);

                // Update status
                $counter.removeClass('success warning error');
                if (length > maxLength) {
                    $counter.addClass('error');
                    $status.text('⚠️ Too long');
                } else if (length > maxLength * 0.9) {
                    $counter.addClass('warning');
                    $status.text('⚠️ Near limit');
                } else {
                    $counter.addClass('success');
                    $status.text('✓ Optimal');
                }
            });
        }

        return $field;
    };

    window.translationModal.createModernTranslationItem = function(item, index) {
        var originalText = item.original || item.original_text || '';
        var translatedText = item.translated || item.translated_text || '';
        var context = item.context || 'general';
        var englishOriginal = item.english_original || '';  // v5.2.39: English gettext hint
        var source = item.source || 'custom';  // v5.2.42: Source (gettext or custom)
        var gettextDomain = item.gettext_domain || '';  // v5.2.42: Gettext domain

        // v5.0.11: Use same structure as SEO fields for consistent styling
        // v5.2.43: Removed English gettext badge (no longer needed)

        // v5.2.42: Add source badge for gettext strings
        var sourceBadge = '';
        if (source === 'gettext' && gettextDomain) {
            sourceBadge = `<span class="lingua-source-badge gettext" title="Gettext from ${this.escapeHtml(gettextDomain)}">📦 Gettext: ${this.escapeHtml(gettextDomain)}</span>`;
        }

        var $item = $(`
            <div class="lingua-translation-item" data-index="${index}" data-source="${this.escapeHtml(source)}" data-gettext-domain="${this.escapeHtml(gettextDomain)}" data-english-original="${this.escapeHtml(englishOriginal)}">
                <div class="lingua-original">
                    <label class="lingua-content-label">
                        <span class="lingua-lang-badge">Original</span>
                        <span class="lingua-block-number">#${index + 1}</span>
                        ${sourceBadge}
                    </label>
                    <div class="lingua-original-text">${this.escapeHtml(originalText)}</div>
                </div>
                <div class="lingua-translated">
                    <label class="lingua-content-label">
                        <span class="lingua-lang-badge">Translation</span>
                        <span class="lingua-block-number">#${index + 1}</span>
                    </label>
                    <textarea class="lingua-translated-text" placeholder="Enter translation..." rows="3">${this.escapeHtml(translatedText)}</textarea>
                </div>
            </div>
        `);

        return $item;
    };

    window.translationModal.escapeHtml = function(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    };

    /**
     * v5.2.43: Group plural pairs together
     * Takes array of items and groups items that have plural_pair relationships
     * Returns array where plural pairs are combined into single group objects
     */
    window.translationModal.groupPluralPairs = function(items) {
        if (!items || items.length === 0) return [];

        var grouped = [];
        var processedIndices = new Set();

        window.linguaDebug('🔢 LINGUA v5.2.44 groupPluralPairs: Processing ' + items.length + ' items');

        items.forEach(function(item, index) {
            // Skip if already processed
            if (processedIndices.has(index)) return;

            // Debug: log item structure
            var originalText = item.original || item.original_text || '';
            window.linguaDebug('🔢 Item #' + index + ':', {
                original: originalText,
                plural_pair: item.plural_pair,
                is_plural: item.is_plural,
                hasProperty: item.hasOwnProperty('plural_pair')
            });

            // Check if this item has a plural_pair
            var pluralPair = item.plural_pair;
            if (!pluralPair) {
                // No plural pair, add as-is
                window.linguaDebug('  ➡️ No plural_pair, adding as regular item');
                grouped.push(item);
                processedIndices.add(index);
                return;
            }

            window.linguaDebug('  🔍 Has plural_pair: ' + pluralPair + ', searching for match...');

            // Find the matching pair in remaining items
            var pairIndex = -1;
            for (var i = index + 1; i < items.length; i++) {
                if (processedIndices.has(i)) continue;

                var otherItem = items[i];
                var otherOriginal = otherItem.original || otherItem.original_text || '';

                // Check if other item's original matches our plural_pair
                if (otherOriginal === pluralPair) {
                    pairIndex = i;
                    break;
                }
            }

            if (pairIndex !== -1) {
                // Found pair! Create grouped object
                var currentOriginal = item.original || item.original_text || '';
                var pairItem = items[pairIndex];

                // v5.2.93: DEBUG - Log pairing logic
                window.linguaDebug('  🔍 v5.2.93 DEBUG pairing:');
                window.linguaDebug('    - item (index ' + index + '): original="' + (item.original || item.original_text) + '", is_plural=' + item.is_plural + ', plural_pair="' + item.plural_pair + '"');
                window.linguaDebug('    - pairItem (index ' + pairIndex + '): original="' + (pairItem.original || pairItem.original_text) + '", is_plural=' + pairItem.is_plural + ', plural_pair="' + pairItem.plural_pair + '"');

                // v5.2.95: CRITICAL FIX - Determine singular/plural by string length instead of is_plural flag
                // The is_plural flag is unreliable (both items may have is_plural=true in some cases)
                // Use heuristic: shorter string is usually singular (e.g., "product" vs "products")
                var itemText = (item.original || item.original_text || '');
                var pairText = (pairItem.original || pairItem.original_text || '');

                var singular = itemText.length <= pairText.length ? item : pairItem;
                var plural = itemText.length <= pairText.length ? pairItem : item;

                window.linguaDebug('    - v5.2.95 FIX: Using string length heuristic');
                window.linguaDebug('    - RESULT: singular="' + (singular.original || singular.original_text) + '" (length=' + (singular.original || singular.original_text).length + ')');
                window.linguaDebug('    - RESULT: plural="' + (plural.original || plural.original_text) + '" (length=' + (plural.original || plural.original_text).length + ')');

                grouped.push({
                    is_plural_group: true,
                    singular: singular,
                    plural: plural,
                    source: item.source || 'custom',
                    gettext_domain: item.gettext_domain || ''
                });

                processedIndices.add(index);
                processedIndices.add(pairIndex);
            } else {
                // No pair found, add as single item
                grouped.push(item);
                processedIndices.add(index);
            }
        });

        window.linguaDebug('🔢 LINGUA v5.2.43: Grouped ' + items.length + ' items into ' + grouped.length + ' items (' + processedIndices.size + ' processed)');
        return grouped;
    };

    /**
     * v5.2.43: Create a special card for plural groups showing both singular and plural forms
     * v5.2.50: Added Default (Russian) section for editing .po file msgstr forms
     * v5.2.54: Added support for 3 plural forms when translating TO Russian (target_plural_forms)
     */
    window.translationModal.createPluralGroupCard = function(group, index) {
        var singularOriginal = group.singular.original || group.singular.original_text || '';
        var pluralOriginal = group.plural.original || group.plural.original_text || '';

        // v5.2.119: Load translations from all_plural_forms array (PHP loads ALL forms now)
        // This array contains ALL plural forms: [0] = form 0, [1] = form 1, [2] = form 2, etc.
        var allPluralForms = group.singular.all_plural_forms || group.plural.all_plural_forms || {};

        // Extract individual form translations with fallbacks to old structure
        var singularTranslated = allPluralForms[0] || group.singular.translated || group.singular.translated_text || '';
        var form1Translated = allPluralForms[1] || '';

        // For pluralTranslated: Use form 1 for 2-form languages (Italian), form 2 for 3-form languages (Russian)
        // We'll determine this dynamically based on targetPluralFormsCount later
        var pluralTranslated = group.plural.translated || group.plural.translated_text || '';

        var source = group.source || 'custom';
        var gettextDomain = group.gettext_domain || '';

        // v5.2.92: DEBUG - Log what we got from PHP
        window.linguaDebug('🔍 LINGUA v5.2.92 DEBUG createPluralGroupCard - RAW group object:', group);
        window.linguaDebug('🔍 LINGUA v5.2.92 DEBUG createPluralGroupCard - group.singular:', group.singular);
        window.linguaDebug('🔍 LINGUA v5.2.92 DEBUG createPluralGroupCard - group.plural:', group.plural);
        window.linguaDebug('🔍 LINGUA v5.2.119 DEBUG all_plural_forms array:', allPluralForms);
        window.linguaDebug('🔍 LINGUA v5.2.92 DEBUG createPluralGroupCard - EXTRACTED:', {
            singularOriginal: singularOriginal,
            pluralOriginal: pluralOriginal,
            singularTranslated: singularTranslated,
            form1Translated: form1Translated,
            pluralTranslated: pluralTranslated
        });

        // v5.2.91: CRITICAL FIX - plural forms count (programmatic)
        var targetLanguage = $('#lingua-target-lang').val();
        var hasTargetPlurals = group.singular.has_target_plurals || group.plural.has_target_plurals || false;
        var targetPluralFormsCount = group.singular.target_plural_forms_count || group.plural.target_plural_forms_count || 0;

        // v5.2.119: Update pluralTranslated to use correct form index based on language
        // Italian (nplurals=2): form 1 is the last form
        // Russian (nplurals=3): form 2 is the last form
        if (targetPluralFormsCount === 2) {
            pluralTranslated = allPluralForms[1] || pluralTranslated;
        } else if (targetPluralFormsCount === 3) {
            pluralTranslated = allPluralForms[2] || pluralTranslated;
        } else if (targetPluralFormsCount > 3) {
            pluralTranslated = allPluralForms[targetPluralFormsCount - 1] || pluralTranslated;
        }

        // v5.2.42: Add source badge for gettext strings
        var sourceBadge = '';
        if (source === 'gettext' && gettextDomain) {
            sourceBadge = `<span class="lingua-source-badge gettext" title="Gettext from ${this.escapeHtml(gettextDomain)}">📦 Gettext: ${this.escapeHtml(gettextDomain)}</span>`;
        }

        // v5.2.102: Build translation fields dynamically based on targetPluralFormsCount
        var translationFieldsHTML = '';

        // v5.2.102: DEBUG - Log plural forms count
        window.linguaDebug('🔢 LINGUA v5.2.102: targetLanguage=' + targetLanguage + ', hasTargetPlurals=' + hasTargetPlurals + ', targetPluralFormsCount=' + targetPluralFormsCount);

        // v5.2.196: Plural form example numbers like Loco Translate
        var pluralExamples = {
            'ru': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
            'uk': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
            'be': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
            'sr': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
            'hr': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
            'bs': ['1, 21, 31', '2, 3, 4', '0, 5, 6'],
            'pl': ['1', '2, 3, 4', '0, 5, 6'],
            'cs': ['1', '2, 3, 4', '0, 5, 6'],
            'sk': ['1', '2, 3, 4', '0, 5, 6'],
            'en': ['1', '0, 2, 3'],
            'de': ['1', '0, 2, 3'],
            'fr': ['0, 1', '2, 3, 4'],
            'es': ['1', '0, 2, 3'],
            'it': ['1', '0, 2, 3'],
            'sl': ['1, 101', '2, 102', '3, 4, 103', '0, 5, 6'],
            'ar': ['0', '1', '2', '3, 4, 5', '11, 12', '100, 101'],
            'ga': ['1', '2', '3, 4, 5', '7, 8, 9', '11, 12'],
            'zh': ['0, 1, 2'],
            'ja': ['0, 1, 2'],
            'ko': ['0, 1, 2'],
            'default': ['1', '0, 2', '3, 4', '5, 6', '11, 12', '100']
        };

        var getPluralLabel = function(lang, formIndex) {
            var examples = pluralExamples[lang] || pluralExamples['default'];
            return examples[formIndex] || formIndex;
        };

        if (hasTargetPlurals && targetPluralFormsCount > 0) {
            // v5.2.196: Dynamic plural forms with Loco-style example labels
            window.linguaDebug('✅ LINGUA v5.2.196: Rendering ' + targetPluralFormsCount + ' plural forms for ' + targetLanguage);

            for (var i = 0; i < targetPluralFormsCount; i++) {
                var formTranslated = allPluralForms[i] || '';
                // Special handling for specific forms
                if (i === 0) formTranslated = singularTranslated || formTranslated;
                if (i === 1 && targetPluralFormsCount === 3 && targetLanguage === 'ru') formTranslated = form1Translated || formTranslated;
                if (i === targetPluralFormsCount - 1 && targetPluralFormsCount > 1) formTranslated = pluralTranslated || formTranslated;

                var label = getPluralLabel(targetLanguage, i);
                translationFieldsHTML += `
                    <div class="lingua-plural-form">
                        <label class="lingua-plural-label">${label}</label>
                        <textarea class="lingua-translated-text lingua-plural-form-${i}" data-original="${this.escapeHtml(singularOriginal)}" ${i > 0 ? 'data-plural-original="' + this.escapeHtml(pluralOriginal) + '"' : ''} placeholder="Enter translation..." rows="2">${this.escapeHtml(formTranslated)}</textarea>
                    </div>
                `;
            }
        } else {
            // Fallback: Show 2 forms with Loco-style labels
            var label0 = getPluralLabel(targetLanguage, 0);
            var label1 = getPluralLabel(targetLanguage, 1);
            translationFieldsHTML = `
                <div class="lingua-plural-form">
                    <label class="lingua-plural-label">${label0}</label>
                    <textarea class="lingua-translated-text lingua-plural-singular" data-original="${this.escapeHtml(singularOriginal)}" placeholder="Enter translation..." rows="2">${this.escapeHtml(singularTranslated)}</textarea>
                </div>
                <div class="lingua-plural-form">
                    <label class="lingua-plural-label">${label1}</label>
                    <textarea class="lingua-translated-text lingua-plural-plural" data-original="${this.escapeHtml(pluralOriginal)}" placeholder="Enter translation..." rows="2">${this.escapeHtml(pluralTranslated)}</textarea>
                </div>
            `;
        }

        var $item = $(`
            <div class="lingua-translation-item lingua-plural-group" data-index="${index}" data-source="${this.escapeHtml(source)}" data-gettext-domain="${this.escapeHtml(gettextDomain)}" data-has-target-plurals="${hasTargetPlurals}" data-target-plural-forms-count="${targetPluralFormsCount}">
                <div class="lingua-original">
                    <label class="lingua-content-label">
                        <span class="lingua-lang-badge">Original (Plural Forms)</span>
                        <span class="lingua-block-number">#${index + 1}</span>
                        ${sourceBadge}
                    </label>
                    <div class="lingua-plural-forms">
                        <div class="lingua-plural-form">
                            <label class="lingua-plural-label">Singular:</label>
                            <div class="lingua-original-text lingua-plural-text">${this.escapeHtml(singularOriginal)}</div>
                        </div>
                        <div class="lingua-plural-form">
                            <label class="lingua-plural-label">Plural:</label>
                            <div class="lingua-original-text lingua-plural-text">${this.escapeHtml(pluralOriginal)}</div>
                        </div>
                    </div>
                </div>
                <div class="lingua-translated">
                    <label class="lingua-content-label">
                        <span class="lingua-lang-badge">Translation (Plural Forms)</span>
                        <span class="lingua-block-number">#${index + 1}</span>
                    </label>
                    <div class="lingua-plural-forms">
                        ${translationFieldsHTML}
                    </div>
                </div>
            </div>
        `);

        return $item;
    };

    // autoTranslateSingle removed (Pro feature removed)

    // translateModernItem function removed (auto-translate Pro feature removed)

    // v5.0.7: Auto-resize textarea helper function (only for translation fields)
    window.linguaAutoResizeTextarea = function(textarea) {
        if (!textarea) return;

        // Reset height to auto to get the correct scrollHeight
        textarea.style.height = 'auto';

        // Set height to scrollHeight (content height), min 60px, max 500px
        var newHeight = Math.min(Math.max(textarea.scrollHeight, 60), 500);
        textarea.style.height = newHeight + 'px';
    };

    // v5.0.7: Auto-resize ONLY translation textareas on input
    $(document).on('input', '.lingua-translated-text, .lingua-seo-field-translation textarea, textarea[data-role="translated"]', function() {
        window.linguaAutoResizeTextarea(this);
    });

    // v5.0.7: Auto-resize translation textareas after content is loaded
    window.translationModal.autoResizeAllTextareas = function() {
        $('.lingua-translated-text, .lingua-seo-field-translation textarea, textarea[data-role="translated"]').each(function() {
            window.linguaAutoResizeTextarea(this);
        });
    };

    /**
     * v5.1: Render Media tab with images grouped by src hash
     */
    window.translationModal.renderMediaTab = function(mediaItems) {
        window.linguaDebug('[LINGUA MEDIA v5.1] Rendering', mediaItems.length, 'media items');

        var $container = $('#lingua-media-content');
        $container.empty();

        // Group items by src_hash (each image has 1-3 items: src, alt, title)
        var imageGroups = {};

        mediaItems.forEach(function(item) {
            var srcHash = item.src_hash || '';
            if (!srcHash) return;

            if (!imageGroups[srcHash]) {
                imageGroups[srcHash] = {
                    src: null,
                    alt: null,
                    title: null
                };
            }

            // Assign item to correct attribute
            var attr = item.attribute || '';
            if (attr === 'src' || attr === 'alt' || attr === 'title') {
                imageGroups[srcHash][attr] = item;
            }
        });

        // Render each image group
        var imageIndex = 0;
        Object.keys(imageGroups).forEach(function(srcHash) {
            var group = imageGroups[srcHash];
            if (!group.src) return; // Skip if no src (shouldn't happen)

            var $imageCard = $('<div class="lingua-media-card" data-src-hash="' + srcHash + '"></div>');

            // Image preview
            var imageSrc = group.src.original || '';
            var $preview = $('<div class="lingua-media-preview"><img src="' + imageSrc + '" alt="" /></div>');
            $imageCard.append($preview);

            // Fields container
            var $fields = $('<div class="lingua-media-fields"></div>');

            // SRC field (image URL replacement)
            // v5.3.35: Use localized strings
            var strings = lingua_admin.strings || {};
            var srcOriginal = group.src.original || '';
            var srcTranslated = group.src.translated || '';
            $fields.append(
                '<div class="lingua-media-field">' +
                    '<label>Image URL (src):</label>' +
                    '<input type="text" class="lingua-media-original" value="' + window.translationModal.escapeHtml(srcOriginal) + '" readonly />' +
                    '<div class="lingua-media-input-wrapper">' +
                        '<input type="text" class="lingua-media-translated" data-attribute="src" value="' + window.translationModal.escapeHtml(srcTranslated) + '" placeholder="' + (strings.enter_url_or_add_media || 'Enter URL or use Add Media button') + '" />' +
                        '<button type="button" class="lingua-add-media-btn button button-primary" data-src-hash="' + srcHash + '">' + (strings.add_media || 'Add Media') + '</button>' +
                    '</div>' +
                '</div>'
            );

            // ALT field
            if (group.alt) {
                var altOriginal = group.alt.original || '';
                var altTranslated = group.alt.translated || '';
                $fields.append(
                    '<div class="lingua-media-field">' +
                        '<label>ALT text:</label>' +
                        '<input type="text" class="lingua-media-original" value="' + window.translationModal.escapeHtml(altOriginal) + '" readonly />' +
                        '<input type="text" class="lingua-media-translated" data-attribute="alt" value="' + window.translationModal.escapeHtml(altTranslated) + '" placeholder="Enter translated alt text" />' +
                    '</div>'
                );
            }

            // TITLE field
            if (group.title) {
                var titleOriginal = group.title.original || '';
                var titleTranslated = group.title.translated || '';
                $fields.append(
                    '<div class="lingua-media-field">' +
                        '<label>Title:</label>' +
                        '<input type="text" class="lingua-media-original" value="' + window.translationModal.escapeHtml(titleOriginal) + '" readonly />' +
                        '<input type="text" class="lingua-media-translated" data-attribute="title" value="' + window.translationModal.escapeHtml(titleTranslated) + '" placeholder="Enter translated title" />' +
                    '</div>'
                );
            }

            $imageCard.append($fields);

            // Save button (type="button" prevents form submission)
            // v5.3.35: Use localized string
            var saveBtnHtml = '<button type="button" class="lingua-button lingua-save-media-btn" data-src-hash="' + srcHash + '">' +
                '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>' +
                '<polyline points="17,21 17,13 7,13 7,21"/>' +
                '<polyline points="7,3 7,8 15,8"/>' +
                '</svg>' +
                (strings.save_media || 'Save Media') +
                '</button>';
            var $saveBtn = $(saveBtnHtml);
            $imageCard.append($saveBtn);

            $container.append($imageCard);
            imageIndex++;
        });

        // Attach save handler
        $('.lingua-save-media-btn').on('click', function(e) {
            window.linguaDebug('[LINGUA MEDIA v5.2] Save Media button clicked');
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            var srcHash = $(this).data('src-hash');
            window.translationModal.saveMediaTranslation(srcHash);
            return false;
        });

        // v5.2: Attach "Add Media" button handler (WordPress Media Library picker)
        $('.lingua-add-media-btn').on('click', function(e) {
            e.preventDefault();

            var srcHash = $(this).data('src-hash');
            window.translationModal.openMediaLibraryPicker(srcHash);
        });

        window.linguaDebug('[LINGUA MEDIA v5.1] Rendered', imageIndex, 'images');
    };

    /**
     * v5.2: Open WordPress Media Library picker for image selection
     */
    window.translationModal.openMediaLibraryPicker = function(srcHash) {
        window.linguaDebug('[LINGUA MEDIA v5.2] Opening media picker for hash:', srcHash);
        window.linguaDebug('[LINGUA MEDIA v5.2] wp available:', typeof wp);
        window.linguaDebug('[LINGUA MEDIA v5.2] wp.media available:', typeof wp !== 'undefined' ? typeof wp.media : 'wp not defined');

        // Check if wp.media is available
        if (typeof wp === 'undefined' || typeof wp.media === 'undefined') {
            console.error('[LINGUA MEDIA v5.2] WordPress Media Library not available');
            alert('WordPress Media Library is not available. Please reload the page and try again.');
            return;
        }

        window.linguaDebug('[LINGUA MEDIA v5.2] Creating media frame...');

        // Create media frame (or reuse existing one)
        var mediaFrame = wp.media({
            title: 'Select or Upload Translated Image',
            button: {
                text: 'Use this image'
            },
            multiple: false,  // Only allow single image selection
            library: {
                type: 'image'  // Only show images
            }
        });

        // When an image is selected, insert its URL into the input field
        mediaFrame.on('select', function() {
            var attachment = mediaFrame.state().get('selection').first().toJSON();
            window.linguaDebug('[LINGUA MEDIA v5.2] Image selected:', attachment);

            // Get the input field for this src_hash
            var $card = $('.lingua-media-card[data-src-hash="' + srcHash + '"]');
            var $srcInput = $card.find('.lingua-media-translated[data-attribute="src"]');

            // Insert the full-size image URL
            var imageUrl = attachment.url || attachment.sizes.full.url;
            $srcInput.val(imageUrl);

            // Optional: Auto-fill alt and title if they're empty
            var $altInput = $card.find('.lingua-media-translated[data-attribute="alt"]');
            var $titleInput = $card.find('.lingua-media-translated[data-attribute="title"]');

            if ($altInput.length && !$altInput.val() && attachment.alt) {
                $altInput.val(attachment.alt);
            }

            if ($titleInput.length && !$titleInput.val() && attachment.title) {
                $titleInput.val(attachment.title);
            }

            window.linguaDebug('[LINGUA MEDIA v5.2] Image URL inserted:', imageUrl);
        });

        // Open the media frame
        mediaFrame.open();
    };

    /**
     * v5.1: Save media translation for specific image
     */
    window.translationModal.saveMediaTranslation = function(srcHash) {
        var $card = $('.lingua-media-card[data-src-hash="' + srcHash + '"]');
        var translations = [];

        // Collect all translated fields for this image
        $card.find('.lingua-media-translated').each(function() {
            var $input = $(this);
            var attribute = $input.data('attribute');
            var translated = $input.val().trim();

            // v5.2 FIX: For 'src' field, original is in parent's sibling (because of wrapper div)
            var $original;
            if (attribute === 'src') {
                $original = $input.closest('.lingua-media-input-wrapper').prev('.lingua-media-original');
            } else {
                $original = $input.prev('.lingua-media-original');
            }

            var original = $original.val();

            if (original && translated) {
                translations.push({
                    id: 'media_' + srcHash + '_' + attribute,
                    original: original,
                    translated: translated,
                    context: 'media',
                    field_group: 'media',
                    type: 'media_' + attribute,
                    src_hash: srcHash,
                    attribute: attribute,
                    source: 'v5.2_media_tab'
                });
            }
        });

        if (translations.length === 0) {
            alert('Please enter at least one translation');
            return;
        }

        window.linguaDebug('[LINGUA MEDIA v5.1] Saving', translations.length, 'media translations');
        window.linguaDebug('[LINGUA MEDIA v5.1] Translations data:', translations);

        // Get current post and language context
        var currentPostId = window.translationModal.currentPostId;
        var currentLanguage = window.translationModal.currentLanguage;

        if (!currentPostId || !currentLanguage) {
            alert('Invalid state - post ID or language not set');
            return;
        }

        // Disable save button
        var $saveBtn = $('.lingua-save-media-btn[data-src-hash="' + srcHash + '"]');
        $saveBtn.prop('disabled', true).html(
            '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
            '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>' +
            '<polyline points="17,21 17,13 7,13 7,21"/>' +
            '<polyline points="7,3 7,8 15,8"/>' +
            '</svg>' +
            'Saving...'
        );

        // Format translations in the same way as collectModernTranslations()
        var formattedTranslations = {
            translation_strings: JSON.stringify(translations),
            statistics: {
                seo_fields: 0,
                content_blocks: 0,
                page_strings: 0,
                attributes: 0,
                media: translations.length
            },
            total_strings: translations.length,
            version: 'v5.2_media'
        };

        window.linguaDebug('[LINGUA MEDIA v5.2] Formatted translations:', formattedTranslations);

        // Send AJAX request
        $.ajax({
            url: lingua_admin.ajax_url,
            method: 'POST',
            data: {
                action: 'lingua_save_translation',
                nonce: lingua_admin.nonce,
                post_id: currentPostId,
                language: currentLanguage,
                translations: formattedTranslations,
                use_global_translation: 'true'
            },
            success: function(response) {
                window.linguaDebug('[LINGUA MEDIA v5.1] AJAX response:', response);
                if (response.success) {
                    window.linguaDebug('[LINGUA MEDIA v5.1] Save successful!');

                    // Remove any existing success messages
                    $saveBtn.siblings('.lingua-media-save-success').remove();

                    // Show success message next to button
                    var $successMsg = $('<span class="lingua-media-save-success">Media translation saved successfully</span>');
                    $saveBtn.after($successMsg);

                    // Mark fields as saved (green border)
                    $saveBtn.closest('.lingua-media-card').find('.lingua-media-translated').css('border-color', '#10b981');

                    // Auto-hide success message after 5 seconds
                    setTimeout(function() {
                        $successMsg.fadeOut(300, function() {
                            $(this).remove();
                        });
                    }, 5000);

                    // v5.2: Reload iframe preview to show updated media
                    setTimeout(function() {
                        window.translationModal.reloadPreviewIframe();
                    }, 400);
                } else {
                    console.error('[LINGUA MEDIA v5.1] Save failed:', response.data);
                    alert('Failed to save: ' + (response.data || 'Unknown error'));
                }
            },
            error: function(xhr, status, error) {
                console.error('[LINGUA MEDIA v5.1] AJAX error:', error);
                alert('AJAX error: ' + error);
            },
            complete: function() {
                $saveBtn.prop('disabled', false).html(
                    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                    '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>' +
                    '<polyline points="17,21 17,13 7,13 7,21"/>' +
                    '<polyline points="7,3 7,8 15,8"/>' +
                    '</svg>' +
                    'Save Media'
                );
            }
        });
    };

    /**
     * v5.2: Iframe Preview Functions
     */
    window.translationModal.createPreviewIframe = function() {
        if ($('#lingua-preview-iframe').length) {
            return; // Already exists
        }

        var $iframe = $('<iframe id="lingua-preview-iframe"></iframe>');
        var $loading = $('<div id="lingua-preview-loading">Loading preview...</div>');

        $('body').append($iframe);
        $('body').append($loading);

        window.linguaDebug('[LINGUA IFRAME v5.2] Preview iframe created');
    };

    window.translationModal.updatePreviewIframe = function(targetLanguage) {
        var currentUrl = window.location.pathname;
        // v5.2.62: Get default language from PHP settings
        var defaultLanguage = lingua_admin.default_language || 'ru';

        // v5.2.63: DEBUG logging
        window.linguaDebug('[LINGUA IFRAME v5.2.63 DEBUG] targetLanguage:', targetLanguage);
        window.linguaDebug('[LINGUA IFRAME v5.2.63 DEBUG] this.currentLanguage:', this.currentLanguage);
        window.linguaDebug('[LINGUA IFRAME v5.2.63 DEBUG] defaultLanguage:', defaultLanguage);

        // Build preview URL
        var previewUrl = this.buildLanguageUrl(currentUrl, targetLanguage || this.currentLanguage);

        // Add query parameter to hide admin bar and version for cache busting
        previewUrl += (previewUrl.indexOf('?') > -1 ? '&' : '?') + 'lingua_preview=1&v=52156';

        window.linguaDebug('[LINGUA IFRAME v5.2] Updating iframe to:', previewUrl);

        var $iframe = $('#lingua-preview-iframe');
        var $loading = $('#lingua-preview-loading');

        if ($iframe.length) {
            // Show loading
            $loading.addClass('visible');

            // Update iframe src
            $iframe.attr('src', previewUrl);

            // Hide loading when iframe loads
            $iframe.one('load', function() {
                $loading.removeClass('visible');
                window.linguaDebug('[LINGUA IFRAME v5.2.156] Preview loaded successfully');

                // v5.2.156: Nonce is already inherited by iframe, extract immediately
                window.linguaDebug('[Lingua v5.2.156] Nonce ready (inherited from parent): ' + (window.lingua_admin.nonce ? window.lingua_admin.nonce.substr(0, 10) + '...' : 'undefined'));
                window.linguaDebug('[Lingua v5.2.156] Triggering auto-extract NOW');

                if (window.translationModal && typeof window.translationModal.extractContent === 'function') {
                    window.translationModal.extractContent();
                } else {
                    console.error('[Lingua v5.2.156] ❌ translationModal.extractContent not found');
                }
            });
        }
    };

    window.translationModal.buildLanguageUrl = function(currentUrl, targetLang) {
        // v5.2.72: Get default language from PHP (no hardcode!)
        var defaultLanguage = window.lingua_admin && window.lingua_admin.default_language ? window.lingua_admin.default_language : 'en';

        window.linguaDebug('[LINGUA IFRAME v5.2.72] buildLanguageUrl input:', {currentUrl: currentUrl, targetLang: targetLang, defaultLanguage: defaultLanguage});

        // Ensure URL starts with /
        if (!currentUrl.startsWith('/')) {
            currentUrl = '/' + currentUrl;
        }

        // Remove existing language prefix (v5.2.135: added sl, also match URLs ending with just /xx/)
        // First try to match /xx/ pattern, then /xx (for homepage like /sl/)
        var cleanUrl = currentUrl.replace(/^\/([a-z]{2})(?:\/|$)/, '/');

        // Ensure we have at least a /
        if (cleanUrl === '' || cleanUrl === '/') {
            cleanUrl = '/';
        }

        window.linguaDebug('[LINGUA IFRAME] cleanUrl after removing prefix:', cleanUrl);

        // Add new prefix if not default
        if (targetLang && targetLang !== defaultLanguage) {
            var result = '/' + targetLang + cleanUrl;
            window.linguaDebug('[LINGUA IFRAME] Final URL with language prefix:', result);
            return result;
        }

        window.linguaDebug('[LINGUA IFRAME] Final URL (default language):', cleanUrl);
        return cleanUrl;
    };

    window.translationModal.reloadPreviewIframe = function() {
        var $iframe = $('#lingua-preview-iframe')[0];
        if ($iframe && $iframe.contentWindow) {
            var $loading = $('#lingua-preview-loading');
            $loading.addClass('visible');

            // Get clean URL without query parameters
            var currentSrc = $iframe.src;
            var url = new URL(currentSrc);
            var cleanPath = url.pathname;

            // Rebuild URL with fresh parameters
            var newSrc = cleanPath + '?lingua_preview=1&_t=' + Date.now();

            window.linguaDebug('[LINGUA IFRAME v5.2] Reloading iframe from:', currentSrc, 'to:', newSrc);

            $iframe.src = newSrc;

            // Hide loading after reload (reduced timeout)
            setTimeout(function() {
                $loading.removeClass('visible');
            }, 600);

            window.linguaDebug('[LINGUA IFRAME v5.2] Preview iframe reloaded with cache-busting');
        }
    };

    window.translationModal.syncIframeResize = function(panelWidth) {
        var $iframe = $('#lingua-preview-iframe');
        if ($iframe.length) {
            var iframeLeft = panelWidth;
            var iframeWidth = 100 - panelWidth;

            $iframe.css({
                'left': iframeLeft + '%',
                'width': iframeWidth + '%'
            });
        }
    };

    // Initialize the translation modal
    window.translationModal.init();
    window.linguaDebug('✅ LINGUA DEBUG: translation-modal.js initialization completed successfully');

});

} catch (globalError) {
    console.error('🔥🔥🔥 LINGUA CRITICAL ERROR: Global JavaScript error in translation-modal.js:', globalError);
    alert('🔥 LINGUA ERROR: Критическая ошибка в translation-modal.js! Смотрите консоль: ' + globalError.message);
}