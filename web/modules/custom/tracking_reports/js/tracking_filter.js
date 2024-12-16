(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.trackingFilter = {
    attach: function (context, settings) {
      once('trackingFilter', '.facility-filter', context).forEach(function (element) {
        $(element).on('change', function () {
          const selectedFacility = $(this).val();
          const rows = $('.tracking-report-table tbody tr');
          const tbody = $('.tracking-report-table tbody');
          let visibleRows = 0;

          // Remove existing no results message if it exists
          $('.no-results-message').remove();

          rows.each(function () {
            if (selectedFacility === 'all' || $(this).attr('data-facility') === selectedFacility) {
              $(this).show();
              visibleRows++;
            } else {
              $(this).hide();
            }
          });

          // Show message if no rows are visible
          if (visibleRows === 0) {
            const messageRow = $('<tr class="no-results-message"><td colspan="7">No results found for this organization</td></tr>');
            tbody.append(messageRow);
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
