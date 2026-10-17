<template>
    <!-- The order's A4 invoice, shown in the parent's print box. -->
    <OrderInvoiceComponent print-id="orderInvoiceSheet" :order="order" :orderProducts="orderProducts" :orderUser="orderUser"
        :orderAddress="orderAddress" :outletAddress="outletAddress" />
</template>

<script>
import targetService from "../../../services/targetService";
import OrderInvoiceComponent from "../../shared/OrderInvoiceComponent";

export default {
    name: "OrderReceiptComponent",
    components: { OrderInvoiceComponent },
    props: {
        "method": { type: Function },
        "orderId": { type: Number }
    },
    data() {
        return {
            loading: {
                isActive: false
            },
        }
    },
    mounted() {
        this.modalShow();
        this.show();
    },
    computed: {
        order: function () {
            return this.$store.getters['frontendOrder/show'];
        },
        orderProducts: function () {
            return this.$store.getters['frontendOrder/orderProducts'];
        },
        orderUser: function () {
            return this.$store.getters['frontendOrder/orderUser'];
        },
        orderAddress: function () {
            return this.$store.getters['frontendOrder/orderAddress'];
        },
        outletAddress: function () {
            return this.$store.getters['frontendOrder/outletAddress'];
        },
    },
    methods: {
        modalShow: function () {
            targetService.showTarget("orderReceiptPrint", 'modal-active');
        },
        show: function () {
            if (this.$props.orderId && typeof this.$props.orderId !== "undefined") {
                this.loading.isActive = true;
                this.$store.dispatch("frontendOrder/show", this.$props.orderId).then(res => {
                    this.loading.isActive = false;
                }).catch((error) => {
                    this.loading.isActive = false;
                });
            }
        },
    }
}
</script>
