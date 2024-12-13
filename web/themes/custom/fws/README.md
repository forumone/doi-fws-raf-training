# FWS base theme

This theme is intended to give a good start on what a standard FWS site looks like, while allowing things to be overriden in a parent theme if need be. The FWS base theme is based on bootstrap and compiles it own bootstrap source. If you wish to create a parent them, it should use the FWS base theme as a parent (base). 

## Base theme Settings

There are a few settings that need to be changed when you enable the theme and visit `admin/appearance/settings/fws` 

### General Vertical tab
Make sure that `Fluid Container` is unchecked inside of the `container` fieldset. 

### CDN Vertical tab
Change the `CDN Provider` dropdown to `None (compile locally)`. 

### FWS Theme Vertical tab
The `Navigation` fieldset contains a checkbox to override the default orientation of the main menu, and instead flox it to the left. This is optional to set. 

The `Search` fieldset also contains a single checkbox, and should ONLY be checked if you are actually using the serach form in the navigation collapsible region similar to the fws.gov site. As the help text explain, this will not actually place the block for you, but will create the appropriate margins to allow the search to display properly. If you are not using the search form, do not check the box. 

### Logo and Favicon

Finally, at the bottom left you can update the logo by overriding the global settings. You can either update the global settings, or update them on the fws theme. Either way, update the `Logo Image` and `Favicon` paths by using the following:

```
logo path: themes/custom/fws/images/logo/logo.svg
favicon path: themes/custom/fws/favicon.ico
```

## Mega Menus

It is recommended that you enable the `we_megamenu` drupal contrib module, even if there are not plans to utilize mega menus. The bulk of the stlying for the main menu is based on the configuration and markup from the `we_megamenu` module. It is not required, but you may have to do additional styling to get things to look property if you choose not to use it. 

### Mega Menu Version

The version of the mega menu module has been pinned to a very specific version (8.x-1.7) that was in use during the development of the theme. The reason this module is locked down while other contrib modules are not, is due to the amount of stlying that is dependant on the output of the markup of this module, and its default styling. To upgrade to another version will require some testing to make sure things were not changed that would have an impact on this theme. 

### Mega Menu Customizations 

In addition to quite a lot of styling (most of which is in _main-nav.scss), there are a few other customizations that should be noted. The FWS base them overrides the mega menu front end library with the following:

```
libraries-override:
  we_megamenu/form.we-mega-menu-frontend:
    css:
      theme:
        #this removes this library as we are already compiling bootstrap css and don't need it twice
        assets/includes/bootstrap/css/bootstrap.min.css: false
    js:
      assets/js/we_megamenu_frontend.js: js/we_megamenu_frontend.js
      assets/js/we_mobile_menu.js: js/we_mobile_menu.js
```

The css section is just telling it not to load the `bootstrap.min.css` file because we are already supplying bootstrap and don't need a second copy. 

The Javascript section is overriding the two JS files that would be supplied by mega menu for the front end library. These 2 files were copies and brought in to the FWS base theme. All changes are commented thoroguhly in the files, but essentially the reason for doing this was to combine the "hamburger" mobile menu controls from both bootstrap base theme and mega menu into a single control that has the behavior of both. 

### Enable Mega Menus and Update Main Naviation Block

In order to use the `we_megamenu` module you must download it and turn it on like any other drupal module. 

After that, you need to replace the standard main menu block with the one provided by `we_megamenu`. To do this, simply head over the `/admin/structure/block` page and remove the main navigation block and replace it with the main naviation block that has a Category for `Druapl 8 Mega Menu`. 

### Mega Menu Configuration

To actually configure the Mega Menu you will use the page located at `/admin/structure/we-mega-menu`. This page is for configuring any of the menus, but you will likely only ever need to use the one for Main naviation. For an overview of how Mega Menus work, you can see the documenation at `https://www.weebpal.com/guides/megamenu-d8-documentation`. 

At a minimum, you will need to change some of the basic settings on the default page when you click on `config` on the main menu. You need to make the settings look like:

```
Style: Default
Animation: Fading
Action: Clicked
Auto arrow: Off
Show submenu: On
Mobile collapse: Off
```

Click save. 

### Adding a Home Icon to the Main Menu

The fws.gov site uses a home icon as the first entry of the main menu after usability testing showed that a high percentatge of people didn't realize that clicking on the logo or header title would link home. If you want to use a house icon, you simply need to call the class `home` to a menu link (ideally the first one). In order to achieve this, you'll need to visit the mega menu configuration page at `/admin/structure/we-mega-menu`. Click `config` on the main menu. Click to select the menu item you wish to modify and add class of `home` in the Extra Class textfield. Save it and this menu item will get styled using a `:before` font awesome icon of a house. The text will be hidden. 

You may notice that there is a textfield to add an icon to a menu entry, but this will not hide the link text, which is why I chose to use the class of `home` instead. If you do choose to use this option, you need to type in the font awesome class, such as `fa-home` for example. 