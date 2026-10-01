<template>
    <div class="db-month-filter flex items-stretch h-9 rounded-md border border-primary bg-white">
        <button type="button" :aria-label="$t('label.previous')" :disabled="allMonths" @click="step(-1)"
            class="px-2 rounded-s-md text-primary transition hover:bg-primary/5 disabled:opacity-40 disabled:cursor-not-allowed">
            <i class="lab lab-line-chevron-left lab-font-size-14 inline-block rtl:rotate-180"></i>
        </button>
        <Datepicker :modelValue="pickerMonth" @update:modelValue="pick" monthPicker autoApply
            :maxDate="today" :disabled="allMonths">
            <template #trigger>
                <button type="button" :disabled="allMonths"
                    class="db-month-filter-label h-full min-w-[124px] px-2.5 text-sm font-medium text-primary border-x border-primary/20 transition hover:bg-primary/5 disabled:cursor-default disabled:hover:bg-transparent">
                    {{ label }}
                </button>
            </template>
        </Datepicker>
        <button type="button" :aria-label="$t('label.next')" :disabled="allMonths || atCurrentMonth" @click="step(1)"
            class="px-2 rounded-e-md text-primary transition hover:bg-primary/5 disabled:opacity-40 disabled:cursor-not-allowed">
            <i class="lab lab-line-chevron-right lab-font-size-14 inline-block rtl:rotate-180"></i>
        </button>
    </div>
</template>

<script>
import Datepicker from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import i18n from "../../../i18n";
import monthService from "../../../services/monthService";

/**
 * "‹ October 2026 ›" in a page header. Reads and writes the page's own
 * from_date/to_date (the same pair its Filter panel sets), then calls `method`
 * to reload - or, without one, leaves components watching `search` to react.
 * Emits `change` with the [from, to] Dates for the Filter panel's date picker.
 * A range that is not a whole month (picked in the Filter panel) shows as
 * dates; no range at all shows as "All Time".
 */
export default {
    name: "MonthFilterComponent",
    components: { Datepicker },
    props: {
        search: { type: Object },
        method: { type: Function },
        // Set while the page ignores the month, e.g. an order ID lookup.
        allMonths: { type: Boolean, default: false },
    },
    emits: ["change"],
    data() {
        return {
            today: new Date(),
        };
    },
    computed: {
        label: function () {
            const dates = monthService.dates(this.search);
            if (this.allMonths || !dates) {
                return this.$t("label.all_time");
            }
            const locale = i18n.global.locale.value;
            if (monthService.isWholeMonth(this.search)) {
                return new Intl.DateTimeFormat(locale, { month: "long", year: "numeric" }).format(dates[0]);
            }

            return new Intl.DateTimeFormat(locale, { day: "numeric", month: "short", year: "numeric" }).formatRange(dates[0], dates[1]);
        },
        atCurrentMonth: function () {
            return monthService.shift(this.search, 1).from_date > monthService.monthOf().from_date;
        },
        pickerMonth: function () {
            const from = monthService.toDate(this.search.from_date) ?? new Date();

            return { month: from.getMonth(), year: from.getFullYear() };
        },
    },
    methods: {
        step: function (by) {
            this.apply(monthService.shift(this.search, by));
        },
        pick: function (value) {
            if (value) {
                this.apply(monthService.monthOf(new Date(value.year, value.month, 1)));
            }
        },
        apply: function (month) {
            this.search.from_date = month.from_date;
            this.search.to_date = month.to_date;
            this.$emit("change", monthService.dates(month));
            if (this.method) {
                this.method();
            }
        },
    },
};
</script>
