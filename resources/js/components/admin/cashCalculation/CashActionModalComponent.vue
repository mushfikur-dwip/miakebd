<template>
    <div class="fixed inset-0 z-50 p-3 w-screen h-dvh overflow-y-auto bg-black/50 transition-all duration-300 modal-active">
        <div class="w-full rounded-xl mx-auto bg-white transition-all duration-300 max-w-lg">
            <div class="flex items-center justify-between gap-2 py-4 px-4 border-b border-slate-100">
                <div>
                    <h3 class="text-lg font-bold">{{ heading }}</h3>
                    <p class="text-xs text-gray-500">{{ outlet.name }}</p>
                </div>
                <button @click="$emit('close')" type="button" class="lab-line-circle-cross text-lg text-danger"></button>
            </div>

            <!-- Settings: MFS switch and PIN change -->
            <div v-if="action.type === 'settings'" class="p-4 space-y-6">
                <div class="rounded-lg border p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h4 class="font-semibold">{{ tr('mfs_service') }}</h4>
                            <p class="text-xs text-gray-500 mt-1">{{ tr('mfs_help') }}</p>
                        </div>
                        <span class="text-xs font-semibold px-2 py-1 rounded-full whitespace-nowrap"
                            :class="outlet.mfs_enabled ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">
                            {{ outlet.mfs_enabled ? tr('mfs_on') : tr('mfs_off') }}
                        </span>
                    </div>
                    <form class="mt-4 flex flex-wrap items-end gap-2" @submit.prevent="toggleMfs">
                        <div class="flex-1 min-w-[140px]">
                            <label class="db-field-title">{{ tr('pin') }}</label>
                            <input v-model="form.pin" type="password" inputmode="numeric" autocomplete="off" class="db-field-control" />
                        </div>
                        <button type="submit" class="db-btn py-2 text-white" :class="outlet.mfs_enabled ? 'bg-red-500' : 'bg-primary'" :disabled="busy">
                            {{ outlet.mfs_enabled ? tr('turn_off') : tr('turn_on') }}
                        </button>
                    </form>
                    <small class="db-field-alert" v-if="section === 'mfs' && errors.pin">{{ errors.pin }}</small>
                    <small class="db-field-alert d-block" v-if="section === 'mfs' && message">{{ message }}</small>
                </div>

                <form class="rounded-lg border p-4" @submit.prevent="changePin">
                    <h4 class="font-semibold mb-3">{{ tr('change_pin') }}</h4>
                    <p v-if="usesDefaultPin" class="default-pin-note mb-3 rounded-md bg-amber-50 text-amber-800 p-2 text-xs">{{ tr('default_pin_warning') }}</p>
                    <div class="space-y-3">
                        <div>
                            <label class="db-field-title">{{ tr('current_pin') }}</label>
                            <input v-model="form.current_pin" type="password" inputmode="numeric" autocomplete="off" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.current_pin">{{ errors.current_pin }}</small>
                        </div>
                        <div>
                            <label class="db-field-title">{{ tr('new_pin') }}</label>
                            <input v-model="form.new_pin" type="password" inputmode="numeric" autocomplete="new-password" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.new_pin">{{ errors.new_pin }}</small>
                        </div>
                        <div>
                            <label class="db-field-title">{{ tr('confirm_pin') }}</label>
                            <input v-model="form.new_pin_confirmation" type="password" inputmode="numeric" autocomplete="new-password" class="db-field-control" />
                        </div>
                    </div>
                    <small class="db-field-alert d-block mt-2" v-if="section === 'pin' && message">{{ message }}</small>
                    <div class="modal-btns mt-4">
                        <button type="submit" class="modal-btn-fill" :disabled="busy">
                            <i class="fa-solid fa-circle-check"></i>
                            <span>{{ tr('change_pin') }}</span>
                        </button>
                    </div>
                </form>

                <!-- Every balance of the branch to 0. The history keeps the
                     reset as lines of its own, so nothing disappears. -->
                <form class="cash-reset rounded-lg border border-red-200 p-4" @submit.prevent="reset">
                    <h4 class="font-semibold text-red-700">{{ tr('reset_title') }}</h4>
                    <p class="text-xs text-gray-500 mt-1 mb-3">{{ tr('reset_help') }}</p>
                    <label class="flex items-start gap-2 text-sm mb-3 cursor-pointer">
                        <input v-model="form.reset_ok" type="checkbox" class="mt-0.5" />
                        <span>{{ tr('reset_confirm', { branch: outlet.name }) }}</span>
                    </label>
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="flex-1 min-w-[140px]">
                            <label class="db-field-title">{{ tr('pin') }}</label>
                            <input v-model="form.reset_pin" type="password" inputmode="numeric" autocomplete="off" class="db-field-control" />
                        </div>
                        <button type="submit" class="db-btn py-2 text-white bg-red-600 disabled:opacity-50" :disabled="busy || !form.reset_ok || !form.reset_pin">
                            {{ tr('reset_button') }}
                        </button>
                    </div>
                    <small class="db-field-alert" v-if="section === 'reset' && errors.pin">{{ errors.pin }}</small>
                    <small class="db-field-alert d-block" v-if="section === 'reset' && message">{{ message }}</small>
                </form>
            </div>

            <!-- Every money movement -->
            <form v-else class="d-block w-full p-4" @submit.prevent="submit">
                <p v-if="help" class="text-xs text-gray-500 mb-4 bg-gray-50 rounded-md p-2">{{ help }}</p>

                <!-- Reverse: what is being undone -->
                <div v-if="action.type === 'reverse'" class="rounded-md border p-3 mb-4 text-sm">
                    <div class="font-semibold">{{ tr('type_' + action.entry.type) }} · {{ tr('acc_' + action.entry.account) }}</div>
                    <div class="mt-1">{{ money(action.entry.amount) }} · {{ action.entry.converted_date }} · {{ action.entry.creator }}</div>
                    <div class="text-gray-500" v-if="action.entry.note">{{ action.entry.note }}</div>
                </div>

                <div class="space-y-3">
                    <div v-if="['add', 'withdraw'].includes(action.type) || (action.type === 'count' && !action.account)">
                        <label class="db-field-title required">{{ tr('account') }}</label>
                        <select v-model.number="form.account" class="db-field-control">
                            <option v-for="account in accounts" :key="account" :value="account">{{ tr('acc_' + account) }}</option>
                        </select>
                        <small class="db-field-alert" v-if="errors.account">{{ errors.account }}</small>
                    </div>

                    <template v-if="action.type === 'transfer'">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="db-field-title required">{{ tr('from') }}</label>
                                <select v-model.number="form.from_account" class="db-field-control">
                                    <option v-for="account in accounts" :key="account" :value="account">{{ tr('acc_' + account) }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="db-field-title required">{{ tr('to') }}</label>
                                <select v-model.number="form.to_account" class="db-field-control">
                                    <option v-for="account in accounts" :key="account" :value="account">{{ tr('acc_' + account) }}</option>
                                </select>
                            </div>
                        </div>
                        <small class="db-field-alert" v-if="errors.to_account">{{ errors.to_account }}</small>
                    </template>

                    <!-- Blind count -->
                    <template v-if="action.type === 'count'">
                        <p class="text-xs text-gray-500">{{ isSim ? tr('sim_count_help') : wholeDrawer && outlet.mfs_enabled ? tr('whole_drawer_help') : tr('blind_count_help') }}</p>
                        <div v-if="employees.length">
                            <label class="db-field-title required">{{ tr('counted_by') }}</label>
                            <select v-model="form.counted_by_id" class="db-field-control counted-by">
                                <option value="">{{ tr('choose_counter') }}</option>
                                <option v-for="employee in employees" :key="employee.id" :value="employee.id">{{ employee.name }}</option>
                            </select>
                            <small class="db-field-alert" v-if="errors.counted_by_id">{{ errors.counted_by_id }}</small>
                        </div>
                        <div v-if="isSim">
                            <label class="db-field-title required">{{ [ACCOUNT.POS_CARD, ACCOUNT.POS_MFS].includes(form.account) ? tr('balance') : tr('sim_balance') }}</label>
                            <input v-model="form.counted" type="number" min="0" step="0.01" class="db-field-control" />
                            <small class="db-field-alert" v-if="errors.counted">{{ errors.counted }}</small>
                        </div>
                        <div v-else>
                            <div class="db-table-responsive border rounded-md">
                                <table class="db-table">
                                    <thead class="db-table-head border-t-0">
                                        <tr class="db-table-head-tr">
                                            <th class="db-table-head-th">{{ tr('denomination') }}</th>
                                            <th class="db-table-head-th">{{ tr('pieces') }}</th>
                                            <th class="db-table-head-th text-right">{{ tr('amount') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="db-table-body">
                                        <tr v-for="note in denominations" :key="note" class="db-table-body-tr">
                                            <td class="db-table-body-td font-medium">{{ note }}</td>
                                            <td class="db-table-body-td">
                                                <input v-model="form.pieces[note]" type="number" min="0" step="1" class="db-field-control max-w-24 h-9" />
                                            </td>
                                            <td class="db-table-body-td text-right">{{ money(note * (parseInt(form.pieces[note], 10) || 0)) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="flex justify-between mt-3 font-semibold">
                                <span>{{ tr('counted_total') }}</span>
                                <span>{{ money(countedTotal) }}</span>
                            </div>
                        </div>
                    </template>

                    <div v-if="['add', 'withdraw', 'transfer', 'mfs'].includes(action.type)">
                        <label class="db-field-title required">{{ tr('amount') }}</label>
                        <input v-model="form.amount" type="number" min="0" step="0.01" class="db-field-control" />
                        <small class="db-field-alert" v-if="errors.amount">{{ errors.amount }}</small>
                    </div>

                    <template v-if="action.type === 'mfs'">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="db-field-title">{{ tr('customer_number') }}</label>
                                <input v-model="form.party" type="text" inputmode="tel" class="db-field-control" />
                            </div>
                            <div>
                                <label class="db-field-title">{{ tr('trx_id') }}</label>
                                <input v-model="form.reference" type="text" class="db-field-control" />
                            </div>
                        </div>
                    </template>

                    <div v-if="action.type === 'withdraw'">
                        <label class="db-field-title required">{{ tr('given_to') }}</label>
                        <input v-model="form.party" type="text" class="db-field-control" />
                        <small class="db-field-alert" v-if="errors.party">{{ errors.party }}</small>
                    </div>

                    <div v-if="action.type !== 'count'">
                        <label class="db-field-title" :class="{ required: action.type !== 'mfs' }">
                            {{ ['withdraw', 'reverse', 'transfer'].includes(action.type) ? tr('reason') : tr('note') }}
                        </label>
                        <div class="flex flex-wrap gap-2 mb-2" v-if="action.type === 'add'">
                            <button type="button" class="text-xs px-2 py-1 rounded-full border" @click="form.note = tr('opening_chip')">{{ tr('opening_chip') }}</button>
                            <button type="button" class="text-xs px-2 py-1 rounded-full border" @click="form.note = tr('commission_chip')">{{ tr('commission_chip') }}</button>
                        </div>
                        <input v-model="form.note" type="text" maxlength="500" class="db-field-control" />
                        <small class="db-field-alert" v-if="errors.note">{{ errors.note }}</small>
                    </div>

                    <div v-if="needsPin">
                        <label class="db-field-title required">{{ tr('pin') }}</label>
                        <input v-model="form.pin" type="password" inputmode="numeric" autocomplete="off" class="db-field-control" />
                        <small class="db-field-alert" v-if="errors.pin">{{ errors.pin }}</small>
                    </div>
                </div>

                <!-- The count as saved: the notes, the total and - for a balance
                     viewer only, so a cashier's count stays blind - what was
                     expected and the difference. -->
                <div v-if="result" class="mt-4 rounded-lg border overflow-hidden text-sm">
                    <!-- The point of a count: does it match the calculation? A
                         count never moves money, so a mismatch means count again. -->
                    <div class="count-verdict flex items-start gap-2 px-3 py-3 font-semibold"
                        :class="result.matched ? 'bg-green-50 text-green-800' : 'bg-amber-50 text-amber-900'">
                        <i :class="result.matched ? 'fa-solid fa-circle-check text-green-600' : 'fa-solid fa-triangle-exclamation text-amber-600'"
                            class="text-lg leading-5" aria-hidden="true"></i>
                        <span>{{ result.matched ? tr('count_correct') : tr('count_wrong') }}</span>
                    </div>
                    <div class="px-3 py-1.5 border-t bg-gray-50 text-xs text-gray-500">
                        {{ tr('count_saved') }}<template v-if="result.by"> · {{ tr('counted_by') }}: <b>{{ result.by }}</b></template> · {{ tr('count_is_check') }}
                    </div>
                    <table v-if="result.denominations && result.denominations.length" class="w-full">
                        <tbody>
                            <tr v-for="line in result.denominations" :key="line.note" class="border-t border-gray-100">
                                <td class="px-3 py-1.5">{{ line.note }} × {{ line.pieces }}</td>
                                <td class="px-3 py-1.5 text-right">{{ money(line.amount) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="flex justify-between px-3 py-2 border-t font-semibold">
                        <span>{{ tr('counted_total') }}</span><span>{{ money(result.counted) }}</span>
                    </div>
                    <template v-if="result.expected !== undefined">
                        <div class="flex justify-between px-3 py-2 border-t">
                            <span>{{ tr('expected') }}</span><span>{{ money(result.expected) }}</span>
                        </div>
                        <div class="flex justify-between px-3 py-2 border-t font-bold"
                            :class="result.variance < 0 ? 'bg-red-50 text-red-700' : result.variance > 0 ? 'bg-amber-50 text-amber-800' : 'bg-green-50 text-green-700'">
                            <span>{{ tr('difference') }}</span>
                            <span>{{ money(result.variance, true) }} · {{ result.variance < 0 ? tr('short') : result.variance > 0 ? tr('over') : tr('exact') }}</span>
                        </div>
                    </template>
                </div>

                <small class="db-field-alert d-block mt-3" v-if="message">{{ message }}</small>

                <div class="modal-btns mt-6">
                    <button v-if="!result" type="submit" class="modal-btn-fill" :disabled="busy">
                        <i class="fa-solid fa-circle-check"></i>
                        <span>{{ tr('save') }}</span>
                    </button>
                    <!-- Not matched: straight back to an empty count. -->
                    <button v-if="result && !result.matched" type="button" class="modal-btn-fill" @click="countAgain">
                        <i class="fa-solid fa-rotate-right"></i>
                        <span>{{ tr('count_again') }}</span>
                    </button>
                    <button type="button" class="modal-btn-outline" @click="$emit('close')">
                        <i class="fa-solid fa-circle-xmark"></i>
                        <span>{{ tr('close') }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script>
import alertService from "../../../services/alertService";
import { tr, ACCOUNT, ALL_ACCOUNTS, BASE_ACCOUNTS, NOTE_ACCOUNTS } from "./cashCalculationText";
import { save, failure } from "./cashHttp";

export default {
    name: "CashActionModalComponent",
    props: {
        action: { type: Object, required: true },
        outlet: { type: Object, required: true },
        denominations: { type: Array, default: () => [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1] },
        money: { type: Function, required: true },
        // Who can be named under "Counted by".
        employees: { type: Array, default: () => [] },
        usesDefaultPin: { type: Boolean, default: false },
    },
    emits: ["close", "saved"],
    data() {
        return {
            ACCOUNT: ACCOUNT,
            busy: false,
            message: "",
            section: "",
            errors: {},
            result: null,
            form: {
                account: this.action.account || ACCOUNT.DRAWER,
                from_account: ACCOUNT.DRAWER,
                to_account: this.outlet.mfs_enabled ? ACCOUNT.BKASH_CASH : ACCOUNT.DRAWER,
                amount: "",
                note: "",
                party: "",
                reference: "",
                pin: "",
                counted: "",
                pieces: {},
                current_pin: "",
                new_pin: "",
                new_pin_confirmation: "",
                counted_by_id: "",
                reset_pin: "",
                reset_ok: false,
            },
        };
    },
    created() {
        // Lets a save whose answer was lost be sent again without saving twice.
        this.resend = {};
    },
    computed: {
        accounts: function () {
            return this.outlet.mfs_enabled ? ALL_ACCOUNTS : BASE_ACCOUNTS;
        },
        needsPin: function () {
            return ["withdraw", "transfer", "reverse"].includes(this.action.type);
        },
        // E-money (a SIM, the till's card or MFS) is counted by typing the
        // balance; only notes are counted note by note.
        isSim: function () {
            return !NOTE_ACCOUNTS.includes(this.form.account);
        },
        // The drawer holds every note - the count checks them all together.
        wholeDrawer: function () {
            return this.form.account === ACCOUNT.DRAWER;
        },
        countedTotal: function () {
            return this.denominations.reduce((sum, note) => sum + note * (parseInt(this.form.pieces[note], 10) || 0), 0);
        },
        heading: function () {
            const provider = tr((this.action.provider || "bkash") + "_agent");
            switch (this.action.type) {
                case "add": return tr("add_money");
                case "withdraw": return tr("withdraw");
                case "transfer": return tr("transfer");
                case "reverse": return tr("reverse");
                case "settings": return tr("settings");
                case "mfs": return provider + " · " + tr(this.action.kind);
                case "count": return this.wholeDrawer ? tr("count_all_cash") : tr("count") + " · " + tr("acc_" + this.form.account);
                default: return "";
            }
        },
        help: function () {
            if (this.action.type === "mfs") {
                return tr(this.action.kind + "_help");
            }
            if (this.action.type === "reverse") {
                return tr("reverse_help");
            }
            return "";
        },
    },
    methods: {
        tr: tr,
        payload: function () {
            const base = { outlet_id: this.outlet.id };
            const f = this.form;
            switch (this.action.type) {
                case "add":
                    return ["admin/cash-calculation/add", { ...base, account: f.account, amount: f.amount, note: f.note }];
                case "withdraw":
                    return ["admin/cash-calculation/withdraw", { ...base, account: f.account, amount: f.amount, party: f.party, note: f.note, pin: f.pin }];
                case "transfer":
                    return ["admin/cash-calculation/transfer", { ...base, from_account: f.from_account, to_account: f.to_account, amount: f.amount, note: f.note, pin: f.pin }];
                case "mfs":
                    return ["admin/cash-calculation/mfs", {
                        ...base, provider: this.action.provider, kind: this.action.kind, amount: f.amount,
                        party: f.party, reference: f.reference, note: f.note,
                    }];
                case "count": {
                    const by = f.counted_by_id ? { counted_by_id: f.counted_by_id } : {};
                    return ["admin/cash-calculation/count", this.isSim
                        ? { ...base, ...by, account: f.account, counted: f.counted }
                        : { ...base, ...by, account: f.account, denominations: this.pieces() }];
                }
                case "reverse":
                    return ["admin/cash-calculation/reverse/" + this.action.entry.id, { note: f.note, pin: f.pin }];
            }
        },
        // Only the notes actually counted; an empty drawer still sends the
        // 0-piece row so the server records a count of zero.
        pieces: function () {
            const pieces = {};
            this.denominations.forEach((note) => {
                const quantity = parseInt(this.form.pieces[note], 10) || 0;
                if (quantity > 0) {
                    pieces[note] = quantity;
                }
            });
            if (Object.keys(pieces).length === 0) {
                pieces[this.denominations[0]] = 0;
            }
            return pieces;
        },
        submit: function () {
            if (this.action.type === "count" && this.employees.length && !this.form.counted_by_id) {
                this.errors = { counted_by_id: tr("choose_counter_first") };
                return;
            }
            const [url, data] = this.payload();
            this.send(url, data, "", (res) => {
                if (this.action.type === "count" && res.data.data) {
                    // The count stays on screen - its notes, and for a balance
                    // viewer the difference - while the page refreshes behind it.
                    this.result = res.data.data;
                    this.$emit("saved", false);
                    return;
                }
                if (this.action.type === "add") {
                    // The page shows the big green tick for this one.
                    this.$emit("saved", true, { kind: "add", account: this.form.account, amount: Number(this.form.amount) });
                    return;
                }
                alertService.success(this.action.type === "count" ? tr("count_saved") : tr("saved"));
                this.$emit("saved", true);
            });
        },
        countAgain: function () {
            this.result = null;
            this.form.pieces = {};
            this.form.counted = "";
            this.message = "";
            this.errors = {};
        },
        toggleMfs: function () {
            this.send("admin/cash-calculation/mfs-toggle", {
                outlet_id: this.outlet.id,
                enabled: !this.outlet.mfs_enabled,
                pin: this.form.pin,
            }, "mfs", () => {
                alertService.success(tr("saved"));
                this.$emit("saved", true);
            });
        },
        reset: function () {
            this.send("admin/cash-calculation/reset", {
                outlet_id: this.outlet.id,
                pin: this.form.reset_pin,
            }, "reset", (res) => {
                const n = res.data.data ? res.data.data.accounts_reset : 0;
                alertService.success(n ? tr("reset_done", { n }) : tr("reset_nothing"));
                this.$emit("saved", true);
            });
        },
        changePin: function () {
            this.send("admin/cash-calculation/pin", {
                outlet_id: this.outlet.id,
                current_pin: this.form.current_pin,
                new_pin: this.form.new_pin,
                new_pin_confirmation: this.form.new_pin_confirmation,
            }, "pin", () => {
                alertService.success(tr("pin_changed"));
                this.$emit("saved", true);
            });
        },
        send: function (url, data, section, done) {
            this.busy = true;
            this.message = "";
            this.section = section;
            this.errors = {};
            save(url, data, this.resend).then((res) => {
                this.busy = false;
                done(res);
            }).catch((err) => {
                this.busy = false;
                const body = err.response?.data || {};
                if (body.errors) {
                    Object.keys(body.errors).forEach((field) => {
                        this.errors[field] = body.errors[field][0];
                    });
                    // Denomination and other nested fields have no input of
                    // their own, so their message goes to the general line.
                    if (Object.keys(body.errors).some((field) => field.includes("."))) {
                        this.message = body.message;
                    }
                } else {
                    this.message = failure(err);
                }
                // A refused PIN is typed again; a lost connection keeps it, so
                // tapping again resends exactly the same save.
                if (!err.isNetworkError) {
                    this.form.pin = "";
                    this.form.reset_pin = "";
                }
            });
        },
    },
};
</script>
