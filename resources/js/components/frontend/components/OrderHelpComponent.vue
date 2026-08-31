<template>
    <!-- Escape hatch for a customer who cannot finish an order on their own.
         A dead end is a lost sale, and a large share of orders in this market
         are closed in chat instead — so both routes stay one tap away, on the
         product page and on every checkout step.

         The guard is belt-and-braces: `phoneDigits` falls back to the store's
         own number, so in practice it is always truthy. It stays so that
         removing the fallback later hides the panel instead of shipping a
         link that dials nothing. -->
    <div v-if="phoneDigits" class="co-help" :class="{ 'is-bare': bare }">
        <p class="co-help-title">{{ heading }}</p>
        <p v-if="!bare" class="co-help-text">{{ $t('message.order_help_text') }}</p>

        <div class="co-help-actions">
            <!-- wa.me is WhatsApp's own short link: it opens the app on a
                 phone and WhatsApp Web on a desktop, so one href covers both.
                 The number must be digits only, no + and no leading zero. -->
            <a :href="whatsappLink" target="_blank" rel="noopener noreferrer"
               class="co-help-btn co-help-wa" :aria-label="$t('button.whatsapp')">
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413" />
                </svg>
                <span>{{ $t('button.whatsapp') }}</span>
            </a>

            <a :href="callLink" class="co-help-btn co-help-call" :aria-label="$t('button.phone_call')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                </svg>
                <span>{{ $t('button.phone_call') }}</span>
            </a>
        </div>

        <!-- dir="ltr" so the number keeps its reading order under the Arabic
             locale, where the surrounding text runs right to left. -->
        <a v-if="!bare" :href="callLink" dir="ltr" class="co-help-number">{{ displayNumber }}</a>
    </div>
</template>

<script>
import appService from "../../../services/appService";

// Used when the shop has not filled Settings -> Company in. Same number the
// navbar and footer helplines fall back to; the three must not disagree.
const FALLBACK_PHONE = '01709786330';
const FALLBACK_CALLING_CODE = '880';

// A wa.me link with the whole cart pasted in stops being readable, and very
// long URLs get truncated on the way into the app.
const MAX_LISTED_ITEMS = 10;

export default {
    name: "OrderHelpComponent",
    props: {
        // Given on a product page, where there is no cart yet: the message is
        // then about this one item. Left null on checkout, where the cart is
        // what the customer wants to talk about.
        // Expected keys: name, quantity, price, sku.
        product: {
            type: Object,
            default: null
        },
        // Product-page look: just the line and the two buttons, no card and no
        // helpline number. The product summary is already a dense column and a
        // second bordered box in it reads as a second product.
        bare: {
            type: Boolean,
            default: false
        }
    },
    computed: {
        setting: function () {
            return this.$store.getters['frontendSetting/lists'] || {};
        },
        carts: function () {
            const carts = this.$store.getters['frontendCart/lists'];

            return Array.isArray(carts) ? carts : [];
        },
        total: function () {
            return this.$store.getters['frontendCart/total'];
        },
        heading: function () {
            return this.product
                ? this.$t('message.order_help_product')
                : this.$t('label.order_help_title');
        },
        // Everything below works on digits only. Admins type numbers with
        // spaces, dashes and brackets, none of which belong in a tel: or
        // wa.me target.
        phoneDigits: function () {
            return this.digitsOf(this.setting.company_phone) || FALLBACK_PHONE;
        },
        callingCodeDigits: function () {
            return this.digitsOf(this.setting.company_calling_code) || FALLBACK_CALLING_CODE;
        },
        // WhatsApp resolves a link by full international number, with no plus
        // and no trunk zero: 01709786330 has to arrive as 8801709786330.
        // A number that already carries the country code is left alone, so
        // saving it either way in the admin gives the same link.
        whatsappNumber: function () {
            const phone = this.phoneDigits;
            const code = this.callingCodeDigits;

            if (phone.startsWith(code) && phone.length > code.length) {
                return phone;
            }

            return code + phone.replace(/^0+/, '');
        },
        displayNumber: function () {
            return this.phoneDigits;
        },
        // The dialer gets the international form: it works on a local handset
        // and it also works for a customer calling from abroad, which the
        // bare local number does not.
        callLink: function () {
            return 'tel:+' + this.whatsappNumber;
        },
        // Built from the route, not from window.location.href. This is a SPA:
        // moving from one product to the next never reloads the page, so a
        // href read once would keep pointing at the product before it.
        pageUrl: function () {
            if (typeof window === 'undefined') {
                return '';
            }

            return window.location.origin + this.$route.fullPath;
        },
        whatsappText: function () {
            return this.product ? this.productText : this.cartText;
        },
        // Product page: the shop needs to know which item, which variation and
        // how many — the SKU pins the variation down exactly, and the link
        // saves them searching for it.
        productText: function () {
            const product = this.product || {};
            const lines = [this.$t('message.whatsapp_order_greeting'), ''];

            lines.push(product.name || '');

            if (product.sku) {
                lines.push('SKU: ' + product.sku);
            }

            const quantity = Number(product.quantity) || 1;
            lines.push(quantity + ' x ' + this.money(product.price));

            if (this.pageUrl) {
                lines.push('', this.pageUrl);
            }

            return lines.join('\n');
        },
        cartText: function () {
            const lines = [this.$t('message.whatsapp_order_greeting')];
            const carts = this.carts;

            if (carts.length > 0) {
                lines.push('');

                carts.slice(0, MAX_LISTED_ITEMS).forEach((cart, index) => {
                    const variation = cart.variation_names ? ' (' + cart.variation_names + ')' : '';
                    lines.push(
                        (index + 1) + '. ' + cart.name + variation
                        + ' - ' + cart.quantity + ' x ' + this.money(cart.price)
                    );
                });

                if (carts.length > MAX_LISTED_ITEMS) {
                    lines.push('+ ' + (carts.length - MAX_LISTED_ITEMS));
                }

                lines.push('');
                lines.push(this.$t('label.total') + ': ' + this.money(this.total));
            }

            return lines.join('\n');
        },
        whatsappLink: function () {
            return 'https://wa.me/' + this.whatsappNumber
                + '?text=' + encodeURIComponent(this.whatsappText);
        }
    },
    methods: {
        digitsOf: function (value) {
            return String(value ?? '').replace(/\D/g, '');
        },
        money: function (amount) {
            const setting = this.setting;

            return appService.currencyFormat(
                Number(amount) || 0,
                setting.site_digit_after_decimal_point,
                setting.site_default_currency_symbol,
                setting.site_currency_position
            );
        }
    }
}
</script>
