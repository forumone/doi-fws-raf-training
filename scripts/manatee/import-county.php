<?php

/**
 * @file
 * Drush script to import county data into taxonomy terms.
 */

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Entity\EntityStorageException;

$csv_file = '../scripts/manatee/data/L_County.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters
$row_count = 0;
$success_count = 0;
$error_count = 0;

$vocabulary = 'county';
$vid = Vocabulary::load($vocabulary);
if (!$vid) {
  exit("Vocabulary '$vocabulary' not found.");
}

$handle = fopen($csv_file, 'r');
if (!$handle) {
  exit('Error opening CSV file.');
}

// Skip header row
fgetcsv($handle);

while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  try {
    list($state, $county) = $data;

    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $county,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists for county: $county - skipping");
      continue;
    }

    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $county,
      'field_state' => $state,
      'status' => 1,
    ]);

    $term->save();
    $success_count++;
    print("\nImported county taxonomy term: $county");
  } catch (EntityStorageException $e) {
    print("\nError on row $row_count: " . $e->getMessage());
    $error_count++;
  } catch (Exception $e) {
    print("\nGeneral error on row $row_count: " . $e->getMessage());
    $error_count++;
  }
}

fclose($handle);

print("\nImport completed:");
print("\nTotal rows processed: $row_count");
print("\nSuccessfully imported: $success_count");
print("\nErrors: $error_count\n");
