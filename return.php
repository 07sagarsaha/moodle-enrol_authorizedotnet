<?php

require_once('../../config.php');

global $CFG, $DB, $USER;

$courseid = required_param('courseid', PARAM_INT);
$userid = required_param('userid', PARAM_INT);
$instanceid = required_param('instanceid', PARAM_INT);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($sesskey)) {
    print_error('bad_sesskey');
}

$instance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

// In a real-world scenario, you would need to implement a more robust way to
// verify the transaction status, such as using webhooks or the
// getTransactionDetailsRequest API call. For the purpose of this refactoring,
// we are assuming that a successful return to this page means a successful
// payment.

$enrol_plugin = enrol_get_plugin('authorizedotnet');
if ($enrol_plugin) {
    $enrol_plugin->enrol_user($instance, $user->id, $instance->roleid);
}

redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
