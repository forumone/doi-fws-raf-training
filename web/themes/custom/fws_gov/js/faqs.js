(function ($, Drupal) {

    'use strict';

    // toggles collapsiblity on FAQs
    Drupal.behaviors.faqs = {
        attach: function (context) {
            $(once('faqs', '.view-display-id-faq_block .faq-wrapper .faq-question', context)).on('click', function () {
                $(this).parent().toggleClass('open', 1000);
            });
        }
    };

})(jQuery, Drupal);