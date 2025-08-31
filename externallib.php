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
use core_external\external_single_structure;
use moodle_url;

require_once("$CFG->libdir/externallib.php");
require_once('vendor/authorizenet/authorizenet/autoload.php');

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants as ANetConstants;

class enrol_authorizedotnet_externallib extends external_api {

    

    public static function get_hosted_payment_url_parameters() {
        return new external_function_parameters([
            'instanceid' => new external_value(PARAM_INT, 'Enrolment instance ID'),
            'userid' => new external_value(PARAM_INT, 'The ID of the user to be enrolled'),
        ]);
    }

    public static function get_hosted_payment_url($instanceid, $userid) {
        global $DB, $CFG;

        self::validate_parameters(self::get_hosted_payment_url_parameters(), ['instanceid' => $instanceid, 'userid' => $userid]);
        $instance = $DB->get_record('enrol', ['id' => $instanceid, 'enrol' => 'authorizedotnet'], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $instance->courseid], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

        // Determine the payment cost.
        $cost = (float) $instance->cost;
        if ($cost <= 0) {
            $plugin = enrol_get_plugin('authorizedotnet');
            $cost = (float) $plugin->get_config('cost');
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

        // Add order information.
        $order = new AnetAPI\OrderType();
        $invoiceNumber = $user->id . '-' . $course->id . '-' . time();
        $order->setInvoiceNumber($invoiceNumber);
        $order->setDescription($course->fullname);
        $transactionRequestType->setOrder($order);

        // Store the invoice number in the session to verify payment on return.
        global $SESSION;
        $SESSION->enrol_authorizedotnet_invoicenumber = $invoiceNumber;
        $SESSION->enrol_authorizedotnet_instanceid = $instanceid;

        // Add customer data.
        $customer = new AnetAPI\CustomerDataType();
        $customer->setId($user->id);
        $customer->setEmail($user->email);

        // Add billing information.
        $billTo = new AnetAPI\CustomerAddressType();
        $billTo->setFirstName($user->firstname);
        $billTo->setLastName($user->lastname);
        $billTo->setCompany(!empty($user->institution) ? $user->institution : '');
        $billTo->setAddress(!empty($user->address) ? $user->address : 'N/A');
        $billTo->setCity(!empty($user->city) ? $user->city : 'N/A');
        $billTo->setZip(!empty($user->zip) ? $user->zip : '00000');
        $billTo->setCountry(!empty($user->country) ? $user->country : 'US');
        $billTo->setPhoneNumber(!empty($user->phone1) ? $user->phone1 : '');
        $billTo->setEmail($user->email);

        $transactionRequestType->setCustomer($customer);
        $transactionRequestType->setBillTo($billTo);

        // Configure hosted payment page settings.
        $setting1 = new AnetAPI\SettingType();
        $setting1->setSettingName("hostedPaymentButtonOptions");
        $setting1->setSettingValue("{\"text\": \"Pay Now\"}");

        // Set up the return URL to our custom verification page.
        $cancelUrl = new moodle_url('/course/view.php', ['id' => $course->id]);
        $returnUrl = new moodle_url('/enrol/authorizedotnet/return.php');

        $setting2 = new AnetAPI\SettingType();
        $setting2->setSettingName("hostedPaymentReturnOptions");
        $setting2->setSettingValue(json_encode([
            "showReceipt" => true,
            "url" => $returnUrl->out(false),
            "urlText" => "Continue to Course",
            "cancelUrl" => $cancelUrl->out(false),
            "cancelUrlText" => "Cancel and Return to Course"
        ]));

        // The third setting is now setting3.
        $setting3 = new AnetAPI\SettingType();
        $setting3->setSettingName("hostedPaymentPaymentOptions");
        $setting3->setSettingValue("{\"cardCodeRequired\": false, \"showCreditCard\": true, \"showBankAccount\": false}");

        // Build the final request.
        $request = new AnetAPI\GetHostedPaymentPageRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setTransactionRequest($transactionRequestType);
        $request->addToHostedPaymentSettings($setting1);
        $request->addToHostedPaymentSettings($setting2);
        $request->addToHostedPaymentSettings($setting3);

        $controller = new AnetController\GetHostedPaymentPageController($request);
        $useSandbox = !(bool)$plugin->get_config('checkproductionmode');
        $endpoint = $useSandbox ? ANetConstants\ANetEnvironment::SANDBOX : ANetConstants\ANetEnvironment::PRODUCTION;

        $response = $controller->executeWithApiResponse($endpoint);

        if ($response != null && $response->getMessages()->getResultCode() == "Ok") {
            $token = $response->getToken();
            $formUrl = $useSandbox
                ? 'https://test.authorize.net/payment/payment'
                : 'https://accept.authorize.net/payment/payment';

            return [
                'status' => true,
                'formurl' => $formUrl,
                'token' => $token,
            ];
        } else {
            $errorMessages = $response->getMessages()->getMessage();
            $errorMessageText = 'Error: ' . $errorMessages[0]->getCode() . " " . $errorMessages[0]->getText();

            return [
                'status' => false,
                'error' => get_string('errorheading', 'enrol_authorizedotnet') . ' ' . $errorMessageText,
            ];
        }
    }

    public static function get_hosted_payment_url_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_BOOL, 'Status of the request'),
            'formurl' => new external_value(PARAM_URL, 'The URL for the payment form submission', VALUE_OPTIONAL),
            'token' => new external_value(PARAM_RAW, 'The payment token', VALUE_OPTIONAL),
            'error' => new external_value(PARAM_TEXT, 'Error message', VALUE_OPTIONAL),
        ]);
    }
}