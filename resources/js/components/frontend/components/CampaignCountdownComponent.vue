<template>
    <div v-if="remaining > 0" class="flex items-center gap-2 sm:gap-3">
        <span class="text-sm font-medium capitalize text-text hidden sm:inline">
            {{ $t('label.ends_in') }}
        </span>
        <div class="flex items-center gap-1.5 sm:gap-2">
            <div v-for="unit in units" :key="unit.key" class="flex items-center gap-1.5 sm:gap-2">
                <div
                    class="flex flex-col items-center justify-center min-w-[44px] sm:min-w-[52px] py-1.5 rounded-lg bg-primary text-white">
                    <span class="text-base sm:text-xl font-bold leading-none tabular-nums">{{ unit.value }}</span>
                    <span class="text-[10px] sm:text-xs font-medium capitalize opacity-90">{{ unit.label }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
export default {
    name: "CampaignCountdownComponent",
    props: {
        /**
         * Seconds left, computed on the server. Preferred over parsing
         * `endsAt` in the browser because a customer with a wrong device clock
         * would otherwise see a countdown that disagrees with the sale.
         */
        endsInSeconds: {
            type: Number,
            default: 0
        }
    },
    emits: ["expired"],
    data() {
        return {
            remaining: 0,
            timer: null,
        }
    },
    computed: {
        units: function () {
            const total = Math.max(0, this.remaining);
            const days = Math.floor(total / 86400);
            const hours = Math.floor((total % 86400) / 3600);
            const minutes = Math.floor((total % 3600) / 60);
            const seconds = total % 60;

            const units = [
                { key: 'hours', value: this.pad(hours), label: this.$t('label.hours') },
                { key: 'minutes', value: this.pad(minutes), label: this.$t('label.minutes') },
                { key: 'seconds', value: this.pad(seconds), label: this.$t('label.seconds') },
            ];

            // Days only when there are any — a permanent "00 days" on a sale
            // ending this afternoon reads as broken.
            if (days > 0) {
                units.unshift({ key: 'days', value: this.pad(days), label: this.$t('label.days') });
            }

            return units;
        }
    },
    watch: {
        // The page reuses this component across campaigns (flash and
        // clearance), so a new value has to restart the tick rather than let
        // the previous campaign's countdown keep running.
        endsInSeconds: function () {
            this.start();
        }
    },
    mounted() {
        this.start();
    },
    beforeUnmount() {
        this.stop();
    },
    methods: {
        pad: function (value) {
            return value < 10 ? '0' + value : String(value);
        },
        start: function () {
            this.stop();
            this.remaining = Math.max(0, Number(this.endsInSeconds) || 0);

            if (this.remaining <= 0) {
                return;
            }

            this.timer = window.setInterval(() => {
                this.remaining -= 1;

                if (this.remaining <= 0) {
                    this.stop();
                    // Lets the page hide the campaign the moment it ends,
                    // instead of leaving special prices on screen until the
                    // customer navigates. The server rejects the expired
                    // campaign independently — this is the visible half.
                    this.$emit("expired");
                }
            }, 1000);
        },
        stop: function () {
            if (this.timer !== null) {
                window.clearInterval(this.timer);
                this.timer = null;
            }
        }
    }
}
</script>
