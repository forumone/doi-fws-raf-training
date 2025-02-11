#!/usr/bin/env php
<?php

/**
 * @file
 * Scrub passwords from _USER_.csv file.
 *
 * This script reads the _USER_.csv file, removes the password column data,
 * and creates a new file with passwords removed while preserving other data.
 */

// Define file paths.
$input_file = dirname(__FILE__) . '/data/_USER_.csv';
$output_file = dirname(__FILE__) . '/data/_USER_.scrubbed.csv';
$backup_file = dirname(__FILE__) . '/data/_USER_.backup.csv';

// Verify input file exists.
if (!file_exists($input_file)) {
  die("Input CSV file not found at: $input_file\n");
}

// Create backup of original file.
if (!copy($input_file, $backup_file)) {
  die("Failed to create backup file at: $backup_file\n");
}

echo "Created backup at: $backup_file\n";

// Open input and output files.
$input_handle = fopen($input_file, 'r');
$output_handle = fopen($output_file, 'w');

if (!$input_handle || !$output_handle) {
  die("Failed to open input or output file\n");
}

$count = 0;
$errors = [];

// Process each row.
while (($data = fgetcsv($input_handle)) !== FALSE) {
  if (count($data) < 3) {
    $errors[] = "Invalid row format: " . implode(',', $data);
    continue;
  }

  // Preserve ID, name, and email but set password to empty string.
  $scrubbed_row = [
  // ID.
    $data[0],
  // Name.
    $data[1],
  // Email.
    $data[2],
  // Empty password.
    '',
  ];

  // Write scrubbed row to output file.
  if (fputcsv($output_handle, $scrubbed_row) === FALSE) {
    $errors[] = "Failed to write row: " . implode(',', $scrubbed_row);
    continue;
  }

  $count++;
}

// Close file handles.
fclose($input_handle);
fclose($output_handle);

echo "\nPassword scrubbing completed.\n";
echo "Successfully processed $count rows.\n";
echo "Scrubbed file saved to: $output_file\n";

if (!empty($errors)) {
  echo "\nErrors encountered:\n";
  foreach ($errors as $error) {
    echo "- $error\n";
  }
}

// Offer to replace original file.
echo "\nWould you like to replace the original file with the scrubbed version? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = fgets($handle);
if (trim(strtolower($line)) === 'y') {
  if (rename($output_file, $input_file)) {
    echo "Original file replaced with scrubbed version.\n";
    echo "Original backup preserved at: $backup_file\n";
  }
  else {
    echo "Failed to replace original file.\n";
    echo "Scrubbed version remains at: $output_file\n";
    echo "Original backup preserved at: $backup_file\n";
  }
}
fclose($handle);
