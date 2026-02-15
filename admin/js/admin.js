/**
 * Lingua Admin JavaScript
 */

// v5.4.1: Ensure linguaDebug exists before use (may load before inline footer script)
if (typeof window.linguaDebug !== 'function') {
    window.linguaDebug = function() {};
}

// 🔥 CRITICAL DEBUG: Test admin.js loading
window.linguaDebug('🔥🔥🔥 LINGUA ADMIN.JS: Starting to load');
window.linguaAdminJsLoaded = true;

jQuery(document).ready(function($) {
    window.linguaDebug('🔥🔥🔥 LINGUA ADMIN.JS: jQuery ready, continuing...');
    
    // Tab switching functionality
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        
        // Remove active class from all tabs and content
        $('.nav-tab').removeClass('nav-tab-active');
        $('.tab-content').removeClass('active');
        
        // Add active class to clicked tab
        $(this).addClass('nav-tab-active');
        
        // Show corresponding content
        var target = $(this).attr('href');
        $(target).addClass('active');
    });
    
    // v5.2: Toggle API key visibility for Middleware API
    $('#toggle-api-key').on('click', function() {
        var $input = $('#middleware_api_key');
        var $button = $(this);

        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $button.text(lingua_admin.strings.hide || 'Hide');
        } else {
            $input.attr('type', 'password');
            $button.text(lingua_admin.strings.show || 'Show');
        }
    });
    
    // v5.2: Test Middleware API connection
    $('#test-api-connection').on('click', function() {
        var $button = $(this);
        var $result = $('#api-test-result');

        $button.prop('disabled', true);
        $result.removeClass('success error').addClass('loading').text(lingua_admin.strings.testing_connection || 'Testing...');

        $.ajax({
            url: lingua_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'lingua_test_api',
                nonce: lingua_admin.nonce,
                api_key: $('#middleware_api_key').val()
            },
            success: function(response) {
                if (response.success) {
                    $result.removeClass('loading error').addClass('success').text('✓ ' + response.data);
                } else {
                    $result.removeClass('loading success').addClass('error').text('✗ ' + response.data);
                }
            },
            error: function() {
                $result.removeClass('loading success').addClass('error').text('✗ ' + (lingua_admin.strings.connection_failed || 'Connection failed'));
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });
    
    // Handle filter button on translations page
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
        
        // For now, just show an alert - translation modal will be implemented in next step
        alert('Translation modal will open here for post ID: ' + postId);
    });
    
    // Handle bulk translate button
    $('#bulk-translate-posts').on('click', function(e) {
        e.preventDefault();
        alert('Bulk translation functionality will be implemented');
    });
    
    // Handle translate button in post editor and admin bar
    $(document).on('click', '.lingua-admin-bar-translate-btn', function(e) {
        e.preventDefault();
        
        
        // Проверим все data-атрибуты
        $.each(this.attributes, function(i, attr) {
            if (attr.name.indexOf('data-') === 0) {
            }
        });
        
        // Пробуем получить post ID разными способами
        var postId1 = $(this).data('post-id');
        var postId2 = $(this).attr('data-post-id');
        var postId3 = this.getAttribute('data-post-id');
        var postId4 = $(this).closest('#wp-admin-bar-lingua-translate-page').find('a').attr('data-post-id');
        
        // Новый метод - из href ссылки
        var href = $(this).find('a').attr('href') || $(this).attr('href');
        var postId5 = null;
        if (href && href.indexOf('#lingua-translate-') === 0) {
            postId5 = href.replace('#lingua-translate-', '');
        }
        
        
        var postId = postId1 || postId2 || postId3 || postId4 || postId5;
        
        
        // Если ID не найден, попробуем другие методы
        if (!postId || postId == '0' || postId === 0) {
            
            // Проверим URL
            var urlParams = new URLSearchParams(window.location.search);
            var urlPostId = urlParams.get('post') || urlParams.get('p');
            
            // Проверим путь URL
            var pathMatch = window.location.pathname.match(/\/(\d+)\//);
            var pathPostId = pathMatch ? pathMatch[1] : null;
            
            // Для тестирования - просто используем ID = 1
            postId = urlPostId || pathPostId || '1';
        }
        
        
        if (!postId || postId == '0' || postId === '0') {
            alert('Debug: No post ID found. Check console for details.');
            return;
        }
        
        // Инициализируем модальное окно перевода
        if (typeof translationModal !== 'undefined') {
            translationModal.openModal(postId);
        } else {
            console.error('Translation modal not found. Make sure translation-modal.js is loaded.');
            alert('Translation modal not loaded. Please refresh the page and try again.');
        }
    });
    
    // Language selection improvements
    $('.lingua-language-item input[type="checkbox"]').on('change', function() {
        var $item = $(this).closest('.lingua-language-item');
        
        if (this.checked) {
            $item.css('background-color', '#e8f4f8');
        } else {
            $item.css('background-color', '');
        }
    });
    
    // Initialize language item states
    $('.lingua-language-item input[type="checkbox"]:checked').each(function() {
        $(this).closest('.lingua-language-item').css('background-color', '#e8f4f8');
    });
    
    // Form validation
    $('form').on('submit', function(e) {
        var hasErrors = false;
        
        // Check required fields
        $(this).find('input[required], select[required]').each(function() {
            if (!$(this).val()) {
                $(this).css('border-color', 'red');
                hasErrors = true;
            } else {
                $(this).css('border-color', '');
            }
        });
        
        if (hasErrors) {
            e.preventDefault();
            alert('Please fill in all required fields.');
        }
    });
    
    // Auto-save settings notification
    var hasUnsavedChanges = false;
    
    $('#lingua-settings-form input, #lingua-settings-form select, #lingua-settings-form textarea').on('change', function() {
        hasUnsavedChanges = true;
    });
    
    $(window).on('beforeunload', function() {
        if (hasUnsavedChanges) {
            return 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    $('form').on('submit', function() {
        hasUnsavedChanges = false;
    });
});