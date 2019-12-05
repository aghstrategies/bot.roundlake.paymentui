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
    // TODO should this be 0 everywhere?
    $processingFee = 0;
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
    $columnHeaders = array('Event', 'Registrant', 'Cost', 'Paid to Date', '$$ remaining', 'Make Payment');
    $this->assign('columnHeaders', $columnHeaders);

    //Get event names for which logged in user and the related contacts are registered
    $this->_participantInfo = CRM_Paymentui_BAO_Paymentui::getParticipantInfo($this->_contactId);
    $this->assign('participantInfo', $this->_participantInfo);
    $latefees = 0;
    $defaults = array();
    if (!empty($this->_participantInfo)) {
      foreach ($this->_participantInfo as $pid => $pInfo) {
        $latefees = $latefees + $pInfo['latefees'];
        $element =& $this->add('text', "payment[$pid]", NULL, array(), FALSE);
        $defaults["payment[$pid]"] = $pInfo['totalDue'];
      }
    }
    if ($latefees) {
      $this->assign('latefees', $latefees);
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
      'credit_card_type'   => 'Credit Card Type',
      'credit_card_number' => 'Credit Card Number',
      'cvv2'               => 'CVV',
      'billing_first_name' => 'Billing First Name',
      'billing_last_name' => 'Billing Last Name',
      'billing_street_address-5'  => 'Billing Street Address',
      'billing_city-5' => 'City',
      'billing_state_province_id-5' => 'State Province',
      'billing_postal_code-5' => 'Postal Code',
      'billing_country_id-5' => 'Country',
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
    $values = $this->exportValues();
    $this->_params = $this->controller->exportValues($this->_name);
    $totalAmount = 0;
    $config = CRM_Core_Config::singleton();
    $lateFees = 0;
    $fees = CRM_Paymentui_BAO_Paymentui::getFeesFromSettings();
    $processingFee = 4;
    $totalProcessingFee = 0;
    if (!empty($fees['processing_fee'])) {
      $processingFee = $fees['processing_fee'];
    }
    $processingFee = $processingFee / 100;

    //Calculate total amount paid and individual amount for each contribution
    foreach ($this->_params['payment'] as $pid => $pVal) {
      // add together partial pay amounts
      $totalAmount += $pVal;
      //save partial pay amount to particioant info array
      $this->_participantInfo[$pid]['partial_payment_pay'] = $pVal;
      // add together late fees
      $lateFees += $this->_participantInfo[$pid]['latefees'];
      //calculate processing fee
      $this->_participantInfo[$pid]['processingfees'] = round($pVal * $processingFee, 2);
      $totalProcessingFee += $this->_participantInfo[$pid]['processingfees'];
    }
    $totalAmount = $totalAmount + $lateFees + $totalProcessingFee;
    //Building params for CC processing
    $this->_params["state_province-{$this->_bltID}"] = $this->_params["billing_state_province-{$this->_bltID}"] = CRM_Core_PseudoConstant::stateProvinceAbbreviation($this->_params["billing_state_province_id-{$this->_bltID}"]);

    $this->_params["country-{$this->_bltID}"] = $this->_params["billing_country-{$this->_bltID}"] = CRM_Core_PseudoConstant::countryIsoCode($this->_params["billing_country_id-{$this->_bltID}"]);

    $this->_params['year']           = CRM_Core_Payment_Form::getCreditCardExpirationYear($this->_params);
    $this->_params['month']          = CRM_Core_Payment_Form::getCreditCardExpirationMonth($this->_params);
    $this->_params['ip_address']     = CRM_Utils_System::ipAddress();
    $this->_params['amount']         = $totalAmount;
    // $this->_params['amount_level']   = $params['amount_level'];
    $this->_params['currencyID']     = $config->defaultCurrency;
    $this->_params['payment_action'] = 'Sale';
    $this->_params['invoiceID']      = md5(uniqid(rand(), TRUE));

    // TODO verify this is an id
    $this->_params['payment_processor_id'] = $this->_paymentProcessor;

    $paymentParams = $this->_params;
    CRM_Core_Payment_Form::mapParams($this->_bltID, $this->_params, $paymentParams, TRUE);
    // $payment = CRM_Core_Payment::singleton($this->_mode, $this->_paymentProcessor, $this);
    // $payment = Civi\Payment\System::singleton()->getByProcessor($this->_paymentProcessor);
    // $result = $payment->doDirectPayment($paymentParams);
    $pay = CRM_Paymentui_BAO_Paymentui::apishortcut('PaymentProcessor', 'pay', $paymentParams);

    // Log payment details info to ConfigAndLog
    $paymentParamsToPrintToLog = $paymentParams;
    unset($paymentParamsToPrintToLog['credit_card_number']);
    CRM_Core_Error::debug_var('Info sent to Authorize.net from the partial payment form', $paymentParamsToPrintToLog);

    if (!empty($pay['is_error']) && $pay['is_error'] == 1) {
      CRM_Core_Session::setStatus(ts($pay['error_message']), 'Error Processing Payment', 'no-popup');
    }
    else {
      // TODO I THINK this now happens when one runs payment.create test and then delete
      // $CCFinancialTrxn = CRM_Paymentui_BAO_Paymentui::createFinancialTrxn($paymentParams);
      $partialPaymentInfo = $this->_participantInfo;

      // Log participant information just in case
      CRM_Core_Error::debug_var('Participant Info', $this->_participantInfo);

      //Process all the partial payments and update the records
      $paymentProcessedInfo = CRM_Paymentui_BAO_Paymentui::process_partial_payments($paymentParams, $this->_participantInfo);

      parent::postProcess();

      //Define status message
      $statusMsg = ts('The payment(s) have been processed successfully.');
      CRM_Core_Session::setStatus($statusMsg, ts('Saved'), 'success');

      //Redirect to the same URL
      $url     = CRM_Utils_System::url('civicrm/addpayment', "reset=1");
      $session = CRM_Core_Session::singleton();
      CRM_Utils_System::redirect($url);
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
