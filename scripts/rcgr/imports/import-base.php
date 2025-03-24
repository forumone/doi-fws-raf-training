<?php

/**
 * @file
 * Base script for importing taxonomies from RCGR reference CSV files.
 *
 * This is a template to be used by specific vocabulary import scripts.
 */

use Drupal\taxonomy\Entity\Term;
use Drush\Drush;

/**
 * Imports terms from a CSV file into a taxonomy vocabulary.
 *
 * @param array $mapping
 *   Mapping configuration for the vocabulary.
 * @param int $limit
 *   Maximum number of terms to import.
 * @param bool $update_existing
 *   Whether to update existing terms.
 *
 * @return array
 *   Statistics about the import operation.
 */
function import_taxonomy_terms(array $mapping, $limit = PHP_INT_MAX, $update_existing = FALSE) {
  // Get the path to the data directory.
  $data_dir = dirname(dirname(__FILE__)) . '/data/';
  $csv_file = $mapping['csv_file'];
  $input_file = $data_dir . $csv_file;

  // Initialize counters.
  $stats = [
    'processed' => 0,
    'created' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => 0,
  ];

  Drush::logger()->notice("Processing {$mapping['vid']} terms from {$csv_file}...");

  // Open input file.
  $handle = fopen($input_file, 'r');
  if (!$handle) {
    Drush::logger()->error("Error: Could not open input file {$input_file}");
    return $stats;
  }

  // Read header row.
  $header = fgetcsv($handle);
  if (!$header) {
    Drush::logger()->error("Error: Could not read header row from {$csv_file}");
    fclose($handle);
    return $stats;
  }

  // Remove quotes from header values.
  $header = array_map(function ($value) {
    return trim($value, '"');
  }, $header);

  Drush::logger()->notice("CSV Header columns: " . implode(', ', $header));

  // Find the index of the name field in the header.
  $name_field_index = array_search($mapping['name_field'], $header);
  if ($name_field_index === FALSE) {
    Drush::logger()->error("Error: Required name field '{$mapping['name_field']}' not found in CSV header");
    fclose($handle);
    return $stats;
  }

  // Create a mapping of CSV column names to their indices.
  $column_indices = array_flip($header);

  // Process each row.
  $row_number = 1;
  while (($row = fgetcsv($handle)) !== FALSE && $stats['created'] < $limit) {
    $row_number++;
    $stats['processed']++;

    // Remove quotes from values.
    $row = array_map(function ($value) {
      return trim($value, '"');
    }, $row);

    // Handle special row skipping logic if defined in the mapping.
    if (isset($mapping['skip_row_callback']) && is_callable($mapping['skip_row_callback'])) {
      if ($mapping['skip_row_callback']($row, $row_number, $column_indices)) {
        Drush::logger()->warning("Warning: Skipping row based on custom logic");
        continue;
      }
    }
    // Default skip logic for empty rows or separator rows.
    elseif (empty($row) || (count($row) === 1 && empty($row[0]))) {
      Drush::logger()->warning("Warning: Skipping empty row");
      continue;
    }

    // Get the term name from the appropriate column.
    if (isset($mapping['name_callback']) && is_callable($mapping['name_callback'])) {
      $name = $mapping['name_callback']($row, $column_indices);
    }
    else {
      $name = $row[$column_indices[$mapping['name_field']]];
    }

    // Skip rows with empty names or special values.
    if (empty($name) || $name === '---' || $name === 'All') {
      Drush::logger()->warning("Warning: Skipping row with empty or special name");
      continue;
    }

    // Log field values being processed.
    Drush::logger()->notice("Processing field values for term '$name':");
    foreach ($mapping['field_mappings'] as $csv_column => $field_name) {
      if (isset($column_indices[$csv_column])) {
        $value = isset($row[$column_indices[$csv_column]]) ? trim($row[$column_indices[$csv_column]]) : '';
        Drush::logger()->notice("  $csv_column => $field_name: '$value'");
      }
    }

    // Check if term already exists.
    $existing_term = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties([
        'vid' => $mapping['vid'],
        'name' => $name,
      ]);

    if (!empty($existing_term)) {
      $term = reset($existing_term);

      if (!$update_existing) {
        Drush::logger()->warning("Term '$name' already exists in {$mapping['vid']} - skipping");
        $stats['skipped']++;
        continue;
      }

      Drush::logger()->notice("Updating existing term '$name' in {$mapping['vid']}");
    }
    else {
      // Create new term.
      $term = Term::create([
        'vid' => $mapping['vid'],
        'name' => $name,
        'langcode' => 'en',
      ]);
    }

    // Set description if available.
    if (!empty($mapping['description_field']) &&
        isset($column_indices[$mapping['description_field']]) &&
        !empty($row[$column_indices[$mapping['description_field']]])) {
      $description = trim($row[$column_indices[$mapping['description_field']]]);
      $term->setDescription($description);
      Drush::logger()->notice("Setting description: '$description'");
    }

    // Set field values.
    foreach ($mapping['field_mappings'] as $csv_column => $field_name) {
      if (isset($column_indices[$csv_column])) {
        // Get the field value based on the field name and CSV column.
        $value = isset($row[$column_indices[$csv_column]]) ? trim($row[$column_indices[$csv_column]]) : '';

        // Skip empty values.
        if (empty($value)) {
          continue;
        }

        // Debug logs for values.
        Drush::logger()->notice("Setting field $field_name with value '$value'");

        // Handle field values based on field type.
        try {
          // Get the field definition.
          $field_definition = $term->getFieldDefinition($field_name);
          if (!$field_definition) {
            Drush::logger()->warning("Warning: Field definition not found for $field_name");
            continue;
          }

          // Debug the field type.
          $field_type = $field_definition->getType();
          Drush::logger()->notice("Field $field_name is of type: $field_type");

          // Set the field value based on its type.
          switch ($field_type) {
            case 'string':
              // Ensure string values are properly formatted.
              $value = (string) $value;
              Drush::logger()->notice("Setting $field_name to string value: '$value'");
              $term->set($field_name, $value);
              break;

            case 'integer':
              // Handle integer fields.
              $int_value = (int) $value;
              Drush::logger()->notice("Setting $field_name integer value: '$int_value'");
              $term->set($field_name, $int_value);
              break;

            case 'entity_reference':
              // Handle entity references.
              $handler_settings = $field_definition->getSetting('handler_settings');
              $target_bundles = $handler_settings['target_bundles'] ?? [];
              $vocabulary = !empty($target_bundles) ? key($target_bundles) : '';

              if (empty($vocabulary)) {
                Drush::logger()->warning("Warning: Could not determine target vocabulary for $field_name");
                continue;
              }

              Drush::logger()->notice("Looking for referenced term '$value' in vocabulary '$vocabulary'");

              $referenced_term = \Drupal::entityTypeManager()
                ->getStorage('taxonomy_term')
                ->loadByProperties([
                  'vid' => $vocabulary,
                  'name' => $value,
                ]);

              if (!empty($referenced_term)) {
                $referenced_term = reset($referenced_term);
                $term->set($field_name, ['target_id' => $referenced_term->id()]);
                Drush::logger()->notice("Set $field_name reference to term '{$referenced_term->getName()}' (id: {$referenced_term->id()})");
              }
              else {
                Drush::logger()->warning("Referenced term '$value' not found in $vocabulary vocabulary - skipping field");
                continue;
              }
              break;

            default:
              Drush::logger()->warning("Warning: Unsupported field type {$field_definition->getType()} for $field_name");
              continue;
          }
        }
        catch (\Exception $e) {
          Drush::logger()->warning("Warning: Could not set value '$value' for field $field_name: " . $e->getMessage());
          continue;
        }
      }
    }

    try {
      $term->save();
      if (!empty($existing_term)) {
        Drush::logger()->success("Updated term '$name' in {$mapping['vid']} vocabulary");
        $stats['updated']++;
      }
      else {
        Drush::logger()->success("Created term '$name' in {$mapping['vid']} vocabulary");
        $stats['created']++;
      }

      // Execute post-save callback if defined.
      if (isset($mapping['post_save_callback']) && is_callable($mapping['post_save_callback'])) {
        $mapping['post_save_callback']($term, $row, $column_indices);
      }
    }
    catch (\Exception $e) {
      Drush::logger()->error("Error saving term '$name' in {$mapping['vid']} vocabulary: " . $e->getMessage());
      $stats['errors']++;
    }
  }

  fclose($handle);

  // Report statistics for this taxonomy.
  Drush::logger()->notice("Completed import for {$mapping['vid']}:");
  Drush::logger()->notice("  Total rows processed: {$stats['processed']}");
  Drush::logger()->notice("  Terms created: {$stats['created']}");
  Drush::logger()->notice("  Terms updated: {$stats['updated']}");
  Drush::logger()->notice("  Terms skipped: {$stats['skipped']}");
  Drush::logger()->notice("  Errors: {$stats['errors']}");

  return $stats;
}
