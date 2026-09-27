<template>
    <LoadingComponent :props="loading" />
    <div class="col-12">
        <!-- Header: branch tabs, date, settings -->
        <div class="db-card mb-6">
            <div class="db-card-header border-none flex-wrap gap-3">
                <h3 class="db-card-title">{{ tr('title') }}</h3>
                <div class="db-card-filter flex-wrap gap-2">
                    <!-- The admin's own date picker, as on the other report screens,
                         rather than a native date input: the site-wide custom.css
                         restyles native date inputs so any click on one opens the
                         browser calendar. -->
                    <template v-if="canSeeBalance">
                        <button type="button" class="db-btn py-2 px-3 bg-gray-100" @click="shiftDay(-1)" aria-label="Previous day">‹</button>
                        <div class="w-40">
                            <Datepicker v-model="pickerDate" hideInputIcon autoApply :enableTimePicker="false" :clearable="false"
                                :maxDate="maxDate" format="dd-MM-yyyy" utc="false" />
                        </div>
                        <button type="button" class="db-btn py-2 px-3 bg-gray-100" :disabled="date >= today" @click="shiftDay(1)" aria-label="Next day">›</button>
                        <button v-if="date !== today" type="button" class="db-btn py-2 bg-gray-100" @click="date = today; load()">{{ tr('today') }}</button>
                    </template>
                    <button v-if="summary" type="button" class="db-btn py-2 text-white bg-primary" @click="open({ type: 'settings' })">
                        <i class="lab lab-line-settings"></i>
                        <span>{{ tr('settings') }}</span>
                    </button>
                </div>
            </div>
            <div class="px-4 pb-4 flex flex-wrap gap-2">
                <button v-for="outlet in outlets" :key="outlet.id" type="button" @click="selectOutlet(outlet.id)"
                    class="px-4 py-2 rounded-lg text-sm font-medium border transition"
                    :class="outlet.id === outletId ? 'bg-primary text-white border-primary' : 'bg-white text-heading border-gray-200 hover:border-primary'">
                    {{ outlet.name }}
                    <span v-if="outlet.mfs_enabled" class="ml-1 text-[10px] opacity-80">MFS</span>
                </button>
                <span v-if="loaded && outlets.length === 0" class="text-sm text-gray-500">{{ tr('no_branch') }}</span>
            </div>
        </div>

        <div v-if="summary && summary.pin_locked_minutes > 0" class="mb-4 rounded-lg bg-red-50 text-red-700 p-3 text-sm font-medium">
            {{ tr('pin_locked', { n: summary.pin_locked_minutes }) }}
        </div>
        <div v-if="summary && summary.uses_default_pin" class="mb-4 rounded-lg bg-amber-50 text-amber-800 p-3 text-sm flex flex-wrap items-center justify-between gap-2">
            <span>{{ tr('default_pin_warning') }}</span>
            <button type="button" class="db-btn py-1.5 text-white bg-amber-600" @click="open({ type: 'settings' })">{{ tr('change_pin') }}</button>
        </div>

        <template v-if="summary">
            <!-- Cashier: record only, no figures -->
            <div v-if="!canSeeBalance" class="db-card p-4 mb-6">
                <p class="text-sm text-gray-500 mb-4">{{ tr('cashier_help') }}</p>
                <h4 class="font-semibold mb-2">{{ tr('shop_cash') }}</h4>
                <div class="flex flex-wrap gap-2 mb-5">
                    <button type="button" class="db-btn py-2 text-white bg-primary" @click="open({ type: 'add', account: ACCOUNT.DRAWER })">{{ tr('add_money') }}</button>
                    <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'withdraw', account: ACCOUNT.DRAWER })">🔒 {{ tr('withdraw') }}</button>
                    <button v-if="summary.outlet.mfs_enabled" type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'transfer' })">🔒 {{ tr('transfer') }}</button>
                    <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'count', account: ACCOUNT.DRAWER })">{{ tr('count') }}</button>
                </div>
                <div v-if="summary.outlet.mfs_enabled" class="grid gap-4 lg:grid-cols-3">
                    <div v-for="provider in PROVIDERS" :key="provider">
                        <CashMfsQuickComponent :provider="provider" :outlet-id="summary.outlet.id" @saved="saved" />
                        <div class="flex flex-wrap gap-2 mt-2">
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'count', account: MFS[provider].sim })">{{ provider === 'recharge' ? tr('count_balance') : tr('count_sim') }}</button>
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'count', account: MFS[provider].cash })">{{ tr('count_cash') }}</button>
                        </div>
                    </div>
                </div>
                <CashMfsOffComponent v-else :outlet-id="summary.outlet.id" @saved="saved" />
            </div>

            <!-- Owner / manager: the statements -->
            <template v-else>
                <div class="grid gap-6 mb-6 lg:grid-cols-2 2xl:grid-cols-3">
                    <!-- Cash drawer: notes and coins only -->
                    <div class="db-card p-4 flex flex-col">
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <div>
                                <h4 class="text-base font-semibold">{{ tr('shop_cash') }}</h4>
                                <p class="text-[11px] text-gray-500 mt-0.5">{{ tr('drawer_help') }}</p>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">{{ summary.is_today ? tr('available_cash') : tr('closing_balance') }}</div>
                                <div class="text-2xl font-bold text-primary">{{ money(summary.drawer.closing) }}</div>
                            </div>
                        </div>
                        <table class="w-full text-sm">
                            <tbody>
                                <tr v-for="line in drawerLines" :key="line.key" class="border-b border-gray-100 last:border-0">
                                    <td class="py-1.5" :class="line.strong ? 'font-semibold' : 'text-gray-600'">{{ line.label }}</td>
                                    <td class="py-1.5 text-right" :class="lineClass(line)">{{ money(line.value) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="text-xs text-gray-500 mt-3">{{ tr('last_count') }}: {{ countText(summary.drawer.last_count) }}</p>
                        <!-- Card and MFS have their own card now; "Other" names no
                             place the money went, so it stays information only. -->
                        <div v-if="summary.pos_other && summary.pos_other.other > 0" class="mt-3 rounded-md bg-gray-50 p-2 text-xs text-gray-600">
                            {{ tr('non_cash_info') }}: {{ tr('other_sales') }} {{ money(summary.pos_other.other) }}
                        </div>
                        <div class="flex flex-wrap gap-2 mt-auto pt-4" v-if="summary.is_today">
                            <button type="button" class="db-btn py-2 text-white bg-primary" @click="open({ type: 'add', account: ACCOUNT.DRAWER })">{{ tr('add_money') }}</button>
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'withdraw', account: ACCOUNT.DRAWER })">🔒 {{ tr('withdraw') }}</button>
                            <button v-if="summary.outlet.mfs_enabled" type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'transfer' })">🔒 {{ tr('transfer') }}</button>
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'count', account: ACCOUNT.DRAWER })">{{ tr('count') }}</button>
                        </div>
                    </div>

                    <!-- Till sales paid by card or MFS: e-money, never notes -->
                    <div v-if="summary.emoney" class="db-card p-4 flex flex-col">
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <div>
                                <h4 class="text-base font-semibold text-sky-700">{{ tr('emoney_title') }}</h4>
                                <p class="text-[11px] text-gray-500 mt-0.5">{{ tr('emoney_help') }}</p>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="text-xs text-gray-500">{{ tr('total_card_mfs') }}</div>
                                <div class="text-2xl font-bold">{{ money(summary.emoney.total_closing) }}</div>
                            </div>
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500 border-b border-gray-200">
                                    <th class="py-1.5 text-left font-medium"></th>
                                    <th class="py-1.5 text-right font-medium">{{ tr('card_col') }}</th>
                                    <th class="py-1.5 text-right font-medium">{{ tr('mfs_col') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in emoneyLines" :key="line.key" class="border-b border-gray-100 last:border-0">
                                    <td class="py-1.5" :class="line.strong ? 'font-semibold' : 'text-gray-600'">{{ line.label }}</td>
                                    <td class="py-1.5 text-right" :class="lineClass({ ...line, value: line.card })">{{ money(line.card) }}</td>
                                    <td class="py-1.5 text-right" :class="lineClass({ ...line, value: line.mfs })">{{ money(line.mfs) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="text-xs text-gray-500 mt-3">
                            {{ tr('card_col') }}: {{ countText(summary.emoney.card.last_count) }}<br />
                            {{ tr('mfs_col') }}: {{ countText(summary.emoney.mfs.last_count) }}
                        </p>
                        <div class="flex flex-wrap gap-2 mt-auto pt-4" v-if="summary.is_today">
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'withdraw', account: ACCOUNT.POS_CARD })">🔒 {{ tr('withdraw') }}</button>
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'count', account: ACCOUNT.POS_CARD })">{{ tr('count_card') }}</button>
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'count', account: ACCOUNT.POS_MFS })">{{ tr('count_mfs') }}</button>
                        </div>
                    </div>

                    <!-- bKash agent, Nagad agent and Recharge, each on its own -->
                    <div v-for="(pot, provider) in summary.mfs" :key="provider" class="db-card p-4 flex flex-col">
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <h4 class="text-base font-semibold" :class="theme(provider).title">{{ tr(provider + '_agent') }}</h4>
                            <div class="text-right">
                                <div class="text-xs text-gray-500">{{ simLabel(provider) }} + {{ tr('cash') }}</div>
                                <div class="text-2xl font-bold">{{ money(pot.total_closing) }}</div>
                            </div>
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs text-gray-500 border-b border-gray-200">
                                    <th class="py-1.5 text-left font-medium"></th>
                                    <th class="py-1.5 text-right font-medium">{{ simLabel(provider) }}</th>
                                    <th class="py-1.5 text-right font-medium">{{ tr('cash') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in mfsLines(pot, provider)" :key="line.key" class="border-b border-gray-100 last:border-0">
                                    <td class="py-1.5" :class="line.strong ? 'font-semibold' : 'text-gray-600'">{{ line.label }}</td>
                                    <td class="py-1.5 text-right" :class="lineClass({ ...line, value: line.sim })">{{ money(line.sim) }}</td>
                                    <td class="py-1.5 text-right" :class="lineClass({ ...line, value: line.cash })">{{ money(line.cash) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="text-xs text-gray-500 mt-3">
                            {{ simLabel(provider) }}: {{ countText(pot.sim.last_count) }}<br />
                            {{ tr('cash') }}: {{ countText(pot.cash.last_count) }}
                        </p>
                        <div class="mt-auto pt-4" v-if="summary.is_today">
                            <CashMfsQuickComponent :provider="provider" :outlet-id="summary.outlet.id" @saved="saved" />
                        </div>
                        <div class="flex flex-wrap gap-2 pt-3" v-if="summary.is_today">
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'add', account: MFS[provider].sim })">{{ tr('add_money') }}</button>
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'withdraw', account: MFS[provider].cash })">🔒 {{ tr('withdraw') }}</button>
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'count', account: MFS[provider].sim })">{{ provider === 'recharge' ? tr('count_balance') : tr('count_sim') }}</button>
                            <button type="button" class="db-btn py-2 bg-gray-100" @click="open({ type: 'count', account: MFS[provider].cash })">{{ tr('count_cash') }}</button>
                        </div>
                    </div>

                    <div v-if="!summary.outlet.mfs_enabled" class="db-card p-4">
                        <CashMfsOffComponent :outlet-id="summary.outlet.id" @saved="saved" />
                    </div>
                </div>

                <!-- Grand total: the only place they are all added up -->
                <div class="db-card p-4 mb-6">
                    <h4 class="text-base font-semibold mb-3">{{ tr('grand_total') }}</h4>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="rounded-lg bg-gray-50 p-3">
                            <div class="text-xs text-gray-500">{{ tr('notes_total') }}</div>
                            <div class="text-lg font-semibold">{{ money(summary.grand_total.notes) }}</div>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3">
                            <div class="text-xs text-gray-500">{{ tr('emoney_total') }}</div>
                            <div class="text-lg font-semibold">{{ money(summary.grand_total.emoney) }}</div>
                        </div>
                        <div class="rounded-lg bg-primary/10 p-3">
                            <div class="text-xs text-gray-600">{{ tr('total_branch_money') }}</div>
                            <div class="text-2xl font-bold text-primary">{{ money(summary.grand_total.total) }}</div>
                        </div>
                    </div>
                </div>

                <!-- Every count of the day: the notes counted, what was expected
                     and the difference. -->
                <div class="db-card p-4 mb-6">
                    <h4 class="text-base font-semibold mb-3">{{ tr('counts_title') }}</h4>
                    <p v-if="!summary.counts || summary.counts.length === 0" class="text-sm text-gray-500">{{ tr('no_counts') }}</p>
                    <div v-else class="db-table-responsive">
                        <table class="db-table">
                            <thead class="db-table-head">
                                <tr class="db-table-head-tr">
                                    <th class="db-table-head-th">{{ tr('date') }}</th>
                                    <th class="db-table-head-th">{{ tr('account') }}</th>
                                    <th class="db-table-head-th">{{ tr('notes_counted') }}</th>
                                    <th class="db-table-head-th text-right">{{ tr('counted_word') }}</th>
                                    <th class="db-table-head-th text-right">{{ tr('expected') }}</th>
                                    <th class="db-table-head-th text-right">{{ tr('difference') }}</th>
                                    <th class="db-table-head-th">{{ tr('by') }}</th>
                                </tr>
                            </thead>
                            <tbody class="db-table-body">
                                <tr v-for="count in summary.counts" :key="count.id" class="db-table-body-tr">
                                    <td class="db-table-body-td whitespace-nowrap">{{ count.at }}</td>
                                    <td class="db-table-body-td whitespace-nowrap">{{ tr('acc_' + count.account) }}</td>
                                    <td class="db-table-body-td text-xs">
                                        <span v-if="count.denominations.length">
                                            {{ count.denominations.map((line) => line.note + '×' + line.pieces).join(' · ') }}
                                        </span>
                                        <span v-else class="text-gray-400">{{ tr('typed_balance') }}</span>
                                    </td>
                                    <td class="db-table-body-td text-right whitespace-nowrap">{{ money(count.counted) }}</td>
                                    <td class="db-table-body-td text-right whitespace-nowrap">{{ money(count.expected) }}</td>
                                    <td class="db-table-body-td text-right whitespace-nowrap font-semibold"
                                        :class="count.variance < 0 ? 'text-red-600' : count.variance > 0 ? 'text-amber-700' : 'text-green-700'">
                                        {{ money(count.variance, true) }}
                                        <span class="text-[10px] font-normal">{{ count.variance < 0 ? tr('short') : count.variance > 0 ? tr('over') : tr('exact') }}</span>
                                    </td>
                                    <td class="db-table-body-td whitespace-nowrap">{{ count.by }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Watch list -->
                <div class="db-card p-4 mb-6" v-if="alerts">
                    <h4 class="text-base font-semibold mb-3">{{ tr('watch_list') }}</h4>
                    <p v-if="quietWeek" class="text-sm text-gray-500">{{ tr('nothing_to_watch') }}</p>
                    <div class="grid gap-4 lg:grid-cols-3" v-else>
                        <div v-if="alerts.pos_reversals.length">
                            <div class="text-sm font-medium text-red-700 mb-2">{{ tr('pos_reversals_alert') }}</div>
                            <ul class="text-xs space-y-1.5">
                                <li v-for="row in alerts.pos_reversals" :key="row.id" class="rounded bg-red-50 p-2">
                                    <b>{{ money(row.amount) }}</b>
                                    <span v-if="row.reference"> · {{ tr('order') }} #{{ row.reference }}</span>
                                    · {{ row.by }} · {{ row.at }}
                                    <span v-if="row.minutes_after_sale !== null"> · {{ tr('minutes_after_sale', { n: row.minutes_after_sale }) }}</span>
                                </li>
                            </ul>
                        </div>
                        <div v-if="alerts.pin_failures.length">
                            <div class="text-sm font-medium text-red-700 mb-2">{{ tr('wrong_pin_alert') }}</div>
                            <ul class="text-xs space-y-1.5">
                                <li v-for="(row, index) in alerts.pin_failures" :key="index" class="rounded bg-red-50 p-2">
                                    <b>{{ row.by }}</b> · {{ tr('tries', { n: row.count }) }} · {{ row.last_at }}
                                </li>
                            </ul>
                        </div>
                        <div v-if="alerts.shortages.length">
                            <div class="text-sm font-medium text-red-700 mb-2">{{ tr('short_counts_alert') }}</div>
                            <ul class="text-xs space-y-1.5">
                                <li v-for="(row, index) in alerts.shortages" :key="index" class="rounded bg-red-50 p-2">
                                    <b>{{ money(row.variance) }}</b> · {{ tr('acc_' + row.account) }} · {{ row.by }} · {{ row.at }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </template>

            <!-- History (owner) / my entries today (cashier) -->
            <div class="db-card">
                <div class="db-card-header border-none flex-wrap gap-3">
                    <h3 class="db-card-title">{{ canSeeBalance ? tr('history') : tr('my_entries_today') }}</h3>
                    <div class="db-card-filter flex-wrap gap-2" v-if="canSeeBalance">
                        <div class="w-56">
                            <Datepicker v-model="historyRange" hideInputIcon autoApply :range="true" :enableTimePicker="false"
                                :maxDate="maxDate" format="dd-MM-yyyy" utc="false" :placeholder="tr('from') + ' - ' + tr('to')" />
                        </div>
                        <select v-model="filters.account" class="db-field-control h-10 w-auto" @change="listEntries(1)">
                            <option value="">{{ tr('all_accounts') }}</option>
                            <option v-for="account in ALL_ACCOUNTS" :key="account" :value="account">{{ tr('acc_' + account) }}</option>
                        </select>
                        <select v-model="filters.type" class="db-field-control h-10 w-auto" @change="listEntries(1)">
                            <option value="">{{ tr('all_types') }}</option>
                            <option v-for="type in 11" :key="type" :value="type">{{ tr('type_' + type) }}</option>
                        </select>
                    </div>
                </div>
                <div class="db-table-responsive">
                    <table class="db-table stripe">
                        <thead class="db-table-head">
                            <tr class="db-table-head-tr">
                                <th class="db-table-head-th">{{ tr('date') }}</th>
                                <th class="db-table-head-th">{{ tr('account') }}</th>
                                <th class="db-table-head-th">{{ tr('type') }}</th>
                                <th class="db-table-head-th text-right">{{ tr('amount') }}</th>
                                <th class="db-table-head-th text-right" v-if="canSeeBalance">{{ tr('balance_after') }}</th>
                                <th class="db-table-head-th">{{ tr('by') }}</th>
                                <th class="db-table-head-th">{{ tr('details') }}</th>
                                <th class="db-table-head-th" v-if="canSeeBalance"></th>
                            </tr>
                        </thead>
                        <tbody class="db-table-body">
                            <tr v-for="entry in entries" :key="entry.id" class="db-table-body-tr" :class="{ 'opacity-50': entry.reversed }">
                                <td class="db-table-body-td whitespace-nowrap">{{ entry.converted_date }}</td>
                                <td class="db-table-body-td whitespace-nowrap">{{ tr('acc_' + entry.account) }}</td>
                                <td class="db-table-body-td whitespace-nowrap">
                                    <span :class="{ 'line-through': entry.reversed }">{{ tr('type_' + entry.type) }}</span>
                                    <span v-if="entry.reversed" class="ml-1 text-[10px] text-red-600">({{ tr('reversed') }})</span>
                                </td>
                                <td class="db-table-body-td text-right font-medium whitespace-nowrap" :class="entry.amount < 0 ? 'text-red-600' : 'text-green-700'">
                                    {{ money(entry.amount, true) }}
                                </td>
                                <td class="db-table-body-td text-right whitespace-nowrap" v-if="canSeeBalance">{{ money(entry.balance_after) }}</td>
                                <td class="db-table-body-td whitespace-nowrap">{{ entry.creator }}</td>
                                <td class="db-table-body-td text-xs">{{ details(entry) }}</td>
                                <td class="db-table-body-td" v-if="canSeeBalance">
                                    <button v-if="entry.reversible && !entry.reversed" type="button"
                                        class="text-xs px-2 py-1 rounded border border-red-200 text-red-600 whitespace-nowrap"
                                        @click="open({ type: 'reverse', entry })">🔒 {{ tr('reverse') }}</button>
                                </td>
                            </tr>
                            <tr v-if="entries.length === 0" class="db-table-body-tr">
                                <td class="db-table-body-td text-center text-gray-500" :colspan="canSeeBalance ? 8 : 6">{{ tr('no_entries') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6" v-if="entries.length > 0 && pagination.meta">
                    <PaginationSMBox :pagination="pagination" :method="listEntries" />
                    <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                        <PaginationTextComponent :props="{ page: pagination.meta }" />
                        <PaginationBox :pagination="pagination" :method="listEntries" />
                    </div>
                </div>
            </div>
        </template>

        <CashActionModalComponent v-if="action && summary" :action="action" :outlet="summary.outlet"
            :denominations="summary.denominations" :money="money" @close="action = null" @saved="saved" />
    </div>
</template>

<script>
import axios from "axios";
import LoadingComponent from "../components/LoadingComponent";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import CashActionModalComponent from "./CashActionModalComponent";
import CashMfsQuickComponent from "./CashMfsQuickComponent";
import CashMfsOffComponent from "./CashMfsOffComponent";
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import appService from "../../../services/appService";
import currencyPositionEnum from "../../../enums/modules/currencyPositionEnum";
import { tr, ACCOUNT, ALL_ACCOUNTS, MFS, PROVIDERS, KINDS, THEME } from "./cashCalculationText";

const STORAGE_KEY = "cash_calculation_outlet";

// YYYY-MM-DD in the browser's own time zone - the shop's day, not UTC's.
function localDate(date = new Date()) {
    const pad = (n) => String(n).padStart(2, "0");
    return date.getFullYear() + "-" + pad(date.getMonth() + 1) + "-" + pad(date.getDate());
}

function parseDate(value) {
    const [y, m, d] = value.split("-").map(Number);
    return new Date(y, m - 1, d);
}

export default {
    name: "CashCalculationComponent",
    components: {
        LoadingComponent, PaginationTextComponent, PaginationBox, PaginationSMBox,
        CashActionModalComponent, CashMfsQuickComponent, CashMfsOffComponent, Datepicker,
    },
    data() {
        return {
            ACCOUNT: ACCOUNT,
            ALL_ACCOUNTS: ALL_ACCOUNTS,
            MFS: MFS,
            PROVIDERS: PROVIDERS,
            historyRange: null,
            loading: { isActive: false },
            loaded: false,
            outlets: [],
            outletId: null,
            // Both come from the server (summary.today / summary.date): the
            // device clock may be on the wrong day. Empty until the first
            // answer, which is then the shop's today.
            today: "",
            date: "",
            summary: null,
            alerts: null,
            entries: [],
            pagination: {},
            filters: { from: "", to: "", account: "", type: "" },
            action: null,
        };
    },
    computed: {
        setting: function () {
            return this.$store.getters["frontendSetting/lists"] || {};
        },
        canSeeBalance: function () {
            return !!(this.summary && this.summary.can_see_balance);
        },
        // The statement date as the picker wants it (a Date), kept in `date`
        // as YYYY-MM-DD for the API.
        pickerDate: {
            get: function () {
                return this.date ? parseDate(this.date) : null;
            },
            set: function (value) {
                if (!value) {
                    return;
                }
                this.date = localDate(value);
                this.load();
            },
        },
        drawerLines: function () {
            const d = this.summary.drawer;
            return [
                { key: "opening", label: tr("opening_balance"), value: d.opening, strong: true, always: true },
                { key: "pos", label: tr("pos_cash_sales"), value: d.pos_sales, always: true },
                { key: "pos_rev", label: tr("pos_reversed"), value: d.pos_reversals },
                { key: "added", label: tr("money_added"), value: d.added, always: true },
                { key: "withdrawn", label: tr("withdrawn"), value: d.withdrawn, always: true },
                { key: "t_in", label: tr("transfer_in"), value: d.transfer_in },
                { key: "t_out", label: tr("transfer_out"), value: d.transfer_out },
                { key: "variance", label: tr("count_difference"), value: d.variance },
                { key: "reversals", label: tr("reversals"), value: d.reversals },
                {
                    key: "closing", label: this.summary.is_today ? tr("available_cash") : tr("closing_balance"),
                    value: d.closing, strong: true, always: true,
                },
            ].filter((line) => line.always || Number(line.value) !== 0);
        },
        maxDate: function () {
            return this.today ? parseDate(this.today) : new Date();
        },
        emoneyLines: function () {
            const card = this.summary.emoney.card;
            const mfs = this.summary.emoney.mfs;
            const row = (key, label, field, strong = false, always = false) => ({
                key, label, card: card[field], mfs: mfs[field], strong, always,
            });
            return [
                row("opening", tr("opening_balance"), "opening", true, true),
                row("pos", tr("pos_sales"), "pos_sales", false, true),
                row("pos_rev", tr("pos_reversed"), "pos_reversals"),
                row("added", tr("money_added"), "added"),
                row("withdrawn", tr("withdrawn"), "withdrawn"),
                row("t_in", tr("transfer_in"), "transfer_in"),
                row("t_out", tr("transfer_out"), "transfer_out"),
                row("variance", tr("count_difference"), "variance"),
                row("reversals", tr("reversals"), "reversals"),
                row("closing", this.summary.is_today ? tr("closing") : tr("closing_balance"), "closing", true, true),
            ].filter((line) => line.always || Number(line.card) !== 0 || Number(line.mfs) !== 0);
        },
        quietWeek: function () {
            return this.alerts
                && this.alerts.pos_reversals.length === 0
                && this.alerts.pin_failures.length === 0
                && this.alerts.shortages.length === 0;
        },
    },
    watch: {
        historyRange: function (range) {
            this.filters.from = range && range[0] ? localDate(range[0]) : "";
            this.filters.to = range && range[1] ? localDate(range[1]) : "";
            this.listEntries(1);
        },
    },
    mounted() {
        this.loadOutlets();
    },
    methods: {
        tr: tr,
        theme: function (provider) {
            return THEME[provider] || THEME.bkash;
        },
        // A Recharge "SIM" is the recharge balance.
        simLabel: function (provider) {
            return provider === "recharge" ? tr("balance") : tr("sim");
        },
        shiftDay: function (days) {
            const next = parseDate(this.date);
            next.setDate(next.getDate() + days);
            const value = localDate(next);
            if (value > this.today) {
                return;
            }
            this.date = value;
            this.load();
        },
        money: function (value, signed = false) {
            const amount = Number(value) || 0;
            const text = appService.currencyFormat(
                Math.abs(amount),
                this.setting.site_digit_after_decimal_point ?? 2,
                this.setting.site_default_currency_symbol ?? "৳",
                this.setting.site_currency_position ?? currencyPositionEnum.LEFT
            );
            if (amount < 0) {
                return "-" + text;
            }
            return signed && amount > 0 ? "+" + text : text;
        },
        lineClass: function (line) {
            if (line.strong) {
                return "font-semibold";
            }
            const value = Number(line.value) || 0;
            return value < 0 ? "text-red-600" : value > 0 ? "text-green-700" : "text-gray-400";
        },
        // A service's own transactions always show; anything else only when it
        // happened (e.g. an old bKash recharge from before Recharge had its own
        // card).
        mfsLines: function (pot, provider) {
            const s = pot.sim;
            const c = pot.cash;
            const own = KINDS[provider] || [];
            const row = (key, label, field, strong = false, always = false) => ({
                key, label, sim: s[field], cash: c[field], strong, always,
            });
            return [
                row("opening", tr("opening_balance"), "opening", true, true),
                row("cash_in", tr("cash_in"), "cash_in", false, own.includes("cash_in")),
                row("cash_out", tr("cash_out"), "cash_out", false, own.includes("cash_out")),
                row("recharge", tr("recharge"), "recharge", false, own.includes("recharge")),
                row("added", tr("money_added"), "added"),
                row("withdrawn", tr("withdrawn"), "withdrawn"),
                row("t_in", tr("transfer_in"), "transfer_in"),
                row("t_out", tr("transfer_out"), "transfer_out"),
                row("variance", tr("count_difference"), "variance"),
                row("reversals", tr("reversals"), "reversals"),
                row("closing", this.summary.is_today ? tr("closing") : tr("closing_balance"), "closing", true, true),
            ].filter((line) => line.always || Number(line.sim) !== 0 || Number(line.cash) !== 0);
        },
        countText: function (count) {
            if (!count) {
                return tr("never_counted");
            }
            const variance = Number(count.variance) || 0;
            const result = variance === 0 ? tr("exact") : this.money(Math.abs(variance)) + " " + (variance < 0 ? tr("short") : tr("over"));
            return count.at + " · " + count.by + " · " + result;
        },
        details: function (entry) {
            const parts = [];
            if (entry.order_id) {
                parts.push(tr("order") + " #" + (entry.reference || entry.order_id));
            } else if (entry.reference) {
                parts.push("TrxID " + entry.reference);
            }
            if (entry.party) {
                parts.push(entry.party);
            }
            if (entry.note) {
                parts.push(entry.note);
            }
            return parts.join(" · ");
        },
        loadOutlets: function () {
            this.loading.isActive = true;
            axios.get("admin/cash-calculation/outlets").then((res) => {
                this.outlets = res.data.data;
                this.loaded = true;
                let stored = null;
                try {
                    stored = parseInt(window.localStorage.getItem(STORAGE_KEY), 10);
                } catch (e) {
                    stored = null;
                }
                const pick = this.outlets.find((outlet) => outlet.id === stored) || this.outlets[0];
                if (pick) {
                    this.selectOutlet(pick.id);
                } else {
                    this.loading.isActive = false;
                }
            }).catch(() => {
                this.loading.isActive = false;
                this.loaded = true;
            });
        },
        selectOutlet: function (id) {
            this.outletId = id;
            try {
                window.localStorage.setItem(STORAGE_KEY, String(id));
            } catch (e) {
                // A private window may refuse storage; the page works without it.
            }
            this.load();
        },
        // quiet: refresh behind an open modal without the full-screen loader
        // covering it.
        load: function (quiet = false) {
            if (!this.outletId) {
                return;
            }
            this.loading.isActive = quiet !== true;
            axios.get("admin/cash-calculation/summary", {
                params: { outlet_id: this.outletId, date: this.date || undefined },
            }).then((res) => {
                this.summary = res.data.data;
                this.date = this.summary.date;
                if (this.summary.today) {
                    this.today = this.summary.today;
                }
                this.loading.isActive = false;
                this.listEntries(1);
                if (this.summary.can_see_balance) {
                    axios.get("admin/cash-calculation/alerts", { params: { outlet_id: this.outletId } })
                        .then((alerts) => { this.alerts = alerts.data.data; })
                        .catch(() => { this.alerts = null; });
                } else {
                    this.alerts = null;
                }
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        listEntries: function (page = 1) {
            const params = { outlet_id: this.outletId, page: page, per_page: 25 };
            if (this.canSeeBalance) {
                Object.keys(this.filters).forEach((key) => {
                    if (this.filters[key] !== "") {
                        params[key] = this.filters[key];
                    }
                });
            }
            axios.get("admin/cash-calculation/entries", { params }).then((res) => {
                this.entries = res.data.data;
                this.pagination = res.data;
            }).catch(() => {
                this.entries = [];
            });
        },
        open: function (action) {
            this.action = action;
        },
        // close = false keeps the modal up (an owner reading a count result)
        // while the figures behind it refresh.
        saved: function (close = true) {
            if (close) {
                this.action = null;
            }
            this.loadOutletsQuietly();
            this.load(true);
        },
        // The MFS switch changes the branch tab's badge.
        loadOutletsQuietly: function () {
            axios.get("admin/cash-calculation/outlets").then((res) => {
                this.outlets = res.data.data;
            }).catch(() => {});
        },
    },
};
</script>
