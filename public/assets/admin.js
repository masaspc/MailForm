'use strict';

const editor = document.querySelector('[data-editor]');
if (editor) {
    const list = document.getElementById('field-list');
    const template = document.getElementById('field-template');
    const dirtyStatus = document.querySelector('[data-dirty]');
    let nextId = Date.now();
    let dirty = false;

    function refreshFields() {
        const fields = [...list.querySelectorAll('[data-field]')];
        fields.forEach((field, index) => {
            field.querySelector('.field-no').textContent = '入力項目 ' + (index + 1);
            field.querySelector('[data-move="up"]').disabled = index === 0;
            field.querySelector('[data-move="down"]').disabled = index === fields.length - 1;
            const withChoices = ['radio', 'checkbox', 'select', 'multi_select'].includes(field.querySelector('[data-type]').value);
            field.querySelector('[data-choices]').hidden = !withChoices;
        });
    }

    function markDirty() {
        dirty = true;
        dirtyStatus.textContent = '未保存の変更があります';
    }

    refreshFields();
    editor.addEventListener('input', markDirty);
    editor.addEventListener('submit', () => {
        // Omit empty optional controls so long forms stay below PHP's max_input_vars limit.
        list.querySelectorAll('input,textarea,select').forEach(input => {
            if (!input.required && input.value === '') input.disabled = true;
        });
    });
    editor.addEventListener('change', (event) => {
        markDirty();
        if (event.target.matches('[data-type]')) refreshFields();
    });
    editor.addEventListener('click', (event) => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.hasAttribute('data-add')) {
            const row = template.content.cloneNode(true);
            const option = editor.querySelector('[data-library]').selectedOptions[0];
            const preset = JSON.parse(option.dataset.definition || '{}');
            const existing = new Set([...list.querySelectorAll('input[name$="[name]"]')].map(input => input.value));
            const base = preset.name;
            for (let suffix = 2; base && existing.has(preset.name); suffix++) {
                preset.name = base + '_' + suffix;
                preset.html_id = preset.name;
            }
            row.querySelectorAll('[name]').forEach(input => {
                const property = input.name.match(/\[([^\]]+)\]$/)?.[1];
                if (property === 'choices_text') {
                    input.value = (preset.choices || []).filter(choice => !Number(choice.delete_flag)).map(choice => choice.label_text).join('\n');
                } else if (property !== 'id' && preset[property] != null) {
                    if (input.type === 'checkbox') input.checked = Boolean(Number(preset[property]));
                    else input.value = preset[property];
                }
                input.name = input.name.replace('__NEW__', String(nextId));
            });
            nextId++;
            list.append(row);
            list.lastElementChild.querySelector('input[required]').focus();
        } else {
            const field = button.closest('[data-field]');
            if (!field) return;
            if (button.hasAttribute('data-remove')) {
                if (!window.confirm('この入力項目を下書きから削除しますか？')) return;
                field.remove();
            } else if (button.dataset.move === 'up' && field.previousElementSibling) {
                list.insertBefore(field, field.previousElementSibling);
            } else if (button.dataset.move === 'down' && field.nextElementSibling) {
                list.insertBefore(field.nextElementSibling, field);
            } else return;
        }
        refreshFields();
        markDirty();
    });
    document.querySelectorAll('[data-saved-action]').forEach(form => {
        form.addEventListener('submit', event => {
            if (!dirty) return;
            event.preventDefault();
            dirtyStatus.textContent = '先に下書きを保存してください';
            editor.querySelector('.save-bar button').focus();
        });
    });
}
document.querySelectorAll('[data-confirm]').forEach(form => {
    form.addEventListener('submit', event => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});
const menuToggle = document.querySelector('.nav-toggle');
if (menuToggle) {
    menuToggle.addEventListener('click', () => {
        const hidden = document.querySelector('.shell').classList.toggle('collapsed');
        menuToggle.setAttribute('aria-expanded', String(!hidden));
    });
}
