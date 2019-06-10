This Extension is a combination of:

+ https://github.com/backoffice/BOT-Partial-Payment-Extension
+ https://github.com/backoffice/bot.roundlake.paymentui

modified to work with civicrm 4.7

Differences between this extension and Backoffice Originals:

+ Uses an api call to get related contacts and only gets parent/child relationships
+ Uses id 15 instead of 14 for partial payments
+ Uses the payment processor as set on the [settings page for the extension](https://ymcaga.org/administrator/?option=com_civicrm&task=civicrm/paymentui/feessettings)
+ Adds late fees based on the "Event Late Fees" (a custom field on the event) and the Late Fee amount set on the [settings page for the extension](https://ymcaga.org/administrator/?option=com_civicrm&task=civicrm/paymentui/feessettings)
+ Creates a token of the table of partially paid event registrations for the contact and anyone they have a child of relationship to
+ Adds a credit card processing fee based on the percentage amount set on the [settings page for the extension](https://ymcaga.org/administrator/?option=com_civicrm&task=civicrm/paymentui/feessettings)
+ Creates a settings page: civicrm/paymentui/feessettings where one can set the processing fee and the late fee
+ Tokens: generates two tokens: {partialPayment.simpleTable} and {partialPayment.table} that generates the tables the user sees when on the payment page as tokens to be used in emails and for invoices.
+ Sets default amount paid input text to be the amount owed if late plus the amount for the next payment
+ Ignores any [com.joineryhq.percentagepricesetfield](https://github.com/twomice/com.joineryhq.percentagepricesetfield) line items from totals

## Settings for this extension:

### On the settings page:

https://ymcaga.org/administrator/?option=com_civicrm&task=civicrm/paymentui/feessettings

Processing Fee: Amount to be charged for using credit card 4 = 4%
Late Fee: Amount to be charged if late (if two scheduled payments late $20)
Select Payment Processor: Payment Processor to use on add payment page

### On the event page:

For each event that one would like to set up a payment schedule for (have people be charged late fees if they do not make their partial payments on time): One must add a payment schedule formatted like this to the "Event Late Fees" custom field on the event. For an event where the registration fee was $200 a payment schedule may look like this one below. Where the date is the Date due and the number is the amount due at that time.  on 06/22/2017 with this payment schedule the event registrant would be expected to have paid 100 or owe a late fee.

```
06/01/2017:50
06/21/2017:50
07/21/2017:50
08/21/2017:50
```

## Create a Partially Paid Event registration

This must be done on the backend. Go to a contact, add an event registration with a participant status of "partially paid" and enter into the record payment amount an amount less than the total. Visit: https://ymcaga.org/index.php?option=com_civicrm&task=civicrm/addpayment logged in on the front end as the contact you created a partially paid event registration for. you should see the cost being the payment option amount. Paid to Date being the amount entered into the record payment box. $$ remaining being the difference.

In Civi 4.7.23 and above, amount entered when partially paid registration is made cannot be $0.00 without the price of the event itself also being saved as $0.00. No future payments will be allowed if this is done. Enter an amount greater than $0.00 to see the remaining balance owed and enable future payments.

See Screenshots regarding this process in img folder.

For additional tests regarding late fees and processing fees see tests file.
