<template>
    <div>
        <div class="db-field-control flex flex-wrap items-center gap-2 min-h-[46px] h-auto py-2"
            :class="invalid ? 'invalid' : ''" @click="focusInput">
            <span v-for="(keyword, index) in keywords" :key="index"
                class="inline-flex items-center gap-1.5 rounded-full bg-primary-slate px-3 py-1 text-xs font-medium text-primary">
                {{ keyword }}
                <button type="button" class="text-primary/60 hover:text-red-500" @click.stop="remove(index)"
                    :aria-label="'remove ' + keyword">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </span>

            <input ref="entry" v-model="draft" type="text"
                class="flex-1 min-w-[140px] border-0 bg-transparent p-0 text-sm outline-none"
                :placeholder="keywords.length === 0 ? placeholder : ''" @keydown.enter.prevent="commit"
                @keydown.,.prevent="commit" @keydown.tab="commit" @keydown.delete="backspace" @blur="commit" />
        </div>

        <div class="mt-1 flex items-center justify-between">
            <small class="text-slate-500">{{ hint }}</small>
            <small :class="tooMany ? 'db-field-alert' : 'text-slate-500'">{{ keywords.length }} / {{ max }}</small>
        </div>
    </div>
</template>

<script>
/**
 * Chip editor over a plain comma-separated string.
 *
 * Deliberately NOT the JSON array format the product SEO tab uses: the value
 * here goes straight into <meta name="keywords" content="..."> server-side, and
 * a JSON array would render as ["a","b"] in the tag. Comma-separated is the
 * format the meta tag actually wants, so it is what gets stored.
 */
export default {
    name: "MetaKeywordsInput",
    props: {
        modelValue: { type: String, default: "" },
        invalid: { type: Boolean, default: false },
        max: { type: Number, default: 25 },
        placeholder: { type: String, default: "skin care bangladesh, acne treatment bd, …" },
    },
    emits: ["update:modelValue"],
    data() {
        return {
            draft: "",
        };
    },
    computed: {
        keywords: function () {
            return (this.modelValue || "")
                .split(",")
                .map((item) => item.trim())
                .filter(Boolean);
        },
        tooMany: function () {
            return this.keywords.length > this.max;
        },
        hint: function () {
            return this.$t("message.meta_keywords_chip_hint");
        },
    },
    methods: {
        focusInput: function () {
            this.$refs.entry?.focus();
        },
        emit: function (list) {
            this.$emit("update:modelValue", list.join(", "));
        },
        commit: function () {
            const value = this.draft.trim().replace(/,+$/, "");

            if (value === "") {
                this.draft = "";
                return;
            }

            // Case-insensitive dedupe: "Acne" and "acne" are one keyword to a
            // search engine, and shipping both just dilutes the tag.
            const exists = this.keywords.some((item) => item.toLowerCase() === value.toLowerCase());

            if (!exists) {
                this.emit(this.keywords.concat(value));
            }

            this.draft = "";
        },
        remove: function (index) {
            const next = this.keywords.slice();
            next.splice(index, 1);
            this.emit(next);
        },
        backspace: function (event) {
            // Backspace on an empty box removes the last chip, which is the
            // behaviour every tag input has.
            if (this.draft === "" && this.keywords.length > 0) {
                event.preventDefault();
                this.remove(this.keywords.length - 1);
            }
        },
    },
};
</script>
