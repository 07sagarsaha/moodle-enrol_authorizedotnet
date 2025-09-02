import Ajax from "core/ajax";
import Templates from "core/templates";
import Modal from "core/modal";
import ModalEvents from "core/modal_events";
import { getString } from "core/str";
import Notification from "core/notification";

const { call: fetchMany } = Ajax;

// Repository function
const getConfigForJs = (instanceid) =>
    fetchMany([{
        methodname: "enrol_authorizedotnet_get_config_for_js",
        args: { instanceid },
    }])[0];

const processPayment = (instanceid, userid, opaqueData) =>
    fetchMany([{
        methodname: "enrol_authorizedotnet_process_payment",
        args: {
            instanceid,
            userid,
            opaquedata: JSON.stringify(opaqueData),
        },
    }])[0];

const switchSdk = (environment) => {
    const sdkUrl = (environment === 'sandbox')
        ? 'https://jstest.authorize.net/v3/AcceptUI.js'
        : 'https://js.authorize.net/v3/AcceptUI.js';

    if (switchSdk.currentlyloaded === sdkUrl) {
        return Promise.resolve();
    }

    if (switchSdk.currentlyloaded) {
        const suspectedScript = document.querySelector(`script[src="${switchSdk.currentlyloaded}"]`);
        if (suspectedScript) {
            suspectedScript.parentNode.removeChild(suspectedScript);
        }
    }

    const script = document.createElement('script');
    return new Promise(resolve => {
        script.onload = () => resolve();
        script.setAttribute('src', sdkUrl);
        script.setAttribute('charset', 'utf-8');
        document.head.appendChild(script);
        switchSdk.currentlyloaded = sdkUrl;
    });
};
switchSdk.currentlyloaded = '';

function authorizeNetPayment(instanceid, userid) {
    const enrolButton = document.getElementById(`enrolbutton-${instanceid}`);
    if (!enrolButton) {
        return;
    }

    enrolButton.addEventListener("click", async () => {
        let modal;
        try {
            // 1. Get the config from the server.
            const config = await getConfigForJs(instanceid);

            // 2. Render the button template into HTML.
            const body = await Templates.render(
                'enrol_authorizedotnet/authorizedotnet_button',
                {
                    apiloginid: config.apiloginid,
                    clientkey: config.publicclientkey,
                }
            );

            // 3. Create the modal and wait for it to be fully rendered.
            modal = await Modal.create({
                title: getString("pluginname", "enrol_authorizedotnet"),
                body: body,
                show: true,
                removeOnClose: true,
            });

            // 4. NOW load the SDK after the button is in the DOM.
            await switchSdk(config.environment);

            // 5. Define the response handler for the SDK.
            window.responseHandler = function (response) {
                // Prevent outside clicks while processing.
                modal.getRoot().on(ModalEvents.outsideClick, (e) => e.preventDefault());

                if (response.messages.resultCode === "Error") {
                    let errorMessages = '';
                    for (let i = 0; i < response.messages.message.length; i++) {
                        errorMessages += response.messages.message[i].text + '\n';
                    }
                    Notification.alert(getString('error', 'moodle'), errorMessages);
                    modal.hide();
                    return;
                }
                modal.setBody(getString('authorising', 'enrol_authorizedotnet'));

                processPayment(instanceid, userid, response.opaqueData)
                    .then((res) => {
                        modal.hide();
                        if (res.success) {
                            window.location.reload();
                        } else {
                            Notification.alert(getString('error', 'moodle'), res.message);
                        }
                    })
                    .catch((err) => {
                        Notification.exception(err);
                        modal.hide();
                    });
            };

        } catch (err) {
            Notification.exception(err);
            if (modal) {
                modal.hide();
            }
        }
    });
}

export default {
    authorizeNetPayment,
};
