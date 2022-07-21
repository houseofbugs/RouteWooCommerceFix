(function ($) {
    "use strict";
    jQuery(function ($) {
        $('.route-datepicker').datepicker({
            dateFormat : 'yy-mm-dd'
        });
        $('.woocommerce-save-button').html('Sync Orders');
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
