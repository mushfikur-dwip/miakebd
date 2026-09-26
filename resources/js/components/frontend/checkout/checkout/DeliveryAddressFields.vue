<template>
    <!-- The delivery form's fields, shared by the guest's one-step form and the
         address card a signed-in (or returning guest) customer sees. The parent
         owns the form object and the submit; this only renders and cleans. -->
    <div class="daf">
        <div class="daf-field">
            <label :for="uid + '-name'" class="daf-label required">{{ $t('label.full_name') }}</label>
            <input
                :id="uid + '-name'"
                v-model="form.full_name"
                type="text"
                autocomplete="name"
                enterkeyhint="next"
                maxlength="120"
                :placeholder="$t('label.full_name')"
                class="daf-input"
                :class="{ invalid: errors.full_name }"
                @input="clear('full_name')"
            />
            <small v-if="errors.full_name" class="daf-error">{{ first(errors.full_name) }}</small>
        </div>

        <div class="daf-field">
            <label :for="uid + '-phone'" class="daf-label required">{{ $t('label.phone') }}</label>
            <div class="daf-input daf-input--group" :class="{ invalid: errors.phone }">
                <span class="daf-dial">+880</span>
                <input
                    :id="uid + '-phone'"
                    :value="form.phone"
                    type="tel"
                    inputmode="numeric"
                    autocomplete="tel-national"
                    enterkeyhint="next"
                    maxlength="16"
                    placeholder="01XXXXXXXXX"
                    class="daf-bare"
                    @input="onPhone"
                />
            </div>
            <small v-if="errors.phone" class="daf-error">{{ first(errors.phone) }}</small>
            <small v-else-if="phoneHint" class="daf-hint">{{ $t('message.guest_phone_hint') }}</small>
        </div>

        <div class="daf-field">
            <label :for="uid + '-district'" class="daf-label required">{{ $t('label.district') }}</label>
            <!-- A native select: it opens the phone's own picker, works in the
                 Facebook/Instagram in-app browsers ad traffic arrives in, can be
                 autofilled, and has nothing to load - the old searchable
                 dropdown fetched its list over the network and lost it for
                 good after the address form was closed once. -->
            <div class="daf-select" :class="{ invalid: errors.state, empty: !form.state }">
                <select
                    :id="uid + '-district'"
                    v-model="form.state"
                    autocomplete="address-level2"
                    class="daf-input"
                    @change="clear('state')"
                >
                    <option value="" disabled>{{ $t('label.select_district') }}</option>
                    <option v-for="district in districts" :key="district.name" :value="district.name">
                        {{ district.name }} ({{ district.bn_name }})
                    </option>
                </select>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m6 9 6 6 6-6" />
                </svg>
            </div>
            <small v-if="errors.state" class="daf-error">{{ first(errors.state) }}</small>
        </div>

        <div class="daf-field daf-field--email">
            <label :for="uid + '-email'" class="daf-label">
                {{ $t('label.email') }} <span class="daf-optional">({{ $t('label.optional') }})</span>
            </label>
            <input
                :id="uid + '-email'"
                v-model.trim="form.email"
                type="email"
                inputmode="email"
                autocomplete="email"
                maxlength="190"
                placeholder="name@example.com"
                class="daf-input"
                :class="{ invalid: errors.email }"
                @input="clear('email')"
            />
            <small v-if="errors.email" class="daf-error">{{ first(errors.email) }}</small>
        </div>

        <div class="daf-field daf-field--wide">
            <label :for="uid + '-address'" class="daf-label required">{{ $t('label.full_address') }}</label>
            <textarea
                :id="uid + '-address'"
                v-model="form.address"
                rows="2"
                autocomplete="street-address"
                maxlength="500"
                :placeholder="$t('label.full_address_placeholder')"
                class="daf-input daf-textarea"
                :class="{ invalid: errors.address }"
                @input="clear('address')"
            ></textarea>
            <small v-if="errors.address" class="daf-error">{{ first(errors.address) }}</small>
        </div>
    </div>
</template>

<script>
import bdDistricts from "../../../../data/bdDistricts";
import phoneService from "../../../../services/phoneService";

let instance = 0;

export default {
    name: "DeliveryAddressFields",
    props: {
        // { full_name, phone, state, address, email } - mutated in place, like
        // every other form object in this app.
        form: { type: Object, required: true },
        errors: { type: Object, default: () => ({}) },
        phoneHint: { type: Boolean, default: false },
    },
    data() {
        instance += 1;

        return {
            // Unique per instance: the shipping and billing cards can both be
            // open, and duplicate ids would point their labels at each other.
            uid: "daf" + instance,
            districts: bdDistricts,
        };
    },
    methods: {
        first: function (error) {
            return Array.isArray(error) ? error[0] : error;
        },
        clear: function (field) {
            if (this.errors[field]) {
                this.errors[field] = null;
            }
        },
        onPhone: function (event) {
            const cleaned = phoneService.clean(event.target.value);

            // Written back to the element as well as the model: when a stray
            // character is stripped the model does not change, so Vue would
            // not re-render and the character would stay on screen.
            if (event.target.value !== cleaned) {
                event.target.value = cleaned;
            }

            this.form.phone = cleaned;
            this.clear("phone");
        },
    },
};
</script>

<style scoped>
.daf {
    display: grid;
    gap: 14px;
}

@media (min-width: 640px) {
    .daf {
        grid-template-columns: 1fr 1fr;
        gap: 15px 16px;
    }

    .daf-field--wide {
        grid-column: 1 / -1;
    }
}

/* On a phone the optional field goes last, after everything the order needs. */
@media (max-width: 639px) {
    .daf-field--email {
        order: 5;
    }
}

.daf-label {
    display: block;
    margin-bottom: 6px;
    font-size: 13.5px;
    font-weight: 600;
    color: #1f1f39;
}

.daf-label.required::after {
    content: " *";
    color: #e93c3c;
}

.daf-optional {
    font-weight: 500;
    color: #8a8ca3;
}

.daf-input {
    display: block;
    width: 100%;
    height: 48px;
    padding: 0 14px;
    /* 16px, not less: iOS zooms the whole page into any smaller field. */
    font-size: 16px;
    font-weight: 500;
    color: #1f1f39;
    background: #ffffff;
    border: 1px solid #d9dbe9;
    border-radius: 10px;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    -webkit-appearance: none;
    appearance: none;
}

.daf-input::placeholder,
.daf-bare::placeholder {
    color: #a0a3bd;
    font-weight: 400;
}

.daf-input:focus,
.daf-input:focus-within {
    border-color: rgb(var(--primary) / 0.55);
    box-shadow: 0 0 0 3px rgb(var(--primary) / 0.1);
}

.daf-input.invalid,
.daf-select.invalid .daf-input {
    border-color: #e93c3c;
    box-shadow: 0 0 0 3px rgb(233 60 60 / 0.08);
}

.daf-input--group {
    display: flex;
    align-items: center;
    gap: 10px;
}

.daf-dial {
    flex-shrink: 0;
    padding-right: 10px;
    font-size: 13.5px;
    font-weight: 600;
    color: #6e7191;
    border-right: 1px solid #eff0f6;
}

.daf-bare {
    flex: 1;
    min-width: 0;
    height: 100%;
    font-size: 16px;
    font-weight: 500;
    letter-spacing: 0.02em;
    background: transparent;
    border: 0;
    outline: none;
}

.daf-select {
    position: relative;
}

.daf-select .daf-input {
    padding-right: 40px;
    cursor: pointer;
}

.daf-select.empty .daf-input {
    color: #a0a3bd;
    font-weight: 400;
}

/* Options inherit the empty-state grey otherwise. */
.daf-select option {
    color: #1f1f39;
}

.daf-select svg {
    position: absolute;
    top: 50%;
    right: 14px;
    width: 18px;
    height: 18px;
    color: #6e7191;
    transform: translateY(-50%);
    pointer-events: none;
}

.daf-textarea {
    height: auto;
    min-height: 76px;
    padding: 12px 14px;
    line-height: 1.5;
    resize: vertical;
}

.daf-error,
.daf-hint {
    display: block;
    margin-top: 5px;
    font-size: 12px;
    line-height: 1.4;
}

.daf-error {
    color: #e93c3c;
}

.daf-hint {
    color: #6e7191;
}

@media (prefers-reduced-motion: reduce) {
    .daf-input {
        transition: none;
    }
}
</style>
