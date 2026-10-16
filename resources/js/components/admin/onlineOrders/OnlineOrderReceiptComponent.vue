<template>
    <button type="button" v-print="printObj"
        class="flex items-center justify-center gap-2 px-4 h-[38px] rounded shadow-db-card bg-primary">
        <i class="lab lab-line-printer lab-font-size-16 text-white"></i>
        <span class="text-sm capitalize text-white"> {{ $t('button.print_invoice') }}</span>
    </button>

    <!-- Printed, never shown: an online order gets an A4 invoice, not the
         till's 80 mm receipt (that one stays on the POS pages). -->
    <div id="receipt" class="modal">
        <OrderInvoiceComponent print-id="print" :order="order" :orderProducts="orderProducts" :orderUser="orderUser"
            :orderAddress="orderAddress" :outletAddress="outletAddress" />
    </div>
</template>

<script>
import print from "vue3-print-nb";
import OrderInvoiceComponent from "../../shared/OrderInvoiceComponent";
import { tr } from "../../shared/invoiceText";

export default {
    name: "OnlineOrderReceiptComponent",
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
                // An A4 page with a printer's margin. (vue3-print-nb splits
                // extraHead on commas, so keep it comma-free.)
                extraHead: "<style>@page{size:A4;margin:12mm}</style>",
            },
        }
    },
    computed: {
        outletAddress: function () {
            return this.$store.getters['onlineOrder/outletAddress'];
        }
    },
}
</script>
