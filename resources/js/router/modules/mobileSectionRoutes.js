import MobileSectionListComponent from "../../components/admin/mobileSection/MobileSectionListComponent.vue";

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
