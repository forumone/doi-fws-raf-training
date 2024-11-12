<?php

namespace Drupal\fws\Plugin\Setting\fws\Search;

use Drupal\bootstrap\Plugin\Setting\SettingBase;

/**
 * IF YOU ARE EDITING THIS FILE, YOU MUST CHANGE THE FILENAME AND CLASS NAME FOR THE CHANGES
 * TO TAKE EFFECT, OR THE 'ID' IN THE ANNOTATION. I HAVE NO IDEA WHY, BUT CLEARING THE CACHES WILL NOT WORK AND YOU WILL 
 * NOT BE ABLE TO SEE THE CHANGE YOU HAVE MADE.
 * 
 * Container theme settings.
 *
 * @ingroup plugins_setting
 *
 * @BootstrapSetting(
 *   id = "fws_use_search",
 *   type = "checkbox",
 *   title = @Translation("Use Search"),
 *   defaultValue = 0,
 *   description = @Translation("Enable if your site is using a search block. This will NOT place the search block for you, but instead create the proper margins to allow the search to display correctly. See the FWS theme Readme.md file for more information."),
 *   groups = {
 *     "fws" = @Translation("FWS Theme"),
 *     "search" = @Translation("Search"),
 *   },
 * )
 */
class UseSearch extends SettingBase {}
