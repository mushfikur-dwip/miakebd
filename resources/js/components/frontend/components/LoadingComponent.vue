<template>
    <!-- A slim progress bar along the top of the screen instead of the old
         full-screen spinner, which greyed out the whole page - on a product
         page, the product - every time anything loaded. Shown only if the wait
         outlasts a blink, so quick loads show nothing at all.

         The transparent shield keeps what the overlay also did: taps are
         ignored while a request is in flight, so a double tap on a submit
         button cannot send the form twice. -->
    <Teleport to="body">
        <div v-if="props && props.isActive" class="lc-shield" aria-hidden="true"></div>
        <transition name="lc-fade">
            <div v-if="shown" class="lc" role="progressbar" aria-busy="true" :aria-label="$t('label.please_wait')">
                <span class="lc-bar"></span>
            </div>
        </transition>
    </Teleport>
</template>

<script>
export default {
    name: "LoadingComponent",
    props: ['props'],
    data() {
        return {
            shown: false,
            timer: null,
        };
    },
    watch: {
        "props.isActive": {
            immediate: true,
            handler: function (active) {
                clearTimeout(this.timer);

                if (active) {
                    this.timer = setTimeout(() => {
                        this.shown = true;
                    }, 150);
                } else {
                    this.shown = false;
                }
            },
        },
    },
    beforeUnmount() {
        clearTimeout(this.timer);
    },
};
</script>

<style scoped>
.lc {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 9999;
    height: 3px;
    overflow: hidden;
    background: rgb(var(--primary) / 0.15);
    pointer-events: none;
}

.lc-bar {
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;
    width: 40%;
    border-radius: 0 3px 3px 0;
    background: linear-gradient(90deg, rgb(var(--primary) / 0.6), rgb(var(--primary)));
    box-shadow: 0 0 10px rgb(var(--primary) / 0.5);
    animation: lc-slide 1.1s cubic-bezier(0.45, 0, 0.25, 1) infinite;
}

.lc-shield {
    position: fixed;
    inset: 0;
    z-index: 9998;
    background: transparent;
    cursor: progress;
}

@keyframes lc-slide {
    0% { transform: translateX(-100%) scaleX(0.6); }
    60% { transform: translateX(160%) scaleX(1); }
    100% { transform: translateX(260%) scaleX(0.6); }
}

.lc-fade-enter-active,
.lc-fade-leave-active {
    transition: opacity 0.2s ease;
}

.lc-fade-enter-from,
.lc-fade-leave-to {
    opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
    .lc-bar {
        width: 100%;
        animation: none;
    }
}
</style>
