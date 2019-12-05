<?php

require_once 'paymentui.civix.php';

/**
 * Implements hook_civicrm_tokens().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_tokens
 */
function paymentui_civicrm_tokens(&$tokens) {
  $tokens['partialPayment'] = array(
    'partialPayment.today_format_full' => 'Todays Date',
    'partialPayment.table' => 'Table of Partial Payment Information',
    'partialPayment.simpleTable' => 'Simple table of Partial Payment Event Information',
  );
}

/**
 * Implements hook_civicrm_tokenValues().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_tokenValues
 */
function paymentui_civicrm_tokenValues(&$values, $cids, $job = NULL, $tokens = array(), $context = NULL) {
  if (!empty($tokens['partialPayment'])) {
    foreach ($cids as $contactID) {
      $participantInfo = CRM_Paymentui_BAO_Paymentui::getParticipantInfo($contactID);
      $table = CRM_Paymentui_BAO_Paymentui::buildEmailTable($participantInfo);
      $simpleTable = CRM_Paymentui_BAO_Paymentui::buildSimpleEmailTable($participantInfo);
      $partialPaymentTokens = array(
        'partialPayment.today_format_full' => CRM_Utils_Date::customFormat(date('Y-m-d'), NULL, array('Y', 'm', 'd')),
        'partialPayment.table' => $table,
        'partialPayment.simpleTable' => $simpleTable,
      );
      $values[$contactID] = empty($values[$contactID]) ? $partialPaymentTokens : $values[$contactID] + $partialPaymentTokens;
    }
  }
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function paymentui_civicrm_config(&$config) {
  _paymentui_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function paymentui_civicrm_xmlMenu(&$files) {
  _paymentui_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function paymentui_civicrm_install() {
  return _paymentui_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function paymentui_civicrm_uninstall() {
  return _paymentui_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function paymentui_civicrm_enable() {
  return _paymentui_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function paymentui_civicrm_disable() {
  return _paymentui_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function paymentui_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _paymentui_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function paymentui_civicrm_managed(&$entities) {
  return _paymentui_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function paymentui_civicrm_caseTypes(&$caseTypes) {
  _paymentui_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function paymentui_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _paymentui_civix_civicrm_alterSettingsFolders($metaDataFolders);
}
