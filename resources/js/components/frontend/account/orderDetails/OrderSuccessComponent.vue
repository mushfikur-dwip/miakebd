<template>
    <!-- Shown once, when the customer lands here straight from placing the
         order. Pure CSS: a ring that draws itself, a disc that pops, a tick that
         draws, two ripples and a confetti burst, then the text settles in. No
         library, nothing to download, and all of it collapses to a plain fade
         for anyone who has asked their device for less motion. -->
    <Teleport to="body">
        <transition name="os-fade">
            <div v-if="open" class="os-backdrop" @click.self="close">
                <div class="os-card" role="dialog" aria-modal="true" :aria-labelledby="titleId">
                    <button type="button" class="os-x" :aria-label="$t('button.close')" @click="close">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                             stroke-linecap="round" aria-hidden="true">
                            <path d="M6 6l12 12M18 6 6 18" />
                        </svg>
                    </button>

                    <div class="os-hero" aria-hidden="true">
                        <span class="os-ripple"></span>
                        <span class="os-ripple os-ripple--late"></span>

                        <span class="os-confetti">
                            <i v-for="piece in confetti" :key="piece.id" :class="'os-bit os-bit--' + piece.shape"
                               :style="piece.style"></i>
                        </span>

                        <svg class="os-badge" viewBox="0 0 96 96">
                            <circle class="os-disc" cx="48" cy="48" r="44" />
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
                        <div v-if="order.order_serial_no" class="os-row">
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
                        <!-- The order is fetched after this opens; hold its
                             place so the card does not jump when it arrives. -->
                        <template v-if="!order.order_serial_no">
                            <span class="os-skel"></span>
                            <span class="os-skel os-skel--short"></span>
                        </template>
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
        const reach = 95 + ((i * 37) % 70);
        const x = Math.cos(angle) * reach;
        const y = Math.sin(angle) * reach;

        pieces.push({
            id: i,
            shape: SHAPES[i % SHAPES.length],
            style: {
                "--x": x.toFixed(1) + "px",
                "--y": y.toFixed(1) + "px",
                "--fall": (y + 150 + ((i * 53) % 90)).toFixed(1) + "px",
                "--r": ((i % 2 ? 1 : -1) * (180 + ((i * 71) % 360))) + "deg",
                "--delay": (0.72 + ((i * 13) % 10) / 100).toFixed(2) + "s",
                "--dur": (1.35 + ((i * 29) % 60) / 100).toFixed(2) + "s",
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
            confetti: buildConfetti(34),
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
        lockScroll: function (lock) {
            document.body.classList.toggle("overflow-hidden", !!lock);
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
    background: rgb(17 17 34 / 0.55);
    -webkit-backdrop-filter: blur(3px);
    backdrop-filter: blur(3px);
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
    animation: os-pop 0.55s cubic-bezier(0.2, 1.3, 0.35, 1) both;
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

.os-badge {
    position: relative;
    z-index: 2;
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
    animation: os-draw 0.55s cubic-bezier(0.65, 0, 0.35, 1) 0.15s forwards;
}

.os-disc {
    fill: rgb(var(--primary));
    transform: scale(0);
    transform-origin: 48px 48px;
    animation: os-disc 0.5s cubic-bezier(0.2, 1.5, 0.4, 1) 0.58s forwards;
}

.os-tick {
    fill: none;
    stroke: #ffffff;
    stroke-width: 6.5;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 60;
    stroke-dashoffset: 60;
    animation: os-draw 0.36s cubic-bezier(0.65, 0, 0.35, 1) 0.8s forwards;
}

.os-ripple {
    position: absolute;
    inset: 0;
    z-index: 1;
    border-radius: 999px;
    border: 3px solid rgb(var(--primary) / 0.45);
    opacity: 0;
    animation: os-ripple 1.1s cubic-bezier(0.2, 0.7, 0.3, 1) 0.8s forwards;
}

.os-ripple--late {
    animation-delay: 1.05s;
}

/* ---- Confetti ----------------------------------------------------------- */

.os-confetti {
    position: absolute;
    top: 50%;
    left: 50%;
    z-index: 3;
    width: 0;
    height: 0;
    pointer-events: none;
}

.os-bit {
    position: absolute;
    top: 0;
    left: 0;
    opacity: 0;
    animation: os-burst var(--dur) cubic-bezier(0.15, 0.6, 0.35, 1) var(--delay) forwards;
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
    animation: os-rise 0.5s cubic-bezier(0.2, 0.8, 0.3, 1) calc(0.78s + var(--d) * 0.08s) forwards;
}

.os-meta {
    display: flex;
    flex-direction: column;
    gap: 9px;
    margin-bottom: 20px;
    padding: 14px 15px;
    text-align: left;
    border-radius: 14px;
    background: #f8f8fb;
}

.os-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
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

.os-skel {
    display: block;
    height: 14px;
    border-radius: 6px;
    background: linear-gradient(90deg, #ececf2 0%, #f6f6fa 50%, #ececf2 100%);
    background-size: 200% 100%;
    animation: os-shimmer 1.2s ease-in-out infinite;
}

.os-skel--short {
    width: 60%;
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
    transition: transform 0.2s ease, filter 0.2s ease;
}

.os-primary:hover {
    transform: translateY(-1px);
    filter: brightness(1.06);
}

.os-primary:active {
    transform: scale(0.99);
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
}

.os-secondary:hover {
    color: rgb(var(--primary));
}

/* ---- Keyframes ---------------------------------------------------------- */

@keyframes os-pop {
    from {
        opacity: 0;
        transform: translateY(18px) scale(0.92);
    }
    to {
        opacity: 1;
        transform: none;
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
        opacity: 0.9;
        transform: scale(1);
    }
    100% {
        opacity: 0;
        transform: scale(1.9);
    }
}

/* Out along its angle, then drops as it spins and fades - a cheap gravity. */
@keyframes os-burst {
    0% {
        opacity: 1;
        transform: translate(0, 0) rotate(0deg) scale(0.4);
    }
    35% {
        opacity: 1;
        transform: translate(var(--x), var(--y)) rotate(calc(var(--r) * 0.4)) scale(1);
    }
    100% {
        opacity: 0;
        transform: translate(calc(var(--x) * 1.25), var(--fall)) rotate(var(--r)) scale(0.9);
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
