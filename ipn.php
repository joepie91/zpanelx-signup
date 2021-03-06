<?php
/**
 * This a simple ipn for paypal
 *
 *  @package    Zpanelx Auto-sign-up
 *  @author     Martin Kollerup
 *  @license    http://opensource.org/licenses/gpl-3.0.html
 *  @link       build on http://www.micahcarrick.com/paypal-ipn-with-php.html
 */
 
error_reporting(E_ALL);
ini_set('log_errors', true);
ini_set('error_log', dirname(__FILE__).'/log/ipn_errors.log');


include('lib/db.php');
include('lib/functions.php');
include('lib/ipnlistener.php');
include('lib/xmwsclient.class.php');
$listener = new IpnListener();
$listener->use_sandbox = zpanelx::getConfig('test');

/*
To post over standard HTTP connection, use:
$listener->use_ssl = false;

To post using the fsockopen() function rather than cURL, use:
$listener->use_curl = false;
*/

//send request to paypal and will verify
try {
    $listener->requirePostMethod();
    $verified = $listener->processIpn();
} catch (Exception $e) {
    error_log($e->getMessage());
    exit(0);
}


/*
The processIpn() method returned true if the IPN was "VERIFIED" and false if it
was "INVALID".
*/
if ($verified) {

    $item_name = $_POST['item_name'];
    $invoice = $_POST['invoice'];
    $payment_status = $_POST['payment_status'];
    $payment_amount = $_POST['mc_gross'];
    $payment_currency = $_POST['mc_currency'];
    $txn_id = $_POST['txn_id'];
    $receiver_email = $_POST['receiver_email'];
    $business = $_POST['business'];
    $payer_email = $_POST['payer_email'];

    if($business != zpanelx::getConfig('email_paypal')){
        zpanelx::error("INVALID PAYMENT: A wrong paypal email have been used: ".$business." and invoice id: ".$invoice);  
    }
    if($payment_currency != zpanelx::getConfig('cs')){
        zpanelx::error("INVALID PAYMENT: Paypal returned a wrong currency(".$payment_currency.") relative to the settings. Invice id: ".$invoice);  
    }

    //Check if the invoice id exits or have been paid
    $data     = "<token>".$invoice."</token>";
    $invoice  = zpanelx::api("reseller_billing", "Invoice", $data, zpanelx::getConfig('zpanel_url'), zpanelx::getConfig('api'));

    if($invoice['xmws']['content']['code'] == "0"){
        zpanelx::error("Invoice id was not found");
    } 
    elseif($invoice['xmws']['content']['code'] == "1"){
        $inv_user      = $invoice['xmws']['content']['invoice']['user'];
        $inv_desc      = $invoice['xmws']['content']['invoice']['desc'];
        $inv_amount    = $invoice['xmws']['content']['invoice']['amount'];
        $inv_id        = $invoice['xmws']['content']['invoice']['id'];
        $inv_status    = $invoice['xmws']['content']['invoice']['status'];

    }
    else{
        zpanelx::error("Invoice data could not be loaded");
    }

    if(!$inv_user){
        //Forcing to show the error
        zpanelx::error("Invoice id was not found in the system");
    } 
    elseif($status == "1"){
        //FOrcing to show the error
        zpanelx::error("This invoice has already been paid.");
    }
    
    //Do the user have paid the same as we want!?
    //TODO: Add something with tax
    if ($inv_amount != $_POST['mc_gross']) {
        zpanelx::error("INVALID PAYMENT: ".$invoice." (invoice number) - ".$payment_amount." (payment received) - ".$inv_amount." (invoice amount)");  
    }

    $data = "<method>Paypal</method><user_id>".$inv_user."</user_id><txn_id>".$txn_id."</txn_id><token>".$invoice."</token>";
    $invoice = zpanelx::api("reseller_billing", "Payment", $data, zpanelx::getConfig('zpanel_url'), zpanelx::getConfig('api'));

    switch($invoice['xmws']['content']['code']){
        case "1":
            zpanelx::sendWelcomeMail($inv_user);
        break;
        case "2":
            zpanelx::error("PAYMENT ERROR: Could not create invoice");
        break;
        case "3":
            zpanelx::error("PAYMENT ERROR: Could not select inv_desc");
        break;
        case "4":
            zpanelx::error("PAYMENT ERROR: Could not activate user");
        break;
        case "5":
            zpanelx::error("PAYMENT ERROR: Could notadd to x_rb_billing");
        break;
    }

    if(!empty(zpanelx::$zerror)){
        zpanelx::sendemail(zpanelx::getConfig('email_paypal_error'),"Invalid payment received", 
            implode('<br />',zpanelx::$zerror)."<br /><div style=\"white-space: pre;\">".$listener->getTextReport()."</div>");
    }

} else {
    //there have been some problem with the Payment.. There have been sent a report to the admin.
    zpanelx::sendmail(zpanelx::getConfig('email_paypal_error'), 'Invalid IPN', $listener->getTextReport());
}
?>
