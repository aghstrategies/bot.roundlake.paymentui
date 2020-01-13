<?php
use CRM_Paymentui_ExtensionUtil as E;

/**
 * FeeLineItem.Create API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_fee_line_item_Create_spec(&$params) {
  $params['entity_id']['api.required'] = 1;
  $params['qty']['api.required'] = 1;
  $params['unit_price']['api.required'] = 1;
  $params['line_total']['api.required'] = 1;
  $params['label']['api.default'] = 'line item';
}

/**
 * FeeLineItem.Create API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_fee_line_item_Create($params) {
  return _civicrm_api3_basic_create(_civicrm_api3_get_BAO('civicrm_api3_line_item_create'), $params, 'LineItem');
}
