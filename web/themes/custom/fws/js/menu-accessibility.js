alert('menu-accessibility');

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.menuAccessibility = {
    attach: function (context, settings) {

      // Find all submenu buttons
      $(once('menu-accessibility', '[data-submenu-button]', context)).each(function() {
        const $submenuButton = $(this);
        const $menuItem = $submenuButton.closest('.mb-item');

        // Find the associated menu link with the title
        const $menuLink = $menuItem.find('a.we-mega-menu-li[data-menu-title]');

        if ($menuLink.length) {
          const menuTitle = $menuLink.data('menu-title');
          const menuLinkId = $menuLink.attr('id');

          // Create unique IDs for this submenu
          const submenuTextId = 'submenu-text-' + menuTitle.toLowerCase().replace(/\s+/g, '-').replace(/[^\w-]+/g, '-');

          // Find the span that says "submenu"
          const $submenuText = $submenuButton.find('.visible-hidden');

          // Set ID on the span
          $submenuText.attr('id', submenuTextId);

          // Set aria-labelledby to reference both IDs
          $submenuButton.attr('aria-labelledby', menuLinkId + ' ' + submenuTextId);
        }
      });
    }
  };

})(jQuery, Drupal);
