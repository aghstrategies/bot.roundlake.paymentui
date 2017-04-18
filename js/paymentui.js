CRM.$(function ($) {
  var calculateTotal = function () {
    var total = 0.00;
    $.each($("input[name^='payment']"), function () {
      var amt = $(this).val();
      if ($.isNumeric(amt)) {
        total = parseFloat(total) + parseFloat(amt);
      }
    });

    var creditcardfees = (total * 2 / 100).toFixed(2);
    var latefees = 0;
    if (parseFloat($('#latefees').html()) > 0) {
      latefees = parseFloat($('#latefees').html());
    }

    document.getElementById('creditCardFees').innerHTML = creditcardfees;
    total = Math.round(total * 100, 2) / 100;
    total = parseFloat(total) + parseFloat(creditcardfees) + latefees;
    total.toFixed(2);
    document.getElementById('total').innerHTML = total;
  };

  $("input[id^='payment']").keyup(function () {
    calculateTotal();
  });

  calculateTotal();
});
