<template>
    <!-- Up from the moment Confirm Order is pressed until the browser leaves
         for the payment step. master.blade.php paints the same screen on the
         order page before the app loads there, so the two halves read as one
         (the markup and the .ob styles in app.css are shared - keep them in
         step). -->
    <Teleport to="body">
        <transition name="ob-fade">
            <div v-if="open" class="ob" role="status" aria-live="polite">
                <div class="ob-card">
                    <span class="ob-badge" aria-hidden="true">
                        <span class="ob-pulse"></span>
                        <svg class="ob-spin" viewBox="0 0 76 76">
                            <circle cx="38" cy="38" r="36" />
                        </svg>
                        <span class="ob-core">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
                                 stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5.5 8h13l-1.1 12H6.6L5.5 8Z" />
                                <path d="M9 8V7a3 3 0 0 1 6 0v1" />
                            </svg>
                        </span>
                    </span>
                    <b class="ob-title">{{ $t('message.placing_your_order') }}</b>
                    <span class="ob-text">{{ $t('message.dont_close_this_page') }}</span>
                </div>
            </div>
        </transition>
    </Teleport>
</template>

<script>
export default {
    name: "OrderPlacingComponent",
    props: {
        open: { type: Boolean, default: false },
    },
};
</script>

<style scoped>
/* Here rather than in app.css: Tailwind drops component-layer classes it never
   sees written out, and Vue builds these names from "ob-fade" at runtime. */
.ob-fade-enter-active,
.ob-fade-leave-active {
    transition: opacity 0.2s ease;
}

.ob-fade-enter-from,
.ob-fade-leave-to {
    opacity: 0;
}
</style>
