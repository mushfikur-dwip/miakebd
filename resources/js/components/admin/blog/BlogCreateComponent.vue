<template>
    <LoadingComponent :props="loading" />

    <form @submit.prevent="save">
        <div class="grid grid-cols-12 gap-4">
            <!-- ------------------------------- main column -->
            <div class="col-span-12 lg:col-span-8 flex flex-col gap-4">
                <div class="db-card">
                    <div class="db-card-header border-none">
                        <h3 class="db-card-title">
                            {{ isEditing ? $t("button.edit_post") : $t("button.add_post") }}
                        </h3>
                    </div>

                    <div class="p-4">
                        <div class="form-row">
                            <div class="form-col-12">
                                <label for="title" class="db-field-title required">{{ $t("label.title") }}</label>
                                <input v-model="form.title" :class="errors.title ? 'invalid' : ''" type="text" id="title"
                                    class="db-field-control" />
                                <small class="db-field-alert" v-if="errors.title">{{ errors.title[0] }}</small>
                            </div>

                            <div class="form-col-12">
                                <label for="slug" class="db-field-title">{{ $t("label.slug") }}</label>
                                <div class="flex items-center gap-2">
                                    <span class="text-sm text-slate-400 whitespace-nowrap">/blog/</span>
                                    <input v-model="form.slug" :class="errors.slug ? 'invalid' : ''" type="text" id="slug"
                                        class="db-field-control" :placeholder="$t('label.slug_auto_placeholder')" />
                                </div>
                                <!-- Changing a live post's slug throws away every
                                     ranking signal the old URL earned, so the
                                     warning is shown rather than the field hidden. -->
                                <small class="text-slate-500" v-if="!errors.slug">
                                    {{ isEditing ? $t("message.slug_edit_warning") : $t("message.slug_auto_hint") }}
                                </small>
                                <small class="db-field-alert" v-if="errors.slug">{{ errors.slug[0] }}</small>
                            </div>

                            <div class="form-col-12">
                                <label for="excerpt" class="db-field-title">{{ $t("label.excerpt") }}</label>
                                <textarea v-model="form.excerpt" :class="errors.excerpt ? 'invalid' : ''" id="excerpt"
                                    rows="3" class="db-field-control" maxlength="500"
                                    :placeholder="$t('label.excerpt_placeholder')"></textarea>
                                <small class="text-slate-500">{{ (form.excerpt || "").length }}/500</small>
                                <small class="db-field-alert" v-if="errors.excerpt">{{ errors.excerpt[0] }}</small>
                            </div>

                            <div class="form-col-12">
                                <label for="content" class="db-field-title required">{{ $t("label.content") }}</label>
                                <quill-editor v-model:value="form.content" :class="errors.content ? 'invalid' : ''"
                                    id="content" class="!h-96 textarea-border-radius ql-container ql-snow" />
                                <small class="db-field-alert" v-if="errors.content">{{ errors.content[0] }}</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ------------------------------- SEO -->
                <div class="db-card">
                    <div class="db-card-header border-none">
                        <h3 class="db-card-title">{{ $t("label.seo") }}</h3>
                    </div>

                    <div class="p-4">
                        <!-- What the post will actually look like in Google.
                             Editing meta fields blind is how titles end up
                             truncated at 65 chars. -->
                        <div class="mb-5 p-4 rounded-lg border border-slate-200 bg-slate-50">
                            <p class="text-xs uppercase tracking-wide text-slate-400 mb-2">
                                {{ $t("label.search_preview") }}
                            </p>
                            <p class="text-sm text-emerald-700 truncate">{{ previewUrl }}</p>
                            <p class="text-lg text-blue-700 leading-snug">{{ previewTitle }}</p>
                            <p class="text-sm text-slate-600">{{ previewDescription }}</p>
                        </div>

                        <div class="form-row">
                            <div class="form-col-12">
                                <label for="meta_title" class="db-field-title">{{ $t("label.meta_title") }}</label>
                                <input v-model="form.meta_title" :class="errors.meta_title ? 'invalid' : ''" type="text"
                                    id="meta_title" class="db-field-control" :placeholder="form.title" />
                                <small :class="metaTitleLength > 65 ? 'db-field-alert' : 'text-slate-500'">
                                    {{ metaTitleLength }}/65 — {{ $t("message.meta_title_hint") }}
                                </small>
                                <small class="db-field-alert" v-if="errors.meta_title">{{ errors.meta_title[0] }}</small>
                            </div>

                            <div class="form-col-12">
                                <label for="meta_description" class="db-field-title">
                                    {{ $t("label.meta_description") }}
                                </label>
                                <textarea v-model="form.meta_description"
                                    :class="errors.meta_description ? 'invalid' : ''" id="meta_description" rows="3"
                                    class="db-field-control" maxlength="500"
                                    :placeholder="$t('label.meta_description_placeholder')"></textarea>
                                <small :class="metaDescriptionLength > 158 ? 'db-field-alert' : 'text-slate-500'">
                                    {{ metaDescriptionLength }}/158 — {{ $t("message.meta_description_hint") }}
                                </small>
                                <small class="db-field-alert" v-if="errors.meta_description">
                                    {{ errors.meta_description[0] }}
                                </small>
                            </div>

                            <div class="form-col-12">
                                <label for="meta_keywords" class="db-field-title">{{ $t("label.meta_keywords") }}</label>
                                <MetaKeywordsInput v-model="form.meta_keywords" :invalid="!!errors.meta_keywords" />
                                <small class="db-field-alert" v-if="errors.meta_keywords">
                                    {{ errors.meta_keywords[0] }}
                                </small>
                            </div>

                            <div class="form-col-12 sm:form-col-6">
                                <label for="canonical_url" class="db-field-title">{{ $t("label.canonical_url") }}</label>
                                <input v-model="form.canonical_url" :class="errors.canonical_url ? 'invalid' : ''"
                                    type="text" id="canonical_url" class="db-field-control"
                                    :placeholder="$t('label.canonical_url_placeholder')" />
                                <small class="text-slate-500">{{ $t("message.canonical_hint") }}</small>
                                <small class="db-field-alert" v-if="errors.canonical_url">
                                    {{ errors.canonical_url[0] }}
                                </small>
                            </div>

                            <div class="form-col-12 sm:form-col-6">
                                <label for="robots" class="db-field-title">{{ $t("label.robots") }}</label>
                                <vue-select class="db-field-control f-b-custom-select" id="robots" v-model="form.robots"
                                    :options="robotsOptions" label-by="name" value-by="id" :closeOnSelect="true"
                                    :clearOnClose="true" placeholder="index, follow" />
                                <small class="text-slate-500">{{ $t("message.robots_hint") }}</small>
                                <small class="db-field-alert" v-if="errors.robots">{{ errors.robots[0] }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ------------------------------- sidebar -->
            <div class="col-span-12 lg:col-span-4 flex flex-col gap-4">
                <div class="db-card">
                    <div class="db-card-header border-none">
                        <h3 class="db-card-title">{{ $t("label.publish") }}</h3>
                    </div>
                    <div class="p-4">
                        <div class="form-row">
                            <div class="form-col-12">
                                <label class="db-field-title required">{{ $t("label.status") }}</label>
                                <div class="db-field-radio-group">
                                    <div class="db-field-radio">
                                        <div class="custom-radio">
                                            <input :value="enums.statusEnum.ACTIVE" v-model="form.status" id="active"
                                                type="radio" class="custom-radio-field" />
                                            <span class="custom-radio-span"></span>
                                        </div>
                                        <label for="active" class="db-field-label">{{ $t("label.published") }}</label>
                                    </div>
                                    <div class="db-field-radio">
                                        <div class="custom-radio">
                                            <input :value="enums.statusEnum.INACTIVE" v-model="form.status"
                                                id="inactive" type="radio" class="custom-radio-field" />
                                            <span class="custom-radio-span"></span>
                                        </div>
                                        <label for="inactive" class="db-field-label">{{ $t("label.draft") }}</label>
                                    </div>
                                </div>
                                <small class="db-field-alert" v-if="errors.status">{{ errors.status[0] }}</small>
                            </div>

                            <div class="form-col-12">
                                <label for="published_at" class="db-field-title">{{ $t("label.published_at") }}</label>
                                <input v-model="form.published_at" type="datetime-local" id="published_at"
                                    class="db-field-control" />
                                <small class="text-slate-500">{{ $t("message.published_at_hint") }}</small>
                                <small class="db-field-alert" v-if="errors.published_at">
                                    {{ errors.published_at[0] }}
                                </small>
                            </div>

                            <div class="form-col-12">
                                <label class="db-field-title flex items-center gap-2">
                                    <input type="checkbox" v-model="form.is_featured" class="w-4 h-4" />
                                    <span>{{ $t("label.featured_post") }}</span>
                                </label>
                                <small class="text-slate-500">{{ $t("message.featured_hint") }}</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="db-card">
                    <div class="db-card-header border-none">
                        <h3 class="db-card-title">{{ $t("label.organize") }}</h3>
                    </div>
                    <div class="p-4">
                        <div class="form-row">
                            <div class="form-col-12">
                                <label for="blog_category_id" class="db-field-title">{{ $t("label.category") }}</label>
                                <vue-select class="db-field-control f-b-custom-select" id="blog_category_id"
                                    :class="errors.blog_category_id ? 'invalid' : ''" v-model="form.blog_category_id"
                                    :options="categories" label-by="name" value-by="id" :closeOnSelect="true"
                                    :searchable="true" :clearOnClose="true" placeholder="--" search-placeholder="--" />
                                <small class="db-field-alert" v-if="errors.blog_category_id">
                                    {{ errors.blog_category_id[0] }}
                                </small>
                            </div>

                            <div class="form-col-12">
                                <label for="tags" class="db-field-title">{{ $t("label.concerns") }}</label>
                                <vue-select class="db-field-control f-b-custom-select" id="tags" v-model="form.tags"
                                    :options="allTags" label-by="name" value-by="id" :closeOnSelect="false"
                                    :searchable="true" :clearOnClose="true" multiple
                                    :placeholder="$t('label.concerns_placeholder')" search-placeholder="--" />
                                <small class="text-slate-500">{{ $t("message.concerns_hint") }}</small>
                                <small class="db-field-alert" v-if="errors.tags">{{ errors.tags[0] }}</small>
                            </div>

                            <div class="form-col-12">
                                <label for="author_name" class="db-field-title">{{ $t("label.author") }}</label>
                                <input v-model="form.author_name" type="text" id="author_name" class="db-field-control"
                                    :placeholder="$t('label.author_placeholder')" />
                                <small class="db-field-alert" v-if="errors.author_name">
                                    {{ errors.author_name[0] }}
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="db-card">
                    <div class="db-card-header border-none">
                        <h3 class="db-card-title">{{ $t("label.cover_image") }}</h3>
                    </div>
                    <div class="p-4">
                        <div v-if="coverPreview" class="mb-3">
                            <img :src="coverPreview" alt="cover" class="w-full rounded-lg object-cover" />
                        </div>
                        <input @change="changeImage" :class="errors.image ? 'invalid' : ''" id="image" type="file"
                            class="db-field-control" ref="imageProperty"
                            accept="image/png, image/jpeg, image/jpg, image/webp" />
                        <small class="text-slate-500">{{ $t("message.cover_image_hint") }}</small>
                        <small class="db-field-alert" v-if="errors.image">{{ errors.image[0] }}</small>
                    </div>
                </div>

                <div class="db-card">
                    <div class="p-4 flex items-center gap-2">
                        <button type="submit" class="db-btn py-2 text-white bg-primary">
                            <i class="lab lab-fill-save"></i>
                            <span>{{ $t("button.save") }}</span>
                        </button>
                        <router-link :to="{ name: 'admin.blog.list' }" class="db-btn py-2 text-slate-600 bg-slate-100">
                            <i class="lab lab-fill-close-circle"></i>
                            <span>{{ $t("button.close") }}</span>
                        </router-link>
                    </div>
                </div>
            </div>
        </div>
    </form>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import MetaKeywordsInput from "./MetaKeywordsInput";
import statusEnum from "../../../enums/modules/statusEnum";
import alertService from "../../../services/alertService";
import { quillEditor } from "vue3-quill";

export default {
    name: "BlogCreateComponent",
    components: { LoadingComponent, quillEditor, MetaKeywordsInput },
    data() {
        return {
            loading: { isActive: false },
            enums: { statusEnum: statusEnum },
            image: "",
            existingCover: "",
            errors: {},
            form: {
                title: "",
                slug: "",
                blog_category_id: null,
                tags: [],
                excerpt: "",
                content: "",
                author_name: "",
                is_featured: false,
                published_at: "",
                meta_title: "",
                meta_description: "",
                meta_keywords: "",
                canonical_url: "",
                robots: "",
                status: statusEnum.ACTIVE,
            },
        };
    },
    mounted() {
        this.$store.dispatch("blogCategory/lists", { paginate: 0 });
        this.$store.dispatch("blogTag/lists", { paginate: 0 });

        if (this.isEditing) {
            this.loadPost();
        }
    },
    computed: {
        categories: function () {
            return this.$store.getters["blogCategory/lists"];
        },
        allTags: function () {
            return this.$store.getters["blogTag/lists"];
        },
        isEditing: function () {
            return !!this.$route.params.id;
        },
        robotsOptions: function () {
            return [
                { id: "index, follow, max-image-preview:large, max-snippet:-1", name: this.$t("label.robots_index") },
                { id: "noindex, follow", name: this.$t("label.robots_noindex") },
            ];
        },
        metaTitleLength: function () {
            return (this.form.meta_title || this.form.title || "").length;
        },
        metaDescriptionLength: function () {
            return (this.form.meta_description || "").length;
        },
        previewTitle: function () {
            const title = this.form.meta_title || this.form.title || this.$t("label.title");
            return title.length > 65 ? title.slice(0, 65) + "…" : title;
        },
        previewDescription: function () {
            const text =
                this.form.meta_description ||
                this.form.excerpt ||
                // Strip the editor's markup so the preview reflects the text
                // Google would actually show, not raw <p> tags.
                (this.form.content || "").replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
            return text.length > 158 ? text.slice(0, 158) + "…" : text;
        },
        previewUrl: function () {
            const slug = this.form.slug || this.slugify(this.form.title) || "post-url";
            return `${window.location.origin}/blog/${slug}`;
        },
        coverPreview: function () {
            return this.image ? URL.createObjectURL(this.image) : this.existingCover;
        },
    },
    methods: {
        slugify: function (value) {
            return (value || "")
                .toString()
                .toLowerCase()
                .trim()
                .replace(/[^\w\s-]/g, "")
                .replace(/[\s_-]+/g, "-")
                .replace(/^-+|-+$/g, "");
        },
        changeImage: function (e) {
            this.image = e.target.files[0];
        },
        loadPost: function () {
            this.loading.isActive = true;
            this.$store.dispatch("blogPost/show", this.$route.params.id).then((res) => {
                const post = res.data.data;
                this.form = {
                    title: post.title,
                    slug: post.slug,
                    blog_category_id: post.blog_category_id,
                    tags: post.tag_ids || [],
                    excerpt: post.excerpt,
                    content: post.content,
                    author_name: post.author_name,
                    is_featured: post.is_featured,
                    published_at: post.published_at,
                    meta_title: post.meta_title,
                    meta_description: post.meta_description,
                    meta_keywords: post.meta_keywords,
                    canonical_url: post.canonical_url,
                    robots: post.robots,
                    status: post.status,
                };
                this.existingCover = post.cover;
                this.$store.dispatch("blogPost/edit", post.id);
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        save: function () {
            try {
                const fd = new FormData();
                fd.append("title", this.form.title);
                fd.append("slug", this.form.slug || "");
                fd.append("blog_category_id", this.form.blog_category_id === null ? "" : this.form.blog_category_id);
                // Comma-separated ids. Always sent, even when empty, so
                // clearing every concern actually detaches them (syncTags
                // treats an absent key as "leave alone").
                fd.append("tags", (this.form.tags || []).join(","));
                fd.append("excerpt", this.form.excerpt || "");
                fd.append("content", this.form.content || "");
                fd.append("author_name", this.form.author_name || "");
                // FormData stringifies booleans to "true"/"false", which
                // Laravel's boolean rule rejects. 1/0 validates cleanly.
                fd.append("is_featured", this.form.is_featured ? 1 : 0);
                fd.append("published_at", this.form.published_at || "");
                fd.append("meta_title", this.form.meta_title || "");
                fd.append("meta_description", this.form.meta_description || "");
                fd.append("meta_keywords", this.form.meta_keywords || "");
                fd.append("canonical_url", this.form.canonical_url || "");
                fd.append("robots", this.form.robots || "");
                fd.append("status", this.form.status);

                if (this.image) {
                    fd.append("image", this.image);
                }

                this.loading.isActive = true;
                this.$store.dispatch("blogPost/save", { form: fd, search: {} }).then(() => {
                    this.loading.isActive = false;
                    alertService.successFlip(this.isEditing ? 1 : 0, this.$t("menu.blog_posts"));
                    this.errors = {};
                    this.$router.push({ name: "admin.blog.list" });
                }).catch((err) => {
                    this.loading.isActive = false;
                    this.errors = err.response?.data?.errors || {};
                    if (err.response?.data?.message && !err.response?.data?.errors) {
                        alertService.error(err.response.data.message);
                    }
                });
            } catch (err) {
                this.loading.isActive = false;
                alertService.error(err);
            }
        },
    },
    unmounted() {
        // Without this the store keeps isEditing=true, so the next "Add post"
        // would PATCH the article that was just edited instead of creating one.
        this.$store.dispatch("blogPost/reset");
    },
};
</script>
