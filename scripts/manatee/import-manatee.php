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
$species_csv = '../scripts/manatee/data/T_Manatee.csv';
$names_csv = '../scripts/manatee/data/T_Manatee_Name.csv';

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
$update_times = []; // Store update times by node ID

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

    // Store the update timestamp
    $update_timestamp = strtotime($update_date);

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
      // Update existing node
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
      $node->set('changed', $update_timestamp);
      $node->set('title', "Manatee $number");
      $node->setRevisionLogMessage('Initial revision created by ' . $create_by);
      $node->enforceIsNew(FALSE);
      
      print("\nUpdating existing species node: Manatee $number");
      $species_updated++;
    }
    else {
      // Create new node
      $node_data['uid'] = get_user_id($create_by);
      $node_data['created'] = strtotime($create_date);
      $node_data['changed'] = $update_timestamp;
      $node_data['title'] = "Manatee $number";
      $node = Node::create($node_data);
      
      // Set initial revision information
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->setRevisionUserId(get_user_id($create_by));
      $node->setRevisionCreationTime(strtotime($create_date));
      $node->setRevisionLogMessage('Initial revision created by ' . $create_by);
      
      print("\nCreating new species node: Manatee $number");
      $species_created++;
    }

    // Save node and store update info
    $node->save();
    $number_to_nid[$number] = $node->id();
    $update_times[$node->id()] = [
      'timestamp' => $update_timestamp,
      'user_id' => $update_by === 'D' ? 1 : get_user_id($update_by),
      'by' => $update_by
    ];
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
    $update_time = $update_times[$node_id]['timestamp'];

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
      
      // Preserve the original update timestamp
      $node->set('changed', $update_time);
      $node->setRevisionLogMessage('Parent references updated');
      $node->save();
      
      print("\nUpdated parents for Manatee $number");
    }
  }
  catch (Exception $e) {
    print("\nError updating parents for $number: " . $e->getMessage());
    $error_count++;
  }
}

// Third Pass: Import names as paragraphs.
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
    if (!isset($number_to_nid[$number])) {
      print("\nSpecies not found for MLog: $number");
      continue;
    }

    $nid = $number_to_nid[$number];
    $node = Node::load($nid);
    $update_time = $update_times[$nid]['timestamp'];
    
    // Delete existing paragraphs
    $existing_paragraphs = [];
    foreach ($node->get('field_names') as $item) {
      $existing_paragraphs[] = $item->target_id;
    }
    if (!empty($existing_paragraphs)) {
      $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
      $entities = $storage->loadMultiple($existing_paragraphs);
      $storage->delete($entities);
      $node->set('field_names', []);
      // Preserve the update timestamp when saving after deleting paragraphs
      $node->set('changed', $update_time);
      $node->save();
      print("\nDeleted existing names for Manatee $number");
    }

    // Create new paragraphs
    $new_paragraphs = [];
    foreach ($names as $row) {
      $name = trim($row['Name']);
      if (empty($name)) {
        continue;
      }

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
      $paragraph->set('parent_id', $nid);
      $paragraph->set('parent_field_name', 'field_names');
      $paragraph->save();
      
      $new_paragraphs[] = [
        'target_id' => $paragraph->id(),
        'target_revision_id' => $paragraph->getRevisionId(),
      ];
      
      $names_created++;
      print("\nAdded name '$name' to Manatee $number");
    }

    // Final node save - do this whether or not there are paragraphs
    $node->setNewRevision();
    $node->isDefaultRevision(TRUE);
    
    // Add paragraphs if they exist
    if (!empty($new_paragraphs)) {
      $node->set('field_names', $new_paragraphs);
    }
    
    // Set the final user and timestamp information
    $node->setRevisionUserId($update_times[$nid]['user_id']);
    $node->setRevisionCreationTime($update_time);
    $node->set('changed', $update_time);
    $node->setRevisionLogMessage('Final update by ' . 
      ($update_times[$nid]['by'] === 'D' ? 'admin' : $update_times[$nid]['by']));
    
    $node->save();
  }
  catch (Exception $e) {
    print("\nError adding names for $number: " . $e->getMessage());
    $error_count++;
  }
}

// Final verification pass
print("\n\nRunning final verification pass...\n");
foreach ($number_to_nid as $number => $nid) {
    if (!isset($update_times[$nid])) {
        print("\nWarning: No update time found for Manatee $number");
        continue;
    }
    
    try {
        $node = Node::load($nid);
        if ($node->getChangedTime() != $update_times[$nid]['timestamp']) {
            print("\nFixing timestamp for Manatee $number");
            $node->setNewRevision();
            $node->isDefaultRevision(TRUE);
            $node->setRevisionUserId($update_times[$nid]['user_id']);
            $node->setRevisionCreationTime($update_times[$nid]['timestamp']);
            $node->set('changed', $update_times[$nid]['timestamp']);
            $node->setRevisionLogMessage('Final verification update by ' . 
                ($update_times[$nid]['by'] === 'D' ? 'admin' : $update_times[$nid]['by']));
            $node->save();
        }
    } catch (Exception $e) {
        print("\nError in final verification for Manatee $number: " . $e->getMessage());
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
 * Helper Functions
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