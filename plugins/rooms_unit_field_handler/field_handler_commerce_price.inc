<?php


$plugin = array(
  'label' => t('Commerce Price'),
  'handler' => array(
    'class' => 'BatUnitFieldHandlerCommercePrice',
  ),
);

class BatUnitFieldHandlerCommercePrice extends BatUnitFieldHandlerBase {
  public function getFieldType() {
    return 'commerce_price';
  }

  public function getFieldInfo() {
    $price_fields = array();

    $bat_unit_type = bat_type_load($this->unit->type);
    $base_price_field = NULL;
    if (isset($bat_unit_type->data['pricing_field'])) {
      $base_price_field = $bat_unit_type->data['pricing_field'];
    }

    $fields = field_info_instances('bat_unit', $this->unit->type);
    foreach ($fields as $field) {
      $field_info = field_info_field($field['field_name']);

      if ($field_info['type'] == 'commerce_price') {
        $field_array = $this->unit->{$field['field_name']};

        if ($field['field_name'] != $base_price_field) {
          if (isset($field_array[LANGUAGE_NONE][0]['amount'])) {
            $price_fields[$field['field_name']] = array(
              'amount' => $field_array[LANGUAGE_NONE][0]['amount'],
              'currency_code' => $field_array[LANGUAGE_NONE][0]['currency_code'],
            );
          }
          else {
            $price_fields[$field['field_name']] = array();
          }
        }
      }
    }

    return $price_fields;
  }
}
