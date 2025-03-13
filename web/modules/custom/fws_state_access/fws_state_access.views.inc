<?php

/**
 * @file
 * Provides views data for the fws_state_access module.
 */

/**
 * Implements hook_views_data_alter().
 */
function fws_state_access_views_data_alter(array &$data) {
  // Add our custom filter to the users table.
  $data['users']['state_user_filter'] = [
    'title' => t('Filter by current user state'),
    'filter' => [
      'title' => t('Filter by current user state'),
      'help' => t('Filter users to only show those with the same state as the current user.'),
      'field' => 'uid',
      'id' => 'state_user_filter',
    ],
  ];
}
