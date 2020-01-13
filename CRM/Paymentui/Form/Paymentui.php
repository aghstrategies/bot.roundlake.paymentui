<?php

require_once 'CRM/Core/Form.php';

/**
 * Form controller class
 */
class CRM_Paymentui_Form_Paymentui extends CRM_Core_Form {

  public $_params;

  /**
   * Function to set variables up before form is built
   *
   * @return void
   * @access public
   */
  public function preProcess() {
    $loggedInUser = CRM_Core_Session::singleton()->getLoggedInContactID();
    if (!$loggedInUser) {
      return;
    }
    $this->_paymentProcessor = array('billing_mode' => 1);
    $locationTypes = CRM_Core_PseudoConstant::get('CRM_Core_DAO_Address', 'location_type_id', array(), 'validate');
    $this->_bltID = array_search('Billing', $locationTypes);
    $this->set('bltID', $this->_bltID);
    $this->assign('bltID', $this->_bltID);
    $this->_fields = array();
    $processors = CRM_Financial_BAO_PaymentProcessor::getPaymentProcessors($capabilities = array('LiveMode'), $ids = FALSE);
    $processorToUse = CRM_Financial_BAO_PaymentProcessor::getDefault()->id;
    //get payment processor from setting
    $paymentProcessorSetting = CRM_Paymentui_BAO_Paymentui::apishortcut('Setting', 'get', array(
      'sequential' => 1,
      'return' => array("paymentui_processor"),
    ));
    if (!empty($paymentProcessorSetting['values'][0]['paymentui_processor'])) {
      $processorToUse = $paymentProcessorSetting['values'][0]['paymentui_processor'];
    }
    CRM_Core_Payment_Form::buildPaymentForm($this, $processors[$processorToUse], 1, FALSE);
    $this->_paymentProcessor = CRM_Financial_BAO_PaymentProcessor::getPayment($processorToUse, 'live');
  }

  /**
   * Function to build the form
   *
   * @return void
   * @access public
   */
  public function buildQuickForm() {
    CRM_Core_Resources::singleton()->addScriptFile('bot.roundlake.paymentui', 'js/paymentui.js');

    $processingFee = 4;
    $fees = CRM_Paymentui_BAO_Paymentui::getFeesFromSettings();
    if (!empty($fees['processing_fee'])) {
      $processingFee = $fees['processing_fee'];
    }

    CRM_Core_Resources::singleton()->addVars('paymentui', array('processingFee' => $processingFee));
    //Get contact name of the logged in user
    $session     = CRM_Core_Session::singleton();
    $this->_contactId   = $session->get('userID');

    if (!$this->_contactId) {
      // $message = ts('You must be logged in to view this page. To login visit: https://ymcaga.org/login');
      // CRM_Utils_System::setUFMessage($message);
      //Message not showing up in joomla:
      $displayName = 'You must be logged in to view this page. To login visit: <a target="_blank" href="https://ymcaga.org/login">https://ymcaga.org/login</a>';
      $this->assign('displayName', $displayName);
      return;
    }
    $this->assign('contactId', $this->_contactId);
    $displayName = CRM_Contact_BAO_Contact::displayName($this->_contactId);
    $this->assign('displayName', $displayName);

    //Set column headers for the table
    $columnHeaders = array('Event', 'Registrant', 'Cost', 'Paid to Date', '$ remaining', 'Make Payment');
    $this->assign('columnHeaders', $columnHeaders);

    //Get Info about this contact (and this contacts related contacts) Registrations
    $this->_participantInfo = CRM_Paymentui_BAO_Paymentui::getParticipantInfo($this->_contactId);
    $this->assign('participantInfo', $this->_participantInfo);
    $latefees = 0;
    $defaults = array();
    if (!empty($this->_participantInfo)) {
      foreach ($this->_participantInfo as $pid => $pInfo) {
        $latefees = $latefees + $pInfo['latefees'];
        $this->add('text', "payment[$pid]", "payment[$pid]", array(), FALSE);
        if ($pInfo['latefees'] > 0) {
          $this->add('text', "latefee[$pid]", "latefee[$pid]", ['disabled' => TRUE], FALSE);
          $defaults["latefee[$pid]"] = $pInfo['latefees'];
        }
        $this->add('text', "pfee[$pid]", "pfee[$pid]", ['disabled' => TRUE], FALSE);
        $this->add('text', "subtotal[$pid]", "subtotal[$pid]", ['disabled' => TRUE], FALSE);
        $defaults["payment[$pid]"] = $pInfo['totalDue'];
      }
    }
    $email = $this->add('text', "email", "Email to send receipt", array(), TRUE);
    $this->assign('email', $email);
    $this->setDefaults($defaults);
    $this->addButtons(array(
    array(
      'type' => 'submit',
      'name' => ts('Submit'),
      'isDefault' => TRUE,
    ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
    $this->addFormRule(array('CRM_Paymentui_Form_Paymentui', 'formRule'), $this);
  }

  /**
   * global form rule
   *
   * @param array $fields the input form values
   * @param array $files the uploaded files if any
   * @param $self
   *
   * @internal param array $options additional user data
   *
   * @return true if no errors, else array of errors
   * @access public
   * @static
   */
  public static function formRule($fields, $files, $self) {
    $errors = array();
    //Validate the amount: should not be more than balance and should be numeric
    foreach ($fields['payment'] as $pid => $amount) {
      if ($amount) {
        if ($self->_participantInfo[$pid]['balance'] < $amount) {
          $errors['payment[' . $pid . ']'] = "Amount can not exceed the balance amount";
        }
        if (!is_numeric($amount)) {
          $errors['payment[' . $pid . ']'] = "Please enter a valid amount";
        }
      }
    }
    //Validate credit card fields
    $required = array(
      'credit_card_type'            => 'Credit Card Type',
      'credit_card_number'          => 'Credit Card Number',
      'cvv2'                        => 'CVV',
      'billing_first_name'          => 'Billing First Name',
      'billing_last_name'           => 'Billing Last Name',
      'billing_street_address-5'    => 'Billing Street Address',
      'billing_city-5'              => 'City',
      'billing_state_province_id-5' => 'State Province',
      'billing_postal_code-5'       => 'Postal Code',
      'billing_country_id-5'        => 'Country',
    );

    foreach ($required as $name => $fld) {
      if (!$fields[$name]) {
        $errors[$name] = ts('%1 is a required field.', array(1 => $fld));
      }
    }
    CRM_Core_Payment_Form::validateCreditCard($fields, $errors);
    return $errors;
  }

  /**
   * Function to process the form
   *
   * @access public
   *
   * @return void
   */
  public function postProcess() {
    $paymentSuccess = [];
    // $values = $this->exportValues();
    $this->_params = $this->controller->exportValues($this->_name);
    $totalAmount = 0;
    $config = CRM_Core_Config::singleton();
    $fees = CRM_Paymentui_BAO_Paymentui::getFeesFromSettings();
    $processingFee = 4;
    $totalProcessingFee = 0;
    // $lateFees = 0;
    if (!empty($fees['processing_fee'])) {
      $processingFee = $fees['processing_fee'];
    }
    $processingFee = $processingFee / 100;

    //Building params for CC processing
    $this->_params["state_province-{$this->_bltID}"] = $this->_params["billing_state_province-{$this->_bltID}"] = CRM_Core_PseudoConstant::stateProvinceAbbreviation($this->_params["billing_state_province_id-{$this->_bltID}"]);
    $this->_params["country-{$this->_bltID}"] = $this->_params["billing_country-{$this->_bltID}"] = CRM_Core_PseudoConstant::countryIsoCode($this->_params["billing_country_id-{$this->_bltID}"]);
    $this->_params['year']           = CRM_Core_Payment_Form::getCreditCardExpirationYear($this->_params);
    $this->_params['month']          = CRM_Core_Payment_Form::getCreditCardExpirationMonth($this->_params);
    $this->_params['ip_address']     = CRM_Utils_System::ipAddress();
    $this->_params['currencyID']     = $config->defaultCurrency;
    $this->_params['payment_action'] = 'Sale';
    $this->_params['payment_processor_id'] = $this->_paymentProcessor['id'];

    $paymentParams = $this->_params;
    CRM_Core_Payment_Form::mapParams($this->_bltID, $paymentParams, $paymentParams, TRUE);
    foreach ($this->_params['payment'] as $pid => $pVal) {
      // blank canvas
      $partTotal = 0;
      $pfee = 0;
      $latefee = 0;

      //save partial pay amount to participant info array
      $this->_participantInfo[$pid]['partial_payment_pay'] = $pVal;
      if (isset($pVal) && $pVal > 0) {
        $partTotal = $partTotal + $pVal;

        //calculate processing fee
        if ($processingFee !== 0) {
          //  Calculate processing fee  (calculate here because we do not trust js)
          $pfee = round($pVal * $processingFee, 2);

          // Add processing fee to total for this participant
          $partTotal = $partTotal + $pfee;

          $this->_participantInfo[$pid]['processingfees'] = $pfee;

        }
      }

      // If there is a late fee add it
      if (!empty($this->_participantInfo[$pid]['latefees'])) {
        $latefee = $this->_participantInfo[$pid]['latefees'];
        $partTotal = $partTotal + $latefee;
      }
      if ($partTotal > 0) {
        $totalAmount = $totalAmount + $partTotal;
        // save participant total to participant info
        $this->_participantInfo[$pid]['participant_total'] = $partTotal;

        $paymentParams['contribution_id'] = $this->_participantInfo[$pid]['contribution_id'];
        $paymentParams['amount'] = $partTotal;

        $pay = CRM_Paymentui_BAO_Paymentui::apishortcut('PaymentProcessor', 'pay', $paymentParams);

        // Log payment details info to ConfigAndLog
        if (!empty($pay['values'][0])) {
          $paymentParamsToPrintToLog = $pay['values'][0];
          unset($paymentParamsToPrintToLog['credit_card_number']);
          CRM_Core_Error::debug_var('Info sent to Authorize.net from the partial payment form', $paymentParamsToPrintToLog);
        }

        // Log participant information just in case
        CRM_Core_Error::debug_var('Participant Info', $this->_participantInfo[$pid]);

        if (!empty($pay['is_error']) && $pay['is_error'] == 1) {
          $paymentParamsToPrint = $paymentParams;
          unset($paymentParamsToPrint['credit_card_number']);
          CRM_Core_Error::debug_var('Info sent to Authorize.net from the partial payment form', $paymentParamsToPrint);
          $this->_participantInfo[$pid]['success'] = 0;
          CRM_Core_Session::setStatus(ts('For %2 - %1 for $ %3. %4', [
            1 => $this->_participantInfo[$pid]['contact_name'],
            2 => $this->_participantInfo[$pid]['event_name'],
            3 => $this->_participantInfo[$pid]['participant_total'],
            4 => $pay['error_message'],
          ]), ts('Error Processing Payment'), 'error');
        }
        else {
          // Payment Processed sucessfully
          if (!empty($pay['values'][0])) {
            // update contribution to include line items for fees
            CRM_Paymentui_BAO_Paymentui::update_line_items_for_fees($pid, $pfee, $latefee, $this->_participantInfo[$pid]['contribution_id']);

            // Record payment in CiviCRM
            $paymentProcessedInfo = CRM_Paymentui_BAO_Paymentui::process_partial_payments($paymentParams, $this->_participantInfo, $pay['values'][0], $pid);
            $paymentSuccess[$pid] = TRUE;
            $this->_participantInfo[$pid]['success'] = 1;

            CRM_Core_Session::setStatus(ts('For %2 - %1 for $ %3.', [
              1 => $this->_participantInfo[$pid]['contact_name'],
              2 => $this->_participantInfo[$pid]['event_name'],
              3 => $this->_participantInfo[$pid]['participant_total'],
            ]), ts('Successfully Processed Payment'), 'success');
          }
        }
      }
    }
    if (!empty($paymentSuccess)) {
      // TODO Refactor
      CRM_Paymentui_BAO_Paymentui::send_receipt($this->_participantInfo, $paymentParams);

      parent::postProcess();

      //Redirect to the same URL
      $url     = CRM_Utils_System::url('civicrm/addpayment', "reset=1");
      $session = CRM_Core_Session::singleton();
      CRM_Utils_System::redirect($url);
    }
    if ($totalAmount == 0) {
      CRM_Core_Session::setStatus(ts('No Payment amount entered'), ts('No Payments Processed'), 'success');
    }
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
