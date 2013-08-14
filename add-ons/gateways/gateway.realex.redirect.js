//add sagepay redirection
$(document).bind('em_booking_gateway_add_realex', function(event, response){

	// called by EM if return JSON contains gateway key, notifications messages are shown by now.
	if(response.result){
		var rrForm = $('<form action="'+response.redirect_url+'" method="post" id="em-realex-form"></form>');
		$.each( response.form_vars, function(index,value){
			rrForm.append('<input type="hidden" name="'+index+'" value="'+value+'" />');
		});
		rrForm.append('<input id="em-paypal-submit" type="submit" style="display:none" />');
		rrForm.insertAfter('#em-booking-form').trigger('submit');
	}
});