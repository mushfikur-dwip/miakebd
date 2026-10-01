<template>
    <div id="posReceiptModal" class="modal">
        <div class="modal-dialog max-w-[340px] rounded-none receipt" id="print">
            <div class="modal-header hidden-print">
                <button type="button" @click="reset"
                    class="modal-close flex items-center justify-center gap-1.5 py-2 px-4 rounded bg-[#FB4E4E]">
                    <i class="lab lab-fill-close-circle lab-font-size-16 text-white"></i>
                    <span class="text-xs leading-5 capitalize text-white">{{ $t('button.close') }}</span>
                </button>
                <button type="button" v-print="printObj"
                    class="flex items-center justify-center gap-1.5 py-2 px-4 rounded bg-[#1AB759]">
                    <i class="lab lab-fill-printer lab-font-size-16 text-white"></i>
                    <span class="text-xs leading-5 capitalize text-white">{{ $t('button.print_invoice') }}</span>
                </button>
            </div>
            <div class="modal-body text-heading">
                <div class="text-center">
                    <h3 class="text-[26px] leading-none font-extrabold tracking-[0.3em] ps-[0.3em] text-heading">
                        {{ company.company_name }}</h3>
                    <div class="flex items-center justify-center gap-2 my-3" aria-hidden="true">
                        <span class="w-10 border-t border-heading"></span>
                        <span class="w-[5px] h-[5px] rotate-45 border border-heading"></span>
                        <span class="w-10 border-t border-heading"></span>
                    </div>
                    <h4 class="mx-auto max-w-[270px] text-[10px] leading-[15px] font-semibold uppercase tracking-[0.14em] text-balance text-heading">
                        {{ companyAddress }}</h4>
                    <h5 class="mt-2.5 inline-flex max-w-full items-center whitespace-nowrap rounded-full border border-heading text-heading">
                        <span class="ps-2.5 pe-2 py-[3px] text-[9px] font-bold uppercase tracking-[0.16em] border-e border-heading">{{ $t('label.support') }}</span>
                        <span class="ps-2 pe-2.5 py-[3px] text-xs font-bold tracking-[0.03em]" dir="ltr">{{ company.company_calling_code }} {{ company.company_phone }}</span>
                    </h5>
                </div>

                <div class="flex items-center gap-2.5 mt-4 mb-2">
                    <span class="flex-1 border-t border-heading"></span>
                    <span class="text-[10px] font-extrabold uppercase tracking-[0.28em] ps-[0.28em] text-heading">{{ $t('menu.order_receipt') }}</span>
                    <span class="flex-1 border-t border-heading"></span>
                </div>

                <table class="w-full leading-4">
                    <tbody>
                        <tr>
                            <td class="py-[3px] text-start text-[10px] font-semibold uppercase tracking-[0.12em] text-paragraph">
                                {{ $t('label.order_id') }}
                            </td>
                            <td class="py-[3px] text-end text-[11px] font-bold text-heading">#{{ order.order_serial_no }}</td>
                        </tr>
                        <tr>
                            <td class="py-[3px] text-start text-[10px] font-semibold uppercase tracking-[0.12em] text-paragraph">
                                {{ $t('label.date') }}
                            </td>
                            <td class="py-[3px] text-end text-[11px] font-bold text-heading">
                                {{ order.order_date }}<span class="mx-1.5 text-paragraph">&middot;</span>{{ order.order_time }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table class="w-full leading-4 mt-3">
                    <thead>
                        <tr class="border-t border-b border-heading">
                            <th scope="col" class="py-1.5 w-7 text-start text-[9px] font-bold uppercase tracking-[0.14em] text-heading">
                                {{ $t('label.qty') }}
                            </th>
                            <th scope="col" class="py-1.5 text-start text-[9px] font-bold uppercase tracking-[0.14em] text-heading">
                                {{ $t('label.product_description') }}
                            </th>
                            <th scope="col" class="py-1.5 text-end text-[9px] font-bold uppercase tracking-[0.14em] text-heading">
                                {{ $t('label.price') }}
                            </th>
                        </tr>
                    </thead>

                    <tbody>
                        <tr v-if="orderProducts.length > 0" v-for="product in orderProducts" :key="product"
                            class="border-b border-dashed border-gray-300 last:border-b-0">
                            <td class="py-2 align-top text-[11px] leading-[15px] font-bold text-heading">
                                {{ product.quantity }}
                            </td>
                            <td class="py-2 pe-3 align-top">
                                <p class="text-[11px] leading-[15px] font-semibold text-heading">{{ product.product_name }}</p>
                                <p v-if="product.variation_names" class="mt-0.5 text-[10px] leading-[14px] text-paragraph">
                                    {{ product.variation_names }}
                                </p>
                                <p class="text-[10px] leading-[14px] text-paragraph" v-if="product.product_tax.length > 0"
                                    v-for="tax in product.product_tax" :key="tax">
                                    {{ tax.tax_name }} ({{ tax.tax_rate }}%)
                                </p>
                            </td>
                            <td class="py-2 align-top text-end text-[11px] leading-[15px] font-semibold whitespace-nowrap tabular-nums text-heading">
                                {{ product.subtotal_currency_price }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="pt-2 border-t border-heading">
                    <table class="w-full leading-4">
                        <tbody>
                            <tr>
                                <td class="py-[3px] text-start text-[10px] font-semibold uppercase tracking-[0.12em] text-paragraph">
                                    {{ $t('label.subtotal') }}
                                </td>
                                <td class="py-[3px] text-end text-[11px] font-semibold tabular-nums text-heading">
                                    {{ order.subtotal_currency_price }}
                                </td>
                            </tr>
                            <tr>
                                <td class="py-[3px] text-start text-[10px] font-semibold uppercase tracking-[0.12em] text-paragraph">
                                    {{ $t('label.tax_fee') }}
                                </td>
                                <td class="py-[3px] text-end text-[11px] font-semibold tabular-nums text-heading">
                                    {{ order.tax_currency_price }}
                                </td>
                            </tr>
                            <tr>
                                <td class="py-[3px] text-start text-[10px] font-semibold uppercase tracking-[0.12em] text-paragraph">
                                    {{ $t('label.discount') }}
                                </td>
                                <td class="py-[3px] text-end text-[11px] font-semibold tabular-nums text-heading">
                                    {{ order.discount_currency_price }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="flex items-center justify-between mt-2 py-2 border-t border-b-[3px] border-double border-heading">
                        <span class="text-xs font-extrabold uppercase tracking-[0.2em] text-heading">{{ $t('label.total') }}</span>
                        <span class="text-[17px] leading-6 font-extrabold tabular-nums text-heading">{{ order.total_currency_price }}</span>
                    </div>
                </div>

                <table class="w-full leading-4 mt-2">
                    <tbody>
                        <tr>
                            <td class="py-[3px] text-start text-[10px] font-semibold uppercase tracking-[0.12em] text-paragraph">
                                {{ $t('label.payment_type') }}
                            </td>
                            <td class="py-[3px] text-end text-[11px] font-bold text-heading">{{ order.pos_payment_method_name }}</td>
                        </tr>
                        <template v-if="order.pos_received_amount > 0">
                            <tr>
                                <td class="py-[3px] text-start text-[10px] font-semibold uppercase tracking-[0.12em] text-paragraph">
                                    {{ $t('label.cash') }}
                                </td>
                                <td class="py-[3px] text-end text-[11px] font-semibold tabular-nums text-heading">
                                    {{ order.pos_currency_received_amount }}
                                </td>
                            </tr>
                            <tr>
                                <td class="py-[3px] text-start text-[10px] font-semibold uppercase tracking-[0.12em] text-paragraph">
                                    {{ $t('label.change') }}
                                </td>
                                <td class="py-[3px] text-end text-[11px] font-bold tabular-nums text-heading">
                                    {{ order.change_currency_amount }}
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>

                <div class="mt-3 pt-4 border-t border-dashed border-gray-400 text-center">
                    <p class="text-[13px] leading-4 font-extrabold uppercase tracking-[0.35em] ps-[0.35em] text-heading">
                        {{ $t('message.thank_you') }}
                    </p>
                    <p class="mt-1 text-[10px] leading-[14px] font-semibold uppercase tracking-[0.2em] ps-[0.2em] text-paragraph">
                        {{ $t('message.please_come_again') }}
                    </p>
                    <div class="mt-3.5 mx-auto max-w-[230px] rounded-md border border-heading px-3 py-2">
                        <p class="text-[8.5px] leading-3 font-bold uppercase tracking-[0.2em] ps-[0.2em] text-heading">
                            {{ $t('message.order_online_anytime') }}
                        </p>
                        <p class="mt-1 text-[15px] leading-5 font-extrabold tracking-[0.08em] text-heading" dir="ltr">{{ storeDomain }}</p>
                    </div>
                    <!-- Settings > Site > Receipt footer text. Not capitalized:
                         it prints as typed, and pre-line keeps its line breaks. -->
                    <p v-if="setting.site_receipt_footer"
                        class="mt-3 text-[11px] leading-[15px] font-semibold tracking-[0.04em] text-heading whitespace-pre-line">{{ setting.site_receipt_footer }}</p>
                    <div class="flex items-center justify-center gap-2 mt-3.5" aria-hidden="true">
                        <span class="w-10 border-t border-heading"></span>
                        <span class="w-[5px] h-[5px] rotate-45 border border-heading"></span>
                        <span class="w-10 border-t border-heading"></span>
                    </div>
                </div>

            </div>
        </div>
    </div>
</template>

<script>
import print from "vue3-print-nb";
import appService from "../../../services/appService";

export default {
    name: "ReceiptComponent",
    props: {
        order: Object
    },
    data() {
        return {
            printObj: {
                id: "print",
                popTitle: this.$t("menu.order_receipt"),
                // Chrome's default ~1cm page margins squeeze an 80mm roll to ~170px
                // and wrap the phone number; the receipt's own padding is enough.
                // (vue3-print-nb splits extraHead on commas, so keep it comma-free.)
                extraHead: "<style>@page{margin:0}</style>",
            },
        }
    },
    computed: {
        company: function () {
            return this.$store.getters['company/lists'];
        },
        // "Level 1,Ramc" -> "Level 1, Ramc": one space after every comma.
        companyAddress: function () {
            return (this.company.company_address || '').replace(/\s*,\s*/g, ', ');
        },
        // The shop and this admin share a domain, so the receipt always names the live one.
        storeDomain: function () {
            return window.location.hostname.replace(/^www[.]/, '');
        },
        setting: function () {
            return this.$store.getters['frontendSetting/lists'];
        },
        orderProducts: function () {
            return this.$store.getters['posOrder/orderProducts'];
        },
    },
    mounted() {
        this.$store.dispatch("company/lists").then().catch();
    },
    methods: {
        reset: function () {
            appService.modalHide();
        },
    },
    directives: {
        print
    },
}
</script>
<style scoped>
@media print {
    .hidden-print {
        display: none !important;
    }

    /* Thermal printers turn grey into faint dots: print everything solid black. */
    .receipt,
    .receipt * {
        color: #000 !important;
        border-color: #000 !important;
    }
}
</style>
