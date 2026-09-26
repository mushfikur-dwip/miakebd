<template>
    <LoadingComponent :props="loading" />

    <button
        data-modal="#address"
        @click="showTarget()"
        type="button"
        class="w-full rounded-2xl py-10 flex items-center justify-center gap-2.5 text-primary bg-[#FFF4F1]"
    >
        <i class="lab-fill-circle-plus text-lg"></i>
        <span class="text-lg font-semibold capitalize">{{
            addButton.title
        }}</span>
    </button>
    <div
        id="address"
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
                    @click="reset()"
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
                            >{{ $t("label.full_name") }}</label
                        >
                        <input
                            type="text"
                            v-model="props.form.full_name"
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
                            >{{ $t("label.phone") }}</label
                        >
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
                                :value="props.form.phone"
                                @input="onPhone($event)"
                                v-bind:class="errors.phone ? 'invalid' : ''"
                                type="tel"
                                inputmode="numeric"
                                autocomplete="tel-national"
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
                            >{{ $t("label.email") }}</label
                        >
                        <input
                            type="email"
                            v-model="props.form.email"
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
                            >{{ $t("label.district") }}</label
                        >
                        <!-- Native select over the bundled list. The searchable
                             dropdown read a list fetched on mount that Cancel and
                             Save both emptied, so it was blank from the second
                             address on; it also called a callCities() that no
                             longer exists on every pick. -->
                        <select
                            id="state"
                            v-model="props.form.state"
                            autocomplete="address-level2"
                            :class="errors.state ? 'invalid' : ''"
                            class="w-full h-12 px-4 rounded-lg text-base bg-white border border-[#D9DBE9] hover:border-primary/30 focus-within:border-primary/30 transition-all duration-500"
                        >
                            <option :value="null" disabled>{{ $t("label.select_district") }}</option>
                            <option v-for="district in districts" :key="district.name" :value="district.name">
                                {{ district.name }} ({{ district.bn_name }})
                            </option>
                        </select>
                        <small class="db-field-alert" v-if="errors.state">
                            {{ errors.state[0] }}
                        </small>
                    </div>

                    <!-- Row 4: Full Address (Full Width) -->
                    <div class="form-col-12">
                        <label
                            class="text-sm font-medium capitalize mb-1 field-title required"
                            for="street_address"
                            >{{ $t("label.full_address") }}</label
                        ><input
                            type="text"
                            :class="errors.address ? 'invalid' : ''"
                            v-model="props.form.address"
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
                                {{ $t("button.add_address") }}</button
                            ><button
                                @click="reset()"
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
import targetService from "../../../../services/targetService";
import alertService from "../../../../services/alertService";
import phoneService from "../../../../services/phoneService";
import bdDistricts from "../../../../data/bdDistricts";
import LoadingComponent from "../../components/LoadingComponent";

export default {
    name: "AddressComponent",
    components: { LoadingComponent },
    props: ["props"],
    data() {
        return {
            loading: {
                isActive: false,
            },
            errors: {},
            targetID: "address",
            addClass: "modal-active",
            districts: bdDistricts,
        };
    },
    mounted() {
        // Bangladesh only; the districts are bundled, so nothing to load.
        this.props.form.country = "Bangladesh";
        this.props.form.country_code = "+880";
    },
    computed: {
        addButton: function () {
            return { title: this.$t("button.add_new_address") };
        },
        countries: function () {
            return this.$store.getters["frontendCountryStateCity/countries"];
        },
    },
    methods: {
        // Bengali digits converted, everything else dropped - the keypress
        // filter this replaces blocked Bangla-keyboard numbers entirely.
        onPhone(e) {
            const cleaned = phoneService.clean(e.target.value);
            if (e.target.value !== cleaned) {
                e.target.value = cleaned;
            }
            this.props.form.phone = cleaned;
        },
        showTarget: function () {
            targetService.showTarget(this.targetID, this.addClass);
        },

        callCountry: function () {
            this.$store.dispatch("frontendCountryStateCity/countries");
        },

        callStates: function (countryName) {
            this.props.form.state = null;

            this.$store
                .dispatch(
                    "frontendCountryStateCity/statesByCountry",
                    countryName
                )
                .then((res) => {
                    this.props.states = res.data.data;
                });
        },
        reset: function () {
            targetService.hideTarget(this.targetID, this.addClass);
            this.$store.dispatch("frontendAddress/reset").then().catch();
            this.errors = {};
            this.$props.props.form = {
                full_name: "",
                email: "",
                country_code: "+880",
                phone: "",
                country: "Bangladesh",
                state: null,
                address: "",
            };
            this.$props.props.states = [];
        },
        save: function () {
            try {
                const tempId =
                    this.$store.getters["frontendAddress/temp"].temp_id;
                this.loading.isActive = true;
                this.$store
                    .dispatch("frontendAddress/save", this.props)
                    .then((res) => {
                        targetService.hideTarget(this.targetID, this.addClass);
                        this.loading.isActive = false;
                        alertService.successFlip(
                            tempId === null ? 0 : 1,
                            this.$t("label.address")
                        );
                        this.props.form = {
                            full_name: "",
                            email: "",
                            country_code: "+880",
                            phone: "",
                            country: "Bangladesh",
                            state: null,
                            address: "",
                        };
                        this.$props.props.states = [];
                        this.errors = {};
                    })
                    .catch((err) => {
                        this.loading.isActive = false;
                        // No response at all on a dropped connection; reading
                        // .data off undefined threw and the button froze.
                        const data = err && err.response ? err.response.data : {};
                        this.errors = data.errors || {};
                        if (!data.errors) {
                            alertService.error(data.message || this.$t("message.check_connection"));
                        }
                    });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
    },
};
</script>
