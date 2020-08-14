<?php

class Billing
{
	/*
		helper function to search string in template for invoice_create
		created by -- Ankit Mishra
	*/
	function search_string_between($string, $start, $end) {
		$string = ' ' . $string;
		$ini = strpos($string, $start);
		if ($ini == 0) return '';
		$ini += strlen($start);
		$len = strpos($string, $end, $ini) - $ini;
		return substr($string, $ini, $len);
	}

	/*
		helper function to replace string in template for invoice_create
		created by -- Ankit Mishra
	*/
	function replace_string_between($str, $needle_start, $needle_end, $replacement) {
		$pos = strpos($str, $needle_start);
		$start = $pos === false ? 0 : $pos + strlen($needle_start);
		$pos = strpos($str, $needle_end, $start);
		$end = $start === false ? strlen($str) : $pos;
		return substr_replace($str,$replacement,  $start, $end - $start);
	}

	/*
		billing invoice_create_core function
		helper function for billing invoice_create function
		it will take invoice_id and retunr all the (invoice details with invoice_items data) in json
		created by -- Ankit Mishra
	*/
	public function invoice_create_core($dbConn, $invoice_id, &$invoice_data, &$message = '')
	{
		//take invoice id return false if no invoice id given
		if(!is_numeric($invoice_id)){
			$message = "Please provide a valid invoice id.";
			return __LINE__;
		}

		// declare globle variables.
		$user_details	 = array();
		$invoice_items   = array();

		//fetch basic details from miscsetting
		$dbConn->FetchAllData("select * from tblmiscsettings where setting_name='currency' and module_code='BILLING'",$arMiscCSearch,$iMCRows);
		$currency	= $arMiscCSearch[0]['setting_value'];

		//Get The User id and invoice details from invoice id 
		if(is_numeric($invoice_id)) {
			$dbConn->FetchAllData("select * from invoice where invoice_id=$invoice_id",$arInvoiceSearch,$iURows);
			if ($iURows == 0) 
			{
				$message = "invoice is not available";
				return __LINE__;
			}
		}

		if($iURows>0)
		{
			//#######---search the user of billing via user id and create an billing user details array
			$user_id = $arInvoiceSearch[0]['user_id'];
			$searchBillingUser	= "select * from tblusers where userid = $user_id";
			@$dbConn->ExecuteSelectQuery($searchBillingUser, $rsBillingUser,$iUBrows);
			if($iUBrows > 0)
			{		
				while($arUserDevices=$dbConn->GetData($rsBillingUser))
				{
					$user_details["user_id"]			=	$arUserDevices['userid'];
					$user_details["user_name"]			=	$arUserDevices['username'];
					$user_details["user_company"]		=	$arUserDevices['company_name'];
					$user_details["user_email"]			=	$arUserDevices['email'];
					$user_details["user_address"]		=	$arUserDevices['address'];
					$user_details["user_phone"]			=	$arUserDevices['phone'];
					$user_details["user_bill_type"]		=	$arUserDevices['bill_type'];
					$user_details["user_credit_limit"]	=	$arUserDevices['credit_limit'];
					$user_details["user_credit_period"]	=	$arUserDevices['credit_period'];
				}
			}

			//######--- fetch all the invoice details related to this invoice id
			$invoice_date 		= $arInvoiceSearch[0]['invoice_date'];
			$invoice_code		= $arInvoiceSearch[0]['invoice_code'];			
			$total_amount		= $arInvoiceSearch[0]['total_amount'];
			$invoice_id			= $arInvoiceSearch[0]['invoice_id'];
			$invoice_type		= $arInvoiceSearch[0]['invoice_type'];
			$invoice_status		= $arInvoiceSearch[0]['invoice_status'];
			$previous_balance	= $arInvoiceSearch[0]['previous_balance'];
			$invoice_description= $arInvoiceSearch[0]['invoice_description'];
			$bill_to			= $arInvoiceSearch[0]['user_description'];

			if($invoice_status	== -1)
			{
				$message = "This is a cancelled invoice.";
				return __LINE__;
			}
		
			if(trim($invoice_code) != '')
				$invoice_no=$invoice_code;
			else
				$invoice_no=$invoice_id;
		
			$total_unit_rate = 0;
			$total_invoice_amount_payble = 0;

			//################-- if invoice items are available under this invoice id --###############################
			$dbConn->FetchAllData("select * from invoice_items where invoice_id=$invoice_id", $arInvoiceItems, $iIRows);
			for($iICount=0;$iICount<$iIRows;$iICount++)
			{
				$invoice_item_id			= $arInvoiceItems[$iICount]['invoice_item_id'];
				$invoice_item_description	= $arInvoiceItems[$iICount]['invoice_item_description'];
				$invoice_item_rate			= $arInvoiceItems[$iICount]['invoice_item_rate'];
				$total_rate					= $arInvoiceItems[$iICount]['total_rate'];

				//testing code for this month total and subtotal
				$total_unit_rate += $invoice_item_rate;
				$total_invoice_amount_payble += $total_rate;
				
				//fetch taxes of this item id
				$dbConn->FetchAllData("select * from tblinvoiceitemtaxes where invoice_item_id=$invoice_item_id", $arInvoiceItemTaxes, $iITRows);
				
				$invoice_items[$iICount]["invoice_item_description"] 	= base64_encode($invoice_item_description);
				$invoice_items[$iICount]["invoice_item_rate"]			= $invoice_item_rate;
				$invoice_items[$iICount]["item_taxes"]					= $arInvoiceItemTaxes;
				$invoice_items[$iICount]["item_total_amount"]			= $total_rate;
				$invoice_items[$iICount]["item_total_amount_in_words"]	= ucwords(no_to_words(round($total_rate)));
				$invoice_items[$iICount]["current_date"]				= date('d-m-Y',strtotime($invoice_date));
			}

		} // main invoice details block ends here.

		// main invoice code starts---#######################################
		$invoice_data = array();
		$last_payment			=	0;
		$last_adjustment		=	0;
			
		$payment_search_params['user_id']		= $user_id;
		$payment_search_params['type']			= 2;
		$payment_search_params['end_date']		= $invoice_date;
		$payment_search_params['payment_status']= 0;
		$payment_search_params['payment_source']= 0;
			
		$iResult = $this->statement_generate($dbConn, $payment_search_params, $statement_arrray, $message);

		//print_r($statement_arrray);die();
			
		if($iResult == 0)
			$last_payment = $statement_arrray[count($statement_arrray)-1]['total_amount'];
		
		//previuos bal from invoice details
		$balanace_due =  $previous_balance;
		if($balanace_due > $user_details["user_credit_limit"])
		{
			$due_date	= 'Immediate';
		}
		else
		{	
			$due_date	= date('d-m-y', strtotime("+7 day",strtotime($invoice_date)));
		}
						
		$amount_payable	=	($balanace_due + $total_amount);		
		$amount_in_words	= ucwords(no_to_words(round($amount_payable)));

		//####################---invoice final data creation---##################
		$invoice_data["user_details"]		= $user_details;
		$invoice_data["invoice_date"]		= date('d-m-Y',strtotime($invoice_date));
		$invoice_data["due_date"]			= $due_date;
		$invoice_data["currency"]			= $currency;

		$invoice_data["amount_payable"]		= $amount_payable;
		$invoice_data["amount_in_words"]	= $amount_in_words;

		$invoice_data["previos_balance"]	= $balanace_due;
		$invoice_data["last_payment"]		= $last_payment;

		$invoice_data["this_invoice_total"]	= $total_invoice_amount_payble;
		$invoice_data["total_unit_rate"]	= $total_unit_rate;

		$invoice_data["invoice_number"]		= $invoice_no;
		$invoice_data["invoice_items"]		= $invoice_items;
		$invoice_data["invoice_description"]= $invoice_description;

		$message = "Invoice found.";
		return 0;
	}

	/*
		billing invoice_create function
		main invoice create function for creatinh html invoice
		it will take json data from invoice_create_core and create a HTML printable page of invoices
		created by -- Ankit Mishra
	*/
	public function invoice_create($dbConn, $invoice_id, $invoice_print_params, &$invoice_html, &$err_message = '')
	{
		//take invoice id return false if no invoice id given
		if(!is_numeric($invoice_id)){
			$err_message = "invoice id can't be blank.";
			return __LINE__;
		}
		else
		{
			$iResult = $this->invoice_create_core($dbConn, $invoice_id, $invoice_data, $message);

			if ($iResult == 0)
			{
				//print("<pre>".print_r($invoice_data,true)."</pre>");die();
				//#########--get all invoice root data
				$user_details		= $invoice_data["user_details"];
				$invoice_date 		= $invoice_data["invoice_date"];
				$due_date 			= $invoice_data["due_date"];
				$amount_in_words 	= $invoice_data["amount_in_words"];
				$previos_balance 	= $invoice_data["previos_balance"];
				$last_payment 		= $invoice_data["last_payment"];
				$adjustments 		= $invoice_data["adjustments"];
				$amount_payable 	= $invoice_data["amount_payable"];
				$currency 			= $invoice_data["currency"];
				$this_invoice_total = $invoice_data["this_invoice_total"];
				$total_unit_rate 	= $invoice_data["total_unit_rate"];
				$invoice_number 	= $invoice_data["invoice_number"];
				$invoice_description = $invoice_data["invoice_description"];
				$invoice_items		= $invoice_data["invoice_items"];
				$bill_to			=($user_details["user_name"]."<br>".$user_details["user_address"]."<br>".$user_details["user_email"]."<br>".$user_details["user_phone"]);


				//#############--getting the template from database
				$dbConn->FetchAllData("select setting_name,setting_value from tblmiscsettings where setting_name IN('invoice_template', 'invoice_template_consolidated') and module_code='BILLING' ORDER BY setting_name",$arMiscSearch,$iMRows);
				$invoice_template_main	= base64_decode($arMiscSearch[0]['setting_value']);
				$individual_template	= base64_decode($arMiscSearch[1]['setting_value']);  

				//$year_duration = date("y")."-".date('y', strtotime('+1 year'));
				//$invoice_number = "GEO."/".$year_duration."/".$invoice_number";
				
				
				//##########---template holders for testing
				//$invoice_template_main = file_get_contents($invoice_print_params['static_server_path']."/invoice_main.html");
				//$individual_template = file_get_contents($invoice_print_params['static_server_path']."/invoice_individual.html");

				$individual_template_body = $this->search_string_between($individual_template,'<body>','</body>');
				$items_list_data = '<thead><tr><th>S.NO.</th><th>Description</th><th>Unit Rate</th><th>Taxes</th><th>Date</th><th>Amount</th></tr></thead>';

				//##########----logo and stamp images for invoice 
				$domain_logo_img =	"<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.logo-small.png\" alt=\"Logo\" title=\"Logo\">&nbsp;&nbsp;<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.logo.png\" alt=\"Logo\" title=\"Logo\">";
				$domain_sign_img =	"<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.sign.png\" alt=\"Sign\" title=\"Sign\">";
				$domain_stamp_img =	"<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.stamp.png\" alt=\"Stamp\" title=\"Stamp\">";
		
				//#####---generating individual invoices for each items
				for($iCount=0;$iCount<count($invoice_items);$iCount++)
				{
					$invoice_item_description =base64_decode($invoice_items[$iCount]["invoice_item_description"]);
					$invoice_item_rate		   = $invoice_items[$iCount]["invoice_item_rate"];
					$invoice_item_total_amount = $invoice_items[$iCount]["item_total_amount"];
					$invoice_items_date	 	   = $invoice_items[$iCount]["current_date"]; 
					$items_taxes	  		   = $invoice_items[$iCount]["item_taxes"];
					$item_total_amount_in_words	  = $invoice_items[$iCount]["item_total_amount_in_words"]; 
					
					foreach($items_taxes as $aa){
						$taxes  .= $aa["tax_name"]."@".$aa["tax_value"]." (".$currency." ".(($invoice_item_rate * $aa["tax_value"])/100).")"."</br>"; //text format -> IGST@9.00 (INR 3.96) 
					}
		
					$individual_invoice_array = array('{CURRENT_DATE}','{BILL_TO}', '{TAXES}' , '{ITEM_TOTAL_AMOUNT_IN_WORDS}', '{DESCRIPTION}','{RATE}', '{AMOUNT}','{DUE_DATE}','{INVOICE_NO}','{DOMAIN_LOGO_IMG}', '{CURRENCY}', '{DOMAIN_STAMP_IMG}', '{DOMAIN_SIGN_IMG}');
		
					$individual_replace_array  = array( $invoice_items_date, $bill_to, $taxes, $item_total_amount_in_words, $invoice_item_description, $invoice_item_rate, $invoice_item_total_amount, $due_date, $invoice_number, $domain_logo_img, $currency, $domain_stamp_img,$domain_sign_img);
		
					$replacer = str_replace($individual_invoice_array, $individual_replace_array, $individual_template_body);
					$final_individual_template_body .= $replacer;
					unset($replacer);
					unset($taxes);
				}
		
				//#####---replacing final invoices in template html
				$invoice_items_invoices = $this->replace_string_between($individual_template, '<body>', '</body>', $final_individual_template_body);
		
				//#####---creating consolidated list view of invoice items			
				for($iiCount=0;$iiCount<count($invoice_items);$iiCount++)
				{
					$invoice_item_description =base64_decode($invoice_items[$iiCount]["invoice_item_description"]);
					$invoice_item_rate		   = $invoice_items[$iiCount]["invoice_item_rate"];
					$invoice_item_total_amount = $invoice_items[$iiCount]["item_total_amount"];
					$invoice_items_date	 	   = $invoice_items[$iiCount]["current_date"]; 
					$items_taxes	  		   = $invoice_items[$iiCount]["item_taxes"];
					$serial_no 				   = $iiCount+1;  
		
					foreach($items_taxes as $aa){
						$taxes  .= $aa["tax_name"]."@".$aa["tax_value"]." (".$currency." ".(($invoice_item_rate * $aa["tax_value"])/100).")"."</br>"; //text format -> IGST@9.00 (INR 3.96) 
					}
		
					$items_list_data .= '<tbody><tr><td>{SERIAL}</td><td>{DESCRIPTION}</td><td>{CURRENCY} {RATE}</td><td>{TAXES}</td><td>{CURRENT_DATE}</td><td>{CURRENCY} {THIS_MONTH_TOTAL}</td></tr></tbody';
		
					$search_array1	=	array('{SERIAL}', '{DESCRIPTION}','{RATE}','{TAXES}','{THIS_MONTH_TOTAL}','{CURRENCY}','{CURRENT_DATE}');
					$replace_array1	=   array($serial_no, $invoice_item_description, $invoice_item_rate, $taxes, $invoice_item_total_amount, $currency, $invoice_items_date);			
					$repeat_data   .=	str_replace($search_array1, $replace_array1, $items_list_data);
					unset($items_list_data);
					unset($taxes);
				}
		
				//##########---final replacing of data in main invoice template---#####################
				$search_array = array('{CURRENT_DATE}','{BILL_TO}','{DATE}','{DUE_DATE}','{START_DATE}','{END_DATE}','{REPEAT_DATA}', '{TAXES}' , '{AMOUNT_IN_WORDS}', '{PREVIOUS_BALANCE}','{LAST_PAYMENT}','{THIS_INVOICE_TOTAL}', '{ADJUSTMENTS}','{AMOUNT_PAYABLE}','{DOMAIN_LOGO_IMG}', '{CURRENCY}','{THIS_MONTH_TOTAL}','{THIS_MONTH_SUBTOTAL}', '{DOMAIN_STAMP_IMG}', '{DOMAIN_SIGN_IMG}','{INVOICE_NO}', '{REPEAT_DATA}','{INDIVIDUAL_INVOICES}');
		
				$replace_array  =   array( date('d-m-Y',strtotime($invoice_date)), $bill_to, date('d-m-Y',strtotime($invoice_date)), $due_date, $start_date,$end_date, "<thead>".$repeat_data."</thead><tbody>".$final_data."</tbody>", $taxes_html, $amount_in_words, $previos_balance, $last_payment, $this_invoice_total, $adjustment, $amount_payable, $domain_logo_img, $currency, $this_month_total,$total_unit_rate, $domain_stamp_img,$domain_sign_img,$invoice_number, $repeat_data, $invoice_items_invoices);
		
				$invoice_html	.= str_replace($search_array, $replace_array, $invoice_template_main);
				return 0;

			}
			else
			{
				$err_message = $message;
				return $iResult;
			}
		}
	}
		
	/* 
		invoice print function for cp
		created by -- Ankit Mishra
	*/

	// Add a new invoice
	public function invoice_add($dbConn, $invoice_add_params, $invoice_items_param, &$invoice_result_arrray, &$message = '', $invoice_item_taxes_param=array())
	{		
		if(!is_numeric($invoice_add_params['user_id'])){
			$message = "Please provide a valid user id.";
			return __LINE__;
		} else{
			// Fetch user name and address
			$dbConn->FetchAllData("select company_name,address,email,phone from tblusers where userid={$invoice_add_params['user_id']} and billing_contact=1",$arUser,$iUserRows);
			if($iUserRows==0){
				$message = "User id does not exist or is not enabled for billing.";
				return __LINE__;				
			}
			$user_description = "'{$arUser[0]['company_name']}<br>{$arUser[0]['address']}<br>{$arUser[0]['email']}<br>{$arUser[0]['phone']}'";
		}
		
		if($invoice_add_params['invoice_currency']=='')
			$invoice_currency="'INR'";
		else
			$invoice_currency="'{$invoice_add_params['invoice_currency']}'";
			
		if(!is_numeric($invoice_add_params['invoice_type']))
			$invoice_type=0;		// Defaults to recurring(0). Set to 1 for sale type
		else
			$invoice_type=$invoice_add_params['invoice_type'];

		if(!is_numeric($invoice_add_params['invoice_source']))
			$invoice_source=0;		// 0 - General, 1 - Cancelled Payment
		else
			$invoice_source=$invoice_add_params['invoice_source'];

		if($invoice_add_params['invoice_description']=='')
			$invoice_description="'Recurring Invoice'";
		else
			$invoice_description="'{$invoice_add_params['invoice_description']}'";

		if($invoice_add_params['po_number']=='')
			$po_number="NULL";
		else
			$po_number="'{$invoice_add_params['po_number']}'";

		$previous_balance = 0;		
		$total_amount = 0;			// TODO: Calculate total amount
		
		$dbConn->ExecuteQuery('BEGIN',$iRows);

		// Calculating previous balance start
		$balance_params['user_id'] = $invoice_add_params['user_id'];
		$this->balance_fetch($dbConn, $balance_params, $balance_arrray, $message);
		$previous_balance = $balance_arrray['user_balance'];
		// Calculating previous balance end
		
		// Invoice code start
		$invoice_code = 1;
		$dbConn->FetchAllData("LOCK TABLE invoice_series IN ACCESS EXCLUSIVE MODE;Select last_value from invoice_series",$arInvoiceCode,$iICRows);
		if($iICRows>0){
			$invoice_code = $arInvoiceCode[0]['last_value'] + 1;
		}
		$dbConn->FetchAllData("Update invoice_series set last_value=$invoice_code",$arICUpdate, $iICURows);
		// Invoice code end

		$tally_status = 0;
		
		//added tally status column
		$dbConn->FetchAllData("Insert into invoice(user_id,invoice_currency, previous_balance,  invoice_description,user_description, invoice_code, invoice_type, po_number,invoice_source, tally_status) values ({$invoice_add_params['user_id']},$invoice_currency, $previous_balance, $invoice_description,$user_description, $invoice_code, $invoice_type, $po_number, $invoice_source, $tally_status) RETURNING invoice_id",$arInvoice,$iIRows);
		if($iIRows>0){
			$invoice_id = $arInvoice[0]['invoice_id'];
		}else{
			$dbConn->ExecuteQuery('ROLLBACK',$iRows);
		}
		
		// Add invoice items code start
		if(count($invoice_items_param)==0){
			$message = "Please add at least one item against invoice.";
			$dbConn->ExecuteQuery('ROLLBACK',$iRows);
			return __LINE__;
		}
		// Fetch taxes
		if($invoice_type==0)
			$dbConn->FetchAllData("Select a.*,b.tax_name,b.tax_value from tblusertaxes as a,tbltaxes as b where a.tax_id=b.tax_id and a.user_id={$invoice_add_params['user_id']}",$arUserTaxes,$iUTRows);

		// Loop through all the items
		for($invoice_item_counter=0; $invoice_item_counter < count($invoice_items_param);$invoice_item_counter++){
			if(!is_numeric($invoice_items_param[$invoice_item_counter]['quantity'])) 
				$quantity=1;
			else
				$quantity=$invoice_items_param[$invoice_item_counter]['quantity'];

			if(!is_numeric($invoice_items_param[$invoice_item_counter]['unit_rate']) || $invoice_items_param[$invoice_item_counter]['unit_rate'] <= 0){
				$message = "Please provide a valid unit rate.";
				$dbConn->ExecuteQuery('ROLLBACK',$iRows);
				return __LINE__;
			}

			if(trim($invoice_items_param[$invoice_item_counter]['invoice_item_description']) == ''){
				$message = "Please provide item description.";
				$dbConn->ExecuteQuery('ROLLBACK',$iRows);
				return __LINE__;
			}

			$total_amount_items = $invoice_items_param[$invoice_item_counter]['unit_rate'] * $quantity;
			if($invoice_type==0){
				//Recurring Invoice
				if($iUTRows > 0){
					// Loop through taxes
					for($iCount=0;$iCount<$iUTRows;$iCount++){
						$total_amount_items	+=	(($invoice_items_param[$invoice_item_counter]['unit_rate'] * $arUserTaxes[$iCount]['tax_value'])/100);
					}
				}				
			}else{
				for($iCount=0;$iCount<count($invoice_item_taxes_param);$iCount++){
					$total_amount_items	+=	(($invoice_items_param[$invoice_item_counter]['unit_rate'] * $invoice_item_taxes_param[$iCount]['tax_value'])/100);
				}
			}

			$total_amount += $total_amount_items;

			$dbConn->FetchAllData("insert into invoice_items (invoice_item_description, invoice_item_rate, invoice_id, total_rate) values ('{$invoice_items_param[$invoice_item_counter]['invoice_item_description']}',{$invoice_items_param[$invoice_item_counter]['unit_rate']}, $invoice_id, $total_amount_items) returning invoice_item_id",$arInvoiceItems,$iIIRows);
			if($iIIRows > 0){
				$invoice_item_id = $arInvoiceItems[0]['invoice_item_id'];

				if($invoice_type == 1)
				{
					// code run from billing add invoice tab (Sale invoice)
					for($iCount=0;$iCount<count($invoice_item_taxes_param);$iCount++)
					{
						$dbConn->ExecuteQuery("insert into tblinvoiceitemtaxes(invoice_item_id,tax_name,tax_value) values($invoice_item_id,'{$invoice_item_taxes_param[$iCount]['tax_name']}',{$invoice_item_taxes_param[$iCount]['tax_value']})",$iUTRows);
					}
				}
				else
				{
					//entry for invoice taxes related to item invoice from user table  - Recurring table
					$dbConn->ExecuteQuery("insert into tblinvoiceitemtaxes (tax_name, tax_value, invoice_item_id) select a.tax_name, a.tax_value,$invoice_item_id from tbltaxes as a,tblusertaxes as b where a.tax_id=b.tax_id and b.user_id={$invoice_add_params['user_id']}",$iUTRows);
				}
			}
		}
		// Add invoice items code end
		// Update invoice with total amount
		$dbConn->ExecuteQuery("Update invoice set total_amount=$total_amount where invoice_id=$invoice_id",$iUIMRows);
		$dbConn->ExecuteQuery('COMMIT',$iRows);

		if($iUIMRows > 0){
			$invoice_result_arrray['invoice_id'] = $invoice_id;
			$message = "Invoice Added.";
			return 0;
		}
	}

	#This function is used to search invoise 
	final public function invoice_search($invoice_search_params, &$invoice_result_array, &$message='')
	{		
		try
		{		
			//invoice_id	invoice_code	parent_invoice_id	po_number	unit_rate	registration_no	credit	start_date	end_date	status	user_id
			global $dbConn;		#global database object
			$invoice_id			= @$invoice_search_params[0]['invoice_id'];
			$invoice_code		= @$invoice_search_params[0]['invoice_code'];
			$po_number			= @$invoice_search_params[0]['po_number'];
			$unit_rate			= @$invoice_search_params[0]['unit_rate'];
			$registration_no	= @$invoice_search_params[0]['registration_no'];
			$start_date			= @$invoice_search_params[0]['start_date'];			
			$end_date			= @$invoice_search_params[0]['end_date'];
			$status				= @$invoice_search_params[0]['status'];
			$user_id			= @$invoice_search_params[0]['user_id'];
			$payment_mode		= @$invoice_search_params[0]['payment_mode'];
			$payment_date		= @$invoice_search_params[0]['payment_date'];						
			$invoice_type		= @$invoice_search_params[0]['invoice_type'];
	
				$invoice_search_sql	= "select a.*, b.userid	as buserid from invoice as a left join tblusers as b on b.userid=a.user_id where  1=1 ";
				
				if( is_numeric($invoice_id))				
					$invoice_search_sql .= " AND a.invoice_id = $invoice_id ";
				
				if(is_numeric($user_id))
					$invoice_search_sql .= " AND a.user_id = $user_id";
				
				if(is_numeric($invoice_code))
					$invoice_search_sql .= " AND (a.invoice_code = $invoice_code OR a.invoice_id=$invoice_code) ";
	
				if(trim($po_number != ''))
					$invoice_search_sql .= " AND a.po_number ilike '%$po_number%'";
	
				if(is_numeric($unit_rate))
					$invoice_search_sql .= " AND a.unit_rate = $unit_rate ";
	
				if(is_numeric($invoice_type))
					$invoice_search_sql .= " AND a.invoice_type	= $invoice_type ";
	
				if(trim($start_date != '') && trim($end_date != ''))				
					$invoice_search_sql .= " AND (a.invoice_date between '$start_date' AND '$end_date')";
	
				if(is_numeric($status))
					$invoice_search_sql .= " AND a.invoice_status = $status";
				
				$dbConn->FetchAllData($invoice_search_sql,$rsInvoice,$invoice_rows);
				if($invoice_rows > 0)
				{				
					$invoice_result_array = $rsInvoice;
					return 0;
				}
				else
				{
					//no records, return an error message
					$message = "No records found";
					return 1;
				}
		}
		catch (Exception $e)
		{
			# HANDle error
			$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
			return 9999;
		}		
	}
	
	#This function is used to search invoise 
	final public function payment_search($payment_search_params, &$payment_result_array, &$message='')
	{		
			try
			{			
				global $dbConn;				#global database object
				$payment_id				= @$payment_search_params[0]['payment_id'];
				$payment_amount			= @$payment_search_params[0]['payment_amount'];
				$payment_mode			= @$payment_search_params[0]['payment_mode'];
				$payment_note			= @$payment_search_params[0]['payment_note'];
				$deduction_amount		= @$payment_search_params[0]['deduction_amount'];
				$deduction_type			= @$payment_search_params[0]['deduction_type'];
				$deduction_note			= @$payment_search_params[0]['deduction_note'];
				$payment_status			= @$payment_search_params[0]['payment_status'];
				$st_user_payment		= @$payment_search_params[0]['st_user_payment'];
				$payfrom_date			= @$payment_search_params[0]['payfrom_date'];
				$payto_date				= @$payment_search_params[0]['payto_date'];
				$all_data				= @$payment_search_params[0]['all_data'];
				
				$payment_search_sql	= "select a.*, b.username, b.company_name from tblpayments as a, tblusers as b where a.user_id=b.userid ";
				
				if( is_numeric($payment_id))				
					$payment_search_sql .= " AND payment_id= $payment_id ";
				
				if(is_numeric($payment_amount))
					$payment_search_sql .= " AND payment_amount = $payment_amount";
				
				if(trim($payment_mode != ''))
					$payment_search_sql .= " AND payment_mode =$payment_mode";
				
				if(trim($payment_note != ''))
					$payment_search_sql .= " AND payment_note  ilike '%$payment_note%'";
				
				if($all_data != 1)
				{	
				if( is_numeric($payment_status))
					$payment_search_sql .= " AND payment_status=$payment_status";
				}
	
				if( is_numeric($st_user_payment))
					$payment_search_sql .= " AND user_id=$st_user_payment";
	
				if(trim($payfrom_date != '') && trim($payto_date != ''))				
					$payment_search_sql .= " AND a.payment_date	between '$payfrom_date' AND '$payto_date' ";
	
				//echo $payment_search_sql;
							
				$dbConn->FetchAllData($payment_search_sql,$arSettings,$setting_rows);
				if($setting_rows > 0)
				{
					$payment_result_array	=	$arSettings;
					return 0;
				}
				else
				{
					//no records, return an error message
					$message = "No records found";
					return 1;
				}
			}
			catch (Exception $e)
			{
				# HANDle error
				$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
				return 9999;
			}
			
		}

			// update invoice table
	final public function invoice_update($invoice_update_params, &$invoice_result_array, &$message='')
	{		
		try
		{		
			global $dbConn;				#global database object

			$invoice_id		= @$invoice_update_params[0]['invoice_id'];
			$invoice_code	= @$invoice_update_params[0]['invoice_code'];
			$payment_date	= @$invoice_update_params[0]['payment_date'];
			$payment_mode	= @$invoice_update_params[0]['payment_mode'];
			$invoice_status	= @$invoice_update_params[0]['invoice_status'];
			$login_user_id	= @$invoice_update_params[0]['login_user_id'];

			if(!is_numeric($invoice_id))
			{
				$message = "Please provide a valid invoice id.";
				return 1;
			}			
			@$dbConn->ExecuteQuery("BEGIN;",$iRows);
			$invoice_update_sql = "UPDATE invoice SET invoice_id=$invoice_id";		

			if(is_numeric($invoice_code))
				$invoice_update_sql .= ", invoice_code = $invoice_code";
			
			if(trim($invoice_status)!= "")
			{
				$invoice_update_sql .= ", invoice_status = $invoice_status";

				if($invoice_status == -1)
				{
					$invoice_search_sql = "SELECT * FROM invoice WHERE invoice_id = $invoice_id";
					@$dbConn->FetchAllData($invoice_search_sql,$arInvoice,$iRows);	
					$invoice_date	= $arInvoice[0]['invoice_date'];
					$user_id		= $arInvoice[0]['user_id'];
					$total_amount	= $arInvoice[0]['total_amount'];					

					//If invoice is cancelled then insert a payment of that amount in current date
					$payment_add_sql = "insert into tblpayments(payment_amount, payment_note, payment_mode, user_id,  total_amount) values($total_amount, 'Credit Note Against Cancelled Invoice ID: {$invoice_id}\n Dated: {$invoice_date}', 4, $user_id,$total_amount)";
					@$dbConn->ExecuteQuery($payment_add_sql,$iRows);
					
					@$dbConn->ExecuteQuery("update invoice_items set status = -1 where invoice_id = $invoice_id",$iRows);
				}
			}
			
			$invoice_update_sql .= " WHERE invoice_id = $invoice_id";
			
			@$dbConn->ExecuteQuery($invoice_update_sql,$iRows);				
			if($iRows > 0)
			{
				//we now need the user id
				$message="Invoice updated.";
				$dbConn->ExecuteQuery("COMMIT;",$iRows);
				return 0;				
			}
			else
			{
				//get the last error code
				$error_string = pg_last_error();
				$dbConn->ExecuteQuery("ROLLBACK",$iRows);
				if($error_string===false)
				{
					//do nothing
				}
				else
				{
					if(!strpos($error_string, "invoice_pkey")===false)
					{
						$message = "Invalid invoice id specified";
						return 6;
					}
					else
					{
						$message = "An unkonwn error has occured. Please try after some time.";
						return 7;
					}					
				}
			}
		}
		catch (Exception $e)
		{
			# HANDle error
			$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
			return 9999;
		}		
	}
	
	// Search invoiceitems
	public function invoiceitems_search($dbConn, $invoice_id, &$invoiceitems_result_array, &$message = '')
	{
		if(!is_numeric($invoice_id)){
			$message = "Please provide a valid invoice id.";
			return __LINE__;
		}

		$dbConn->FetchAllData("SELECT a.*,b.invoice_code FROM invoice_items as a,invoice as b WHERE a.invoice_id=b.invoice_id and a.invoice_id = $invoice_id",$arInvoiceItems,$iRows);

		if($iRows == 0){
			$message = "Please provide a valid invoice id.";
			return __LINE__;
		}else{
			$invoiceitems_result_array = $arInvoiceItems;
			return 0;
		}		
	}

	// Cancel a invoice and generate a credit note against it
	public function invoice_cancel($dbConn, $invoice_id, $login_user_id, &$message = '')
	{
		if(!is_numeric($invoice_id)){
			$message = "Please provide a valid invoice id.";
			return __LINE__;
		}

		if(!is_numeric($login_user_id)){
			$message = "Please provide a valid login id.";
			return __LINE__;
		}

		$dbConn->FetchAllData("SELECT * FROM invoice WHERE invoice_id = $invoice_id and invoice_status=0 and invoice_source=0",$arInvoice,$iRows);

		if($iRows == 0){
			$message = "Please provide a valid invoice id.";
			return __LINE__;
		}

		$invoice_date	= explode(' ',$arInvoice[0]['invoice_date']);
		$user_id		= $arInvoice[0]['user_id'];
		$total_amount	= $arInvoice[0]['total_amount'];

		//If invoice is cancelled then insert a payment of that amount in current date
		$dbConn->ExecuteQuery("BEGIN;",$iRows);
		//TODO: insert payment through payment_add function
		$payment_add_params['payment_amount']	= $total_amount;
		$payment_add_params['payment_note']		= "Credit Note Against Cancelled Invoice #: {$invoice_id}\n Dated: {$invoice_date[0]}";
		$payment_add_params['payment_mode']		= -1;
		$payment_add_params['payment_source']	= 1;		
		$payment_add_params['login_user_id']	= $login_user_id;
		$payment_add_params['user_id'] = $user_id;
		$iResult = $this->payment_add($dbConn, $payment_add_params, $payment_result_arrray, $message);
		if($iResult != 0){
			$message = "Credit note not added against cancelled invoice";
			return __LINE__;
		}
		
		$dbConn->ExecuteQuery("update invoice_items set status = -1 where invoice_id = $invoice_id;update invoice set invoice_status = -1 where invoice_id = $invoice_id;",$iUIRows);
		//Insert previous rows values with login user id
		$dbConn->FetchAllData("select row_to_json(t) as row_to_json from (select * from invoice where invoice_id = $invoice_id) t", $arLogArray, $iILRows);
		$json_data = $arLogArray[0]['row_to_json'];
		$dbConn->ExecuteQuery("insert into tbllogdata(log_user_id,log_data,log_table) values ($login_user_id,'{$json_data}','invoice')",$iLGRows);
		
		$dbConn->ExecuteQuery("COMMIT;",$iRows);

		if($iUIRows > 0 && $iResult == 0){
			$message = "Invoice Cancelled.";
			return 0;
		}
		else{
			$message = "Invoice Not Cancelled.";
			return __LINE__;
		}
	}
	
	// Add a new payment
	public function payment_add($dbConn, $payment_add_params, &$payment_result_arrray, &$message = '')
	{
		if(!is_numeric($payment_add_params['user_id'])){
			$message = "Please provide a valid user id.";
			return __LINE__;
		} else{
			// Fetch user name and address
			$dbConn->FetchAllData("select company_name,address,email,phone,credit_limit from tblusers where userid={$payment_add_params['user_id']} and billing_contact=1",$arUser,$iUserRows);
			if($iUserRows==0){
				$message = "User id does not exist or is not enabled for billing.";
				return __LINE__;				
			}
			$user_description = "'{$arUser[0]['company_name']}<br>{$arUser[0]['address']}<br>{$arUser[0]['email']}<br>{$arUser[0]['phone']}'";
		}

		if(!is_numeric($payment_add_params['login_user_id']))
			$payment_add_params['login_user_id'] = "NULL";

		if(!is_numeric($payment_add_params['payment_amount']) || $payment_add_params['payment_amount'] <= 1){
			$message = "Please provide a valid payment amount.";
			return __LINE__;
		}

		if(trim($payment_add_params['payment_note'])==''){
			$message = "Please provide a payment note.";
			return __LINE__;
		}else{
			$payment_add_params['payment_note'] = "'" . $payment_add_params['payment_note'] . "'";
		}

		//TODO: remove deduction section later
		if(!is_numeric($payment_add_params['deduction_amount']))
			$deduction_amount=0;
		else
			$deduction_amount=$payment_add_params['deduction_amount'];

		if(!is_numeric($payment_add_params['payment_source']))
			$payment_source=0;
		else
			$payment_source=$payment_add_params['payment_source'];

		if(trim($payment_add_params['deduction_note'])=='')
			$deduction_note="NULL";
		else
			$deduction_note="'{$payment_add_params['deduction_note']}'";

		if(!is_numeric($payment_add_params['deduction_type']))
			$deduction_type=0;
		else
			$deduction_type=$payment_add_params['deduction_type'];
		//TODO: remove deduction section later

		if(!is_numeric($payment_add_params['zaakpay_orderid']))
			$zaakpay_orderid = 0;
		else
			$zaakpay_orderid = $payment_add_params['zaakpay_orderid'];
		
		if(!is_numeric($payment_add_params['payment_status']))
			$payment_status = 0;
		else
			$payment_status = $payment_add_params['payment_status'];

		if(!is_numeric($payment_add_params['payment_mode']))
			$payment_mode = 6;
		else
			$payment_mode = $payment_add_params['payment_mode'];

		$total_amount	=	$payment_add_params['payment_amount'] + $deduction_amount;
		$dbConn->ExecuteQuery("BEGIN;",$iRows);
		$dbConn->FetchAllData("insert into tblpayments(payment_amount, payment_note, payment_mode, deduction_amount, deduction_type, deduction_note, user_id, login_user_id, total_amount,zaakpay_orderid,payment_status,user_description,payment_source) values({$payment_add_params['payment_amount']}, {$payment_add_params['payment_note']}, $payment_mode, $deduction_amount, $deduction_type, $deduction_note, {$payment_add_params['user_id']}, {$payment_add_params['login_user_id']},$total_amount, $zaakpay_orderid, $payment_status,$user_description,$payment_source) returning payment_id",$arPayment,$iPRows);
		if($iPRows>0){
			$payment_id = $arPayment[0]['payment_id'];
		}else{
			$message = "Payment not added";
			$dbConn->ExecuteQuery("ROLLBACK;",$iRows);
			return __LINE__;	
		}

		if($iPRows > 0){
			if($payment_status == 0){
				//code to unblock patch if any
				$credit_limit					= $arUser[0]['credit_limit'];				
				$balance_params['user_id']		= $payment_add_params['user_id'];
				$iResult = $this->balance_fetch($dbConn, $balance_params, $balance_array, $message);
				$balanace_due =  $balance_array['user_balance'];
				if($balanace_due < $credit_limit){
					$dbConn->ExecuteQuery("update tblusers set user_status=0 where userid={$payment_add_params['user_id']}",$iUURows);
				}
			}
			$dbConn->ExecuteQuery("COMMIT;",$iRows);
			$payment_result_arrray['payment_id'] = $payment_id;			
			$message="New Payment Added.";
			return 0;				
		}
	}
	
	// Cancel a payment and generate a new debit note against it
	public function payment_cancel($dbConn, $payment_cancel_params, &$payment_result_arrray, &$message = '')
	{
		if(!is_numeric($payment_cancel_params['payment_id'])){
			$message = "Please provide a valid payment id.";
			return __LINE__;
		}

		if(!is_numeric($payment_cancel_params['login_user_id'])){
			$message = "Please provide a valid login id.";
			return __LINE__;
		}

		if(trim($payment_cancel_params['cancel_text']) == ''){
			$message = "Please provide a cancel text.";
			return __LINE__;
		}

		$dbConn->FetchAllData("SELECT * FROM tblpayments WHERE payment_id = {$payment_cancel_params['payment_id']} and payment_status=0 and payment_source=0",$arPayment,$iPSRows);

		if($iPSRows == 0){
			$message = "Please provide a valid payment id.";
			return __LINE__;
		}

		$total_amount	= $arPayment[0]['total_amount'];
		$user_id		= $arPayment[0]['user_id'];
		$payment_date	= explode(' ',$arPayment[0]['payment_date']);

		$invoice_add_params['user_id'] = $user_id;
		$invoice_add_params['invoice_description']	= "Debit Note Against Cancelled Payment #: {$payment_cancel_params['payment_id']}\n Dated: $payment_date[0]";
		$invoice_add_params['invoice_type']			= 1;
		$invoice_add_params['invoice_source']		= 1;
		$invoice_items_param[0]['unit_rate']		= $total_amount;
		$invoice_items_param[0]['invoice_item_description'] = "Debit Note Against Cancelled Payment #: {$payment_cancel_params['payment_id']}\n Dated: $payment_date[0]";
		$dbConn->ExecuteQuery("BEGIN;",$iRows);
		$result = $this->invoice_add($dbConn, $invoice_add_params,$invoice_items_param,$invoice_result,$message);
		if($result != 0){
			$dbConn->ExecuteQuery("ROLLBACK;",$iRows);
			$message = "Debit note not added against cancelled payment";
			return __LINE__;			
		}else{
			$dbConn->ExecuteQuery("Update tblpayments set payment_status=1,cancel_text='{$payment_cancel_params['cancel_text']}' where payment_id={$payment_cancel_params['payment_id']}",$iUPRows);
			//Insert previous rows values with login user id
			$dbConn->FetchAllData("select row_to_json(t) as row_to_json from (select * from tblpayments where payment_id={$payment_cancel_params['payment_id']}) t", $arLogArray, $iILRows);
			$json_data = $arLogArray[0]['row_to_json'];
			$dbConn->ExecuteQuery("insert into tbllogdata(log_user_id,log_data,log_table) values ({$payment_cancel_params['login_user_id']},'{$json_data}','tblpayments')",$iLGRows);
			$dbConn->ExecuteQuery("COMMIT;",$iRows);
			return 0;
		}
	}
	
	// Fetch user balance on a given date
	public function balance_fetch($dbConn, $balance_params, &$balance_array, &$message = '')
	{
		if(!is_numeric($balance_params['user_id'])){
			$message = "Please provide a valid user id.";
			return __LINE__;
		}

		if(trim($balance_params['balance_date']) == '')
			$balance_params['balance_date'] = "'" . date('Y-m-d H:i:sO') . "'";
		else
			$balance_params['balance_date'] = "'" . $balance_params['balance_date'] . "'";

		// get last invoice
		$outstanding_amount = 0;
		$sqlBalance= "select total_amount, previous_balance, invoice_date from invoice where user_id=".$balance_params['user_id']." and invoice_date <= {$balance_params['balance_date']} order by invoice_id desc Limit 1";
		$dbConn->FetchAllData($sqlBalance, $arBalance, $iBRows);
		if($iBRows>0){
			$outstanding_amount = $arBalance[0]['total_amount'] + $arBalance[0]['previous_balance'];
			$invoice_date = "'" . $arBalance[0]['invoice_date'] . "'";
		}else{
			$invoice_date = "'1970-01-01 00:00:00'";		// If no invoice is generated, simply calculate all payments
		}

		// get payments between the last invoice date and the date passed in search parameter
		$total_payment= 0;
		$sqlPayments= "select sum(total_amount) as total_payment from tblpayments where user_id=".$balance_params['user_id']." and (payment_date between $invoice_date and {$balance_params['balance_date']}) and payment_status <> -1";
		$dbConn->FetchAllData($sqlPayments, $arPayment, $iPRows);
		if($iPRows>0){
			$total_payment = $arPayment[0]['total_payment'];
		}

		$sqlUsers= "select username,company_name from tblusers where userid=".$balance_params['user_id'];
		$dbConn->FetchAllData($sqlUsers, $arUsers, $iURows);

		$balance_array['user_balance']	= $outstanding_amount - $total_payment;
		$balance_array['username']		= $arUsers[0]['username'];
		$balance_array['company_name']	= $arUsers[0]['company_name'];
		return 0;		
	}
	
	// Generate user statement within a given time period
	public function statement_generate($dbConn, $statement_params, &$statement_arrray, &$message = '')
	{
		/*if(!is_numeric($statement_params['user_id'])){
			$message = "Please provide a valid user id.";
			return __LINE__;
		}*/

		if(trim($statement_params['start_date']) == '')
			$statement_params['start_date'] = "'1970-01-01 00:00:00'";
		else
			$statement_params['start_date'] = "'" . $statement_params['start_date'] . "'";

		if(trim($statement_params['end_date']) == ''){
			$balance_params['balance_date'] = date('Y-m-d H:i:sO');
			$statement_params['end_date'] = "'" . date('Y-m-d H:i:sO') . "'";
		}
		else{
			$balance_params['balance_date'] = $statement_params['end_date'];
			$statement_params['end_date'] = "'" . $statement_params['end_date'] . "'";
		}

		if(!is_numeric($statement_params['type'])){
			$statement_params['type'] = 0;		// 0 - Both (Invoice and Payments); 1 - Invoice; 2 - Payments
		}

		$dbConn->ExecuteQuery("SET TIME ZONE 'Asia/Kolkata';", $iSRows);

		$invoice_query = "Select invoice_id, cast (invoice_date as timestamp(0)) as statement_date, total_amount,1 as data_type,invoice_status,invoice_type,invoice_source, invoice_code, tally_status, invoice_description as note, username,company_name,address from invoice as a, tblusers as b where a.user_id=b.userid and (invoice_date between {$statement_params['start_date']} and {$statement_params['end_date']})  ";

		if(is_numeric($statement_params['user_id']))
			$invoice_query .= " and a.user_id={$statement_params['user_id']}";

		if(is_numeric($statement_params['invoice_id']))
			$invoice_query .= " and invoice_id={$statement_params['invoice_id']}";

		if(is_numeric($statement_params['invoice_code']))
			$invoice_query .= " and invoice_code={$statement_params['invoice_code']}";

		if(is_numeric($statement_params['invoice_type']))
			$invoice_query .= " and invoice_type={$statement_params['invoice_type']}";

		if(is_numeric($statement_params['invoice_source']))
			$invoice_query .= " and invoice_source = {$statement_params['invoice_source']}";

		if(is_numeric($statement_params['invoice_status']))
			$invoice_query .= " and invoice_status = {$statement_params['invoice_status']}";

		$payment_query = "Select payment_id, cast (payment_date as timestamp(0)) as statement_date, total_amount,2 as data_type, payment_status,payment_mode,payment_source,payment_id,payment_note as note, username,company_name,address from tblpayments as a, tblusers as b where a.user_id=b.userid and (payment_date between {$statement_params['start_date']} and {$statement_params['end_date']}) and payment_status <> -1";

		if(is_numeric($statement_params['user_id']))
			$payment_query .= " and a.user_id={$statement_params['user_id']}";

		if(is_numeric($statement_params['payment_id']))
			$payment_query .= " and payment_id={$statement_params['payment_id']}";

		if(is_numeric($statement_params['payment_amount']))
			$payment_query .= " and payment_amount={$statement_params['payment_amount']}";

		if(is_numeric($statement_params['payment_mode']))
			$payment_query .= " and payment_mode={$statement_params['payment_mode']}";

		if(trim($statement_params['payment_note']) != '')
			$payment_query .= " and payment_note like '%{$statement_params['payment_note']}%'";

		if(is_numeric($statement_params['payment_source']))
			$payment_query .= " and payment_source = {$statement_params['payment_source']}";

		if(is_numeric($statement_params['payment_status']))
			$payment_query .= " and payment_status = {$statement_params['payment_status']}";

		$statement_query = "select * from (	" . $invoice_query . " UNION " . $payment_query . " ) as statement_table";
		if($statement_params['type']==1)
			$statement_query = $invoice_query;
		elseif($statement_params['type']==2)
			$statement_query = $payment_query;

		$statement_query .= " order by statement_date";

		$dbConn->FetchAllData($statement_query, $statement_arrray, $iSRows);

		if($iSRows == 0){
			$message = "No rows found.";
			return __LINE__;
		}else{
			//Fetch Outstanding amount only if the action is for statement generate
			if(($statement_params['type'] == 0) && (is_numeric($statement_params['user_id']))){
				$balance_params['user_id']			= $statement_params['user_id'];
				$iResult = $this->balance_fetch($dbConn, $balance_params, $balance_array, $message);
				$statement_arrray['balance_due']	= $balance_array['user_balance'];
				$statement_arrray['username']		= $balance_array['username'];
				$statement_arrray['company_name']	= $balance_array['company_name'];
			}
			return 0;
		}
	}

	// Update user patch, billing term, billing contact, credit period, credit limit, bill type, default unit rate, payment patch
	public function user_update($dbConn, $user_update_params, &$user_update_result, &$message = '')
	{
		if(!is_numeric($user_update_params['user_id'])){
			$message = "Please provide a valid user id.";
			return __LINE__;
		}

		if(!is_numeric($user_update_params['executing_user_id'])){
			$message = "Please provide a valid executing user id.";
			return __LINE__;
		}else{
			$USER_ID  = $user_update_params['executing_user_id'];
		}
		
		$dbConn->ExecuteQuery("BEGIN", $iRows);
		//Insert previous rows values with login user id
		$dbConn->FetchAllData("select row_to_json(t) as row_to_json from (select * from tblusers where userid={$user_update_params['user_id']}) t", $arLogArray, $iILRows);
		$json_data = $arLogArray[0]['row_to_json'];
		$dbConn->ExecuteQuery("insert into tbllogdata(log_user_id,log_data,log_table) values ($USER_ID,'{$json_data}','tblusers')",$iLGRows);

		$user_update_sql = "update tblusers set userid={$user_update_params['user_id']}";

		if(is_numeric($user_update_params['billing_term']))
			$user_update_sql .= ",billing_term={$user_update_params['billing_term']}";

		if(is_numeric($user_update_params['billing_contact']))
			$user_update_sql .= ",billing_contact={$user_update_params['billing_contact']}";

		if(is_numeric($user_update_params['credit_period']))
			$user_update_sql .= ",credit_period={$user_update_params['credit_period']}";

		if(is_numeric($user_update_params['credit_limit']))
			$user_update_sql .= ",credit_limit={$user_update_params['credit_limit']}";

		if(is_numeric($user_update_params['bill_type']))
			$user_update_sql .= ",bill_type={$user_update_params['bill_type']}";

		if(is_numeric($user_update_params['unit_rate']))
			$user_update_sql .= ",unit_rate={$user_update_params['unit_rate']}";

		if(is_numeric($user_update_params['bill_scheme']))
			$user_update_sql .= ",bill_scheme={$user_update_params['bill_scheme']}";

		if($user_update_params['company_name'] != '')
			$user_update_sql .= ",company_name='{$user_update_params['company_name']}'";

		if($user_update_params['phone'] != '')
			$user_update_sql .= ",phone='{$user_update_params['phone']}'";

		if($user_update_params['address'] != '')
			$user_update_sql .= ",address='{$user_update_params['address']}'";

		if($user_update_params['email'] != '')
			$user_update_sql .= ",email='{$user_update_params['email']}'";

		if(is_numeric($user_update_params['user_status']))
			$user_update_sql .= ",user_status={$user_update_params['user_status']}";

		$user_update_sql .= " where userid={$user_update_params['user_id']}";

		$dbConn->ExecuteQuery($user_update_sql,$iUURows);
		if($iUURows > 0){
			$dbConn->ExecuteQuery("COMMIT", $iRows);
			$message = "User Update Successfully.";
			return 0;
		}else{
			$dbConn->ExecuteQuery("ROLLBACK", $iRows);
			$message = "User Not Updated.";
			return __LINE__;
		}
	}

	// Update device next billing date, billing term, billing status, unit rate
	public function device_update($dbConn, $device_update_params, &$device_update_result, &$message = '')
	{
		if(!is_numeric($device_update_params['device_id'])){
			$message = "Please provide a valid device id.";
			return __LINE__;
		}

		if(!is_numeric($device_update_params['executing_user_id'])){
			$message = "Please provide a valid executing user id.";
			return __LINE__;
		}else{
			$USER_ID  = $device_update_params['executing_user_id'];
		}
		
		$dbConn->ExecuteQuery("BEGIN", $iRows);
		//Insert previous rows values with login user id
		$dbConn->FetchAllData("select row_to_json(t) as row_to_json from (select * from tbldevices where device_id={$device_update_params['device_id']}) t", $arLogArray, $iILRows);
		$json_data = $arLogArray[0]['row_to_json'];
		$dbConn->ExecuteQuery("insert into tbllogdata(log_user_id,log_data,log_table) values ($USER_ID,'{$json_data}','tbldevices')",$iLGRows);

		$device_update_sql = "update tbldevices set device_id={$device_update_params['device_id']}";

		if(trim($device_update_params['next_billing_date']) != '')
		{
			$next_billing_day	= date("d", strtotime($device_update_params['next_billing_date']));

			$days_to_add = array(29 => 3, 30 => 2, 31 => 1);

			if($next_billing_day == 29 || $next_billing_day == 30 || $next_billing_day == 31){
				$date = strtotime("+".$days_to_add[$next_billing_day]." days", strtotime($device_update_params['next_billing_date']));
				$device_update_params['next_billing_date'] = date("Y-m-d H:i:s", $date); 
			}

			$device_update_sql .= ",next_billing_date='{$device_update_params['next_billing_date']}'";
		}

		if(is_numeric($device_update_params['billing_term']))
			$device_update_sql .= ",billing_term={$device_update_params['billing_term']}";

		if(is_numeric($device_update_params['billing_status']))
			$device_update_sql .= ",billing_status={$device_update_params['billing_status']}";

		if(is_numeric($device_update_params['unit_rate']))
			$device_update_sql .= ",unit_rate={$device_update_params['unit_rate']}";

		if(is_numeric($device_update_params['installment_amount']))
			$device_update_sql .= ",installment_amount={$device_update_params['installment_amount']}";

		if(is_numeric($device_update_params['installment_period']))
			$device_update_sql .= ",installment_period={$device_update_params['installment_period']}";

		$device_update_sql .= " where device_id={$device_update_params['device_id']}";

		$dbConn->ExecuteQuery($device_update_sql,$iDURows);
		if($iDURows > 0){
			$dbConn->ExecuteQuery("COMMIT", $iRows);
			$message = "Device Update Successfully.";
			return 0;
		}else{
			$dbConn->ExecuteQuery("ROLLBACK", $iRows);
			$message = "Device Not Updated.";
			return __LINE__;
		}
	}

	// Invoice Print function 
	// this function is obsolate in new billing devided into invoice_create & invoice_create_core
	public function invoice_print($dbConn, $invoice_id, $invoice_item_id, $invoice_print_params, &$return_html, &$message = '')
	{
		if(!is_numeric($invoice_id) && !is_numeric($invoice_item_id)){
			$message = "Please provide a valid invoice id.";
			return __LINE__;
		}
		
		$dbConn->FetchAllData("select * from tblmiscsettings where setting_name='currency' and module_code='BILLING'",$arMiscCSearch,$iMCRows);

		$dbConn->FetchAllData("select * from tblmiscsettings where setting_name='invoice_template_consolidated' and module_code='BILLING'",$arMiscSearch,$iMRows);

		$currency				= $arMiscCSearch[0]['setting_value'];
		$invoice_template		= $arMiscSearch[0]['setting_value'];
		$invoice_template_decode= base64_decode($invoice_template);	

		$repeat_data_template = '<tr><th>{DESCRIPTION}</th><th>{CURRENCY} {RATE}</th><th>{TAX_NAME}</th><th>{TAX_VALUE}</th><th>{CURRENCY} {THIS_MONTH_TOTAL}</th></tr>';

		$repeat_data = '<tr><td colspan=5>{DOMAIN_LOGO_IMG} &nbsp;{INVOICE_NO} &nbsp; {CURRENT_DATE}</td></tr><tr style="background-color:#ccc;"><td><strong>Description</strong></td><td><strong>Unit Rate</strong></td><td><strong>Tax Name</strong><td><strong>Tax Value</strong></td><td><strong>Amount</strong></td></tr>';

		$repeat_data_items = '<tr style="background-color:#ccc;"><td><strong>Description</strong></td><td><strong>Unit Rate</strong></td><td><strong>Tax Name</strong><td><strong>Tax Value</strong></td><td><strong>Amount</strong></td></tr>';

		// search group owner detail (portal Admin)
		$company_name		= "Nucleus Microsystems Pvt. Ltd.";
		$company_address	= "10184, Arya Samaj Road Karol Bagh New Delhi - 110005";
		$company_website	= "www.nms.co.in";
		$company_email		= "info@nms.co.in";
		$company_phone		= "011 47574757";
		$gst_no				= "07AABCN8579P1ZJ";
		$pan_number			= "AABCN8579P";
		$hsn_sac			= "00998319";

		$return_html = '';

		//Get The User id via invoice id or invoice type id 
		if(is_numeric($invoice_id))
		{
			$dbConn->FetchAllData("select * from invoice where invoice_id=$invoice_id",$arUserSearch,$iURows);
		
		}
		elseif(is_numeric($invoice_item_id))
		{
			$dbConn->FetchAllData("select a.user_id from invoice as a,invoice_items as b where a.invoice_id=b.invoice_id and b.invoice_item_id=$invoice_item_id",$arUserSearch,$iURows);
		}

		$user_id = $arUserSearch[0]['user_id'];

		$searchBillingUser	= "select * from tblusers where userid = $user_id";
		@$dbConn->ExecuteSelectQuery($searchBillingUser, $rsBillingUser,$iUBrows);
		if($iUBrows > 0)
		{		
			while($arUserDevices=$dbConn->GetData($rsBillingUser))
			{
				$user_id		=	$arUserDevices['userid'];
				$user_name		=	$arUserDevices['username'];
				$company_name1	=	$arUserDevices['company_name'];
				$user_email		=	$arUserDevices['email'];
				$address		=	$arUserDevices['address'];
				$phone			=	$arUserDevices['phone'];
				$bill_type		=	$arUserDevices['bill_type'];
				$credit_limit	=	$arUserDevices['credit_limit'];
				$credit_period	=	$arUserDevices['credit_period'];
				$bill_to		=	($company_name1."<br>".$address."<br>".$user_email."<br>".$phone);
			}
		}

		$domain_logo_img =	"<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.logo-small.png\" alt=\"Logo\" title=\"Logo\">&nbsp;&nbsp;<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.logo.png\" alt=\"Logo\" title=\"Logo\">";

		$domain_sign_img =	"<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.sign.png\" alt=\"Sign\" title=\"Sign\">";
		$domain_stamp_img =	"<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.stamp.png\" alt=\"Stamp\" title=\"Stamp\">";

		if(is_numeric($invoice_item_id))
		{		
			$invoice_template_decode= file_get_contents($invoice_print_params['static_server_path']."/invoice_cons.html");	
			$final_data = '';
			$searchInvoiceItems= "select a.*,b.invoice_date,b.user_description from invoice_items as a,invoice as b where a.invoice_id=b.invoice_id and a.invoice_item_id=".$invoice_item_id;
			$dbConn->ExecuteSelectQuery($searchInvoiceItems, $resultInvoiceItem, $iIRows);
			if($iIRows>0)
			{
				$arInvoiceISearch			= $dbConn->GetData($resultInvoiceItem);
				$invoice_item_id			= $arInvoiceISearch['invoice_item_id'];
				$invoice_item_description	= $arInvoiceISearch['invoice_item_description'];
				$invoice_date				= $arInvoiceISearch['invoice_date'];
				$invoice_item_rate			= $arInvoiceISearch['invoice_item_rate'];
				$total_rate					= $arInvoiceISearch['total_rate'];
				$status						= $arInvoiceISearch['status'];
				$bill_to					= $arInvoiceISearch['user_description'];

				if($status == -1){
					$message = "This is a cancelled invoice.";
					return __LINE__;
				}
				
				$dbConn->FetchAllData("select * from tblinvoiceitemtaxes where invoice_item_id=$invoice_item_id", $arInvoiceItemTaxes, $iITRows);
				$tax_name = '';
				$tax_value = '';
				for($iITCount=0;$iITCount<$iITRows;$iITCount++)
				{
					$tax_name			.=	$arInvoiceItemTaxes[$iITCount]['tax_name'] . ' @ ' . $arInvoiceItemTaxes[$iITCount]['tax_value'] ."%<br>";
					$tax_value			.=	"{CURRENCY} ".(($invoice_item_rate * $arInvoiceItemTaxes[$iITCount]['tax_value'])/100) ."<br>";
				}

				$search_array1	=	array('{DESCRIPTION}','{RATE}','{TAX_NAME}','{TAX_VALUE}','{THIS_MONTH_TOTAL}','{CURRENCY}','{CURRENT_DATE}');
				$replace_array1	=   array($invoice_item_description,$invoice_item_rate,$tax_name,$tax_value,$total_rate,$currency,date('d-m-Y',strtotime($invoice_date)));			
				$final_data		.=	str_replace($search_array1, $replace_array1, $repeat_data_template);
			}


			$search_array	=	array('{USER_NAME}','{COMPANY_NAME}','{COMPANY_ADDRESS}','{COMPANY_WEBSITE}','{COMPANY_PHONE}','{COMPANY_EMAIL}','{GST_NO}','{PAN_NUMBER}','{CURRENT_DATE}','{BILL_TO}','{DATE}','{DUE_DATE}','{START_DATE}','{END_DATE}','{REPEAT_DATA}', '{TAXES}' ,'{DOMAIN_LOGO_IMG}', '{CURRENCY}','{THIS_MONTH_TOTAL}','{THIS_MONTH_SUBTOTAL}', '{DOMAIN_STAMP_IMG}', '{DOMAIN_SIGN_IMG}','{INVOICE_NO}');
		
			$replace_array  =   array($user_name,$company_name,$company_address,$company_website,$company_phone,$company_email,$gst_no,$pan_number,  date('d-m-Y',strtotime($invoice_date)), $bill_to, date('d-m-Y',strtotime($invoice_date)), $due_date, $start_date,$end_date, "<thead>".$repeat_data_items."</thead><tbody>".$final_data."</tbody>", $taxes_html, $domain_logo_img, $currency,$total_invoice_amount_payble,$total_unit_rate, $domain_stamp_img,$domain_sign_img,date('Ymd',strtotime($invoice_date))."/{$invoice_item_id}");

			$return_html	.= str_replace($search_array, $replace_array, $invoice_template_decode);
		}
		else
		{
			//selecting the last invoice date
			$invoice_date	= '';
			$searchInvoice= "select * from invoice where invoice_id=$invoice_id";
			$dbConn->ExecuteSelectQuery($searchInvoice, $resultInvoice, $iRows);
			if($iRows>0)
			{
				$arInvoiceSearch	= $dbConn->GetData($resultInvoice);
				$invoice_date 		= $arInvoiceSearch['invoice_date'];
				$invoice_code		= $arInvoiceSearch['invoice_code'];			
				$total_amount		= $arInvoiceSearch['total_amount'];
				$invoice_id			= $arInvoiceSearch['invoice_id'];
				$invoice_type		= $arInvoiceSearch['invoice_type'];
				$invoice_status		= $arInvoiceSearch['invoice_status'];
				$previous_balance	= $arInvoiceSearch['previous_balance'];
				$total_invoice_amount_payble	= $arInvoiceSearch['total_invoice_amount_payble'];
				$bill_to					= $arInvoiceSearch['user_description'];

				if($invoice_status	== -1)
				{
					$message = "This is a cancelled invoice.";
					return __LINE__;
					/*
					$invoice_status_text	=	"(Cancelled)";
					$invoice_status_image	=	$static_server_path.'templates/' . $TEMPLATE . "/images/cancelled.jpg";
					$background_image		=	"style='background:url(".$invoice_status_image.") no-repeat 311px 311px'";*/
				}

				if($po_number == '')
					$po_number = "--";
				
				if(trim($invoice_code) != '')
					$invoice_no=$invoice_code;
				else
					$invoice_no=$invoice_id;
				
				$final_data = '';
				$total_unit_rate = 0;
				$total_invoice_amount_payble = 0;
				$dbConn->FetchAllData("select * from invoice_items where invoice_id=$invoice_id", $arInvoiceItems, $iIRows);
				for($iICount=0;$iICount<$iIRows;$iICount++)
				{
					$invoice_item_id	= $arInvoiceItems[$iICount]['invoice_item_id'];
					$invoice_item_rate	= $arInvoiceItems[$iICount]['invoice_item_rate'];
					$total_rate			= $arInvoiceItems[$iICount]['total_rate'];
					$total_unit_rate += $invoice_item_rate;
					$total_invoice_amount_payble += $total_rate;
					$invoice_item_description			= $arInvoiceItems[$iICount]['invoice_item_description'];
					$dbConn->FetchAllData("select * from tblinvoiceitemtaxes where invoice_item_id=$invoice_item_id", $arInvoiceItemTaxes, $iITRows);
					$tax_name = '';
					$tax_value = '';
					for($iITCount=0;$iITCount<$iITRows;$iITCount++)
					{
						$tax_name			.=	$arInvoiceItemTaxes[$iITCount]['tax_name'] . ' @ ' . $arInvoiceItemTaxes[$iITCount]['tax_value'] ."%<br>";
						$tax_value			.=	"{CURRENCY} ".(($invoice_item_rate * $arInvoiceItemTaxes[$iITCount]['tax_value'])/100) ."<br>";
					}

					$search_array1	=	array('{DESCRIPTION}','{RATE}','{TAX_NAME}','{TAX_VALUE}','{THIS_MONTH_TOTAL}','{CURRENCY}','{CURRENT_DATE}');
					$replace_array1	=   array($invoice_item_description,$invoice_item_rate,$tax_name,$tax_value,$total_rate,$currency,date('d-m-Y',strtotime($invoice_date)));			
					$final_data		.=	str_replace($search_array1, $replace_array1, $repeat_data_template);
				}			
			}
			
			$last_payment			=	0;
			$last_adjustment		=	0;

			$payment_search_params['user_id']		= $user_id;
			$payment_search_params['type']			= 2;
			$payment_search_params['end_date']		= $invoice_date;
			$payment_search_params['payment_status']= 0;
			$payment_search_params['payment_source']= 0;

			$iResult = $this->statement_generate($dbConn, $payment_search_params, $statement_arrray, $message);

			if($iResult == 0)
				$last_payment = $statement_arrray[count($statement_arrray)-1]['total_amount'];
			
			$balanace_due =  $previous_balance;
			if($balanace_due > $credit_limit)
			{
				$due_date	= 'Immediate';
			}
			else
			{	
				$due_date	= date('d-m-y', strtotime("+7 day",strtotime($invoice_date)));
			}

			$domain_logo_img =	"<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.logo-small.png\" alt=\"Logo\" title=\"Logo\">&nbsp;&nbsp;<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.logo.png\" alt=\"Logo\" title=\"Logo\">";

			$domain_sign_img =	"<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.sign.png\" alt=\"Sign\" title=\"Sign\">";
			$domain_stamp_img =	"<img src=\"".$invoice_print_params['static_server_path']."/images/{$invoice_print_params['domain_name']}.stamp.png\" alt=\"Stamp\" title=\"Stamp\">";
			
			$amount_payable	=	($balanace_due + $total_amount);		
			$amount_in_words	= ucwords(no_to_words(round($amount_payable)));

			$search_array	=	array('{USER_NAME}','{COMPANY_NAME}','{COMPANY_ADDRESS}','{COMPANY_WEBSITE}','{COMPANY_PHONE}','{COMPANY_EMAIL}','{GST_NO}','{PAN_NUMBER}','{CURRENT_DATE}','{BILL_TO}','{DATE}','{DUE_DATE}','{START_DATE}','{END_DATE}','{REPEAT_DATA}', '{TAXES}' , '{AMOUNT_IN_WORDS}', '{PREVIOUS_BALANCE}','{PAYMENTS}', '{ADJUSTMENTS}','{AMOUNT_PAYABLE}','{DOMAIN_LOGO_IMG}', '{CURRENCY}','{THIS_MONTH_TOTAL}','{THIS_MONTH_SUBTOTAL}', '{DOMAIN_STAMP_IMG}', '{DOMAIN_SIGN_IMG}','{INVOICE_NO}');

			$repeat_data	= str_replace('{CURRENT_DATE}', date('d-m-Y',strtotime($invoice_date)), $repeat_data);
		
			$replace_array  =   array($user_name,$company_name,$company_address,$company_website,$company_phone,$company_email,$gst_no,$pan_number,  date('d-m-Y',strtotime($invoice_date)), $bill_to, date('d-m-Y',strtotime($invoice_date)), $due_date, $start_date,$end_date, "<thead>".$repeat_data."</thead><tbody>".$final_data."</tbody>", $taxes_html, $amount_in_words, $balanace_due,$last_payment,$last_adjustment,$amount_payable, $domain_logo_img, $currency,$total_invoice_amount_payble,$total_unit_rate, $domain_stamp_img,$domain_sign_img,date('Ymd',strtotime($invoice_date))."/{$invoice_no}");

			$return_html	.= str_replace($search_array, $replace_array, $invoice_template_decode);
		}

		$message = "Invoice found.";
		
		return 0;
	}

	#This function is used to search package 
	final public function package_search($package_search_params, &$package_search_result, &$package_search_message='')
	{
		try
		{
			global $dbConn;				#global database object
			$billingpackage_id	= @$package_search_params[0]['billingpackage_id'];
			$package_name		= @$package_search_params[0]['package_name'];
			$package_rate		= @$package_search_params[0]['package_rate'];
			
			$package_search_sql	= "select a.* from tblbillingpackage as a where 1=1";
			
			if( is_numeric($billingpackage_id))				
				$package_search_sql .= " AND a.billingpackage_id = $billingpackage_id";
			
			//check for limit AND offsets
			$limit_text = '';
			if((is_numeric($limit)!==false) || ($limit > 0))
				$limit_text=' LIMIT ALL';
				
			$offset_text='';
			if((is_numeric($offset)!==false) || ($offset > 0))
				$offset_text=' OFFSET $offset';

			//check for field to order by
			if(is_numeric($order_field)===false)
				$order_field=0;
				
			switch($order_field)
			{
				case 0:
					$order_field='a.billingpackage_id';
					break;

				case 1:
					$order_field='a.package_name';
					break;

				default:
					$order_field='a.billingpackage_id';
					break;
			}
			
			//order type - Desc or Ascending
			if(is_numeric($order_type)===false)
				$order_type=0;

			if($order_type==1)
				$order_type="DESC";
			else
				$order_type="ASC";

			$package_search_sql .= " ORDER BY $order_field $order_type $limit_text $offset_text";
			//echo $package_search_sql;	
			
			
			$intCounter = 0;			
			$dbConn->ExecuteSelectQuery($package_search_sql,$rsSettings,$setting_rows);
			if($setting_rows > 0)
			{
				while($package_data=$dbConn->GetData($rsSettings))
				{
					$package_search_result[$intCounter]['billingpackage_id']= $package_data['billingpackage_id'];
					$package_search_result[$intCounter]['package_name']		= $package_data['package_name']; 
					$package_search_result[$intCounter]['package_rate']		= $package_data['package_rate']; 
					
					$intCounter++;
				}
				return 0;
			}
			else
			{
				//no records, return an error message
				$message = "No records found";
				return 1;
			}
		}
		catch (Exception $e)
		{
			# HANDle error
			$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
			return 9999;
		}
		
	}
	
	# Delete package extension
	final public function package_delete($package_delete_params, &$message='')
	{
		try
		{
			global $dbConn;		#global database object
			$billingpackage_id			= @$package_delete_params[0]['billingpackage_id'];

			if(!is_numeric($billingpackage_id))
			{
				$message = "Package id must be numeric.";
				return 1;
			}
						
			$delete_sql = "delete from 	tblbillingpackage where billingpackage_id = $billingpackage_id";
			$dbConn->ExecuteQuery($delete_sql, $iRows);
			if($iRows > 0)
			{
				return 0;
			}
			else
			{
				//Delete failed
				$message = "Unable to delete the package. Kindly try again.";
				return 3;
			}
			
		}
		catch (Exception $e)
		{
			# Handle error
			$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
			return 9999;
		}
	}
	
	//update an exsiting package
	final public function package_update($package_update_params, &$message='')
	{
		try
		{
			global $dbConn;		#global database object

			$billingpackage_id	= @$package_update_params[0]['billingpackage_id'];
			$pkg_name_add		= @$package_update_params[0]['pkg_name_add'];
			$pkg_rate_add		= @$package_update_params[0]['pkg_rate_add'];
			
			if(!is_numeric($billingpackage_id))
			{
				$message = "Please provide a valid package id.";
				return 1;
			}

			$package_update_query = "Update tblbillingpackage set billingpackage_id=$billingpackage_id ";

			if(trim($pkg_name_add!=''))
				$package_update_query .= ", package_name='$pkg_name_add' ";

			if(is_numeric($pkg_rate_add))
				$package_update_query .= ", package_rate=$pkg_rate_add ";
			
			$package_update_query .= " where billingpackage_id=$billingpackage_id";
			
			@$dbConn->ExecuteQuery($package_update_query,$iRows);				
			if($iRows > 0)
			{
				//we now need the user id
				$message="Package updated.";
				return 0;				
			}
			else
			{
				//get the last error code
				$error_string = pg_last_error();
				if($error_string===false)
				{
					//do nothing
				}
				else
				{
					$message = "Unknown error has occurred.";
					return 6;
					
				}
			}
		}
		catch (Exception $e)
		{
			# Handle error
			$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
			return 9999;
		}
	}
	
	// ADD Package
	final public function package_add($package_add_params, &$package_array, &$message='')
	{
		try
		{
			//your code goes here
			global $dbConn;

			$pkg_name_add	= @$package_add_params[0]['pkg_name_add'];
			$pkg_rate_add	= @$package_add_params[0]['pkg_rate_add'];
			
			if(trim($pkg_name_add)=='')
			{
				$message = "Please provide a pakage name.";
				return 1;
			}
			
			if(!is_numeric($pkg_rate_add))
			{
				$message = "Please enter package rate.";
				return 2;
			}
			
			if(trim($pkg_name_add)=='')
				$pkg_name_add='';
			
			if(!is_numeric($pkg_rate_add))
				$pkg_rate_add=0;
				
			
			$package_add_sql = "insert into tblbillingpackage(package_name, package_rate) values('$pkg_name_add', $pkg_rate_add)";	
				
			//echo $package_add_sql ;
			@$dbConn->ExecuteQuery($package_add_sql,$iRows);				
			if($iRows > 0)
			{
				//we now need the user id
				$billingpackage_id = $dbConn->GetLastInsertId('tblbillingpackage','billingpackage_id');
				$package_array[0]['billingpackage_id'] = $billingpackage_id;
				
				$message="New package created.";
				return 0;				
			}
			else
			{
				//get the last error code
				$error_string = pg_last_error();
				if($error_string===false)
				{
					//do nothing
				}
				else
				{
					$message = "Unknown error occured. Please try again.";
					return 999;			
				}
			}
		}
		catch (Exception $e)
		{
			# Handle error
			$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
			return 9999;
		}
	}

	#This function is used to search invoise 
	final public function user_invoice_payment_search($user_invoice_search_params, &$user_invoice_result_array, &$message='')
	{		
		try
		{		
			global $dbConn;				#global database object

			$user_id			= @$user_invoice_search_params[0]['user_id'];
			$start_date			= @$user_invoice_search_params[0]['start_date'];			
			$end_date			= @$user_invoice_search_params[0]['end_date'];
			
			if(!is_numeric($user_id))	
			{
				$message="Invalid user id";
				return 1;
			}		
			
			$invoice_sql	="select invoice_id, invoice_date, total_amount,1 as data_type,invoice_status,invoice_type, 	invoice_currency from invoice where user_id=$user_id ";

			$payment_sql	="select payment_id, payment_date, total_amount,2 as data_type, payment_mode,-1 as invoice_type,payment_note from tblpayments where user_id= $user_id and payment_status=0 ";

			if(trim($start_date != '') && trim($end_date != ''))
			{
				$invoice_sql .= " AND invoice_date between '$start_date' AND '$end_date' ";
				$payment_sql .= " AND payment_date	between '$start_date' AND '$end_date' ";
			}
			
			$invoice_search_sql = "select * from (	" .$invoice_sql ."	UNION ALL	" .$payment_sql." ) as h order by invoice_date";
			
			$dbConn->FetchAllData($invoice_search_sql,$arSettings,$setting_rows);
			if($setting_rows > 0)
			{
				$user_invoice_result_array = $arSettings;
				return 0;
			}
			else
			{
				//no records, return an error message
				$message = "No records found";
				return 1;
			}
		}
		catch (Exception $e)
		{
			# HANDle error
			$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
			return 9999;
		}		
	}

	#This function is used to search invoice log search
	final public function invoicelog_search($invoicelog_search_params, &$invoicelog_result_array, &$message='')
	{		
		try
		{		
			global $dbConn;		#global database object

			$invoice_id			= @$invoicelog_search_params[0]['invoice_id'];
			$invoice_code		= @$invoicelog_search_params[0]['invoice_code'];
			$po_number			= @$invoicelog_search_params[0]['po_number'];
			$unit_rate			= @$invoicelog_search_params[0]['unit_rate'];
			$registration_no	= @$invoicelog_search_params[0]['registration_no'];
			$start_date			= @$invoicelog_search_params[0]['start_date'];			
			$end_date			= @$invoicelog_search_params[0]['end_date'];
			$status				= @$invoicelog_search_params[0]['status'];
			$login_user_id		= @$invoicelog_search_params[0]['login_user_id'];
			$change_date		= @$invoicelog_search_params[0]['change_date'];
									
			$limit				= @$invoice_search_params[0]['limit'] ;
			$offset				= @$invoice_search_params[0]['offset'] ;
			$order_field		= @$invoice_search_params[0]['order_field'] ;
			$order_type			= @$invoice_search_params[0]['order_type'] ;

			$invoicelog_search_sql	= "select a.*, b.username from tblinvoicelog as a, tblusers as b where a.login_user_id=b.userid ";
			
			if( is_numeric($invoice_id))				
				$invoicelog_search_sql .= " AND a.invoice_id	= $invoice_id ";
			
			if(trim($invoice_code != ''))	
				$invoicelog_search_sql .= " AND a.invoice_code ilike '%$invoice_code%'";

			if(trim($po_number != ''))
				$invoicelog_search_sql .= " AND a.po_number ilike '%$po_number%'";

			if(is_numeric($unit_rate))
				$invoicelog_search_sql .= " AND a.unit_rate	= $unit_rate ";

			if(trim($registration_no != ''))
				$invoicelog_search_sql .= " AND a.registration_no ilike '%$registration_no%'";

			if(trim($start_date != '') && trim($end_date != ''))				
				$invoicelog_search_sql .= " AND a.start_date	between '$start_date' AND '$end_date' AND a.end_date BETWEEN '$start_date' AND '$end_date'";

			if(trim($change_date != ''))				
				$invoicelog_search_sql .= " AND a.change_date	between '$change_date' ";

			if(is_numeric($status))
				$invoicelog_search_sql .= " AND a.status	= $status";
			
			
			echo $invoicelog_search_sql;			
			
			$intCounter = 0;			
			$dbConn->ExecuteSelectQuery($invoicelog_search_sql,$rsSettings,$setting_rows);
			if($setting_rows > 0)
			{
				while($invoice_data=$dbConn->GetData($rsSettings))
				{
					$invoicelog_result_array[$intCounter]['user_name']			= $invoice_data['username']; 
					$invoicelog_result_array[$intCounter]['invoicelog_id']		= $invoice_data['invoicelog_id'];
					$invoicelog_result_array[$intCounter]['invoice_id']			= $invoice_data['invoice_id'];
					$invoicelog_result_array[$intCounter]['invoice_code']		= $invoice_data['invoice_code'];
					$invoicelog_result_array[$intCounter]['po_number']			= $invoice_data['po_number']; 
					$invoicelog_result_array[$intCounter]['unit_rate']			= $invoice_data['unit_rate'];
					$invoicelog_result_array[$intCounter]['registration_no']	= $invoice_data['registration_no'];
					$invoicelog_result_array[$intCounter]['start_date']			= $invoice_data['start_date']; 
					$invoicelog_result_array[$intCounter]['end_date']			= $invoice_data['end_date']; 
					$invoicelog_result_array[$intCounter]['status']				= $invoice_data['status']; 
					$invoicelog_result_array[$intCounter]['change_date']		= $invoice_data['change_date']; 
					$invoicelog_result_array[$intCounter]['invoice_description']= $invoice_data['invoice_description']; 
					

					$intCounter++;
				}
				return 0;
			}
			else
			{
				//no records, return an error message
				$message = "No records found";
				return 1;
			}
		}
		catch (Exception $e)
		{
			# HANDle error
			$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
			return 9999;
		}
		
	}
	
	#This function is used to search invoice log search
	final public function paymentlog_search($paymentlog_search_params, &$paymentlog_result_array, &$message='')
	{		
		try
		{		
			global $dbConn;		#global database object

			$paymentlog_id		= @$paymentlog_search_params[0]['paymentlog_id'];
			$payment_amount		= @$paymentlog_search_params[0]['payment_amount'];
			$payment_note		= @$paymentlog_search_params[0]['payment_note'];
			$payment_mode		= @$paymentlog_search_params[0]['payment_mode'];
			$deduction_amount	= @$paymentlog_search_params[0]['deduction_amount'];
			$deduction_type		= @$paymentlog_search_params[0]['deduction_type'];			
			$deduction_note		= @$paymentlog_search_params[0]['deduction_note'];
			$user_id			= @$paymentlog_search_params[0]['user_id'];
			$payment_date		= @$paymentlog_search_params[0]['payment_date'];
			$login_user_id		= @$paymentlog_search_params[0]['login_user_id'];
			$change_date		= @$paymentlog_search_params[0]['change_date'];
			$payment_id			= @$paymentlog_search_params[0]['payment_id'];
									
			$limit				= @$paymentlog_search_params[0]['limit'];
			$offset				= @$paymentlog_search_params[0]['offset'];
			$order_field		= @$paymentlog_search_params[0]['order_field'];
			$order_type			= @$paymentlog_search_params[0]['order_type'];
			
			$paymentlog_search_sql	= "select a.*, b.username from tblpaymentlog as a, tblusers as b where a.login_user_id=b.userid ";
			
			if( is_numeric($paymentlog_id))				
				$paymentlog_search_sql .= " AND a.paymentlog_id	= $paymentlog_id ";

			if( is_numeric($payment_id))				
				$paymentlog_search_sql .= " AND a.payment_id = $payment_id ";
			
			if(is_numeric($login_user_id))
				$paymentlog_search_sql .= " AND b.login_user_id = $login_user_id";

			if(is_numeric($payment_amount))
				$paymentlog_search_sql .= " AND a.payment_amount	= $payment_amount ";
			
			if(trim($payment_note != ''))
				$paymentlog_search_sql .= " AND a.payment_note ilike '%$payment_note%'";

			
			if(trim($change_date != ''))				
				$paymentlog_search_sql .= " AND a.change_date	between '$change_date' ";

			if(is_numeric($status))
				$paymentlog_search_sql .= " AND a.status	= $status";
			
			//check for limit AND offsets
			$limit_text = '';
			if((is_numeric($limit)!==false) || ($limit > 0))
				$limit_text=' LIMIT ALL';
				
			$offset_text='';
			if((is_numeric($offset)!==false) || ($offset > 0))
				$offset_text=' OFFSET $offset';

			//check for field to order by
			if(is_numeric($order_field)===false)
				$order_field=0;
			
			switch($order_field)
			{
				case 0:
					$order_field='a.paymentlog_id';
					break;

				case 1:
					$order_field='a.payment_amount';
					break;

				case 2:
					$order_field='a.payment_note';
					break;

				case 3:
					$order_field='a.payment_mode';
					break;

				case 4:
					$order_field='a.deduction_amount';
					break;
				
				case 5:
					$order_field='a.user_id ';
					break;		

				default:
					$order_field='a.paymentlog_id';
					break;
			}
			
			//order type - Desc or Ascending
			if(is_numeric($order_type)===false)
				$order_type=0;

			if($order_type==1)
				$order_type="DESC";
			else
				$order_type="ASC";

			$paymentlog_search_sql .= " ORDER BY $order_field $order_type $limit_text $offset_text";
			//echo $paymentlog_search_sql;			
			
			$intCounter = 0;			
			$dbConn->ExecuteSelectQuery($paymentlog_search_sql,$rsSettings,$setting_rows);
			if($setting_rows > 0)
			{
				while($payment_data=$dbConn->GetData($rsSettings))
				{
					$paymentlog_result_array[$intCounter]['user_name']			= $payment_data['username']; 
					$paymentlog_result_array[$intCounter]['paymentlog_id']		= $payment_data['paymentlog_id'];
					$paymentlog_result_array[$intCounter]['payment_amount']		= $payment_data['payment_amount']; 
					$paymentlog_result_array[$intCounter]['payment_note']		= $payment_data['payment_note']; 
					$paymentlog_result_array[$intCounter]['payment_mode']		= $payment_data['payment_mode']; 
					$paymentlog_result_array[$intCounter]['deduction_amount']	= $payment_data['deduction_amount']; 
					$paymentlog_result_array[$intCounter]['payment_date']		= $payment_data['payment_date'];
					$paymentlog_result_array[$intCounter]['login_user_id']		= $payment_data['login_user_id']; 
					
					$paymentlog_result_array[$intCounter]['change_date']		= $payment_data['change_date']; 
					
					$intCounter++;
				}
				return 0;
			}
			else
			{
				//no records, return an error message
				$message = "No records found";
				return 1;
			}
		}
		catch (Exception $e)
		{
			# HANDle error
			$message = ShowErrorMessages($e->getMessage(),$e->getCode(),$e->getFile(),$e->getLine(),$e->getTraceAsString());
			return 9999;
		}
		
	}

}

?>