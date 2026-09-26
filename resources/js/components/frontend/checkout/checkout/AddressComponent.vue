<template>
    <div v-if="show" class="co-card co-address">
        <div class="co-card-head">
            <span class="co-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                    <circle cx="12" cy="10" r="3" />
                </svg>
            </span>
            <h3>{{ title }}</h3>
            <div v-if="!formVisible && addresses.length > 0" class="flex flex-wrap items-center gap-2">
                <button
                    v-if="selected.id"
                    type="button"
                    @click.prevent="startEdit(selected)"
                    class="address-action bg-[#E6FFF0] text-success"
                >
                    <i class="lab-fill-edit"></i>
                    <span>{{ $t("button.edit") }}</span>
                </button>
                <button
                    type="button"
                    @click.prevent="startAdd"
                    class="address-action bg-primary-slate text-primary"
                >
                    <i class="lab-fill-circle-plus"></i>
                    <span>{{ $t("button.add_new") }}</span>
                </button>
            </div>
        </div>

        <div class="co-card-body">
            <!-- Placeholder while the list loads, so a customer who has saved
                 addresses never sees the empty form flash up first. -->
            <div v-if="!loaded && addresses.length === 0" class="addr-skeleton" aria-hidden="true">
                <span></span><span></span>
            </div>

            <!-- The form sits in the card itself - nothing to open. It is what a
                 customer with no saved address sees straight away, and what
                 "Add New" and "Edit" swap in for the list. -->
            <form v-else-if="formVisible" class="block w-full" @submit.prevent="save" novalidate>
                <DeliveryAddressFields :form="form" :errors="errors" />

                <div class="addr-buttons">
                    <button type="submit" class="addr-save" :disabled="saving">
                        <span v-if="saving" class="addr-spinner" aria-hidden="true"></span>
                        {{ saving ? $t("label.please_wait") : $t("button.save_address") }}
                    </button>
                    <button v-if="addresses.length > 0" type="button" class="addr-cancel" @click.prevent="closeForm">
                        {{ $t("button.cancel") }}
                    </button>
                </div>
            </form>

            <div v-else class="co-opts addr-list">
                <button
                    v-for="address in addresses"
                    :key="address.id"
                    type="button"
                    class="co-opt"
                    :class="selected.id === address.id ? 'selected' : ''"
                    :aria-pressed="selected.id === address.id"
                    @click.prevent="method(address)"
                >
                    <span class="co-radio" aria-hidden="true"></span>
                    <span class="co-opt-text">
                        <b>{{ address.full_name }}</b>
                        <span v-if="address.phone" dir="ltr">{{ address.country_code ?? "" }} {{ address.phone }}</span>
                        <span v-if="address.address">{{ address.address }}</span>
                        <span v-if="address.state" class="addr-district">{{ address.state }}</span>
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>

<script>
import alertService from "../../../../services/alertService";
import deliveryFormService from "../../../../services/deliveryFormService";
import DeliveryAddressFields from "./DeliveryAddressFields.vue";

export default {
    name: "AddressComponent",
    components: { DeliveryAddressFields },
    props: {
        show: { type: Boolean, default: false },
        slug: { type: String, default: "shipping" },
        title: { type: String },
        selectedAddress: { type: Object },
        method: { type: Function },
    },
    data() {
        return {
            // The list is loaded by CheckoutComponent; this card only shows it.
            formOpen: false,
            editingId: null,
            saving: false,
            form: deliveryFormService.empty(this.$store.getters.authInfo),
            errors: deliveryFormService.noErrors(),
        };
    },
    computed: {
        addresses: function () {
            return this.$store.getters["frontendAddress/lists"] || [];
        },
        loaded: function () {
            return this.$store.getters["frontendAddress/loaded"];
        },
        selected: function () {
            return this.selectedAddress || {};
        },
        formVisible: function () {
            return this.formOpen || (this.loaded && this.addresses.length === 0);
        },
    },
    methods: {
        startAdd: function () {
            this.editingId = null;
            this.form = deliveryFormService.empty(this.$store.getters.authInfo);
            this.errors = deliveryFormService.noErrors();
            this.formOpen = true;
        },
        startEdit: function (address) {
            this.editingId = address.id;
            this.form = deliveryFormService.fromAddress(address);
            this.errors = deliveryFormService.noErrors();
            this.formOpen = true;
        },
        closeForm: function () {
            this.formOpen = false;
            this.editingId = null;
            this.errors = deliveryFormService.noErrors();
        },
        save: async function () {
            if (this.saving) {
                return;
            }

            this.errors = deliveryFormService.validate(this.form, this.$t);
            if (deliveryFormService.hasErrors(this.errors)) {
                return;
            }

            this.saving = true;

            try {
                // The store decides POST or PUT from its temp state, which the
                // other address card may have left behind - set it explicitly.
                if (this.editingId) {
                    await this.$store.dispatch("frontendAddress/edit", this.editingId);
                } else {
                    await this.$store.dispatch("frontendAddress/reset");
                }

                const response = await this.$store.dispatch("frontendAddress/save", {
                    form: deliveryFormService.payload(this.form),
                    search: { paginate: 0, order_column: "id", order_type: "desc" },
                });

                const saved = response.data.data;

                // Shown at once rather than when the store's own refresh comes
                // back: until then a first address would leave the list empty,
                // keeping the filled-in form on screen to be submitted twice.
                this.$store.commit("frontendAddress/lists", [saved].concat(this.addresses.filter(item => item.id !== saved.id)));

                this.saving = false;
                alertService.successFlip(this.editingId ? 1 : 0, this.$t("label.address"));
                this.closeForm();

                // Selecting it re-prices shipping: an edit may have changed
                // the district.
                this.method(saved);
            } catch (err) {
                this.saving = false;

                const result = deliveryFormService.fromResponse(err, this.$t);
                this.errors = Object.assign(deliveryFormService.noErrors(), result.fields);

                if (result.message) {
                    alertService.error(result.message);
                }
            }
        },
    },
};
</script>

<style scoped>
.address-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 30px;
    padding: 0 11px;
    border-radius: 99px;
    font-size: 12.5px;
    font-weight: 600;
    text-transform: capitalize;
    white-space: nowrap;
    transition: filter 0.2s ease;
}

.address-action:hover {
    filter: brightness(0.96);
}

.addr-list {
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.addr-district {
    font-weight: 600;
    color: #1f1f39 !important;
}

.addr-buttons {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-top: 18px;
}

.addr-save,
.addr-cancel {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    height: 46px;
    padding: 0 26px;
    border-radius: 9999px;
    font-size: 14.5px;
    font-weight: 700;
    white-space: nowrap;
    transition: filter 0.2s ease, transform 0.2s ease;
}

.addr-save {
    min-width: 170px;
    color: #ffffff;
    background: rgb(var(--primary));
}

.addr-save:hover:not(:disabled) {
    filter: brightness(1.06);
}

.addr-save:disabled {
    opacity: 0.75;
    cursor: wait;
}

.addr-cancel {
    color: #1f1f39;
    background: #f7f7fc;
}

.addr-cancel:hover {
    filter: brightness(0.97);
}

.addr-spinner {
    width: 1rem;
    height: 1rem;
    border-radius: 9999px;
    border: 2px solid rgb(255 255 255 / 0.35);
    border-top-color: #ffffff;
    animation: addr-spin 0.7s linear infinite;
}

@keyframes addr-spin {
    to {
        transform: rotate(360deg);
    }
}

.addr-skeleton {
    display: grid;
    gap: 11px;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
}

.addr-skeleton span {
    display: block;
    height: 86px;
    border-radius: 10px;
    background: linear-gradient(90deg, #f3f4f7 0%, #fafafc 50%, #f3f4f7 100%);
    background-size: 200% 100%;
    animation: addr-shimmer 1.2s ease-in-out infinite;
}

@keyframes addr-shimmer {
    from {
        background-position: 100% 0;
    }
    to {
        background-position: -100% 0;
    }
}

@media (max-width: 639px) {
    .addr-save {
        flex: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .address-action,
    .addr-save,
    .addr-cancel {
        transition: none;
    }

    .addr-spinner,
    .addr-skeleton span {
        animation: none;
    }
}
</style>
