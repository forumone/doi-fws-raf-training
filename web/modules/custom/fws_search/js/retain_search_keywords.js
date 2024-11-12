(function ($, Drupal) {

    'use strict';

    Drupal.behaviors.retain_search_keywords = {
        attach: function () {
            function updateKeywords(value) {
                $('.menu--search-menu li a ').each(function() {
                            let href = $(this).attr('href');
                            const q = href.indexOf('?');
                            if(q !== -1) {
                                href = href.substring(0,q);
                            }
                            var newHref = href + '?$keywords=' + JSON.stringify(value);
                            $(this).attr('href', newHref);
                        });
            }
            const searchParams = (new URL(document.URL)).searchParams;
            // $keywords will be there from the search app, keys may be there if the user used the global search input
            let initial = searchParams.get('$keywords')
            if(!initial) {
                // this will happen if the user uses the global search box.
                initial = searchParams.get('keys');
                if(!!initial) {
                    // need to make it json since $keywords is serialized as JSON in the
                    // URL parameters
                    initial = JSON.stringify(initial);
                }
            }
            if(initial !== null) {
                updateKeywords(JSON.parse(initial));
            }
            let tries = 0;
            function findKeywordInput() {
                const input = $('mat-form-field.full-text input');
                if(input.length !== 1) {
                    if(tries++ > 50) {
                        return console.log('Unable to find keyword input field, giving up...');
                    }
                    setTimeout(findKeywordInput,250);
                } else {
                    input.on('input',function() {
                        updateKeywords(this.value);
                    });
                }
            }
            findKeywordInput();
        }
    };
})(jQuery, Drupal);