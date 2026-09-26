import phoneService from "./phoneService";

const FIELDS = ["full_name", "phone", "state", "address", "email"];

// Shared by the guest's one-step checkout form and the address card, so both
// validate, send and report errors the same way.
export default {
    empty: function (user) {
        const source = user || {};
        // A number registered under another country code would be wrong
        // behind the fixed +880 prefix, so it is left for the customer to type.
        const localNumber = source.phone && (!source.country_code || source.country_code === "+880");

        return {
            full_name: source.name || "",
            phone: localNumber ? "0" + phoneService.local(source.phone) : "",
            state: "",
            address: "",
            email: "",
        };
    },

    fromAddress: function (address) {
        return {
            full_name: address.full_name || "",
            phone: address.phone ? "0" + phoneService.local(address.phone) : "",
            state: address.state || "",
            address: address.address || "",
            email: address.email || "",
        };
    },

    noErrors: function () {
        return FIELDS.reduce((errors, field) => {
            errors[field] = null;
            return errors;
        }, {});
    },

    // Mirrors AddressRequest, so a customer on a slow connection learns what is
    // missing instantly instead of after a round trip.
    validate: function (form, t) {
        const errors = this.noErrors();

        if (!String(form.full_name || "").trim()) {
            errors.full_name = t("message.name_required");
        }

        if (!form.phone) {
            errors.phone = t("message.phone_required");
        } else if (!phoneService.isValid(form.phone)) {
            errors.phone = t("message.phone_invalid");
        }

        if (!form.state) {
            errors.state = t("message.district_required");
        }

        const address = String(form.address || "").trim();
        if (!address) {
            errors.address = t("message.address_required");
        } else if (address.length < 6) {
            errors.address = t("message.address_too_short");
        }

        const email = String(form.email || "").trim();
        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            errors.email = t("message.email_invalid");
        }

        return errors;
    },

    hasErrors: function (errors) {
        return FIELDS.some(field => !!errors[field]);
    },

    payload: function (form) {
        return {
            full_name: String(form.full_name || "").trim(),
            email: String(form.email || "").trim(),
            country_code: "+880",
            phone: phoneService.local(form.phone),
            country: "Bangladesh",
            state: form.state,
            address: String(form.address || "").replace(/\s+/g, " ").trim(),
        };
    },

    // Turns a rejected request into field errors plus one message to show.
    // A dropped connection has no response at all, and a 429 carries only
    // "Too Many Attempts." - both used to surface as a bare generic error.
    fromResponse: function (err, t) {
        const response = err && err.response ? err.response : null;
        const data = response ? response.data || {} : {};
        const fields = this.noErrors();
        let hasFieldError = false;

        if (data.errors && typeof data.errors === "object") {
            Object.keys(data.errors).forEach((key) => {
                // The guest endpoint calls the name `name`; the address one
                // `full_name`. Either lands on the same field.
                const field = key === "name" ? "full_name" : key;

                if (FIELDS.includes(field)) {
                    const error = data.errors[key];
                    fields[field] = Array.isArray(error) ? error[0] : error;
                    hasFieldError = true;
                }
            });
        }

        let message = null;

        // Status 0: app.js gives unanswered requests a stand-in response.
        if (!response || response.status === 0) {
            message = t("message.check_connection");
        } else if (response.status === 429) {
            message = t("message.too_many_attempts");
        } else if (!hasFieldError) {
            message = data.message || t("message.something_went_wrong");
        }

        return { fields: fields, message: message, data: data, status: response ? response.status : 0 };
    },
}
