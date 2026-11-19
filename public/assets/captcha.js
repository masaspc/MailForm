'use strict';
const tokenInput = document.querySelector('[data-sitekey]');
if (tokenInput) {
    const form = tokenInput.form;
    const notice = form.querySelector('[data-captcha-status]');
    let checking = false;
    form.addEventListener('submit', async event => {
        if (event.submitter?.hasAttribute('formaction')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (checking) return;
        checking = true;
        notice.textContent = notice.dataset.wait;
        try {
            await new Promise(resolve => grecaptcha.ready(resolve));
            tokenInput.value = await grecaptcha.execute(tokenInput.dataset.sitekey, { action: 'easy_mail_send' });
            HTMLFormElement.prototype.submit.call(form);
        } catch {
            notice.textContent = notice.dataset.error;
            checking = false;
        }
    }, true);
}
