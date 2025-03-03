#!/usr/bin/env php
<?php

/**
 * @file
 * Drush script to import species image data from CSV into the species_image content type.
 *
 * Usage: drush scr scripts/falcon/import_species_images.php.
 *
 * This script processes a CSV file containing species image data, including binary image data.
 * For each row in the CSV:
 * 1. It extracts the record number (recno) to identify the species image.
 * 2. It checks if a node with this record number already exists and updates it, or creates a new one.
 * 3. It processes the binary image data from the CSV:
 *    - Checks if the data is base64 encoded and decodes it if necessary.
 *    - Validates the image data and detects the MIME type.
 *    - Saves the image to the Falcon site's files directory using the name_of_image field value.
 *    - Associates the saved file with the species_image node.
 * 4. It sets other field values from the CSV data.
 * 5. It saves the node and reports the results.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;

// Define the CSV file path.
// Since the script runs from /var/www/html/web, we need to use a relative path from there.
$csv_file = 'sites/falcon/files/falcon-data/falc_dad_species_image_202502271311.csv';

// Check if the file exists.
if (!file_exists($csv_file)) {
  print("CSV file not found at: $csv_file\n");
  print("Please place the CSV file at the correct location and try again.\n");
  exit(1);
}

// Initialize counters.
$row_count = 0;
$created_count = 0;
$updated_count = 0;
$error_count = 0;

// Open the CSV file.
$handle = fopen($csv_file, 'r');
if (!$handle) {
  print("Error opening CSV file.\n");
  exit(1);
}

// Read the header row.
$header = fgetcsv($handle);
if (!$header) {
  print("Error reading CSV header.\n");
  fclose($handle);
  exit(1);
}

// Map CSV columns to field names.
$field_mapping = [
  'recno' => 'field_recno',
  'recno_3186a' => 'field_recno_3186a',
  'owner_state' => 'field_owner_state',
  'authorized_cd' => 'field_authorized_cd',
  'name_of_image' => 'field_name_of_image',
  'type_of_image' => 'field_type_of_image',
  'species_image' => 'field_species_image',
  // 'image_extension' => 'field_image_extension',
  // 'image_size' => 'field_image_size',
  // 'image_size_view' => 'field_image_size_view',
  // 'dt_create' => 'field_dt_create',
  // 'dt_update' => 'field_dt_update',
];

// Create an array to map CSV column indices to field names.
$column_mapping = [];
foreach ($header as $index => $column_name) {
  if (isset($field_mapping[$column_name])) {
    $column_mapping[$index] = $field_mapping[$column_name];
  }
}

// Process each row.
while (($data = fgetcsv($handle)) !== FALSE) {
  $row_count++;

  try {
    // Extract the record number for use as a unique identifier.
    $recno = NULL;
    foreach ($data as $index => $value) {
      if (isset($column_mapping[$index]) && $column_mapping[$index] === 'field_recno') {
        $recno = $value;
        break;
      }
    }

    if (empty($recno)) {
      print("Row $row_count: Missing record number, skipping.\n");
      continue;
    }

    // Check if a node with this record number already exists.
    $existing_nodes = \Drupal::entityTypeManager()
      ->getStorage('node')
      ->loadByProperties([
        'type' => 'species_image',
        'field_recno' => $recno,
      ]);

    if (!empty($existing_nodes)) {
      // Update existing node.
      $node = reset($existing_nodes);
      $is_new = FALSE;
    }
    else {
      // Create a new node.
      $node = Node::create([
        'type' => 'species_image',
        'title' => "Species Image $recno",
        'status' => 1,
      ]);
      $is_new = TRUE;
    }

    // Set field values from CSV data.
    foreach ($data as $index => $value) {
      if (isset($column_mapping[$index])) {
        $field_name = $column_mapping[$index];

        // Skip empty values.
        if (empty($value) && $value !== '0') {
          continue;
        }

        // Handle different field types.
        switch ($field_name) {
          case 'field_species_image':
            // Get the name of the image from the CSV data.
            $image_name = '';
            foreach ($data as $img_index => $img_value) {
              if (isset($column_mapping[$img_index]) && $column_mapping[$img_index] === 'field_name_of_image') {
                $image_name = $img_value;
                break;
              }
            }

            if (empty($image_name)) {
              print("Row $row_count: Missing image name, skipping image processing.\n");
              break;
            }

            // Check if we have valid binary data.
            if (empty($value)) {
              print("Row $row_count: Empty image data, skipping.\n");
              break;
            }

            // Convert the SQL Server BLOB data to binary.
            try {
              $value = convert_to_binary($value);
              print("Row $row_count: Converted SQL Server BLOB data to binary.\n");
            }
            catch (Exception $e) {
              print("Row $row_count: Failed to convert BLOB data: " . $e->getMessage() . "\n");
              break;
            }

            // Check if the converted data is valid.
            if (empty($value)) {
              print("Row $row_count: Conversion resulted in empty data, skipping.\n");
              break;
            }

            // Create a sanitized filename.
            $sanitized_filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $image_name);

            // Extract extension from the original filename.
            $original_extension = strtolower(pathinfo($sanitized_filename, PATHINFO_EXTENSION));
            $valid_extensions = ['jpg', 'jpeg', 'png', 'gif'];

            // If no valid extension exists, add a default one.
            if (!in_array($original_extension, $valid_extensions)) {
              // Try to determine extension from the binary data
              // Use Drupal's MIME type guesser service.
              $mime_type_guesser = \Drupal::service('file.mime_type.guesser');
              $temp_mime = $mime_type_guesser->guessMimeType('', $value);

              $mime_to_ext = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
              ];

              if (isset($mime_to_ext[$temp_mime])) {
                $sanitized_filename .= '.' . $mime_to_ext[$temp_mime];
                print("Row $row_count: Added extension ." . $mime_to_ext[$temp_mime] . " based on detected MIME type.\n");
              }
              else {
                // Default to jpg if no extension and can't detect.
                $sanitized_filename .= '.jpg';
                print("Row $row_count: Added default .jpg extension.\n");
              }
            }

            // Define the directory and URI for the file.
            // Use the falcon site's files directory instead of default.
            $species_images_dir = 'species_images';

            // Create the physical directory in the Falcon site's files directory.
            $physical_directory = DRUPAL_ROOT . '/sites/falcon/files/' . $species_images_dir;
            if (!file_exists($physical_directory)) {
              mkdir($physical_directory, 0775, TRUE);
              print("Created directory: $physical_directory\n");
            }

            // Create the direct file path to save to.
            $file_path = $physical_directory . '/' . $sanitized_filename;

            // Also create a URI that will be used for the file entity.
            // This URI should be accessible via the web without the /sites/default/files prefix.
            $file_uri = 'sites/falcon/files/' . $species_images_dir . '/' . $sanitized_filename;

            // Save the binary data directly to the file.
            try {
              // Determine MIME type from file extension first.
              $mime_type = NULL;
              $extension = strtolower(pathinfo($sanitized_filename, PATHINFO_EXTENSION));

              // Map common image extensions to MIME types.
              $extension_mime_map = [
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
              ];

              if (isset($extension_mime_map[$extension])) {
                $mime_type = $extension_mime_map[$extension];
                print("Row $row_count: Using MIME type $mime_type based on file extension.\n");
              }
              else {
                // Fallback to detecting from binary data.
                $mime_type_guesser = \Drupal::service('file.mime_type.guesser');
                $mime_type = $mime_type_guesser->guessMimeType('', $value);
                print("Row $row_count: Detected MIME type $mime_type from binary data.\n");
              }

              // Validate mime type.
              $valid_mime_types = ['image/jpeg', 'image/png', 'image/gif'];
              if (!in_array($mime_type, $valid_mime_types)) {
                print("Row $row_count: Invalid image type detected: $mime_type. Skipping.\n");
                break;
              }

              print("Row $row_count: Processing image of type $mime_type.\n");

              // Write the binary data directly to the file using binary mode.
              $fp = fopen($file_path, 'wb');
              if ($fp) {
                $bytes_written = fwrite($fp, $value);
                fclose($fp);

                if ($bytes_written !== FALSE) {
                  print("Row $row_count: Successfully saved file to: $file_path ($bytes_written bytes written)\n");

                  // Create a file entity that references the file.
                  // Use a direct URI that doesn't use the public:// scheme.
                  $file = File::create([
                    'uri' => $file_uri,
                    'filename' => $sanitized_filename,
                    'filemime' => $mime_type,
                  // 1 = permanent
                    'status' => 1,
                  ]);
                  $file->save();

                  print("Row $row_count: Created file entity with URI: " . $file->getFileUri() . "\n");

                  // Associate the file with the node.
                  $node->set($field_name, [
                    'target_id' => $file->id(),
                    'alt' => $image_name,
                    'title' => $image_name,
                  ]);

                  print("Row $row_count: Saved image file: $sanitized_filename\n");
                }
                else {
                  print("Row $row_count: Failed to write data to file.\n");
                }
              }
              else {
                print("Row $row_count: Failed to open file for writing.\n");
              }
            }
            catch (\Exception $e) {
              print("Row $row_count: Error saving image file: " . $e->getMessage() . "\n");
            }
            break;

          case 'field_image_size':
            // Convert to integer.
            $node->set($field_name, (int) $value);
            break;

          case 'field_dt_create':
          case 'field_dt_update':
            // Convert to datetime format if not empty.
            if (!empty($value)) {
              try {
                $date = new DateTime($value);
                $node->set($field_name, $date->format('Y-m-d\TH:i:s'));
              }
              catch (Exception $e) {
                print("Row $row_count: Invalid date format for $field_name: $value\n");
              }
            }
            break;

          default:
            // Default handling for string fields.
            $node->set($field_name, $value);
            break;
        }
      }
    }

    // Save the node.
    $node->save();

    if ($is_new) {
      $created_count++;
      print("Created species image node for record $recno\n");
    }
    else {
      $updated_count++;
      print("Updated species image node for record $recno\n");
    }
  }
  catch (EntityStorageException $e) {
    print("Row $row_count: Error saving node: " . $e->getMessage() . "\n");
    $error_count++;
  }
  catch (Exception $e) {
    print("Row $row_count: General error: " . $e->getMessage() . "\n");
    $error_count++;
  }
}

// Close the CSV file.
fclose($handle);

// Print summary.
print("\nImport completed:\n");
print("Total rows processed: $row_count\n");
print("Nodes created: $created_count\n");
print("Nodes updated: $updated_count\n");
print("Errors: $error_count\n");
print("\nDone.\n");

/**
 * Converts SQL Server varbinary data to binary.
 */
function convert_to_binary($escapedString) {
  // Debug the input.
  $input_length = strlen($escapedString);
  print("Input string length: $input_length bytes\n");

  // Check for common SQL Server BLOB prefixes and remove them if present.
  if (substr($escapedString, 0, 2) === '0x') {
    $escapedString = substr($escapedString, 2);
    print("Removed '0x' prefix from SQL Server varbinary data.\n");
  }

  // For SQL Server varbinary data, it's typically hex encoded.
  // If the string consists of only hex characters and has an even length,
  // it's very likely to be hex encoded binary data.
  if (ctype_xdigit($escapedString) && strlen($escapedString) % 2 === 0) {
    print("Data appears to be SQL Server varbinary (hex encoded), converting to binary.\n");
    $hex_binary = hex2bin($escapedString);
    if ($hex_binary !== FALSE) {
      print("Successfully converted SQL Server varbinary data to binary.\n");

      // Debug the output.
      $output_length = strlen($hex_binary);
      print("Output binary length: $output_length bytes\n");

      return $hex_binary;
    }
  }

  // If we get here, the data might not be in the expected format.
  // Try other conversion methods as fallbacks.
  // Replace Unicode escape sequences.
  $binary = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
    return chr(hexdec($matches[1]));
  }, $escapedString);

  // Replace escaped characters like \r, \n, etc.
  $binary = stripcslashes($binary);

  // Check if the data might be base64 encoded as a fallback.
  if (strlen($binary) < 10 || !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $binary)) {
    // If the binary result is too short or doesn't contain binary data,
    // try base64 decoding as a fallback.
    $base64_decoded = base64_decode($escapedString, TRUE);
    if ($base64_decoded !== FALSE && strlen($base64_decoded) > strlen($binary)) {
      $binary = $base64_decoded;
      print("Fallback: Successfully converted base64 data to binary.\n");
    }
  }

  // Debug the output.
  $output_length = strlen($binary);
  print("Output binary length: $output_length bytes\n");

  return $binary;
}
