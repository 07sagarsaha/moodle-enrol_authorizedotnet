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
 * External library for authorizedotnet payment using Accept Hosted.
 *
 * This AMD module manages the entire client-side flow for Accept Hosted.
 * It fetches a secure token from the server, opens the secure hosted form in
 * an iframe, and then sends the successful payment response back to the server
 * to finalize the enrollment.
 *
 * @package    enrol_authorizedotnet
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2024 DualCube Team(https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ajax from 'core/ajax';

const { call: fetchMany } = ajax;

/**
 * Repository function to get the hosted form token from the server.
 * @param {int} instanceid The enrolment instance ID.
 * @returns {Promise<object>}
 */
const getHostedFormToken = (instanceid) =>
    fetchMany([{
        methodname: 'enrol_authorizedotnet_get_hosted_form_token',
        args: { instanceid }
    }])[0];

/**
 * Repository function to finalize the enrollment after a successful payment.
 * @param {int} instanceid The enrolment instance ID.
 * @param {string} dataDescriptor The data descriptor from the hosted form.
 * @param {string} dataValue The data value from the hosted form.
 * @returns {Promise<object>}
 */
const finalizeEnrollment = (instanceid, dataDescriptor, dataValue) =>
    fetchMany([{
        methodname: 'enrol_authorizedotnet_finalize_enrollment',
        args: { instanceid, dataDescriptor, dataValue }
    }])[0];

/**
 * Creates a DOM abstraction object for easy element manipulation.
 * Caches element lookups to prevent repeated DOM queries.
 * @param {string} instanceid The ID of the enrolment instance.
 * @returns {object} An object with helper methods for DOM interaction.
 */
const createDOM = (instanceid) => {
    const cache = new Map();
    return {
        /**
         * Gets a DOM element by its ID, with instanceid appended.
         * Caches the result for future calls.
         * @param {string} id The base ID of the element (e.g., 'payment_form').
         * @returns {HTMLElement|null} The found element or null if not found.
         */
        getElement(id) {
            const fullid = `${id}-${instanceid}`;
            if (!cache.has(fullid)) {
                cache.set(fullid, document.getElementById(fullid));
            }
            return cache.get(fullid);
        },
        /**
         * Sets the inner HTML of a DOM element.
         * @param {string} id The base ID of the element.
         * @param {string} html The HTML content to set.
         */
        setElement(id, html) {
            const element = this.getElement(id);
            if (element) {
                element.innerHTML = html;
            }
        },
        /**
         * Sets the state of a button (disabled, text, opacity).
         * @param {string} id The base ID of the button.
         * @param {boolean} disabled Whether the button should be disabled.
         * @param {string} text The text to display on the button.
         * @param {string} opacity The opacity value. Defaults to '0.7' if disabled, '1' otherwise.
         */
        setButtonState(id, disabled, text, opacity = disabled ? "0.7" : "1") {
            const button = this.getElement(id);
            if (button) {
                button.disabled = disabled;
                button.textContent = text;
                button.style.opacity = opacity;
                button.style.cursor = disabled ? "not-allowed" : "pointer";
            }
        },
    };
};

/**
 * Main function to handle the Authorize.Net payment process.
 * This function encapsulates all the logic and event handling for the payment form.
 * @param {int} instanceid The enrolment instance ID.
 * @param {int} courseid The course ID.
 * @param {int} userid The user ID.
 * @param {string} paystring The text for the 'Pay' button.
 * @param {string} pleasewaitstring The text for the 'Please wait...' button state.
 */
function authorizeDotNetPayment(instanceid, courseid, userid, paystring, pleasewaitstring) {
    const DOM = createDOM(instanceid);
    const form = DOM.getElement("payment_form");

    if (!form) {
        console.error('Payment form not found!');
        return;
    }

    /**
     * Handles the form submission and payment processing.
     * @param {Event} e The submit event.
     */
    const processPaymentHandler = async (e) => {
        e.preventDefault();

        // Use DOM abstraction to manage button state.
        DOM.setButtonState("paybutton", true, pleasewaitstring);
        DOM.setElement("payment_error", ''); // Clear any previous errors.

        try {
            // Step 1: Get a token from the Moodle server.
            const response = await getHostedFormToken(instanceid);

            if (!response.status) {
                throw new Error(response.message || 'Failed to get payment token.');
            }

            // Step 2: Use the token to open the secure hosted payment form.
            const secureData = {
                hostedPage: {
                    dataValue: response.token,
                    dataDescriptor: 'COMMON.ACCEPT.HOSTED.PAYMENTPAGE',
                },
            };

            // This is the global function provided by the Accept.js script.
            // It opens the iframe and handles the payment process securely.
            // eslint-disable-next-line no-undef
            Accept.dispatchData(secureData);
        } catch (error) {
            // Display a generic error message for network or unexpected issues.
            DOM.setElement("payment_error", error.message || 'An unexpected error occurred during payment.');
            // Restore button state on failure.
            DOM.setButtonState("paybutton", false, paystring);
        }
    };

    /**
     * Handles the callback from the hosted payment form.
     * This function is triggered when a user completes the payment in the iframe.
     * @param {object} response The response object from the hosted form.
     */
    const handleHostedPaymentResponse = async (response) => {
        if (response.messages.resultCode === "Ok") {
            // Get the payment data from the response.
            const opaqueData = response.opaqueData;

            try {
                // Step 3: Send the opaque data back to the server to finalize the enrollment.
                const serverResponse = await finalizeEnrollment(
                    instanceid,
                    opaqueData.dataDescriptor,
                    opaqueData.dataValue
                );

                if (serverResponse.status) {
                    // Finalization was successful, redirect the user.
                    window.location.href = serverResponse.redirecturl;
                } else {
                    // Display error message from the server.
                    DOM.setElement("payment_error", serverResponse.message || 'Enrollment failed.');
                }
            } catch (error) {
                // Display a generic error message for network or unexpected issues.
                DOM.setElement("payment_error", error.message || 'An unexpected error occurred during enrollment.');
            }
        } else {
            // The payment in the hosted form failed or was cancelled.
            let message = "Payment failed or was cancelled.";
            if (response.messages.message && response.messages.message.length > 0) {
                message = response.messages.message[0].text;
            }
            DOM.setElement("payment_error", message);
        }

        // Always restore the button state after the process is complete.
        DOM.setButtonState("paybutton", false, paystring);
    };

    /**
     * Sets up all the necessary event listeners for the page.
     */
    const setupEventListeners = () => {
        // Attach the main event listener to the form's submit event.
        form.addEventListener('submit', processPaymentHandler);
        
        // Listen for the callback from the hosted payment form.
        window.addEventListener('message', (event) => {
            if (event.data.AcceptJSEvent === 'handleResponse') {
                handleHostedPaymentResponse(event.data);
            }
        });
    };

    // Initialize the module by setting up event listeners.
    setupEventListeners();
}

export default {
    authorizeDotNetPayment,
};
