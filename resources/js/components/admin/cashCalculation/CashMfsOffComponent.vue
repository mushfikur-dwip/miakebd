<template>
    <div class="rounded-lg border border-dashed border-gray-300 p-4">
        <h4 class="font-semibold">{{ tr('mfs_off_title') }}</h4>
        <p class="text-xs text-gray-500 mt-1 mb-3">{{ tr('mfs_off_help') }}</p>
        <form class="flex flex-wrap items-center gap-2" @submit.prevent="turnOn">
            <input v-model="pin" type="password" inputmode="numeric" autocomplete="off" :placeholder="tr('pin')"
                class="db-field-control h-10 w-40" />
            <button type="submit" class="db-btn py-2 text-white bg-primary" :disabled="busy">{{ tr('turn_on') }}</button>
        </form>
        <small class="db-field-alert d-block mt-1" v-if="error">{{ error }}</small>
    </div>
</template>

<script>
import axios from "axios";
import alertService from "../../../services/alertService";
import { tr } from "./cashCalculationText";

/**
 * Shown in place of the bKash / Nagad boxes while a branch has agent service
 * off, so switching it on is on the page rather than hidden in Settings.
 */
export default {
    name: "CashMfsOffComponent",
    props: {
        outletId: { type: Number, required: true },
    },
    emits: ["saved"],
    data() {
        return { pin: "", error: "", busy: false };
    },
    methods: {
        tr: tr,
        turnOn: function () {
            this.error = "";
            this.busy = true;
            axios.post("admin/cash-calculation/mfs-toggle", {
                outlet_id: this.outletId,
                enabled: true,
                pin: this.pin,
            }).then(() => {
                this.busy = false;
                this.pin = "";
                alertService.success(tr("saved"));
                this.$emit("saved");
            }).catch((err) => {
                this.busy = false;
                this.pin = "";
                const body = err.response?.data || {};
                this.error = body.errors ? Object.values(body.errors)[0][0] : (body.message || tr("something_wrong"));
            });
        },
    },
};
</script>
