<template>
    <section v-if="faq || facts.length" class="mt-8 space-y-6">
        <div v-if="faq">
            <h2 class="text-xl font-bold mb-2">{{ faq.question }}</h2>
            <p class="text-description leading-relaxed">{{ faq.answer }}</p>
        </div>

        <div v-if="facts.length">
            <h2 class="text-xl font-bold mb-3">Product details</h2>
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6">
                <div v-for="fact in facts" :key="fact.key" class="flex gap-3 py-2 border-b border-gray-100">
                    <dt class="text-text min-w-[110px]">{{ fact.key }}</dt>
                    <dd class="font-medium">{{ fact.value }}</dd>
                </div>
            </dl>
        </div>
    </section>
</template>

<script>
export default {
    name: "ProductSeoBlocks",
    props: {
        description: { type: String, default: "" },
    },
    computed: {
        blocks() {
            const normalized = this.description
                .replace(/<\/p>\s*<p>\s*(?:<br\s*\/?>|&nbsp;|\s)*\s*<\/p>\s*<p>/gi, "</p>\n\n<p>")
                .replace(/<\/p>/gi, "\n")
                .replace(/<br\s*\/?>/gi, "\n");
            const container = document.createElement("div");
            container.innerHTML = normalized;

            return (container.textContent || "")
                .split(/\r?\n\s*\r?\n/)
                .map((block) => block.trim())
                .filter(Boolean);
        },
        faq() {
            if (!this.blocks[1]) {
                return null;
            }

            const lines = this.blocks[1].split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
            const question = (lines.shift() || "").replace(/^Q:\s*/i, "");
            const answer = lines.join(" ");

            return question && answer ? { question, answer } : null;
        },
        facts() {
            if (!this.blocks[2]) {
                return [];
            }

            return this.blocks[2]
                .replace(/^Product details\s*[—-]\s*/i, "")
                .split(/[|·]/)
                .map((part) => {
                    const [key, ...value] = part.split(":");
                    return { key: key.trim(), value: value.join(":").trim() };
                })
                .filter((fact) => fact.key && fact.value);
        },
    },
};
</script>
