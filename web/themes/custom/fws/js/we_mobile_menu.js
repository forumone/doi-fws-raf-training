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

      // Add keyboard navigation trapping
      function trapFocus(e) {
        if (!$(settings.pageSelector).hasClass(settings.toggledClass)) return;

        var $focusableElements = $('#navbar-collapse').find('a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])');
        var $firstFocusable = $focusableElements.first();
        var $lastFocusable = $focusableElements.last();

        if (e.key === 'Tab') {
          // If there are no focusable elements, prevent default tab behavior
          if ($focusableElements.length === 0) {
            e.preventDefault();
            return;
          }

          // If shift + tab
          if (e.shiftKey) {
            // If the first focusable element receives focus, move to the last focusable element
            if (document.activeElement === $firstFocusable[0]) {
              e.preventDefault();
              $lastFocusable.focus();
            }
          }
          // If just tab
          else {
            // If the last focusable element receives focus, move to the first focusable element
            if (document.activeElement === $lastFocusable[0]) {
              e.preventDefault();
              $firstFocusable.focus();
            }
          }
        }
        if (e.key === 'Escape') {
          _weMegaMenuClear();
        }
      }

      $(window).resize(function () {
        if ($(window).width() <= 991) {
          $(settings.targetWrapper).addClass('mobile-main-menu');
        } else {
          $(settings.targetWrapper).removeClass('mobile-main-menu');
          $(settings.pageSelector).removeClass('mobile-menu-open');
          $(settings.pageSelector).removeClass(settings.toggledClass);
          $(settings.pageSelector).find('.overlay').remove();
          item.removeClass('open');
          item.find('ul').css('display', '');
          $(document).off('keydown', trapFocus);
          $('#navbar-collapse').attr('aria-expanded', 'false');
        }
      });

      function _weMegaMenuClear() {
        var wrapper = $(settings.pageSelector);
        var overlay = wrapper.find('.overlay');
        overlay.remove();
        wrapper.removeClass(settings.toggledClass);
        wrapper.removeClass('mobile-menu-open');
        wrapper.find('div.region-we-mega-menu nav').removeClass('we-mobile-megamenu-active');
        $(document).off('keydown', trapFocus);
        $('#navbar-collapse').attr('aria-expanded', 'false');

        if (overlay.length > 0) {
          wrapper.find('.btn-close').remove();
          overlay.remove();
        }
      }

      this.off('click.mobileMenu');
      this.on('click.mobileMenu', function (e) {
        var targetWrapper = $('div.region-we-mega-menu').find('nav.navbar-we-mega-menu');

        var wrapper = $(settings.pageSelector);
        if (!wrapper.hasClass(settings.toggledClass)) {
          wrapper.addClass(settings.toggledClass);
          wrapper.addClass('mobile-menu-open');
          $(settings.targetWrapper).addClass('mobile-main-menu');
          targetWrapper.addClass('we-mobile-megamenu-active');
          $(document).on('keydown', trapFocus);
          $('#navbar-collapse').attr('aria-expanded', 'true');

          if (wrapper.find('.overlay').length == 0) {
            var overlay = $('<div class="overlay mobile-menu-overlay"></div>');
            overlay.prependTo(wrapper);
            overlay.click(function () {
              _weMegaMenuClear();
            });
          }
          if (wrapper.find('.btn-close').length == 0) {
            var btnClose = $('<button class="btn-close menu__toggle"></button>');
            btnClose.prependTo(wrapper);

            $('.btn-close').on('click', function (e) {
              _weMegaMenuClear();
              $("#navbar-collapse").removeClass('in');
              $('body').removeClass('mega-menu-open');
              e.preventDefault();
              return false;
            });
          }
        } else {
          _weMegaMenuClear();
        }
        e.preventDefault();
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
