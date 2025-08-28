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
if ($status !== "settledSuccessfully" && $status !== "approved" && $status !== "capturedPendingSettlement") {
    http_response_code(200);
    exit("Ignored status: $status");
}

// Our invoice was structured as userId-courseId-timestamp
if ($invoice) {
    // Use `sscanf` for more reliable parsing of a specific format.
    // It will extract the first two integers, which are the user and course IDs.
    $parts = sscanf($invoice, '%d-%d-%d');
    if (count($parts) >= 2) {
        $userid = (int)$parts[0];
        $courseid = (int)$parts[1];
    } else {
        file_put_contents($log_file, date("d/m/Y H:i:s") . " ERROR: Invalid invoice format: $invoice\n", FILE_APPEND);
        http_response_code(400);
        exit("Invalid invoice format");
    }

    if ($userid > 0 && $courseid > 0) {
        // Find the enrol instance for this course
        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
        
        if ($instance) {
            $plugin = enrol_get_plugin('authorizedotnet');
            $plugin->enrol_user($instance, $userid, $instance->roleid, time(), 0);

            file_put_contents($log_file, date("d/m/Y H:i:s") . " Enrolled user $userid into course $courseid after payment $transId\n", FILE_APPEND);
        } else {
            file_put_contents($log_file, date("d/m/Y H:i:s") . " ERROR: Enrol instance not found for course $courseid\n", FILE_APPEND);
            http_response_code(400);
            exit("Enrol instance not found");
        }
    } else {
        file_put_contents($log_file, date("d/m/Y H:i:s") . " ERROR: User or course ID invalid: User=$userid, Course=$courseid\n", FILE_APPEND);
        http_response_code(400);
        exit("Invalid user or course ID");
    }
} else {
    file_put_contents($log_file, date("d/m/Y H:i:s") . " ERROR: Invoice number is missing in webhook payload.\n", FILE_APPEND);
    http_response_code(400);
    exit("Invoice number missing");
}

// Always return 200 to Authorize.Net
http_response_code(200);
echo "OK";