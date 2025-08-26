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
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * External library for authorizedotnet using Accept Hosted.
 *
 * This file handles two main API calls:
 * 1. Generating a secure payment form token for Accept Hosted.
 * 2. Finalizing the enrolment after the user successfully completes the hosted form.
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
use moodle_url;

require_once("$CFG->libdir/enrollib.php");
require_once(__DIR__ . '/classes/enrol_authorizedotnet_paymentprocess.php');

class enrol_authorizedotnet_external extends external_api {

    /**
     * Define the parameters for the hosted form token request.
     *
     * @return external_function_parameters
     */
    public static function get_hosted_form_token_parameters() {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'Enrolment instance ID'),
        ]);
    }

    /**
     * Define the return value for the hosted form token request.
     *
     * @return external_single_structure
     */
    public static function get_hosted_form_token_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'True if token is successful'),
            'token' => new external_value(PARAM_RAW, 'The hosted form token', VALUE_OPTIONAL),
            'redirecturl' => new external_value(PARAM_URL, 'URL to redirect after payment', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL)
        ]);
    }

    /**
     * Generates a hosted payment form token from Authorize.Net.
     *
     * @param int $instanceid The enrolment instance ID.
     * @return array
     */
    public static function get_hosted_form_token($instanceid) {
        global $CFG, $DB, $USER;

        self::validate_context(context_user::instance($USER->id));

        $instance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);

        $paymentprocess = new \enrol_authorizedotnet_payment_process(
            $instance->cost,  // Use the enrol instance cost
            $course->id,
            $USER->id,
            $instance        // pass full object, not just id
        );


        try {
            $token = $paymentprocess->create_hosted_payment_token();

            return [
                'status' => true,
                'token' => $token
            ];

        } catch (\Exception $e) {
            $message = $e->getMessage();
            return [
                'status' => false,
                'message' => $message
            ];
        }
    }

    /**
     * Define the parameters for finalizing the enrollment.
     *
     * @return external_function_parameters
     */
    public static function finalize_enrollment_parameters() {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'Enrolment instance ID'),
            'dataDescriptor' => new external_value(PARAM_RAW, 'The data descriptor from Authorize.Net'),
            'dataValue' => new external_value(PARAM_RAW, 'The data value from Authorize.Net')
        ]);
    }

    /**
     * Define the return value for finalizing the enrollment.
     *
     * @return external_single_structure
     */
    public static function finalize_enrollment_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'True if enrollment is successful'),
            'redirecturl' => new external_value(PARAM_URL, 'URL to redirect to after enrollment', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL)
        ]);
    }

    /**
     * Finalizes the payment and enrollment after the user completes the hosted form.
     *
     * @param int $instanceid The enrolment instance ID.
     * @param string $dataDescriptor The data descriptor from the hosted form.
     * @param string $dataValue The data value from the hosted form.
     * @return array
     */
    public static function finalize_enrollment($instanceid, $dataDescriptor, $dataValue) {
        global $CFG, $DB, $USER;

        self::validate_context(context_user::instance($USER->id));

        $instance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);

        $paymentprocess = new \enrol_authorizedotnet_payment_process(
            $instance->cost,
            $course->id,
            $USER->id,
            $instance
        );


        try {
            // Process the enrollment using the data from the hosted form.
            $paymentprocess->finalize_enrollment($dataDescriptor, $dataValue);

            // If we reach this point, both payment and enrollment were successful.
            return [
                'status' => true,
                'redirecturl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false)
            ];

        } catch (\Exception $e) {
            $message = $e->getMessage();
            return [
                'status' => false,
                'message' => $message
            ];
        }
    }
}
