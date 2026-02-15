jQuery(document).ready(function($) {
    // Prevent clicking on current language links
    $('.lingua-current-language > a').on('click', function(e) {
        e.preventDefault();
        return false;
    });
    
    // Add dropdown functionality for menu items with sub-menus
    $('.menu-item-has-children.lingua-language-switcher-item').hover(
        function() {
            $(this).find('.sub-menu').first().stop(true, true).slideDown(200);
        },
        function() {
            $(this).find('.sub-menu').first().stop(true, true).slideUp(200);
        }
    );
    
    // Mobile menu toggle
    if ($(window).width() <= 768) {
        $('.lingua-language-switcher-item > a').on('click', function(e) {
            if ($(this).parent().hasClass('menu-item-has-children')) {
                e.preventDefault();
                $(this).siblings('.sub-menu').slideToggle();
            }
        });
    }
});