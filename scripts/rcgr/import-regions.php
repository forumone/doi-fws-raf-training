<?php

/**
 * @file
 * Import and update FWS regions with descriptive names.
 */

use Drupal\taxonomy\Entity\Term;
use Drush\Drush;

$logger = Drush::logger();

// Define FWS region names and descriptions.
$fws_regions = [
  '1' => [
    'name' => 'Pacific Coast (CA, ID, NV, OR, WA)',
    'description' => 'FWS Region 1: Pacific Coast states including California, Idaho, Nevada, Oregon, and Washington.',
  ],
  '2' => [
    'name' => 'Southwest (AZ, NM, OK, TX)',
    'description' => 'FWS Region 2: Southwest states including Arizona, New Mexico, Oklahoma, and Texas.',
  ],
  '3' => [
    'name' => 'Great Lakes/Upper Midwest (IA, IL, IN, MI, MN, MO, OH, WI)',
    'description' => 'FWS Region 3: Great Lakes and Upper Midwest states including Iowa, Illinois, Indiana, Michigan, Minnesota, Missouri, Ohio, and Wisconsin.',
  ],
  '4' => [
    'name' => 'Southeast (AL, AR, FL, GA, KY, LA, MS, NC, SC, TN)',
    'description' => 'FWS Region 4: Southeast states including Alabama, Arkansas, Florida, Georgia, Kentucky, Louisiana, Mississippi, North Carolina, South Carolina, and Tennessee.',
  ],
  '5' => [
    'name' => 'Northeast (CT, DC, DE, MA, MD, ME, NH, NJ, NY, PA, RI, VA, VT, WV)',
    'description' => 'FWS Region 5: Northeast states including Connecticut, District of Columbia, Delaware, Massachusetts, Maryland, Maine, New Hampshire, New Jersey, New York, Pennsylvania, Rhode Island, Virginia, Vermont, and West Virginia.',
  ],
  '6' => [
    'name' => 'Mountain-Prairie (CO, KS, MT, ND, NE, SD, UT, WY)',
    'description' => 'FWS Region 6: Mountain-Prairie states including Colorado, Kansas, Montana, North Dakota, Nebraska, South Dakota, Utah, and Wyoming.',
  ],
  '7' => [
    'name' => 'Alaska (AK)',
    'description' => 'FWS Region 7: The state of Alaska.',
  ],
];

$created = 0;
$updated = 0;
$skipped = 0;
$deleted = 0;

// First, update any existing "9" or numeric-only region terms to "Legacy Region 9".
$query = \Drupal::entityQuery('taxonomy_term')
  ->condition('vid', 'region')
  ->condition('name', ['9', 'Region 9'], 'IN')
  ->accessCheck(FALSE);
$tids = $query->execute();

if (!empty($tids)) {
  foreach ($tids as $tid) {
    $term = Term::load($tid);
    $term->set('name', 'Legacy Region 9');
    $term->set('description', [
      'value' => 'Legacy region code - not a valid FWS region',
      'format' => 'plain_text',
    ]);
    $term->save();
    $logger->notice("Updated legacy term {$tid} to 'Legacy Region 9'");
    $updated++;
  }
}

// Remove old "Region X" terms.
$query = \Drupal::entityQuery('taxonomy_term')
  ->condition('vid', 'region')
  ->condition('name', 'Region %', 'LIKE')
  ->accessCheck(FALSE);
$tids = $query->execute();

if (!empty($tids)) {
  foreach ($tids as $tid) {
    $term = Term::load($tid);
    $term->delete();
    $logger->notice("Deleted old term: {$term->label()}");
    $deleted++;
  }
}

// Then create/update the valid FWS region terms.
foreach ($fws_regions as $region_number => $region_data) {
  // Check if the term already exists.
  $query = \Drupal::entityQuery('taxonomy_term')
    ->condition('vid', 'region')
    ->condition('name', $region_data['name'])
    ->accessCheck(FALSE)
    ->range(0, 1);

  $tids = $query->execute();

  if (!empty($tids)) {
    $tid = reset($tids);
    $term = Term::load($tid);

    // Update the description if it has changed.
    $current_description = $term->get('description')->value;

    if ($current_description !== $region_data['description']) {
      $term->set('description', [
        'value' => $region_data['description'],
        'format' => 'plain_text',
      ]);
      $term->save();
      $logger->notice("Updated description for {$region_data['name']}");
      $updated++;
    }
    else {
      $logger->notice("No changes needed for {$region_data['name']}");
      $skipped++;
    }
  }
  else {
    // Create a new term.
    $term = Term::create([
      'vid' => 'region',
      'name' => $region_data['name'],
      'description' => [
        'value' => $region_data['description'],
        'format' => 'plain_text',
      ],
      'status' => TRUE,
    ]);
    $term->save();
    $logger->notice("Created new term for {$region_data['name']}");
    $created++;
  }
}

$logger->notice("Region import complete. Created: {$created}, Updated: {$updated}, Skipped: {$skipped}, Deleted: {$deleted}");
