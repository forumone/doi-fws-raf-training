<?php

namespace Drupal\aerial_videos\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides an aerial species selection block.
 *
 * @Block(
 *   id = "aerial_species_block",
 *   admin_label = @Translation("Aerial Species Selection"),
 *   category = @Translation("Aerial Videos")
 * )
 */
class AerialSpeciesBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Load all species group terms.
    $species_groups = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'species_group']);

    // Sort terms by their Species Group ID.
    usort($species_groups, function ($a, $b) {
      $a_id = $a->get('field_species_group_id')->value;
      $b_id = $b->get('field_species_group_id')->value;
      return $a_id <=> $b_id;
    });

    // Build the species group options.
    $group_options = ['' => $this->t('- Select -')];
    foreach ($species_groups as $term) {
      $group_id = $term->get('field_species_group_id')->value;
      $group_options[$group_id] = $term->label();
    }

    // Load all species terms.
    $species_terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => 'species']);

    // Sort species terms alphabetically by name.
    usort($species_terms, function ($a, $b) {
      return strcasecmp($a->label(), $b->label());
    });

    // Build the species options, grouped by species group.
    $species_options = ['' => $this->t('- Select -')];
    foreach ($species_terms as $term) {
      $species_id = $term->get('field_species_id')->value;
      $species_group = $term->get('field_species_group')->entity;
      if ($species_group) {
        $group_id = $species_group->get('field_species_group_id')->value;
        $species_options[$species_id] = $term->label();
      }
    }

    // Add JavaScript to handle dynamic filtering.
    $form['#attached']['library'][] = 'aerial_videos/species-selection';

    $form = [
      '#type' => 'form',
      '#attached' => [
        'library' => ['aerial_videos/species-selection'],
        'drupalSettings' => [
          'aerialVideos' => [
            'speciesByGroup' => $this->getSpeciesByGroup($species_terms),
          ],
        ],
      ],
      'group_select' => [
        '#type' => 'select2',
        '#title' => $this->t('Species Group'),
        '#empty_option' => '',
        '#attributes' => [
          'class' => ['dropdown'],
          'id' => 'selectedGroup',
          'name' => 'selectedGroup',
          'onchange' => 'Drupal.behaviors.aerialVideos.filterSpecies()',
          'title' => 'Species Group',
        ],
        '#options' => $group_options,
      ],
      'species_select' => [
        '#type' => 'select2',
        '#title' => $this->t('Select Species'),
        '#options' => $species_options,
        '#empty_option' => '',
        '#attributes' => [
          'class' => ['dropdown'],
          'id' => 'selectedSpecies',
          'name' => 'selectedSpecies',
        ],
      ],
    ];

    return $form;
  }

  /**
   * Helper function to organize species by group.
   *
   * @param \Drupal\taxonomy\Entity\Term[] $species_terms
   *   Array of species taxonomy terms.
   *
   * @return array
   *   Species organized by group ID.
   */
  protected function getSpeciesByGroup(array $species_terms) {
    $species_by_group = [];

    // First collect all species for each group.
    foreach ($species_terms as $term) {
      $species_id = $term->get('field_species_id')->value;
      $species_group = $term->get('field_species_group')->entity;
      if ($species_group) {
        $group_id = $species_group->get('field_species_group_id')->value;
        $species_by_group[$group_id][$species_id] = [
          'id' => $species_id,
          'name' => $term->label(),
          'code' => $term->get('field_species_code')->value,
        ];
      }
    }

    // Sort species within each group alphabetically by name.
    foreach ($species_by_group as &$group) {
      uasort($group, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
      });
    }

    return $species_by_group;
  }

}
