(function ($, Drupal, once) {
  'use strict';

  console.log('=== Sighting Count JavaScript Loaded ===');

  Drupal.behaviors.fwsSightingCount = {
    attach: function (context, settings) {
      console.log('=== Sighting Count Behavior Attaching ===');

      // Function to get current filter values
      const getFilterValues = function () {
        const startDate = $('#edit-field-date-time-value--2').val();
        const endDate = $('#edit-field-date-time-value-2--2').val();
        return {
          startDate: startDate || '',
          endDate: endDate || ''
        };
      };

      // Function to update the sighting count
      const updateSightingCount = function (yearId) {
        if (!yearId) {
          console.log('No year ID provided');
          return;
        }

        // Get the year text for logging
        const yearText = $('#edit-field-year-target-id--2 option[value="' + yearId + '"]').text();
        console.log('Updating count for year:', yearText, '(ID:', yearId, ')');

        // Get current date filter values
        const filters = getFilterValues();
        console.log('Current filters:', filters);

        // Build the URL with query parameters
        let url = '/api/sighting-count/' + yearId;
        const params = [];
        if (filters.startDate) {
          params.push('start_date=' + encodeURIComponent(filters.startDate));
        }
        if (filters.endDate) {
          params.push('end_date=' + encodeURIComponent(filters.endDate));
        }
        if (params.length > 0) {
          url += '?' + params.join('&');
        }

        $.ajax({
          url: url,
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

      // Handle start date filter changes
      once('startDateFilter', '#edit-field-date-time-value--2', context).forEach(function (element) {
        $(element).on('change', function () {
          const yearId = $('#edit-field-year-target-id--2').val();
          if (yearId) {
            console.log('Start date changed, updating count');
            updateSightingCount(yearId);
          }
        });
      });

      // Handle end date filter changes
      once('endDateFilter', '#edit-field-date-time-value-2--2', context).forEach(function (element) {
        $(element).on('change', function () {
          const yearId = $('#edit-field-year-target-id--2').val();
          if (yearId) {
            console.log('End date changed, updating count');
            updateSightingCount(yearId);
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
