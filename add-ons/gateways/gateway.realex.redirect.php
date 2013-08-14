<?php

class EM_Gateway_Realex_Redirect extends EM_Gateway {
	var $gateway = 'realex';
	var $title = 'RealEx Redirect';
	var $status = 4;
	var $status_txt = 'Awaiting RealEx Payment';
	var $button_enabled = true;
	var $payment_return = true;

	/**
	 * Sets up gateaway and adds relevant actions/filters
	 */
	function __construct() {
		parent::__construct();
		$this->status_txt = __('Awaiting RealEx Payment','em-pro');
		if($this->is_active()) {
			//Booking Interception
			if ( absint(get_option('em_'.$this->gateway.'_booking_timeout')) > 0 ){
				//Modify spaces calculations only if bookings are set to time out, in case pending spaces are set to be reserved.
				add_filter('em_bookings_get_pending_spaces', array(&$this, 'em_bookings_get_pending_spaces'),1,2);
			}
			add_action('em_gateway_js', array(&$this,'em_gateway_js'));
			//Gateway-Specific
			add_action('em_template_my_bookings_header',array(&$this,'say_thanks')); //say thanks on my_bookings page
			add_action('em_template_my_bookings_header',array(&$this,'pay_fail_message')); //display error message back to customer
			//set up cron
			$timestamp = wp_next_scheduled('emp_cron_hook');
			if( absint(get_option('em_'.$this->gateway.'_booking_timeout')) > 0 && !$timestamp ){
				$result = wp_schedule_event(time(),'em_minute','emp_cron_hook');
			}elseif( !$timestamp ){
				wp_unschedule_event($timestamp, 'emp_cron_hook');
			}
		}else{
			//unschedule the cron
			$timestamp = wp_next_scheduled('emp_cron_hook');
			wp_unschedule_event($timestamp, 'emp_cron_hook');
		}
	}

	/*
	 * --------------------------------------------------
	 * Booking Interception - functions that modify booking object behaviour
	 * --------------------------------------------------
	 */

	/**
	 * Modifies pending spaces calculations to include RealEx bookings, but only if RealEx bookings are set to time-out
	 * (i.e. they'll get deleted after x minutes), therefore can be considered as 'pending' and can be reserved temporarily.
	 * @param integer $count
	 * @param EM_Bookings $EM_Bookings
	 * @return integer
	 */
	function em_bookings_get_pending_spaces($count, $EM_Bookings){
		foreach($EM_Bookings->bookings as $EM_Booking){
			if($EM_Booking->booking_status == $this->status && $this->uses_gateway($EM_Booking)){
				$count += $EM_Booking->get_spaces();
			}
		}
		return $count;
	}

	/**
	 * Intercepts return data after a booking has been made and adds RealEx Redirect form vars, modifies feedback message.
	 * @param array $return
	 * @param EM_Booking $EM_Booking
	 * @return array
	 */
	function booking_form_feedback( $return, $EM_Booking = false ){
		//Double check $EM_Booking is an EM_Booking object and that we have a booking awaiting payment.
		if( is_object($EM_Booking) && $this->uses_gateway($EM_Booking) ){
			if( !empty($return['result']) && $EM_Booking->get_price() > 0 && $EM_Booking->booking_status == $this->status ){
				$return['message'] = get_option('em_'.$this->gateway.'_booking_feedback');
				$realex_redirect_url = $this->get_redirect_url();
				$realex_redirect_vars = $this->get_form_vars($EM_Booking);
				$realex_redirect_return = array('redirect_url'=>$realex_redirect_url, 'form_vars'=>$realex_redirect_vars);
				$return = array_merge($return, $realex_redirect_return);
			}else{
				//returning a free message
				$return['message'] = get_option('em_'.$this->gateway.'_booking_feedback_free');
			}
		}
		return $return;
	}

	/*
	 * --------------------------------------------------
	 * Booking UI - modifications to booking pages and tables containing RealEx Redirect bookings
	 * --------------------------------------------------
	 */


	/**
	 * Instead of a simple status string, a resume payment button is added to the status
	 * message so user can resume booking from their my-bookings page.
	 * @param string $message
	 * @param EM_Booking $EM_Booking
	 * @return string
	 */
	function em_my_bookings_booked_message( $message, $EM_Booking){
		$message = parent::em_my_bookings_booked_message($message, $EM_Booking);
		if($this->uses_gateway($EM_Booking) && $EM_Booking->booking_status == $this->status){
			//user owes money!
			$realex_redirect_vars = $this->get_form_vars($EM_Booking);
			$form = '<form action="'.$this->get_redirect_url().'" method="post">';
			foreach($realex_redirect_vars as $key=>$value){
				$form .= '<input type="hidden" name="'.$key.'" value="'.$value.'" />';
			}
			$form .= '<input type="submit" value="'.__('Resume Payment','em-pro').'">';
			$form .= '</form>';
			$message .= " ". $form;
		}
		return $message;
	}

	/**
	 * Outputs extra custom content e.g. the logo by default.
	 */
	function booking_form(){
		echo get_option('em_'.$this->gateway.'_form');
	}

	/**
	 * Outputs some JavaScript during the em_gateway_js action, which is run inside a script
	 * html tag, located in gateways/gateway.realex.redirect.js
	 */
	function em_gateway_js(){
		include(dirname(__FILE__).'/gateway.realex.redirect.js');
	}



	/*
	 * ----------------------------------------------------------------------
	 * RealEx Form Functions - functions specific to RealEx Form payments
	 * ----------------------------------------------------------------------
	 */

	/**
	 * Retreive the realex reditrect pay vars needed to send to the gatway to proceed with payment
	 * @param EM_Booking $EM_Booking
	 */
	function get_form_vars($EM_Booking){
		global $wp_rewrite, $EM_Notices;

		$merchantid = get_option('em_'. $this->gateway . "_merchant_id" );
		$secret     = get_option('em_'. $this->gateway . "_shared_secret" );

		//The code below is used to create the timestamp format required by Realex Payments
		$timestamp = strftime("%Y%m%d%H%M%S");
		mt_srand((double)microtime()*1000000);

		$orderid = $EM_Booking->booking_id."-".$timestamp."-".mt_rand(1, 999);

		$curr = get_option('dbem_bookings_currency', 'GBP');

		// The amount should be in the smallest unit of the required currency (i.e. 2000 = £20, $20 or €20)
        $amount     = $EM_Booking->get_price(false, false, true) * 100;

		/*
		Below is the code for creating the digital signature using the MD5 algorithm provided
		by PHP. you can use the SHA1 algorithm alternatively.
		*/
		$tmp = "$timestamp.$merchantid.$orderid.$amount.$curr";
		$md5hash = md5($tmp);
		$tmp = "$md5hash.$secret";
		$md5hash = md5($tmp);

		$comment = "Booking #".$EM_Booking->booking_id." - ".
			$EM_Booking->booking_spaces. " space(s) for ".
			$EM_Booking->event->event_name;

		$form_vars = array(
			"MERCHANT_ID" => $merchantid,
			"ORDER_ID" => $orderid,
			"CURRENCY" => $curr,
			"AMOUNT" => $amount,
			"TIMESTAMP" => $timestamp,
			"MD5HASH" => $md5hash,
			"AUTO_SETTLE_FLAG" => "1",
			"CUST_NUM" => $EM_Booking->person_id,
			"COMMENT1" => "Events Manager Booking from ".get_site_url(),
			"COMMENT2" => $comment,
			"PROD_ID" => $EM_Booking->event_id
		);

		$sub_acc = get_option('em_'. $this->gateway . "_account" );
		if( !empty( $sub_acc ) ) {
			$form_vars['ACCOUNT'] = $sub_acc;
		}

		return apply_filters('em_gateway_realex_redirect_get_form_vars', $form_vars, $EM_Booking, $this);
	}

	function get_redirect_url(){
		return "https://epage.payandshop.com/epage.cgi";
	}

	/*
	 * Overide parent return url with value for rewrite rule defined in gateway.realex.redirect.php
	 * Can be removed if RealEx sort out their return URL issues
	 */
	function get_payment_return_url() {
		return get_site_url()."/realex-redirect-return";
	}

	function say_thanks(){
		if( $_REQUEST['thanks'] == 1 ){
			echo "<div class='em-booking-message em-booking-message-success'>".get_option('em_'.$this->gateway.'_booking_feedback_thanks').'</div>';
		}
	}

	function pay_fail_message() {
		if( isset( $_REQUEST['fail'] ) ) {
			echo "<div class='em-booking-message em-booking-message-error'><p>Booking Unsuccessful</p><p>";
			switch ( substr($_REQUEST['fail'], 0, 1 ) ) {
				case "1":
					echo "Your payment was declined by the bank.  This could be due to insufficient funds, or incorrect card details.";
					break;
				case "2":
					echo "Your payment could not be processed due to a error from the bank. You have not been charged.";
					break;
				case "3":
					echo "Your payment could not be processed due to a error from the RealEx payment system. You have not been charged.";
					break;
				case "5":
					echo "Your payment could not be processed due to errors in the data received by RealEx payment system.";
					break;
				case "6":
					echo "Payment could not be processed as the RealEx client account has been deactivated.";
					break;
			}
			echo "</p></div>";
		}
	}

	/**
	 * Runs when user returns from Sage Pay with transaction result. Bookings are updated and transactions are recorded accordingly.
	 */
	function handle_payment_return() {

		//var_dump( $_POST );

		if( empty( $_POST['TIMESTAMP'])) {
			echo '<p>RealEx Response Error: Invalid RealEx response</p>';
			echo '<p><a href="'.get_site_url().'">'.__('Continue', 'emp-pro').'</a>';
			exit;
		}

		/*
		 Note:The below code is used to grab the fields Realex Payments POSTs back
		 to this script after a card has been authorised. Realex Payments need
		 to know the full URL of this script in order to POST the data back to this
		 script. Please inform Realex Payments of this URL if they do not have it
		 already.

		 Look at the Realex Documentation to view all hidden fields Realex POSTs back
		 for a card transaction.
		*/
		$timestamp = $_POST['TIMESTAMP'];
		$result = $_POST['RESULT'];
		$orderid = $_POST['ORDER_ID'];
		$message = $_POST['MESSAGE'];
		$authcode = $_POST['AUTHCODE'];
		$pasref = $_POST['PASREF'];
		$realexmd5 = $_POST['MD5HASH'];

		$merchantid = get_option('em_'. $this->gateway . "_merchant_id" );
		$secret     = get_option('em_'. $this->gateway . "_shared_secret" );
		$currency 	= get_option('dbem_bookings_currency', 'GBP');

		//---------------------------------------------------------------
		//Below is the code for creating the digital signature using the md5 algorithm.
		//This digital siganture should correspond to the
		//one Realex Payments POSTs back to this script and can therefore be used to verify the message Realex sends back.
		$tmp = "$timestamp.$merchantid.$orderid.$result.$message.$pasref.$authcode";
		$md5hash = md5($tmp);
		$tmp = "$md5hash.$secret";
		$md5hash = md5($tmp);

		//Check to see if hashes match or not
		if ($md5hash != $realexmd5) {
			echo "RealEx Response Error: hashes don't match - response not authenticated!";
			echo '<p><a href="'.get_site_url().'">'.__('Continue', 'emp-pro').'</a>';
			exit;
		}

		/* --------------------------------------------------------------
		 send yourself an email or send the customer an email or update a database or whatever you want to do here.

		 The next part is important to understand. The result field sent back to this
		 response script will indicate whether the card transaction was successful or not.
		 The result 00 indicates it was while anything else indicates it failed.
		 Refer to the Realex Payments documentation to get a full list to response codes.


		IMPORTANT: Whatever this response script prints is grabbed by Realex Payments
		and placed in the template again. It is placed wherever the comment "<!--E-PAGE TABLE HERE-->"
		is in the template you provide. This is the case so that from a customer's perspective, they are not suddenly removed from
		a secure site to an unsecure site. This means that although we call this response script the
		customer is still on Realex PAyemnt's site and therefore it is recommended that a HTML link is
		printed in order to redirect the customrer back to the merchants site.
		*/


		// Load Booking
		$orderid = substr( $orderid, 0, strpos($orderid, '-') );
		$EM_Booking = new EM_Booking( $orderid );

		if( !empty($EM_Booking->booking_id) ){

			if ($result == "00") {

				$EM_Booking->booking_meta[$this->gateway] = array('txn_id'=>$pasref, 'amount' => $EM_Booking->get_price(false, false, true));
		        $this->record_transaction($EM_Booking,  $EM_Booking->get_price(false, false, true), $currency, $timestamp, $authcode, 'Completed', $message);

	        	//Set booking status, but no emails sent
				if( !get_option('em_'.$this->gateway.'_manual_approval', false) || !get_option('dbem_bookings_approval') ){
					$EM_Booking->set_status(1, false); //Approve
				}else{
					$EM_Booking->set_status(0, false); //Set back to normal "pending"
				}

				// Redirect to custom page, or default thanks message
				$redirect = get_option('em_'. $this->gateway . '_return_success');
				if( empty( $redirect ) ) {
					$redirect = get_permalink(get_option("dbem_my_bookings_page")).'?thanks=1';
				}

				echo '<p>'.get_option('em_'.$this->gateway.'_booking_feedback_thanks').'</p>';
				echo '<p><a href="'.$redirect.'">'.__('Continue', 'emp-pro').'</a>';

			} else {
		        $this->record_transaction($EM_Booking, $EM_Booking->get_price(false, false, true), $currency, $timestamp, $authcode, $result, $message);

		        $EM_Booking->cancel();
		        do_action( 'em_payment_'.strtolower($strStatus), $EM_Booking, $this);

				// Redirect to custom page, or my bookings with error
				$redirect = get_option('em_'. $this->gateway . '_return_fail');

				if( empty( $redirect ) ) {
					$redirect = get_permalink(get_option("dbem_my_bookings_page")).'?fail='.$result;
				}

				echo '<p>'.get_option('em_'.$this->gateway.'_booking_feedback_fail').'</p>';
				echo '<a href="'.$redirect.'">'.__('Continue', 'emp-pro').'</a>';
			}
		}else{
			if( $result == "00" ){

				$message = apply_filters('em_gateway_paypal_bad_booking_email',"
A Payment has been received by RealEx for a non-existent booking.

Event Details : %event%

It may be that this user's booking has timed out yet they proceeded with payment at a later stage.

To refund this transaction, you must go to your RealEx account and search for this transaction:

Transaction ID : %transaction_id%

When viewing the transaction details, you should see an option to issue a refund.

If there is still space available, the user must book again.

Sincerely,
Events Manager
					", $booking_id, $event_id);

				if( !empty($event_id) ){
					$EM_Event = new EM_Event($event_id);
					$event_details = $EM_Event->name . " - " . date_i18n(get_option('date_format'), $EM_Event->start);
				}else{
					$event_details = __('Unknown','em-pro');
				}
				$message  = str_replace(array('%transaction_id%', '%event%'), array($strVPSTxId, $event_details), $message);
				wp_mail(get_option('em_'. $this->gateway . "_email" ), __('Unprocessed payment needs refund'), $message);
			}else{
				echo 'Error: Bad RealEx request, custom ID does not correspond with any pending booking.';
				exit;
			}
		}
		return;
	}

	/*
	 * --------------------------------------------------
	 * Gateway Settings Functions
	 * --------------------------------------------------
	 */

	/**
	 * Outputs custom RealEx Redirect setting fields in the settings page
	 */
	function mysettings() {
		global $EM_options;
		?>
		<table class="form-table">
		<tbody>
		  <tr valign="top">
			  <th scope="row"><?php _e('Success Message', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="booking_feedback" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('The message that is shown to a user when a booking is successful whilst being redirected to RealEx for payment.','em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Success Free Message', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="booking_feedback_free" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_free" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('If some cases if you allow a free ticket (e.g. pay at gate) as well as paid tickets, this message will be shown and the user will not be redirected to RealEx.','em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Thank You Message', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="booking_feedback_thanks" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_thanks" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('Message displayed to the user on successful payment.','em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Fail Message', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="booking_feedback_fail" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_feedback_fail" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('Messaged displayed to the user on payment failure.','em-pro'); ?></em>
			  </td>
		  </tr>
		</tbody>
		</table>

		<h3><?php echo sprintf(__('%s Options','em-pro'),'RealEx Redirect')?></h3>
		<p><?php echo __('<strong>Important:</strong> In order to integrate RealEx Redirect with your site, you need inform RealEx of your response URL.');?><br />
		<?php echo " ". sprintf(__('Your return url is %s','em-pro'),'<code>'.$this->get_payment_return_url().'</code>');?><br />
	    <?php echo __('<strong>Note:</strong> This url is generated with a custom rewrite rule and could cause problems if url re-writing not supported on your server.');?></p>

		<table class="form-table">
		<tbody>
			<tr valign="top">
				  <th scope="row"><?php _e('Merchant ID', 'emp-pro') ?></th>
				  <td><input type="text" name="merchant_id" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_merchant_id", "" )); ?>" /></td>
			</tr>
			<tr valign="top">
				  <th scope="row"><?php _e('Account', 'emp-pro') ?></th>
				  <td>
				  	<input type="text" name="account" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_account", "" )); ?>" />
				  	<em><?php _e('Enter RealEx sub account name if set up. Leave blank for default.', 'em-pro'); ?></em>
				  </td>
			</tr>
			<tr valign="top">
			 	<th scope="row"><?php _e('Shared Secret', 'emp-pro') ?></th>
			    <td><input type="password" name="shared_secret" value="<?php esc_attr_e(get_option( 'em_'. $this->gateway . "_shared_secret", "" )); ?>" /></td>
			</tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Return Success URL', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="return_success" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_return_success" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('Once a payment is completed, users will sent to the My Bookings page which confirms that the payment has been made. If you would to customize the thank you page, create a new page and add the link here.  Link must be absolute. Leave blank to return to default booking page with the thank you message specified above.', 'em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Return Fail URL', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="return_fail" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_return_fail" )); ?>" style='width: 40em;' /><br />
			  	<em><?php _e('If a payment is unsucessful or if a user cancels, they will be redirected to the my bookings page. If you want a custom page instead, create a new page and add the link here. Link must be absolute.', 'em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Delete Bookings Pending Payment', 'em-pro') ?></th>
			  <td>
			  	<input type="text" name="booking_timeout" style="width:50px;" value="<?php esc_attr_e(get_option('em_'. $this->gateway . "_booking_timeout" )); ?>" style='width: 40em;' /> <?php _e('minutes','em-pro'); ?><br />
			  	<em><?php _e('Once a booking is started and the user is taken to RealEx Redirect, Events Manager stores a booking record in the database to identify the incoming payment. These spaces may be considered reserved if you enable <em>Reserved unconfirmed spaces?</em> in your Events &gt; Settings page. If you would like these bookings to expire after x minutes, please enter a value above (note that bookings will be deleted, and any late payments will need to be refunded manually via RealEx).','em-pro'); ?></em>
			  </td>
		  </tr>
		  <tr valign="top">
			  <th scope="row"><?php _e('Manually approve completed transactions?', 'em-pro') ?></th>
			  <td>
			  	<input type="checkbox" name="manual_approval" value="1" <?php echo (get_option('em_'. $this->gateway . "_manual_approval" )) ? 'checked="checked"':''; ?> /><br />
			  	<em><?php _e('By default, when someone pays for a booking, it gets automatically approved once the payment is confirmed. If you would like to manually verify and approve bookings, tick this box.','em-pro'); ?></em><br />
			  	<em><?php echo sprintf(__('Approvals must also be required for all bookings in your <a href="%s">settings</a> for this to work properly.','em-pro'),EM_ADMIN_URL.'&amp;page=events-manager-options'); ?></em>
			  </td>
		  </tr>
		</tbody>
		</table>
		<?php
	}

	/*
	 * Run when saving RealEx settings, saves the settings available in EM_Gateway_Realex_Redirect::mysettings()
	 */
	function update() {

		parent::update();
		if( !empty($_REQUEST['Submit']) ) {
			$gateway_options = array(
				$this->gateway . "_merchant_id" => $_REQUEST[ 'merchant_id' ],
				$this->gateway . "_account" => $_REQUEST[ 'account' ],
				$this->gateway . "_shared_secret" => $_REQUEST[ 'shared_secret' ],
				$this->gateway . "_return_success" => $_REQUEST[ 'return_success' ],
				$this->gateway . "_return_fail" => $_REQUEST[ 'return_fail' ],
				$this->gateway . "_booking_feedback" => wp_kses_data( $_REQUEST[ 'booking_feedback' ]),
				$this->gateway . "_booking_feedback_free" => wp_kses_data( $_REQUEST[ 'booking_feedback_free' ]),
				$this->gateway . "_booking_feedback_thanks" => wp_kses_data($_REQUEST[ 'booking_feedback_thanks' ]),
				$this->gateway . "_booking_feedback_fail" => wp_kses_data($_REQUEST[ 'booking_feedback_fail' ]),
				$this->gateway . "_booking_feedback" => wp_kses_data($_REQUEST[ 'booking_feedback' ]),
				$this->gateway . "_manual_approval" => $_REQUEST[ 'manual_approval' ],
				$this->gateway . "_booking_timeout" => $_REQUEST[ 'booking_timeout' ],
			);
			foreach($gateway_options as $key=>$option){
				update_option('em_'.$key, stripslashes($option));
			}
		}
		//default action is to return true
		return true;
	}
}
EM_Gateways::register_gateway('realex', 'EM_Gateway_Realex_Redirect');

/**
 * Deletes bookings pending payment that are more than x minutes old, defined by RealEx Redirect options.
 */
function em_gateway_realex_redirect_booking_timeout(){
	global $wpdb;
	//Get a time from when to delete
	$minutes_to_subtract = absint(get_option('em_realex_redirect_booking_timeout'));
	if( $minutes_to_subtract > 0 ){
		//Run the SQL query
		//first delete ticket_bookings with expired bookings
		$sql = "DELETE FROM ".EM_TICKETS_BOOKINGS_TABLE." WHERE booking_id IN (SELECT booking_id FROM ".EM_BOOKINGS_TABLE." WHERE booking_date < TIMESTAMPADD(MINUTE, -{$minutes_to_subtract}, NOW()) AND booking_status=4);";
		$wpdb->query($sql);
		//then delete the bookings themselves
		$sql = "DELETE FROM ".EM_BOOKINGS_TABLE." WHERE booking_date < TIMESTAMPADD(MINUTE, -{$minutes_to_subtract}, NOW()) AND booking_status=4;";
		$wpdb->query($sql);
		update_option('emp_result_try',$sql);
	}
}
add_action('emp_cron_hook', 'em_gateway_realex_redirect_booking_timeout');
?>