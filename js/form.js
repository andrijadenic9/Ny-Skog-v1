document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.contact-form-style-03');
    if (!form) return;

    const submitBtn = form.querySelector('button[type="submit"]');
    if (!submitBtn) return;

    const setLoading = (on) => {
        submitBtn.disabled = on;
        submitBtn.classList.toggle('is-loading', on);
    };

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const results = form.querySelector('.form-results');
        if (results) {
            results.classList.remove('d-none');
            results.className = 'form-results mt-20px alert alert-info';
            results.textContent = 'Sender melding...'; // Poruka se Šalje...
        }

        const actionURL = form.getAttribute('action');
        if (!actionURL) {
            if (results) {
                results.className = 'form-results mt-20px alert alert-danger';
                results.textContent = 'Feil: Ingen handlings-URL på skjemaet.'; // Greška: nema action URL-a na formi.
            }
            return;
        }

        // hidden inputs
        let tokenInput = form.querySelector('input[name="g-recaptcha-response"]');
        if (!tokenInput) {
            tokenInput = document.createElement('input');
            tokenInput.type = 'hidden';
            tokenInput.name = 'g-recaptcha-response';
            form.appendChild(tokenInput);
        }

        let rcActionInput = form.querySelector('input[name="rc_action"]');
        if (!rcActionInput) {
            rcActionInput = document.createElement('input');
            rcActionInput.type = 'hidden';
            rcActionInput.name = 'rc_action';
            form.appendChild(rcActionInput);
        }
        rcActionInput.value = 'contact';

        if (typeof grecaptcha === 'undefined') {
            if (results) {
                results.className = 'form-results mt-20px alert alert-danger';
                results.textContent = 'reCAPTCHA er ikke lastet inn.'; // reCAPTCHA nije učitana.
            }
            setLoading(false);
            return;
        }

        setLoading(true);

        grecaptcha.ready(function () {
            grecaptcha
                .execute('6LeH7vErAAAAAJiZN3uo5SPDUYJc65R8qU6SgHhB', { action: 'contact' })
                .then(async function (token) {
                    tokenInput.value = token;

                    let ok = false;
                    let message = '';

                    try {
                        // optional timeout (15s) – možeš i bez ovoga
                        const ctrl = new AbortController();
                        const t = setTimeout(() => ctrl.abort(), 15000);

                        const resp = await fetch(actionURL, {
                            method: 'POST',
                            body: new FormData(form),
                            signal: ctrl.signal,
                        });
                        clearTimeout(t);

                        const ct = resp.headers.get('content-type') || '';
                        const raw = await resp.text();

                        // Pomožna dijagnostika u konzoli
                        console.debug('ContactForm response:', {
                            status: resp.status,
                            contentType: ct,
                            snippet: raw.slice(0, 200),
                        });

                        let data;
                        if (ct.includes('application/json')) {
                            try {
                                data = JSON.parse(raw);
                            } catch (e) {
                                data = {
                                    ok: false,
                                    message: 'Server sent invalid JSON. Snippet: ' + raw.slice(0, 200),
                                };
                            }
                        } else {
                            data = {
                                ok: false,
                                message: `Non-JSON response (status ${resp.status}). Snippet: ` + raw.slice(0, 200),
                            };
                        }

                        ok = !!data.ok;
                        message = data.message || (ok ? 'Message sent.' : 'An error occurred while sending.');
                    } catch (err) {
                        console.error(err);
                        ok = false;
                        message =
                            err.name === 'AbortError' ? 'Timed out while sending.' : 'An error occurred while sending.';
                    } finally {
                        setLoading(false);
                    }

                    if (results) {
                        results.className = 'form-results mt-20px alert ' + (ok ? 'alert-success' : 'alert-danger');
                        results.textContent = message;
                    }
                    if (ok) form.reset();
                })
                .catch(function (err) {
                    console.error(err);
                    if (results) {
                        results.className = 'form-results mt-20px alert alert-danger';
                        results.textContent = 'reCAPTCHA failed.';
                    }
                    setLoading(false);
                });
        });
    });
});
