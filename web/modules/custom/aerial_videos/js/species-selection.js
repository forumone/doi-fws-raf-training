/**
 * @file
 * JavaScript behaviors for species selection.
 */

(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.aerialVideos = {
    attach: function (context, settings) {
      // Function to populate species dropdown
      this.populateSpecies = function (filteredByGroup = false) {
        const selectedGroup = $('#selectedGroup').val();
        const $speciesSelect = $('#selectedSpecies');
        const speciesByGroup = drupalSettings.aerialVideos.speciesByGroup;
        let allSpecies = [];

        // Clear current options
        $speciesSelect.empty();
        $speciesSelect.append($('<option>', {
          value: '',
          text: '- Select -'
        }));

        // If filtering by group, only get species from that group
        if (filteredByGroup && selectedGroup && speciesByGroup[selectedGroup]) {
          allSpecies = Object.values(speciesByGroup[selectedGroup]);
        } else {
          // Get all species from all groups
          Object.values(speciesByGroup).forEach(group => {
            allSpecies = allSpecies.concat(Object.values(group));
          });
        }

        // Sort alphabetically by name
        allSpecies.sort((a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }));

        // Add species to dropdown
        allSpecies.forEach(function (item) {
          $speciesSelect.append($('<option>', {
            value: item.id,
            text: item.name,
            'data-video-url': item.videoUrl
          }));
        });

        // Reset the species selection
        $speciesSelect.val('');
        if ($speciesSelect.hasClass('select2-hidden-accessible')) {
          $speciesSelect.trigger('change');
        }
      };

      // Handle video playback when the submit button is clicked
      this.playVideo = function () {
        const $selectedOption = $('#selectedSpecies option:selected');
        if ($selectedOption.length && $selectedOption.attr('data-video-url')) {
          const videoUrl = $selectedOption.attr('data-video-url');
          // Open video in new tab
          window.open(videoUrl, '_blank');
        }
      };

      // Initial population of species dropdown
      once('aerial-videos-init', 'body', context).forEach(() => {
        this.populateSpecies(false);
      });

      // Attach the change handler to the group select
      once('aerial-videos', '#selectedGroup', context).forEach(function (element) {
        $(element).on('change', function () {
          Drupal.behaviors.aerialVideos.populateSpecies(true);
        });
      });

      // Attach the click handler to the view video button
      once('aerial-videos-button', '#viewVideoButton', context).forEach(function (element) {
        $(element).on('click', function (e) {
          e.preventDefault();
          Drupal.behaviors.aerialVideos.playVideo();
        });
      });
    }
  };

})(jQuery, Drupal, once);
