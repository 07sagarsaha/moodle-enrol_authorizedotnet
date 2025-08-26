import ajax from 'core/ajax';
import notification from 'core/notification';

const processPayment = (instanceid, paymentdata) =>
    ajax.call([{
        methodname: 'enrol_authorizedotnet_process_payment',
        args: { instanceid, paymentdata }
    }])[0];

const init = (instanceid) => {
    const form = document.getElementById(`payment_form-${instanceid}`);
    if (!form) {
        return;
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const paymentdata = {
            cardnumber: form.querySelector('[name=cardnumber]').value,
            expmonth: form.querySelector('[name=expmonth]').value,
            expyear: form.querySelector('[name=expyear]').value,
            cardcode: form.querySelector('[name=cardcode]').value,
            firstname: form.querySelector('[name=firstname]').value,
            lastname: form.querySelector('[name=lastname]').value,
            email: form.querySelector('[name=email]').value,
            phone: form.querySelector('[name=phone]').value,
            address: form.querySelector('[name=address]').value,
            city: form.querySelector('[name=city]').value,
            state: form.querySelector('[name=state]').value,
            zip: form.querySelector('[name=zip]').value,
            country: form.querySelector('[name=country]').value,
        };

        try {
            const response = await processPayment(instanceid, paymentdata);
            if (response.status) {
                window.location.href = response.redirecturl;
            } else {
                notification.add(response.message, { type: 'error' });
            }
        } catch (error) {
            notification.exception(error);
        }
    });
};

export default { init };