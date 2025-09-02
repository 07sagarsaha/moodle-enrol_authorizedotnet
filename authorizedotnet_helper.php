<?php
namespace enrol_authorizedotnet;
defined('MOODLE_INTERNAL') || die();
require_once __DIR__ . '/vendor/authorizenet/authorizenet/autoload.php';
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

/**
 * Helper class for interacting with the Authorize.net API.
 */
class authorizedotnet_helper {

    private string $apiloginid;
    private string $transactionkey;
    private bool $sandbox;

    public function __construct(string $apiloginid, string $transactionkey, bool $sandbox) {
        $this->apiloginid = $apiloginid;
        $this->transactionkey = $transactionkey;
        $this->sandbox = $sandbox;
    }

    public function create_transaction(float $amount, string $currency, object $opaquedata, \stdClass $user, \stdClass $course): array {
        // Authentication.
        $merchantauthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantauthentication->setName($this->apiloginid);
        $merchantauthentication->setTransactionKey($this->transactionkey);

        // Payment details.
        $opaquedatatype = new AnetAPI\OpaqueDataType();
        $opaquedatatype->setDataDescriptor($opaquedata->dataDescriptor);
        $opaquedatatype->setDataValue($opaquedata->dataValue);

        $payment = new AnetAPI\PaymentType();
        $payment->setOpaqueData($opaquedatatype);

        // Transaction request.
        $transactionrequest = new AnetAPI\TransactionRequestType();
        $transactionrequest->setTransactionType("authCaptureTransaction");
        $transactionrequest->setAmount($amount);
        $transactionrequest->setPayment($payment);

        // Add order information.
        $order = new AnetAPI\OrderType();
        $invoiceNumber = $user->id . '-' . $course->id . '-' . time();
        $order->setInvoiceNumber($invoiceNumber);
        $order->setDescription($course->fullname);
        $transactionrequest->setOrder($order);

        // Add customer data.
        $customer = new AnetAPI\CustomerDataType();
        $customer->setId($user->id);
        $customer->setEmail($user->email);
        $transactionrequest->setCustomer($customer);

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
        $transactionrequest->setBillTo($billTo);

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantauthentication);
        $request->setRefId('ref' . time());
        $request->setTransactionRequest($transactionrequest);

        // Execute.
        $controller = new AnetController\CreateTransactionController($request);
        $environment = $this->sandbox
            ? \net\authorize\api\constants\ANetEnvironment::SANDBOX
            : \net\authorize\api\constants\ANetEnvironment::PRODUCTION;

        $response = $controller->executeWithApiResponse($environment);

        if ($response === null) {
            return ['success' => false, 'message' => 'No response from Authorize.net'];
        }

        if ($response->getMessages()->getResultCode() !== "Ok") {
            $tresponse = $response->getTransactionResponse();
            $message = $tresponse && $tresponse->getErrors()
                ? $tresponse->getErrors()[0]->getErrorText()
                : $response->getMessages()->getMessage()[0]->getText();
            return ['success' => false, 'message' => $message];
        }

        $tresponse = $response->getTransactionResponse();
        if ($tresponse && $tresponse->getResponseCode() === "1") {
            return [
                'success'       => true,
                'transactionid' => $tresponse->getTransId(),
                'status'        => $tresponse->getMessages()[0]->getDescription(),
            ];
        }

        $message = $tresponse && $tresponse->getErrors()
            ? $tresponse->getErrors()[0]->getErrorText()
            : 'Transaction Failed';

        return ['success' => false, 'message' => $message];
    }
}