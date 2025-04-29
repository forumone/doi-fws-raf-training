/**
 * @file
 * JavaScript to add print view mode classes to the body tag.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * Adds print view mode class to the body element.
   */
  Drupal.behaviors.fwsPrintBodyClass = {
    attach: function (context, settings) {
      // Only run once
      $(context).once('fws-print-body-class').each(function () {
        // Check if we should add the body class
        if (drupalSettings.fwsPrint && drupalSettings.fwsPrint.addBodyClass) {
          $('body').addClass('node-view-mode-print');
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
