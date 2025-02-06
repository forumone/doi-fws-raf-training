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
    $species_options = [
      '' => $this->t('- Select -'),
      '708' => $this->t('American Bittern'),
      '203' => $this->t('American Black Duck'),
      '701' => $this->t('American Coot'),
      '205' => $this->t('American Green-winged Teal'),
      '713' => $this->t('American White Pelican'),
      '211' => $this->t('American Wigeon'),
      '417' => $this->t('Barrow\'s Goldeneye'),
      '406' => $this->t('Black Scoter'),
      '501' => $this->t('Black-bellied Whistling Duck'),
      '206' => $this->t('Blue-winged Teal'),
      '104' => $this->t('Brant'),
      '419' => $this->t('Bufflehead'),
      '102' => $this->t('Canada and Cackling Geese'),
      '306' => $this->t('Canvasback'),
      '207' => $this->t('Cinnamon Teal'),
      '601' => $this->t('Common and Yellow-billed Loons'),
      '401' => $this->t('Common Eider'),
      '416' => $this->t('Common Goldeneye'),
      '412' => $this->t('Common Merganser'),
      '712' => $this->t('Cormorants'),
      '110' => $this->t('Cranes'),
      '705' => $this->t('Eared Grebe'),
      '105' => $this->t('Emperor Goose'),
      '502' => $this->t('Fulvous Whistling Duck'),
      '210' => $this->t('Gadwall'),
      '714' => $this->t('Great Blue Heron'),
      '710' => $this->t('Guillemots'),
      '411' => $this->t('Harlequin Duck'),
      '414' => $this->t('Hooded Merganser'),
      '703' => $this->t('Horned Grebe'),
      '402' => $this->t('King Eider'),
      '410' => $this->t('Long-tailed Duck'),
      '201' => $this->t('Mallard'),
      '202' => $this->t('Mottled Duck'),
      '711' => $this->t('Murres'),
      '204' => $this->t('Northern Pintail'),
      '209' => $this->t('Northern Shoveler'),
      '603' => $this->t('Pacific Loon'),
      '702' => $this->t('Pied-billed Grebe'),
      '709' => $this->t('Pigeon Guillemot'),
      '413' => $this->t('Red-breasted Merganser'),
      '305' => $this->t('Redhead'),
      '704' => $this->t('Red-necked Grebe'),
      '602' => $this->t('Red-throated Loon'),
      '304' => $this->t('Ring-necked Duck'),
      '307' => $this->t('Ruddy Duck'),
      '303' => $this->t('Scaup'),
      '409' => $this->t('Scoter comparisons'),
      '403' => $this->t('Spectacled Eider'),
      '404' => $this->t('Steller\'s Eider'),
      '408' => $this->t('Surf Scoter'),
      '106' => $this->t('Swans'),
      '706' => $this->t('Western Grebe'),
      '101' => $this->t('White Geese'),
      '103' => $this->t('White-fronted Goose'),
      '407' => $this->t('White-winged Scoter'),
      '715' => $this->t('Williow Ptarmigan'),
      '212' => $this->t('Wood Duck'),
    ];

    $form = [
      '#type' => 'form',
      'species_select' => [
        '#type' => 'select',
        '#title' => $this->t('Species'),
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

}
