(function () {
    function getQueryParam(name) {
        var params = new URLSearchParams(window.location.search);

        return params.get(name);
    }

    function setInputValue(selector, value) {
        var inputs;

        if (!value) {
            return;
        }

        inputs = document.querySelectorAll(selector);

        Array.prototype.forEach.call(inputs, function (input) {
            input.value = value;
        });
    }

    function prefillProgressiveHiddenFields(contactId, opportunityId) {
        setInputValue('input[name="form_fields[contact_id]"], input[name="contact_id"]', contactId);
        setInputValue('input[name="form_fields[opportunity_id]"], input[name="opportunity_id"]', opportunityId);
    }

    function getProgressiveForms() {
        return Array.prototype.slice.call(
            document.querySelectorAll('[data-ghl-progressive-form]')
        );
    }

    function setElementVisible(element, isVisible) {
        element.hidden = !isVisible;
        element.style.display = isVisible ? '' : 'none';

        if (isVisible) {
            element.removeAttribute('aria-hidden');
        } else {
            element.setAttribute('aria-hidden', 'true');
            element.classList.remove('is-active', 'is-complete');
        }
    }

    function dispatchFilteredEvent(form, eventItems, allowedSteps) {
        var event;

        if (typeof window.CustomEvent === 'function') {
            event = new CustomEvent('ghlProgressiveFormFiltered', {
                bubbles: true,
                detail: {
                    eventItems: eventItems || [],
                    allowedSteps: allowedSteps || []
                }
            });
        } else {
            event = document.createEvent('CustomEvent');
            event.initCustomEvent(
                'ghlProgressiveFormFiltered',
                true,
                false,
                {
                    eventItems: eventItems || [],
                    allowedSteps: allowedSteps || []
                }
            );
        }

        form.dispatchEvent(event);
    }

    function revealAllSteps(forms, eventItems, allowedSteps) {
        forms.forEach(function (form) {
            form
                .querySelectorAll('[data-ghl-event-item]')
                .forEach(function (element) {
                    setElementVisible(element, true);
                });

            dispatchFilteredEvent(form, eventItems, allowedSteps);
        });
    }

    function filterSteps(forms, eventItems, allowedSteps) {
        var allowedLookup = {};
        var hasAnyMatch = false;

        if (!Array.isArray(allowedSteps) || !allowedSteps.length) {
            revealAllSteps(forms, eventItems, allowedSteps);
            return;
        }

        allowedSteps.forEach(function (stepKey) {
            allowedLookup[stepKey] = true;
        });

        forms.forEach(function (form) {
            var formHasMatch = false;

            form
                .querySelectorAll('[data-ghl-event-item]')
                .forEach(function (element) {
                    var stepKey = element.getAttribute('data-ghl-event-item');
                    var isVisible = !!allowedLookup[stepKey];

                    if (isVisible) {
                        formHasMatch = true;
                        hasAnyMatch = true;
                    }

                    setElementVisible(element, isVisible);
                });

            if (!formHasMatch) {
                form
                    .querySelectorAll('[data-ghl-event-item]')
                    .forEach(function (element) {
                        setElementVisible(element, true);
                    });
            }

            dispatchFilteredEvent(form, eventItems, formHasMatch ? allowedSteps : []);
        });

        if (!hasAnyMatch) {
            revealAllSteps(forms, eventItems, []);
        }
    }

    function getContactEndpoint() {
        if (
            window.ghlElementorProgressiveForm &&
            window.ghlElementorProgressiveForm.contactEndpoint
        ) {
            return window.ghlElementorProgressiveForm.contactEndpoint;
        }

        return window.location.origin + '/wp-json/ghl-elementor/v1/progressive-form/contact';
    }

    function getSubmitEndpoint() {
        if (
            window.ghlElementorProgressiveForm &&
            window.ghlElementorProgressiveForm.submitEndpoint
        ) {
            return window.ghlElementorProgressiveForm.submitEndpoint;
        }

        return window.location.origin + '/wp-json/ghl-elementor/v1/progressive-form/opportunity';
    }

    function buildContactUrl(endpoint, contactId) {
        var separator = endpoint.indexOf('?') === -1 ? '?' : '&';

        return endpoint + separator + 'contact_id=' + encodeURIComponent(contactId);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var contactId = getQueryParam('contact_id');
        var opportunityId = getQueryParam('opportunity_id');
        var forms = getProgressiveForms();

        prefillProgressiveHiddenFields(contactId, opportunityId);

        window.ghlElementorProgressiveForm = window.ghlElementorProgressiveForm || {};
        window.ghlElementorProgressiveForm.contactId = contactId || '';
        window.ghlElementorProgressiveForm.opportunityId = opportunityId || '';
        window.ghlElementorProgressiveForm.submitEndpoint = getSubmitEndpoint();

        if (!forms.length) {
            return;
        }

        if (!contactId || typeof window.fetch !== 'function') {
            revealAllSteps(forms, [], []);
            return;
        }

        window
            .fetch(buildContactUrl(getContactEndpoint(), contactId), {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json'
                }
            })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Progressive form contact lookup failed.');
                }

                return response.json();
            })
            .then(function (data) {
                filterSteps(forms, data.event_items || [], data.allowed_steps || []);
            })
            .catch(function () {
                revealAllSteps(forms, [], []);
            });
    });
}());
