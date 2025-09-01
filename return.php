<?php
file_put_contents("/home/demo/public_html/moodledemo/enrol/authorizedotnetpro/error.log", date("d/m/Y H:i:s") . " | POST at top of return.php: " . json_encode("$_POST") . "\n", FILE_APPEND);
// This file is part of Moodle - http://moodle.org/
// GPL header omitted for brevity


require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/externallib.php');
require_login();

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants as ANetConstants;

require_once(__DIR__ . '/vendor/authorizenet/authorizenet/autoload.php');

// ========== HELPERS ==========


// ========== PROCESS PAYMENT ==========
function process_payment($userid, $instanceid) {
    global $DB, $SESSION, $CFG;

    $logfile = "/home/demo/public_html/moodledemo/enrol/authorizedotnet/error.log";
    $log = function($msg) use ($logfile) {
        file_put_contents($logfile, date("d/m/Y H:i:s") . " | " . $msg . "\n", FILE_APPEND);
    };

    $plugin = enrol_get_plugin('authorizedotnet');
    if (!$plugin) {
        $log("Plugin not found");
        redirect($CFG->wwwroot, 'Plugin not found');
    }

    $plugininstance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $plugininstance->courseid], '*', MUST_EXIST);
    $context = context_course::instance($course->id);
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

    $invoicenumber   = $SESSION->enrol_authorizedotnet_invoicenumber ?? null;
    $sessioninstance = $SESSION->enrol_authorizedotnet_instanceid ?? 0;

    $log("Start process_payment | user={$userid} | instanceid={$instanceid} | invoice={$invoicenumber}");

    if (empty($invoicenumber) || $sessioninstance != $instanceid) {
        $log("Invalid session data: sessioninstance={$sessioninstance} expected={$instanceid}");
        redirect($CFG->wwwroot, 'Invalid session data');
    }

    // Setup auth
    $merchantAuth = new AnetAPI\MerchantAuthenticationType();
    $merchantAuth->setName($plugin->get_config('loginid'));
    $merchantAuth->setTransactionKey($plugin->get_config('transactionkey'));

    $environment = $plugin->get_config('checkproductionmode')
        ? ANetConstants\ANetEnvironment::PRODUCTION
        : ANetConstants\ANetEnvironment::SANDBOX;

    $log("Using environment=" . ($plugin->get_config('checkproductionmode') ? "PRODUCTION" : "SANDBOX"));

    // Fetch unsettled transactions to find our transaction ID.
    $request = new AnetAPI\GetUnsettledTransactionListRequest();
    $request->setMerchantAuthentication($merchantAuth);
    $controller = new AnetController\GetUnsettledTransactionListController($request);
    $response = $controller->executeWithApiResponse($environment);

    $transid = null;
    if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
        if ($response->getTransactions() != null) {
            $matching_transaction = current(array_filter($response->getTransactions(), function($transaction) use ($invoicenumber) {
                return $transaction->getInvoiceNumber() == $invoicenumber;
            }));

            if ($matching_transaction) {
                $transid = $matching_transaction->getTransId();
                $log("Found matching transaction. TransID={$transid} for Invoice={$invoicenumber}");
            }
        }
    } else {
        $errorMessages = $response->getMessages()->getMessage();
        $log("Failed to get unsettled transactions. Error code: " . $errorMessages[0]->getCode() . " Error message: " . $errorMessages[0]->getText());
        redirect($CFG->wwwroot, 'Could not retrieve transaction list.');
    }

    if (empty($transid)) {
        $log("Could not find transaction for invoice number: {$invoicenumber}");
        redirect($CFG->wwwroot, 'Invalid transaction data.');
    }

    // Fetch transaction details
    $detailsReq = new AnetAPI\GetTransactionDetailsRequest();
    $detailsReq->setMerchantAuthentication($merchantAuth);
    $detailsReq->setTransId($transid);
    $detailsCtrl = new AnetController\GetTransactionDetailsController($detailsReq);
    $detailsResp = $detailsCtrl->executeWithApiResponse($environment);

    if (!$detailsResp) {
        $log("No response from GetTransactionDetails");
        redirect($CFG->wwwroot, 'Unable to get transaction details');
    }

    $log("Details resultCode=" . $detailsResp->getMessages()->getResultCode());

    if ($detailsResp->getMessages()->getResultCode() !== "Ok") {
        foreach ($detailsResp->getMessages()->getMessage() as $msg) {
            $log("API Error code=" . $msg->getCode() . " text=" . $msg->getText());
        }
        redirect($CFG->wwwroot, 'Unable to get transaction details');
    }

    $tresponse = $detailsResp->getTransaction();
    if ($tresponse) {
        $log("Transaction status=" . $tresponse->getTransactionStatus() .
             " respCode=" . $tresponse->getResponseCode() .
             " authAmt=" . $tresponse->getAuthAmount() .
             " settleAmt=" . $tresponse->getSettleAmount());
    } else {
        $log("Transaction object is null");
    }


    if ($tresponse && $tresponse->getResponseCode() == "1") { 
         $status = strtolower($tresponse->getTransactionStatus()); 
         $successStatuses = ['capturedpendingsettlement', 'settledsuccessfully']; 

         if (in_array($status, $successStatuses)) { 
             $log("Payment successful. Status={$status}. Enrolling user={$userid} in course={$course->id}"); 

             // --- START: REPLACEMENT BLOCK ---
             $data = new stdClass();
             $creditcard = $tresponse->getPayment()->getCreditCard();
             $billto = $tresponse->getBillTo();
             $customer = $tresponse->getCustomer();

             $data->item_name            = $course->fullname;
             $data->courseid             = $course->id;
             $data->userid               = $user->id;
             $data->instanceid           = $instanceid;
             $data->amount               = $tresponse->getSettleAmount() ?? $tresponse->getAuthAmount();
             $data->payment_status       = $tresponse->getTransactionStatus();
             $data->response_code        = $tresponse->getResponseCode();
             $data->response_reason_text = $tresponse->getResponseReasonDescription() ?? '';
             $data->auth_code            = $tresponse->getAuthCode();
             $data->trans_id             = $tresponse->getTransId();
             $data->method               = 'CC'; // A static value like 'CC' (Credit Card) is more suitable for the 'method' field (char(6)).
             $data->account_number       = $creditcard ? $creditcard->getCardNumber() : '';
             $data->card_type            = $creditcard ? $creditcard->getCardType() : ''; // This correctly uses the larger 'card_type' field (char(30)).
             $data->invoice_num          = $invoicenumber; // It's important to save the invoice number used for the lookup.
             $data->first_name           = $billto ? $billto->getFirstName() : '';
             $data->last_name            = $billto ? $billto->getLastName() : '';
             $data->email                = $customer ? $customer->getEmail() : '';
             $data->timeupdated          = time();
             $plugin->enroll_user_and_send_notifications($plugininstance, $course, $context, $user, $data); 

             redirect(new moodle_url('/course/view.php', ['id' => $course->id]), 
                      get_string('paymentthanks', '', $course->fullname)); 
         } else { 
             $log("Transaction approved but with non-success status={$status}. Not enrolling yet."); 
             redirect($CFG->wwwroot, 'Transaction pending settlement'); 
         }
    } else {
        $log("Transaction failed. Status=" . ($tresponse->getTransactionStatus() ?? 'N/A') .
             " ResponseCode=" . ($tresponse->getResponseCode() ?? 'N/A'));
        redirect($CFG->wwwroot, 'Transaction failed');
    }
}

// ========== DIRECT ACCESS ==========
if (!AJAX_SCRIPT) {
    global $USER, $SESSION;
    file_put_contents(
        "/home/demo/public_html/moodledemo/enrol/authorizedotnet/error.log",
        date("d/m/Y H:i:s") . " | USER->id=" . var_export($USER->id ?? null, true) .
        " | instanceid=" . var_export($SESSION->enrol_authorizedotnet_instanceid ?? null, true) .
        " | invoicenumber=" . var_export($SESSION->enrol_authorizedotnet_invoicenumber ?? null, true) . "\n",
        FILE_APPEND
    );

    if (!empty($USER->id) && !empty($SESSION->enrol_authorizedotnet_instanceid) && !empty($SESSION->enrol_authorizedotnet_invoicenumber)) {
        process_payment((int)$USER->id, (int)$SESSION->enrol_authorizedotnet_instanceid);
    } else {
        redirect(new moodle_url('/my'));
    }
}