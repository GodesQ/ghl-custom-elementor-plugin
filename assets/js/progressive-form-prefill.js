(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var params = new URLSearchParams(window.location.search);
        var contactId = params.get('contact_id');
        var opportunityId = params.get('opportunity_id');
        var contactInput;
        var opportunityInput;

        if (contactId) {
            contactInput = document.querySelector('input[name="form_fields[contact_id]"]');

            if (contactInput) {
                contactInput.value = contactId;
            }
        }

        if (opportunityId) {
            opportunityInput = document.querySelector('input[name="form_fields[opportunity_id]"]');

            if (opportunityInput) {
                opportunityInput.value = opportunityId;
            }
        }
    });
}());
