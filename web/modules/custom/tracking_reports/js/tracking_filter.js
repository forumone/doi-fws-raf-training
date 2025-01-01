(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.facilityFilter = {
    attach: function (context, settings) {
      once('facility-filter', '#facility-filter-select', context).forEach(function (element) {
        $(element).on('change', function() {
          $(this).closest('form').submit();
        });
      });
    }
  };

  Drupal.behaviors.trackingReportsAddEvent = {
    attach: function (context, settings) {
      once('tracking-reports-add-event', '.add-event-select', context).forEach(function (element) {
        $(element).on('change', function (e) {
          const selectedType = $(this).val();
          const speciesId = $(this).data('species-id');

          if (selectedType) {
            window.location.href = `${drupalSettings.trackingReports.baseUrl}${selectedType}?edit[field_species_ref][widget][0][target_id]=${speciesId}`;
          }
        });
      });
    }
  };
})(jQuery, Drupal, once);
