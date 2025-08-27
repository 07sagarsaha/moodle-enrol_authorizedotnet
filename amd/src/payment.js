
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
 * JavaScript for Authorize.Net payment.
 *
 * @package    enrol_authorizedotnet
 * @author     Your Name <your@email.com>
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(['core/ajax', 'core/notification'], function(ajax, notification) {

    var getHostedPaymentUrl = function(instanceid) {
        return ajax.call([{
            methodname: 'enrol_authorizedotnet_get_hosted_payment_url',
            args: {
                'instanceid': instanceid
            }
        }])[0];
    };

    var init = function(instanceid) {
        var enrolButton = document.getElementById('enrolbutton-' + instanceid);
        var paymentResponse = document.getElementById('paymentresponse-' + instanceid);

        if (enrolButton) {
            enrolButton.addEventListener('click', function() {
                enrolButton.disabled = true;
                enrolButton.textContent = 'Processing...';

                getHostedPaymentUrl(instanceid)
                    .done(function(response) {
                        if (response.status && response.url) {
                            window.location.href = response.url;
                        } else {
                            paymentResponse.textContent = response.error;
                            paymentResponse.style.display = 'block';
                            enrolButton.disabled = false;
                            enrolButton.textContent = 'Pay Now';
                        }
                    })
                    .fail(function(ex) {
                        notification.exception(ex);
                        enrolButton.disabled = false;
                        enrolButton.textContent = 'Pay Now';
                    });
            });
        }
    };

    return {
        init: init
    };
});
