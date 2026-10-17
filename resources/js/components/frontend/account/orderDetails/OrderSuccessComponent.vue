<template>
    <!-- Shown once, when the customer lands here straight from placing the
         order. Pure CSS: a ring that draws itself, a disc that settles in, a
         tick that draws, a soft ripple and a confetti burst, then the text
         rises in. No library, nothing to download, and all of it collapses to
         a plain fade for anyone who has asked their device for less motion.

         It takes over from the "Confirming your order..." screen painted while
         the app loaded (see services/orderBridge.js). In that case its
         backdrop is there from the first frame, so the two dark layers never
         stack and flash. -->
    <Teleport to="body">
        <transition name="os-fade">
            <div v-if="open" class="os-backdrop" :class="bridged ? 'os-backdrop--instant' : ''" @click.self="close">
                <div class="os-card" role="dialog" aria-modal="true" :aria-labelledby="titleId">
                    <button type="button" class="os-x" :aria-label="$t('button.close')" @click="close">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                             stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6l12 12M18 6 6 18" />
                        </svg>
                    </button>

                    <div class="os-hero" aria-hidden="true">
                        <span class="os-ripple"></span>

                        <span class="os-confetti">
                            <i v-for="piece in confetti" :key="piece.id" :class="'os-bit os-bit--' + piece.shape"
                               :style="piece.style"></i>
                        </span>

                        <!-- The disc is an element of its own rather than part
                             of the SVG: the browser can then scale it on the
                             graphics card, where it stays smooth even while the
                             page below is still busy. -->
                        <span class="os-disc"></span>
                        <svg class="os-badge" viewBox="0 0 96 96">
                            <circle class="os-ring" cx="48" cy="48" r="44" />
                            <path class="os-tick" d="M30 49.5 42.5 62 67 36" />
                        </svg>
                    </div>

                    <h3 :id="titleId" class="os-title os-in" style="--d: 0">{{ $t('message.order_placed_title') }}</h3>
                    <p class="os-text os-in" style="--d: 1">
                        {{ firstName
                            ? $t('message.order_placed_thanks', { name: firstName })
                            : $t('message.order_placed_thanks_plain') }}
                    </p>

                    <div class="os-meta os-in" style="--d: 2">
                        <!-- Normally the order has arrived before this opens.
                             If it is slow, placeholder lines the same height as
                             the real ones hold its place, and the real ones
                             fade in over them. -->
                        <transition name="os-swap" mode="out-in">
                            <div v-if="order.order_serial_no" key="rows" class="os-rows">
                                <div class="os-row">
                                    <span>{{ $t('label.order_id') }}</span>
                                    <b class="os-chip">#{{ order.order_serial_no }}</b>
                                </div>
                                <div v-if="order.total_currency_price" class="os-row">
                                    <span>{{ $t('label.total') }}</span>
                                    <b>{{ order.total_currency_price }}</b>
                                </div>
                                <div v-if="order.payment_method_name" class="os-row">
                                    <span>{{ $t('label.payment_method') }}</span>
                                    <b>{{ order.payment_method_name }}</b>
                                </div>
                            </div>
                            <div v-else key="placeholder" class="os-rows">
                                <span class="os-skel"></span>
                                <span class="os-skel"></span>
                                <span class="os-skel os-skel--short"></span>
                            </div>
                        </transition>
                    </div>

                    <div class="os-actions os-in" style="--d: 3">
                        <button ref="primary" type="button" class="os-primary" @click="close">
                            {{ $t('button.see_your_order_details') }}
                        </button>
                        <router-link :to="{ name: 'frontend.home' }" class="os-secondary" @click="close">
                            {{ $t('button.continue_shopping') }}
                        </router-link>
                    </div>
                </div>
            </div>
        </transition>
    </Teleport>
</template>

<script>
import orderBridge from "../../../../services/orderBridge";

// Brand colour first so it dominates, then a warm, festive spread.
const COLOURS = ["rgb(var(--primary))", "#F5B83D", "#2AC769", "#FF8FA3", "#5AB2FF", "#A78BFA", "rgb(var(--primary))"];
const SHAPES = ["rect", "dot", "strip"];

// Fixed rather than random, so the burst looks the same every time and no two
// pieces collide into a clump. Spread over the upper half-circle, where there
// is room above the badge, and allowed to fall past it.
function buildConfetti(count) {
    const pieces = [];

    for (let i = 0; i < count; i += 1) {
        const t = i / (count - 1);
        const angle = (-165 + t * 150) * (Math.PI / 180);
        const reach = 90 + ((i * 37) % 70);
        const x = Math.cos(angle) * reach;
        const y = Math.sin(angle) * reach;

        pieces.push({
            id: i,
            shape: SHAPES[i % SHAPES.length],
            style: {
                "--x": x.toFixed(1) + "px",
                "--y": y.toFixed(1) + "px",
                "--fall": (y + 170 + ((i * 53) % 90)).toFixed(1) + "px",
                "--r": ((i % 2 ? 1 : -1) * (200 + ((i * 71) % 300))) + "deg",
                "--delay": (0.62 + ((i * 13) % 10) / 100).toFixed(2) + "s",
                "--dur": (1.6 + ((i * 29) % 60) / 100).toFixed(2) + "s",
                background: COLOURS[i % COLOURS.length],
            },
        });
    }

    return pieces;
}

export default {
    name: "OrderSuccessComponent",
    props: {
        open: { type: Boolean, default: false },
        order: { type: Object, default: () => ({}) },
        name: { type: String, default: "" },
    },
    emits: ["close"],
    data() {
        return {
            titleId: "os-title-" + Math.random().toString(36).slice(2, 8),
            confetti: buildConfetti(28),
            bridged: false,
            scrollPadded: false,
        };
    },
    computed: {
        firstName: function () {
            return String(this.name || "").trim().split(/\s+/)[0] || "";
        },
    },
    watch: {
        open: {
            immediate: true,
            handler: function (value) {
                if (value) {
                    // Decided before this renders, so the backdrop knows
                    // whether to appear at once (taking over from the screen
                    // that covered the page load) or to fade in itself.
                    this.bridged = orderBridge.isShown();
                    orderBridge.release(true);
                }

                this.lockScroll(value);

                if (value) {
                    // After the entrance, so a screen reader announces the
                    // dialog first and the focus ring does not flash mid-pop.
                    setTimeout(() => {
                        if (this.$refs.primary) {
                            this.$refs.primary.focus({ preventScroll: true });
                        }
                    }, 1100);
                }
            },
        },
    },
    mounted() {
        document.addEventListener("keydown", this.onKey);
    },
    beforeUnmount() {
        document.removeEventListener("keydown", this.onKey);
        this.lockScroll(false);
    },
    methods: {
        close: function () {
            this.$emit("close");
        },
        onKey: function (event) {
            if (this.open && event.key === "Escape") {
                this.close();
            }
        },
        /**
         * Locking the page's scroll takes its scrollbar away, and on a desktop
         * everything behind the card used to jump sideways by that width as it
         * opened. The gap is padded back while it is open.
         */
        lockScroll: function (lock) {
            const body = document.body;

            if (lock) {
                const gap = window.innerWidth - document.documentElement.clientWidth;
                if (gap > 0 && !this.scrollPadded) {
                    body.style.paddingRight = gap + "px";
                    this.scrollPadded = true;
                }
                body.classList.add("overflow-hidden");
                return;
            }

            body.classList.remove("overflow-hidden");
            if (this.scrollPadded) {
                body.style.paddingRight = "";
                this.scrollPadded = false;
            }
        },
    },
};
</script>

<style scoped>
.os-backdrop {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    overflow-y: auto;
    /* Confetti flies past the card's edges; on a narrow phone it would
       otherwise widen the scroll area for a moment and jolt the page. */
    overflow-x: hidden;
    /* No backdrop blur: blurring the whole page behind a moving card is what
       made the entrance stutter on lower-end phones. */
    background: rgb(17 17 34 / 0.55);
}

.os-card {
    position: relative;
    width: 100%;
    max-width: 380px;
    margin: auto;
    padding: 30px 22px 22px;
    text-align: center;
    background: #ffffff;
    border-radius: 22px;
    box-shadow: 0 24px 60px -12px rgb(17 17 34 / 0.35);
    /* Settles in without bouncing - an overshoot on the whole card read as a
       jolt rather than a celebration. */
    animation: os-card-in 0.6s cubic-bezier(0.16, 1, 0.3, 1) backwards;
}

.os-x {
    position: absolute;
    top: 12px;
    right: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 99px;
    color: #8a8ca3;
    background: #f5f5f9;
    transition: background-color 0.2s ease, color 0.2s ease;
}

.os-x:hover {
    color: #1f1f39;
    background: #ececf2;
}

.os-x svg {
    width: 15px;
    height: 15px;
}

/* ---- The badge ---------------------------------------------------------- */

.os-hero {
    position: relative;
    width: 96px;
    height: 96px;
    margin: 4px auto 20px;
}

.os-disc {
    position: absolute;
    inset: 4px;
    z-index: 2;
    border-radius: 999px;
    background: rgb(var(--primary));
    transform: scale(0);
    /* A little past full size and back - enough to feel alive, not a bounce. */
    animation: os-disc 0.55s cubic-bezier(0.34, 1.3, 0.64, 1) 0.45s forwards;
}

.os-badge {
    position: relative;
    z-index: 3;
    width: 96px;
    height: 96px;
    overflow: visible;
}

.os-ring {
    fill: none;
    stroke: rgb(var(--primary));
    stroke-width: 4;
    stroke-linecap: round;
    stroke-dasharray: 277;
    stroke-dashoffset: 277;
    transform: rotate(-90deg);
    transform-origin: 48px 48px;
    animation: os-draw 0.5s cubic-bezier(0.65, 0, 0.35, 1) 0.1s forwards;
}

.os-tick {
    fill: none;
    stroke: #ffffff;
    stroke-width: 6.5;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 60;
    stroke-dashoffset: 60;
    animation: os-draw 0.36s cubic-bezier(0.65, 0, 0.35, 1) 0.72s forwards;
}

.os-ripple {
    position: absolute;
    inset: 0;
    z-index: 1;
    border-radius: 999px;
    border: 3px solid rgb(var(--primary) / 0.35);
    opacity: 0;
    animation: os-ripple 1.3s cubic-bezier(0.16, 1, 0.3, 1) 0.75s forwards;
}

/* ---- Confetti ----------------------------------------------------------- */

.os-confetti {
    position: absolute;
    top: 50%;
    left: 50%;
    z-index: 4;
    width: 0;
    height: 0;
    pointer-events: none;
}

.os-bit {
    position: absolute;
    top: 0;
    left: 0;
    opacity: 0;
    will-change: transform, opacity;
    animation: os-burst var(--dur) linear var(--delay) forwards;
}

.os-bit--rect {
    width: 8px;
    height: 10px;
    margin: -5px 0 0 -4px;
    border-radius: 2px;
}

.os-bit--dot {
    width: 8px;
    height: 8px;
    margin: -4px 0 0 -4px;
    border-radius: 99px;
}

.os-bit--strip {
    width: 4px;
    height: 13px;
    margin: -6px 0 0 -2px;
    border-radius: 2px;
}

/* ---- Text --------------------------------------------------------------- */

.os-title {
    margin: 0 0 6px;
    font-size: 23px;
    font-weight: 800;
    letter-spacing: -0.01em;
    color: #1f1f39;
}

.os-text {
    margin: 0 auto 18px;
    max-width: 290px;
    font-size: 14.5px;
    line-height: 1.55;
    color: #6e7191;
}

.os-in {
    opacity: 0;
    /* Starts while the disc is still landing, so the card is never a large
       blank white square waiting for its badge. */
    animation: os-rise 0.55s cubic-bezier(0.16, 1, 0.3, 1) calc(0.48s + var(--d) * 0.07s) forwards;
}

.os-meta {
    margin-bottom: 20px;
    padding: 14px 15px;
    text-align: left;
    border-radius: 14px;
    background: #f8f8fb;
}

.os-rows {
    display: flex;
    flex-direction: column;
    gap: 9px;
}

.os-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 22px;
    font-size: 13.5px;
    color: #6e7191;
}

.os-row b {
    font-weight: 700;
    color: #1f1f39;
    text-align: right;
}

.os-chip {
    padding: 2px 10px;
    border-radius: 99px;
    color: rgb(var(--primary)) !important;
    background: rgb(var(--primary-light));
    letter-spacing: 0.02em;
}

/* As tall as a real row (22px), so the card does not change height when the
   order arrives and replaces them. */
.os-skel {
    display: block;
    height: 14px;
    margin: 4px 0;
    border-radius: 6px;
    background: linear-gradient(90deg, #ececf2 0%, #f6f6fa 50%, #ececf2 100%);
    background-size: 200% 100%;
    animation: os-shimmer 1.2s ease-in-out infinite;
}

.os-skel--short {
    width: 60%;
}

.os-swap-enter-active,
.os-swap-leave-active {
    transition: opacity 0.2s ease;
}

.os-swap-enter-from,
.os-swap-leave-to {
    opacity: 0;
}

.os-actions {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.os-primary {
    height: 50px;
    border-radius: 99px;
    font-size: 15px;
    font-weight: 700;
    color: #ffffff;
    background: rgb(var(--primary));
    box-shadow: 0 10px 22px -10px rgb(var(--primary) / 0.8);
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), filter 0.2s ease, box-shadow 0.25s ease;
}

.os-primary:hover {
    transform: translateY(-1px);
    filter: brightness(1.06);
    box-shadow: 0 14px 26px -12px rgb(var(--primary) / 0.85);
}

.os-primary:active {
    transform: scale(0.985);
}

/* It is focused on open; the browser's default black ring looked like an
   error on the brand-coloured button. */
.os-primary:focus {
    outline: none;
}

.os-primary:focus-visible {
    outline: 3px solid rgb(var(--primary) / 0.35);
    outline-offset: 3px;
}

.os-secondary {
    display: block;
    padding: 6px;
    font-size: 14px;
    font-weight: 600;
    color: #6e7191;
    transition: color 0.2s ease;
}

.os-secondary:hover {
    color: rgb(var(--primary));
}

/* ---- Keyframes ---------------------------------------------------------- */

@keyframes os-card-in {
    from {
        opacity: 0;
        transform: translateY(16px) scale(0.96);
    }
}

@keyframes os-draw {
    to {
        stroke-dashoffset: 0;
    }
}

@keyframes os-disc {
    to {
        transform: scale(1);
    }
}

@keyframes os-ripple {
    0% {
        opacity: 0.85;
        transform: scale(1);
    }
    100% {
        opacity: 0;
        transform: scale(1.8);
    }
}

/* A thrown arc: out fast and slowing to the top of the throw, then falling
   faster and faster as it spins, fading only at the very end. Each stretch
   has its own easing - one curve over the whole flight made every piece stop
   dead at the top and then drop. */
@keyframes os-burst {
    0% {
        opacity: 1;
        transform: translate3d(0, 0, 0) rotate(0deg) scale(0.4);
        animation-timing-function: cubic-bezier(0.12, 0.75, 0.35, 1);
    }
    32% {
        opacity: 1;
        transform: translate3d(var(--x), var(--y), 0) rotate(calc(var(--r) * 0.3)) scale(1);
        animation-timing-function: cubic-bezier(0.45, 0, 0.85, 0.55);
    }
    82% {
        opacity: 1;
    }
    100% {
        opacity: 0;
        transform: translate3d(calc(var(--x) * 1.2), var(--fall), 0) rotate(var(--r)) scale(0.85);
    }
}

@keyframes os-rise {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: none;
    }
}

@keyframes os-shimmer {
    from {
        background-position: 100% 0;
    }
    to {
        background-position: -100% 0;
    }
}

.os-fade-enter-active,
.os-fade-leave-active {
    transition: opacity 0.25s ease;
}

.os-fade-enter-from,
.os-fade-leave-to {
    opacity: 0;
}

/* Taking over from the page-load screen: there from the first frame. */
.os-backdrop--instant.os-fade-enter-active {
    transition: none;
}

.os-backdrop--instant.os-fade-enter-from {
    opacity: 1;
}

@media (min-width: 640px) {
    .os-card {
        padding: 34px 28px 24px;
    }
}

/* Everything lands in its final state at once; only the fade remains. */
@media (prefers-reduced-motion: reduce) {
    .os-card,
    .os-in {
        animation: none;
        opacity: 1;
    }

    .os-ring,
    .os-tick {
        animation: none;
        stroke-dashoffset: 0;
    }

    .os-disc {
        animation: none;
        transform: none;
    }

    .os-ripple,
    .os-confetti,
    .os-skel {
        display: none;
    }

    .os-primary,
    .os-x {
        transition: none;
    }
}
</style>
