/**
 * @file
 * JavaScript behaviors for species selection.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.aerialVideos = {
    attach: function (context, settings) {
      // Initialize the species dropdown based on the selected group.
      this.filterSpecies = function () {
        const selectedGroup = $('#selectedGroup').val();
        const $speciesSelect = $('#selectedSpecies');
        const speciesByGroup = drupalSettings.aerialVideos.speciesByGroup;

        // Clear current options.
        $speciesSelect.empty();
        $speciesSelect.append($('<option>', {
          value: '',
          text: '- Select -'
        }));

        // If a group is selected, add its species.
        if (selectedGroup && speciesByGroup[selectedGroup]) {
          const species = speciesByGroup[selectedGroup];
          // Convert to array and sort alphabetically by name
          const sortedSpecies = Object.values(species).sort((a, b) =>
            a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })
          );

          // Add sorted species to dropdown
          sortedSpecies.forEach(function (item) {
            $speciesSelect.append($('<option>', {
              value: item.id,
              text: item.name
            }));
          });
        }

        // Reset the species selection.
        $speciesSelect.val('');
        if ($speciesSelect.hasClass('select2-hidden-accessible')) {
          $speciesSelect.trigger('change');
        }
      };

      // Attach the change handler to the group select.
      $('#selectedGroup', context).once('aerial-videos').on('change', function () {
        Drupal.behaviors.aerialVideos.filterSpecies();
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
