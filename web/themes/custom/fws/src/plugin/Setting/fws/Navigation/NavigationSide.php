<?php

namespace Drupal\fws\Plugin\Setting\fws\Navigation;

use Drupal\bootstrap\Plugin\Setting\SettingBase;

/**
 * IF YOU ARE EDITING THIS FILE, YOU MUST CHANGE THE FILENAME AND CLASS NAME FOR THE CHANGES
 * TO TAKE EFFECT, OR THE 'ID' IN THE ANNOTATION. I HAVE NO IDEA WHY, BUT CLEARING THE CACHES WILL NOT WORK AND YOU WILL 
 * NOT BE ABLE TO SEE THE CHANGE YOU HAVE MADE.
 * 
 * Main navigation side.
 *
 * @ingroup plugins_setting
 *
 * @BootstrapSetting(
 *   id = "fws_main_navigation_side",
 *   type = "checkbox",
 *   title = @Translation("Main Navigation Left"),
 *   defaultValue = 0,
 *   description = @Translation("Check this box to have the main navigation floated left, and leave it uncheck to have it floated right"),
 *   groups = {
 *     "fws" = @Translation("FWS Theme"),
 *     "navigation" = @Translation("Navigation"),
 *   },
 * )
 */
class NavigationSide extends SettingBase {}
