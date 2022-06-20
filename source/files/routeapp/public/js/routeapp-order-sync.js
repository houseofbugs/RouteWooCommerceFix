(function ($) {
    "use strict";
    jQuery(function ($) {
        $('.route-datepicker').datepicker({
            dateFormat : 'yy-mm-dd'
        });
        $('.woocommerce-save-button').html('Sync Orders');
        if ($('#route-order-sync-query').length) {
            let queryValue = $('#route-order-sync-query').html();
            $('#mainform').append('<div class="route-order-sync-query-visible">' + queryValue + '</div>');
        }
        if ($('#message').length) {
            let queryCountMessage = 'Total orders processed: ' + $('#route-order-sync-query-count').html();
            $('#message').html('<p>' + queryCountMessage + '</p>');
        }
        let spinner = new jQuerySpinner({
            parentId: 'wpwrap'
        });
        $('.woocommerce-save-button').on( "click", function() {
            spinner.show();
        });

    });
})(jQuery);