(function ($, Drupal, once) {
  'use strict';

  console.log('=== Sighting Count JavaScript Loaded ===');

  Drupal.behaviors.fwsSightingCount = {
    attach: function (context, settings) {
      console.log('=== Sighting Count Behavior Attaching ===');

      // Function to update the sighting count
      const updateSightingCount = function (yearId) {
        if (!yearId) {
          console.log('No year ID provided');
          return;
        }

        // Get the year text for logging
        const yearText = $('#edit-field-year-target-id--2 option[value="' + yearId + '"]').text();
        console.log('Updating count for year:', yearText, '(ID:', yearId, ')');

        $.ajax({
          url: '/api/sighting-count/' + yearId,
          method: 'GET',
          success: function (response) {
            console.log('API Success - Received count:', response.count);
            const countElement = $('.block-fws-sighting-count .fws-sighting-count');
            console.log('Found count element:', countElement.length > 0);
            if (response.error) {
              console.error('API Error:', response.error);
              countElement.text('Error: ' + response.error);
            } else {
              countElement.text(response.count + ' cranes');
            }
          },
          error: function (xhr, status, error) {
            console.error('API Error:', error);
            $('.block-fws-sighting-count .fws-sighting-count').text('Error loading count');
          }
        });
      };

      // Handle the year filter changes
      const yearSelect = $('#edit-field-year-target-id--2', context);
      console.log('Looking for year select in context:', yearSelect.length > 0);

      once('fwsSightingCount', '#edit-field-year-target-id--2', context).forEach(function (element) {
        console.log('=== Setting up year select handler ===');
        const $element = $(element);

        // Get initial count on page load
        const yearId = $element.val();
        console.log('Initial year value:', yearId);

        // Log all available options for debugging
        console.log('Available years:');
        $element.find('option').each(function () {
          console.log('  ' + $(this).val() + ': ' + $(this).text());
        });

        if (yearId) {
          console.log('Calling updateSightingCount with initial year:', yearId);
          updateSightingCount(yearId);
        }

        // Update count when year changes
        $element.on('change', function () {
          const selectedYear = $(this).val();
          console.log('Year changed to:', selectedYear);
          if (selectedYear) {
            updateSightingCount(selectedYear);
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
