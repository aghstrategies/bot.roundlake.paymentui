Scenario: Making a Partial Payment

When a logged in user visits http://dev.ymcaga.org/index.php?option=com_civicrm&task=civicrm/roundlake/add/payment&reset=1
And they or a contact with a child of relationship to them have a partial payment for an event each paritally paid event will show up with the event, reigstraant cost, paid to date, $$ remaining and a box to make a payment
And if they enter an amount to the make payment box for any line the total amounts entered are added together and displaed on the total line
And if they enter an amount greater than $$ remaining the form will throw an error
And they can enter their credit card information to make a payment against the $$ remaining
Then if they pay the $$ remaining that partial payment will become paid and no longer show up and if they still owe it will continue to show up with the updated $$ remaining and paid to data amounts.

Scenario: Sending an email to a parent with the 'Table of Partial Payment Information' token

When sending an email to a contact
And that email includes partialPayment.table token
And that contact has a parent of relationship to <child>
And the <child> has a partially paid event registration
Then the token will display a table of the <child> partially paid event registration information including the following fields: Event Name, Contact Name, Total Amount, Paid, Balance
And there will be a row for each partially paid event registration for each contact with a child of relationship and the the contact email is being sent to.

Scenario: Applying late fees to partially paid registrations

When an event has the custom field (generated by this extension) "Event Late Fees" populated with a table that looks like this: "04/15/2017:100\r\n04/20/2017:100\r\n04/25/2017:100"
And todays date is <today>
And the contact has paid <dollars>
And the late fee as set on the civicrm/paymentui/feessettings page is <lateFee>
Then <lateFee> will be added to the total (no credit card processing will be applied to it)
And a contribution will be made on the backend on the childs record

| today      | dollars | lateFee |
| 04/16/2017 | 100     | 0       |
| 04/16/2017 | 10      | 10      |
| 04/21/2017 | 10      | 20      |
| 04/21/2017 | 102     | 10      |
| 04/21/2017 | 200     | 0       |

Scenario: Applying Processing Fee

When a logged in contact makes a payment of <dollars> on the roundlake/add/payment page
And the procesing fee as set on the civicrm/paymentui/feessettings page is <processingFee>
Then a <processingFee>% processing fee is added to the payment
And this amount is displayed as a line item "Processing Fee" on the page
And a contribution record is made for the logged in contact for the amount <dollars>
And if they are late fees the late fees are not included in the <dollars> only the payments towards partially paid event registrations are included in <dollars>

Scenario: Defaulting to amount due

When viewing the add payment page
And the event for the row has an event schedule
Then the amount due will have a default amount that is the amount due by the next payment - the amount paid
