<?php

/**
 * @file
 * Script to import permits from CSV into Drupal nodes (simplified version).
 */

use Drupal\node\Entity\Node;
use Drush\Drush;

// Check if a limit was passed as an argument.
$limit = isset($argv[1]) ? (int) $argv[1] : 0;
$logger = Drush::logger();

// Initialize counters.
$total = 0;
$created = 0;
$updated = 0;
$skipped = 0;
$errors = 0;

// Log the start of the import.
$logger->notice('Starting simplified import for permits');

// Open the CSV file.
$csv_file = dirname(__FILE__) . '/data/rcgr_permit_app_mast_202503031405.csv';
$logger->notice('Opening CSV file: ' . $csv_file);
$handle = fopen($csv_file, 'r');

if ($handle === FALSE) {
  $logger->error('Could not open CSV file: ' . $csv_file);
  return;
}

// Read the header row and map column names to indices.
$header = fgetcsv($handle);
$csv_map = array_flip($header);

// Process each row.
while (($row = fgetcsv($handle)) !== FALSE) {
  $total++;

  // Check for limit.
  if ($limit > 0 && $total > $limit) {
    break;
  }

  // Extract the permit number.
  $permit_no = isset($row[$csv_map['permit_no']]) ? trim($row[$csv_map['permit_no']]) : '';

  // Skip if permit number is empty.
  if (empty($permit_no)) {
    $logger->notice('Skipping row: Empty permit number');
    $skipped++;
    continue;
  }

  // Check if a node with this permit number already exists.
  $query = \Drupal::entityQuery('node')
    ->condition('type', 'permit')
    ->condition('field_permit_no', $permit_no)
    ->accessCheck(FALSE);

  $nids = $query->execute();

  try {
    if (empty($nids)) {
      $logger->notice('Creating new permit node for: ' . $permit_no);

      // Create a basic node with just essential fields.
      $node = Node::create([
        'type' => 'permit',
        'title' => 'Permit: ' . $permit_no,
        'status' => 1,
        'field_permit_no' => $permit_no,
      ]);

      // Save the node.
      $node->save();
      $logger->notice('Created permit node: ' . $permit_no);
      $created++;
    }
    else {
      $logger->notice('Permit already exists: ' . $permit_no);
      $skipped++;
    }
  }
  catch (\Exception $e) {
    $logger->error('Error processing permit node: ' . $permit_no . '. Error: ' . $e->getMessage());
    $errors++;
  }
}

// Close the file handle.
fclose($handle);

// Log the final statistics.
$logger->notice('Import complete. Total: ' . $total . ', Created: ' . $created . ', Updated: ' . $updated . ', Skipped: ' . $skipped . ', Errors: ' . $errors);
