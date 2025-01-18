<?php

/**
 * @file
 * Drush script to import sightings from CSV.
 *
 * Usage: drush scr import-sightings.php.
 */

use Drupal\node\Entity\Node;

$filename = '../scripts/eps/data/sightings_export_2025-01-17_19-07-21.csv';

if (!file_exists($filename)) {
  print("File not found: $filename\n");
  exit(1);
}

// Debug: Print available fields.
$field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'sighting');
print("\nAvailable fields for sighting content type:\n");
foreach ($field_definitions as $field_name => $field_definition) {
  print($field_name . " => " . $field_definition->getType() . "\n");
}

// Open CSV file.
$file = fopen($filename, 'r');
if (!$file) {
  print("Could not open file: $filename\n");
  exit(1);
}

// Read headers.
$headers = fgetcsv($file);
if (!$headers) {
  print("Could not read CSV headers\n");
  fclose($file);
  exit(1);
}

// Initialize counters.
$created = 0;
$updated = 0;
$errors = 0;

// Process each row.
while (($row = fgetcsv($file)) !== FALSE) {
  try {
    // Create array combining headers with values.
    $data = array_combine($headers, $row);

    // Check if node exists.
    $existing_node = NULL;
    if (!empty($data['Node ID'])) {
      $existing_node = Node::load($data['Node ID']);
    }

    if ($existing_node) {
      $node = $existing_node;
      $isUpdate = TRUE;
    }
    else {
      // Create new node.
      $node = Node::create([
        'type' => 'sighting',
        'status' => 1,
      ]);
      $isUpdate = FALSE;
    }

    // Set the title from Title column.
    if (!empty($data['Title'])) {
      $node->setTitle($data['Title']);
    }

    // Set field values using the machine names from your configuration.
    if (!empty($data['Date & Time'])) {
      $node->field_date_time = [
        'value' => date('Y-m-d\TH:i:s', strtotime($data['Date & Time'])),
      ];
    }

    if (!empty($data['Habitat'])) {
      $node->field_habitat = ['value' => $data['Habitat']];
    }

    if (!empty($data['Location'])) {
      $location = str_replace('"', '', $data['Location']);
      $coordinates = array_map('trim', explode(',', $location));
      if (count($coordinates) == 2) {
        $node->field_location = [
          'lat' => (float) $coordinates[0],
          'lng' => (float) $coordinates[1],
        ];
      }
    }

    if (!empty($data['Method'])) {
      $node->field_method = ['value' => $data['Method']];
    }

    if (!empty($data['Notes']) && $data['Notes'] !== '.') {
      $node->field_notes = [
        'value' => $data['Notes'],
        'format' => 'plain_text',
      ];
    }

    if (!empty($data['Number of Cranes'])) {
      $node->field_bird_count = ['value' => (int) $data['Number of Cranes']];
    }

    if (!empty($data['Spotter Username']) && $data['Spotter Username'] !== '.') {
      $users = \Drupal::entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['name' => $data['Spotter Username']]);
      if ($user = reset($users)) {
        $node->uid = $user->id();
      }
    }

    // Save the node.
    $node->save();

    if ($isUpdate) {
      $updated++;
      if ($updated % 100 === 0) {
        print("Updated $updated sighting nodes...\n");
      }
    }
    else {
      $created++;
      if ($created % 100 === 0) {
        print("Created $created sighting nodes...\n");
      }
    }
  }
  catch (\Exception $e) {
    print("Error processing row: " . implode(', ', $row) . "\n");
    print("Error message: " . $e->getMessage() . "\n");
    $errors++;
  }
}

// Close file.
fclose($file);

// Output summary.
print("\nImport completed:\n");
print("Created: $created\n");
print("Updated: $updated\n");
print("Errors: $errors\n");
