(function ($, Drupal, once) {
  'use strict';

  // Initialize the fws_submenu behavior if it doesn't exist
  Drupal.behaviors.fws_submenu = Drupal.behaviors.fws_submenu || {};

  Drupal.behaviors.bootstrapDropdowns = {
    attach: function (context, settings) {
      // Enable dropdown menus with hover functionality
      once('bootstrap-hover', '.dropdown', context).forEach(function (element) {
        const $dropdown = $(element);

        $dropdown.hover(
          function () {
            const $this = $(this);
            // Add a small delay to prevent accidental triggers
            $this.data('timeout', setTimeout(function () {
              $this.addClass('show');
              $this.find('.dropdown-menu').addClass('show');
            }, 200));
          },
          function () {
            const $this = $(this);
            // Clear timeout if it exists
            clearTimeout($this.data('timeout'));
            $this.removeClass('show');
            $this.find('.dropdown-menu').removeClass('show');
          }
        );
      });

      // Prevent the dropdown from closing when clicking inside it
      once('bootstrap-click', '.dropdown-menu', context).forEach(function (element) {
        $(element).click(function (e) {
          e.stopPropagation();
        });
      });
    }
  };

})(jQuery, Drupal, once);
