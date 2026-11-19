'use strict';

const entry = document.querySelector('form[data-entry]');
if (entry) {
    const messages = JSON.parse(entry.dataset.messages || '{}');
    const tr = (text) => messages[text] || text;
    let dirty = false;
    entry.addEventListener('input', () => { dirty = true; });
    entry.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', (event) => {
        if (dirty && entry.dataset.warn === '1') {
            event.preventDefault();
            event.returnValue = '';
        }
    });
    if (entry.dataset.noEnter === '1') {
        entry.addEventListener('keydown', (event) => {
            if (!event.isComposing && event.keyCode !== 229 && event.key === 'Enter' &&
                event.target.matches('input:not([type=submit],[type=button],[type=file])')) {
                event.preventDefault();
            }
        });
    }
    for (const input of entry.querySelectorAll('input[data-width], textarea[data-width]')) {
        const target = input.dataset.match ? document.getElementById(input.dataset.match) : null;
        const pattern = input.dataset.pattern ? new RegExp(input.dataset.pattern, 'u') : null;
        const forbidden = (input.dataset.forbidden || '').split(',').filter(Boolean);
        const validate = () => {
            let error = '';
            if (target && input.value !== target.value) {
                error = input.dataset.matchError || tr('再入力した内容が一致しません。');
            } else if (input.value && pattern && !pattern.test(input.value)) {
                error = input.dataset.formatError || tr('入力形式を確認してください。');
            } else if (forbidden.some(word => input.value.includes(word))) {
                error = tr('使用できない文字が含まれています。');
            }
            input.setCustomValidity(error);
        };
        input.addEventListener('input', validate);
        target?.addEventListener('input', validate);
        input.addEventListener('blur', () => {
            if (input.dataset.width === 'hankaku') {
                input.value = input.value.replace(/[！-～]/gu, c => String.fromCodePoint(c.codePointAt(0) - 65248))
                    .replaceAll('　', ' ').replace(/[‐ー―]/gu, '-').replaceAll('〜', '~');
            } else if (input.dataset.width === 'zenkaku') {
                input.value = input.value.replace(/[!-~]/gu, c => String.fromCodePoint(c.codePointAt(0) + 65248))
                    .replaceAll(' ', '　').replace(/[｡-ﾟ]+/gu, s => s.normalize('NFKC'));
            }
            validate();
            if (input.hasAttribute('data-realtime') && !input.checkValidity()) input.reportValidity();
        });
        validate();
    }
    for (const name of entry.querySelectorAll('input[data-kana-for]')) {
        const kana = document.getElementById(name.dataset.kanaFor);
        if (!kana || kana.form !== entry) continue;
        let previous = '';
        let reading = '';
        let manual = !!kana.value;
        kana.addEventListener('input', () => { manual = !!kana.value; });
        name.addEventListener('compositionstart', () => {
            previous = name.value ? kana.value : '';
            reading = '';
        });
        name.addEventListener('compositionupdate', (event) => {
            if (/^[\u3041-\u3096\u30a1-\u30faー　 ]+$/u.test(event.data)) reading = event.data;
        });
        name.addEventListener('compositionend', () => {
            if (!manual && reading) {
                const hiragana = name.closest('[data-kind]').dataset.kana === 'hiragana';
                kana.value = previous + Array.from(reading, (char) => {
                    const code = char.codePointAt(0);
                    if (hiragana && code >= 0x30a1 && code <= 0x30f6) return String.fromCodePoint(code - 96);
                    if (!hiragana && code >= 0x3041 && code <= 0x3096) return String.fromCodePoint(code + 96);
                    return char;
                }).join('');
            }
        });
        name.addEventListener('input', () => {
            if (!name.value && !manual) kana.value = '';
        });
    }
    for (const button of entry.querySelectorAll('[data-lookup]')) {
        const field = button.closest('[data-kind="zp_address"]');
        const zip = field.querySelector('[data-address-part="zip"]');
        const address = field.querySelector('[data-address-part="address"]');
        const prefecture = field.querySelector('[data-address-part="pref"]');
        const choices = field.querySelector('[data-address-choices]');
        const result = field.querySelector('[data-lookup-result]');
        const digits = () => zip.value.normalize('NFKC').replaceAll('-', '').trim();
        let options = [];

        const populate = (option) => {
            [prefecture.value, address.value] = option;
            dirty = true;
            result.textContent = tr('住所をご確認のうえ、番地・建物名を入力してください。');
        };
        choices.addEventListener('change', () => {
            const option = options[choices.value];
            if (option) populate(option);
        });
        async function lookup() {
            const code = digits();
            choices.hidden = true;
            if (!/^[0-9]{7}$/.test(code)) {
                result.textContent = tr('7桁の郵便番号を入力してください。');
                return;
            }
            const initialAddress = address.value;
            const initialPrefecture = prefecture.value;
            button.disabled = true;
            result.textContent = tr('検索しています…');
            try {
                const response = await fetch(button.dataset.lookup + '/' + code[0] + '.json.gz', {
                    credentials: 'same-origin', referrerPolicy: 'no-referrer'
                });
                if (!response.ok) throw new Error();
                const bytes = new Uint8Array(await response.arrayBuffer());
                const text = bytes[0] === 0x1f && bytes[1] === 0x8b
                    ? await new Response(new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'))).text()
                    : new TextDecoder().decode(bytes);
                if (code !== digits() || address.value !== initialAddress || prefecture.value !== initialPrefecture) {
                    result.textContent = tr('入力内容が変わりました。再度検索できます。');
                    return;
                }
                options = JSON.parse(text)[code] || [];
                if (!options.length) {
                    result.textContent = tr('該当する住所がありません。手入力できます。');
                } else if (options.length === 1) {
                    populate(options[0]);
                } else {
                    choices.replaceChildren(new Option(tr('住所を選択してください'), ''));
                    options.forEach(([pref, town], i) => choices.add(new Option(pref + town, String(i))));
                    choices.hidden = false;
                    result.textContent = tr('該当する住所が複数あります。');
                }
            } catch {
                result.textContent = tr('住所を検索できませんでした。手入力できます。');
            } finally {
                button.disabled = false;
            }
        }
        button.addEventListener('click', lookup);
        zip.addEventListener('change', () => {
            if (!button.disabled && /^[0-9]{7}$/.test(digits()) && !address.value && !prefecture.value) lookup();
        });
    }

}
const send = document.querySelector('form[data-send="1"]');
if (send) {
    const submitters = Array.from(send.elements).filter(element => element.type === 'submit');
    send.addEventListener('submit', (event) => {
        if (!event.submitter?.hasAttribute('formaction')) {
            for (const button of submitters) button.disabled = true;
        }
    });
    window.addEventListener('pageshow', () => {
        for (const button of submitters) button.disabled = false;
    });
}
