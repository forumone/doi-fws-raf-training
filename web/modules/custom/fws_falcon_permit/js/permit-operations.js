/**
 * @file
 * JavaScript behaviors for permit operations select field.
 */
(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.permitOperations = {
    attach: function (context, settings) {

      once('operation-select', '.operation-select', context).forEach(function (operationSelect) {
        $(operationSelect).on('change', function () {
          var operation = $(this).val();
          var nodeId = $(this).data('node-id');

          if (operation) {
            var url;

            switch (operation) {
              case 'view':
                url = '/node/' + nodeId;
                break;
              case 'edit':
                url = '/node/' + nodeId + '/edit';
                break;
              case 'delete':
                url = '/node/' + nodeId + '/delete';
                break;
            }

            if (url) {
              window.location.href = url;
            }
          }
        });
      })
    }
  };

})(jQuery, Drupal);
