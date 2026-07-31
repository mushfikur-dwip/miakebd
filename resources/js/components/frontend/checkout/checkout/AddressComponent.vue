<template>
    <LoadingComponent :props="loading" />
    <div v-if="show" class="co-card">
        <div class="co-card-head">
            <span class="co-card-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z" />
                    <circle cx="12" cy="10" r="3" />
                </svg>
            </span>
            <h3>{{ title }}</h3>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-if="Object.keys(selectedAddress).length > 0"
                    type="button"
                    @click.prevent="edit(selectedAddress)"
                    class="address-action bg-[#E6FFF0] text-success"
                >
                    <i class="lab-fill-edit"></i>
                    <span>{{ $t("button.edit") }}</span>
                </button>
                <button
                    type="button"
                    @click.prevent="
                        showTarget(slug + '-address-modal', 'modal-active')
                    "
                    class="address-action bg-primary-slate text-primary"
                >
                    <i class="lab-fill-circle-plus"></i>
                    <span>{{ $t("button.add_new") }}</span>
                </button>
            </div>
        </div>
        <div class="co-card-body">
            <div class="co-opts">
                <button
                    type="button"
                    :class="
                        Object.keys(selectedAddress).length > 0 &&
                        address.id === selectedAddress.id
                            ? 'selected'
                            : ''
                    "
                    @click.prevent="activeAddress(address)"
                    v-for="address in addresses"
                    :key="address.id"
                    class="co-opt"
                >
                    <span class="co-radio" aria-hidden="true"></span>
                    <span class="co-opt-text">
                        <b>{{ address.full_name }}</b>
                        <span v-if="address.phone"
                            >{{ address.country_code ?? "" }} {{ address.phone }}</span
                        >
                        <span v-if="address.address">{{ address.address }}</span>
                        <span v-if="address.state">{{ address.state }}</span>
                    </span>
                </button>
            </div>
        </div>
    </div>

    <div
        :id="slug + '-address-modal'"
        class="fixed inset-0 z-50 p-3 w-screen h-dvh overflow-y-auto bg-black/50 transition-all duration-300 opacity-0 invisible"
    >
        <div
            class="w-full rounded-xl mx-auto bg-white transition-all duration-300 max-w-3xl"
        >
            <div
                class="flex items-center justify-between gap-2 py-4 px-4 border-b border-slate-100"
            >
                <h3 class="text-lg font-bold capitalize">
                    {{ $t("label.address") }}
                </h3>
                <button
                    @click.prevent="reset"
                    type="button"
                    class="lab-line-circle-cross text-lg text-[#E93C3C]"
                ></button>
            </div>
            <form class="w-full p-5" @submit.prevent="save">
                <div class="form-row">
                    <!-- Row 1: Full Name (Full Width) -->
                    <div class="form-col-12">
                        <label
                            for="full_name"
                            class="text-sm font-medium capitalize mb-1 field-title required"
                        >
                            {{ $t("label.full_name") }}
                        </label>
                        <input
                            type="text"
                            v-model="address.form.full_name"
                            :class="errors.full_name ? 'invalid' : ''"
                            class="w-full h-12 px-4 rounded-lg text-base border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500"
                        />
                        <small class="db-field-alert" v-if="errors.full_name">
                            {{ errors.full_name[0] }}
                        </small>
                    </div>

                    <!-- Row 2: Phone + Email -->
                    <div class="form-col-12">
                        <label
                            for="phone"
                            class="text-sm font-medium capitalize mb-1 field-title required"
                        >
                            {{ $t("label.phone") }}
                        </label>
                        <div
                            :class="errors.phone ? 'invalid' : ''"
                            class="field-control flex items-center"
                        >
                            <div class="w-fit flex-shrink-0 px-2">
                                <span class="flex items-center gap-1">
                                    <span
                                        class="whitespace-nowrap flex-shrink-0 text-xs"
                                        >+880</span
                                    >
                                </span>
                            </div>
                            <input
                                v-model="address.form.phone"
                                v-on:keypress="phoneNumber($event)"
                                :class="errors.phone ? 'invalid' : ''"
                                type="text"
                                id="phone"
                                class="pl-2 text-sm w-full h-full"
                            />
                        </div>
                        <small class="db-field-alert" v-if="errors.phone">
                            {{ errors.phone[0] }}
                        </small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <label
                            for="email"
                            class="text-sm font-medium capitalize mb-1 field-title"
                        >
                            {{ $t("label.email") }}
                        </label>
                        <input
                            type="email"
                            v-model="address.form.email"
                            :class="errors.email ? 'invalid' : ''"
                            class="w-full h-12 px-4 rounded-lg text-base border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500"
                        />
                        <small class="db-field-alert" v-if="errors.email">
                            {{ errors.email[0] }}
                        </small>
                    </div>

                    <!-- Row 3: District (State) -->
                    <div class="form-col-12 sm:form-col-6">
                        <label
                            class="text-sm font-medium capitalize mb-1 field-title required"
                            for="state"
                        >
                            District
                        </label>
                        <vue-select
                            class="w-full h-12 px-4 rounded-lg text-base capitalize border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500 appearance-none"
                            id="state"
                            v-bind:class="errors.state ? 'invalid' : ''"
                            v-model="address.form.state"
                            :options="address.states"
                            label-by="name"
                            value-by="name"
                            :closeOnSelect="true"
                            :searchable="true"
                            :clearOnClose="true"
                            placeholder="--"
                            search-placeholder="--"
                        />
                        <small class="db-field-alert" v-if="errors.state">
                            {{ errors.state[0] }}
                        </small>
                    </div>

                    <!-- Row 4: Full Address (Full Width) -->
                    <div class="form-col-12">
                        <label
                            class="text-sm font-medium capitalize mb-1 field-title required"
                            for="street_address"
                        >
                            Full Address
                        </label>
                        <input
                            type="text"
                            :class="errors.address ? 'invalid' : ''"
                            v-model="address.form.address"
                            class="w-full h-12 px-4 rounded-lg text-base border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500"
                        />
                        <small class="db-field-alert" v-if="errors.address">
                            {{ errors.address[0] }}
                        </small>
                    </div>

                    <div class="form-col-12 sm:form-col-6">
                        <div class="flex flex-wrap gap-6 mt-2">
                            <button
                                type="submit"
                                class="font-bold text-center h-12 leading-12 px-8 rounded-full whitespace-nowrap bg-primary text-white capitalize"
                            >
                                {{ $t("button.save_address") }}
                            </button>

                            <button
                                @click.prevent="reset"
                                type="button"
                                class="font-bold text-center h-12 leading-12 px-8 rounded-full whitespace-nowrap bg-[#F7F7FC] capitalize"
                            >
                                {{ $t("button.cancel") }}
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</template>

<script>
import orderTypeEnum from "../../../../enums/modules/orderTypeEnum";
import appService from "../../../../services/appService";
import targetService from "../../../../services/targetService";
import alertService from "../../../../services/alertService";
import LoadingComponent from "../../components/LoadingComponent.vue";

export default {
    name: "AddressComponent",
    props: {
        show: { type: Boolean, Default: false },
        slug: { type: String, Default: "shipping" },
        title: { type: String },
        selectedAddress: { type: Object },
        method: { type: Function },
    },
    data() {
        return {
            loading: {
                isActive: false,
            },
            orderTypeEnum: orderTypeEnum,
            address: {
                form: {
                    full_name: "",
                    email: "",
                    country_code: "+880",
                    phone: "",
                    country: "Bangladesh",
                    state: null,
                    address: "",
                },
                search: {
                    paginate: 0,
                    order_column: "id",
                    order_type: "asc",
                },
                states: [],
            },
            worldMapData: [],
            activeAddressId: null,
            errors: {},
        };
    },
    components: {
        LoadingComponent,
    },
    computed: {
        addresses: function () {
            return this.$store.getters["frontendAddress/lists"];
        },
        countries: function () {
            return this.$store.getters["frontendCountryStateCity/countries"];
        },
    },
    mounted() {
        this.loading.isActive = true;
        setTimeout(() => {
            this.callCountry();
            // Auto-select Bangladesh and load states
            this.address.form.country = "Bangladesh";
            this.callStates("Bangladesh");
        }, 300);
        this.$store
            .dispatch("frontendAddress/lists", {
                search: {
                    paginate: 0,
                    order_column: "id",
                    order_type: "asc",
                },
            })
            .then((res) => {
                this.purgeForeignAddress();
                this.loading.isActive = false;
            })
            .catch((err) => {
                this.loading.isActive = false;
            });

        this.loading.isActive = false;
        this.address.form.country_code = "+880";
    },
    methods: {
        // The cart is persisted to localStorage, so the address chosen for an
        // earlier order survives into the next one — and every guest checkout
        // creates a fresh user row, so that id then belongs to somebody else.
        // OrderRequest requires shipping_id/billing_id and the order is
        // refused, which is why a second order could never be placed. If the
        // stored address is not in this customer's own list, drop it so they
        // are asked to pick one instead of silently sending a dead id.
        purgeForeignAddress: function () {
            const selected = this.selectedAddress || {};

            if (!selected.id) {
                return;
            }

            const owned = (this.addresses || []).some(address => address.id === selected.id);

            if (!owned) {
                this.activeAddressId = null;
                this.method({});
            }
        },
        phoneNumber(e) {
            return appService.phoneNumber(e);
        },
        activeAddress: function (address) {
            this.activeAddressId = address.id;
            this.method(address);
        },
        showTarget: function (targetID, addClass) {
            targetService.showTarget(targetID, addClass);
        },
        callCountry: function () {
            this.$store.dispatch("frontendCountryStateCity/countries");
        },
        callStates: function (countryName) {
            this.address.form.state = null;
            this.address.states = [];

            this.$store
                .dispatch(
                    "frontendCountryStateCity/statesByCountry",
                    countryName
                )
                .then((res) => {
                    this.address.states = res.data.data;
                });
        },
        reset: function () {
            targetService.hideTarget(
                this.slug + "-address-modal",
                "modal-active"
            );
            this.$store.dispatch("frontendAddress/reset").then().catch();
            this.errors = {};
            this.address.form = {
                full_name: "",
                email: "",
                country_code: "+880",
                phone: "",
                country: "Bangladesh",
                state: null,
                address: "",
            };
            this.address.states = [];
        },
        save: function () {
            try {
                const tempId =
                    this.$store.getters["frontendAddress/temp"].temp_id;
                this.loading.isActive = true;
                this.$store
                    .dispatch("frontendAddress/save", this.address)
                    .then((res) => {
                        targetService.hideTarget(
                            this.slug + "-address-modal",
                            "modal-active"
                        );
                        this.loading.isActive = false;
                        alertService.successFlip(
                            tempId === null ? 0 : 1,
                            this.$t("label.address")
                        );
                        this.address.form = {
                            full_name: "",
                            email: "",
                            country_code: "+880",
                            phone: "",
                            country: null,
                            state: null,
                            zip_code: "",
                            address: "",
                        };
                        this.address.states = [];
                        this.errors = {};
                        this.activeAddress(res.data.data);
                    })
                    .catch((err) => {
                        this.loading.isActive = false;
                        this.errors = err.response.data.errors;
                    });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
        edit: function (address) {
            if (Object.keys(this.selectedAddress).length > 0) {
                targetService.showTarget(
                    this.slug + "-address-modal",
                    "modal-active"
                );
                this.loading.isActive = true;
                this.$store
                    .dispatch("frontendAddress/edit", address.id)
                    .then(async (res) => {
                        this.loading.isActive = false;

                        if (address.state !== "") {
                            await this.$store
                                .dispatch(
                                    "frontendCountryStateCity/statesByCountry",
                                    address.country
                                )
                                .then((res) => {
                                    this.address.states = res.data.data;
                                });
                        } else {
                            await this.$store
                                .dispatch(
                                    "frontendCountryStateCity/statesByCountry",
                                    address.country
                                )
                                .then((res) => {
                                    this.address.states = res.data.data;
                                });
                            this.address.form.state = null;
                        }

                        this.address.form = {
                            full_name: address.full_name,
                            email: address.email,
                            country_code: address.country_code,
                            phone: address.phone,
                            country: address.country || "Bangladesh",
                            state: address.state,
                            address: address.address,
                        };

                        if (address.state === "") {
                            this.address.form.state = null;
                        }

                    })
                    .catch((err) => {
                        alertService.error(err.response.data.message);
                    });
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

@media (prefers-reduced-motion: reduce) {
    .address-action {
        transition: none;
    }
}
</style>
