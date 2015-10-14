<?php


$plugin = array(
  'label' => t('Base Price'),
  'handler' => array(
    'class' => 'RoomsUnitFieldHandlerBasePrice',
  ),
);

class RoomsUnitFieldHandlerBasePrice extends RoomsUnitFieldHandlerBase {
  public function getFieldType() {
    return 'base_price';
  }

  public function getFieldInfo() {
    $price_fields = array();

    $rooms_unit_type = rooms_unit_type_load($this->unit->type);
    $base_price_field = NULL;
    if (isset($rooms_unit_type->data['pricing_field'])) {
      $base_price_field = $rooms_unit_type->data['pricing_field'];
    }

    $fields = field_info_instances('rooms_unit', $this->unit->type);
    foreach ($fields as $field) {
      $field_info = field_info_field($field['field_name']);

      if ($field_info['type'] == 'commerce_price') {
        $field_array = $this->unit->{$field['field_name']};

        if ($field['field_name'] == $base_price_field) {
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
