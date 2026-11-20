'use strict';

for (const editor of document.querySelectorAll('[data-scenario-editor]')) {
    const template = editor.querySelector('template');

    for (const list of editor.querySelectorAll('[data-rule-list]')) {
        const input = list.querySelector('[data-rule-value]');
        const rules = list.querySelector('[data-rules]');
        const add = list.querySelector('[data-rule-add]');
        let initial;
        try {
            initial = JSON.parse(input.value);
            if (!Array.isArray(initial)) continue;
        } catch {
            continue;
        }

        const setButtons = () => {
            add.disabled = rules.children.length >= 100;
            for (const [index, row] of Array.from(rules.children).entries()) {
                row.querySelector('[data-rule-action="up"]').disabled = index === 0;
                row.querySelector('[data-rule-action="down"]').disabled = index === rules.children.length - 1;
            }
        };
        const sync = () => {
            input.value = JSON.stringify(Array.from(rules.children, row => {
                const rule = {};
                for (const field of row.querySelectorAll('[data-rule-field]')) {
                    const key = field.dataset.ruleField;
                    if (key === 'isActive') rule[key] = field.value === 'true';
                    else if (field.value !== '' || key === 'channel') rule[key] = field.value;
                }
                for (const checkbox of row.querySelectorAll('[data-rule-condition]:checked')) {
                    const conditions = rule.fieldsConditions ??= {};
                    (conditions[checkbox.dataset.ruleCondition] ??= []).push(checkbox.value);
                }
                return rule;
            }));
            setButtons();
        };
        const addRule = rule => {
            const row = template.content.firstElementChild.cloneNode(true);
            for (const field of row.querySelectorAll('[data-rule-field]')) {
                const key = field.dataset.ruleField;
                if (key === 'isActive') field.value = [false, 0, '0'].includes(rule?.isActive) ? 'false' : 'true';
                else if (typeof rule?.[key] === 'string') {
                    if (key === 'channel' && !Array.from(field.options).some(option => option.value === rule[key])) {
                        field.append(new Option(rule[key] + ' — отсутствует', rule[key]));
                    }
                    field.value = rule[key];
                }
            }
            for (const box of row.querySelectorAll('[data-rule-condition]')) {
                box.checked = (rule?.fieldsConditions?.[box.dataset.ruleCondition] ?? []).includes(box.value);
            }
            if (row.querySelector('[data-rule-condition]:checked')) row.querySelector('details').open = true;
            rules.append(row);
            return row;
        };
        for (const rule of initial) addRule(rule);
        input.hidden = true;
        add.hidden = false;
        setButtons();
        add.addEventListener('click', () => {
            if (rules.children.length >= 100) return;
            addRule(null).querySelector('select').focus();
            sync();
        });
        rules.addEventListener('input', sync);
        rules.addEventListener('change', sync);
        rules.addEventListener('click', event => {
            const button = event.target.closest('[data-rule-action]');
            if (!button) return;
            const row = button.closest('.scenario-rule');
            if (button.dataset.ruleAction === 'remove') {
                const next = row.nextElementSibling ?? row.previousElementSibling;
                row.remove();
                (next?.querySelector('select') ?? add).focus();
            } else if (button.dataset.ruleAction === 'up' && row.previousElementSibling) {
                row.previousElementSibling.before(row);
            } else if (button.dataset.ruleAction === 'down' && row.nextElementSibling) {
                row.nextElementSibling.after(row);
            }
            sync();
            if (button.dataset.ruleAction !== 'remove') {
                (button.disabled ? row.querySelector('select') : button).focus();
            }
        });
        editor.closest('form').addEventListener('submit', sync);
    }
}
