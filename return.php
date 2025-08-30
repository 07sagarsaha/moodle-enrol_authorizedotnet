<?php
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
function enroll_user_and_send_notifications($plugininstance, $course, $context, $user, $enrollmentdata) {
    global $DB;
    $plugin = enrol_get_plugin('authorizedotnet');
    $DB->insert_record('enrol_authorizedotnet', $enrollmentdata);
    $timestart = time();
    $timeend = $plugininstance->enrolperiod ? $timestart + $plugininstance->enrolperiod : 0;
    $plugin->enrol_user($plugininstance, $user->id, $plugininstance->roleid, $timestart, $timeend);
    send_enrollment_notifications($course, $context, $user, $plugin);
    return true;
}

function send_message_custom($course, $userfrom, $userto, $subject, $orderdetails, $shortname, $fullmessage, $fullmessagehtml) {
    $recipients = is_array($userto) ? $userto : [$userto];
    foreach ($recipients as $recipient) {
        $message = new \core\message\message();
        $message->courseid = $course->id;
        $message->component = 'enrol_authorizedotnet';
        $message->name = 'authorizedotnet_enrolment';
        $message->userfrom = $userfrom;
        $message->userto = $recipient;
        $message->subject = $subject;
        $message->fullmessage = $fullmessage;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $fullmessagehtml;
        $message->smallmessage = get_string('newenrolment', 'enrol_authorizedotnet', $shortname);
        $message->notification = 1;
        $message->contexturl = new moodle_url('/course/view.php', ['id' => $course->id]);
        $message->contexturlname = $orderdetails->coursename;
        message_send($message);
    }
}

function send_enrollment_notifications($course, $context, $user, $plugin) {
    global $CFG;
    $teacher = false;
    if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC', '', '', '', '', false, true)) {
        $users = sort_by_roleassignment_authority($users, $context);
        $teacher = array_shift($users);
    }

    $mailstudents = $plugin->get_config('mailstudents');
    $mailteachers = $plugin->get_config('mailteachers');
    $mailadmins   = $plugin->get_config('mailadmins');

    $shortname = format_string($course->shortname, true, ['context' => $context]);
    $coursecontext = context_course::instance($course->id);
    $orderdetails = new stdClass();
    $orderdetails->coursename = format_string($course->fullname, true, ['context' => $coursecontext]);

    $sitename = $CFG->sitename;

    if (!empty($mailstudents)) {
        $userfrom = empty($teacher) ? core_user::get_noreply_user() : $teacher;
        $fullmessage = get_string('welcometocoursetext', 'enrol_authorizedotnet', [
            'course' => $course->fullname,
            'sitename' => $sitename,
        ]);
        $subject = get_string('enrolmentuser', 'enrol_authorizedotnet', $shortname);
        send_message_custom($course, $userfrom, $user, $subject, $orderdetails, $shortname, $fullmessage, '<p>' . $fullmessage . '</p>');
    }

    if (!empty($mailteachers) && !empty($teacher)) {
        $fullmessage = get_string('adminmessage', 'enrol_authorizedotnet', [
            'username' => fullname($user),
            'course' => $course->fullname,
            'sitename' => $sitename
        ]);
        $subject = get_string('enrolmentnew', 'enrol_authorizedotnet', [
            'username' => fullname($user),
            'course' => $course->fullname,
        ]);
        send_message_custom($course, $user, $teacher, $subject, $orderdetails, $shortname, $fullmessage, '<p>' . $fullmessage . '</p>');
    }

    if (!empty($mailadmins)) {
        $admins = get_admins();
        $fullmessage = get_string('adminmessage', 'enrol_authorizedotnet', [
            'username' => fullname($user),
            'course' => $course->fullname,
            'sitename' => $sitename
        ]);
        $subject = get_string('enrolmentnew', 'enrol_authorizedotnet', [
            'username' => fullname($user),
            'course' => $course->fullname,
        ]);
        send_message_custom($course, $user, $admins, $subject, $orderdetails, $shortname, $fullmessage, '<p>' . $fullmessage . '</p>');
    }
}

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

    // Find transaction by invoice number
    $txnlistReq = new AnetAPI\GetUnsettledTransactionListRequest();
    $txnlistReq->setMerchantAuthentication($merchantAuth);
    $txnlistCtrl = new AnetController\GetUnsettledTransactionListController($txnlistReq);
    $txnlistResp = $txnlistCtrl->executeWithApiResponse($environment);

    $transid = null;
    if ($txnlistResp) {
        $log("UnsettledTxnList resultCode=" . $txnlistResp->getMessages()->getResultCode());
        foreach ($txnlistResp->getTransactions() as $txn) {
            $log("Checking txn id={$txn->getTransId()} invoice={$txn->getInvoiceNumber()} status={$txn->getTransactionStatus()}");
            if ($txn->getInvoiceNumber() === $invoicenumber) {
                $transid = $txn->getTransId();
                $log("Match found! transid={$transid}");
                break;
            }
        }
    } else {
        $log("No response from GetUnsettledTransactionList");
    }

    if (!$transid) {
        $log("Unable to locate transaction for invoice {$invoicenumber}");
        redirect($CFG->wwwroot, 'Unable to locate transaction for invoice ' . $invoicenumber);
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
             $data->response_reason_text = $tresponse->getMessages()[0]->getDescription() ?? '';
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
             enroll_user_and_send_notifications($plugininstance, $course, $context, $user, $data); 

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
    $transactionId = $_POST['transId'] ?? null;
    file_put_contents( "/home/demo/public_html/moodledemo/enrol/authorizedotnet/error.log", date("d/m/Y H:i:s", time()) . ":response:  : " . var_export($transactionId, true) . "\n", FILE_APPEND);
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
