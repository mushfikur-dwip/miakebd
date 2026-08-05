const BlogComponent = () => import("../../components/admin/blog/BlogComponent");
const BlogListComponent = () => import("../../components/admin/blog/BlogListComponent");
const BlogCreateComponent = () => import("../../components/admin/blog/BlogCreateComponent");
const BlogCategoryComponent = () => import("../../components/admin/blog/BlogCategoryComponent");
const BlogCategoryListComponent = () => import("../../components/admin/blog/BlogCategoryListComponent");
const BlogTagComponent = () => import("../../components/admin/blog/BlogTagComponent");
const BlogTagListComponent = () => import("../../components/admin/blog/BlogTagListComponent");

export default [
    {
        path: "/admin/blog",
        component: BlogComponent,
        name: "admin.blog",
        redirect: { name: "admin.blog.list" },
        meta: {
            isFrontend: false,
            auth: true,
            // Must match the `url` column of the `blog` permission row, which
            // is how the sidebar and the route guard decide visibility.
            permissionUrl: "blog",
            breadcrumb: "blog_posts",
        },
        children: [
            {
                path: "",
                component: BlogListComponent,
                name: "admin.blog.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "blog",
                    breadcrumb: "",
                },
            },
            {
                path: "create",
                component: BlogCreateComponent,
                name: "admin.blog.create",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "blog",
                    breadcrumb: "add_post",
                },
            },
            {
                // Same component as create — it switches on route.params.id.
                path: "edit/:id",
                component: BlogCreateComponent,
                name: "admin.blog.edit",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "blog",
                    breadcrumb: "edit_post",
                },
            },
        ],
    },
    {
        path: "/admin/blog-categories",
        component: BlogCategoryComponent,
        name: "admin.blog-categories",
        redirect: { name: "admin.blog-categories.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "blog",
            breadcrumb: "blog_categories",
        },
        children: [
            {
                path: "",
                component: BlogCategoryListComponent,
                name: "admin.blog-categories.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "blog",
                    breadcrumb: "",
                },
            },
        ],
    },
    {
        path: "/admin/blog-tags",
        component: BlogTagComponent,
        name: "admin.blog-tags",
        redirect: { name: "admin.blog-tags.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "blog",
            breadcrumb: "blog_tags",
        },
        children: [
            {
                path: "",
                component: BlogTagListComponent,
                name: "admin.blog-tags.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "blog",
                    breadcrumb: "",
                },
            },
        ],
    },
];
