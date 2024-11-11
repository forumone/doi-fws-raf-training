<?php

// Small cleanup to delete erroneous folder.
if (file_exists('public:') && is_writable('public:')) {
  rmdir('public:');
}

// $container = \Drupal::getContainer();
// $config_factory = $container->get('config.factory');

// $blocks = [
//   'fws_theme_breadcrumbs',
//   'fws_theme_help',
//   'fws_theme_page_title',
// ];

// foreach ($blocks as $block) {
//   $config = $config_factory->getEditable('block.block.' . $block);
//   if ($config) {
//     $config->set('status', 0)->save();
//   }
// }
