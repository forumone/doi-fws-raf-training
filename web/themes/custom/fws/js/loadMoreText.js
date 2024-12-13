(function($, Drupal) {
  $.fn.loadMoreText = function( options ) {

    let settings = $.extend({
      symbol: 240,
    }, options);

    // variables
    let $loadMoreText = $(this);

    $(once('load-more', $loadMoreText)).each(function (i, el) {

      let $ellipsestext = '...';
      let $content = $(this).html();

      // Trimmed text.
      if ($content.length > settings.symbol) {
        let c = $content.substr(0, settings.symbol);
        let h = $content;
        let html =
          '<div class="truncate-text" style="display:block">' +
          c  + '<span class="moreellipses">' + $ellipsestext + '</span>' + `<a href="#" class="load-more__btn load-text">${Drupal.t('More')} <span class="caret"></span></i></a>` + '</div>' +
          '<div class="truncate-text" style="display:none">' + h + `<a href="#" class="load-less__btn load-text">${Drupal.t('Less')} <span class="caret-up"></span></a>` + '</div>';

        $(this).html(html);
      }

      // Show/hide text.
      $(once('load-more-text', '.load-text')).click(function (e) {
        e.preventDefault();
        let thisEl = $(this);
        let cT = thisEl.closest(".truncate-text");
        let tX = ".truncate-text";


        if (thisEl.hasClass("load-less__btn")) {
          cT.prev(tX).toggle();
          cT.hide();
        } else {
          cT.toggle();
          cT.next(tX).fadeToggle();
        }
        return false;
      });
    });
  };

})( jQuery, Drupal );
