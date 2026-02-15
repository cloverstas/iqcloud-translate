jQuery(document).ready(function($) {
    // Lingua Public JavaScript
    window.linguaDebug('Lingua Public loaded');
    
    // Handle language switcher
    $('.lingua-language-switcher select').on('change', function() {
        var selectedLang = $(this).val();
        // Redirect to translated version
        window.location.href = window.location.pathname + '?lang=' + selectedLang;
    });
});