const CampaignComponent = () => import("../../components/admin/campaigns/CampaignComponent");
const CampaignListComponent = () => import("../../components/admin/campaigns/CampaignListComponent");
const CampaignShowComponent = () => import("../../components/admin/campaigns/CampaignShowComponent");

export default [
    {
        path: '/admin/campaigns',
        component: CampaignComponent,
        name: 'admin.campaigns',
        redirect: { name: 'admin.campaigns.list' },
        meta: {
            isFrontend: false,
            auth: true,
            // Must match the `url` column of the `campaigns` permission row,
            // which is what the sidebar and the route guard both look up.
            permissionUrl: 'campaigns',
            breadcrumb: 'campaigns'
        },
        children: [
            {
                path: '',
                component: CampaignListComponent,
                name: 'admin.campaigns.list',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'campaigns',
                    breadcrumb: ''
                },
            },
            {
                path: 'show/:id',
                component: CampaignShowComponent,
                name: 'admin.campaign.show',
                meta: {
                    isFrontend: false,
                    auth: true,
                    permissionUrl: 'campaigns',
                    breadcrumb: 'view',
                },
            },
        ]
    }
]
