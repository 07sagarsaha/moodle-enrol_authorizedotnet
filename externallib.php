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
require_once("$CFG->dirroot/enrol/authorizedotnet/vendor/autoload.php");

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

class enrol_authorizedotnet_externallib extends external_api {

    public static function get_hosted_payment_url_parameters() {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'Enrolment instance ID'),
        ]);
    }

    public static function get_hosted_payment_url($instanceid) {
        global $CFG, $USER, $DB;

        self::validate_parameters(self::get_hosted_payment_url_parameters(), ['instanceid' => $instanceid]);

        $instance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
        $context = context_course::instance($course->id);
        self::validate_context($context);

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

        // Create the transaction request.
        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType("authCaptureTransaction");
        $transactionRequestType->setAmount($cost);

        $returnUrlParams = [
            'courseid' => $instance->courseid,
            'userid' => $USER->id,
            'instanceid' => $instance->id,
            'sesskey' => sesskey(),
        ];
        $returnUrl = new moodle_url('/enrol/authorizedotnet/return.php', $returnUrlParams);

        $setting1 = new AnetAPI\SettingType();
        $setting1->setSettingName("hostedPaymentButtonOptions");
        $setting1->setSettingValue("{\"text\": \"Pay\"}");

        $setting2 = new AnetAPI\SettingType();
        $setting2->setSettingName("hostedPaymentReturnOptions");
        $setting2->setSettingValue(
            "{\"showReceipt\": true, \"url\": \"" . $returnUrl->out(false) . "\", \"urlText\": \"Continue to Course\", \"cancelUrl\": \"" . (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false) . "\", \"cancelUrlText\": \"Cancel\"}"
        );

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
        $endpoint = $plugin->get_config('checkproductionmode') ? \net\authorize\api\constants\ANetEnvironment::SANDBOX : \net\authorize\api\constants\ANetEnvironment::PRODUCTION;
        $response = $controller->executeWithApiResponse($endpoint);

        if (($response != null) && ($response->getMessages()->getResultCode() == "Ok")) {
            $token = $response->getToken();
            $formUrl = $plugin->get_config('checkproductionmode') ? 'https://test.authorize.net/payment/payment' : 'https://accept.authorize.net/payment/payment';
            
            // Instead of a redirect, we return the URL to the JS.
            return [
                'status' => true,
                'url' => $formUrl . '?token=' . $token,
            ];
        } else {
            $errorMessages = $response->getMessages()->getMessage();
            return [
                'status' => false,
                'error' => get_string('errorheading', 'enrol_authorizedotnet') . $errorMessages[0]->getCode() . " " . $errorMessages[0]->getText(),
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