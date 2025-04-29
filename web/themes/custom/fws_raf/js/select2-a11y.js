(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.select2Accessibility = {
    attach: function (context, settings) {
      // Target all Select2 instances using Select2's default class
      once('select2-a11y', '.select2-hidden-accessible', context).forEach(function (element) {
        const $select = $(element);
        const selectId = $select.attr('id');
        const resultsId = `${selectId}-results`;
        const descriptionId = `${selectId}-description`;
        const statusId = `${selectId}-status`;

        // Check if the select is inside a .select-wrapper and add class if found
        const $wrapper = $select.closest('.select-wrapper');
        if ($wrapper.length) {
          $wrapper.addClass('select-wrapper--select2');
        }

        // Add unique ID to selection element
        const $container = $select.next('.select2-container');
        const $selection = $container.find('.select2-selection');
        const $renderedText = $container.find('.select2-selection__rendered');

        // Add unique ID to selection element
        const selectionId = `${selectId}-selection`;
        $selection.attr('id', selectionId);

        // Find the label in the same form-item container regardless of its structure
        const $controlLabel = $select.closest('.form-item').find('label.control-label');
        if ($controlLabel.length) {
          // Ensure the label has a for attribute pointing to the selection element
          $controlLabel.attr('for', selectionId);
        }

        // Find associated label with for attribute (may be different from control-label)
        const $label = $(`label[for="${selectId}"]`);
        if ($label.length) {
          const labelId = $label.attr('id') || `${selectId}-label`;
          $label.attr('id', labelId);
        }

        // Set proper aria-controls (remove aria-labelledby as requested)
        $selection
          .removeAttr('aria-labelledby')
          .attr('aria-controls', resultsId);

        // Remove redundant attributes from rendered text
        $renderedText
          .removeAttr('role')
          .removeAttr('aria-readonly');

        // Add descriptive text for screen readers
        const description = $select.attr('multiple')
          ? 'Press enter to open the dropdown. Use arrow keys to navigate, space to select multiple, enter to close. Selected items can be removed with delete, backspace, or the clear button.'
          : 'Press enter to open the dropdown. Use arrow keys to navigate, enter to select, escape to close. Selected item can be removed with delete, backspace, or the clear button.';

        const $description = $(`<span id="${descriptionId}" class="visually-hidden">${description}</span>`);
        const $status = $(`<span id="${statusId}" class="visually-hidden" aria-live="assertive"></span>`);
        $container.append($description).append($status);

        // Add description to the selection
        $selection.attr('aria-describedby', descriptionId);

        // Enhanced function to make the clear button work with keyboard
        const updateClearButton = function ($elem) {
          // Remove previous event handlers to prevent duplicates
          $(document).off('keydown.select2clear', '.select2-selection__clear');

          // Find clear button
          const $clearButton = $elem.next('.select2-container').find('.select2-selection__clear');
          if ($clearButton.length) {
            // Set proper attributes
            $clearButton
              .attr('aria-label', 'Remove all items')
              .attr('title', 'Remove all items')
              .attr('aria-describedby', `${$renderedText.attr('id')}`)
              .attr('tabindex', '0')
              .attr('role', 'button');

            // Remove any existing event handlers to prevent duplicates
            $clearButton.off('keydown.select2clear');

            // Add keyboard event listener at the document level with delegated selector
            $(document).on('keydown.select2clear', '.select2-selection__clear', function(e) {
              // Handle Enter (13) or Space (32) key presses
              if (e.which === 13 || e.which === 32) {
                e.preventDefault();
                e.stopPropagation();

                // Trigger the mousedown event to clear the selection
                $(this).trigger('mousedown');

                // Announce selection cleared
                announceSelection(null, true);

                // Return focus to the selection element
                $(this).closest('.select2-container').find('.select2-selection').focus();
              }
            });
          }
        };

        // Apply to all select2 elements on page load
        $('.select2-hidden-accessible').each(function() {
          updateClearButton($(this));
        });

        // Helper function to format label text
        const formatLabelText = function (label) {
          // Remove any existing colons and trim whitespace
          return label.replace(/:/g, '').trim();
        };

        // Announce selection changes with a slight delay
        const announceSelection = function (selected, isCleared) {
          // Clear any pending announcements
          if (window.rafAnnouncementTimeout) {
            clearTimeout(window.rafAnnouncementTimeout);
          }

          window.rafAnnouncementTimeout = setTimeout(function () {
            let text;
            if (isCleared) {
              text = 'Selection cleared';
            } else if (selected) {
              text = `Event: ${selected}`;
            } else {
              text = 'No selection';
            }
            $status.text(text);
          }, 100); // Brief delay to ensure screen readers catch the change
        };

        // Initial announcement if there's a pre-selected value
        const initialValue = $select.find('option:selected').text();
        if (initialValue && initialValue !== '') {
          announceSelection(initialValue);
        }

        $select.on('select2:select', function (e) {
          announceSelection(e.params.data.text);

          // Update aria-selected attribute when selection changes
          const selectedValue = e.params.data.id;
          $('.select2-results__option').each(function() {
            const $option = $(this);
            $option.attr('aria-selected', $option.attr('data-select2-id') === selectedValue ? 'true' : 'false');
          });

          // Make sure the clear button is keyboard accessible after selection
          updateClearButton($(this));
        });

        $select.on('select2:unselect', function () {
          announceSelection(null, true);
          updateClearButton($(this));
        });

        $select.on('select2:open', function () {
          // Clear any pending announcements
          if (window.rafAnnouncementTimeout) {
            clearTimeout(window.rafAnnouncementTimeout);
          }

          // Announce number of available options
          const optionCount = $select.find('option').length - 1; // Subtract 1 for the empty placeholder option

          window.rafAnnouncementTimeout = setTimeout(function () {
            $status.text(`${optionCount} options available`);
          }, 100);
        });

        // Add necessary ARIA attributes when dropdown opens
        $select.on('select2:open', function () {
          const $container = $(this).next('.select2-container');
          const $dropdown = $('.select2-dropdown');
          const $results = $dropdown.find('.select2-results__options');
          const $search = $('.select2-search__field');

          // Set ID and required ARIA attributes on results list (remove aria-expanded as requested)
          $results
            .attr('id', resultsId)
            .removeAttr('aria-expanded')
            .attr('role', 'listbox')
            .attr('aria-label', 'Search results');

          // Add ARIA attributes to the search input if it exists
          if ($search.length) {
            $search
              .attr('aria-expanded', 'true')
              .attr('aria-controls', resultsId);
          }

          // Add ARIA attributes to each option and ensure the selected option has aria-selected="true"
          $results.find('li[role="option"]').each(function () {
            const $option = $(this);
            $option.attr('aria-selected', $option.hasClass('select2-results__option--selected') ? 'true' : 'false');
          });

          // Make sure the clear button is keyboard accessible
          updateClearButton($(this));
        });

        // Update aria-expanded when dropdown closes
        $select.on('select2:close', function () {
          const $search = $('.select2-search__field');
          if ($search.length) {
            $search.attr('aria-expanded', 'false');
          }

          // Make sure the clear button is keyboard accessible
          updateClearButton($(this));
        });

        // Add a mutation observer to handle dynamically added clear buttons
        if (window.MutationObserver && !window.select2ClearObserver) {
          window.select2ClearObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
              if (mutation.addedNodes.length) {
                // Check each added node
                $(mutation.addedNodes).each(function() {
                  const $node = $(this);
                  // If this is a clear button or contains one
                  if ($node.hasClass('select2-selection__clear') || $node.find('.select2-selection__clear').length) {
                    // Update all clear buttons
                    $('.select2-hidden-accessible').each(function() {
                      updateClearButton($(this));
                    });
                  }
                });
              }
            });
          });

          // Start observing the document body for added nodes
          window.select2ClearObserver.observe(document.body, {
            childList: true,
            subtree: true
          });
        }
      });
    }
  };
})(jQuery, Drupal, once);
