<template>
    <div class="fixed inset-0 z-50 p-3 w-screen h-dvh overflow-y-auto bg-black/50 transition-all duration-300"
        :class="{ 'modal-active': modal.isShowModal }">
        <div class="w-full rounded-xl mx-auto bg-white transition-all duration-300 max-w-2xl">
            <div class="flex items-center justify-between gap-2 py-4 px-4 border-b border-slate-100">
                <div>
                    <h3 class="text-lg font-bold capitalize">{{ item.product_name }}</h3>
                    <p class="text-sm text-gray-500" v-if="item.variation_names">{{ item.variation_names }}</p>
                    <p class="text-xs text-gray-400" v-if="item.sku">{{ $t('label.sku') }}: {{ item.sku }}</p>
                </div>
                <button @click="close" type="button" class="lab-line-circle-cross text-lg text-danger"></button>
            </div>

            <form class="d-block w-full p-4" @submit.prevent="save">
                <p class="text-sm text-gray-500 mb-3">{{ $t('message.stock_edit_hint') }}</p>

                <div class="db-table-responsive border rounded-md">
                    <table class="db-table">
                        <thead class="db-table-head border-t-0">
                            <tr class="db-table-head-tr">
                                <th class="db-table-head-th">{{ $t('label.branch') }}</th>
                                <th class="db-table-head-th">{{ $t('label.current_stock') }}</th>
                                <th class="db-table-head-th">{{ $t('label.add_quantity') }}</th>
                                <th class="db-table-head-th">{{ $t('label.new_total') }}</th>
                            </tr>
                        </thead>
                        <tbody class="db-table-body">
                            <tr v-for="(row, index) of rows" :key="index" class="db-table-body-tr">
                                <td class="db-table-body-td font-medium">{{ row.outlet_name }}</td>
                                <!-- Never a negative: a branch that was billed
                                     past its stock reads 0, with the overdraft
                                     spelled out underneath. -->
                                <td class="db-table-body-td">
                                    {{ row.current }}
                                    <span v-if="row.billed_beyond > 0" class="block text-[11px] text-amber-700">
                                        {{ $t('message.stock_billed_beyond', { n: row.billed_beyond }) }}
                                    </span>
                                </td>
                                <td class="db-table-body-td">
                                    <input v-model="row.add" type="number" min="0" step="1" inputmode="numeric" class="db-field-control max-w-32" />
                                </td>
                                <!-- Off the real figure, not the shown one: 1
                                     added to a branch at -1 is 0, not 1. -->
                                <td class="db-table-body-td font-semibold">{{ newTotal(row) }}</td>
                            </tr>
                            <tr v-if="rows.length === 0" class="db-table-body-tr">
                                <td class="db-table-body-td text-center" colspan="4">
                                    {{ $t('message.no_branch_found') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between mt-4">
                    <span class="text-sm font-medium">
                        {{ $t('label.total') }}: <b>{{ total }}</b>
                    </span>
                    <span class="text-xs text-gray-400">{{ $t('message.stock_edit_total_hint') }}</span>
                </div>

                <small class="db-field-alert d-block mt-2" v-if="error">{{ error }}</small>

                <div class="modal-btns mt-6">
                    <button type="submit" class="modal-btn-fill" :disabled="rows.length === 0">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>{{ $t('button.save') }}</span>
                    </button>
                    <button type="button" class="modal-btn-outline" @click="close">
                        <i class="fa-solid fa-circle-xmark"></i>
                        <span>{{ $t('button.close') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script>
import alertService from "../../../services/alertService";

export default {
    name: "StockEditModalComponent",
    props: ["item", "modal", "outlets"],
    emits: ["saved"],
    data() {
        return {
            rows: [],
            error: "",
        };
    },
    computed: {
        // The real figures, so the footer agrees with "what the website shows"
        // rather than quietly counting an overdraft as nothing.
        total: function () {
            return this.rows.reduce((sum, row) => sum + this.newTotal(row), 0);
        },
    },
    mounted() {
        this.build();
    },
    methods: {
        /**
         * One editable line per branch, seeded from what the row already
         * carries. The unassigned pool only appears when it actually holds
         * something, so it can be emptied into a branch but never filled.
         */
        build: function () {
            const held = {};
            (this.item.outlet_stocks || []).forEach((entry) => {
                held[entry.outlet_id === null ? 0 : entry.outlet_id] = entry;
            });

            this.rows = this.outlets.map((outlet) => this.line(outlet.id, outlet.name, held[outlet.id]));

            // A branch that has since been deactivated still shows in the table
            // if it holds stock, so it needs a line here too - otherwise that
            // stock is visible but not correctable.
            (this.item.outlet_stocks || []).forEach((entry) => {
                if (entry.outlet_id === null || this.rows.some(row => row.outlet_id === entry.outlet_id)) {
                    return;
                }
                this.rows.push(this.line(entry.outlet_id, entry.outlet_name, entry));
            });

            if (held[0]) {
                this.rows.push(this.line(0, this.$t('label.unassigned'), held[0]));
            }
        },
        /**
         * One branch's line. `current` is what the page shows (never below
         * zero) and `real` is what is actually on record - the arithmetic runs
         * on `real`, so adding onto an oversold branch lands on the right
         * number instead of claiming stock that is still owed.
         */
        line: function (outletId, outletName, entry) {
            const shown = entry ? entry.quantity : 0;
            const real = entry && entry.real_quantity !== undefined ? entry.real_quantity : shown;

            return {
                outlet_id: outletId,
                outlet_name: outletName,
                current: shown,
                real: real,
                billed_beyond: entry ? (entry.billed_beyond || 0) : 0,
                add: 0,
            };
        },
        newTotal: function (row) {
            return row.real + (parseInt(row.add, 10) || 0);
        },
        close: function () {
            this.modal.isShowModal = false;
        },
        save: function () {
            this.error = "";

            // Units that arrived, added on top: never negative, never a fraction.
            const invalid = this.rows.find(row => row.add === "" || row.add === null || !/^[0-9]+$/.test(String(row.add)));
            if (invalid) {
                this.error = this.$t('message.stock_edit_number_required');
                return;
            }

            const fd = new FormData();
            fd.append('product_id', this.item.product_id);
            fd.append('variation_id', this.item.variation_id || 0);
            fd.append('stocks', JSON.stringify(this.rows.map(row => ({
                outlet_id: row.outlet_id,
                add: parseInt(row.add, 10),
            }))));

            this.$store.dispatch('stock/updateItem', fd).then(() => {
                alertService.success(this.$t('message.stock_updated'));
                this.close();
                this.$emit('saved');
            }).catch((err) => {
                this.error = err.response?.data?.message || err.response?.data?.errors?.global?.[0] || "";
                if (!this.error) {
                    alertService.error(this.$t('message.something_wrong'));
                }
            });
        },
    },
};
</script>
