<template>
    <LoadingComponent :props="loading" />

    <div class="db-card db-tab-div active">
        <div class="db-card-header border-none">
            <h3 class="db-card-title">{{ $t("menu.blog_categories") }}</h3>
            <div class="db-card-filter">
                <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                <BlogCategoryCreateComponent :props="props" />
            </div>
        </div>

        <div class="db-table-responsive">
            <table class="db-table stripe">
                <thead class="db-table-head">
                    <tr class="db-table-head-tr">
                        <th class="db-table-head-th">{{ $t("label.name") }}</th>
                        <th class="db-table-head-th">{{ $t("label.slug") }}</th>
                        <th class="db-table-head-th">{{ $t("label.posts") }}</th>
                        <th class="db-table-head-th">{{ $t("label.priority") }}</th>
                        <th class="db-table-head-th">{{ $t("label.status") }}</th>
                        <th class="db-table-head-th">{{ $t("label.action") }}</th>
                    </tr>
                </thead>
                <tbody class="db-table-body" v-if="categories.length > 0">
                    <tr class="db-table-body-tr" v-for="category in categories" :key="category.id">
                        <td class="db-table-body-td">{{ category.name }}</td>
                        <td class="db-table-body-td text-slate-500">/blog/category/{{ category.slug }}</td>
                        <td class="db-table-body-td">{{ category.posts_count }}</td>
                        <td class="db-table-body-td">{{ category.priority }}</td>
                        <td class="db-table-body-td">
                            <span :class="statusClass(category.status)">
                                {{ enums.statusEnumArray[category.status] }}
                            </span>
                        </td>
                        <td class="db-table-body-td">
                            <div class="flex justify-start items-center gap-1.5">
                                <SmModalEditComponent @click="edit(category)" />
                                <SmDeleteComponent @click="destroy(category.id)" />
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tbody class="db-table-body" v-else>
                    <tr class="db-table-body-tr">
                        <td class="db-table-body-td text-center" colspan="6">
                            <div class="p-4">
                                <div class="max-w-[300px] mx-auto mt-2">
                                    <img class="w-full h-full"
                                        :src="ENV.API_URL + '/images/default/not-found/not_found.png'" alt="Not Found" />
                                </div>
                                <span class="d-block mt-3 text-lg">{{ $t("message.no_data_found") }}</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6"
            v-if="categories.length > 0">
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
import BlogCategoryCreateComponent from "./BlogCategoryCreateComponent";
import alertService from "../../../services/alertService";
import PaginationTextComponent from "../components/pagination/PaginationTextComponent";
import PaginationBox from "../components/pagination/PaginationBox";
import PaginationSMBox from "../components/pagination/PaginationSMBox";
import appService from "../../../services/appService";
import statusEnum from "../../../enums/modules/statusEnum";
import TableLimitComponent from "../components/TableLimitComponent";
import SmDeleteComponent from "../components/buttons/SmDeleteComponent";
import SmModalEditComponent from "../components/buttons/SmModalEditComponent";
import ENV from "../../../config/env";

export default {
    name: "BlogCategoryListComponent",
    components: {
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        BlogCategoryCreateComponent,
        LoadingComponent,
        SmDeleteComponent,
        SmModalEditComponent,
    },
    data() {
        return {
            loading: { isActive: false },
            enums: {
                statusEnum: statusEnum,
                statusEnumArray: {
                    [statusEnum.ACTIVE]: this.$t("label.active"),
                    [statusEnum.INACTIVE]: this.$t("label.inactive"),
                },
            },
            props: {
                form: {
                    name: "",
                    description: "",
                    meta_title: "",
                    meta_description: "",
                    meta_keywords: "",
                    priority: 0,
                    status: statusEnum.ACTIVE,
                },
                search: {
                    paginate: 1,
                    page: 1,
                    per_page: 10,
                    order_column: "id",
                    order_type: "desc",
                },
            },
            ENV: ENV,
        };
    },
    mounted() {
        this.list();
    },
    computed: {
        categories: function () {
            return this.$store.getters["blogCategory/lists"];
        },
        pagination: function () {
            return this.$store.getters["blogCategory/pagination"];
        },
        paginationPage: function () {
            return this.$store.getters["blogCategory/page"];
        },
    },
    methods: {
        statusClass: function (status) {
            return appService.statusClass(status);
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch("blogCategory/lists", this.props.search).then(() => {
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        edit: function (category) {
            appService.modalShow();
            this.$store.dispatch("blogCategory/edit", category.id);
            this.props.form = {
                name: category.name,
                description: category.description,
                meta_title: category.meta_title,
                meta_description: category.meta_description,
                meta_keywords: category.meta_keywords,
                priority: category.priority,
                status: category.status,
            };
        },
        destroy: function (id) {
            appService.destroyConfirmation().then(() => {
                this.loading.isActive = true;
                this.$store.dispatch("blogCategory/destroy", {
                    id: id,
                    search: this.props.search,
                }).then(() => {
                    this.loading.isActive = false;
                    alertService.successFlip(null, this.$t("menu.blog_categories"));
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
