<?php

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\field\Entity\FieldConfig;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;

// Update parse_csv_file to include error handling
function parse_csv_file($filepath) {
  if (!file_exists($filepath)) {
    echo "Error: File not found at {$filepath}\n";
    return [];
  }
  
  try {
    $rows = array_map('str_getcsv', file($filepath));
    if (empty($rows)) {
      echo "Warning: No data found in {$filepath}\n";
      return [];
    }
    
    $header = array_shift($rows);
    echo "Found " . count($rows) . " rows in {$filepath}\n";
    $data = [];
    foreach ($rows as $row) {
      $data[] = array_combine($header, $row);
    }
    return $data;
  } catch (Exception $e) {
    echo "Error parsing CSV: " . $e->getMessage() . "\n";
    return [];
  }
}

// Function to get all parameters for a test ID
function get_test_parameters($params_data, $test_id) {
  $test_params = [];
  foreach ($params_data as $param) {
    if ($param['TEST_ID'] == $test_id) {
      $param_name = $param['PARAM_NAME'];
      $param_value = $param['PARAM_VALUE'];
      
      // Handle multiple values for same parameter
      if (!isset($test_params[$param_name])) {
        $test_params[$param_name] = [];
      }
      $test_params[$param_name][] = $param_value;
    }
  }
  return $test_params;
}

// Function to get test data from TEST.csv
function get_test_answers($filepath) {
  $answers = [];
  if (($handle = fopen($filepath, "r")) !== FALSE) {
    $headers = fgetcsv($handle);
    while (($data = fgetcsv($handle)) !== FALSE) {
      $row = array_combine($headers, $data);
      $test_id = $row['TEST_ID'];
      $answers[$test_id] = [
        'bird_count' => $row['BIRD_COUNT'] ?? null,
        'photo_path' => $row['PHOTO'] ?? null,
        'video_path' => $row['VIDEO'] ?? null,
        'correct_species' => $row['SPECIES'] ?? null,
      ];
    }
    fclose($handle);
  }
  return $answers;
}

// Function to get taxonomy term ID by name and vocabulary
function get_term_id_by_name($name, $vocabulary) {
  $terms = \Drupal::entityTypeManager()
    ->getStorage('taxonomy_term')
    ->loadByProperties([
      'name' => $name,
      'vid' => $vocabulary,
    ]);
  
  if (!empty($terms)) {
    return reset($terms)->id();
  }
  
  return null;
}

// Function to map difficulty level to taxonomy term ID
function map_difficulty_level($level) {
  $map = [
    'Beginner/Refresher (10 seconds to view image)' => 'Beginner Image',
    'Beginner/Refresher (10 seconds video)' => 'Beginner Video',
    'Moderate (6 seconds to view image)' => 'Moderate Image',
    'Moderate (5 seconds video)' => 'Moderate Video', 
    'Challenging (3 seconds to view image)' => 'Challenging Image',
    'Challenging (3 seconds video)' => 'Challenging Video'
  ];
  
  $term_name = $map[$level] ?? '';
  if ($term_name) {
    return get_term_id_by_name($term_name, 'difficulty_level');
  }
  return null;
}

// Function to map range to taxonomy term ID
function map_range($range) {
  $map = [
    '<100' => 'Under 100',
    '100-500' => '100-500',
    '500-3,000' => '500-3000',
    '>3,000 (images always displayed for 15 secs)' => 'Over 3000',
    'ANY' => 'Any'
  ];
  
  $term_name = $map[$range] ?? '';
  if ($term_name) {
    return get_term_id_by_name($term_name, 'count_ranges');
  }
  return null;
}

// Function to validate configurations
function validate_configurations() {
  $required_content_types = [
    'species_counting_results' => [
      'fields' => [
        'field_count_difficulty', // Updated field name
        'field_count_ranges',
        'field_species_image'
      ]
    ],
    'species_id_results' => [
      'fields' => [
        'field_id_difficulty', // Updated field name
        'field_species_groups',
        'field_regions',
        'field_species_video'
      ]
    ]
  ];

  $required_taxonomies = [
    'difficulty_level' => [
      'Beginner Image',
      'Beginner Video',
      'Moderate Image',  
      'Moderate Video',
      'Challenging Image',
      'Challenging Video'
    ],
    'count_ranges' => [
      'Under 100',
      '100-500', 
      '500-3000',
      'Over 3000',
      'Any'
    ],
    'species_groups' => [
      'Dabbling Ducks',
      'Diving Ducks',
      'Sea Ducks',
      'Geese, Swans and Cranes',
      'Whistling Ducks',
      'Other Non-waterfowl (video only; not narrated)',
      'ALL species'
    ],
    'regions' => [
      'Alaska',
      'Arctic Tundra',
      'Boreal and Taiga',
      'Prairies and Parklands',
      'Pacific Coastal',
      'Intermountain West',
      'NE U.S. and Eastern Canada',
      'Atlantic Coastal',
      'Southeast U.S.',
      'ALL Regions and Habitats'
    ]
  ];

  $errors = [];

  // Check content types and their fields
  foreach ($required_content_types as $type => $config) {
    if (!\Drupal::entityTypeManager()->getStorage('node_type')->load($type)) {
      $errors[] = "Missing content type: $type";
      continue;
    }

    foreach ($config['fields'] as $field) {
      if (!FieldConfig::loadByName('node', $type, $field)) {
        $errors[] = "Missing field $field on content type $type";
      }
    }
  }

  // Check taxonomies and their terms
  foreach ($required_taxonomies as $vocab => $terms) {
    if (!\Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load($vocab)) {
      $errors[] = "Missing vocabulary: $vocab";
      continue;
    }

    foreach ($terms as $term) {
      if (!get_term_id_by_name($term, $vocab)) {
        $errors[] = "Missing term '$term' in vocabulary '$vocab'";
      }
    }
  }

  return $errors;
}

// Function to get file metadata
function get_file_metadata($type = 'photo') {
  $metadata = [];
  $filepath = __DIR__ . '/data/' . ($type === 'photo' ? 'PHOTO_FILE_METADATA.csv' : 'VIDEO_FILE_SPECIES_CHOICE.csv');
  
  if (!file_exists($filepath)) {
    echo "Warning: Metadata file not found: {$filepath}\n";
    return [];
  }
  
  $data = parse_csv_file($filepath);
  foreach ($data as $row) {
    $file_id = $row['FILE_ID'];
    $metadata[$file_id] = $row;
  }
  
  return $metadata;
}

// Function to get media entity by filename
function get_media_entity($file_id, $type = 'image') {
  $media_storage = \Drupal::entityTypeManager()->getStorage('media');
  
  if ($type === 'video') {
    // Video files follow pattern: [FILE_ID]_2030kbps.mp4
    $filename_pattern = $file_id . '_2030kbps.mp4';
    $query = $media_storage->getQuery()
      ->condition('bundle', $type)
      ->condition('name', '%' . $filename_pattern, 'LIKE')
      ->range(0, 1);
  } else {
    // Photos use direct file ID lookup from metadata
    $query = $media_storage->getQuery()
      ->condition('bundle', $type)
      ->condition('name', $file_id)
      ->range(0, 1);
  }
  
  $media_ids = $query->execute();
  if (!empty($media_ids)) {
    $media_id = reset($media_ids);
    echo "Found existing media entity {$media_id} for file ID {$file_id}\n";
    return $media_id;
  }
  
  echo "Warning: No media entity found for file ID {$file_id}\n";
  return null;
}

// Main import logic
function import_test_data() {
  echo "Starting configuration validation...\n";
  
  // Validate configurations first
  $errors = validate_configurations();
  if (!empty($errors)) {
    echo "Configuration errors found:\n";
    foreach ($errors as $error) {
      echo "- $error\n";
    }
    echo "Please fix these issues before running the import.\n";
    return;
  }
  
  echo "Configuration valid.\n";
  
  // Load CSV files with absolute paths
  $module_path = \Drupal::service('extension.list.module')->getPath('raf_aerial');
  $param_file = $module_path . '/data/TEST_PARAM.csv';
  $test_file = $module_path . '/data/TEST.csv';
  
  echo "Looking for parameter file at: {$param_file}\n";
  echo "Looking for test file at: {$test_file}\n";
  
  $params_data = parse_csv_file($param_file);
  if (empty($params_data)) {
    echo "Error: No parameter data found. Aborting import.\n";
    return;
  }
  
  $test_answers = get_test_answers($test_file);
  if (empty($test_answers)) {
    echo "Error: No test data found. Aborting import.\n";
    return;
  }
  
  // Load file metadata
  $photo_metadata = get_file_metadata('photo');
  $video_metadata = get_file_metadata('video');
  
  // Get unique test IDs
  $test_ids = array_unique(array_column($params_data, 'TEST_ID'));
  echo "Found " . count($test_ids) . " unique test IDs\n";
  
  // Process each test
  foreach ($test_ids as $test_id) {
    $params = get_test_parameters($params_data, $test_id);
    $answer_data = $test_answers[$test_id] ?? null;
    
    // Skip if no parameters found
    if (empty($params) || empty($answer_data)) {
      continue;
    }
    
    // Determine content type based on parameters
    $is_counting = isset($params['RANGE']);
    $content_type = $is_counting ? 'species_counting_results' : 'species_id_results';
    
    // Create node
    $node = Node::create([
      'type' => $content_type,
      'title' => 'Test ' . $test_id,
      'status' => 1
    ]);
    
    // Set difficulty level using correct field
    if (isset($params['LEVEL'][0])) {
      $difficulty_tid = map_difficulty_level($params['LEVEL'][0]);
      if ($difficulty_tid) {
        $field_name = $is_counting ? 'field_count_difficulty' : 'field_id_difficulty';
        $node->set($field_name, ['target_id' => $difficulty_tid]);
      }
    }
    
    if ($is_counting) {
      // Set count ranges
      $range_tids = [];
      foreach ($params['RANGE'] as $range) {
        $range_tid = map_range($range);
        if ($range_tid) {
          $range_tids[] = ['target_id' => $range_tid];
        }
      }
      if (!empty($range_tids)) {
        $node->set('field_count_ranges', $range_tids);
      }

      // Set correct bird count
      if (!empty($answer_data['bird_count'])) {
        $node->set('field_bird_count', $answer_data['bird_count']);
      }
      
      // Link to existing species image using file ID
      if (!empty($answer_data['photo_path'])) {
        $file_id = basename($answer_data['photo_path']);
        if (isset($photo_metadata[$file_id])) {
          $media_id = get_media_entity($file_id, 'image');
          if ($media_id) {
            $node->set('field_species_image', ['target_id' => $media_id]);
          }
        }
      }
      
    } else {
      // Set species groups and regions
      if (isset($params['GROUP'])) {
        $group_terms = [];
        foreach ($params['GROUP'] as $group) {
          $tid = get_term_id_by_name($group, 'species_groups');
          if ($tid) {
            $group_terms[] = ['target_id' => $tid];
          }
        }
        if (!empty($group_terms)) {
          $node->set('field_species_groups', $group_terms);
        }
      }
      
      if (isset($params['REGION'])) {
        $region_terms = [];
        foreach ($params['REGION'] as $region) {
          $tid = get_term_id_by_name($region, 'regions');
          if ($tid) {
            $region_terms[] = ['target_id' => $tid];
          }
        }
        if (!empty($region_terms)) {
          $node->set('field_regions', $region_terms);
        }
      }

      // Link to existing species video using file ID
      if (!empty($answer_data['video_path'])) {
        $file_id = basename($answer_data['video_path']);
        if (isset($video_metadata[$file_id])) {
          $media_id = get_media_entity($file_id, 'video');
          if ($media_id) {
            $node->set('field_species_video', ['target_id' => $media_id]);
          }
        }
      }
      
      // Set correct species if available
      if (!empty($answer_data['correct_species'])) {
        $species_tid = get_term_id_by_name($answer_data['correct_species'], 'species');
        if ($species_tid) {
          $node->set('field_correct_species', ['target_id' => $species_tid]);
        }
      }
    }
    
    try {
      $node->save();
      echo "Created {$content_type} node for Test {$test_id}\n";
    } catch (Exception $e) {
      echo "Error creating node for Test {$test_id}: " . $e->getMessage() . "\n";
    }
  }
}

// Execute import with error handling
try {
  echo "Starting import process...\n";
  import_test_data();
  echo "Import complete!\n";
} catch (Exception $e) {
  echo "Fatal error during import: " . $e->getMessage() . "\n";
  echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
