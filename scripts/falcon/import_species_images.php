<?php

/**
 * @file
 * Drush script to import species image data from CSV.
 */

use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;

// Field mapping configuration.
$field_mapping = [
  'recno' => 'field_recno',
  'recno_3186a' => 'field_recno_3186a',
  'owner_state' => 'field_owner_state',
  'authorized_cd' => 'field_authorized_cd',
  'name_of_image' => 'field_name_of_image',
  'type_of_image' => 'field_type_of_image',
  'species_image' => 'field_species_image',
];

// CSV file path - updated to use the correct path.
$csv_file = DRUPAL_ROOT . '/sites/falcon/files/falcon-data/falc_dad_species_image_202503031511.csv';

if (!file_exists($csv_file)) {
  echo "Error: CSV file not found at {$csv_file}\n";
  exit(1);
}

// Initialize counters.
$processed = 0;
$created = 0;
$errors = 0;

// Open CSV file.
$handle = fopen($csv_file, 'r');
if ($handle === FALSE) {
  echo "Error: Unable to open CSV file.\n";
  exit(1);
}

// Skip header row.
$header = fgetcsv($handle);
if (!$header) {
  echo "Error: Unable to read CSV header.\n";
  fclose($handle);
  exit(1);
}

// Cache for state taxonomy terms to avoid repeated lookups
$state_term_cache = [];

// Process each row.
while (($row = fgetcsv($handle)) !== FALSE) {
  $processed++;
  $data = array_combine($header, $row);

  try {
    // Create new node.
    $node = Node::create([
      'type' => 'species_image',
      'title' => $data['name_of_image'] ?? 'Species Image ' . $data['recno'],
      'status' => 1,
    ]);

    // Set field values.
    foreach ($field_mapping as $csv_field => $drupal_field) {
      if ($csv_field === 'species_image') {
        // Handle base64 image.
        if (!empty($data[$csv_field])) {
          $image_data = base64_decode($data[$csv_field]);
          if ($image_data !== FALSE) {
            $file_name = 'species_image_' . $data['recno'] . '.jpg';
            $directory = 'public://species_images';

            // Ensure directory exists.
            if (!\Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
              throw new \Exception("Failed to create directory: $directory");
            }

            $file_path = $directory . '/' . $file_name;
            if (file_put_contents($file_path, $image_data)) {
              $file = File::create([
                'uri' => $file_path,
                'filename' => $file_name,
                'status' => 1,
              ]);
              $file->save();
              $node->set($drupal_field, [
                'target_id' => $file->id(),
                'alt' => $data['name_of_image'] ?? 'Species Image',
              ]);
              echo "Created file: $file_name\n";
            }
            else {
              throw new \Exception("Failed to write image file: $file_name");
            }
          }
          else {
            throw new \Exception("Invalid base64 image data for record: " . $data['recno']);
          }
        }
      }
      else if ($csv_field === 'owner_state') {
        // Special handling for the state taxonomy reference field
        if (!empty($data[$csv_field])) {
          $state_code = $data[$csv_field];
          
          // Get the taxonomy term ID for the state code
          if (!isset($state_term_cache[$state_code])) {
            // Look up the taxonomy term by name
            $terms = \Drupal::entityTypeManager()
              ->getStorage('taxonomy_term')
              ->loadByProperties([
                'vid' => 'state', // Assuming the vocabulary ID is 'state'
                'name' => $state_code,
              ]);
            
            if (!empty($terms)) {
              $term = reset($terms);
              $state_term_cache[$state_code] = $term->id();
              echo "Found term ID {$term->id()} for state: $state_code\n";
            } else {
              // Create the term if it doesn't exist
              echo "State term not found for $state_code, creating it...\n";
              $new_term = Term::create([
                'vid' => 'state',
                'name' => $state_code,
              ]);
              $new_term->save();
              $state_term_cache[$state_code] = $new_term->id();
              echo "Created new state term with ID {$new_term->id()} for: $state_code\n";
            }
          }
          
          // Set the term reference
          $node->set($drupal_field, ['target_id' => $state_term_cache[$state_code]]);
        }
      }
      else {
        if (isset($data[$csv_field]) && $data[$csv_field] !== '') {
          $node->set($drupal_field, $data[$csv_field]);
        }
      }
    }

    $node->save();
    $created++;
    echo "Created node for record {$data['recno']}\n";
  }
  catch (\Exception $e) {
    $errors++;
    echo "Error processing record {$data['recno']}: " . $e->getMessage() . "\n";
    continue;
  }
}

fclose($handle);

// Print summary.
echo "\nImport Summary:\n";
echo "Processed: $processed records\n";
echo "Created: $created nodes\n";
echo "Errors: $errors\n";
echo "\nImport completed.\n";
