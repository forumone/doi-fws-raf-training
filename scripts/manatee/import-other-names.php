<?php

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

/**
 * Path to your CSV file.
 */
$csv_file = '../scripts/manatee/data/T_Other_Names.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters
$row_count = 0;
$created_count = 0;
$updated_count = 0;
$error_count = 0;

// Open CSV file
$handle = fopen($csv_file, 'r');
if (!$handle) {
  exit('Error opening CSV file.');
}

// Skip header row
fgetcsv($handle);

// Process each row
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  try {
    // CSV columns: Name, SystemID, System, CreateBy, CreateDate, UpdateBy, UpdateDate
    [$name, $system_id, $system, $create_by, $create_date, $update_by, $update_date] = $data;

    $create_timestamp = strtotime($create_date);
    $update_timestamp = strtotime($update_date);

    // Check if other_names node already exists
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'other_names',
        'field_name' => $name,
        'field_system_id' => $system_id,
      ]);

    // Common field data for this node
    $base_node_data = [
      'type'          => 'other_names',
      'field_name'    => $name,
      'field_system_id' => $system_id,
      'field_system'  => [
        'target_id' => get_system_term_id($system),
      ],
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // UPDATE EXISTING NODE (2 REVISIONS)
      $original_node = reset($existing_nodes);
      $nid = $original_node->id();

      // Clear entity cache before loading
      \Drupal::entityTypeManager()->getStorage('node')->resetCache([$nid]);
      
      // 1) First revision: "create_by"
      $node = Node::load($nid);
      
      // Update base fields before first revision
      foreach ($base_node_data as $field => $value) {
        $node->set($field, $value);
      }
      $node->set('title', "Other Name: $name");

      // Force new revision
      $node->setNewRevision(TRUE);
      $node->setRevisionTranslationAffected(TRUE);
      $node->isDefaultRevision(TRUE);
      $node->setRevisionUserId(get_user_id($create_by));
      $node->setRevisionCreationTime($create_timestamp);
      $node->set('created', $create_timestamp);
      $node->set('changed', $create_timestamp);
      $node->setRevisionLogMessage('Initial revision created by ' . $create_by);
      $node->save();

      print("\nUpdating existing other_names node (1st revision): $name");
      
      // Clear entity cache again before second revision
      \Drupal::entityTypeManager()->getStorage('node')->resetCache([$nid]);
      
      // 2) Second revision: "update_by"
      $node = Node::load($nid);
      $node->setNewRevision(TRUE);
      $node->setRevisionTranslationAffected(TRUE);
      $node->isDefaultRevision(TRUE);
      $node->set('title', "Other Name: $name (updated)");
      $node->set('changed', $update_timestamp);
      $update_user_id = $update_by === 'D' ? 1 : get_user_id($update_by);
      $node->setRevisionUserId($update_user_id);
      $node->setRevisionCreationTime($update_timestamp);
      $node->setRevisionLogMessage('Second revision updated by ' . ($update_by === 'D' ? 'admin' : $update_by));
      $node->save();

      $updated_count++;
    } else {
      // CREATE NEW NODE (2 REVISIONS)
      // 1) First revision: creation
      $node_data = $base_node_data;
      $node_data['uid'] = get_user_id($create_by);
      $node_data['title'] = "Other Name: $name";
      $node_data['created'] = $create_timestamp;
      $node_data['changed'] = $create_timestamp;

      $node = Node::create($node_data);
      $node->enforceIsNew();
      $node->setNewRevision(TRUE);
      $node->setRevisionTranslationAffected(TRUE);
      $node->isDefaultRevision(TRUE);
      $node->setRevisionUserId(get_user_id($create_by));
      $node->setRevisionCreationTime($create_timestamp);
      $node->setRevisionLogMessage('Initial revision created by ' . $create_by);
      $node->save();

      print("\nCreating new other_names node (1st revision): $name");
      
      // Clear entity cache before second revision
      \Drupal::entityTypeManager()->getStorage('node')->resetCache([$node->id()]);
      
      // 2) Second revision: update info
      $node = Node::load($node->id());
      $node->setNewRevision(TRUE);
      $node->isDefaultRevision(TRUE); // Fixed: Changed from setRevisionDefault
      $node->set('title', "Other Name: $name (updated)");
      $node->set('changed', $update_timestamp);
      $update_user_id = $update_by === 'D' ? 1 : get_user_id($update_by);
      $node->setRevisionUserId($update_user_id);
      $node->setRevisionCreationTime($update_timestamp);
      $node->setRevisionLogMessage('Second revision updated by ' . ($update_by === 'D' ? 'admin' : $update_by));
      $node->save();

      $created_count++;
    }
  }
  catch (EntityStorageException $e) {
    print("\nError on row $row_count: " . $e->getMessage());
    $error_count++;
  }
  catch (\Exception $e) {
    print("\nGeneral error on row $row_count: " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

// Print summary
print("\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nNewly created: $created_count");
print("\nUpdated: $updated_count");
print("\nErrors: $error_count\n");

// Helper functions
function get_system_term_id($system) {
  if (empty($system)) {
    return NULL;
  }
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'vid' => 'system',
      'name' => $system,
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