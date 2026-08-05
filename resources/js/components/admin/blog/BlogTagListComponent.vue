<template>
    <LoadingComponent :props="loading" />

    <div class="db-card db-tab-div active">
        <div class="db-card-header border-none">
            <h3 class="db-card-title">{{ $t("menu.blog_tags") }}</h3>
            <div class="db-card-filter">
                <TableLimitComponent :method="list" :search="props.search" :page="paginationPage" />
                <BlogTagCreateComponent :props="props" />
            </div>
        </div>

        <p class="px-4 pb-3 text-sm text-slate-500">{{ $t("message.blog_tags_intro") }}</p>

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
                <tbody class="db-table-body" v-if="tags.length > 0">
                    <tr class="db-table-body-tr" v-for="tag in tags" :key="tag.id">
                        <td class="db-table-body-td">{{ tag.name }}</td>
                        <td class="db-table-body-td text-slate-500">/blog/tag/{{ tag.slug }}</td>
                        <td class="db-table-body-td">{{ tag.posts_count }}</td>
                        <td class="db-table-body-td">{{ tag.priority }}</td>
                        <td class="db-table-body-td">
                            <span :class="statusClass(tag.status)">
                                {{ enums.statusEnumArray[tag.status] }}
                            </span>
                        </td>
                        <td class="db-table-body-td">
                            <div class="flex justify-start items-center gap-1.5">
                                <SmModalEditComponent @click="edit(tag)" />
                                <SmDeleteComponent @click="destroy(tag.id)" />
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

        <div class="flex items-center justify-between border-t border-gray-200 bg-white px-4 py-6" v-if="tags.length > 0">
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
import BlogTagCreateComponent from "./BlogTagCreateComponent";
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
    name: "BlogTagListComponent",
    components: {
        TableLimitComponent,
        PaginationSMBox,
        PaginationBox,
        PaginationTextComponent,
        BlogTagCreateComponent,
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
                    order_column: "priority",
                    order_type: "asc",
                },
            },
            ENV: ENV,
        };
    },
    mounted() {
        this.list();
    },
    computed: {
        tags: function () {
            return this.$store.getters["blogTag/lists"];
        },
        pagination: function () {
            return this.$store.getters["blogTag/pagination"];
        },
        paginationPage: function () {
            return this.$store.getters["blogTag/page"];
        },
    },
    methods: {
        statusClass: function (status) {
            return appService.statusClass(status);
        },
        list: function (page = 1) {
            this.loading.isActive = true;
            this.props.search.page = page;
            this.$store.dispatch("blogTag/lists", this.props.search).then(() => {
                this.loading.isActive = false;
            }).catch(() => {
                this.loading.isActive = false;
            });
        },
        edit: function (tag) {
            appService.modalShow();
            this.$store.dispatch("blogTag/edit", tag.id);
            this.props.form = {
                name: tag.name,
                description: tag.description,
                meta_title: tag.meta_title,
                meta_description: tag.meta_description,
                meta_keywords: tag.meta_keywords,
                priority: tag.priority,
                status: tag.status,
            };
        },
        destroy: function (id) {
            appService.destroyConfirmation().then(() => {
                this.loading.isActive = true;
                this.$store.dispatch("blogTag/destroy", {
                    id: id,
                    search: this.props.search,
                }).then(() => {
                    this.loading.isActive = false;
                    alertService.successFlip(null, this.$t("menu.blog_tags"));
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
