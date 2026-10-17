<template>
    <div class="fixed inset-0 z-50 p-3 w-screen h-dvh overflow-y-auto bg-black/50 transition-all duration-300 modal-active">
        <div class="w-full rounded-xl mx-auto bg-white transition-all duration-300 max-w-lg">
            <div class="flex items-center justify-between gap-2 py-4 px-4 border-b border-slate-100">
                <div>
                    <h3 class="text-lg font-bold" :class="isReverse ? 'text-red-600' : ''">
                        🔒 {{ isReverse ? tr('reverse_sale') : tr('change_method') }}
                    </h3>
                    <p class="text-xs text-gray-500">#{{ order.order_serial_no }} · {{ order.branch_name }}</p>
                </div>
                <button @click="$emit('close')" type="button" class="lab-line-circle-cross text-lg text-danger" aria-label="Close"></button>
            </div>

            <form class="d-block w-full p-4" @submit.prevent="submit">
                <div class="rounded-md border p-3 mb-4 text-sm flex flex-wrap items-center justify-between gap-2">
                    <span class="font-semibold">{{ order.total_currency_price }}</span>
                    <span class="text-gray-600">{{ tr('paid_now') }}: <b>{{ order.pos_payment_method_name }}</b>
                        <span v-if="order.pos_payment_note"> ({{ order.pos_payment_note }})</span></span>
                </div>
                <p class="text-xs mb-4 rounded-md p-2" :class="isReverse ? 'bg-red-50 text-red-700' : 'bg-gray-50 text-gray-600'">
                    {{ isReverse ? tr('reverse_help') : tr('method_help') }}
                </p>

                <div class="space-y-3">
                    <template v-if="!isReverse">
                        <div>
                            <label class="db-field-title required">{{ tr('new_method') }}</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button v-for="option in methodOptions" :key="option.value" type="button"
                                    class="pos-method-option rounded-lg border px-3 py-2 text-sm font-medium text-left transition"
                                    :class="form.method === option.value ? 'border-primary bg-primary/10 text-primary' : 'border-gray-200 hover:border-primary'"
                                    :aria-pressed="form.method === option.value" @click="pick(option.value)">
                                    {{ option.label }}
                                </button>
                            </div>
                            <small class="db-field-alert" v-if="errors.pos_payment_method">{{ errors.pos_payment_method }}</small>
                        </div>
                        <div v-if="noteLabel">
                            <label class="db-field-title required">{{ noteLabel }}</label>
                            <input v-model="form.note" type="text" class="db-field-control"
                                :inputmode="form.method === METHOD.CARD ? 'numeric' : 'text'"
                                :maxlength="form.method === METHOD.CARD ? 4 : 100" />
                            <small class="db-field-alert" v-if="errors.pos_payment_note">{{ errors.pos_payment_note }}</small>
                        </div>
                    </template>

                    <div>
                        <label class="db-field-title required">{{ tr('reason') }}</label>
                        <input v-model="form.reason" type="text" maxlength="500" class="db-field-control" :placeholder="tr('reason_placeholder')" />
                        <small class="db-field-alert" v-if="errors.reason">{{ errors.reason }}</small>
                    </div>

                    <div>
                        <label class="db-field-title required">{{ isReverse ? tr('branch_pin') : tr('method_pin') }}</label>
                        <input v-model="form.pin" type="password" inputmode="numeric" autocomplete="off" class="db-field-control" />
                        <small class="db-field-alert" v-if="errors.pin">{{ errors.pin }}</small>
                    </div>
                </div>

                <small class="db-field-alert d-block mt-3" v-if="message">{{ message }}</small>
                <p class="text-[11px] text-gray-500 mt-3">{{ tr('locked_note') }}</p>

                <div class="modal-btns mt-5">
                    <button type="submit" class="modal-btn-fill" :class="isReverse ? '!bg-red-600 !border-red-600' : ''" :disabled="busy">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>{{ isReverse ? tr('reverse_sale') : tr('change_method') }}</span>
                    </button>
                    <button type="button" class="modal-btn-outline" @click="$emit('close')">
                        <i class="fa-solid fa-circle-xmark"></i>
                        <span>{{ tr('cancel') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script>
import alertService from "../../../services/alertService";
import posPaymentMethodEnum from "../../../enums/modules/posPaymentMethodEnum";
import { save, failure } from "../cashCalculation/cashHttp";
import { tr } from "./posSaleText";

export default {
    name: "PosSaleActionModalComponent",
    props: {
        // "reverse" or "method"
        action: { type: String, required: true },
        order: { type: Object, required: true },
    },
    emits: ["close", "saved"],
    data() {
        return {
            METHOD: posPaymentMethodEnum,
            busy: false,
            message: "",
            errors: {},
            form: { method: null, note: "", reason: "", pin: "" },
        };
    },
    created() {
        // Lets a save whose answer was lost be sent again without applying twice.
        this.resend = {};
    },
    computed: {
        isReverse: function () {
            return this.action === "reverse";
        },
        // Every method but the one the sale already shows.
        methodOptions: function () {
            return [
                { value: posPaymentMethodEnum.CASH, label: this.$t("label.cash") },
                { value: posPaymentMethodEnum.CARD, label: this.$t("label.card") },
                { value: posPaymentMethodEnum.MOBILE_BANKING, label: this.$t("label.mfs") },
                { value: posPaymentMethodEnum.OTHER, label: this.$t("label.other") },
            ].filter((option) => option.value !== Number(this.order.pos_payment_method));
        },
        // What the POS asks for with each method: card digits, a TrxID, a note.
        noteLabel: function () {
            switch (this.form.method) {
                case posPaymentMethodEnum.CARD: return this.$t("label.enter_card_last_4_digits");
                case posPaymentMethodEnum.MOBILE_BANKING: return this.$t("label.enter_transaction_id");
                case posPaymentMethodEnum.OTHER: return this.$t("label.enter_note");
                default: return "";
            }
        },
    },
    methods: {
        tr: tr,
        pick: function (method) {
            if (this.form.method !== method) {
                this.form.method = method;
                this.form.note = "";
            }
        },
        submit: function () {
            const id = this.order.id;
            const [url, data] = this.isReverse
                ? ["admin/pos-order/reverse/" + id, { reason: this.form.reason, pin: this.form.pin }]
                : ["admin/pos-order/change-method/" + id, {
                    pos_payment_method: this.form.method,
                    pos_payment_note: this.noteLabel ? this.form.note : null,
                    reason: this.form.reason,
                    pin: this.form.pin,
                }];

            this.busy = true;
            this.message = "";
            this.errors = {};
            save(url, data, this.resend).then((res) => {
                this.busy = false;
                alertService.success(this.isReverse ? tr("reversed_ok") : tr("method_ok"));
                this.$emit("saved", res.data.data);
            }).catch((err) => {
                this.busy = false;
                const body = (err.response && err.response.data) || {};
                if (body.errors) {
                    Object.keys(body.errors).forEach((field) => {
                        this.errors[field] = body.errors[field][0];
                    });
                } else {
                    this.message = failure(err);
                }
                // A refused PIN is typed again; a lost connection keeps it, so
                // tapping again resends exactly the same request.
                if (!err.isNetworkError) {
                    this.form.pin = "";
                }
            });
        },
    },
};
</script>
