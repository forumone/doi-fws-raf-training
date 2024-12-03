<?php

/**
 * @file
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\menu_link_content\Entity\MenuLinkContent;

/**
 * @file
 * Script to recreate menu structure for Captive Manatee system.
 */

/**
 *
 */
function recreate_menus() {
  // Delete existing menu links.
  delete_existing_menu_links();

  // Create main menu items.
  create_main_menu_links();

  // Create utility menu items.
  create_utility_menu_links();
}

/**
 * Delete existing menu links from both menus.
 */
function delete_existing_menu_links() {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $menu_links = $storage->loadByProperties([
    'menu_name' => ['main', 'utility'],
  ]);

  foreach ($menu_links as $menu_link) {
    try {
      $menu_link->delete();
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('menu_setup')->error('Failed to delete menu link: @message', ['@message' => $e->getMessage()]);
    }
  }
}

/**
 * Create main menu links.
 */
function create_main_menu_links() {
  $main_menu_items = [
    'enter-manatee-data' => [
      'title' => 'Enter Manatee Data',
      'weight' => 1,
      'children' => [
        'rescue' => ['title' => 'Rescue', 'weight' => 1],
        'transfer' => ['title' => 'Transfer', 'weight' => 2],
        'pre-release-authorization' => ['title' => 'Pre-Release Authorization', 'weight' => 3],
        'release' => ['title' => 'Release', 'weight' => 4],
        'status-update' => ['title' => 'Status Update', 'weight' => 5],
        'death' => ['title' => 'Death', 'weight' => 6],
        'captive-birth' => ['title' => 'Captive Birth', 'weight' => 7],
        'other-manatee-names' => ['title' => 'Other Manatee Names', 'weight' => 8],
      ],
    ],
    'database-search' => [
      'title' => 'Database Search',
      'weight' => 2,
      'children' => [
        'current-captives' => ['title' => 'Current Captives by Facility', 'weight' => 1],
        'manatee-search' => ['title' => 'Manatee Search', 'weight' => 2],
        'table-data-search' => ['title' => 'Table Data Search', 'weight' => 3],
        'lookup-data-view' => ['title' => 'Lookup Data View', 'weight' => 4],
      ],
    ],
    'reports' => [
      'title' => 'Reports',
      'weight' => 3,
      'children' => [
        'standard-reports' => ['title' => 'Standard Reports', 'weight' => 1],
        'administrative-reports' => ['title' => 'Administrative Reports', 'weight' => 2],
      ],
    ],
    'administrative' => [
      'title' => 'Administrative',
      'weight' => 4,
    ],
  ];

  create_menu_structure('main', $main_menu_items);
}

/**
 * Create utility menu links.
 */
function create_utility_menu_links() {
  $utility_menu_items = [
    'resources' => ['title' => 'Resources', 'weight' => 1],
    'help' => ['title' => 'Help', 'weight' => 2],
    'logout' => ['title' => 'Logout', 'weight' => 3],
    'profile' => ['title' => 'Profile', 'weight' => 4],
  ];

  create_menu_structure('utility', $utility_menu_items);
}

/**
 * Helper function to create menu structure.
 */
function create_menu_structure(string $menu_name, array $items, string $parent = '') {
  foreach ($items as $key => $item) {
    try {
      $menu_link = MenuLinkContent::create([
        'title' => $item['title'],
        'link' => ['uri' => 'internal:/' . str_replace('_', '-', $key)],
        'menu_name' => $menu_name,
        'weight' => $item['weight'],
        'expanded' => !empty($item['children']),
      ]);

      if ($parent) {
        $menu_link->set('parent', $parent);
      }

      $menu_link->save();

      // Recursively create children if they exist.
      if (!empty($item['children'])) {
        create_menu_structure($menu_name, $item['children'], 'menu_link_content:' . $menu_link->uuid());
      }
    }
    catch (EntityStorageException $e) {
      \Drupal::logger('menu_setup')->error('Failed to create menu link "@title": @message', [
        '@title' => $item['title'],
        '@message' => $e->getMessage(),
      ]);
    }
  }
}

// Execute the menu recreation.
recreate_menus();
