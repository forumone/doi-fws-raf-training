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

        // Find associated label
        const $label = $(`label[for="${selectId}"]`);
        if ($label.length) {
          const labelId = $label.attr('id') || `${selectId}-label`;
          $label.attr('id', labelId);

          // Add label association to the select2 container
          const $container = $select.next('.select2-container');
          const $selection = $container.find('.select2-selection');
          const $renderedText = $container.find('.select2-selection__rendered');

          // Set proper aria-labelledby and aria-controls
          $selection
            .attr('aria-labelledby', `${labelId} ${$renderedText.attr('id')}`)
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

          // Fix clear button label and description
          const updateClearButton = function ($elem) {
            const $clearButton = $elem.next('.select2-container').find('.select2-selection__clear');
            if ($clearButton.length) {
              $clearButton
                .attr('aria-label', 'Remove all items')
                .attr('title', 'Remove all items')
                .attr('aria-describedby', `${$renderedText.attr('id')}`);
            }
          };
          updateClearButton($select);

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
        }

        // Add necessary ARIA attributes when dropdown opens
        $select.on('select2:open', function () {
          const $container = $(this).next('.select2-container');
          const $dropdown = $('.select2-dropdown');
          const $results = $dropdown.find('.select2-results__options');
          const $search = $('.select2-search__field');

          // Set ID and role on results list
          $results
            .attr('id', resultsId)
            .attr('role', 'listbox')
            .attr('aria-multiselectable', $select.attr('multiple') === 'multiple');

          // Add ARIA attributes to the search input if it exists
          if ($search.length) {
            $search
              .attr('role', 'searchbox')
              .attr('aria-expanded', 'true')
              .attr('aria-controls', resultsId)
              .attr('aria-autocomplete', 'list')
              .attr('aria-activedescendant', ''); // Will be updated by Select2 when navigating

            // If there's a label, associate it with the search field too
            if ($label.length) {
              $search.attr('aria-labelledby', $label.attr('id'));
            }
          }

          // Add ARIA attributes to each option
          $results.find('li[role="option"]').each(function () {
            const $option = $(this);
            $option
              .attr('aria-selected', $option.hasClass('select2-results__option--selected'))
              .attr('aria-disabled', $option.hasClass('select2-results__option--disabled'));
          });
        });
      });
    }
  };
})(jQuery, Drupal, once);
