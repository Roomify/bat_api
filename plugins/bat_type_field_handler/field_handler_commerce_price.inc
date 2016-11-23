<?php

/**
 * @file
 */

$plugin = array(
  'label' => t('Commerce Price'),
  'handler' => array(
    'class' => 'BatTypeFieldHandlerCommercePrice',
  ),
);

/**
 *
 */
class BatTypeFieldHandlerCommercePrice extends BatTypeFieldHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function getFieldType() {
    return 'commerce_price';
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldInfo() {
    $price_fields = array();

    $bat_type = bat_type_load($this->type->type);

    $fields = field_info_instances('bat_type', $this->type->type);
    foreach ($fields as $field) {
      $field_info = field_info_field($field['field_name']);

      if ($field_info['type'] == 'commerce_price') {
        $field_array = $this->type->{$field['field_name']};

        if (isset($field_array[LANGUAGE_NONE][0]['amount'])) {
          $price_fields[$field['field_name']] = array(
            'amount' => $field_array[LANGUAGE_NONE][0]['amount'],
            'currency_code' => $field_array[LANGUAGE_NONE][0]['currency_code'],
          );
        }
      }
    }

    return $price_fields;
  }

}
