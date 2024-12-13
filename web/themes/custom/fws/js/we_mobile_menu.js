(function ($) {

    "use strict";
  
    $.fn.mobileMenu = function (options) {
      var settings = $.extend({
        targetWrapper: '.navbar-we-mega-menu',
        accordionMenu: 'true',
        toggledClass: 'toggled',
        pageSelector: 'body'
      }, options);
  
      if ($(window).width() <= 991) {
        $(settings.targetWrapper).addClass('mobile-main-menu');
      }
  
      var toggleButton = this;
  
      $(window).resize(function () {
        if ($(window).width() <= 991) {
          $(settings.targetWrapper).addClass('mobile-main-menu');
        } else {
          $(settings.targetWrapper).removeClass('mobile-main-menu');
          $('body').css('overflow', '');
          $('body').css('height', '');
          $('body').css('position', '');
          $(settings.pageSelector).removeClass(settings.toggledClass);
          $(settings.pageSelector).find('.overlay').remove();
          $(settings.pageSelector).css('position', '');
          item.removeClass('open');
          item.find('ul').css('display', '');
        }
      });
  
      function _weMegaMenuClear() {
        var wrapper = $(settings.pageSelector);
        var overlay = wrapper.find('.overlay');
        overlay.remove();
        wrapper.css({
          'width': '',
          'position': ''
        });
        wrapper.removeClass(settings.toggledClass);
        wrapper.find('div.region-we-mega-menu nav').removeClass('we-mobile-megamenu-active');
  
        if (overlay.length > 0) {
          wrapper.find('.btn-close').remove();
          overlay.remove();
          $('body').css('overflow', '');
          $('body').css('height', '');
          $('body').css('position', '');
        }
      }
  
      this.off('click.mobileMenu');
      this.on('click.mobileMenu', function (e) {
        /* START CUSTOMIZATION
        * this line needs to get the main mega menu, and the orginal source was designed
        * to work with multiple mega menus, so it would take the original we mega menu hamburger
        * (the item clicked) and find the closest mega menu. Since we only have one and changed
        * the clickable item to the bootstrap hamburger, this line needed editing to get the 
        * right menu. 
        * Orignal line 
        * var targetWrapper = $(this).closest('div.region-we-mega-menu').find('nav.navbar-we-mega-menu');
        * */
        var targetWrapper = $('div.region-we-mega-menu').find('nav.navbar-we-mega-menu');
        // END CUSTOMIZATION
        
        var wrapper = $(settings.pageSelector);
        if (!wrapper.hasClass(settings.toggledClass)) {
          wrapper.addClass(settings.toggledClass).css('position', 'relative');
          $(settings.targetWrapper).addClass('mobile-main-menu');
          targetWrapper.addClass('we-mobile-megamenu-active');
          if (wrapper.find('.overlay').length == 0) {
            var overlay = $('<div class="overlay"></div>');
            overlay.prependTo(wrapper);
            overlay.click(function () {
              _weMegaMenuClear();
            });
            $('body').css('overflow', 'hidden');
            $('body').css('btn-close', 'hidden');
            $('body').css('height', '100%');
            $('body').css('position', 'relative');
          }
          if (wrapper.find('.btn-close').length == 0) {
            var btnClose = $('<span class="btn-close"></span>');
            btnClose.prependTo(wrapper);
  
            $('.btn-close').on('click', function (e) {
              _weMegaMenuClear();
              /* START CUSTOMIZATION
              * Since the bootstrap button is opening both the region-collapsible and the mega menu
              * when the close button is closed, it needs to close the region-collapsible as well.
              * so this will toggle that class to do so. 
              */
              $("#navbar-collapse").removeClass('in');
              //this gives the header a darker gradient, remove it when menu is closed.
              $('body').removeClass('mega-menu-open')
              // END CUSTOMIZATION
              e.preventDefault();
              return false;
            });
          }
  
        } else {
          _weMegaMenuClear();
        }
        e.preventDefault();
        /* START CUSTOMIZATION
        * Since the bootstrap button is opening both the region-collapsible and the mega menu
        * we cannot allow propagation to stop, we need to allow it to continue so bootstrap will
        * open up region-collaspisble. So i commented this line out.  
        */
        //e.stopPropagation();
        /* END CUSTOMIZATION */
      });
  
      if (settings.accordionMenu == 'true') {
        /* START CUSTOMIZATION
        * this line needs to get the main mega menu, and the orginal source was designed
        * to work with multiple mega menus, so it would take the original we mega menu hamburger
        * (the item clicked) and find the closest mega menu. Since we only have one and changed
        * the clickable item to the bootstrap hamburger, this line needed editing to get the 
        * right menu. 
        * Orignal line 
        * var targetWrapper = $(this).closest('div.region-we-mega-menu').find('nav.navbar-we-mega-menu');
        * */
        var targetWrapper = $('div.region-we-mega-menu').find('nav.navbar-we-mega-menu');
        // END CUSTOMIZATION
        var menu = $(targetWrapper).find('ul.we-mega-menu-ul').first();
        var item = menu.find('> li[data-submenu=1]');
        var item_active = menu.find('> li[data-submenu=1].active');
        if ($(window).width() <= 991) {
          item_active.addClass('open');
          item_active.find('> ul').css('display', 'block');
        }
        item.click(function (e) {
          if ($(window).width() <= 991) {
            var $this = $(this);
            var $sub_menu_inner = $this.find('> .we-mega-menu-submenu');
            if (!$this.hasClass('open')) {
              $(item).not($this).removeClass('open');
              item.find('> .we-mega-menu-submenu').slideUp();
              $this.toggleClass('open');
              if ($this.hasClass('open')) {
                $sub_menu_inner.slideDown();
                setTimeout(function () {
                  $(targetWrapper).animate({
                    scrollTop: $this.offset().top
                  }, 700);
                }, 500);
  
              } else {
                $sub_menu_inner.slideUp();
              }
              return false;
            }
          }
        });
      }
    }
  
  }(jQuery));