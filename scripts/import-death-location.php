<?php

/**
 * @file
 * Drush script to import L_Death_Loc.csv data into death location taxonomy terms.
 *
 * Usage: drush scr scripts/import_death_loc.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

$csv_file = '../scripts/data/L_Death_Loc.csv';
if (!file_exists($csv_file)) {
  exit('CSV file not found at: ' . $csv_file);
}

// Initialize counters.
$row_count = 0;
$success_count = 0;
$error_count = 0;

// Get the vocabulary.
$vocabulary = 'death_location';
$vid = Vocabulary::load($vocabulary);
if (!$vid) {
  exit("Vocabulary '$vocabulary' not found.");
}

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
    // CSV columns: DeathLoc, DeathLocation, Description, Active.
    [$name, $death_location, $description, $active] = $data;

    // Check if term already exists.
    $existing_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $vocabulary,
        'name' => $name,
      ]);

    if (!empty($existing_terms)) {
      print("\nTerm already exists for location: $name - skipping");
      continue;
    }

    // Create taxonomy term.
    $term = Term::create([
      'vid' => $vocabulary,
      'name' => $name,
      'field_death_location' => $death_location,
      'description' => [
        'value' => $description,
        'format' => 'basic_html',
      ],
      'field_active' => $active === 'Y' ? 1 : 0,
      'status' => 1,
    ]);

    $term->save();
    $success_count++;
    print("\nImported death location taxonomy term: $name");
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
print("\nSuccessfully imported: $success_count");
print("\nErrors: $error_count\n");
