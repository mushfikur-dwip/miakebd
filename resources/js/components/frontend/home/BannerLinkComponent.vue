<template>
    <router-link v-if="target.type === 'internal'" :to="target.path" class="block w-full">
        <slot />
    </router-link>

    <a v-else-if="target.type === 'external'" :href="target.href" target="_blank" rel="noopener noreferrer"
        class="block w-full">
        <slot />
    </a>

    <!-- No link set: still render the image, just not clickable. A banner with
         a dead <a> around it looks identical and wastes the tap. -->
    <div v-else class="block w-full">
        <slot />
    </div>
</template>

<script>
import linkService from "../../../services/linkService";

export default {
    name: "BannerLinkComponent",
    props: {
        link: {
            type: String,
            default: ""
        }
    },
    computed: {
        target: function () {
            return linkService.resolve(this.link);
        }
    }
}
</script>
