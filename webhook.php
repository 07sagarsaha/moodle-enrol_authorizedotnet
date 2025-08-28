<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/enrollib.php');

$log_file = __DIR__ . "/error.log";
$payload = file_get_contents("php://input");
file_put_contents($log_file, date("d/m/Y H:i:s") . " RAW Webhook: " . $payload . "\n", FILE_APPEND);

// Decode webhook JSON
$data = json_decode($payload, true);
if (!$data || empty($data['payload'])) {
    http_response_code(400);
    exit("Invalid payload");
}

// Extract transaction info
$transId = $data['payload']['id'] ?? null;
$status  = $data['payload']['transactionStatus'] ?? null;
$invoice = $data['payload']['invoiceNumber'] ?? null;

file_put_contents($log_file, date("d/m/Y H:i:s") . " Parsed Webhook: transId=$transId, status=$status, invoice=$invoice\n", FILE_APPEND);

// Only continue on approved/settled
if ($status !== "settledSuccessfully" && $status !== "approved") {
    http_response_code(200);
    exit("Ignored status: $status");
}

// Our invoice was structured as userId-courseId-timestamp
if ($invoice) {
    [$userid, $courseid] = explode('-', $invoice);
    $userid = (int)$userid;
    $courseid = (int)$courseid;

    // Find the enrol instance for this course
    $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);

    if ($instance) {
        $plugin = enrol_get_plugin('authorizedotnet');
        $plugin->enrol_user($instance, $userid, $instance->roleid, time(), 0);

        file_put_contents($log_file, date("d/m/Y H:i:s") . " Enrolled user $userid into course $courseid after payment $transId\n", FILE_APPEND);
    }
}

// Always return 200 to Authorize.Net
http_response_code(200);
echo "OK";
