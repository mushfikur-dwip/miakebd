// Lazy, like every other admin route — a static import here ships an
// admin-only screen to every storefront visitor.
const MobileSectionListComponent = () =>
    import("../../components/admin/mobileSection/MobileSectionListComponent.vue");

export default [
    {
        path: "/admin/mobile-section",
        component: MobileSectionListComponent,
        name: "admin.mobile.section",
        meta: {
            auth: true,
            roles: "admin",
            menu: true,
        },
    },
];
