<?php

namespace Drupal\fws_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the "phone_number_formatter" formatter.
 *
 * @FieldFormatter(
 *   id = "phone_number_formatter",
 *   label = @Translation("Phone Number Formatter (dashed)"),
 *   field_types = {
 *     "string",
 *     "telephone"
 *   }
 * )
 */
class PhoneNumberFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      $value = $item->value;
      // Remove non-digit characters.
      $digits = preg_replace('/\D/', '', $value);
      // If exactly 10 digits, format with dashes.
      if (strlen($digits) === 10) {
        $formatted = substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6);
      }
      else {
        $formatted = $value;
      }
      $elements[$delta] = ['#markup' => $formatted];
    }

    return $elements;
  }

}
