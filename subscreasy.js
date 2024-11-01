var successCallback = function(data) {
    console.log("successCallback: " + data);
    
    var checkout_form = $( 'form.woocommerce-checkout' );

    // add a token to our hidden input field
    // console.log(data) to find the token
    checkout_form.find('#subscreasy_token').val(data.token);

    // deactivate the tokenRequest function event
    checkout_form.off( 'checkout_place_order', tokenRequest );

    // submit the form now
    checkout_form.submit();
};

var errorCallback = function(data) {
    console.log("errorCallback: " + data);
};

var tokenRequest = function() {
    // here will be a payment gateway function that process all the card data from your form,
    // maybe it will need your Publishable API key which is misha_params.publishableKey
    // and fires successCallback() on success and errorCallback on failure

    return false;
};

jQuery(function($){
    $('body').on('updated_checkout', function () {
        // let config = {
        //     minimumResultsForSearch: Infinity
        // }
        // $('#subscreasy_expMonth').selectWoo(config);
        // $('#subscreasy_expYear').selectWoo(config);

        let monthList = getMonthList();
        let yearList = getYearList();

        $("#subscreasy_expMonth").selectWoo({data: monthList, minimumResultsForSearch: Infinity, dropdownAutoWidth: true});
        $("#subscreasy_expYear").selectWoo({data: yearList, minimumResultsForSearch: Infinity, dropdownAutoWidth: true});
    });

    function getMonthList() {
        let monthListArr = [];
        for (let i = 1; i <= 12; i++) {
            let zeroPaddedMonth = i.toString().length < 2 ? "0" + i : i;
            monthListArr.push({ value: zeroPaddedMonth, label: zeroPaddedMonth})
        }
        return toMap(monthListArr);
    }

    function getYearList() {
        let yearListArr = [];
        let thisYear = new Date().getFullYear();
        for (let i = thisYear; i <= thisYear + 11; i++) {
            yearListArr.push({ value: i + "", label: i + ""})
        }
        return toMap(yearListArr);
    }

    function toMap(arr) {
        return $.map(arr, function (obj) {
            obj.id = obj.id || obj.value;
            obj.text = obj.text || obj.label;
            return obj;
        });
    }
});