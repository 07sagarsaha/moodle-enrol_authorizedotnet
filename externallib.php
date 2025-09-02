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
 * External library for authorizedotnet.
 *
 * @package    enrol_authorizedotnet
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2021 DualCube (https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_payment\helper as payment_helper;

require_once(__DIR__ . '/authorizedotnet_helper.php');
require_once("$CFG->libdir/externallib.php");

class enrol_authorizedotnet_externallib extends external_api {

    public static function get_config_for_js_parameters() {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'Enrolment instance ID'),
        ]);
    }

    public static function get_config_for_js($instanceid) {
        global $DB;
        self::validate_parameters(self::get_config_for_js_parameters(), ['instanceid' => $instanceid]);
        $plugin = enrol_get_plugin('authorizedotnet');
        return [
            'apiloginid' => $plugin->get_config('loginid'),
            'publicclientkey' => $plugin->get_config('publicclientkey'),
            'environment' => $plugin->get_config('checkproductionmode') ? 'sandbox' : 'production',
        ];
    }

    public static function get_config_for_js_returns() {
        return new external_function_parameters([
            'apiloginid' => new external_value(PARAM_RAW, 'The API login ID for the gateway.'),
            'publicclientkey' => new external_value(PARAM_RAW, 'The public client key for the gateway.'),
            'environment' => new external_value(PARAM_RAW, 'The environment (sandbox or production).'),
        ]);
    }

    public static function process_payment_parameters() {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'The enrolment instance ID'),
            'userid' => new external_value(PARAM_INT, 'The user ID'),
            'opaquedata' => new external_value(PARAM_RAW, 'The opaque data from Authorize.net'),
        ]);
    }

    public static function process_payment(int $instanceid, int $userid, string $opaquedata): array {
        global $USER, $DB;

        self::validate_parameters(self::process_payment_parameters(), [
            'instanceid' => $instanceid,
            'userid' => $userid,
            'opaquedata' => $opaquedata,
        ]);

        $opaquedataobject = json_decode($opaquedata);
        $plugin = enrol_get_plugin('authorizedotnet');
        $cost = (float) $DB->get_field('enrol', 'cost', ['id' => $instanceid]);
        if ($cost <= 0) {
            $cost = (float) $plugin->get_config('cost');
        }

        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $courseid = $DB->get_field('enrol', 'courseid', ['id' => $instanceid]);
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

        $helper = new \enrol_authorizedotnet\authorizedotnet_helper(
            $plugin->get_config('loginid'),
            $plugin->get_config('transactionkey'),
            $plugin->get_config('checkproductionmode')
        );

        $response = $helper->create_transaction($cost, 'USD', $opaquedataobject, $user, $course);
        $success = false;
        $message = '';

        if ($response['success']) {
            $success = true;
            $context = context_course::instance($courseid);

            try {
                $data = new \stdClass();
                $data->courseid = $course->id;
                $data->userid = $user->id;
                $data->instanceid = $instanceid;
                $data->amount = $cost;
                $data->trans_id = $response['transactionid'];
                $data->timeupdated = time();
                $plugininstance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
                $plugin->enroll_user_and_send_notifications($plugininstance, $course, $context, $user, $data);
            } catch (\Exception $e) {
                debugging('Exception while trying to process payment: ' . $e->getMessage(), DEBUG_DEVELOPER);
                $success = false;
                $message = 'Internal error during enrollment.';
            }
        } else {
            $success = false;
            $message = $response['message'];
        }

        return [
            'success' => $success,
            'message' => $message,
        ];
    }

    public static function process_payment_returns() {
        return new external_function_parameters([
            'success' => new external_value(PARAM_BOOL, 'Whether everything was successful or not.'),
            'message' => new external_value(PARAM_RAW, 'Message (usually the error message).'),
        ]);
    }
}