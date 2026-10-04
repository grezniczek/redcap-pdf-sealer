/* Control Center only. All mutations use the Framework's authenticated AJAX endpoint. */
window.PDFSealerTimestampAdmin = (module, policies, sources, formatTime, pageUrl) => {
    const text = key => module.tt(key);
    const fail = (_form, failureMessage) => window.PDFSealerNotify(failureMessage || text('external_tsa_failed'), 'error');
    const reload = notice => {
        const url = new URL(pageUrl, location.href);
        url.searchParams.set('tsa_notice', notice);
        url.hash = 'tsa';
        location.assign(url.href);
    };
    const register = document.getElementById('tsa-register');
    register.addEventListener('submit', async event => {
        event.preventDefault();
        const fields = register.querySelector('fieldset');
        if (fields.disabled) return;
        const payload = Object.fromEntries(new FormData(register));
        fields.disabled = true;
        try {
            const result = await module.ajax('register_timestamp_source', payload);
            if (!result?.ok) { fail(register, result?.message); return; }
            register.reset();
            reload('registered');
        } catch (_) { fail(register, text('external_tsa_register_ajax')); }
        finally { fields.disabled = false; }
    });
    sources.forEach(source => {
        const card = document.querySelector('[data-tsa-card="' + source.id + '"]');
        const observation = card.querySelector('[data-tsa-observation]');
        const button = card.querySelector('[data-tsa-test]');
        const render = snapshot => {
            observation.className = 'small ' + (snapshot ? (snapshot.ok ? 'text-success' : 'text-danger') : 'text-muted');
            observation.textContent = snapshot
                ? text(snapshot.ok ? 'external_tsa_passed' : 'external_tsa_test_failed') + ' — ' + formatTime(new Date(snapshot.checked_at * 1000))
                    + (snapshot.ok ? '\n' + text('pki_fingerprint') + ': ' + snapshot.signer_sha256 : '')
                    + (snapshot.ok && snapshot.signer_sha1 ? '\n' + text('pki_thumbprint') + ': ' + snapshot.signer_sha1 : '')
                : text('diagnostic_never');
            observation.style.overflowWrap = 'anywhere';
            observation.style.whiteSpace = 'pre-line';
        };
        render(source.diagnostic);
        button.addEventListener('click', async () => {
            if (button.disabled) return;
            button.disabled = true;
            observation.textContent = text('external_tsa_testing');
            try {
                const result = await module.ajax('test_timestamp_source', {source: source.id});
                if (!result?.ok) throw new Error();
                render(result.diagnostic);
            } catch (_) { observation.textContent = text('external_tsa_failed'); observation.className = 'small text-danger'; }
            finally { button.disabled = false; }
        });
    });
    const provider = document.getElementById('tsa-provider');
    const source = document.getElementById('tsa-source');
    const fallback = document.getElementById('tsa-fallback');
    const alternatives = [1, 2].map(position => document.getElementById('tsa-alternative-' + position));
    const save = document.getElementById('tsa-policy-save');
    const sync = () => {
        fallback.disabled = source.value === 'none';
        if (fallback.disabled) fallback.checked = false;
        alternatives.forEach((select, index) => {
            select.disabled = source.value === 'none' || (index === 1 && !alternatives[0].value);
            if (select.disabled) select.value = '';
        });
        alternatives.forEach((select, index) => {
            Array.from(select.options).forEach(option => {
                option.disabled = !!option.value && (option.value === source.value
                    || alternatives.some((other, otherIndex) => otherIndex !== index && other.value === option.value));
            });
        });
    };
    provider.addEventListener('change', () => {
        const policy = policies.find(item => item.id === provider.value);
        source.value = policy?.timestamp_source || 'none';
        alternatives.forEach((select, index) => { select.value = policy?.timestamp_alternatives?.[index] || ''; });
        fallback.checked = !!policy?.bb_fallback;
        sync();
    });
    source.addEventListener('change', () => { fallback.checked = false; alternatives.forEach(select => { select.value = ''; }); sync(); });
    alternatives.forEach(select => select.addEventListener('change', sync));
    sync();
    save.addEventListener('click', async () => {
        if (save.disabled) return;
        if (!provider.reportValidity() || !source.reportValidity()) return;
        const order = alternatives.filter(select => !select.disabled && select.value).map(select => select.value);
        if (new Set([source.value, ...order]).size !== order.length + 1) {
            window.PDFSealerNotify(text('timestamp_order_invalid'), 'error');
            return;
        }
        const payload = {provider: provider.value, source: source.value, alternatives: order, fallback: !fallback.disabled && fallback.checked};
        save.disabled = true;
        try {
            const result = await module.ajax('save_provider_timestamp', payload);
            if (!result?.ok) throw new Error();
            reload('saved');
        } catch (_) {
            window.PDFSealerNotify(text('external_tsa_failed'), 'error');
        } finally { save.disabled = false; }
    });
};
