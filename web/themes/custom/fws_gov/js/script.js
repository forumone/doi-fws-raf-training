(function ($, Drupal, once) {

  'use strict';

  //function that checks too  see if mega menus submenu's need to have heights adjusted to keep the background correct. This
  // is a result of using absolute positioning on dropdown menus and the child menu could be taller than parent. 
  function fixMenuHeight() {

    let $subUl = $('.we-mega-menu-ul.nav-tabs > li.dropdown-menu > .we-mega-menu-submenu > .we-mega-menu-submenu-inner > .we-mega-menu-row > .we-mega-menu-col > ul.subul');
    

    //if desktop mega menus are open
    if($('body').hasClass('mega-menu-open')) {

      //click handlers used to open up the tertiary menus, which can be taller than the first level of navigation in the mega menu
      //and since its positioned absolutely, it won't increase the height of the overall mega menu background wrapper
      //so this will set the Sub menus height = to the subSubMenu height if its greater.
      $('.we-mega-menu-ul.nav-tabs > li.dropdown-menu > .we-mega-menu-submenu > .we-mega-menu-submenu-inner > .we-mega-menu-row > .we-mega-menu-col > ul.subul > li > a').on('click', function() {

        //the UL of the subnavigation (left side)
        let $currentSubUlHeight = $(this).parent().parent().height();
        //the div of the tertiary navigation.
        let $currentOpenSubSubmenuHeight = $(this).parent().find('.we-mega-menu-submenu').height();

        if($currentOpenSubSubmenuHeight > $currentSubUlHeight) {
          //the extra 20 is to give it some padding at the bottom
          $subUl.css('height', ($currentOpenSubSubmenuHeight + 20) + "px");
        }

      });
    }
    else {
      //reset the sub menus back to their natural height
      $subUl.css('height', 'auto');
    }
  };


  Drupal.behaviors.fws_submenu.close_hook = (function(super_close){
    return function() {
      super_close();
      fixMenuHeight();
    }
  })(Drupal.behaviors.fws_submenu.close_hook);

  Drupal.behaviors.fws_submenu.resize_hook = (function(super_resize){
    return function() {
      super_resize();
      if (window.innerWidth >= Drupal.behaviors.fws_submenu.DESKTOP_WIDTH) {
        fixMenuHeight()
      }
    }
  })(Drupal.behaviors.fws_submenu.resize_hook);

  //not sure if this is used anywhere, but its generic enough to stay here - BPW 4-11-2023
  Drupal.behaviors.disableLink = {
    attach: function (context) {
      $('a.disabled').on('click', function(e) {
        e.preventDefault();
      })
    }
  };

  // move banner image details links to below the image/rotator
  // there are 2 rotators, the standard ones on top of facilities that's paragraphs and the
  // one on top of news which is a view and has sligghtly differnet markup, that is why there are
  // 2 differnet append statements. The selector grabs the same outer markup, but the inner markup differs
  Drupal.behaviors.rotator_image_credit_fixer = {
    attach: function (context) {
      //small timeout is added around this to make sure slick slider has loaded
      setTimeout(function() {
        //let items = $('#banner .slick-list .slick-track .slick-slide');

        //loop through each banner image slide
        $(once('rotator-image-credit-fixer', '#banner .slick-list .slick-track .slick-slide', context)).each(function(index) {
          let element = $(this);
          let attribution = element.find('.attribution');
          //shows the attribution which was previously hidden before moving it
          attribution.addClass('show');
          // this is the append when its a paragraph slide
          element.find('.paragraph--type--slide').parent().append(attribution);
          // this is the append when its the news view
          element.find('.views-field-field-banner-image').parent().append(attribution);
        });
      }, 2000);
      
    }
  };

  // move attribution on embedded images, both captions and not captioned
  Drupal.behaviors.embedded_images_credit_fixer = {
    attach: function (context) {
      //gets both captioned and uncaptioned images
      //let items = $('.embedded-media-image');

      //there is a <figure> wrapper around captioned images, so the fix is not the same
      //for captioned images as it will be for non captioned, but .'embedded-media-image' 
      //will always be present
      //loop through selectors
      $(once('embedded-image-credit-fixer', '.embedded-media-image', context)).each(function(index) {
        let element = $(this);

        //captioned image
        if(element.parent().is("figure")){
          //for this case, just get the link inside the attribution div
          let attribution = element.find('.attribution a');
          //append it to to the figcaption used for captioned images
          element.parent().find('figcaption').append(' | ').append(attribution);
        }
        else {
          //not a captioned image
          let attribution = element.find('.attribution');
          //shows the attribution which was previously hidden before moving it
          attribution.addClass('show');
          element.find('.image-with-credit').append(attribution);
        }
        
      });
    }
  };

  // Image slider in site banner
  Drupal.behaviors.fws_banner = {
    attach: function (context) {
      $('#block-fwsbanner .field--name-field-banner-slideshow', context).slick({
        arrows: true,
        prevArrow: '<div class="wrap-arrow left"><button id="prev" type="button" class="prev fa fa-angle-left"></button></div>',
        nextArrow: '<div class="wrap-arrow right"><button id="next" type="button" class="next fa fa-angle-right"></button></div>',
        dots: true,
        responsive: [{
          breakpoint: 991,
          settings: {
            arrows: false
          }
        }, ]
      });
      if (context === document /*context.id === 'block-fwsbanner'*/ ) {
        // only seems will ever get context#block-fwsbanner if the block doesn't cache...
        // the banner contextual links will go to configuring the banner block which is useless
        // if possible update the link to edit the current entity instead which is more useful
        $(document).ready(function () {
          $('#block-fwsbanner>.block-container>.contextual>.contextual-links>.block-configure>a', context).each(function () {
            var link = $(this),
              href = link.attr('href');
            if (/\?destination=\//.test(href)) {
              href = href.replace(/^.*\?destination=/, '') + '/edit';
              link.attr('href', href);
              link.text('Edit');
            }
          });
        });
      }
    }
  };

  // Story and topics slider/tabs.
  Drupal.behaviors.story_and_topics_slider = {
    attach: function (context) {

      if ($(window).width() >= 768) {
        $('.stories .view-content, .topics .view-content').addClass('carousel');
      }

      $('.carousel', context).owlCarousel({
        speed: 500,
        nav: true,
        center: true,
        startPosition: 1,
        dots: true,
        mouseDrag: false,
        loop: false
      });

      //let stories = 0;
      // Dots index. Adds numbers inside of the dots
      $(once('story_and_topics_slider', '.stories > .view-content .owl-dot', context)).each(function (item) {
        $(this).text(++item);
        //stories++;
      });

      $('.stories > .view-header .tabs-item, .stories > .view-content').addClass('active');
    }
  };

  //some tooltips might not work if they aren't in the DOM when bootstrap calls tooltips
  //this can fix those instances
  Drupal.behaviors.tooltip = {
    attach: function (context) {

      //small timeout to give the DOM a second to render in areas where tooltips aren't working normally
      //not that you will likely also need to wrap your tooltips in an "<a href>" so that we can use the tabindexing of A elements. There is a click
      //handler htat will prevent the page from reloading on clicking. We set a trigger of focus (probably should be in the markup as well with a
      // data-trigger="focus" as well). When you click you are focusing the element as well, so this means if a user is tabbing the focus works, and so
      //does the clicking. 
      setTimeout(function() {
        const tooltips = once('tooltips','[data-toggle="tooltip"]');

        tooltips.forEach(el => {
          const $el = $(el);
          $el.tooltip({
            trigger: 'focus'
          });
          $el.on('click',e => {
            if(!e.target.href) {
              e.preventDefault();
            }
          });
        })

        $('#edit-keys').tooltip({
          trigger: 'focus',
          placement: 'left',
          delay: { "show": 500, "hide": 100 }
        });
        
      }, 1000);

      
    }
  };

  Drupal.behaviors.hideLeafletAttribution = {
    attach: function (context) {
      // Remove leaflet attribution control
      // Vlad said it's ok: https://groups.google.com/g/leaflet-js/c/fA6M7fbchOs/m/JTNVhqdc7JcJ
      $(context).on('leafletMapInit', function (e, settings, map, mapid, markers) {
        $('.leaflet-control-attribution').hide();
      })
    }
  }

})(jQuery, Drupal, once);