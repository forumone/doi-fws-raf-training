<?php

/**
 * @file
 * Drush script to export sighting nodes to CSV.
 *
 * Usage: drush scr export-observations.php.
 */

use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\ClientInterface;

// Get all sighting node IDs.
$query = \Drupal::entityQuery('node')
  ->condition('type', 'sighting')
  // Only published nodes.
  ->condition('status', 1)
  ->accessCheck(FALSE);
$nids = $query->execute();

// Define CSV headers based on the field structure.
$headers = [
  'Node ID',
  'Title',
  'Date & Time',
  'Habitat',
  'Location',
  'State',
  'Method',
  'Notes',
  'Number of Cranes',
  'Spotter Username',
  'Created Date',
];

// First, read existing export if it exists to preserve existing state values.
$existing_states = [];
$existing_file = '../scripts/sightings_export.csv';
if (file_exists($existing_file)) {
  print("Found existing export file, reading state values...\n");
  $handle = fopen($existing_file, 'r');
  $headers_check = fgetcsv($handle);

  // Find the position of Node ID and State columns.
  $node_id_pos = array_search('Node ID', $headers_check);
  $state_pos = array_search('State', $headers_check);

  if ($node_id_pos !== FALSE && $state_pos !== FALSE) {
    while (($row = fgetcsv($handle)) !== FALSE) {
      if (!empty($row[$state_pos])) {
        $existing_states[$row[$node_id_pos]] = $row[$state_pos];
      }
    }
  }
  fclose($handle);
  print("Found " . count($existing_states) . " existing state values.\n");
}

// Create CSV file.
$filename = '../scripts/sightings_export_' . date('Y-m-d_H-i-s') . '.csv';
$file = fopen($filename, 'w');

// Write headers.
fputcsv($file, $headers);

// Initialize HTTP client for geocoding.
$client = \Drupal::httpClient();

/**
 * Get state/province from coordinates using Nominatim API.
 *
 * @param \GuzzleHttp\ClientInterface $client
 *   The HTTP client.
 * @param float $lat
 *   The latitude.
 * @param float $lng
 *   The longitude.
 *
 * @return string
 *   The state/province code or empty string if not found.
 */
function get_state_from_coordinates(ClientInterface $client, $lat, $lng) {
  try {
    // Add a user agent as required by Nominatim's usage policy.
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&zoom=5";
    $response = $client->get($url, [
      'headers' => [
        'User-Agent' => 'FWS-RAF-Project/1.0',
      ],
    ]);
    $data = json_decode($response->getBody(), TRUE);

    // Extract state/province from address data.
    if (!empty($data['address'])) {
      // Try different possible fields for state/province.
      $state = $data['address']['state'] ??
               $data['address']['province'] ??
               $data['address']['state_code'] ??
               $data['address']['province_code'] ?? '';

      // If we got a full name and it's in the US or Canada, try to convert to abbreviation.
      if (!empty($state)) {
        // Common US state mappings.
        $us_states = [
          'Alabama' => 'AL',
          'Alaska' => 'AK',
          'Arizona' => 'AZ',
          'Arkansas' => 'AR',
          'California' => 'CA',
          'Colorado' => 'CO',
          'Connecticut' => 'CT',
          'Delaware' => 'DE',
          'Florida' => 'FL',
          'Georgia' => 'GA',
          'Hawaii' => 'HI',
          'Idaho' => 'ID',
          'Illinois' => 'IL',
          'Indiana' => 'IN',
          'Iowa' => 'IA',
          'Kansas' => 'KS',
          'Kentucky' => 'KY',
          'Louisiana' => 'LA',
          'Maine' => 'ME',
          'Maryland' => 'MD',
          'Massachusetts' => 'MA',
          'Michigan' => 'MI',
          'Minnesota' => 'MN',
          'Mississippi' => 'MS',
          'Missouri' => 'MO',
          'Montana' => 'MT',
          'Nebraska' => 'NE',
          'Nevada' => 'NV',
          'New Hampshire' => 'NH',
          'New Jersey' => 'NJ',
          'New Mexico' => 'NM',
          'New York' => 'NY',
          'North Carolina' => 'NC',
          'North Dakota' => 'ND',
          'Ohio' => 'OH',
          'Oklahoma' => 'OK',
          'Oregon' => 'OR',
          'Pennsylvania' => 'PA',
          'Rhode Island' => 'RI',
          'South Carolina' => 'SC',
          'South Dakota' => 'SD',
          'Tennessee' => 'TN',
          'Texas' => 'TX',
          'Utah' => 'UT',
          'Vermont' => 'VT',
          'Virginia' => 'VA',
          'Washington' => 'WA',
          'West Virginia' => 'WV',
          'Wisconsin' => 'WI',
          'Wyoming' => 'WY',
        ];

        // Canadian province mappings.
        $ca_provinces = [
          'Alberta' => 'AB',
          'British Columbia' => 'BC',
          'Manitoba' => 'MB',
          'New Brunswick' => 'NB',
          'Newfoundland and Labrador' => 'NL',
          'Nova Scotia' => 'NS',
          'Ontario' => 'ON',
          'Prince Edward Island' => 'PE',
          'Quebec' => 'QC',
          'Saskatchewan' => 'SK',
          'Northwest Territories' => 'NT',
          'Nunavut' => 'NU',
          'Yukon' => 'YT',
        ];

        // Check if we have a mapping for this state/province.
        $state = $us_states[$state] ?? $ca_provinces[$state] ?? $state;
      }

      return $state;
    }
  }
  catch (GuzzleException $e) {
    return '';
  }

  return '';
}

// Load and write sighting data.
$total = count($nids);
$current = 0;
$looked_up = 0;

foreach ($nids as $nid) {
  $node = Node::load($nid);
  $current++;

  if ($node) {
    // Get field values, handling potential empty fields.
    $date_time = $node->field_date_time->value ?? '';
    if ($date_time) {
      $date_time = date('Y-m-d H:i:s', strtotime($date_time));
    }

    // For list fields, get the selected value.
    $habitat = $node->field_habitat->value ?? '';

    // For geolocation field, combine lat/long and get state.
    $location = '';
    $state = '';
    if ($node->field_location && !$node->field_location->isEmpty()) {
      $lat = $node->field_location->lat;
      $lng = $node->field_location->lng;
      $location = "$lat, $lng";

      // Check if we already have a state value for this node.
      if (isset($existing_states[$node->id()])) {
        $state = $existing_states[$node->id()];
      }
      else {
        // Get state/province from coordinates.
        $state = get_state_from_coordinates($client, $lat, $lng);
        $looked_up++;

        // Add a small delay to respect Nominatim's usage policy (1 request per second).
        if ($current < $total) {
          sleep(1);
        }
      }
    }

    $method = $node->field_method->value ?? '';
    $notes = $node->field_notes->value ?? '';
    $bird_count = $node->field_bird_count->value ?? '';

    // Get node owner username.
    $spotter_username = '';
    $owner_id = $node->getOwnerId();
    if ($owner_id) {
      $owner = User::load($owner_id);
      if ($owner) {
        $spotter_username = $owner->getAccountName();
      }
    }

    $created = $node->getCreatedTime() ? date('Y-m-d H:i:s', $node->getCreatedTime()) : '';

    // Prepare row data.
    $row = [
      $node->id(),
      $node->getTitle(),
      $date_time,
      $habitat,
      $location,
      $state,
      $method,
      $notes,
      $bird_count,
      $spotter_username,
      $created,
    ];

    // Write row to CSV.
    fputcsv($file, $row);

    // Show progress.
    if ($current % 10 === 0) {
      print("Processed $current of $total sightings...\n");
    }
  }
}

// Close file.
fclose($file);

print("\nExport completed to: $filename\n");
print("Total sightings exported: $total\n");
print("New state lookups performed: $looked_up\n");
