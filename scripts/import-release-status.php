<?php

/**
 * @file
 * Drush script to import L_ReleaseStatus.csv data into release_status taxonomy terms.
 *
 * Usage: drush scr scripts/import_release_status.php
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Entity\EntityStorageException;

$csv_file = '../scripts/data/L_Release_Status.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Get the vocabulary
$vocabulary = 'release_status';
$vid = Vocabulary::load($vocabulary);
if (!$vid) {
  exit("Vocabulary '$vocabulary' not found.");
}

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
    // CSV columns: Status,ReleaseStatus,Description,Active
    list($code, $release_status, $description, $active) = $data;

    // Check if term already exists
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $code,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists for release status: $code - skipping");
      continue;
    }

    // Convert Y/N to boolean
    $is_active = ($active === 'Y') ? 1 : 0;

    // Create taxonomy term
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $code,
      'field_release_status' => $release_status,
      'field_active' => $is_active,
      'description' => [
        'value' => $description ?: $release_status, // Use ReleaseStatus as description if Description is empty
        'format' => 'basic_html',
      ],
      'status' => 1,
    ]);

    $term->save();
    $success_count++;
    print("\nImported release status taxonomy term: $code");
  } catch (EntityStorageException $e) {
    print("\nError on row $row_count: " . $e->getMessage());
    $error_count++;
  } catch (Exception $e) {
    print("\nGeneral error on row $row_count: " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

// Print summary
print("\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nSuccessfully imported: $success_count");
print("\nErrors: $error_count\n");
