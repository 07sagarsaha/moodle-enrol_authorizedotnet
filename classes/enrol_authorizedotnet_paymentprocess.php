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
 * authorize.net payment process library.
 *
 * @package    enrol_authorizedotnet
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2024 DualCube Team (https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;
use net\authorize\api\constants\ANetEnvironment;
use net\authorize\api\contract\v1\TransactionResponseType;
require_once(__DIR__ . '/../vendor/autoload.php');

class enrol_authorizedotnet_payment_process {
    protected $paymentdata;
    protected $courseid;
    protected $userid;
    protected $instanceid;

   public function __construct($paymentdata, $courseid, $userid, $instance) {
        $this->paymentdata = $paymentdata;
        $this->courseid = $courseid;
        $this->userid = $userid;
        $this->instance = $instance;
    }


    /**
     * @return AnetAPI\MerchantAuthenticationType
     */
    protected function get_merchant_authentication() {
        global $CFG;

        $auth = new AnetAPI\MerchantAuthenticationType();
        $auth->setName($this->get_config('loginid'));
        $auth->setTransactionKey($this->get_config('transactionkey'));

        return $auth;
    }

    /**
     * @param string $endpoint
     * @return string
     */
    protected function get_endpoint($endpoint) {
        if ($this->get_config('checkproductionmode') == 1) { // 1 means sandbox mode.
            return ANetEnvironment::SANDBOX;
        }
        return ANetEnvironment::PRODUCTION;
    }


    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function get_config($key, $default = null) {
        return enrol_get_plugin('authorizedotnet')->get_config($key, $default);
    }

    /**
     * @param int $amount
     * @param string $description
     * @param string $invoice
     * @param AnetAPI\PaymentType $payment
     * @param AnetAPI\CreditCardType $creditcard
     * @return AnetAPI\TransactionRequestType
     */
    protected function get_transaction_request($amount, $description, $invoice, AnetAPI\PaymentType $payment,
                                               AnetAPI\CreditCardType $creditcard = null) {

        $ordertopay = new AnetAPI\OrderType();
        $ordertopay->setInvoiceNumber($invoice);
        $ordertopay->setDescription($description);

        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType('authCaptureTransaction');
        $transactionRequestType->setAmount($amount);
        $transactionRequestType->setPayment($payment);
        $transactionRequestType->setOrder($ordertopay);

        return $transactionRequestType;
    }

    /**
     * Creates a hosted payment form token for Accept Hosted.
     *
     * @return string The hosted form token.
     * @throws moodle_exception
     */
    public function create_hosted_payment_token() {
        // Build the merchant authentication object.
        $merchantAuthentication = $this->get_merchant_authentication();
        $refId = 'ref' . time();

        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType("authCaptureTransaction");
        $transactionRequestType->setAmount($this->paymentdata);

        // Define a set of hosted payment settings.
        $hostedPaymentSettings = [
            'hostedPaymentButtonOptions' => '{"text": "' . get_string('pay', 'enrol_authorizedotnet') . '"}',
            'hostedPaymentReturnOptions' => '{"showReceipt": false}',
            'hostedPaymentIFrameCommunicatorUrl' => enrol_get_plugin('authorizedotnet')->get_config('wwwroot') . '/enrol/authorizedotnet/iframecommunicator.html',
            'hostedPaymentPaymentOptions' => '{"cardCodeRequired": true}',
            'hostedPaymentStyleOptions' => '{"bgColor": "#F2F2F2"}',
            'hostedPaymentBillingAddressOptions' => '{"show": true, "required": false}',
            'hostedPaymentShippingAddressOptions' => '{"show": false}',
            'hostedPaymentCustomerOptions' => '{"showEmail": false}',
        ];

        // Create an ArrayOfSetting object and add each setting.
        $settings = [];
        foreach ($hostedPaymentSettings as $name => $value) {
            $setting = new AnetAPI\SettingType();
            $setting->setSettingName($name);
            $setting->setSettingValue($value);
            $settings[] = $setting;
        }

        $request = new AnetAPI\GetHostedPaymentPageRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setTransactionRequest($transactionRequestType);
        $request->setHostedPaymentSettings($settings);

        $controller = new AnetController\GetHostedPaymentPageController($request);
        $response = $controller->executeWithApiResponse(
            $this->get_endpoint($this->get_config('checkproductionmode'))
        );

        // Check the response.
        if ($response !== null) {
            // FIX: The original code was incorrectly trying to get a transaction response from a hosted payment page response.
            // A hosted payment page response only has messages and a token.
            // Check for the response code "Ok".
            if ($response->getMessages()->getResultCode() === 'Ok') {
                return $response->getToken();
            } else {
                $errorMessages = $response->getMessages()->getMessage();
                if (!empty($errorMessages)) {
                    $errorMessage = $errorMessages[0]->getText();
                } else {
                    $errorMessage = get_string('generalexceptionmessage', 'enrol_authorizedotnet');
                }
                throw new moodle_exception($errorMessage);
            }
        } else {
            throw new moodle_exception('generalexceptionmessage', 'enrol_authorizedotnet');
        }
    }

    /**
     * Finalizes the enrollment.
     * @param string $dataDescriptor The data descriptor from the hosted form.
     * @param string $dataValue The data value from the hosted form.
     * @return void
     * @throws moodle_exception
     */
    public function finalize_enrollment($dataDescriptor, $dataValue) {
        global $DB, $CFG;

        $merchantAuthentication = $this->get_merchant_authentication();

        $opaqueData = new AnetAPI\OpaqueDataType();
        $opaqueData->setDataDescriptor($dataDescriptor);
        $opaqueData->setDataValue($dataValue);

        $payment = new AnetAPI\PaymentType();
        $payment->setOpaqueData($opaqueData);

        $transactionRequest = new AnetAPI\TransactionRequestType();
        $transactionRequest->setTransactionType("authCaptureTransaction");
        $transactionRequest->setAmount($this->paymentdata); 
        $transactionRequest->setPayment($payment);

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setTransactionRequest($transactionRequest);

        $controller = new AnetController\CreateTransactionController($request);
        $response = $controller->executeWithApiResponse(
    $this->get_endpoint($this->get_config('checkproductionmode'))
);


        if ($response != null) {
            $tresponse = $response->getTransactionResponse();

            if ($tresponse != null && $tresponse->getResponseCode() == "1") {
                // Payment was successful. Now, enroll the user.
                $this->enrol_user();
            } else {
                throw new moodle_exception($this->get_error_message($tresponse));
            }
        } else {
            throw new moodle_exception('generalexceptionmessage', 'enrol_authorizedotnet');
        }
    }

    /**
     * Enrols the user into the course.
     * @return void
     */
    protected function enrol_user() {
        global $CFG, $DB;

        $plugin = enrol_get_plugin('authorizedotnet');

        $timestart = $this->instance->enrolstartdate ?: 0;
        $timeend   = $this->instance->enrolenddate ?: 0;

        $plugin->enrol_user($this->instance, $this->userid, $this->instance->roleid, $timestart, $timeend);

        $course = get_course($this->courseid);
        $context = context_course::instance($this->courseid);
        add_to_log($this->courseid, 'enrol', 'enrol_user', "view.php?id={$this->courseid}", 'enrol', 0, $this->userid);
    }


    /**
     * @param AnetAPI\TransactionResponse|AnetAPI\GetHostedPaymentPageResponse $response
     * @return string
     */
    protected function get_error_message($response) {
        $errorMessage = '';
        if ($response instanceof AnetAPI\TransactionResponse) {
            $errors = $response->getErrors();
        } elseif ($response instanceof AnetAPI\GetHostedPaymentPageResponse) {
            $errors = $response->getMessages();
        } else {
            return get_string('generalexceptionmessage', 'enrol_authorizedotnet');
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $errorMessage .= 'Error Code: ' . $error->getErrorCode() . ' - ' . 'Error Message: ' . $error->getErrorText() . PHP_EOL;
            }
        }

        return $errorMessage;
    }
}
