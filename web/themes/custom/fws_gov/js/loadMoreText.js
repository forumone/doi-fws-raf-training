(function($, Drupal) {
  $.fn.loadMoreText = function( options ) {
    // symbol is the number of characters to show before trimming
    let settings = $.extend({
      symbol: 240,
    }, options);

    // variables
    let $loadMoreText = $(this);

    $(once('load-more', $loadMoreText)).each(function (i, el) {

      let $ellipsestext = settings.ellipses ? settings.ellipses : '...';
      let $content = $(this).html();

      // Trim text if necessary
      if ($content.length > settings.symbol) {
        let truncatedContent = $content.substr(0, settings.symbol);
        let fullContent = $content;
        let html = '<div class="truncate-text" style="display:block">' + truncatedContent ; 
        if(settings.ellipses != '') {
          html += '<span class="moreellipses">' + $ellipsestext + '</span>'; 
        }
        html += `<a href="#" class="load-more__btn load-text">${Drupal.t(settings.moreText ? settings.moreText : 'More')} <span class="caret"></span></a>`;
        html += '</div>';
        lessHtml = `<a href="#" class="load-less__btn load-text">${Drupal.t(settings.lessText ? settings.lessText : 'Less')} <span class="caret-up"></span></a>`;
        html += '<div class="truncate-text" style="display:none">';
        if(settings.lessLocation == 'top'){
          html += lessHtml + fullContent;
        }else{
          html += fullContent + lessHtml;
        }
        html += '</div>';

        $(this).html(html);
      }

      // Show/hide text.
      $(once('load-more-text', '.load-text')).click(function (e) {
        e.preventDefault();
        let thisEl = $(this);
        let closestTruncateTextElement = thisEl.closest(".truncate-text");
        let truncateClass = ".truncate-text";
        if (thisEl.hasClass("load-less__btn")) {
          closestTruncateTextElement.prev(truncateClass).toggle();
          closestTruncateTextElement.hide();
        } else {
          closestTruncateTextElement.toggle();
          if(settings.noFade){
            closestTruncateTextElement.next(truncateClass).toggle();
          }else{
            closestTruncateTextElement.next(truncateClass).fadeToggle();
          }
        }
        return false;
      });
    });
  };

})( jQuery, Drupal );
