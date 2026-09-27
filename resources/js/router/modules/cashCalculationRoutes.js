const CashCalculationComponent = () => import("../../components/admin/cashCalculation/CashCalculationComponent");

export default [
    {
        path: "/admin/cash-calculation",
        component: CashCalculationComponent,
        name: "admin.cash-calculation",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "cash-calculation",
            breadcrumb: "cash_calculation",
        },
    },
];
