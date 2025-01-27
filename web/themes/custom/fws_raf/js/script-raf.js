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

  Drupal.behaviors.tableSortAccessibility = {
    attach: function (context, settings) {
      // Target all links in table headers that have sort functionality
      once('table-sort-accessibility', 'th a[href*="sort="]', context).forEach(function (link) {
        $(link).attr('role', 'button');
      });
    }
  };

  Drupal.behaviors.viewsExposedFormFocus = {
    attach: function (context, settings) {
      // Store the focused element globally to persist through AJAX
      if (!Drupal.viewsExposedFormFocus) {
        Drupal.viewsExposedFormFocus = {
          focusedElement: null
        };
      }

      once('views-exposed-form-focus', 'form.views-exposed-form', context).forEach(function (element) {
        const $form = $(element);

        // Store focused element for any input change in the form
        $form.find('input, select').on('change input', function (e) {
          console.log('change input', this.name);
          Drupal.viewsExposedFormFocus.focusedElement = {
            name: this.name,
            id: this.id,
            selectionStart: this.selectionStart,
            selectionEnd: this.selectionEnd
          };
        });
      });

      // Only attach the ajaxComplete handler once
      once('views-exposed-form-ajax', 'body', context).forEach(function (element) {
        $(document).on('ajaxComplete', function (event, xhr, settings) {
          console.log('ajaxComplete');
          const focusedElement = Drupal.viewsExposedFormFocus.focusedElement;
          if (focusedElement) {
            console.log('focusedElement', focusedElement.name);
            // Find the same element in the updated DOM
            const elementSelector = focusedElement.name ?
              `[name="${focusedElement.name}"]` :
              `#${focusedElement.id}`;
            const $newElement = $(elementSelector);
            if ($newElement.length) {
              $newElement.focus();
              // Restore cursor position if it's a text input or date input
              if ($newElement.is('input[type="text"]')) {
                $newElement[0].setSelectionRange(
                  focusedElement.selectionStart,
                  focusedElement.selectionEnd
                );
              }
            }
          }
        });
      });
    }
  };

})(jQuery, Drupal, once);
