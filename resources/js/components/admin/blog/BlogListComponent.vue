<template>
    <LoadingComponent :props="loading" />

    <div class="db-card db-tab-div active">
        <div class="db-card-header border-none">
            <h3 class="db-card-title">{{ $t("menu.blog_posts") }}</h3>
            <div class="db-card-filter">
                <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                <router-link :to="{ name: 'admin.blog.create' }" class="db-btn py-2 text-white bg-primary">
                    <i class="lab lab-fill-add-circle"></i>
                    <span>{{ $t("button.add_post") }}</span>
                </router-link>
            </div>
        </div>

        <div class="db-card-header !pt-0 border-none">
            <div class="db-card-filter w-full">
                <div class="form-row w-full">
                    <div class="form-col-12 sm:form-col-4">
                        <input v-model="props.search.title" @input="listWithDebounce" type="text"
                            class="db-field-control" :placeholder="$t('label.title')" />
                    </div>
                    <div class="form-col-12 sm:form-col-4">
                        <vue-select class="db-field-control f-b-custom-select" v-model="props.search.blog_category_id"
                            :options="categories" label-by="name" value-by="id" :closeOnSelect="true" :searchable="true"
                            :clearOnClose="true" :placeholder="$t('label.category')" @update:modelValue="list()" />
                    </div>
                    <div class="form-col-12 sm:form-col-4">
                        <vue-select class="db-field-control f-b-custom-select" v-model="props.search.status"
                            :options="statusOptions" label-by="name" value-by="id" :closeOnSelect="true"
                            :clearOnClose="true" :placeholder="$t('label.status')" @update:modelValue="list()" />
                    </div>
                </div>
            </div>
        </div>

        <div class="db-table-responsive">
            <table class="db-table stripe">
                <thead class="db-table-head">
                    <tr class="db-table-head-tr">
                        <th class="db-table-head-th">{{ $t("label.image") }}</th>
                        <th class="db-table-head-th">{{ $t("label.title") }}</th>
                        <th class="db-table-head-th">{{ $t("label.category") }}</th>
                        <th class="db-table-head-th">{{ $t("label.published_at") }}</th>
                        <th class="db-table-head-th">{{ $t("label.views") }}</th>
                        <th class="db-table-head-th">{{ $t("label.status") }}</th>
                        <th class="db-table-head-th">{{ $t("label.action") }}</th>
                    </tr>
                </thead>
                <tbody class="db-table-body" v-if="posts.length > 0">
                    <tr class="db-table-body-tr" v-for="post in posts" :key="post.id">
                        <td class="db-table-body-td">
                            <img v-if="post.cover" :src="post.cover" :alt="post.title"
                                class="w-16 h-12 object-cover rounded" />
                            <span v-else class="text-slate-400">—</span>
                        </td>
                        <td class="db-table-body-td">
                            <span class="font-medium">{{ textShortener(post.title, 40) }}</span>
                            <span v-if="post.is_featured"
                                class="ml-2 px-2 py-0.5 text-xs rounded bg-amber-100 text-amber-700">
                                {{ $t("label.featured") }}
                            </span>
                        </td>
                        <td class="db-table-body-td">{{ post.category_name || "—" }}</td>
                        <td class="db-table-body-td">{{ post.published_at ? post.published_at.replace("T", " ") : "—" }}</td>
                        <td class="db-table-body-td">{{ post.views }}</td>
                        <td class="db-table-body-td">
                            <span :class="statusClass(post.status)">
                                {{ enums.statusEnumArray[post.status] }}
                            </span>
                        </td>
                        <td class="db-table-body-td">
                            <div class="flex justify-start items-center gap-1.5">
                                <a :href="post.url" target="_blank" rel="noopener"
                                    class="db-table-action-btn" :title="$t('button.view')">
                                    <i class="lab lab-line-eye"></i>
                                </a>
                                <router-link :to="{ name: 'admin.blog.edit', params: { id: post.id } }"
                                    class="db-table-action-btn">
                                    <i class="lab lab-line-edit"></i>
                                </router-link>
                                <SmDeleteComponent @click="destroy(post.id)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tbody class="db-table-body" v-else>
                    <tr class="db-table-body-tr">
                        <td class="db-table-body-td text-center" colspan="7">
                            <div class="p-4">
                                <div class="max-w-[300px] mx-auto mt-2">
                                    <img class="w-full h-full" :src="ENV.API_URL + '/images/default/not-found/not_found.png'"
                                        alt="Not Found" />
                                </div>
                                <span class="d-block mt-3 text-lg">{{ $t("message.no_data_found") }}</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6" v-if="posts.length > 0">
            <PaginationSMBox :pagination="pagination" :method="list" />
            <div class="hidden sm:flex sm:flex-1 sm:items-center sm:justify-between">
                <PaginationTextComponent :props="{ page: paginationPage }" />
                <PaginationBox :pagination="pagination" :method="list" />
            </div>
        </div>
    </div>
</template>

<script>
import LoadingComponent from "../components/LoadingComponent";
import alertService from "../../../services/alertService";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import appService from "../../../services/appService";
import statusEnum from "../../../enums/modules/statusEnum";
import TableLimitComponent from "../components/TableLimitComponent";
import SmDeleteComponent from "../components/buttons/SmDeleteComponent";
import ENV from "../../../config/env";

export default {
    name: "BlogListComponent",
    components: {
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        LoadingComponent,
        SmDeleteComponent,
    },
    data() {
        return {
            loading: { isActive: false },
            debounce: null,
            enums: {
                statusEnum: statusEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive"),
                },
            },
            props: {
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: "id",
                    order_type: "desc",
                    title: "",
                    blog_category_id: null,
                    status: null,
                },
            },
            ENV: ENV,
        };
    },
    mounted() {
        this.list();
        // paginate:0 returns every category, so the filter dropdown is not
        // silently capped at the first page of them.
        this.$store.dispatch("blogCategory/lists", { paginate: 0 });
    },
    computed: {
        posts: function () {
            return this.$store.getters["blogPost/lists"];
        },
        pagination: function () {
            return this.$store.getters["blogPost/pagination"];
        },
        paginationPage: function () {
            return this.$store.getters["blogPost/page"];
        },
        categories: function () {
            return this.$store.getters["blogCategory/lists"];
        },
        statusOptions: function () {
            return [
                { id: statusEnum.ACTIVE, name: this.$t("label.active") },
                { id: statusEnum.INACTIVE, name: this.$t("label.inactive") },
            ];
        },
    },
    methods: {
        statusClass: function (status) {
            return appService.statusClass(status);
        },
        textShortener: function (text, number = 30) {
            return appService.textShortener(text, number);
        },
        // Typing a title fired one request per keystroke; 500ms collapses a
        // word into a single query.
        listWithDebounce: function () {
            clearTimeout(this.debounce);
            this.debounce = setTimeout(() => this.list(), 500);
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch("blogPost/lists", this.props.search).then(() => {
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        destroy: function (id) {
            appService.destroyConfirmation().then(() => {
                this.loading.isActive = true;
                this.$store.dispatch("blogPost/destroy", {
                    id: id,
                    search: this.props.search,
                }).then(() => {
                    this.loading.isActive = false;
                    alertService.successFlip(null, this.$t("menu.blog_posts"));
                }).catch((err) => {
                    this.loading.isActive = false;
                    alertService.error(err.response.data.message);
                });
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
    },
};
</script>
