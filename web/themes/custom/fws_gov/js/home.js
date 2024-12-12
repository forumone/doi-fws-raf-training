(function ($, Drupal) {

    'use strict';

    //featured facilities are at the bottom of the home page relies on slick slider which is added in the main theme
    Drupal.behaviors.featured_facilities = {
        attach: function (context) {
            $('.block-type-block-full-width-slider .featured-facility-wrapper', context).slick({
                arrows: true,
                prevArrow: '<div class="wrap-arrow left"><button id="prev" type="button" class="prev fa fa-angle-left"></button></div>',
                nextArrow: '<div class="wrap-arrow right"><button id="next" type="button" class="next fa fa-angle-right"></button></div>',
                dots: true,
                responsive: [{
                    breakpoint: 991,
                    settings: {
                        arrows: false
                    }
                },]
            });
        }
    };

})(jQuery, Drupal);