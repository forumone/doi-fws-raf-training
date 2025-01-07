<?php

/**
 * @file
 * Drush script to import Manatee data and names into species nodes with paragraphs.
 * Now includes revision support to track creation and update information.
 *
 * Usage: drush scr scripts/import-manatee.php.
 */

use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

// Configuration.
$species_csv = '../scripts/data/T_Manatee.csv';
$names_csv = '../scripts/data/T_Manatee_Name.csv';

// Verify files exist.
if (!file_exists($species_csv) || !file_exists($names_csv)) {
  exit("One or more CSV files not found.\n");
}

// Initialize counters.
$species_count = 0;
$species_created = 0;
$species_updated = 0;
$names_count = 0;
$names_created = 0;
$error_count = 0;

// Arrays to hold data for second pass.
$data_rows = [];
$number_to_nid = [];

// First Pass: Import species nodes without parent references.
print("\nStarting species import...\n");

$handle = fopen($species_csv, 'r');
if (!$handle) {
  exit('Error opening species CSV file.');
}

// Skip header row.
fgetcsv($handle);

while (($data = fgetcsv($handle)) !== FALSE) {
  $species_count++;

  // Store data for second pass.
  $data_rows[] = $data;

  try {
    // CSV columns from T_Manatee.
    [$number, $sex, $dam, $sire, $rearing, $studbook, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if node exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'species',
        'field_number' => $number,
      ]);

    // Prepare base node data
    $node_data = [
      'type' => 'species',
      'field_number' => $number,
      'field_sex' => [
        'target_id' => get_sex_term_id($sex),
      ],
      'field_rearing' => [
        'target_id' => get_rearing_term_id($rearing),
      ],
      'field_studbook' => $studbook,
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // Update existing node with revisions
      $original_node = reset($existing_nodes);
      $nid = $original_node->id();
      
      // Create a fresh node object
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $node = $node_storage->load($nid);
      
      // Update base data
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      
      // Create initial revision with creation info
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->setRevisionUserId(get_user_id($create_by));
      $node->setRevisionCreationTime(strtotime($create_date));
      $node->set('created', strtotime($create_date));
      $node->set('changed', strtotime($create_date));
      $node->set('title', "Manatee $number (temp)");
      $node->setRevisionLogMessage('Initial revision created by ' . $create_by);
      $node->enforceIsNew(FALSE);
      $node->save();
      
      // Create update revision
      $node = $node_storage->load($nid);
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->set('title', "Manatee $number");
      $update_user_id = $update_by === 'D' ? 1 : get_user_id($update_by);
      $node->setRevisionUserId($update_user_id);
      $node->setRevisionCreationTime(strtotime($update_date));
      $node->set('changed', strtotime($update_date));
      $node->setRevisionLogMessage('Updated by ' . ($update_by === 'D' ? 'admin' : $update_by));
      $node->save();
      
      print("\nUpdating existing species node: Manatee $number");
      $species_updated++;
    }
    else {
      // Create new node with revisions
      $node_data['uid'] = get_user_id($create_by);
      $node_data['created'] = strtotime($create_date);
      $node_data['changed'] = strtotime($create_date);
      $node_data['title'] = "Manatee $number (temp)";
      $node = Node::create($node_data);
      
      // Set initial revision information
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->setRevisionUserId(get_user_id($create_by));
      $node->setRevisionCreationTime(strtotime($create_date));
      $node->setRevisionLogMessage('Initial revision created by ' . $create_by);
      $node->save();
      
      // Create update revision
      $node = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->load($node->id());
      
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->set('title', "Manatee $number");
      $update_user_id = $update_by === 'D' ? 1 : get_user_id($update_by);
      $node->setRevisionUserId($update_user_id);
      $node->setRevisionCreationTime(strtotime($update_date));
      $node->set('changed', strtotime($update_date));
      $node->setRevisionLogMessage('Updated by ' . ($update_by === 'D' ? 'admin' : $update_by));
      $node->save();
      
      print("\nCreating new species node: Manatee $number");
      $species_created++;
    }

    $number_to_nid[$number] = $node->id();
  }
  catch (Exception $e) {
    print("\nError processing species $number: " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

// Second Pass: Update parent references.
print("\n\nUpdating parent references...\n");

foreach ($data_rows as $data) {
  [$number, $sex, $dam, $sire, $rearing, $studbook, $create_by, $create_date, $update_by, $update_date] = $data;

  try {
    $node_id = $number_to_nid[$number];
    $node = Node::load($node_id);

    // Update parent references if they exist
    $dam_id = $number_to_nid[$dam] ?? NULL;
    $sire_id = $number_to_nid[$sire] ?? NULL;

    if ($dam_id || $sire_id) {
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      
      if ($dam_id) {
        $node->set('field_dam', ['target_id' => $dam_id]);
      }
      if ($sire_id) {
        $node->set('field_sire', ['target_id' => $sire_id]);
      }
      
      $node->setRevisionUserId(get_user_id($update_by));
      $node->setRevisionCreationTime(strtotime($update_date));
      $node->setRevisionLogMessage('Parent references updated by ' . ($update_by === 'D' ? 'admin' : $update_by));
      $node->save();
      
      print("\nUpdated parents for Manatee $number");
    }
  }
  catch (Exception $e) {
    print("\nError updating parents for $number: " . $e->getMessage());
    $error_count++;
  }
}

// Third Pass: Import names as paragraphs with revisions.
print("\n\nImporting names as paragraphs...\n");

$handle = fopen($names_csv, 'r');
if (!$handle) {
  exit('Error opening names CSV file.');
}

// Skip and validate header.
$header = fgetcsv($handle);
$expected_headers = ['MLog', 'Name', 'Primary', 'CreateBy', 'CreateDate', 'UpdateBy', 'UpdateDate'];
$missing_headers = array_diff($expected_headers, $header);
if (!empty($missing_headers)) {
  exit("Names CSV is missing headers: " . implode(', ', $missing_headers));
}

// Group names by species number
$names_by_species = [];
while (($data = fgetcsv($handle)) !== FALSE) {
  $names_count++;
  $row = array_combine($header, $data);
  $number = trim($row['MLog']);
  
  if (!isset($names_by_species[$number])) {
    $names_by_species[$number] = [];
  }
  $names_by_species[$number][] = $row;
}
fclose($handle);

// Process names for each species
foreach ($names_by_species as $number => $names) {
  try {
    // Skip if species not found
    if (!isset($number_to_nid[$number])) {
      print("\nSpecies not found for MLog: $number");
      continue;
    }

    $node = Node::load($number_to_nid[$number]);
    
    // Delete existing paragraphs once per species
    $existing_paragraphs = [];
    foreach ($node->get('field_names') as $item) {
      $existing_paragraphs[] = $item->target_id;
    }
    if (!empty($existing_paragraphs)) {
      $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
      $entities = $storage->loadMultiple($existing_paragraphs);
      $storage->delete($entities);
      $node->set('field_names', []);
      $node->save();
      print("\nDeleted existing names for Manatee $number");
    }

    // Create all new paragraphs for this species
    $new_paragraphs = [];
    foreach ($names as $row) {
      $name = trim($row['Name']);
      
      // Skip if name is empty
      if (empty($name)) {
        continue;
      }

      // Create new name paragraph
      $values = [
        'type' => 'species_name',
        'field_name' => $name,
        'field_primary' => filter_var($row['Primary'], FILTER_VALIDATE_BOOLEAN),
        'created' => strtotime($row['CreateDate']),
      ];

      $paragraph = Paragraph::create($values);
      $create_user_id = get_user_id($row['CreateBy']);
      $paragraph->setOwnerId($create_user_id);
      $paragraph->setNewRevision(TRUE);
      $paragraph->isDefaultRevision(TRUE);
      $paragraph->set('parent_type', 'node');
      $paragraph->set('parent_id', $number_to_nid[$number]);
      $paragraph->set('parent_field_name', 'field_names');
      $paragraph->save();
      
      $new_paragraphs[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
      
      $names_created++;
      print("\nAdded name '$name' to Manatee $number");
    }

    // Add all paragraphs to node at once
    if (!empty($new_paragraphs)) {
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->set('field_names', $new_paragraphs);
      $node->setRevisionUserId(get_user_id($row['UpdateBy']));
      $node->setRevisionCreationTime(strtotime($row['UpdateDate']));
      $node->setRevisionLogMessage('Added ' . count($new_paragraphs) . ' names by ' . 
        ($row['UpdateBy'] === 'D' ? 'admin' : $row['UpdateBy']));
      $node->save();
    }
  }
  catch (Exception $e) {
    print("\nError adding names for $number: " . $e->getMessage());
    $error_count++;
  }
}

// Print summary.
print("\n\nImport completed:");
print("\nSpecies processed: $species_count");
print("\nSpecies created: $species_created");
print("\nSpecies updated: $species_updated");
print("\nNames processed: $names_count");
print("\nNames created: $names_created");
print("\nErrors: $error_count\n");

/**
 * Helper functions remain unchanged.
 */
function get_sex_term_id($sex) {
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'sex',
      'name' => $sex,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
  return NULL;
}

function get_rearing_term_id($rearing) {
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'rearing',
      'name' => $rearing,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
  return NULL;
}

function get_user_id($username) {
  if (empty($username)) {
    return 1;
  }

  // Use super admin for 'D'
  if ($username == 'D') {
    return 1;
  }

  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }

  print("\nWarning: User '$username' not found, using admin (uid:1)");
  return 1;
}