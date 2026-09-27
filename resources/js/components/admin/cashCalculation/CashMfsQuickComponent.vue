<template>
    <div class="rounded-lg border p-3" :class="theme.box">
        <div class="text-sm font-semibold mb-2" :class="theme.label">{{ tr(provider + '_agent') }}</div>
        <form v-for="kind in kinds" :key="kind" class="mb-2 last:mb-0" @submit.prevent="save(kind)">
            <div class="flex items-center gap-2">
                <label class="w-20 shrink-0 text-sm font-medium" :title="tr(kind + '_help')">{{ tr(kind) }}</label>
                <input v-model="rows[kind].amount" type="number" min="0" step="0.01" inputmode="decimal"
                    :placeholder="tr('amount')" class="db-field-control h-9 min-w-0 flex-1" />
                <input v-model="rows[kind].party" type="text" inputmode="tel" :placeholder="tr('customer_number_short')"
                    class="db-field-control h-9 min-w-0 w-28 hidden sm:block" />
                <button type="submit" class="db-btn h-9 px-3 text-white shrink-0" :class="theme.button" :disabled="rows[kind].busy">
                    {{ tr('add') }}
                </button>
            </div>
            <small class="db-field-alert d-block ml-[5.5rem]" v-if="rows[kind].error">{{ rows[kind].error }}</small>
        </form>
        <p class="text-[11px] text-gray-500 mt-2">{{ provider === 'recharge' ? tr('mfs_quick_help_recharge') : tr('mfs_quick_help') }}</p>
    </div>
</template>

<script>
import axios from "axios";
import alertService from "../../../services/alertService";
import { tr, KINDS, THEME } from "./cashCalculationText";

const blank = () => ({ amount: "", party: "", error: "", busy: false });

/**
 * Agent entry straight on the page - In and Out for bKash and Nagad, Recharge
 * for Recharge - each a box and a button. The counter makes these dozens of
 * times a day, so they do not sit behind a dialog.
 */
export default {
    name: "CashMfsQuickComponent",
    props: {
        provider: { type: String, required: true },
        outletId: { type: Number, required: true },
    },
    emits: ["saved"],
    data() {
        const rows = {};
        (KINDS[this.provider] || []).forEach((kind) => { rows[kind] = blank(); });
        return { rows };
    },
    computed: {
        kinds: function () {
            return KINDS[this.provider] || [];
        },
        theme: function () {
            return THEME[this.provider] || THEME.bkash;
        },
    },
    methods: {
        tr: tr,
        save: function (kind) {
            const row = this.rows[kind];
            row.error = "";
            if (!(Number(row.amount) > 0)) {
                row.error = tr("amount_required");
                return;
            }
            row.busy = true;
            axios.post("admin/cash-calculation/mfs", {
                outlet_id: this.outletId,
                provider: this.provider,
                kind: kind,
                amount: row.amount,
                party: row.party || null,
            }).then(() => {
                this.rows[kind] = blank();
                alertService.success(tr(this.provider + "_agent") + " · " + tr(kind) + " · " + tr("saved"));
                this.$emit("saved");
            }).catch((err) => {
                row.busy = false;
                const body = err.response?.data || {};
                row.error = body.errors ? Object.values(body.errors)[0][0] : (body.message || tr("something_wrong"));
            });
        },
    },
};
</script>
