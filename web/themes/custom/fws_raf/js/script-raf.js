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

        // Set the submenu ID based on the aria-controls attribute due to limited value scope
        const submenuId = $toggle.attr('aria-controls');
        if (submenuId) {
          $menu.attr('id', submenuId);
        }

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

        // Add spacebar support to submit sorting changes
        $(link).on('keydown', function(e) {
          if (e.which === 32) {
            e.preventDefault();  // Prevent page scroll
            window.location = e.target.href;
          }
        });
      });
    }
  };

  Drupal.behaviors.verticalTabAccessibility = {
    attach: function (context, settings) {
      $(window).on('load', function() {
        once('vertical-tab-accessibility', '.js-form-type-vertical-tabs', context).forEach(function (tabs) {
          // Remove the control label at the top
          $(tabs).find('.control-label').remove();

          const $tabList = $(tabs).find('.vertical-tabs-list');
          const $tabButtons = $(tabs).find('.vertical-tab-button');
          const $tabLinks = $(tabs).find('.vertical-tab-button > a');
          const $tabPanels = $(tabs).find('.vertical-tabs-pane');

          // Remove role="tablist" and aria-orientation from the container
          $tabList.attr({
            'aria-label': 'Additional node settings'
          });

          // Set up each tab button as a presentation container
          $tabButtons.attr({
            'role': 'presentation'
          });

          // Remove any existing (active tab) spans
          $tabLinks.find('#active-vertical-tab').remove();

          // Set up each tab link with proper ARIA attributes and remove aria-expanded
          $tabLinks.each(function(index) {
            const $link = $(this);
            const panelId = 'vertical-tabs-panel-' + index;
            const tabId = 'vertical-tabs-tab-' + index;
            const isSelected = $link.parent().hasClass('selected');

            // Remove aria-expanded attribute and ensure it stays removed
            $link.removeAttr('aria-expanded')
              .on('click.removeExpanded mousedown.removeExpanded focus.removeExpanded', function() {
                $(this).removeAttr('aria-expanded');
              });

            $link.attr({
              'role': 'tab',
              'aria-selected': isSelected ? 'true' : 'false',
              'aria-controls': panelId,
              'id': tabId,
              'tabindex': isSelected ? '0' : '-1'
            });
          });

          // Set up each tab panel with proper ARIA attributes
          $tabPanels.each(function(index) {
            const $panel = $(this);
            const panelId = 'vertical-tabs-panel-' + index;
            const tabId = 'vertical-tabs-tab-' + index;

            $panel.attr({
              'role': 'tabpanel',
              'aria-labelledby': tabId,
              'id': panelId,
              'tabindex': '0'
            });

            // Hide all panels except the selected one
            if (!$tabLinks.eq(index).parent().hasClass('selected')) {
              $panel.hide();
            }
          });

          // Add keyboard navigation
          $tabLinks.on('keydown', function(e) {
            const $currentTab = $(this);
            let $targetTab = null;

            switch(e.key) {
              case 'ArrowLeft':
              case 'ArrowUp':
                e.preventDefault();
                $targetTab = $currentTab.parent().prev().find('a');
                if (!$targetTab.length) {
                  $targetTab = $tabLinks.last();
                }
                break;

              case 'ArrowRight':
              case 'ArrowDown':
                e.preventDefault();
                $targetTab = $currentTab.parent().next().find('a');
                if (!$targetTab.length) {
                  $targetTab = $tabLinks.first();
                }
                break;

              case 'Home':
                e.preventDefault();
                $targetTab = $tabLinks.first();
                break;

              case 'End':
                e.preventDefault();
                $targetTab = $tabLinks.last();
                break;

              case ' ':
              case 'Enter':
                e.preventDefault();
                activateTab($currentTab);
                break;
            }

            if ($targetTab && $targetTab.length) {
              activateTab($targetTab);
            }
          });

          // Click handler for tabs
          $tabLinks.on('click', function(e) {
            e.preventDefault();
            activateTab($(this));
          });

          // Function to activate a tab
          function activateTab($tab) {
            const $panel = $('#' + $tab.attr('aria-controls'));

            // Update tab states
            $tabLinks.attr('aria-selected', 'false').attr('tabindex', '-1')
              .removeAttr('aria-expanded'); // Ensure aria-expanded stays removed
            $tab.attr('aria-selected', 'true').attr('tabindex', '0')
              .removeAttr('aria-expanded') // Ensure aria-expanded stays removed
              .focus();

            // Update panel visibility
            $tabPanels.hide();
            $panel.show();

            // Update selected class
            $tabButtons.removeClass('selected');
            $tab.parent().addClass('selected');
          }
        });
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
          const focusedElement = Drupal.viewsExposedFormFocus.focusedElement;
          if (focusedElement) {
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
