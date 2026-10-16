<template>
    <!-- An online order's invoice: an A4 sheet like the ones other shops
         send, never the till's 80 mm receipt. -->
    <div class="order-invoice bg-white text-heading p-6 sm:p-8" :id="printId">
        <header class="flex flex-wrap items-start justify-between gap-6 pb-5 border-b-2 border-heading">
            <div class="min-w-0 max-w-[60%]">
                <img v-if="logo" :src="logo" alt="" class="invoice-logo h-12 w-auto max-w-[180px] object-contain mb-3" />
                <h2 class="text-xl font-extrabold leading-tight">{{ company.company_name }}</h2>
                <p v-if="companyAddress" class="text-xs leading-5 text-paragraph mt-1">{{ companyAddress }}</p>
                <p v-if="companyContact" class="text-xs leading-5 text-paragraph" dir="ltr">{{ companyContact }}</p>
            </div>
            <div class="text-end">
                <h1 class="text-3xl font-extrabold uppercase tracking-[0.2em] leading-none">{{ tr('invoice') }}</h1>
                <table class="invoice-meta text-xs mt-3 ms-auto">
                    <tbody>
                        <tr>
                            <td class="pe-3 py-0.5 text-paragraph text-start">{{ tr('invoice_no') }}</td>
                            <td class="py-0.5 font-bold text-end">#{{ order.order_serial_no }}</td>
                        </tr>
                        <tr>
                            <td class="pe-3 py-0.5 text-paragraph text-start">{{ tr('date') }}</td>
                            <td class="py-0.5 font-semibold text-end">{{ order.order_datetime }}</td>
                        </tr>
                        <tr>
                            <td class="pe-3 py-0.5 text-paragraph text-start">{{ tr('order_type') }}</td>
                            <td class="py-0.5 font-semibold text-end">{{ isPickUp ? tr('pick_up') : tr('delivery') }}</td>
                        </tr>
                        <tr>
                            <td class="pe-3 py-0.5 text-paragraph text-start">{{ tr('payment') }}</td>
                            <td class="py-0.5 font-semibold text-end">{{ order.payment_method_name }}</td>
                        </tr>
                    </tbody>
                </table>
                <span class="invoice-stamp inline-block mt-3 px-3 py-1 rounded border-2 text-xs font-extrabold uppercase tracking-[0.25em]"
                    :class="isPaid ? 'border-emerald-600 text-emerald-700' : 'border-red-500 text-red-600'">
                    {{ isPaid ? tr('paid') : tr('unpaid') }}
                </span>
            </div>
        </header>

        <section class="grid grid-cols-2 gap-6 py-5">
            <div class="min-w-0">
                <h3 class="invoice-label text-[10px] font-bold uppercase tracking-[0.2em] text-paragraph mb-1.5">{{ tr('bill_to') }}</h3>
                <p class="text-sm font-bold">{{ billTo.name }}</p>
                <p v-if="billTo.phone" class="text-xs leading-5" dir="ltr">{{ billTo.phone }}</p>
                <p v-if="billTo.email" class="text-xs leading-5 break-all">{{ billTo.email }}</p>
            </div>
            <div class="min-w-0">
                <h3 class="invoice-label text-[10px] font-bold uppercase tracking-[0.2em] text-paragraph mb-1.5">
                    {{ isPickUp ? tr('pick_up_from') : tr('ship_to') }}
                </h3>
                <template v-if="isPickUp">
                    <p class="text-sm font-bold">{{ outletAddress && outletAddress.name }}</p>
                    <p class="text-xs leading-5">{{ joinAddress(outletAddress) }}</p>
                    <p v-if="outletAddress && outletAddress.phone" class="text-xs leading-5" dir="ltr">
                        {{ (outletAddress.country_code || '') + outletAddress.phone }}
                    </p>
                </template>
                <template v-else-if="shipping">
                    <p class="text-sm font-bold">{{ shipping.full_name }}</p>
                    <p v-if="shipping.phone" class="text-xs leading-5" dir="ltr">{{ (shipping.country_code || '') + shipping.phone }}</p>
                    <p class="text-xs leading-5">{{ joinAddress(shipping) }}</p>
                </template>
            </div>
        </section>

        <table class="invoice-items w-full text-sm">
            <thead>
                <tr class="bg-gray-100">
                    <th scope="col" class="py-2 px-2 w-10 text-start text-[10px] font-bold uppercase tracking-[0.12em]">{{ tr('sl') }}</th>
                    <th scope="col" class="py-2 px-2 text-start text-[10px] font-bold uppercase tracking-[0.12em]">{{ tr('product') }}</th>
                    <th scope="col" class="py-2 px-2 text-end text-[10px] font-bold uppercase tracking-[0.12em] whitespace-nowrap">{{ tr('unit_price') }}</th>
                    <th scope="col" class="py-2 px-2 text-center text-[10px] font-bold uppercase tracking-[0.12em]">{{ tr('qty') }}</th>
                    <th scope="col" class="py-2 px-2 text-end text-[10px] font-bold uppercase tracking-[0.12em]">{{ tr('amount') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(product, index) in lines" :key="product.id || index" class="border-b border-gray-200">
                    <td class="py-2 px-2 align-top text-xs">{{ index + 1 }}</td>
                    <td class="py-2 px-2 align-top">
                        <p class="text-sm font-semibold leading-5">{{ product.product_name }}</p>
                        <p v-if="product.variation_names" class="text-xs leading-4 text-paragraph">{{ product.variation_names }}</p>
                        <p v-for="tax in (product.product_tax || [])" :key="tax.tax_name" class="text-[11px] leading-4 text-paragraph">
                            {{ tax.tax_name }} ({{ tax.tax_rate }}%)
                        </p>
                    </td>
                    <td class="py-2 px-2 align-top text-end tabular-nums whitespace-nowrap">{{ product.currency_price }}</td>
                    <td class="py-2 px-2 align-top text-center tabular-nums">{{ product.quantity }}</td>
                    <td class="py-2 px-2 align-top text-end font-semibold tabular-nums whitespace-nowrap">{{ product.subtotal_currency_price }}</td>
                </tr>
            </tbody>
        </table>

        <section class="invoice-totals flex justify-end mt-4">
            <table class="w-full max-w-[300px] text-sm">
                <tbody>
                    <tr>
                        <td class="py-1 text-paragraph">{{ tr('subtotal') }}</td>
                        <td class="py-1 text-end tabular-nums">{{ order.subtotal_currency_price }}</td>
                    </tr>
                    <tr>
                        <td class="py-1 text-paragraph">{{ tr('discount') }}</td>
                        <td class="py-1 text-end tabular-nums">-{{ order.discount_currency_price }}</td>
                    </tr>
                    <tr v-if="Number(order.wallet_discount) > 0">
                        <td class="py-1 text-paragraph">{{ tr('wallet') }}</td>
                        <td class="py-1 text-end tabular-nums">-{{ order.wallet_discount_currency_price }}</td>
                    </tr>
                    <tr>
                        <td class="py-1 text-paragraph">{{ tr('tax') }}</td>
                        <td class="py-1 text-end tabular-nums">{{ order.tax_currency_price }}</td>
                    </tr>
                    <tr v-if="!isPickUp">
                        <td class="py-1 text-paragraph">{{ tr('shipping') }}</td>
                        <td class="py-1 text-end tabular-nums">{{ order.shipping_charge_currency_price }}</td>
                    </tr>
                    <tr class="border-t-2 border-heading">
                        <td class="pt-2 pb-1 font-extrabold uppercase tracking-[0.1em]">{{ tr('grand_total') }}</td>
                        <td class="pt-2 pb-1 text-end text-lg font-extrabold tabular-nums">{{ order.total_currency_price }}</td>
                    </tr>
                    <tr>
                        <td class="py-1 text-paragraph">{{ tr('paid_amount') }}</td>
                        <td class="py-1 text-end tabular-nums">{{ isPaid ? order.total_currency_price : zero }}</td>
                    </tr>
                    <tr>
                        <td class="py-1 font-bold">{{ tr('due_amount') }}</td>
                        <td class="py-1 text-end font-bold tabular-nums">{{ isPaid ? zero : order.total_currency_price }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
        <p v-if="!isPaid" class="text-end text-xs font-semibold mt-1">{{ tr('cod_note', { amount: order.total_currency_price }) }}</p>

        <footer class="mt-10 pt-4 border-t border-gray-300 text-center">
            <p class="text-sm font-bold">{{ tr('thanks') }}</p>
            <p class="text-xs mt-1 text-paragraph" dir="auto">
                {{ storeDomain }}<template v-if="company.company_phone"> · {{ tr('support', { phone: (company.company_calling_code || '') + ' ' + company.company_phone }) }}</template>
            </p>
            <p class="text-[10px] mt-2 text-paragraph">{{ tr('generated') }}</p>
        </footer>
    </div>
</template>

<script>
import appService from "../../services/appService";
import addressTypeEnum from "../../enums/modules/addressTypeEnum";
import orderTypeEnum from "../../enums/modules/orderTypeEnum";
import paymentStatusEnum from "../../enums/modules/paymentStatusEnum";
import currencyPositionEnum from "../../enums/modules/currencyPositionEnum";
import { tr } from "./invoiceText";

export default {
    name: "OrderInvoiceComponent",
    props: {
        // The element the print button prints.
        printId: { type: String, default: "print" },
        order: { type: Object, default: () => ({}) },
        orderProducts: { type: [Array, Object], default: () => [] },
        orderUser: { type: Object, default: () => ({}) },
        // The admin passes every address, the shop's order page only the first.
        orderAddress: { type: [Array, Object], default: () => [] },
        outletAddress: { type: Object, default: () => ({}) },
    },
    mounted() {
        this.$store.dispatch("company/lists").then().catch(() => {});
    },
    computed: {
        company: function () {
            return this.$store.getters["company/lists"] || {};
        },
        setting: function () {
            return this.$store.getters["frontendSetting/lists"] || {};
        },
        logo: function () {
            return this.setting.theme_logo || "";
        },
        // "Level 1,Ramc" -> "Level 1, Ramc".
        companyAddress: function () {
            return (this.company.company_address || "").replace(/\s*,\s*/g, ", ");
        },
        // "+880 1711000000 · hello@suglow.com"
        companyContact: function () {
            const phone = this.company.company_phone ? ((this.company.company_calling_code || "") + " " + this.company.company_phone).trim() : "";
            return [phone, this.company.company_email || ""].filter((part) => part !== "").join(" · ");
        },
        storeDomain: function () {
            return window.location.hostname.replace(/^www[.]/, "");
        },
        isPickUp: function () {
            return Number(this.order.order_type) === orderTypeEnum.PICK_UP;
        },
        isPaid: function () {
            return Number(this.order.payment_status) === paymentStatusEnum.PAID;
        },
        lines: function () {
            return Array.isArray(this.orderProducts) ? this.orderProducts : Object.values(this.orderProducts || {});
        },
        addresses: function () {
            const list = Array.isArray(this.orderAddress) ? this.orderAddress : [this.orderAddress];
            return list.filter((address) => address && typeof address === "object");
        },
        shipping: function () {
            return this.addresses.find((address) => Number(address.address_type) === addressTypeEnum.SHIPPING) || this.addresses[0] || null;
        },
        // Whoever pays: the billing address when there is one, else whoever it ships to, else the account.
        billTo: function () {
            const billing = this.addresses.find((address) => Number(address.address_type) === addressTypeEnum.BILLING) || this.shipping || {};
            const user = this.orderUser || {};
            return {
                name: billing.full_name || user.name || "",
                phone: billing.phone ? (billing.country_code || "") + billing.phone : (user.phone ? (user.country_code || "") + user.phone : ""),
                email: billing.email || user.email || "",
            };
        },
        zero: function () {
            return appService.currencyFormat(
                0,
                this.setting.site_digit_after_decimal_point ?? 2,
                this.setting.site_default_currency_symbol ?? "৳",
                this.setting.site_currency_position ?? currencyPositionEnum.LEFT
            );
        },
    },
    methods: {
        tr: tr,
        joinAddress: function (address) {
            if (!address) {
                return "";
            }
            return [address.address, address.city, address.state, address.zip_code, address.country]
                .filter((part) => part && String(part).trim() !== "")
                .join(", ");
        },
    },
};
</script>

<style scoped>
.order-invoice {
    max-width: 794px;
    margin: 0 auto;
}

@media print {
    .order-invoice {
        padding: 0;
        max-width: none;
    }

    /* Paper is printed black on white; colour stays on screen. */
    .order-invoice,
    .order-invoice * {
        color: #000 !important;
        border-color: #000 !important;
    }

    .order-invoice thead tr {
        background: #eee !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .order-invoice thead {
        display: table-header-group;
    }

    .order-invoice tr {
        break-inside: avoid;
    }
}
</style>
