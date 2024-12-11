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

  Drupal.behaviors.keepViewsFiltersOpen = {
    attach: function (context, settings) {
      // Handle form submission
      once('views-filter-submit', 'form.views-exposed-form', context).forEach(function (element) {
        $(element).on('submit', function (e) {
          var $panel = $(this).closest('.panel-collapse');
          var $toggle = $panel.siblings('.panel-heading').find('[data-toggle="collapse"]');

          $panel
            .addClass('in')
            .css('height', '')
            .attr('aria-expanded', 'true');

          $toggle
            .removeClass('collapsed')
            .attr('aria-expanded', 'true');
        });
      });

      // Handle AJAX completion
      // Use once() on the document only if it hasn't been processed yet
      once('views-filter-ajax', 'body', context).forEach(function (element) {
        $(document).on('ajaxComplete', function (event, xhr, settings) {
          $('.panel-collapse:has(.views-exposed-form)').each(function () {
            var $panel = $(this);
            var $toggle = $panel.siblings('.panel-heading').find('[data-toggle="collapse"]');

            $panel
              .addClass('in')
              .css('height', '')
              .attr('aria-expanded', 'true');

            $toggle
              .removeClass('collapsed')
              .attr('aria-expanded', 'true');
          });
        });
      });
    }
  };

})(jQuery, Drupal, once);
