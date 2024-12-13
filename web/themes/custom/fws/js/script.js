(function ($, Drupal) {

  'use strict';

  // Nav search toggle.
  Drupal.behaviors.search_toggle = {
    attach: function (context) {
      let $form = $('.search-block-form .input-group'),
        $close = $('.close-search'),
        $header = $('.navbar');

      $(once('search_toggle', $form, context)).on('click', function () {
        $header.addClass('search-bar-visible');
        $($form).find('.form-search').focus();
      });

      //  Close search.
      $(once('search_toggle', $close, context)).on('click', function () {
        $header.removeClass('search-bar-visible');
      });
    }
  };

  // Close submenu main navigation.
  Drupal.behaviors.fws_submenu = {
    DESKTOP_WIDTH: 992,
    // sub themes can replace/extend these functions to add needed functionality
    close_hook: function() {
    },
    resize_hook: function() {
    },
    attach: function (context) {
      let $close = $('<button class="close-menu"></button>'),
        $subMenu = $('.we-mega-menu-ul > .we-mega-menu-li > .we-mega-menu-submenu > .we-mega-menu-submenu-inner'),
        $body = $('body');

      var windowWidth = window.innerWidth;

      // Added ARIA attributes.
      $close.attr('aria-label', 'Close', 'aria-hidden', 'true');

      if (windowWidth >= Drupal.behaviors.fws_submenu.DESKTOP_WIDTH) {
        $(once('submenu_close', $subMenu)).append($close);
      }

      // Close submenu main navigation by using the close button
      $subMenu.on('click', '.close-menu', function () {
        $(this).closest('.we-mega-menu-li').removeClass('clicked');
        $body.removeClass('mega-menu-open');
        Drupal.behaviors.fws_submenu.close_hook();
      });


      // Clicking anyhwere off the mega menu closes it.
      $(once('submenu_close', 'body')).on('click', function () {
        $body.removeClass('mega-menu-open');
        Drupal.behaviors.fws_submenu.close_hook();
      });

      // This is the click handler that invokes the mega menu. This procedue should only be executed on the top
      // level main menu items. It mostly toggles a class on the body for stlying, but it also adjusts the margin
      // left of the mega menu submenu to make it stretch to the edge of the page.
      var firstSubmenuOpen = true;
      $(once('submenu_open', '.we-mega-menu-ul > .we-mega-menu-li > a.we-mega-menu-li', context)).on('click', function () {
        if (!$(this).closest('.we-mega-menu-li').hasClass('clicked')) {
          //this class gives header background stying and changes the overflow-x to hidden
          $body.addClass('mega-menu-open');
        } else {
          $body.removeClass('mega-menu-open');
        }

        /* This section is used to calculate the amount of space between the edge of the page and the container
         * because that is the same width that the mega submenu needs to have as a negative left margin. This whole
         * routine of caclulating the negative left margin needs to be put in a resize function and executed there
         * so that if the page is resized it will stay accurate. 
         * THE ADJUSTMENTS BELOW ARE ONLY NECESSARY IF YOU ARE USING ADMIN MENU - NOT REQUIRE IF NOT LOGGED IN
         */
        function _resize() {
          windowWidth = window.innerWidth;
          if (windowWidth >= Drupal.behaviors.fws_submenu.DESKTOP_WIDTH) {
            if ($body.hasClass('toolbar-horizontal')) {
              let $header_container = $('header > .container');
              let $header_offset = $header_container.offset();
              let $total_offset = ($header_offset.left) * -1;
  
              //this selector must get ONLY the first dropdown even if there are nested ones
              $('.we-mega-menu-ul.nav-tabs > li > .we-mega-menu-submenu').css('margin-left', $total_offset + "px");
            }
          }
          Drupal.behaviors.fws_submenu.resize_hook();
        }

        // If the window is desktop size, call the offset function.
        if (!$('.mobile-nav').length) {
          _resize();
        }

        if(firstSubmenuOpen) {
          // make sure the resize handler isn't repeatedly registered
          firstSubmenuOpen = false;
          // If the window is resized and is desktop size, call the offset function.
          $(window).resize(_resize);
        }
      });
    }
  };

  // Side navigation dropdowns.
  Drupal.behaviors.sideNavDropdowns = {
    attach: function (context) {
      var $menuSubpage = $('.menu--subpage', context);

      if ($menuSubpage.length === 0) {
        return;
      }

      $(document).on('click.sideNavToggle', '.dropdown-toggle', function () {
        var thisToggle = $(this);

        if (!thisToggle.siblings('ul.subnav').is(":visible")) {
          thisToggle.parent().addClass('open');
        } else {
          thisToggle.parent().removeClass('open');
        }
        thisToggle.siblings('ul.subnav').slideToggle();

      });

      $(document).find('.menu--subpage, .mb-submenu').find('li.dropdown >a').each(function (index, element) {
        if ($(this).hasClass('is-active')) {
          $(this).siblings('ul.subnav').css('display', 'block');
          $(this).parent().addClass('open').addClass('is-active');
        }
      });

    }
  }

  // Mobile navigation.
  Drupal.behaviors.mobile_navigation = {
    attach: function (context) {

      var windowWidth = window.innerWidth;

      // Function to create the mobile menu dynamically.
      var cloneMenu = function () {

        var ROOT_UUID = 'menu-parent-bottom-uuid'; //this is a made up uuid to use for reparenting to the root
        var mobileMenu = '<div id="mobile-nav" class="mobile-nav mb-menuwrapper"><div class="nav-container"><ul class="mb-menu">';
        var replaceParent = false;
        // If a sidebar menu exists, inject it into the chosen parent menu item
        var reparentMenuParts = [
            'ul.nav', // custom side menu
            'nav' // drupal side menu
            ].reduce(function(carry,baseSelector) {
                if(!carry) {
                    const s = $(`${baseSelector}[data-id-parent]:not([data-id-parent=""]`);
                    // check to see if we should replace the parent in the menu, rather than attache to it.
                    const r = $(`${baseSelector}[data-replace-parent]:not([data-replace-parent=""]`);
                    if(!!r.data('replace-parent')){
                      replaceParent = true;
                    }
                    if(!!s.data('id-parent')) {
                        const parentMenuIDElement = s;
                        // if it's the NAV version we want the ul child
                        const sideMenuElement = s.prop('tagName') === 'NAV'
                            ? parentMenuIDElement.children('ul')
                            : parentMenuIDElement;
                        const sideMenuLinkElement = sideMenuElement.children('li:first');
                        // if we have everything we need...
                        if(parentMenuIDElement.length && sideMenuElement.length && sideMenuLinkElement.length) {
                            carry = {parentMenuIDElement,sideMenuElement,sideMenuLinkElement};
                        }
                    }
                }
                return carry;
            },null);
        
        if (!!reparentMenuParts) {
          const {parentMenuIDElement,sideMenuElement,sideMenuLinkElement} = reparentMenuParts;
          var parentMenuID = parentMenuIDElement.data('id-parent');
          var sideMenuLink = sideMenuLinkElement.clone().html();
          var sideMenu = sideMenuElement.clone();

          var parentItem = $('li[data-id="' + parentMenuID + '"]');
          //the drilldown and sub-menu headers stick around on window resize so delete them
          parentItem.children('.menu-item-added-dynamically').remove();
          //we need to to know if there are siblings that we are reparenting to later on
          var hasSiblings = (parentItem.children().length > 1);

          //Should it reparent to the root, this is a static UUID used for the root
          if(parentMenuID === ROOT_UUID){
            parentItem.html(sideMenu.find('li:first').html());
          }
          sideMenu.find('li:first').remove();

          var sideMenuHTML = sideMenu.html();

          var subMenuHTML = '';

          if (!parentItem.hasClass('dropdown') && (!parentItem.hasClass('we-mega-menu-li') || (parentItem.hasClass('we-mega-menu-li') && !hasSiblings))) {
            //For Mega Menu we need this code if menu item is an only child of it's parent
            subMenuHTML += '<span class="nav-drilldown menu-item-added-dynamically"><i class="fa fa-angle-right" aria-hidden="true"></i><span class="visible-hidden">Forward</span></span> \
                            <div class="mb-submenu  menu-item-added-dynamically"> \
                              <div class="mb-back"> \
                                <a href="#" class="back-icon"><i class="fa fa-angle-left" aria-hidden="true"></i><span class="visible-hidden">Back</span></a> \
                                <span class="parent-level"></span> \
                              </div> \
                              <div class="current-level menu-item-added-dynamically"></div> \
                              <ul class="subul menu-item-added-dynamically reparented-no-siblings">';
          }else if (parentItem.hasClass('we-mega-menu-li')){
              //this is the code that is needed for wemegamenu if the menu item is a sibling of other menu items in it's parent
              subMenuHTML += '<span class="nav-drilldown  menu-item-added-dynamically"><i class="fa fa-angle-right" aria-hidden="true"></i><span class="visible-hidden">Forward</span></span> \
                              <div class="mb-submenu  menu-item-added-dynamically"> \
                                <ul class="subul reparented-with-siblings">';
          }

          //If it's the root (this uuid) then don't have 3 layers of menu
          if(parentMenuID === ROOT_UUID){
            subMenuHTML +=  sideMenuHTML;
          }else{ //then add a third layer menu
            if(replaceParent){
              subMenuHTML += sideMenuHTML;
            }else{
              subMenuHTML += '<li class="mobile-sidebar-item mb-item">' 
                  + sideMenuLink + 
                  '<span class="nav-drilldown"><i class="fa fa-angle-right" aria-hidden="true"></i><span class="visible-hidden">Forward</span></span> \
                  <div class="mb-submenu"> \
                    <div class="mb-back"> \
                      <a href="#" class="back-icon"><i class="fa fa-angle-left" aria-hidden="true"></i><span class="visible-hidden">Back</span></a> \
                      <span class="parent-level"></span> \
                    </div> \
                    <div class="current-level"></div> \
                    <ul class="nav nav-tabs subul">' + sideMenuHTML + '</ul> \
                  </div> \
                </li>';
            }
          }

          if (!parentItem.hasClass('dropdown')) {
            subMenuHTML += '</ul></div></div>';
            parentItem.append(subMenuHTML);
          } else {
            parentItem.find('> .mb-submenu > .subul').append(subMenuHTML);
          }
        }

        var megaMenuHTML = $('.we-mega-menu-ul').html();
        var utilityMenuHTML = $('.menu--utility').html();

        // Append the megamenu followed by the utility menu.
        mobileMenu += megaMenuHTML;
        mobileMenu += utilityMenuHTML;

        mobileMenu += '</ul></div></div>';

        // Watch for changes to the dom for the navbar to call the menu script
        var targetNode = document.querySelector('#navbar-collapse');

        if (targetNode) {

          var config = {
            childList: true,
            subtree: true
          };

          var callback = function (mutationsList) {

            for (var mutation of mutationsList) {
              if (windowWidth <= 991) {
                var mobileNav = $(document).find('.mobile-nav');

                mobileNav.find('.dropdown-menu').removeClass('dropdown-menu');

                mobileNav.mbmenu();
              }
              observer.disconnect();
            }

          };

          var observer = new MutationObserver(callback);

          observer.observe(targetNode, config);

          $('.navbar-collapse').append(mobileMenu);

        }

      }

      // If the window is tablet or lower, call the mobile menu function.
      if (windowWidth <= 991) {
        if (!$('.mobile-nav').length) {
          cloneMenu();
        }
      }

      // If the window is resized and is tablet or lower, call the mobile menu function or remove the menu.
      $(window).resize(function () {
        windowWidth = window.innerWidth;
        if (windowWidth <= 991) {
          if (!$('.mobile-nav').length) {
            cloneMenu();
          }
        } else {
          $('.mobile-nav').remove();
          $('.mobile-sidebar-item').remove();
          $('.menu-item-added-dynamically').remove();
        }
      });

    }
  };

})(jQuery, Drupal);