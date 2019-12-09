CRM.$(function ($) {
  $.each($('table.partialPaymentInfo tr'), function () {
    if ($('td.balance', this).text() == '$ 0.00') {
      $('td.payment', this).css('visibility', 'hidden');
    }
  });

  var calculateTotal = function () {
    var total = 0;
    var subtotal = 0;
    var pfee = 0;
    var latefee = 0;
    var partId = '';
    var paymentAmount = 0;

    $.each($("input[name^='payment']"), function () {
      partId = $(this).attr('id').substring(8);

      // get late fees
      if ($("input[name='latefee[" + partId + "]").val()) {
        latefee = parseFloat($("input[name='latefee[" + partId + "]").val());
      }

      if ($.isNumeric($(this).val())) {
        paymentAmount = $(this).val();

        // Calculate Processing Fees by Participant
        pfee = (parseFloat(paymentAmount) * CRM.vars.paymentui.processingFee / 100).toFixed(2);
      }
      // calculate subtotals
      subtotal = (parseFloat(paymentAmount) + parseFloat(pfee) + parseFloat(latefee)).toFixed(2);

      // calculate total
      total = (parseFloat(subtotal) + parseFloat(total)).toFixed(2);

      $("input[name='subtotal[" + partId + "]").val(subtotal);
      $("input[name='pfee[" + partId + "]").val(pfee);

    });

    document.getElementById('total').innerHTML = total;
  };

  $("input[id^='payment']").keyup(function () {
    calculateTotal();
  });

  calculateTotal();
});
