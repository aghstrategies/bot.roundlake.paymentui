<?php
/**
 *
 * @package BOT Roundlake Payment User Interface Helper functions
 * $Id$
 *
 */
class CRM_Paymentui_BAO_Paymentui extends CRM_Event_DAO_Participant {

  /**
   * Shortcut for api calls
   * @param  string $entity Entity
   * @param  string $action Action
   * @param  array $params params
   * @return array         results
   */
  public static function apishortcut($entity, $action, $params) {
    try {
      $result = civicrm_api3($entity, $action, $params);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(
        ts('API %3 - %2 Error: %1', array(
          1 => $error,
          2 => $action,
          3 => $entity,
          'domain' => 'bot.roundlake.paymentui',
        ))
      );
    }
    return $result;
  }

  public static function getParticipantInfo($contactID) {
    $relatedContactIDs   = self::getRelatedContacts($contactID);
    $relatedContactIDs[] = $contactID;
    $relContactIDs       = implode(',', $relatedContactIDs);
    $pendingPayLater     = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_ParticipantStatusType', 'Pending from pay later', 'id', 'name');
    $partiallyPaid       = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_ParticipantStatusType', 'Partially paid', 'id', 'name');

    //Get participant info for the primary and related contacts
    $sql = <<<HERESQL
SELECT p.id, p.contact_id, e.title, c.display_name, p.event_id, pp.contribution_id
FROM civicrm_participant p
  INNER JOIN civicrm_contact c
    ON ( p.contact_id =  c.id )
  INNER JOIN civicrm_event e
    ON ( p.event_id = e.id )
  INNER JOIN civicrm_participant_payment pp
    ON ( p.id = pp.participant_id )
WHERE p.contact_id IN ($relContactIDs)
  AND p.status_id IN ($pendingPayLater, $partiallyPaid)
  AND p.is_test = 0
HERESQL;
    $dao = CRM_Core_DAO::executeQuery($sql);
    if ($dao->N) {
      while ($dao->fetch()) {
        $paid = $totalDue = 0;
        $lineItems = self::apishortcut('LineItem', 'get', ['contribution_id' => $dao->contribution_id]);
        foreach ($lineItems['values'] as $lineItemID => $lineItemDetails) {
          if ($lineItemDetails['financial_type_id'] != 1) {
            $totalDue = $totalDue + $lineItemDetails['line_total'];
          }
        }
        // print_r($lineItems); die();
        // Get the payment details of all the participants
        $paymentDetails = CRM_Contribute_BAO_Contribution::getPaymentInfo($dao->id, 'event', FALSE, TRUE);
        $paid = $totalDue - $paymentDetails['balance'];

        // print_r($paymentDetails); die();
        //Get display names of the participants and additional participants, if any
        $displayNames   = self::getDisplayNames($dao->id, $dao->display_name);
        $paymentSched   = self::getLateFees($dao->event_id, $paid, $paymentDetails['balance']);
        if ($paymentDetails['balance'] == 0) {
          $paymentSched['totalDue'] = 0;
        }

        // TODO do we need this - subtract ccfees added by the percentage price field extension
        $ccFeesSQL = <<<HERESQL
        SELECT line_total FROM `civicrm_line_item` as li
JOIN `civicrm_percentagepricesetfield` as p
ON li.price_field_id = p.field_id
WHERE li.contribution_id = $dao->contribution_id
HERESQL;
        $percentagePriceFee = CRM_Core_DAO::singleValueQuery($ccFeesSQL);

        //Create an array with all the participant and payment information
        $participantInfo[$dao->id]['pid']             = $dao->id;
        $participantInfo[$dao->id]['cid']             = $dao->contact_id;
        $participantInfo[$dao->id]['contribution_id'] = $dao->contribution_id;
        $participantInfo[$dao->id]['event_name']      = $dao->title;
        $participantInfo[$dao->id]['contact_name']    = $displayNames;
        $participantInfo[$dao->id]['total_amount']    = $totalDue - $percentagePriceFee;
        $participantInfo[$dao->id]['paid']            = $paid;
        $participantInfo[$dao->id]['balance']         = $paymentDetails['balance'] - $percentagePriceFee;
        $participantInfo[$dao->id]['latefees']        = $paymentSched['lateFee'];
        if (!empty($paymentSched['nextDueDate'])) {
          $participantInfo[$dao->id]['nextDueDate'] = $paymentSched['nextDueDate'];
        }
        $participantInfo[$dao->id]['totalDue']        = $paymentSched['totalDue'] - $percentagePriceFee;
        $participantInfo[$dao->id]['rowClass']        = 'row_' . $dao->id;
        $participantInfo[$dao->id]['payLater']        = $paymentDetails['payLater'];
      }
    }
    else {
      return FALSE;
    }
    return $participantInfo;
  }

  /**
   * Calculate late fees
   * @param  int $eventId      id of the event
   * @param  float $amountPaid Amount paid
   * @param  float $balance    amount left to be paid
   * @return array             late fee details
   */
  public static function getLateFees($eventId, $amountPaid, $balance) {
    $return = array(
      'lateFee' => 0,
      'totalDue' => $balance,
    );
    if ($balance == 0) {
      $return['nextDueDate'] = ts('All Paid', array('domain' => 'bot.roundlake.paymentui'));
    }
    else {
      $lateFeeSchedule = self::apishortcut('CustomField', 'getSingle', array(
        'sequential' => 1,
        'return' => array("id"),
        'name' => "event_late_fees",
        'api.Event.getsingle' => array(
          'id' => $eventId,
        ),
      ));
      if (!empty($lateFeeSchedule['api.Event.getsingle']["custom_{$lateFeeSchedule['id']}"])) {
        $fees = self::getFeesFromSettings();
        $feeAmount = CRM_Utils_Array::value('late_fee', $fees, 0);
        // Parse schedule expects string that looks like:
        //     04/15/2017:100
        //     04/20/2017:100
        //     04/25/2017:100
        $scheduleToParse = $lateFeeSchedule['api.Event.getsingle']["custom_{$lateFeeSchedule['id']}"];
        // Use regex to split on line breaks whether they're Windows (`\r\n`),
        // Mac (`\r`), or Unix (`\n`).
        $arrayOfDates = preg_split('/\r\n|\r|\n/', $scheduleToParse);
        $return['totalDue'] = 0;
        $amountOwed = 0;
        $currentDate = time();
        reset($arrayOfDates);
        foreach ($arrayOfDates as $key => &$dates) {
          list($dateText, $amountDue) = explode(":", $dates);
          $dueDate = DateTime::createFromFormat('m/d/Y', $dateText);
          $dueDate = date_timestamp_get($dueDate);
          $amountOwed = $amountOwed + $amountDue;
          $dates = array(
            'dateText' => $dateText,
            'line' => $dates,
            'unixDate' => $dueDate,
            'amountDue' => $amountDue,
            'amountOwed' => $amountOwed,
            'diff' => $dueDate - $currentDate,
          );

          if ($amountPaid >= $amountOwed) {
            $dates['status'] = 'paid';
          }
          elseif ($dueDate >= $currentDate) {
            $dates['status'] = 'current';
            $return['nextDueDate'] = $dateText;
            $return['totalDue'] = $amountOwed - $amountPaid;
            break;
          }
          else {
            // Add to late fee for each due date in the past
            $return['lateFee'] += $feeAmount;
          }
        }
        // All payments in the past
        if (empty($return['nextDueDate'])) {
          if (($amountOwed - $amountPaid) > 0) {
            $return['totalDue'] = $amountOwed - $amountPaid;
          }
          $return['nextDueDate'] = ts('%1 (ASAP)', array(
            'domain' => 'bot.roundlake.paymentui',
            1 => $dateText,
          ));
        }
      }
    }
    return $return;
  }

  /**
   * Helper function to get formatted display names of the the participants
   * Purpose - to generate comma separated display names of primary and additional participants
   */
  public static function getDisplayNames($participantId, $display_name) {
    $displayName[] = $display_name;
    //Get additional participant names
    $additionalPIds = CRM_Event_BAO_Participant::getAdditionalParticipantIds($participantId);
    if ($additionalPIds) {
      foreach ($additionalPIds as $pid) {
        $cId           = CRM_Core_DAO::getFieldValue('CRM_Event_DAO_Participant', $pid, 'contact_id', 'id');
        $displayName[] = CRM_Contact_BAO_Contact::displayName($cId);
      }
    }
    $displayNames = implode(', ', $displayName);
    return $displayNames;
  }

  /**
   * Helper function to get related contacts of tthe contact
   * Checks for Child, Spouse, Child/Ward relationship types
   */
  public static function getRelatedContacts($contactID) {
    $result = self::apishortcut('Relationship', 'get', array(
      'sequential' => 1,
      'relationship_type_id' => 1,
      'contact_id_b' => $contactID,
      'contact_id_a.is_deleted' => 0,
    ));
    if (!empty($result['values'])) {
      $relatedContactIDs = array();
      foreach ($result['values'] as $relatedContact => $value) {
        $relatedContactIDs[] = $value['contact_id_a'];
      }
      return $relatedContactIDs;
    }
    else {
      return FALSE;
    }
  }

  /**
   * Get fees from settings
   * @return array fees as defined on the settings page
   */
  public static function getFeesFromSettings() {
    $fees = array();
    $existingSetting = self::apishortcut('Setting', 'get', array(
      'sequential' => 1,
      'return' => array("paymentui_processingfee", "paymentui_latefee"),
    ));
    if (!empty($existingSetting['values'][0]['paymentui_processingfee'])) {
      $fees['processing_fee'] = $existingSetting['values'][0]['paymentui_processingfee'];
    }
    if (!empty($existingSetting['values'][0]['paymentui_latefee'])) {
      $fees['late_fee'] = $existingSetting['values'][0]['paymentui_latefee'];
    }
    return $fees;
  }

  /**
   * Build Email html
   * @param  array $participantInfo  Information about the participant
   * @param  boolean $receipt         is this a reciept or not
   * @param  integer $processingFee   processing fee
   * @return string                   Email text
   */
  public static function buildEmailTable($participantInfo, $receipt = FALSE, $processingFee = 0) {
    $table = '<table class="partialPayment" border="1" cellpadding="4" cellspacing="1" style="border-collapse: collapse; text-align: left">
     <thead><tr>
       <th>Event Name</th>
       <th>Student Name</th>
       <th>Cost of Program</th>
       <th>Paid to Date</th>
       <th>Total Balance Owed</th>
       <th>Late Fee Applies On</th>
       <th>Late Fees</th>
       <th>Next Payment Due Amount</th>
     </tr></thead><tbody>';
    foreach ($participantInfo as $row) {
      $table .= "
       <tr class=" . $row['rowClass'] . ">
         <td>" . $row['event_name'] . "</td>
         <td>" . $row['contact_name'] . "</td>
         <td> $" . self::formatNumberAsMoney($row['total_amount']) . "</td>
         <td> $" . self::formatNumberAsMoney($row['paid']) . "</td>
         <td> $" . self::formatNumberAsMoney($row['balance']) . "</td>
         <td>" . $row['nextDueDate'] . "</td>
         <td> $" . self::formatNumberAsMoney(floatval($row['latefees'])) . "</td>
         <td> $" . self::formatNumberAsMoney($row['totalDue']) . "</td>
       </tr>
     ";
    }
    $table .= "</tbody></table>";
    return $table;
  }

  public static function buildReceiptEmailTable($participantInfo) {
    $lateFeeTotal = 0;
    $totalAmountPaid = 0;
    $table = '<table class="partialPayment" border="1" cellpadding="4" cellspacing="1" style="border-collapse: collapse; text-align: left">
     <thead><tr>
       <th>Participant</th>
       <th>Cost of Program</th>
       <th>Paid to Date</th>
       <th>Total Balance Owed</th>
       <th>Late Fee</th>
       <th>Processing Fee</th>
       <th>Payment</th>
       <th>Total Charged for this Participant</th>
    </tr></thead><tbody>';
    foreach ($participantInfo as $row) {
      $table .= "
       <tr class=" . $row['rowClass'] . ">
         <td>" . $row['event_name'] . " - " . $row['contact_name'] . "</td>
         <td> $" . self::formatNumberAsMoney($row['total_amount']) . "</td>
         <td> $" . self::formatNumberAsMoney(($row['paid'] + $row['partial_payment_pay'])) . "</td>
         <td> $" . self::formatNumberAsMoney(($row['balance'] - $row['partial_payment_pay'])) . "</td>
         <td> $" . self::formatNumberAsMoney(floatval($row['latefees'])) . "</td>
         <td> $" . self::formatNumberAsMoney(floatval($row['processingfees'])) . "</td>
         <td> $" . self::formatNumberAsMoney(floatval($row['partial_payment_pay'])) . "</td>
         <td> $" . self::formatNumberAsMoney(floatval($row['participant_total'])) . "</td>
       </tr>
     ";

      if (!empty($row['participant_total'])) {
        $totalAmountPaid = $totalAmountPaid + $row['participant_total'];
      }
    }
    $table .= "</tbody></table><br>";
    $table .= "<p><strong>Total:</strong> $ " . self::formatNumberAsMoney(floatval($totalAmountPaid)) . " </p>";

    return $table;
  }

  /**
   * Simple table token
   * @param  array $participantInfo array of information about the participant
   * @return string                 simple table token text
   */
  public static function buildSimpleEmailTable($participantInfo) {
    $table = '<table class="partialPayment" cellspacing="5" cellpadding="5" style="border-collapse: collapse; text-align: left">
     <thead align="left"><tr>
       <th>Event Name</th>
       <th>Student Name</th>
       <th>Payment Due</th>
       <th>Payment Amount</th>
    </tr></thead><tbody>
    ';
    $lateFeeTotal = 0;
    $amountOwed = 0;
    foreach ($participantInfo as $row) {
      $amountOwed = $amountOwed + $row['totalDue'];
      $table .= "
       <tr class=" . $row['rowClass'] . ">
         <td>" . $row['event_name'] . "</td>
         <td>" . $row['contact_name'] . "</td>
         <td>" . $row['nextDueDate'] . "</td>
         <td> $" . self::formatNumberAsMoney($row['totalDue']) . "</td>
       </tr>
     ";
      if (!empty($row['latefees'])) {
        $lateFeeTotal = $lateFeeTotal + $row['latefees'];
      }
    }
    $table .= "
    <tr>
    <td colspan='2'></td>
      <td style='text-align:left;'>
        <p><strong>Late Fees: </strong> $" . self::formatNumberAsMoney($lateFeeTotal) . "</p>
        <p><strong>Total Due: </strong> $" . self::formatNumberAsMoney((floatval($amountOwed) + floatval($lateFeeTotal))) . "</p>
      </td>
    </tr></tbody></table><br>";
    return $table;
  }

  public static function formatNumberAsMoney($number) {
    return number_format($number, 2, '.', ',');
  }

  /**
   * Function to create records of relevant payments in CiviCRM
   * @param $paymentParams - Payment Processor parameters
   * @param $participantInfo - participantID as key and contributionID, ContactID, PayLater, Partial Payment Amount
   * @param $payResponse - response from paymentprocessor.pay call
   * @param $pid - participant id
   * @return participantInfo array with 'Success' flag
   */
  public static function process_partial_payments($paymentParams, &$participantInfo, $payResponse, $pid) {
    // if (!$participantInfo[$pid]['contribution_id'] || !$pId) {
    //   $participantInfo[$pId]['success'] = 0;
    // }

    // Update participant status for pending from pay later registrations
    //Update participant Status from 'Pending from Pay Later' to 'Partially Paid'
    $pendingPayLater   = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_ParticipantStatusType', 'Pending from pay later', 'id', 'name');
    $participantStatus = CRM_Core_DAO::getFieldValue('CRM_Event_BAO_Participant', $pid, 'status_id', 'id');

    if ($participantStatus == $pendingPayLater) {
      $trxnRecord = CRM_Paymentui_BAO_Paymentui::apishortcut('Participant', 'create', [
        'id' => $pid,
        'status_id' => "Partially paid",
      ]);
    }

    //Making sure that payment params has the correct amount for partial payment
    $paymentParams['total_amount'] = $paymentParams['amount'];
    $paymentParams['payment_instrument_id'] = 1;

    // Add additional financial transactions for each partial payment
    // $trxnRecord = CRM_Paymentui_BAO_Paymentui::recordAdditionalPayment($participantInfo[$pid]['contribution_id'], $paymentParams, 'owed', $pId);
    $paymentParams['participant_id'] = $pid;
    $paymentParams['entity_id'] = $paymentParams['contribution_id'] = $participantInfo[$pid]['contribution_id'];
    $paymentParams['is_send_contribution_notification'] = FALSE;
    $paymentParams['trxn_id'] = $payResponse['trxn_id'];
    $trxnRecord = CRM_Paymentui_BAO_Paymentui::apishortcut('Payment', 'create', $paymentParams);
    if (!empty($trxnRecord['id'])) {
      $participantInfo[$pid]['success'] = 1;
    }

    return $participantInfo;
  }

  /**
   * Send Receipt
   * @param  [type] $participantInfo         [description]
   * @param  [type] $paymentParams           [description]
   * @return [type]                          [description]
   */
  public static function send_receipt($participantInfo, $paymentParams) {
    $receiptTable = CRM_Paymentui_BAO_Paymentui::buildReceiptEmailTable($participantInfo);
    $body = "<p>Thank you for completing your payment. See details below:</p>
      <div>$receiptTable</div>
      <p>Please contact us with any concerns.</p>
      <p>Phone 770-455-9622</p>
      <p>Email us at studentaccounts@georgiacivics.org</p>";
    $mailParams = array(
      'from' => 'Student Accounts <studentaccounts@georgiacivics.org>',
      'toName' => "{$paymentParams['first_name']} {$paymentParams['last_name']}",
      'toEmail' => $paymentParams['email'],
      'cc'   => 'studentaccounts@georgiacivics.org',
      'bcc' => '',
      'subject' => 'Your Account Statement for Student Travel',
      'text' => $body,
      'html' => $body,
      'replyTo' => 'reply-to header in the email',
    );
    $receiptEmail = CRM_Utils_Mail::send($mailParams);
  }

  /**
   * [update_line_items_for_fees description]
   * @param  [type] $pid       [description]
   * @param  [type] $pfee      [description]
   * @param  [type] $latefee   [description]
   * @param  [type] $contribId [description]
   * @return [type]            [description]
   */
  public static function update_line_items_for_fees($pid, $pfee, $latefee, $contribId) {
    // get the Date
    $paymentmade = date('Y-m-d H:i:s');
    // Create new line items for each fee
    if ($latefee > 0) {
      $lateFeeLineItem = self::apishortcut('LineItem', 'create', [
        'entity_table' => "civicrm_participant",
        'qty' => 1,
        'unit_price' => $latefee,
        'line_total' => $latefee,
        'non_deductible_amount' => 0,
        'tax_amount' => 0,
        'price_field_id' => "",
        'contribution_id' => $contribId,
        'label' => "Late Fee - $paymentmade",
        'entity_id' => $pid,
        'financial_type_id' => "Donation",
      ]);
    }

    if ($pfee > 0) {
      $pFeeLineItem = self::apishortcut('LineItem', 'create', [
        'entity_table' => "civicrm_participant",
        'qty' => 1,
        'unit_price' => $pfee,
        'line_total' => $pfee,
        'contribution_id' => $contribId,
        'label' => "Processing Fee - {$paymentmade}",
        'entity_id' => $pid,
        'non_deductible_amount' => 0,
        'tax_amount' => 0,
        'price_field_id' => "",
        'financial_type_id' => "Donation",
      ]);
    }
  }

}
