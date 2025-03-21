<?php

/**
 * @file
 * Script to remove passwords from RCGR userprofile CSV data.
 *
 * Usage: ddev drush scr scripts/rcgr/remove_passwords.php.
 */

// Get the current timestamp for file naming.
$timestamp = date('YmdHi');

/**
 * Simple output function.
 */
function output($message) {
  echo $message . "\n";
}

// When running with drush scr, the working directory is the Drupal docroot (web)
// We need to go one level up to get to the project root.
$project_root = dirname(getcwd());

$input_file = $project_root . '/scripts/rcgr/data/rcgr_userprofile_202503031405.csv';
$output_file = $project_root . "/scripts/rcgr/data/rcgr_userprofile_no_passwords_{$timestamp}.csv";
$backup_file = $project_root . "/scripts/rcgr/data/rcgr_userprofile_202503031405_backup_{$timestamp}.csv";

output("Working with files:");
output("- Input: {$input_file}");
output("- Output: {$output_file}");
output("- Backup: {$backup_file}");

// Make a backup of the original file.
output("Creating backup of the original file...");
if (file_exists($input_file) && copy($input_file, $backup_file)) {
  output("Backup created: {$backup_file}");
}
else {
  output("Failed to create backup file. Input file exists: " . (file_exists($input_file) ? 'YES' : 'NO'));
  output("Aborting.");
  return;
}

// Open input file.
$input = fopen($input_file, 'r');
if (!$input) {
  output("Error: Could not open input file {$input_file}");
  return;
}

// Open output file.
$output = fopen($output_file, 'w');
if (!$output) {
  output("Error: Could not create output file {$output_file}");
  fclose($input);
  return;
}

// Get header row and find the password column index.
$header = fgetcsv($input);
$password_index = array_search('userpw', $header);

if ($password_index === FALSE) {
  output("Error: Password column 'userpw' not found in the CSV header.");
  fclose($input);
  fclose($output);
  return;
}

// Write the header row to the output file.
fputcsv($output, $header);

// Process each row, replacing the password with an empty string.
$rows_processed = 0;
while (($row = fgetcsv($input)) !== FALSE) {
  if (isset($row[$password_index])) {
    // Remove the password.
    $row[$password_index] = "";
  }
  fputcsv($output, $row);
  $rows_processed++;
}

// Close the files.
fclose($input);
fclose($output);

output("Successfully processed {$rows_processed} user profiles.");
output("Passwords removed and saved to {$output_file}");
