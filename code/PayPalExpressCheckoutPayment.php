<?php

/**
 * PayPal Express Checkout Payment
 * @author Jeremy Shipman jeremy [at] burnbright.net
 * @author Nicolaas [at] sunnysideup.co.nz
 *
 * Developer documentation:
 * Integration guide: https://cms.paypal.com/cms_content/US/en_US/files/developer/PP_ExpressCheckout_IntegrationGuide.pdf
 * API reference: 	  https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/howto_api_reference
 * Uses the Name-Value Pair API protocol
 *
 */



class PayPalExpressCheckoutPayment extends EcommercePayment {


	static $db = array(
		'Token' => 'Varchar(30)',
		'PayerID' => 'Varchar(30)',
		'TransactionID' => 'Varchar(30)',
		'AuthorisationCode' => 'Text'
	);
	protected static $logo = "ecommerce/images/paymentmethods/paypal.jpg";
	protected static $payment_methods = array();

	//PayPal URLs
	protected static $test_API_Endpoint = "https://api-3t.sandbox.paypal.com/nvp";
	protected static $test_PAYPAL_URL = "https://www.sandbox.paypal.com/webscr?cmd=_express-checkout&token=";
	protected static $API_Endpoint = "https://api-3t.paypal.com/nvp";
	protected static $PAYPAL_URL = "https://www.paypal.com/cgi-bin/webscr?cmd=_express-checkout&token=";
	protected static $privacy_link = "https://www.paypal.com/us/cgi-bin/webscr?cmd=p/gen/ua/policy_privacy-outside";

	//config
	protected static $test_mode = true; //on by default
	protected static $API_UserName;
	protected static $API_Password;
	protected static $API_Signature;
	protected static $sBNCode = null; // BN Code 	is only applicable for partners
	protected static $version = '64';

	//set custom settings
	protected static $custom_settings = array(
		//design
		//'HDRIMG' => "http://www.mysite.com/images/logo.jpg", //max size = 750px wide by 90px high, and good to be on secure server
		//'HDRBORDERCOLOR' => 'CCCCCC', //header border
		//'HDRBACKCOLOR' => '00FFFF', //header background
		//'PAYFLOWCOLOR'=> 'AAAAAA' //payflow colour
		//'PAGESTYLE' => //page style set in merchant account settings
		'SOLUTIONTYPE' => 'Sole'//require paypal account, or not. Can be or 'Mark' (required) or 'Sole' (not required)
		//'BRANDNAME'  => 'my site name'//override business name in checkout
		//'CUSTOMERSERVICENUMBER' => '0800 1234 5689'//number to call to resolve payment issues
		//'NOSHIPPING' => 1 //disable showing shipping details
	);

	private static function set_config_details($username,$password,$signature,$testmode = true){
		self::$API_UserName = $username;
		self::$API_Password = $password;
		self::$API_Signature = $signature;
		//self::$sBNCode = $sbncode;
		self::$test_mode = $testmode;
	}

	static function set_live_config($username,$password,$signature){
		self::set_config_details($username,$password,$signature,false);
	}

	static function set_test_config($username,$password,$signature){
		self::set_config_details($username,$password,$signature,true);
	}

	static function set_payment_methods($a){
		self::$payment_methods = $a;
	}

	static function set_custom_settings(array $design){
		self::$custom_settings = array_merge(self::$custom_settings,$design);
	}

	function getPaymentFormFields() {
		$logo = '<img src="' . self::$logo . '" alt="Credit card payments powered by PayPal"/>';
		$privacyLink = '<a href="' . self::$privacy_link . '" target="_blank" title="Read PayPal\'s privacy policy">' . $logo . '</a><br/>';
		return new FieldList(
			new LiteralField('PayPalInfo', $privacyLink),
			new LiteralField(
				'PayPalPaymentsList',
				$this->renderWith("PaymentMethods")
			)
		);
	}

	function getPaymentFormRequirements() {return null;}

	//main processing function
	function processPayment($data, $form) {
		//sanity checks for credentials
		if(!self::$API_UserName || !self::$API_Password || !self::$API_Signature){
			user_error('You are attempting to make a payment without the necessary credentials set', E_USER_ERROR);
		}
		$data = $this->Order()->BillingAddress()->toMap();
		$paymenturl = $this->getTokenURL($this->Amount->Amount,$this->Amount->Currency,$data);
		$this->Status = "Pending";
		$this->write();
		if($paymenturl){
			Controller::curr()->redirect($paymenturl); //redirect to payment gateway
			/*
			$page = new Page();

			$page->Title = 'Redirection to PayPal...';
			$page->Logo = '<img src="' . self::$logo . '" alt="Payments powered by PayPal"/>';
			$page->Form = $this->PayPalForm();

			$controller = new Page_Controller($page);

			$form = $controller->renderWith('PaymentProcessingPage');

			return new Payment_Processing($form);
			*/
			return new Payment_Processing();
		}
		$this->Message = _t('PayPalExpressCheckoutPayment.COULDNOTBECONTACTED',"PayPal could not be contacted");
		$this->Status = 'Failure';
		$this->write();
		return new Payment_Failure($this->Message);
	}

	/**
	 *
	 * depracated
	 */
	function PayPalForm() {
		Requirements::javascript(THIRDPARTY_DIR . '/jquery/jquery.js');

		// 1) Main Informations
		$fields = '';
		$order = $this->Order();
		$items = $order->Items();
		$member = $order->Member();

		// 2) Main Settings

		$url = self::$test_mode ? self::$test_url : self::$url;
		$inputs['cmd'] = '_cart';
		$inputs['upload'] = '1';

		// 3) Items Informations

		$cpt = 0;
		foreach($items as $item) {
			$inputs['item_name_' . ++$cpt] = $item->TableTitle();
			// item_number is unnecessary
			$inputs['amount_' . $cpt] = $item->UnitPrice();
			$inputs['quantity_' . $cpt] = $item->Quantity;
		}

		// 4) Payment Informations And Authorisation Code

		$inputs['business'] = self::$test_mode ? self::$test_account_email : self::$account_email;
		$inputs['custom'] = $this->ID . '-' . $this->AuthorisationCode;
		// Add Here The Shipping And/Or Taxes
		$inputs['currency_code'] = $this->Currency;

		// 5) Redirection Informations

		$inputs['cancel_return'] = Director::absoluteBaseURL() . PayPalExpressCheckoutPayment_Handler::cancel_link($inputs['custom']);
		$inputs['return'] = Director::absoluteBaseURL() . PayPalExpressCheckoutPayment_Handler::complete_link();
		$inputs['rm'] = '2';
		// Add Here The Notify URL

		// 6) PayPal Pages Style Optional Informations

		if(self:: $continue_button_text) $inputs['cbt'] = self::$continue_button_text;

		if(self::$header_image_url) $inputs['cpp_header_image'] = urlencode(self::$header_image_url);
		if(self::$header_back_color) $inputs['cpp_headerback_color'] = self::$header_back_color;
		if(self::$header_border_color) $inputs['cpp_headerborder_color'] = self::$header_border_color;
		if(self::$payflow_color) $inputs['cpp_payflow_color'] = self::$payflow_color;
		if(self::$back_color) $inputs['cs'] = self::$back_color;
		if(self::$image_url) $inputs['image_url'] = urlencode(self::$image_url);
		if(self::$page_style) $inputs['page_style'] = self::$page_style;

		// 7) Prepopulating Customer Informations
		$billingAddress = $order->BillingAddress();
		$inputs['first_name'] = $billingAddress->FirstName;
		$inputs['last_name'] = $billingAddress->Surname;
		$inputs['address1'] = $billingAddress->Address;
		$inputs['address2'] = $billingAddress->Address2;
		$inputs['city'] = $billingAddress->City;
		$inputs['zip'] = $billingAddress->PostalCode;
		$inputs['state'] = $billingAddress->Region()->Code;
		$inputs['country'] = $billingAddress->Country;
		$inputs['email'] = $member->Email;

		// 8) Form Creation
		if(is_array($inputs) && count($inputs)) {
			foreach($inputs as $name => $value) {
				$ATT_value = Convert::raw2att($value);
				$fields .= "<input type=\"hidden\" name=\"$name\" value=\"$ATT_value\" />";
			}
		}

		return <<<HTML
			<form id="PaymentForm" method="post" action="$url">
				$fields
				<input type="submit" value="Submit" />
			</form>
			<script type="text/javascript">
				jQuery(document).ready(function() {
					jQuery("input[type='submit']").hide();
					jQuery('#PaymentForm').submit();
				});
			</script>
HTML;
	}

	function populateDefaults() {
		parent::populateDefaults();
		$this->AuthorisationCode = md5(uniqid(rand(), true));
 	}




	/**
	 * Requests a Token url, based on the provided Name-Value-Pair fields
	 * See docs for more detail on these fields:
	 * https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_SetExpressCheckout
	 *
	 * Note: some of these values will override the paypal merchant account settings.
	 * Note: not all fields are listed here.
	 */
	protected function getTokenURL($paymentAmount, $currencyCodeType, $extradata = array()){

		$data = array(
			//payment info
			'PAYMENTREQUEST_0_AMT' => $paymentAmount,
			'PAYMENTREQUEST_0_CURRENCYCODE' => $currencyCodeType, //TODO: check to be sure all currency codes match the SS ones
			//TODO: include individual costs: shipping, shipping discount, insurance, handling, tax??
			//'PAYMENTREQUEST_0_ITEMAMT' => //item(s)
			//'PAYMENTREQUEST_0_SHIPPINGAMT' //shipping
			//'PAYMENTREQUEST_0_SHIPDISCAMT' //shipping discount
			//'PAYMENTREQUEST_0_HANDLINGAMT' //handling
			//'PAYMENTREQUEST_0_TAXAMT' //tax
			//'PAYMENTREQUEST_0_INVNUM' => $this->PaidObjectID //invoice number
			//'PAYMENTREQUEST_0_TRANSACTIONID' //Transaction id
			//'PAYMENTREQUEST_0_DESC' => //description
			//'PAYMENTREQUEST_0_NOTETEXT' => //note to merchant
			//'PAYMENTREQUEST_0_PAYMENTACTION' => , //Sale, Order, or Authorization
			//'PAYMENTREQUEST_0_PAYMENTREQUESTID'
			//return urls
			'RETURNURL' => PayPalExpressCheckoutPayment_Handler::return_link(),
			'CANCELURL' => PayPalExpressCheckoutPayment_Handler::cancel_link(),
			//'PAYMENTREQUEST_0_NOTIFYURL' => //Instant payment notification
			//'CALLBACK'
			//'CALLBACKTIMEOUT'
			//shipping display
			//'REQCONFIRMSHIPPING' //require that paypal account address be confirmed
			'NOSHIPPING' => 1, //show shipping fields, or not 0 = show shipping, 1 = don't show shipping, 2 = use account address, if none passed
			//'ALLOWOVERRIDE' //display only the provided address, not the one stored in paypal
			//TODO: Probably overkill, but you can even include the prices,qty,weight,tax etc for individual sale items
			//other settings
			//'LOCALECODE' => //locale, or default to US
			'LANDINGPAGE' => 'Billing' //can be 'Billing' or 'Login'
		);

		if(!isset($extradata['Name'])){
			$arr =  array();
			if(isset($extradata['FirstName'])) $arr[] = $extradata['FirstName'];
			if(isset($extradata['MiddleName'])) $arr[] = $extradata['MiddleName'];
			if(isset($extradata['Surname'])) $arr[] = $extradata['Surname'];
			$extradata['Name'] = implode(' ',$arr);
		}
		//add member & shipping fields, etc ...this will pre-populate the paypal login / create account form
		foreach(array(
			'Email' => 'EMAIL',
			'Name' => 'PAYMENTREQUEST_0_SHIPTONAME',
			'Address' => 'PAYMENTREQUEST_0_SHIPTOSTREET',
			'Address2' => 'PAYMENTREQUEST_0_SHIPTOSTREET2',
			'City' => 'PAYMENTREQUEST_0_SHIPTOCITY',
			'PostalCode' => 'PAYMENTREQUEST_0_SHIPTOZIP',
			'Region' => 'PAYMENTREQUEST_0_SHIPTOPHONENUM',
			'Phone' => 'PAYMENTREQUEST_0_SHIPTOPHONENUM',
			'Country' => 'PAYMENTREQUEST_0_SHIPTOCOUNTRYCODE',
			'OrderID' => 'PAYMENTREQUEST_0_DESC'
		) as $field => $val){
			if(isset($extradata[$field])){
				$data[$val] = $extradata[$field];
			}
			elseif($this->$field){
				$data[$val] = $this->$field;
			}
		}
		//set design settings
		$data = array_merge(self::$custom_settings,$data);
		$response = $this->apiCall('SetExpressCheckout',$data);
		if(!isset($response['ACK']) ||  !(strtoupper($response['ACK']) == "SUCCESS" || strtoupper($response['ACK']) == "SUCCESSWITHWARNING")){
			$mode = (self::$test_mode === true) ? "test" : "live";
			$debugmessage = "PayPal Debug:" .
					"\nMode: $mode".
					"\nAPI url: ".$this->getApiEndpoint().
					"\nRedirect url: ".$this->getPayPalURL("TOKENGOESHERE").
					"\nUsername: " .self::$API_UserName.
					"\nPassword: " .self::$API_Password.
					"\nSignature: ".self::$API_Signature.
					"\nRequest Data: ".print_r($data,true).
					"\nResponse: ".print_r($response,true);
			if(Director::isDev()){
				Debug::log($debugmessage);
			}
			return null;
		}
		//get and save token for later
		$token = $response['TOKEN'];
		$this->Token = $token;
		$this->write();
		return $this->getPayPalURL($token);
	}

	/**
	 * see https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_DoExpressCheckoutPayment
	 */
	function confirmPayment(){
		$data = array(
			'PAYERID' => $this->PayerID,
			'TOKEN' => $this->Token,
			'PAYMENTREQUEST_0_PAYMENTACTION' => "Sale",
			'PAYMENTREQUEST_0_AMT' => $this->Amount->Amount,
			'PAYMENTREQUEST_0_CURRENCYCODE' => $this->Amount->Currency,
			'IPADDRESS' => urlencode($_SERVER['SERVER_NAME'])
		);
		$response = $this->apiCall('DoExpressCheckoutPayment',$data);
		if(!isset($response['ACK']) ||  !(strtoupper($response['ACK']) == "SUCCESS" || strtoupper($response['ACK']) == "SUCCESSWITHWARNING")){
			return null;
		}
		if(isset($response["PAYMENTINFO_0_TRANSACTIONID"])){
			$this->TransactionID	= $response["PAYMENTINFO_0_TRANSACTIONID"]; 	//' Unique transaction ID of the payment. Note:  If the PaymentAction of the request was Authorization or Order, this value is your AuthorizationID for use with the Authorization & Capture APIs.
		}
		//$transactionType 		= $response["PAYMENTINFO_0_TRANSACTIONTYPE"]; //' The type of transaction Possible values: l  cart l  express-checkout
		//$paymentType			= $response["PAYMENTTYPE"];  	//' Indicates whether the payment is instant or delayed. Possible values: l  none l  echeck l  instant
		//$orderTime 				= $response["ORDERTIME"];  		//' Time/date stamp of payment
		//TODO: should these be updated like this?
		//$this->Amount->Amount	= $response["AMT"];  			//' The final amount charged, including any shipping and taxes from your Merchant Profile.
		//$this->Amount->Currency= $response["CURRENCYCODE"];  	//' A three-character currency code for one of the currencies listed in PayPay-Supported Transactional Currencies. Default: USD.
		//TODO: store this extra info locally?
		//$feeAmt					= $response["FEEAMT"];  		//' PayPal fee amount charged for the transaction
		//$settleAmt				= $response["SETTLEAMT"];  		//' Amount deposited in your PayPal account after a currency conversion.
		//$taxAmt					= $response["TAXAMT"];  		//' Tax charged on the transaction.
		//$exchangeRate			= $response["EXCHANGERATE"];  	//' Exchange rate if a currency conversion occurred. Relevant only if your are billing in their non-primary currency. If the customer chooses to pay with a currency other than the non-primary currency, the conversion occurs in the customer's account.
		if(isset($response["PAYMENTINFO_0_PAYMENTSTATUS"])){
			switch(strtoupper($response["PAYMENTINFO_0_PAYMENTSTATUS"])){
				case "PROCESSED":
				case "COMPLETED":
					$this->Status = 'Success';
					$this->Message = _t('PayPalExpressCheckoutPayment.SUCCESS',"The payment has been completed, and the funds have been successfully transferred");
					break;
				case "EXPIRED":
					$this->Message = _t('PayPalExpressCheckoutPayment.AUTHORISATION',"The authorization period for this payment has been reached");
					$this->Status = 'Failure';
					break;
				case "DENIED":
					$this->Message = _t('PayPalExpressCheckoutPayment.FAILURE',"Payment was denied");
					$this->Status = 'Failure';
					break;
				case "REVERSED":
					$this->Status = 'Failure';
					break;
				case "VOIDED":
					$this->Message = _t('PayPalExpressCheckoutPayment.VOIDED',"An authorization for this transaction has been voided.");
					$this->Status = 'Failure';
					break;
				case "FAILED":
					$this->Status = 'Failure';
					break;
				case "CANCEL-REVERSAL": // A reversal has been canceled; for example, when you win a dispute and the funds for the reversal have been returned to you.
					break;
				case "IN-PROGRESS":
					$this->Message = _t('PayPalExpressCheckoutPayment.INPROGRESS',"The transaction has not terminated");//, e.g. an authorization may be awaiting completion.";
					break;
				case "PARTIALLY-REFUNDED":
					$this->Message = _t('PayPalExpressCheckoutPayment.PARTIALLYREFUNDED',"The payment has been partially refunded.");
					break;
				case "PENDING":
					$this->Message = _t('PayPalExpressCheckoutPayment.PENDING',"The payment is pending.");
					if(isset($response["PAYMENTINFO_0_PENDINGREASON"])){
						$this->Message .= " ".$this->getPendingReason($response["PAYMENTINFO_0_PENDINGREASON"]);
					}
					break;
				case "REFUNDED":
					$this->Message = _t('PayPalExpressCheckoutPayment.REFUNDED',"Payment refunded.");
					break;
				default:
			}
		}
		//$reasonCode		= $response["REASONCODE"];
		$this->write();
	}

	protected function getPendingReason($reason){
		switch($reason){
			case "address":
				return _t('PayPalExpressCheckoutPayment.PENDING.ADDRESS',"A confirmed shipping address was not provided.");
			case "authorization":
				return _t('PayPalExpressCheckoutPayment.PENDING.AUTHORISATION',"Payment has been authorised, but not settled.");
			case "echeck":
				return _t('PayPalExpressCheckoutPayment.PENDING.ECHECK',"eCheck has not cleared.");
			case "intl":
				return _t('PayPalExpressCheckoutPayment.PENDING.INTERNATIONAL',"International: payment must be accepted or denied manually.");
			case "multicurrency":
				return _t('PayPalExpressCheckoutPayment.PENDING.MULTICURRENCY',"Multi-currency: payment must be accepted or denied manually.");
			case "order":
			case "paymentreview":
			case "unilateral":
			case "verify":
			case "other":
		}
	}

	/**
	 * Handles actual communication with API server.
	 */
	protected function apiCall($method,$data = array()){
		$postfields = array(
			'METHOD' => $method,
			'VERSION' => self::$version,
			'USER' => self::$API_UserName,
			'PWD'=> self::$API_Password,
			'SIGNATURE' => self::$API_Signature,
			'BUTTONSOURCE' => self::$sBNCode
		);
		$postfields = array_merge($postfields,$data);
		//Make POST request to Paypal via RESTful service
		$rs = new RestfulService($this->getApiEndpoint(),0); //REST connection that will expire immediately
		$rs->httpHeader('Accept: application/xml');
		$rs->httpHeader('Content-Type: application/x-www-form-urlencoded');
		$response = $rs->request('','POST',http_build_query($postfields));
		return $this->deformatNVP($response->getBody());
	}

	protected function deformatNVP($nvpstr){
		$intial = 0;
	 	$nvpArray = array();
		while(strlen($nvpstr)){
			//postion of Key
			$keypos= strpos($nvpstr,'=');
			//position of value
			$valuepos = strpos($nvpstr,'&') ? strpos($nvpstr,'&'): strlen($nvpstr);
			/*getting the Key and Value values and storing in a Associative Array*/
			$keyval=substr($nvpstr,$intial,$keypos);
			$valval=substr($nvpstr,$keypos+1,$valuepos-$keypos-1);
			//decoding the respose
			$nvpArray[urldecode($keyval)] =urldecode( $valval);
			$nvpstr=substr($nvpstr,$valuepos+1,strlen($nvpstr));
		}
		return $nvpArray;
	}

	protected function getApiEndpoint(){
		return (self::$test_mode === true) ? self::$test_API_Endpoint : self::$API_Endpoint;
	}

	protected function getPayPalURL($token){
		$url = (self::$test_mode === true) ? self::$test_PAYPAL_URL : self::$PAYPAL_URL;
		return $url.$token.'&useraction=commit'; //useraction=commit ensures the payment is confirmed on PayPal, and not on a merchant confirm page.
	}


}

/**
 * Handler for responses from the PayPal site
 */
class PayPalExpressCheckoutPayment_Handler extends Controller {


	protected $payment = null; //only need to get this once

	static $allowed_actions = array(
		'confirm',
		'cancel'
	);

	public function Link($action = null) {
		$link = $this->Config()->get("url_segment")."/";
		if($action) {
			$link .= "$action/";
		}
		return $link;
	}

	function payment(){
		if($this->payment){
			return $this->payment;
		}
		elseif($token = Controller::getRequest()->getVar('token')){
			$p =  PayPalExpressCheckoutPayment::get()->filter(array("Token" => $token, "Status" => "Pending"))->First();
			$this->payment = $p;
			return $p;
		}
		return null;
	}

	function confirm($request){
		//TODO: pretend the user confirmed, and skip straight to results. (check that this is allowed)
		//TODO: get updated shipping details from paypal??
		if($payment = $this->payment()){
			if($pid = Controller::getRequest()->getVar('PayerID')){
				$payment->PayerID = $pid;
				$payment->write();
				$payment->confirmPayment();
			}
		}else{
			//something went wrong?	..perhaps trying to pay for a payment that has already been processed
		}
		$this->doRedirect();
		return;
	}

	function cancel($request){
		if($payment = $this->payment()){
			//TODO: do API call to gather further information
			$payment->Status = "Failure";
			$payment->Message = _t('PayPalExpressCheckoutPayment.USERCANCELLED',"User cancelled");
			$payment->write();
		}
		$this->doRedirect();
		return;
	}

	protected function doRedirect(){
		$payment = $this->payment();
		if($payment && $obj = $payment->PaidObject()){
			$this->redirect($obj->Link());
			return;
		}
		$this->redirect(Director::absoluteURL('home',true)); //TODO: make this customisable in Payment_Controllers
		return;
	}

	private static $url_segment = 'paypalexpresscheckoutpayment_handler';

	public static function return_link() {
		return Director::absoluteURL(self::$url_segment,true)."/confirm/";
	}

	public static function cancel_link() {
		return Director::absoluteURL(self::$url_segment,true)."/cancel/";
	}

}
