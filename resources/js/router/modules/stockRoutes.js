const StockComponent = () => import("../../components/admin/stock/StockComponent");
const StockListComponent = () => import("../../components/admin/stock/StockListComponent");
const StockAdjustmentListComponent = () => import("../../components/admin/stock/StockAdjustmentListComponent");
const StockAdjustmentCreateComponent = () => import("../../components/admin/stock/StockAdjustmentCreateComponent");

export default [
    {
        path: '/admin/stock',
        component: StockComponent,
        name: 'admin.stock',
        redirect: { name: 'admin.stock.list' },
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: 'stock',
            breadcrumb: 'stock'
        },
        children: [
            {
                path: '',
                component: StockListComponent,
                name: 'admin.stock.list',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'stock',
                    breadcrumb: ''
                },
            },
            // Adjustments live under the stock permission rather than one of
            // their own, so no permission row has to be seeded for them.
            {
                path: 'adjustment',
                component: StockAdjustmentListComponent,
                name: 'admin.stock.adjustment.list',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'stock',
                    breadcrumb: 'stock_adjustment'
                },
            },
            {
                path: 'adjustment/create',
                component: StockAdjustmentCreateComponent,
                name: 'admin.stock.adjustment.create',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'stock',
                    breadcrumb: 'stock_adjustment'
                },
            }
        ]
    }
]
