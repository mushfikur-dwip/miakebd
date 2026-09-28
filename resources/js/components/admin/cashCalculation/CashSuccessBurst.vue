<template>
    <!-- A big green tick that draws itself, with what just happened, for
         about two seconds. Money entries at a counter are made in a hurry;
         a toast in the corner is easy to miss, and a missed "saved" is how
         the same Cash In gets typed twice. Clicks go straight through it. -->
    <Teleport to="body">
        <transition name="cash-burst">
            <div v-if="current" :key="current.id" class="cash-burst" role="status" aria-live="polite">
                <div class="cash-burst__card">
                    <svg class="cash-burst__check" viewBox="0 0 52 52" aria-hidden="true">
                        <circle class="cash-burst__circle" cx="26" cy="26" r="24" fill="none" />
                        <path class="cash-burst__tick" fill="none" d="M15 27.5 l7.5 7.5 l15 -16" />
                    </svg>
                    <p class="cash-burst__title">{{ current.title }}</p>
                    <p class="cash-burst__amount">{{ current.amount }}</p>
                    <p v-if="current.effect" class="cash-burst__effect">{{ current.effect }}</p>
                </div>
            </div>
        </transition>
    </Teleport>
</template>

<script>
let burstId = 0;

export default {
    name: "CashSuccessBurst",
    data() {
        return { current: null, timer: null };
    },
    beforeUnmount() {
        clearTimeout(this.timer);
    },
    methods: {
        // { title, amount, effect } - a second call restarts the animation.
        show: function (message) {
            clearTimeout(this.timer);
            this.current = { ...message, id: ++burstId };
            this.timer = setTimeout(() => { this.current = null; }, 2200);
        },
    },
};
</script>

<style scoped>
.cash-burst {
    position: fixed;
    inset: 0;
    z-index: 70;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    padding: 16px;
}

.cash-burst__card {
    min-width: 240px;
    max-width: min(360px, 100%);
    padding: 22px 28px 20px;
    border-radius: 18px;
    background: #fff;
    text-align: center;
    box-shadow: 0 18px 45px rgba(22, 101, 52, 0.22), 0 0 0 1px rgba(22, 163, 74, 0.15);
    animation: cash-burst-pop 0.32s cubic-bezier(0.2, 0.9, 0.3, 1.25) both;
}

.cash-burst__check {
    width: 64px;
    height: 64px;
    margin: 0 auto 10px;
    display: block;
}

.cash-burst__circle {
    stroke: #16a34a;
    stroke-width: 3;
    stroke-dasharray: 151;
    stroke-dashoffset: 151;
    animation: cash-burst-draw 0.5s ease-out 0.05s forwards;
}

.cash-burst__tick {
    stroke: #16a34a;
    stroke-width: 4;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-dasharray: 36;
    stroke-dashoffset: 36;
    animation: cash-burst-draw 0.3s ease-out 0.45s forwards;
}

.cash-burst__title {
    font-size: 18px;
    font-weight: 700;
    color: #166534;
}

.cash-burst__amount {
    margin-top: 2px;
    font-size: 26px;
    font-weight: 800;
    color: #14532d;
}

.cash-burst__effect {
    margin-top: 6px;
    font-size: 13px;
    color: #4b5563;
}

.cash-burst-leave-active {
    transition: opacity 0.3s ease, transform 0.3s ease;
}

.cash-burst-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}

@keyframes cash-burst-draw {
    to { stroke-dashoffset: 0; }
}

@keyframes cash-burst-pop {
    from { opacity: 0; transform: scale(0.85); }
    to { opacity: 1; transform: scale(1); }
}

/* Motion turned down on the device: the tick is simply there. */
@media (prefers-reduced-motion: reduce) {
    .cash-burst__card,
    .cash-burst__circle,
    .cash-burst__tick {
        animation: none;
        stroke-dashoffset: 0;
    }

    .cash-burst-leave-active {
        transition: opacity 0.2s linear;
    }
}
</style>
