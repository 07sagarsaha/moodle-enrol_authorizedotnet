<?php

require_once('../../../config.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/enrol/authorizedotnet/vendor/authorizenet/authorizenet/autoload.php');

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id);

$PAGE->set_url('/enrol/authorizedotnet/return.php', ['courseid' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('paymentverification', 'enrol_authorizedotnet'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

if (!isset($SESSION->enrol_authorizedotnet_invoicenumber) || !isset($SESSION->enrol_authorizedotnet_instanceid)) {
    // Something is wrong, maybe the user came here directly.
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
}

$invoiceNumber = $SESSION->enrol_authorizedotnet_invoicenumber;
$instanceid = $SESSION->enrol_authorizedotnet_instanceid;

// Clean up session variables.
unset($SESSION->enrol_authorizedotnet_invoicenumber);
unset($SESSION->enrol_authorizedotnet_instanceid);

$plugin = enrol_get_plugin('authorizedotnet');
$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
$merchantAuthentication->setName($plugin->get_config('loginid'));
$merchantAuthentication->setTransactionKey($plugin->get_config('transactionkey'));

// Set up the request to get a list of unsettled transactions.
$request = new AnetAPI\GetUnsettledTransactionListRequest();
$request->setMerchantAuthentication($merchantAuthentication);

$controller = new AnetController\GetUnsettledTransactionListController($request);
$useSandbox = !(bool)$plugin->get_config('checkproductionmode');
$endpoint = $useSandbox ? \net\authorize\api\constants\ANetEnvironment::SANDBOX : \net\authorize\api\constants\ANetEnvironment::PRODUCTION;

$response = $controller->executeWithApiResponse($endpoint);

$transactionFound = false;
if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
    if ($response->getTransactions() != null) {
        foreach ($response->getTransactions() as $transaction) {
            if ($transaction->getInvoiceNumber() == $invoiceNumber) {
                // Found the transaction. We can assume it was successful because it's in the unsettled list.
                $transactionFound = true;
                $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
                $user = $DB->get_record('user', ['id' => $USER->id], '*', MUST_EXIST);

                if ($instance->enrolperiod) {
                    $timestart = time();
                    $timeend = $timestart + $instance->enrolperiod;
                } else {
                    $timestart = 0;
                    $timeend = 0;
                }

                // Enrol the user.
                $plugin->enrol_user($instance, $user->id, $instance->roleid, $timestart, $timeend);

                // Send notification.
                $teacher = get_course_teacher($course->id);
                $mailstudents = $plugin->get_config('mailstudents');
                if (!empty($mailstudents)) {
                    $a = new stdClass();
                    $a->course = format_string($course->fullname, true, array('context' => $context));
                    $a->user = fullname($user);
                    $a->teacher = fullname($teacher);
                    $a->teacherrole = get_string('teacher');
                    
                    $subject = get_string('enrolmentnew', 'enrol', $a->course);
                    $message = get_string('welcometocoursetext', 'enrol_authorizedotnet', $a);
                    
                    $userfrom = empty($teacher) ? core_user::get_noreply_user() : $teacher;
                    
                    message_send(new \core\message\message([
                        'userfrom' => $userfrom,
                        'userto' => $user,
                        'subject' => $subject,
                        'fullmessage' => $message,
                        'fullmessageformat' => FORMAT_HTML,
                        'fullmessagehtml' => $message,
                        'smallmessage' => '',
                        'notification' => 1,
                    ]));
                }

                redirect(new moodle_url('/course/view.php', ['id' => $course->id]), get_string('paymentthanks', 'enrol_authorizedotnet'), 5);
                break;
            }
        }
    }
}

if (!$transactionFound) {
    // Transaction not found or API error.
    echo $OUTPUT->notification(get_string('paymentprocessing', 'enrol_authorizedotnet'));
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $course->id]));
}

echo $OUTPUT->footer();
