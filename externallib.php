<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * External library for authorizedotnet
 *
 * @package    enrol_authorizedotnet
 * @author     Your Name
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

require_once("$CFG->libdir/enrollib.php");

class enrol_authorizedotnet_external extends external_api {

    public static function process_payment_parameters() {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'Enrolment instance ID'),
            'paymentdata' => new external_single_structure([
                'cardnumber' => new external_value(PARAM_RAW, 'Card number'),
                'expmonth' => new external_value(PARAM_RAW, 'Expiry month'),
                'expyear' => new external_value(PARAM_RAW, 'Expiry year'),
                'cardcode' => new external_value(PARAM_RAW, 'Card code (CVV)'),
                'firstname' => new external_value(PARAM_TEXT, 'First name'),
                'lastname' => new external_value(PARAM_TEXT, 'Last name'),
                'email' => new external_value(PARAM_EMAIL, 'Email'),
                'phone' => new external_value(PARAM_TEXT, 'Phone number', VALUE_OPTIONAL),
                'address' => new external_value(PARAM_TEXT, 'Address'),
                'city' => new external_value(PARAM_TEXT, 'City'),
                'state' => new external_value(PARAM_TEXT, 'State'),
                'zip' => new external_value(PARAM_TEXT, 'ZIP code'),
                'country' => new external_value(PARAM_TEXT, 'Country'),
            ])
        ]);
    }

    public static function process_payment_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'True if payment is successful'),
            'redirecturl' => new external_value(PARAM_URL, 'URL to redirect to after payment', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL)
        ]);
    }

    public static function process_payment($instanceid, $paymentdata) {
        global $CFG, $DB, $USER;

        self::validate_context(context_user::instance($USER->id));

        $instance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
        $context = context_course::instance($course->id);

        $plugin = enrol_get_plugin('authorizedotnet');

        // Here you would typically call the Authorize.Net SDK to process the payment.
        // For this example, we will simulate a successful payment and enrol the user.

        $paymentprocess = new \enrol_authorizedotnet_payment_process((object)$paymentdata, $course->id, $USER->id, $instance->id);
        $result = $paymentprocess->process_enrolment();

        if ($result) {
            return [
                'status' => true,
                'redirecturl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false)
            ];
        } else {
            return [
                'status' => false,
                'message' => get_string('paymentfailed', 'enrol_authorizedotnet')
            ];
        }
    }
}
