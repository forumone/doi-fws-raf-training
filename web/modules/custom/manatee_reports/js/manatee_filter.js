(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.manateeFilter = {
    attach: function (context, settings) {
      once('manateeFilter', '.facility-filter', context).forEach(function (element) {
        $(element).on('change', function () {
          const selectedFacility = $(this).val();
          const rows = $('.manatee-report-table tbody tr');
          const tbody = $('.manatee-report-table tbody');
          let visibleRows = 0;

          // Remove existing no results message if it exists
          $('.no-manatees-message').remove();

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
            const messageRow = $('<tr class="no-manatees-message"><td colspan="7">No captive manatees found for this organization</td></tr>');
            tbody.append(messageRow);
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
