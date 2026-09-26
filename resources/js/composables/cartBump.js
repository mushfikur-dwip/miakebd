// Mixin for the cart buttons in the header and the mobile bottom bar: when the
// cart gains an item, `cartBump` is true for one short animation (.cart-bump in
// app.css), so the shopper sees where the product went. It watches the total
// quantity rather than the line count, so adding a second unit of something
// already in the cart bumps too.
export default {
    data() {
        return {
            cartBump: false,
        };
    },
    computed: {
        cartUnits: function () {
            return (this.$store.getters['frontendCart/lists'] || [])
                .reduce((sum, line) => sum + (Number(line.quantity) || 0), 0);
        },
    },
    watch: {
        cartUnits: function (now, before) {
            if (now <= before) {
                return;
            }

            // Off then on again, so a second add mid-animation restarts it.
            this.cartBump = false;
            this.$nextTick(() => {
                this.cartBump = true;
                clearTimeout(this.cartBumpTimer);
                this.cartBumpTimer = setTimeout(() => {
                    this.cartBump = false;
                }, 650);
            });
        },
    },
    beforeUnmount() {
        clearTimeout(this.cartBumpTimer);
    },
};
