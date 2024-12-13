(function($, Drupal) {
  $.fn.loadMore = function( options ) {
    // Settings.
    let settings = $.extend({
      count: 5,
      itemsToLoad: -1,
      btnHTML: '',
      btnLessHTML: '',
      item: ''
    }, options);

    // variables
    let $loadMore = $(this);

    // Run through all the elements.
    $loadMore.each(function(i, el) {

      // variables.
      let $thisLoadMore = $(this);
      let $items        = $thisLoadMore.find(settings.item);
      let btnHTML       = settings.btnHTML ? settings.btnHTML : `<a href="#" class="load-more__btn">${Drupal.t('More')} <span class="caret"></span></i></a>`;
      let btnLessHTML       = settings.btnLessHTML ? settings.btnLessHTML : `<a href="#" class="load-less__btn">${Drupal.t('Less')} <span class="caret-up"></span></a>`;
      let $btnHTML      = $(btnHTML);
      let $btnLessHTML      = $(btnLessHTML);
      let itemsToLoad   = settings.itemsToLoad;

      // If options.itemsToLoad is not defined, then assign settings.count to it
      if ( ! options.itemsToLoad || isNaN( options.itemsToLoad ) ) {
        settings.itemsToLoad = settings.count;
      }

      // Add classes
      $thisLoadMore.addClass('load-more');
      $items.addClass('load-more__item');

      // Add button.
      if ( ! $thisLoadMore.find( '.load-more__btn' ).length && $items.length > settings.count ) {
        $thisLoadMore.append( $btnHTML, $btnLessHTML );
      }

      let $btn = $thisLoadMore.find( '.load-more__btn' );

      // Check if button is not present. If not, then attach $btnHTML to the $btn letiable.
      if ( ! $btn.length ) {
        $btn = $btnHTML;
      }

      if ( $items.length > settings.count ) {
        $items.slice(settings.count).hide();
        $btnLessHTML.hide();
      }

      // Add click event on button.
      $btn.on('click', function(e) {
        e.preventDefault();

        let $this = $(this);
        let $hiddenItems = $items.filter(':hidden');
        let $updatedItems = $hiddenItems;

        if ( settings.itemsToLoad !== -1 && settings.itemsToLoad > 0 ) {
          $updatedItems = $hiddenItems.slice(0, settings.itemsToLoad);
        }

        // Show the selected elements.
        if ( $updatedItems.length > 0 ) {
          $updatedItems.fadeIn();
          $btnLessHTML.fadeIn();
          $this.hide();
        }

        // Hide the 'More' button
        // OR if the settings.itemsToLoad is set to -1.
        if ( $hiddenItems.length <= settings.itemsToLoad || settings.itemsToLoad === -1 ) {
          $this.remove();
        }
      });

      // Hide the 'count' items.
      $btnLessHTML.on('click', function(e) {
        e.preventDefault();

        // Hidden count items.
        $items.slice(settings.count).fadeOut(200);

        // Hidden  'Less' btn.
        $(this).hide();

        // Show more btn.
        $btn.show();
      });
    });
  };

}( jQuery, Drupal ));
