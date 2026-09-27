<template>
    <LoadingComponent :props="loading" />
    <section class="mb-28 sm:mb-20">
        <div class="container">
            <!--  Header Route Start -->
            <div class="flex items-start gap-4 mb-7">
                <button @click.prevent="goBack"
                    class="lab lab-line-undo lab-font-size-20 !text-xl !font-bold text-primary"></button>
                <router-view name="header" />
            </div>

            <!--  Header Route Close -->

            <!-- An empty cart used to throw the shopper to the home page with
                 no word of why; now the cart page says so and offers a way on. -->
            <div v-if="cartEmpty" class="flex flex-col items-center text-center gap-4 py-16 px-6 mb-12 rounded-2xl bg-primary-slate">
                <i class="lab-line-bag text-5xl text-primary" aria-hidden="true"></i>
                <p class="text-base sm:text-lg font-semibold max-w-md">{{ $t('message.empty_cart') }}</p>
                <router-link :to="{ name: 'frontend.product' }"
                    class="inline-flex items-center h-11 px-6 rounded-full font-bold text-white bg-primary shadow-btn-primary">
                    {{ $t('button.continue_shopping') }}
                </router-link>
            </div>

            <template v-else>
            <!--  Checkbox Start -->
            <ul class="multi-step w-full max-w-lg mx-auto my-12 pt-2 pb-5 px-4 flex items-center justify-center">
                <li class="list-none w-full flex after:content-[''] after:w-full after:h-1 last:after:hidden last:w-fit"
                    :class="currentRoute === '/checkout/checkout' || currentRoute === '/checkout/payment' ? 'after:bg-success' : 'after:bg-[#EFF0F6]'">
                    <router-link :to="{ name: 'frontend.checkout.cartList' }"
                        class="flex flex-col items-center gap-4 -mt-[13px] relative">
                        <i v-if="currentRoute === '/checkout/checkout' || currentRoute === '/checkout/payment'"
                            class="lab lab-fill-save text-lg w-[30px] h-[30px] leading-[30px] text-center rounded-full text-white bg-success"></i>
                        <span v-else class="w-[30px] h-[30px] border-[4px] rounded-full border-success bg-white"></span>
                        <small :class="currentRoute === '/checkout/cart-list' ? 'text-success' : 'text-secondary'"
                            class="text-sm font-medium capitalize absolute -bottom-8">
                            {{ $t('label.cart') }}
                        </small>
                    </router-link>
                </li>

                <li class="list-none w-full flex after:content-[''] after:w-full after:h-1 last:after:hidden last:w-fit"
                    :class="currentRoute === '/checkout/payment' ? 'after:bg-success' : 'after:bg-[#EFF0F6]'">
                    <router-link :to="{ name: 'frontend.checkout.checkout' }"
                        class="flex flex-col items-center gap-4 -mt-[13px] relative">
                        <i v-if="currentRoute === '/checkout/payment'"
                            class="lab lab-fill-save text-lg w-[30px] h-[30px] leading-[30px] text-center rounded-full text-white bg-success"></i>
                        <span v-else
                            class="w-[30px] h-[30px] border-[4px] rounded-full border-[#D9DBE9] bg-[#D9DBE9]"></span>
                        <small :class="currentRoute === '/checkout/checkout' ? 'text-success' : 'text-secondary'"
                            class="text-sm font-medium capitalize absolute -bottom-8">
                            {{ $t('label.checkout') }}
                        </small>
                    </router-link>
                </li>

                <li
                    class="list-none w-full flex after:content-[''] after:w-full after:h-1 last:after:hidden last:w-fit after:bg-[#EFF0F6]">
                    <router-link :to="{ name: 'frontend.checkout.payment' }"
                        class="flex flex-col items-center gap-4 -mt-[13px] relative">
                        <span class="w-[30px] h-[30px] border-[4px] rounded-full border-[#D9DBE9] bg-[#D9DBE9]"></span>
                        <small :class="currentRoute === '/checkout/payment' ? 'text-success' : 'text-secondary'"
                            class="text-sm font-medium capitalize absolute -bottom-8">
                            {{ $t('label.payment') }}
                        </small>
                    </router-link>
                </li>
            </ul>
            <!--  Checkbox Close -->

            <!-- Default Router -->
            <router-view />
            <!-- Default Router -->
            </template>

            <!-- Sits in the wrapper, not in a step, so the WhatsApp and call
                 buttons are there whether the customer stalls on the cart,
                 the address form or the payment choice. -->
            <OrderHelpComponent />
        </div>
    </section>
</template>

<script>
import CartListComponent from "./cartList/CartListComponent.vue";
import router from "../../../router";
import appService from "../../../services/appService";
import LoadingComponent from "../components/LoadingComponent.vue";
import OrderHelpComponent from "../components/OrderHelpComponent.vue";

export default {
    name: "CheckoutComponent",
    components: { LoadingComponent, CartListComponent, OrderHelpComponent },
    data() {
        return {
            loading: {
                isActive: false,
            },
            currentRoute: null,
        }
    },
    computed: {
        isList: function () {
            return this.$store.getters['frontendCart/isList'];
        },
        cartEmpty: function () {
            return this.$store.getters['frontendCart/lists'].length === 0;
        },
    },
    mounted() {
        this.currentRoute = this.$route.path;
        this.$store.dispatch('frontendCart/listChecker').then(res => {
            if (!res.status) {
                this.toEmptyCart();
            }
        }).catch((err) => {
            if (!err.status) {
                this.toEmptyCart();
            }
        })
    },
    methods: {
        goBack: function () {
            router.go(-1)
        },
        // Checkout and payment cannot go on without a cart, so they fall back
        // to the cart step, which explains itself; the cart step stays put.
        toEmptyCart: function () {
            if (this.$route.name !== 'frontend.checkout.cartList') {
                this.$router.push({ name: 'frontend.checkout.cartList' });
            }
        }
    },
    watch: {
        $route(to, from) {
            this.currentRoute = to.path;
        },
        isList: {
            deep: true,
            handler(isListObject) {
                if (!isListObject) {
                    this.toEmptyCart();
                }
            }
        }
    }
}
</script>