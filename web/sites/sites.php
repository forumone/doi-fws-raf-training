<?php

/**
 * @file
 * Configuration file for multi-site support and directory aliasing feature.
 */

$sites['eps.ddev.site'] = 'eps';
$sites['aerial.ddev.site'] = 'aerial';
$sites['fws-raf.ddev.site'] = 'default';
$sites['fws-raf.ddev.site.epsandhill'] = 'eps';
$sites['fws-raf.ddev.site.aerial'] = 'aerial';

// Acquia environment configurations.
$sites['doifwsdevapps.prod.acquia-sites.com'] = 'default';
// Handle /epsandhill paths on Acquia.
$sites['doifwsdevapps.prod.acquia-sites.com.epsandhill'] = 'eps';
// Handle /aerial paths on Acquia.
$sites['doifwsdevapps.prod.acquia-sites.com.aerial'] = 'aerial';
