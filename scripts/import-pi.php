<?php

/**
 * @file
 * Drush script to import data into pi content type.
 *
 * Usage: drush scr scripts/import_pi.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;

$csv_file = '../scripts/data/T_PI.csv';
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
    // CSV columns: PIID, PI, PIOrg, PIAddress, PIPhone, PIEmail, CreateBy, CreateDate, UpdateBy, UpdateDate.
    [$pi_id, $pi_name, $pi_org, $pi_address, $pi_phone, $pi_email, $create_by, $create_date, $update_by, $update_date] = $data;

    // Check if pi node already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'pi',
        'field_pi_id' => $pi_id,
      ]);

    // Prepare node data.
    $node_data = [
      'type' => 'pi',
    // Use "Unknown PI" if name is missing.
      'title' => $pi_name ?: 'Unknown PI',
      'field_pi_id' => $pi_id,
      'field_pi_name' => $pi_name,
      'field_pi_org' => $pi_org,
      'field_pi_address' => $pi_address,
      'field_pi_phone' => $pi_phone,
      'field_pi_email' => $pi_email,
    // UNIX timestamp.
      'created' => parse_date($create_date, TRUE),
    // UNIX timestamp.
      'changed' => parse_date($update_date, TRUE),
      'status' => 1,
    ];

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      foreach ($node_data as $field => $value) {
        $node->set($field, $value);
      }
      print("\nUpdating existing pi node: $pi_id");
      $updated_count++;
    }
    else {
      // Create new node.
      $node_data['uid'] = get_user_id($create_by);
      $node = Node::create($node_data);
      print("\nCreating new pi node: $pi_id");
      $created_count++;
    }

    $node->save();

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
 * Helper function to parse and format date values.
 * Returns UNIX timestamp by default.
 */
function parse_date($date_value, $as_timestamp = TRUE) {
  try {
    if (empty($date_value)) {
      return NULL;
    }
    $date = \DateTime::createFromFormat('Y-m-d H:i:s.u', $date_value);
    if ($date === FALSE) {
      $date = \DateTime::createFromFormat('Y-m-d H:i:s', $date_value);
    }
    if ($date !== FALSE) {
      return $as_timestamp ? $date->getTimestamp() : $date->format('Y-m-d\TH:i:s');
    }
    print("\nError parsing date: $date_value");
    return NULL;
  }
  catch (Exception $e) {
    print("\nException while parsing date: " . $e->getMessage());
    return NULL;
  }
}

/**
 * Helper function to get User ID by username.
 */
function get_user_id($username) {
  $users = \Drupal::entityTypeManager()
    ->getStorage('user')
    ->loadByProperties(['name' => $username]);

  if (!empty($users)) {
    return reset($users)->id();
  }
  // Default user ID.
  return 1;
}
