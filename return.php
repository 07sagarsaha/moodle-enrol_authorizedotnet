<?php
require_once('../../../config.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->dirroot . '/enrol/authorizedotnet/vendor/authorizenet/authorizenet/autoload.php');

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

$courseid = required_param('courseid', PARAM_INT);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);

$PAGE->set_url('/enrol/authorizedotnet/return.php', ['courseid' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('paymentverification', 'enrol_authorizedotnet'));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

if (!isset($SESSION->enrol_authorizedotnet_invoicenumber) || !isset($SESSION->enrol_authorizedotnet_instanceid)) {
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]));
}

$invoiceNumber = $SESSION->enrol_authorizedotnet_invoicenumber;
$instanceid = $SESSION->enrol_authorizedotnet_instanceid;

// Clean up session.
unset($SESSION->enrol_authorizedotnet_invoicenumber);
unset($SESSION->enrol_authorizedotnet_instanceid);

$plugin = enrol_get_plugin('authorizedotnet');
$merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
$merchantAuthentication->setName($plugin->get_config('loginid'));
$merchantAuthentication->setTransactionKey($plugin->get_config('transactionkey'));

// TODO: ideally you should store transactionId in session and call GetTransactionDetailsRequest
$request = new AnetAPI\GetUnsettledTransactionListRequest();
$request->setMerchantAuthentication($merchantAuthentication);

$controller = new AnetController\GetUnsettledTransactionListController($request);
$useSandbox = !(bool)$plugin->get_config('checkproductionmode');
$endpoint = $useSandbox ? \net\authorize\api\constants\ANetEnvironment::SANDBOX : \net\authorize\api\constants\ANetEnvironment::PRODUCTION;

$response = $controller->executeWithApiResponse($endpoint);

$transactionFound = false;
if ($response && $response->getMessages()->getResultCode() == "Ok" && $response->getTransactions() != null) {
    foreach ($response->getTransactions() as $transaction) {
        if ($transaction->getInvoiceNumber() == $invoiceNumber) {
            $transactionFound = true;
            $instance = $DB->get_record('enrol', ['id' => $instanceid], '*', MUST_EXIST);
            $user = $DB->get_record('user', ['id' => $USER->id], '*', MUST_EXIST);

            $timestart = time();
            $timeend = $instance->enrolperiod ? $timestart + $instance->enrolperiod : 0;

            $plugin->enrol_user($instance, $user->id, $instance->roleid, $timestart, $timeend);

            // Find a teacher.
            $teachers = get_role_users($instance->roleid, $context);
            $teacher = !empty($teachers) ? reset($teachers) : core_user::get_noreply_user();

            // Notify user.
            $a = new stdClass();
            $a->course = format_string($course->fullname, true, ['context' => $context]);
            $a->user = fullname($user);
            $a->teacher = fullname($teacher);

            $subject = get_string('enrolmentnew', 'enrol', $a->course);
            $message = get_string('welcometocoursetext', 'enrol_authorizedotnet', $a);

            message_send(new \core\message\message([
                'userfrom' => $teacher,
                'userto' => $user,
                'subject' => $subject,
                'fullmessage' => $message,
                'fullmessageformat' => FORMAT_HTML,
                'fullmessagehtml' => $message,
                'smallmessage' => '',
                'notification' => 1,
            ]));

            redirect(new moodle_url('/course/view.php', ['id' => $course->id]), get_string('paymentthanks', 'enrol_authorizedotnet'), 5);
            break;
        }
    }
}

if (!$transactionFound) {
    echo $OUTPUT->notification(get_string('paymentprocessing', 'enrol_authorizedotnet'));
    echo $OUTPUT->continue_button(new moodle_url('/course/view.php', ['id' => $course->id]));
}

echo $OUTPUT->footer();
