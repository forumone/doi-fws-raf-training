(function ($, Drupal) {

    'use strict';

    // copy image credits to clipboard
    Drupal.behaviors.clipboard = {
        attach: function (context) {

            var $btnCopy = $('#copy-button');

            $btnCopy.on('click', function() {
                var clipboard = new ClipboardJS('#copy-button');
            
                clipboard.on('success', function(e) {
                  $btnCopy.text('Credit copied to clipboard');
                  $btnCopy.addClass('copied');
            
                  setTimeout(function() {
                    $btnCopy.text('Copy credit');
                    $btnCopy.removeClass('copied');
                  }, 2000);
                });

                clipboard.on('error', function(e) {
                    // console.error('Action:', e.action);
                    // console.error('Trigger:', e.trigger);
                });
            });


            
        }
    };

})(jQuery, Drupal);

  