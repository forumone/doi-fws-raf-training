<?php

/**
 * @file
 * Configuration file for multi-site support and directory aliasing feature.
 */

$sites['eps.ddev.site'] = 'eps';

// Acquia environment configurations.
$sites['doifwsdevapps.prod.acquia-sites.com'] = 'default';
$sites['doifwsdevapps.stage.acquia-sites.com'] = 'default';
$sites['doifwsdevapps.dev.acquia-sites.com'] = 'default';

// Handle /epsandhill paths on Acquia environments.
if (isset($_ENV['AH_SITE_ENVIRONMENT']) && strpos($_SERVER['REQUEST_URI'], '/epsandhill/') === 0) {
  $sites['doifwsdevapps.prod.acquia-sites.com'] = 'eps';
  $sites['doifwsdevapps.stage.acquia-sites.com'] = 'eps';
  $sites['doifwsdevapps.dev.acquia-sites.com'] = 'eps';
}
