<?php

/**
 * @file
 * Drupal site-specific configuration file for eps multisite.
 */

$databases['default']['default'] = [
  'database' => 'eps',
  'username' => 'db',
  'password' => 'db',
  'host' => 'db',
  'port' => '3306',
  'driver' => 'mysql',
  'prefix' => '',
];

$settings['hash_salt'] = 'some-unique-hash-string';
$settings['config_sync_directory'] = '../config/eps/sync';

// Uncomment if you want to use a specific base URL
// $base_url = 'https://eps.ddev.site';
