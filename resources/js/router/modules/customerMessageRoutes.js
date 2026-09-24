const CustomerMessageComponent = () => import("../../components/admin/customerMessage/CustomerMessageComponent");

export default [
    {
        // Guarded by the customers permission, matching the controller: whoever
        // may see the customer list may message it.
        path: "/admin/customer-message",
        component: CustomerMessageComponent,
        name: "admin.customerMessage",
        meta: {
            isFrontend: false,
            auth: true,
            permissionUrl: "customers",
            breadcrumb: "customer_message",
        },
    },
];
