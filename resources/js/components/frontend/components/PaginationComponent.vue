<template>
    <RenderLessPagination :data="data" :limit="limit" :keep-length="keepLength" @pagination-change-page="onPaginationChangePage" v-slot="slotProps">
        <!-- mb on mobile clears FrontendMobileNavBarComponent, which is
             `fixed bottom-0`. Was mobile:mb-12 (48px) against a ~68px bar with
             a cart button raised above it, so Previous/Next sat underneath. -->
        <nav v-bind="$attrs" aria-label="Pagination" v-if="slotProps.computed.total > slotProps.computed.perPage" class="flex items-center justify-center gap-3 sm:gap-4 mobile:mb-6">
            <button :disabled="!slotProps.computed.prevPageUrl" :class="slotProps.computed.prevPageUrl ? 'hover:text-white hover:bg-primary' : ''" v-on="slotProps.prevButtonEvents" class="h-10 leading-10 px-4 rounded-full font-medium capitalize bg-gray-100 transition-all duration-500">
                <slot name="prev-nav">
                    {{ $t('label.previous') }}
                </slot>
            </button>

            <button :class="slotProps.computed.currentPage === page ? 'bg-primary text-white' : ''" :aria-current="slotProps.computed.currentPage === page ? 'page' : null" v-for="(page, key) in slotProps.computed.pageRange" :key="key" v-on="slotProps.pageButtonEvents(page)" class="w-10 h-10 leading-10 rounded-full font-medium capitalize text-center transition-all duration-500 hover:text-white hover:bg-primary hidden sm:block bg-gray-100">
                {{ page }}
            </button>

            <!-- Mobile only. The numbered buttons above are `hidden sm:block`,
                 so on a phone the control was just Previous/Next with nothing
                 saying where you were — unusable on the 14-page listings. -->
            <span class="sm:hidden h-10 leading-10 px-3 rounded-full font-medium text-sm bg-primary-slate text-primary whitespace-nowrap">
                {{ slotProps.computed.currentPage }} / {{ lastPage(slotProps.computed) }}
            </span>

            <button :disabled="!slotProps.computed.nextPageUrl" :class="slotProps.computed.nextPageUrl ? 'hover:text-white hover:bg-primary' : ''" v-on="slotProps.nextButtonEvents" class="h-10 leading-10 px-4 rounded-full font-medium capitalize bg-gray-100 transition-all duration-500">
                <slot name="next-nav">
                    {{ $t('label.next') }}
                </slot>
            </button>
        </nav>
    </RenderLessPagination>
</template>

<script>
import RenderLessPagination from 'laravel-vue-pagination/src/RenderlessPagination.vue';

export default {
    name: "PaginationComponent",
    inheritAttrs: false,
    emits: ['pagination-change-page'],
    components: {
        RenderLessPagination
    },
    props: {
        data: {
            type: Object,
            default: () => {}
        },
        limit: {
            type: Number,
            default: 0
        },
        keepLength: {
            type: Boolean,
            default: false
        },
    },
    data() {
        return {
            activeClass : [
                "bg-primary",
                "text-white"
            ]
        }
    },
    methods: {
        onPaginationChangePage(page) {
            this.$emit('pagination-change-page', page);
            // The new page loads while the viewport stays at the bottom of the
            // list, so it looks like nothing happened. Return to the top.
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },
        // Derived rather than read off the payload: the renderless component
        // exposes total and perPage on every driver, but lastPage only when
        // the API returns a full meta block, and several endpoints here do not.
        lastPage(computed) {
            const perPage = Number(computed.perPage) || 0;
            const total = Number(computed.total) || 0;

            return perPage > 0 ? Math.max(1, Math.ceil(total / perPage)) : 1;
        }
    }
}
</script>
