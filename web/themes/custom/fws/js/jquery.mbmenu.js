/**
 * jquery.mbmenu.js v1.0
 * modified from jquery.dlmenu.js v1.0 by codrops
 * http://www.codrops.com
 *
 * Licensed under the MIT license.
 * http://www.opensource.org/licenses/mit-license.php
 */
;
(function ($, window, undefined) {

  'use strict';

  // global
  var Modernizr = window.Modernizr,
    $body = $('body');


  $.mbmenu = function (options, element) {
    this.$el = $(element);
    this._init(options);
  };

  $.mbmenu.defaults = {
    animationClasses: {
      classin: 'mb-animate-in',
      classout: 'mb-animate-out'
    },
    // callback: click a link that has a sub menu
    // el is the link element (li)
    onLevelClick: function (el) {
      //return false;
    },
    // callback: click a link that does not have a sub menu
    // el is the link element (li); ev is the event obj
    onLinkClick: function (el, ev) {
      return false;
    }
  };

  $.mbmenu.prototype = {
    _init: function (options) {

      // options
      this.options = $.extend(true, {}, $.mbmenu.defaults, options);
      // cache some elements and initialize some variables
      this._config();

      var animEndEventNames = {
          'WebkitAnimation': 'webkitAnimationEnd',
          'OAnimation': 'oAnimationEnd',
          'msAnimation': 'MSAnimationEnd',
          'animation': 'animationend'
        },
        transEndEventNames = {
          'WebkitTransition': 'webkitTransitionEnd',
          'MozTransition': 'transitionend',
          'OTransition': 'oTransitionEnd',
          'msTransition': 'MSTransitionEnd',
          'transition': 'transitionend'
        };
      // animation end event name
      this.animEndEventName = animEndEventNames[Modernizr.prefixed('animation')] + '.mbmenu';
      // transition end event name
      this.transEndEventName = transEndEventNames[Modernizr.prefixed('transition')] + '.mbmenu',
        // support for css animations and css transitions
        this.supportAnimations = Modernizr.cssanimations,
        this.supportTransitions = Modernizr.csstransitions;

      this._initEvents();

    },
    _config: function () {
      this.open = false;
      this.$trigger = this.$el.children('.mb-trigger');
      this.$menucontainer = this.$el.children('.nav-container');
      this.$menu = this.$menucontainer.children('.mb-menu');
      this.$menuitems = this.$menu.find('li');
      this.$back = this.$menu.find('.mb-back');
    },
    _initEvents: function () {

      var self = this;

      this.$trigger.on('click.mbmenu', function () {

        if (self.open) {
          self._closeMenu();
        } else {
          self._openMenu();
        }
        return false;

      });

      // if on home page then set the menu accordingly so it shows
      // if(location.pathname == "/"){
        var mainul = this.$menucontainer.children('ul.mb-menu');
        if(!!mainul){
          mainul.addClass("home-page");
        }
      // }

      this.$menucontainer.find('.current-level').each(function (index, element) {

        var currentlevel = $(this).closest('li');
        var currentlevelLink = currentlevel.children('a').attr('href');
        var currentlevelText = currentlevel.children('a').html();
        var currentlevelHTML = '<a href="' + currentlevelLink + '">' + currentlevelText + '</a>'
        $(this).html(currentlevelHTML);

        var parentlevelText;
        var parentlevel = currentlevel.closest('ul');

        if (parentlevel.hasClass('mb-menu')) {
          parentlevelText = 'FWS Home';
        } else {
          parentlevelText = parentlevel.closest('li').children('a')[0].outerHTML;
        }
        $(this).siblings('.mb-back').find('.parent-level').html(parentlevelText);
        $('.parent-level').find('a').removeAttr('data-toggle').removeClass('dropdown-toggle');

      });

      //Menu Opening: EVERYTHING EXCEPT WE_MEGA_MENU AND UTILITY_MENU
      this.$menucontainer.find('.active > a:not(.utility-link):not(.we-mega-menu-li), .is-active:not(.utility-link):not(.we-mega-menu-li)').each(function (index, element) {
        if (!$(this).parent().parent().hasClass('mb-menu')) {
          $(this).closest('.mb-item').addClass('mb-subviewopen');
          $(this).closest('.mb-item').parents().closest('.mb-item').addClass('mb-subview');
          $('.mb-menu').addClass('mb-subview');
        }
      });
      //Menu Opening: UTILITY MENU
      //the utility menu operates a little differently than the other menu links
      //This opens the current mobile menu from the utility menu, if the user is on a utility menu item
      this.$menucontainer.find('.active > a.utility-link, .is-active.utility-link').each(function (index, element) {
        $(this).parents('.dropdown.active').addClass('mb-subviewopen');
        $('.mb-menu').addClass('mb-subview');
      });
      //Menu Opening: WE-MEGA-MENU
      //Handling the opening of we mega menus takes 2 steps, you have to add the mb-subviewopen to the last active
      //element and mb-subview to the first active element
      this.$menucontainer.find('li.we-mega-menu-li.active:last').each(function (index, element) {
        $('.mb-menu').addClass('mb-subview');
        var firstNonActiveItem = $(this).find('li.we-mega-menu-li.mb-item:not(.active):first > a').first();
        if(firstNonActiveItem.attr('href') === $(this).children('a').first().attr('href')){
          //mega menu doesn't put active on the a sub element if it's the same url as the parent
          var closestActive = $(this).closest('li.we-mega-menu-li.active');
          closestActive.addClass('mb-subviewopen');
          firstNonActiveItem.parent().addClass('active');
          closestActive.parents('li.we-mega-menu-li.active').addClass('mb-subview');
        }else{
          $(this).parent().closest('li.we-mega-menu-li.active').addClass('mb-subviewopen').parents('li.we-mega-menu-li.active').addClass('mb-subview');
        }
      });

      this.$menuitems.children('.nav-drilldown').on('click.mbmenu', function (event) {
        // Remove home-page as they drill down otherwise
        $(this).parents('ul.mb-menu.home-page').removeClass('home-page');
        event.stopPropagation();
        var $item = $(this).closest('li'),
          $submenu = $item.children('.mb-submenu');

        if ($submenu.length > 0) {

          var $flyin = $submenu.clone().addClass('mb-transparent').insertAfter(self.$menu),
            onAnimationEndFn = function () {
              self.$menu.off(self.animEndEventName).removeClass(self.options.animationClasses.classout).addClass('mb-subview');
              $item.addClass('mb-subviewopen');
              $item.parents().closest('.mb-subviewopen').removeClass('mb-subviewopen').addClass('mb-subview');
              $flyin.remove();
              setTimeout(function () {
                $('.mb-transparent').removeClass('mb-transparent');
              }, 100);
            };

          setTimeout(function () {
            $flyin.addClass(self.options.animationClasses.classin);
            self.$menu.addClass(self.options.animationClasses.classout);
            if (self.supportAnimations) {
              self.$menu.on(self.animEndEventName, onAnimationEndFn);
            } else {
              onAnimationEndFn.call();
            }

            self.options.onLevelClick($item);
          });

          return false;

        } else {
          self.options.onLinkClick($item, event);
        }

      });

      this.$back.find(' > .back-icon').on('click.mbmenu', function (event) {

        var $this = $(this),
          $submenu = $this.parent().parent(),
          $item = $submenu.parent(),

          $flyin = $submenu.clone().insertAfter(self.$menu).addClass('mb-transparent');
        setTimeout(function () {
          $('.mb-transparent').removeClass('mb-transparent');
        }, 100);

        var onAnimationEndFn = function () {
          self.$menu.off(self.animEndEventName).removeClass(self.options.animationClasses.classin);
          $flyin.remove();
        };

        $flyin.addClass(self.options.animationClasses.classout);
        self.$menu.addClass(self.options.animationClasses.classin);
        if (self.supportAnimations) {
          self.$menu.on(self.animEndEventName, onAnimationEndFn);
        } else {
          onAnimationEndFn.call();
        }

        self.options.onLevelClick($item);

        $item.removeClass('mb-subviewopen');
        $item.closest('mb-subviewopen').parent().removeClass('mb-subview');
        $item.closest('mb-subviewopen').removeClass('mb-subviewopen');
        if ($item.parent().hasClass('mb-menu')) {
          $item.parent().removeClass('mb-subview');
          $item.parent().parent().removeClass('mb-subviewopen');
        } else {
          $item.parent().addClass('mb-subview');
          $item.parent().closest('li').addClass('mb-subviewopen').removeClass('mb-subview');
        }
        if ($item.parent().parent().parent().hasClass('mb-menu')) {
          $item.parent().parent().parent().addClass('mb-subviewopen');
        } else {
          $item.parent().parent().parent().removeClass('mb-subview').addClass('mb-subviewopen');
        }

        return false;

      });

    },
    closeMenu: function () {
      if (this.open) {
        this._closeMenu();
      }
    },
    _closeMenu: function () {
      var self = this,
        onTransitionEndFn = function () {
          self.$menucontainer.off(self.transEndEventName);
          self._resetMenu();
        };

      this.$menucontainer.removeClass('mb-menuopen');
      this.$menucontainer.addClass('mb-menu-toggle');
      this.$trigger.removeClass('mb-active');
      $('.mobilenav-overlay').remove();
      $('body').removeClass('mobilenav-open');

      if (this.supportTransitions) {
        this.$menu.on(this.transEndEventName, onTransitionEndFn);
      } else {
        onTransitionEndFn.call();
      }

      this.open = false;
    },
    openMenu: function () {
      if (!this.open) {
        this._openMenu();
      }
    },
    _openMenu: function () {
      var self = this;
      // clicking somewhere else makes the menu close
      $body.off('click').on('click.mbmenu', function () {
        self._closeMenu();
      });
      $('.mobilenav-overlay').on('click', function () {
        self._closeMenu();
      });
      this.$menucontainer.addClass('mb-menuopen mb-menu-toggle').on(this.transEndEventName, function () {
        $(this).removeClass('mb-menu-toggle');
      });
      this.$trigger.addClass('mb-active');
      $('body').append('<div class="mobilenav-overlay"></div>');
      $('body').addClass('mobilenav-open');
      this.open = true;
    },
    // resets the menu to its original state (first level of options)
    _resetMenu: function () {
      this.$menu.removeClass('mb-subview');
      this.$menuitems.removeClass('mb-subview mb-subviewopen');
    }
  };

  var logError = function (message) {
    if (window.console) {
      window.console.error(message);
    }
  };

  $.fn.mbmenu = function (options) {
    if (typeof options === 'string') {
      var args = Array.prototype.slice.call(arguments, 1);
      this.each(function () {
        var instance = $.data(this, 'mbmenu');
        if (!instance) {
          logError("cannot call methods on mbmenu prior to initialization; " +
            "attempted to call method '" + options + "'");
          return;
        }
        if (!$.isFunction(instance[options]) || options.charAt(0) === "_") {
          logError("no such method '" + options + "' for mbmenu instance");
          return;
        }
        instance[options].apply(instance, args);
      });
    } else {
      this.each(function () {
        var instance = $.data(this, 'mbmenu');
        if (instance) {
          instance._init();
        } else {
          instance = $.data(this, 'mbmenu', new $.mbmenu(options, this));
        }
      });
    }
    return this;
  };

})(jQuery, window);
