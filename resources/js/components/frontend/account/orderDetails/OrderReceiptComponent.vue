<template>
    <button type="button" v-print="printObj"
        class="px-6 py-3 capitalize rounded-full whitespace-nowrap text-center font-semibold text-white bg-primary">
        {{
            $t('button.download_receipt')
        }}
    </button>

    <!-- Printed, never shown: the order's A4 invoice. -->
    <div id="receipt" class="modal">
        <OrderInvoiceComponent print-id="print" :order="order" :orderProducts="orderProducts" :orderUser="orderUser"
            :orderAddress="orderAddress" :outletAddress="outletAddress" />
    </div>
</template>

<script>
import print from "vue3-print-nb";
import OrderInvoiceComponent from "../../../shared/OrderInvoiceComponent";
import { tr } from "../../../shared/invoiceText";

export default {
    name: "OrderReceiptComponent",
    components: { OrderInvoiceComponent },
    props: {
        order: Object,
        orderProducts: [Array, Object],
        orderUser: Object,
        orderAddress: [Array, Object]
    },
    directives: {
        print
    },
    data() {
        return {
            printObj: {
                id: "print",
                popTitle: tr("invoice"),
                // vue3-print-nb splits extraHead on commas, so keep it comma-free.
                extraHead: "<style>@page{size:A4;margin:12mm}</style>",
            },
        }
    },
    computed: {
        outletAddress: function () {
            return this.$store.getters['frontendOrder/outletAddress'];
        },
    }
}
</script>
