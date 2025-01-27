(function ($, Drupal, once) {
  'use strict';

  // Initialize the fws_submenu behavior if it doesn't exist
  Drupal.behaviors.fws_submenu = Drupal.behaviors.fws_submenu || {};

  Drupal.behaviors.bootstrapDropdowns = {
    attach: function (context, settings) {
      // Enable dropdown menus with hover and keyboard functionality
      once('bootstrap-hover', '.dropdown', context).forEach(function (element) {
        const $dropdown = $(element);
        const $toggle = $dropdown.find('.dropdown-toggle');
        const $menu = $dropdown.find('.dropdown-menu');

        // Hover functionality
        $dropdown.hover(
          function () {
            const $this = $(this);
            // Add a small delay to prevent accidental triggers
            $this.data('timeout', setTimeout(function () {
              $this.addClass('show');
              $menu.addClass('show');
              $toggle.attr('aria-expanded', 'true');
            }, 200));
          },
          function () {
            const $this = $(this);
            // Clear timeout if it exists
            clearTimeout($this.data('timeout'));
            $this.removeClass('show');
            $menu.removeClass('show');
            $toggle.attr('aria-expanded', 'false');
          }
        );

        // Keyboard functionality for dropdown toggle
        $toggle.on('keydown', function (e) {
          // Enter or Space to open/close dropdown
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const isExpanded = $toggle.attr('aria-expanded') === 'true';

            if (!isExpanded) {
              $dropdown.addClass('show');
              $menu.addClass('show');
              $toggle.attr('aria-expanded', 'true');
              // Focus first menu item
              $menu.find('a').first().focus();
            } else {
              $dropdown.removeClass('show');
              $menu.removeClass('show');
              $toggle.attr('aria-expanded', 'false');
            }
          }
        });

        // Keyboard navigation within dropdown menu
        $menu.find('a').on('keydown', function (e) {
          const $current = $(this);
          const $items = $menu.find('a');
          const $firstItem = $items.first();
          const $lastItem = $items.last();

          switch (e.key) {
            case 'ArrowDown':
              e.preventDefault();
              const $next = $current.parent().next().find('a');
              if ($next.length) {
                $next.focus();
              } else {
                $firstItem.focus();
              }
              break;

            case 'ArrowUp':
              e.preventDefault();
              const $prev = $current.parent().prev().find('a');
              if ($prev.length) {
                $prev.focus();
              } else {
                $lastItem.focus();
              }
              break;

            case 'Escape':
              e.preventDefault();
              $dropdown.removeClass('show');
              $menu.removeClass('show');
              $toggle.attr('aria-expanded', 'false');
              $toggle.focus();
              break;

            case 'Tab':
              // Close dropdown when tabbing out
              setTimeout(() => {
                if (!$dropdown.find(':focus').length) {
                  $dropdown.removeClass('show');
                  $menu.removeClass('show');
                  $toggle.attr('aria-expanded', 'false');
                }
              }, 0);
              break;
          }
        });
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

})(jQuery, Drupal, once);
