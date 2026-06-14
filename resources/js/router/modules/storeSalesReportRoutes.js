const StoreSalesReportComponent = () => import("../../components/admin/storeSalesReport/StoreSalesReportComponent");
const StoreSalesReportListComponent = () => import("../../components/admin/storeSalesReport/StoreSalesReportListComponent");

export default [
    {
        path: "/admin/store-sales-report",
        component: StoreSalesReportComponent,
        name: "admin.store-sales-report",
        redirect: { name: "admin.store-sales-report.list" },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "store-sales-report",
            breadcrumb: "store_sales_report",
        },
        children: [
            {
                path: "",
                component: StoreSalesReportListComponent,
                name: "admin.store-sales-report.list",
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: "store-sales-report",
                    breadcrumb: "",
                },
            },
        ],
    },
];
