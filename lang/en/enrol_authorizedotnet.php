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
 * Strings for component 'enrol_authorizedotnet', language 'en'.
 *
 * @package    enrol_authorizedotnet
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2021 DualCube (https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['pluginname'] = 'Authorize.net';
$string['pluginname_desc'] = 'The Authorize.net module allows you to set up paid courses.  If the cost for any course is zero, then students are not asked to pay for entry.  There is a site-wide cost that you set here as a default for the whole site and then a course setting that you can set for each course individually. The course cost overrides the site cost.';
$string['loginid'] = 'Authorize.net Login ID';
$string['transactionkey'] = 'Authorize.net Transaction Key';
$string['merchantmd5hash'] = 'Authorize.net Merchant MD5 hash key';
$string['clientkey'] = 'Authorize.net public Client key';
$string['checkproductionmode'] = 'Enable test Mode';
$string['mailadmins'] = 'Notify admin';
$string['mailstudents'] = 'Notify students';
$string['mailteachers'] = 'Notify teachers';
$string['expiredaction'] = 'Enrolment expiration action';
$string['expiredaction_help'] = 'Select action to carry out when user enrolment expires. Please note that some user data and settings are purged from course during course unenrolment.';
$string['cost'] = 'Enrol cost';
$string['costerror'] = 'The enrolment cost is not numeric';
$string['costorkey'] = 'Please choose one of the following methods of enrolment.';
$string['currency'] = 'Currency';
$string['currencynote'] = 'Authorize.Net supports a single currency per gateway account, so if you need to accept payments in multiple currencies, your client must have multiple gateway accounts and your code will switch to the correct currency.';
$string['assignrole'] = 'Assign role';
$string['defaultrole'] = 'Default role assignment';
$string['defaultrole_desc'] = 'Select role which should be assigned to users during Authorize.net enrolments';
$string['enrolenddate'] = 'End date';
$string['enrolenddate_help'] = 'If enabled, users can be enrolled until this date only.';
$string['enrolenddaterror'] = 'Enrolment end date cannot be earlier than start date';
$string['enrolperiod'] = 'Enrolment duration';
$string['enrolperiod_desc'] = 'Default length of time that the enrolment is valid. If set to zero, the enrolment duration will be unlimited by default.';
$string['enrolperiod_help'] = 'Length of time that the enrolment is valid, starting with the moment the user is enrolled. If disabled, the enrolment duration will be unlimited.';
$string['enrolstartdate'] = 'Start date';
$string['enrolstartdate_help'] = 'If enabled, users can be enrolled from this date onward only.';
$string['authorizedotnet:config'] = 'Configure Authorize.net enrol instances';
$string['authorizedotnet:manage'] = 'Manage enrolled users';
$string['authorizedotnet:unenrol'] = 'Unenrol users from course';
$string['authorizedotnet:unenrolself'] = 'Unenrol self from the course';
$string['status'] = 'Allow Authorize.net enrolments';
$string['status_desc'] = 'Allow users to use Authorize.net to enrol into a course by default.';
$string['unenrolselfconfirm'] = 'Do you really want to unenrol yourself from course "{$a}"?';
$string['token_empty_error'] = 'Web service token could not be empty';
$string['invaliduserid'] = 'Not a valid user id';
$string['invalidcourseid'] = 'Not a valid course id';
$string['invalidcontextid'] = 'Not a valid context id';
$string['invalidintanceid'] = 'Not a valid instance id';
$string['invalidsesskey'] = 'Invalid sesskey';
$string['redirecttocheckout'] = 'Redirecting to secure payment page...';
$string['totaldue'] = 'Total Due';
$string['enrolnow'] = 'Pay Now';
$string['errorheading'] = 'Error: ';
$string['paymentverification'] = 'Payment Verification';
$string['paymentthanks'] = 'Thank you for your payment. You are now enrolled in the course.';
$string['paymentprocessing'] = 'We are processing your payment. If you are not enrolled in a few moments, please contact the site administrator.';
$string['welcometocoursetext'] = 'Welcome to our course {$a->course}! We hope you enjoy it.';
