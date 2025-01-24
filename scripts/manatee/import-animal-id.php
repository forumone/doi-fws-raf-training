<?php

/**
 * @file
 * Drush script to import data into species_id content type with revisions.
 *
 * Usage: drush scr scripts/import_species_id.php
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

$csv_file = '../scripts/manatee/data/T_Animal_Id.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$created_count = 0;
$updated_count = 0;
$error_count = 0;

// Open CSV file.
$handle = fopen($csv_file, 'r');
if (!$handle) {
  exit('Error opening CSV file.');
}

// Skip header row.
fgetcsv($handle);

// Process each row.
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  try {
    // CSV columns: AnimalID, MLog, PrimaryID, IDType, CreateBy, CreateDate, UpdateBy, UpdateDate.
    [$species_id, $number, $primary_id, $id_type, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if species_id node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'species_id',
        'field_species_id' => $species_id,
      ]);

    // Prepare base node data.
    $node_data = [
      'type' => 'species_id',
      'title' => "Species ID $species_id",
      'field_species_id' => $species_id,
      'field_id_type' => [
        'target_id' => get_id_type_term_id($id_type),
      ],
      'field_species_ref' => [
        'target_id' => get_species_node_id($number),
      ],
      'field_primary_id' => $primary_id == '1' ? 1 : 0,
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // Get existing node
      $original_node = reset($existing_nodes);
      $nid = $original_node->id();
      
      // Create a fresh node object
      $node_storage = \Drupal::entityTypeManager()->getStorage('node');
      $node = $node_storage->load($nid);
      
      // Update base data
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      
      // Force new revision with creation info by temporarily changing title
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->setRevisionUserId(get_user_id($create_by));
      $node->setRevisionCreationTime(strtotime($create_date));
      $node->set('created', strtotime($create_date));
      $node->set('changed', strtotime($create_date));
      $node->set('title', $node->getTitle() . ' (temp)');
      $node->setRevisionLogMessage('Initial revision created by ' . $create_by);
      $node->enforceIsNew(FALSE);
      $result = $node->save();
      
      // Restore original title but create new revision with update info
      $node = $node_storage->load($nid);
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->set('title', "Species ID $species_id");
      $update_user_id = $update_by === 'D' ? 1 : get_user_id($update_by);
      $node->setRevisionUserId($update_user_id);
      $node->setRevisionCreationTime(strtotime($update_date));
      $node->set('changed', strtotime($update_date));
      $node->setRevisionLogMessage('Updated by ' . ($update_by === 'D' ? 'admin' : $update_by));
      $result = $node->save();
      
      print("\nUpdating existing species_id node: Species ID $species_id");
      $updated_count++;
    }
    else {
      // Create new node with temporary title
      $node_data['uid'] = get_user_id($create_by);
      $node_data['created'] = strtotime($create_date);
      $node_data['changed'] = strtotime($create_date);
      $node_data['title'] = "Species ID $species_id (temp)";
      $node = Node::create($node_data);
      
      // Set initial revision information
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->setRevisionUserId(get_user_id($create_by));
      $node->setRevisionCreationTime(strtotime($create_date));
      $node->setRevisionLogMessage('Initial revision created by ' . $create_by);
      $result = $node->save();
      
      // Create update revision with final title
      $node = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->load($node->id());
      
      $node->setNewRevision();
      $node->isDefaultRevision(TRUE);
      $node->set('title', "Species ID $species_id");
      $update_user_id = $update_by === 'D' ? 1 : get_user_id($update_by);
      $node->setRevisionUserId($update_user_id);
      $node->setRevisionCreationTime(strtotime($update_date));
      $node->set('changed', strtotime($update_date));
      $node->setRevisionLogMessage('Updated by ' . ($update_by === 'D' ? 'admin' : $update_by));
      $result = $node->save();
      
      print("\nCreating new species_id node: Species ID $species_id");
      $created_count++;
    }

  }
  catch (EntityStorageException $e) {
    print("\nError on row $row_count: " . $e->getMessage());
    $error_count++;
  }
  catch (Exception $e) {
    print("\nGeneral error on row $row_count: " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

// Print summary.
print("\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nNewly created: $created_count");
print("\nUpdated: $updated_count");
print("\nErrors: $error_count\n");

/**
 * Helper functions remain unchanged */

function get_id_type_term_id($id_type) {
  // Handle empty or unknown ID types.
  if (empty($id_type)) {
    return NULL;
  }

  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'id_type',
      'name' => $id_type,
    ]);

  if (!empty($terms)) {
    return reset($terms)->id();
  }
}

function get_species_node_id($number) {
  $nodes = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties([
      'type' => 'species',
      'field_number' => $number,
    ]);

  if (!empty($nodes)) {
    return reset($nodes)->id();
  }

  print("\nError: Manatee node with MLog $number not found.");
  return NULL;
}

function get_user_id($username) {
  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }
  return 1;
}