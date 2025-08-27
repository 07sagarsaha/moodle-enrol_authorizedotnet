<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute and/or modify
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
use core_external\external_single_structure;
use moodle_url;

require_once("$CFG->libdir/externallib.php");
require_once('vendor/authorizenet/authorizenet/autoload.php');

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class enrol_authorizedotnet_externallib extends external_api {

    public static function get_hosted_payment_url_parameters() {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'Enrolment instance ID'),
            'userid' => new external_value(PARAM_INT, 'The ID of the user to be enrolled'),
        ]);
    }

    public static function get_hosted_payment_url($instanceid, $userid) {
        global $DB;

        self::validate_parameters(self::get_hosted_payment_url_parameters(), ['instanceid' => $instanceid, 'userid' => $userid]);

        $instance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);

        if ((float) $instance->cost <= 0) {
            $plugin = enrol_get_plugin('authorizedotnet');
            $cost = (float) $plugin->get_config('cost');
        } else {
            $cost = (float) $instance->cost;
        }

        if (abs($cost) < 0.01) {
            return [
                'status' => false,
                'error' => get_string('nocost', 'enrol_authorizedotnet'),
            ];
        }

        $plugin = enrol_get_plugin('authorizedotnet');
        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($plugin->get_config('loginid'));
        $merchantAuthentication->setTransactionKey($plugin->get_config('transactionkey'));
        file_put_contents("/home/demo/public_html/moodledemo/enrol/authorizedotnet/error.log",
            "LoginID: " . $plugin->get_config('loginid') .
            " | TransactionKey: " . $plugin->get_config('transactionkey') . "\n",
            FILE_APPEND
        );

        // Create the transaction request.
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType("authCaptureTransaction");
        $transactionRequestType->setAmount($cost);

        $returnUrlParams = [
            'courseid' => $instance->courseid,
            'userid' => $userid,
            'instanceid' => $instance->id,
            'sesskey' => sesskey(),
        ];
        $returnUrl = new moodle_url('/enrol/authorizedotnet/return.php', $returnUrlParams);

        $setting1 = new AnetAPI\SettingType();
        $setting1->setSettingName("hostedPaymentButtonOptions");
        $setting1->setSettingValue("{\"text\": \"Pay\"}");

        $returnUrl = new moodle_url('/enrol/authorizedotnet/return.php', $returnUrlParams);
        $cancelUrl = new moodle_url('/course/view.php', ['id' => $course->id]);

        $setting2 = new AnetAPI\SettingType();
        $setting2->setSettingName("hostedPaymentReturnOptions");
        $setting2->setSettingValue(json_encode([
            "showReceipt" => true,
            "url" => $returnUrl->out(false),   // false = no escaping
            "urlText" => "Continue to Course",
            "cancelUrl" => $cancelUrl->out(false),
            "cancelUrlText" => "Cancel"
        ]));

        $setting3 = new AnetAPI\SettingType();
        $setting3->setSettingName("hostedPaymentPaymentOptions");
        $setting3->setSettingValue("{\"cardCodeRequired\": false, \"showCreditCard\": true, \"showBankAccount\": false}");

        $request = new AnetAPI\GetHostedPaymentPageRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setTransactionRequest($transactionRequestType);
        $request->addToHostedPaymentSettings($setting1);
        $request->addToHostedPaymentSettings($setting2);
        $request->addToHostedPaymentSettings($setting3);

        $controller = new AnetController\GetHostedPaymentPageController($request);
        $useSandbox = (bool)$plugin->get_config('checkproductionmode');

        $endpoint = \net\authorize\api\constants\ANetEnvironment::SANDBOX ;
        
        // Execute the API call.
        $response = $controller->executeWithApiResponse($endpoint);

        // Check for a valid response and token.
        if ($response && $response->getMessages()->getResultCode() === "Ok") {
            $token = $response->getToken();
            
            // Log the successful token generation.
            file_put_contents( "/home/demo/public_html/moodledemo/enrol/authorizedotnet/error.log", date("d/m/Y H:i:s", time()) . ":session_params:  : " . var_export($token, true) . "\n", FILE_APPEND);

            $formUrl = $useSandbox
                ? 'https://test.authorize.net/payment/payment'
                : 'https://accept.authorize.net/payment/payment';

            return [
                'status' => true,
                'url' => $formUrl . '?token=' . rawurlencode($token),
            ];
        } else {
            // Log the detailed error from the API response for debugging.
            $errorMessages = $response->getMessages()->getMessage();
            $errorMessageText = '';
            if (is_array($errorMessages)) {
                foreach ($errorMessages as $msg) {
                    $errorMessageText .= 'Code: ' . $msg->getCode() . ', Text: ' . $msg->getText() . '; ';
                }
            } else {
                $errorMessageText = 'An unknown API error occurred.';
            }

            file_put_contents( "/home/demo/public_html/moodledemo/enrol/authorizedotnet/error.log", date("d/m/Y H:i:s", time()) . ":API Error: " . $errorMessageText . "\n", FILE_APPEND);

            return [
                'status' => false,
                'error' => get_string('errorheading', 'enrol_authorizedotnet') . $errorMessageText,
            ];
        }
    }

    public static function get_hosted_payment_url_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Status of the request'),
            'url' => new external_value(PARAM_URL, 'The URL for the hosted payment page', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}