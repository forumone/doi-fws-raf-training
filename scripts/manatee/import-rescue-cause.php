<?php

/**
 * @file
 * Imports rescue cause and death cause data into rescue_cause taxonomy terms.
 *
 * Processes L_Rescue_Cause.csv and L_Death_Cause.csv, skipping terms where
 * the name (CauseID) already exists in the rescue_cause vocabulary.
 *
 * Usage: drush scr scripts/manatee/import-rescue-cause.php.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

// Define the target vocabulary.
$vocabulary = 'rescue_cause';
$vid = Vocabulary::load($vocabulary);
if (!$vid) {
  exit("Vocabulary '$vocabulary' not found.");
}

// Initialize counters for overall summary.
$total_rows_processed = 0;
$total_success_count = 0;
$total_error_count = 0;

/**
 * Processes a CSV file and imports terms into the specified vocabulary.
 *
 * @param string $csv_file_path
 *   Path to the CSV file.
 * @param string $vocabulary_id
 *   The machine name of the target vocabulary.
 * @param string $csv_type
 *   Indicates the type of CSV ('rescue' or 'death') for column mapping.
 * @param int &$row_count
 *   Reference to the total row counter.
 * @param int &$success_count
 *   Reference to the total success counter.
 * @param int &$error_count
 *   Reference to the total error counter.
 */
function import_terms_from_csv(string $csv_file_path, string $vocabulary_id, string $csv_type, int &$row_count, int &$success_count, int &$error_count): void {
  if (!file_exists($csv_file_path)) {
    print("\nWarning: CSV file not found at: $csv_file_path - skipping.");
    return;
  }

  print("\nProcessing CSV file: $csv_file_path");

  // Open CSV file.
  $handle = fopen($csv_file_path, 'r');
  if (!$handle) {
    print("\nError opening CSV file: $csv_file_path - skipping.");
    // Count this as an error? Or just skip? Let's count it.
    $error_count++;
    return;
  }

  // Skip header row.
  fgetcsv($handle);

  // Counter for this specific file.
  $file_row_count = 0;

  // Process each row.
  while (($data = fgetcsv($handle)) !== FALSE) {
    $row_count++;
    $file_row_count++;

    try {
      // Map CSV columns based on type.
      if ($csv_type === 'rescue') {
        // L_Rescue_Cause.csv: CauseID,RescueCause,RescueCauseDetail,Description.
        [$name, $cause, $cause_detail, $description] = $data;
      }
      elseif ($csv_type === 'death') {
        // L_Death_Cause.csv: CauseID, DeathCause, DeathCauseDetail, Description.
        [$name, $cause, $cause_detail, $description] = $data;
      }
      else {
        throw new Exception("Invalid CSV type specified: $csv_type");
      }

      // Trim whitespace from name/ID.
      $name = trim($name);

      // Check if a term with this name already exists in the rescue_cause vocabulary.
      $existing_terms = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties([
          'vid' => $vocabulary_id,
          'name' => $name,
        ]);

      if (!empty($existing_terms)) {
        print("\nTerm already exists in $vocabulary_id for name: $name - skipping (from $csv_file_path)");
        continue;
      }

      // Create taxonomy term in the target vocabulary.
      $term = Term::create([
        'vid' => $vocabulary_id,
        'name' => $name,
        // Map CSV fields to RescueCause fields.
        'field_rescue_cause' => $cause,
        'field_rescue_cause_detail' => $cause_detail,
        'description' => [
          'value' => $description,
          'format' => 'basic_html',
        ],
        'status' => 1,
      ]);
      $term->save();

      $success_count++;
      print("\nImported \"$name\" into $vocabulary_id taxonomy term (from $csv_file_path).");
    }
    catch (EntityStorageException | Exception $e) {
      print("\nError processing name $name (row $file_row_count in $csv_file_path): " . $e->getMessage());
      $error_count++;
    }
  }

  fclose($handle);
  print("\nFinished processing $csv_file_path");
}

// --- Main script execution ---
// Process Rescue Cause CSV first.
$rescue_csv_file = '../scripts/manatee/data/L_Rescue_Cause.csv';
import_terms_from_csv($rescue_csv_file, $vocabulary, 'rescue', $total_rows_processed, $total_success_count, $total_error_count);

// Process Death Cause CSV next, adding only non-existing terms.
$death_csv_file = '../scripts/manatee/data/L_Death_Cause.csv';
import_terms_from_csv($death_csv_file, $vocabulary, 'death', $total_rows_processed, $total_success_count, $total_error_count);


// Print summary.
print("\n\nImport completed for both files:");
print("\nTotal rows processed across both files: $total_rows_processed");
print("\nSuccessfully imported into $vocabulary: $total_success_count");
print("\nTotal errors: $total_error_count\n");
