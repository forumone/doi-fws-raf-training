/**
 * @file
 * JavaScript behaviors for Permit 3-186A form.
 */

(function ($, Drupal, once, drupalSettings) {
  'use strict';

  /**
   * Behavior to manage field group visibility based on activity type.
   */
  Drupal.behaviors.permitFieldGroupVisibility = {
    attach: function (context, settings) {
      // Target any form that appears to be our permit form
      once('permitFieldGroupVisibility', 'form#node-permit-3186a-form, form.node-permit-3186a-form', context).forEach(function (form) {
        console.log('Permit form behavior initialized');

        // Activity types and their required sections
        const visibleSections = {
          '1': ['1', '2', '3', '6'],
          '2': ['1', '2', '6'],
          '3': ['1', '2', '3', '6'],
          '4': ['1', '2', '3', '6'],
          '5': ['1', '2', '4', '6'],
          '6': ['1', '2', '5', '6']
        };

        // Map section numbers to expected DOM IDs (based on your example)
        const sectionIds = {
          '1': 'edit-group-reporter',
          '2': 'edit-group-sender',
          '3': 'edit-group-recipient',
          '4': 'edit-group-capture-info',
          '5': 'edit-group-rebanding',
          '6': 'edit-group-bird-info'
        };

        // Alternative IDs to try if the primary ones aren't found
        const alternativeSectionIds = {
          '4': ['edit-group-section-4', 'edit-group-capture', 'edit-capture-info'],
          '5': ['edit-group-section-5', 'edit-group-reband', 'edit-rebanding']
        };

        // Get URL parameters
        const urlParams = new URLSearchParams(window.location.search);
        const activityType = urlParams.get('activity_type');

        if (activityType) {
          console.log('Activity type from URL: ' + activityType);
        }

        // Try to find the activity type field
        let $activityField = $(form).find('input[name="field_activity_type"], select[name="field_activity_type"]');

        // Find the field groups we need to manipulate
        const fieldGroups = {};

        // Debug all groups in the form
        console.log('Searching for field groups...');
        $(form).find('.field-group-details, .field-group-fieldset, .field-group-tabs, .field-group-tab, .field-group-html-element, .panel, .panel-default')
          .each(function () {
            console.log('Found potential field group: #' + $(this).attr('id'));
          });

        // Look for the field groups by ID
        for (const [section, id] of Object.entries(sectionIds)) {
          const $group = $('#' + id);
          if ($group.length) {
            fieldGroups[section] = $group;
            console.log('Found section ' + section + ' group: #' + id);
          } else {
            console.log('Section ' + section + ' group not found with primary ID: #' + id);

            // Try alternative IDs for section 4 and 5
            if (alternativeSectionIds[section]) {
              for (const altId of alternativeSectionIds[section]) {
                const $altGroup = $('#' + altId);
                if ($altGroup.length) {
                  fieldGroups[section] = $altGroup;
                  console.log('Found section ' + section + ' using alternative ID: #' + altId);
                  break;
                }
              }
            }

            // If still not found, try by content
            if (!fieldGroups[section]) {
              console.log('Trying to find section ' + section + ' by text content');

              // Look for headings that might identify the section
              $(form).find('h2, h3, .panel-title, legend').each(function () {
                const text = $(this).text().toLowerCase();
                if ((section === '4' && (text.includes('capture') || text.includes('section 4'))) ||
                  (section === '5' && (text.includes('reband') || text.includes('section 5')))) {
                  // Try to get the parent container
                  const $container = $(this).closest('.panel, .field-group-details, fieldset');
                  if ($container.length) {
                    fieldGroups[section] = $container;
                    console.log('Found section ' + section + ' by text content: ' + text);
                  }
                }
              });
            }
          }
        }

        // Set up change handler for activity type
        if ($activityField.length) {
          $activityField.on('change', function () {
            const selectedActivity = $(this).val();
            console.log('Activity type changed to: ' + selectedActivity);
            toggleFieldGroups(selectedActivity);
          });
        } else {
          // Try to find the activity radios by looking for their container
          const $radios = $(form).find('input[type="radio"][name$="[activity_type]"]');
          if ($radios.length) {
            console.log('Found activity type radios');
            $radios.on('change', function () {
              const selectedActivity = $(this).val();
              console.log('Activity radio changed to: ' + selectedActivity);
              toggleFieldGroups(selectedActivity);
            });
          } else {
            console.log('Activity field not found by name');
          }
        }

        // Initialize with activity type from URL parameter
        if (activityType) {
          if ($activityField.length) {
            if ($activityField.is('input[type="radio"]')) {
              $activityField.filter('[value="' + activityType + '"]').prop('checked', true).trigger('change');
            } else {
              $activityField.val(activityType).trigger('change');
            }
          } else {
            // Try the radios
            const $radios = $(form).find('input[type="radio"][name$="[activity_type]"]');
            if ($radios.length) {
              $radios.filter('[value="' + activityType + '"]').prop('checked', true).trigger('change');
            } else {
              // Just apply the toggle directly
              toggleFieldGroups(activityType);
            }
          }
        }

        /**
         * Toggle visibility of field groups based on activity type.
         */
        function toggleFieldGroups(activityType) {
          if (!activityType || !visibleSections[activityType]) {
            console.log('No valid activity type or section mapping');
            return;
          }

          const sectionsToShow = visibleSections[activityType];
          console.log('Sections to show for activity ' + activityType + ': ' + sectionsToShow.join(', '));

          // Debug what field groups we found
          console.log('Field groups to manage:', Object.keys(fieldGroups));

          // Process each section
          for (const [section, $group] of Object.entries(fieldGroups)) {
            console.log('Processing section ' + section + ', should show: ' + sectionsToShow.includes(section));

            // Skip section 1, 2, and 6 as they're always shown
            if (['1', '2', '6'].includes(section)) {
              console.log('Skipping section ' + section + ' (always shown)');
              continue;
            }

            if (sectionsToShow.includes(section)) {
              console.log('Showing section ' + section);
              $group.show();
              $group.find('input, select, textarea').prop('disabled', false);
            } else {
              console.log('Hiding section ' + section + ' with ID: ' + $group.attr('id'));
              // Use both methods to ensure hiding works
              $group.hide();
              $group.css('display', 'none');
              $group.find('input, select, textarea').prop('disabled', true);

              // Check if hiding worked
              setTimeout(function () {
                console.log('Section ' + section + ' display style: ' + $group.css('display'));
              }, 100);
            }
          }
        }
      });

      Drupal.behaviors.permitFieldGroupVisibility.autofill();
    },
    autofill: function(context, settings) {
      const $self = Drupal.behaviors.permitFieldGroupVisibility;
      once('initAutofillField', 'form#node-permit-3186a-form, form.node-permit-3186a-form', context).forEach(function(form) {
        const $btnAutoFillSender = $self.createFakeBtn(Drupal.t('I am the sender'));
        $(form).find('#edit-group-sender--content').prepend($btnAutoFillSender);
        $('input', $btnAutoFillSender).on('click', () => $self.handlerAutofill(form));

        const $btnAutoFillRecipient = $self.createFakeBtn(Drupal.t('I am the recipient'));
        $(form).find('#edit-group-recipient--content').prepend($btnAutoFillRecipient);
        $('input', $btnAutoFillRecipient).on('click', () => $self.handlerAutofill(form, 'recipient'));
      });
    },
    createFakeBtn: function(label) {
      let $buttonWrapper = $('<div class="form-item form-type-button"></div>');
      let $button = $('<input type="button" value="' + label + '" class="form-submit btn">');
      $buttonWrapper.append($button);
      return $buttonWrapper;
    },
    handlerAutofill: function(form, type = 'sender') {
      const { mapping_fields, profile } = drupalSettings.fws_falcon_permit;
      for (const [src, desc] of Object.entries(mapping_fields[type])) {
        if (typeof profile[desc] !== 'undefined') {
          $(form).find("input[name^='" + src + "[']").val(profile[desc]);
          $(form).find("select[name='" + src + "']").val(profile[desc]).trigger('change');
        }
      }
    }
  };
})(jQuery, Drupal, once, drupalSettings);
