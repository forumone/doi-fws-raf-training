<?php

/**
 * @file
 * Configuration file for multi-site support and directory aliasing feature.
 */

$sites['fws-raf.ddev.site'] = 'default';
$sites['manatee.ddev.site'] = 'manatee';
$sites['eps.ddev.site'] = 'eps';
$sites['aerial.ddev.site'] = 'aerial';
$sites['falcon.ddev.site'] = 'falcon';
$sites['rcgr.ddev.site'] = 'rcgr';
$sites['fws-raf.ddev.site.manatee'] = 'manatee';
$sites['fws-raf.ddev.site.epsandhill'] = 'eps';
$sites['fws-raf.ddev.site.aerial'] = 'aerial';
$sites['fws-raf.ddev.site.falcon'] = 'falcon';
$sites['fws-raf.ddev.site.rcgr'] = 'rcgr';

// Acquia environment configurations.
$sites['doifwsrafdev.prod.acquia-sites.com'] = 'default';
// Handle /manatee paths on Acquia.
$sites['doifwsrafdev.prod.acquia-sites.com.manatee'] = 'manatee';
// Handle /epsandhill paths on Acquia.
$sites['doifwsrafdev.prod.acquia-sites.com.epsandhill'] = 'eps';
// Handle /aerial paths on Acquia.
$sites['doifwsrafdev.prod.acquia-sites.com.aerial'] = 'aerial';
// Handle /falcon paths on Acquia.
$sites['doifwsrafdev.prod.acquia-sites.com.falcon'] = 'falcon';
// Handle /rcgr paths on Acquia.
$sites['doifwsrafdev.prod.acquia-sites.com.rcgr'] = 'rcgr';
