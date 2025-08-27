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
 * External library for Authorize.Net payment
 *
 * @package    enrol_authorizedotnet
 * @author     Your Name
 * @copyright  2025
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ajax from "core/ajax";
import notification from "core/notification";

const { call: fetchMany } = ajax;

// Repository function
const getHostedPaymentUrl = (instanceid, userid) =>
    fetchMany([{
        methodname: "enrol_authorizedotnet_get_hosted_payment_url",
        args: { instanceid ,userid }
    }])[0];

// DOM helper
const createDOM = (instanceid) => {
    const cache = new Map();
    return {
        getElement(id) {
            const fullid = `${id}-${instanceid}`;
            if (!cache.has(fullid)) {
                cache.set(fullid, document.getElementById(fullid));
            }
            return cache.get(fullid);
        },
        setText(id, text) {
            const element = this.getElement(id);
            if (element) {
                element.textContent = text;
            }
        },
        toggleElement(id, show) {
            const element = this.getElement(id);
            if (element) {
                // Fix for Content-Security-Policy error
                element.classList.toggle("hidden", !show);
            }
        },
        setButton(id, disabled, text) {
            const button = this.getElement(id);
            if (button) {
                button.disabled = disabled;
                button.textContent = text;
                button.classList.toggle("processing", disabled); // Use a class for styles
            }
        }
    };
};

function authorizeNetPayment(instanceid, userid) {
    const DOM = createDOM(instanceid);

    const enrolButton = DOM.getElement("enrolbutton");
    const paymentResponse = DOM.getElement("paymentresponse");

    // Add the hidden class to the error message div initially
    if (paymentResponse) {
        paymentResponse.classList.add("hidden");
    }

    const displayError = (message) => {
        if (paymentResponse) {
            DOM.setText("paymentresponse", message);
            DOM.toggleElement("paymentresponse", true);
        }
    };

    const handlePayment = async () => {
        if (!enrolButton) return;

        DOM.setButton("enrolbutton", true, "Processing...");
        DOM.toggleElement("paymentresponse", false);

        try {
            const response = await getHostedPaymentUrl(instanceid, userid);
            if (response?.status && response.url) {
                window.location.href = response.url;
            } else {
                displayError(response?.error || "Unable to initiate payment.");
                DOM.setButton("enrolbutton", false, "Pay Now");
            }
        } catch (ex) {
            notification.exception(ex);
            DOM.setButton("enrolbutton", false, "Pay Now");
        }
    };

    if (enrolButton) {
        enrolButton.addEventListener("click", handlePayment);
    }
}

export default {
    authorizeNetPayment,
};