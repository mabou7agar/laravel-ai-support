(function () {
    function readValue(field) {
        if (field.type === 'checkbox') {
            return field.checked;
        }

        if (field.tagName === 'SELECT' && field.multiple) {
            return Array.from(field.selectedOptions).map(function (option) {
                return option.value;
            });
        }

        return field.value;
    }

    function collect(form) {
        var data = {};
        var fields = form.querySelectorAll('input[name], select[name], textarea[name]');

        fields.forEach(function (field) {
            var name = field.name.replace(/\[\]$/, '');
            var value = readValue(field);

            if (field.name.endsWith('[]')) {
                data[name] = data[name] || [];
                if (Array.isArray(value)) {
                    data[name] = value;
                } else if (field.checked) {
                    data[name].push(value);
                }
                return;
            }

            if (field.type === 'radio' && !field.checked) {
                return;
            }

            data[name] = value;
        });

        return data;
    }

    document.addEventListener('input', function (event) {
        var form = event.target.closest('[data-ai-collection]');
        if (!form) {
            return;
        }

        form.dispatchEvent(new CustomEvent('ai-collection-change', {
            bubbles: true,
            detail: {
                collection: form.getAttribute('data-ai-collection'),
                data: collect(form),
            },
        }));
    });

    window.AIEngineStructuredCollection = {
        collect: collect,
    };
})();
