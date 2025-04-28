(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.fwsSightingCount = {
    attach: function (context, settings) {
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
          return;
        }

        // Get current date filter values
        const filters = getFilterValues();

        // Build the URL with query parameters using Drupal.url()
        let url = Drupal.url('api/sighting-count/' + yearId);
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
            const countElement = $('.block-fws-sighting-count .fws-sighting-count');
            if (response.error) {
              countElement.text('Error: ' + response.error);
            } else {
              countElement.text(response.count + ' cranes');
            }
          },
          error: function (xhr, status, error) {
            $('.block-fws-sighting-count .fws-sighting-count').text('Error loading count');
          }
        });
      };

      // Handle the year filter changes
      const yearSelect = $('#edit-field-year-target-id--2', context);

      once('fwsSightingCount', '#edit-field-year-target-id--2', context).forEach(function (element) {
        const $element = $(element);

        // Get initial count on page load
        const yearId = $element.val();

        if (yearId) {
          updateSightingCount(yearId);
        }

        // Update count when year changes
        $element.on('change', function () {
          const selectedYear = $(this).val();
          if (selectedYear) {
            updateSightingCount(selectedYear);
          }
          // When using the year filter, reset the dates filters.
          $('#edit-field-date-time-value--2').val('');
          $('#edit-field-date-time-value-2--2').val('');
        });
      });

      // Handle start date filter changes
      once('startDateFilter', '#edit-field-date-time-value--2', context).forEach(function (element) {
        $(element).on('change', function () {
          // When using the dates filters, reset the year filter to All.
          $('#edit-field-year-target-id--2').val('All');
          updateSightingCount('All');
        });
      });

      // Handle end date filter changes
      once('endDateFilter', '#edit-field-date-time-value-2--2', context).forEach(function (element) {
        $(element).on('change', function () {
          // When using the dates filters, reset the year filter to All.
          $('#edit-field-year-target-id--2').val('All');
          updateSightingCount('All');
        });
      });
    }
  };
})(jQuery, Drupal, once);
