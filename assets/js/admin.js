jQuery(document).ready(function($) {
    // Lingua Admin JavaScript
    window.linguaDebug('Lingua Admin loaded');
    
    // Handle language selection
    $('.lingua-language-select').on('change', function() {
        var selectedLanguage = $(this).val();
        window.linguaDebug('Language selected:', selectedLanguage);
    });
    
    // Handle save settings
    $('#lingua-save-settings').on('click', function(e) {
        e.preventDefault();
        // Add AJAX save functionality here
        window.linguaDebug('Settings saved');
    });
});