<template>
    <div v-if="loading" class="mb-4">
        <!-- Skeleton? -->
    </div>
    <div
        v-else-if="buttons.length > 0"
        class="w-full bg-cover bg-center bg-no-repeat mb-4 py-4"
        :style="backgroundStyle"
    >
        <div class="container">
            <div class="grid grid-cols-3 gap-3">
                <router-link
                    v-for="button in buttons"
                    :key="button.id"
                    :to="button.url"
                    class="bg-white rounded-lg shadow px-2 py-2 text-center text-xs font-semibold text-gray-800 hover:text-primary transition"
                >
                    {{ button.name }}
                </router-link>
            </div>
        </div>
    </div>
</template>

<script>
import axios from "axios";

export default {
    name: "MobileSectionComponent",
    data() {
        return {
            loading: true,
            buttons: [],
            background: null,
        };
    },
    computed: {
        backgroundStyle() {
            return this.background
                ? { backgroundImage: `url(${this.background})` }
                : { backgroundColor: "#f3f4f6" };
        },
    },
    mounted() {
        this.fetchData();
    },
    methods: {
        fetchData() {
            axios
                .get("frontend/mobile-section")
                .then((res) => {
                    this.buttons = res.data.data.buttons;
                    this.background = res.data.data.background;
                    this.loading = false;
                })
                .catch((err) => {
                    this.loading = false;
                });
        },
    },
};
</script>
