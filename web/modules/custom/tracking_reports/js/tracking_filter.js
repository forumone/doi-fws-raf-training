(function ($, Drupal, once) {
  'use strict';

  Drupal.behaviors.clientSideTableSort = {
    attach: function (context, settings) {
      once('client-side-sort', '.tracking-report-table th.sortable', context).forEach(function (header) {
        $(header).on('click', function(e) {
          // Prevent default server-side sort
          e.preventDefault();
          
          const table = $(this).closest('table');
          const rows = table.find('tr:gt(0)').toArray();
          const index = $(this).index();
          const isAsc = !$(this).hasClass('is-active') || $(this).attr('aria-sort') === 'descending';
          
          // Update sort indicators
          table.find('th').removeClass('is-active').removeAttr('aria-sort');
          $(this).addClass('is-active').attr('aria-sort', isAsc ? 'ascending' : 'descending');
          
          // Sort rows
          rows.sort(function(a, b) {
            const aValue = $(a).find('td').eq(index).text().trim();
            const bValue = $(b).find('td').eq(index).text().trim();
            
            // Parse dates in YYYY-MM-DD format
            if (aValue.match(/^\d{4}-\d{2}-\d{2}$/)) {
              return isAsc ? 
                new Date(aValue) - new Date(bValue) : 
                new Date(bValue) - new Date(aValue);
            }
            
            // Parse "X yr, Y mo" format
            if (aValue.match(/(\d+)\s*yr(,\s*(\d+)\s*mo)?/)) {
              const aMonths = aValue.match(/(\d+)\s*yr(,\s*(\d+)\s*mo)?/).slice(1)
                .filter(x => x !== undefined && !x.includes(','))
                .reduce((acc, val, i) => acc + (i === 0 ? parseInt(val) * 12 : parseInt(val)), 0);
              const bMonths = bValue.match(/(\d+)\s*yr(,\s*(\d+)\s*mo)?/).slice(1)
                .filter(x => x !== undefined && !x.includes(','))
                .reduce((acc, val, i) => acc + (i === 0 ? parseInt(val) * 12 : parseInt(val)), 0);
              return isAsc ? aMonths - bMonths : bMonths - aMonths;
            }
            
            // Parse "X kg, Y cm" format
            if (aValue.includes('kg')) {
              const aNum = parseFloat(aValue);
              const bNum = parseFloat(bValue);
              if (!isNaN(aNum) && !isNaN(bNum)) {
                return isAsc ? aNum - bNum : bNum - aNum;
              }
            }
            
            // Default string comparison
            return isAsc ? 
              aValue.localeCompare(bValue) : 
              bValue.localeCompare(aValue);
          });
          
          // Reattach sorted rows
          table.find('tr:gt(0)').remove();
          table.append(rows);
        });
      });
    }
  };

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

  Drupal.behaviors.searchAutoSubmit = {
    attach: function (context, settings) {
      once('search-auto-submit', '[name="search"]', context).forEach(function (element) {
        let timer;
        $(element).on('input', function() {
          clearTimeout(timer);
          // Add a small delay to prevent too many submissions while typing
          timer = setTimeout(() => {
            $(this).closest('form').submit();
          }, 500);
        });
      });
    }
  };
})(jQuery, Drupal, once);
