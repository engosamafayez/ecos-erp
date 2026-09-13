import { createBrowserRouter, redirect } from 'react-router-dom';

import { ComingSoonPage } from '@/components/common/coming-soon-page';
import { DriverShell } from '@/components/layout/driver-shell';
import { AcceptInvitationPage } from '@/features/auth/pages/accept-invitation-page';
import { LoginPage } from '@/features/auth/pages/login-page';
import { BrandsPage } from '@/features/brands/pages/brands-page';
import { BusinessAccountsPage } from '@/features/business-accounts/pages/business-accounts-page';
import { TeamsPage } from '@/features/teams/pages/teams-page';
import { BranchesPage } from '@/features/branches/pages/branches-page';
import { BranchCoveragePage } from '@/features/branches/pages/branch-coverage-page';
import { CategoriesPage } from '@/features/categories/pages/categories-page';
import { ChannelsPage } from '@/features/channels/pages/channels-page';
import { CompaniesPage } from '@/features/companies/pages/companies-page';
import { DashboardPage } from '@/features/dashboard/pages/dashboard-page';
// Executive Platform (EPIC-EXECUTIVE-UI-001) — aliased; Marketing also exports ExecutiveDashboardPage.
import { ExecutiveDashboardPage as ExecutivePlatformPage } from '@/features/executive/pages/executive-dashboard-page';
// Reporting V1 (TASK-ECOS-REPORTING-V1-USER-VISIBLE-NAVIGATION-CLOSURE-010)
import { ReportingCataloguePage } from '@/features/reporting/pages/reporting-catalogue-page';
import { ReportDetailPage } from '@/features/reporting/pages/report-detail-page';
// Central Audit workspace (CORE-02 Task 2)
import { AuditLogPage } from '@/features/audit/pages/audit-log-page';
// Finance workspace (EPIC-FINANCE-UI-001)
import { FinanceExecutivePage } from '@/features/finance/pages/finance-executive-page';
import { ChartOfAccountsPage } from '@/features/finance/pages/chart-of-accounts-page';
import { JournalsPage } from '@/features/finance/pages/journals-page';
import { FinancialStatementsPage } from '@/features/finance/pages/financial-statements-page';
import { AccountsReceivablePage } from '@/features/finance/pages/accounts-receivable-page';
import { AccountsPayablePage } from '@/features/finance/pages/accounts-payable-page';
import { CashBankingPage } from '@/features/finance/pages/cash-banking-page';
import { FiscalClosingPage } from '@/features/finance/pages/fiscal-closing-page';
import { BudgetsPage } from '@/features/finance/pages/budgets-page';
import { CostingProfitabilityPage } from '@/features/finance/pages/costing-profitability-page';
import { ExpensesPage } from '@/features/finance/pages/expenses-page';
import { TaxVatPage } from '@/features/finance/pages/tax-vat-page';
import { HomePage } from '@/features/home/pages/home-page';
// IAM Administration Workspace (TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003)
import { IamWorkspacePage } from '@/features/iam-admin/pages/iam-workspace-page';
import { PackagingMaterialsPage } from '@/features/inventory/pages/packaging-materials-page';
import { ConsumablesPage } from '@/features/inventory/pages/consumables-page';
import { SemiFinishedMaterialsPage } from '@/features/inventory/pages/semi-finished-materials-page';
import { OrganizationWorkspace } from '@/features/organization/pages/organization-workspace';
import { OrgSearchPage } from '@/features/organization/pages/org-search-page';
import { ProductsPage } from '@/features/products/pages/products-page';
import { RawMaterialsPage } from '@/features/products/pages/raw-materials-page';
import { UnitsPage } from '@/features/units/pages/units-page';
import { WarehousesPage } from '@/features/warehouses/pages/warehouses-page';
import { ProductMappingsPage } from '@/features/product-mappings/pages/product-mappings-page';
import { OrdersPage } from '@/features/orders/pages/orders-page';
import { OrderWorkspacePage } from '@/features/orders/pages/order-workspace-page';
import { FulfillmentsPage } from '@/features/fulfillments/pages/fulfillments-page';
import { CreateFulfillmentPage } from '@/features/fulfillments/pages/create-fulfillment-page';
import { ViewFulfillmentPage } from '@/features/fulfillments/pages/view-fulfillment-page';
import { SyncLogsPage } from '@/features/sync-logs/pages/sync-logs-page';
import { BomWorkspacePage } from '@/features/boms/pages/bom-workspace-page';
import { RecipesPage } from '@/features/recipes/pages/recipes-page';
import { RecipeWorkspacePage } from '@/features/recipes/pages/recipe-workspace-page';
import { CrmCustomersWorkspacePage } from '@/features/crm/pages/crm-customers-workspace-page';
import { CrmExecutiveWorkspacePage } from '@/features/crm/pages/crm-executive-workspace-page';
import { CrmPortfolioPage } from '@/features/crm/pages/crm-portfolio-page';
import { CustomersPage } from '@/features/customers/pages/customers-page';
import { CustomerProfilePage } from '@/features/customers/pages/customer-profile-page';
import { StockLedgerPage } from '@/features/stock-ledger/pages/stock-ledger-page';
import { InventoryDashboardPage } from '@/features/inventory-control/pages/inventory-dashboard-page';
import { AbcClassificationPage } from '@/features/inventory-control/pages/abc-classification-page';
import { CycleCountPlannerPage } from '@/features/inventory-control/pages/cycle-count-planner-page';
import { VarianceAnalyticsPage } from '@/features/inventory-control/pages/variance-analytics-page';
import { WarehousePerformancePage } from '@/features/inventory-control/pages/warehouse-performance-page';
import { CreateGoodsReceiptPage } from '@/features/goods-receipts/pages/create-goods-receipt-page';
import { EditGoodsReceiptPage } from '@/features/goods-receipts/pages/edit-goods-receipt-page';
import { ViewGoodsReceiptPage } from '@/features/goods-receipts/pages/view-goods-receipt-page';
import { SuppliersPage } from '@/features/suppliers/pages/suppliers-page';
import { MaterialRequestsPage } from '@/features/purchase-materials/pages/material-requests-page';
import { PurchasesPage } from '@/features/purchase-materials/pages/purchases-page';
import { ProcurementHubPage } from '@/features/procurement/pages/procurement-hub-page';
import { ReceivingCenterPage } from '@/features/receiving-center/pages/receiving-center-page';
import { SupplierReturnsPage } from '@/features/supplier-returns/pages/supplier-returns-page';
import { SupplierInvoicesPage } from '@/features/supplier-invoices/pages/supplier-invoices-page';
import { CostPricingCenterPage } from '@/features/cost-management/pages/cost-pricing-center-page';
import { CostManagementDashboardPage } from '@/features/cost-management/pages/cost-management-dashboard-page';
import { CostHistoryPage } from '@/features/cost-management/pages/cost-history-page';
import { InventoryCountPage } from '@/features/inventory-count/pages/inventory-count-page';
import { WasteInvestigationsPage } from '@/features/inventory-count/pages/waste-investigations-page';
import { WarehouseLiabilityPage } from '@/features/inventory-count/pages/warehouse-liability-page';
import { StockTransfersPage } from '@/features/stock-transfers/pages/stock-transfers-page';
import { FulfillmentWaveWorkspacePage } from '@/features/operations/pages/fulfillment-wave-workspace-page';
import { WaveProductDemandPage } from '@/features/operations/pages/wave-product-demand-page';
import { WaveRawMaterialsPage } from '@/features/operations/pages/wave-raw-materials-page';
import { WaveMissingMaterialsPage } from '@/features/operations/pages/wave-missing-materials-page';
import { WaveOrdersPage } from '@/features/operations/pages/wave-orders-page';
import { WaveSettingsPage } from '@/features/operations/pages/wave-settings-page';
import { WaveWorkspaceLayout } from '@/features/operations/components/wave-workspace-layout';
import { DeficitDecisionsPage } from '@/features/operations/pages/deficit-decisions-page';
import { WaveArchivePage } from '@/features/operations/pages/wave-archive-page';
import { WaveEngineSettingsPage } from '@/features/operations/pages/wave-engine-settings-page';
import { PreparationWorkspaceLayout } from '@/features/operations/components/preparation-workspace-layout';
import { PosPage } from '@/features/pos/pages/pos-page';
import { ConfigurationOsPage } from '@/features/admin/configuration/pages/configuration-os-page';
import { BrandConfigurationPage } from '@/features/admin/configuration/pages/brand-configuration-page';
// Pre-Live / Go-Live Preparation (TASK-...-026) — a distinct administration destination, not a
// Configuration OS tab (see module-navigation.ts's own 'golive-section').
import { GoLivePreparationPage } from '@/features/golive/pages/golive-preparation-page';
import { DistributionWorkspacePage } from '@/features/logistics/distribution-workspace/pages/distribution-workspace-page';
import { DriverSettlementWorkspacePage } from '@/features/operations/driver-settlement/pages/driver-settlement-workspace-page';
import { DriverSettlementDetailPage } from '@/features/operations/driver-settlement/pages/driver-settlement-detail-page';
import { TripsWorkspacePage } from '@/features/logistics/trips/pages/trips-workspace-page';
import { ServiceAreasPage } from '@/features/logistics/network/pages/service-areas-page';
import { ActivityCenterPage } from '@/features/logistics/operations/pages/activity-center-page';
// Shipping OS Redesign (TASK-ECOS-SHIPPING-OS-REDESIGN-001) — six approved primary
// workspaces. Nineteen former direct-route imports (Vehicles/Drivers/Shipping Companies/
// Carrier Accounts/Fuel/Distribution Zones/Egypt Geography/Automation/Fleet Dashboard/
// Command Center/Operations Center/old Execution/Dashboards/Alert Center/Enterprise
// Readiness/Enterprise Workspace/Intelligence/Dispatch Board/Delivery) were removed from
// this file — their routes now redirect (see the route table below) and, where a workspace
// composes them live, the same page components are imported directly inside that
// workspace's own feature folder instead of being routed here. No component was deleted.
// Distribution Board's own direct import (TASK-ECOS-DISTRIBUTION-FINAL-SOURCE-CLOSURE-002,
// landed separately) is dropped here too: its backend no longer exists and its route
// below already redirects to the canonical Distribution Workspace.
import { ControlTowerPage as ShippingControlTowerPage } from '@/features/logistics/control-tower/pages/control-tower-page';
import { DispatchExecutionPage as ShippingDispatchExecutionPage } from '@/features/logistics/dispatch-execution/pages/dispatch-execution-page';
import { LiveDriverMapPage as ShippingLiveDriverMapPage } from '@/features/logistics/live-driver-map/pages/live-driver-map-page';
import { ReturnsSettlementPage as ShippingReturnsSettlementPage } from '@/features/logistics/returns-settlement/pages/returns-settlement-page';
import { FleetConfigurationPage as ShippingFleetConfigurationPage } from '@/features/logistics/fleet-configuration/pages/fleet-configuration-page';
import { LoadingDashboardPage } from '@/features/operations/distribution-board/pages/loading-dashboard-page';
import { LoadingWorkspacePage } from '@/features/operations/distribution-board/pages/loading-workspace-page';
// Canonical GROUP-grain Loading Execution workspace (Stack B, /api/loading/groups).
// TASK-1-D-LOADING-EXECUTION-001: the page and its route constant already existed but
// were orphaned; wiring makes the approved warehouse Loading surface reachable.
import { LoadingOsWorkspacePage } from '@/features/operations/loading-os/pages/loading-os-workspace-page';
// TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-IMPLEMENTATION-002.
import { ShippingOrdersPage } from '@/features/operations/shipping-orders/pages/shipping-orders-page';
import { DispatchGatePage } from '@/features/operations/distribution-board/pages/dispatch-gate-page';
import { DispatchGateWorkspacePage } from '@/features/operations/distribution-board/pages/dispatch-gate-workspace-page';
import { MarketingDashboardPage } from '@/features/marketing/pages/marketing-dashboard-page';
import { MarketingAssetsPage } from '@/features/marketing/pages/marketing-assets-page';
import { MetaConnectPage } from '@/features/marketing/pages/meta-connect-page';
import { MetaConnectionPage } from '@/features/marketing/pages/meta-connection-page';
import { CampaignsWorkspacePage } from '@/features/marketing/pages/campaigns-workspace-page';
import { CampaignExecutiveDashboardPage } from '@/features/marketing/pages/campaign-executive-dashboard-page';
import { InitiativesWorkspacePage } from '@/features/marketing/pages/initiatives-workspace-page';
import { InitiativeExecutiveViewPage } from '@/features/marketing/pages/initiative-executive-view-page';
import { CampaignStudioPage } from '@/features/marketing/campaign-studio/pages/campaign-studio-page';
import { CampaignGovernancePage } from '@/features/marketing/campaign-studio/pages/campaign-governance-page';
import { StudioExecutiveDashboardPage } from '@/features/marketing/campaign-studio/pages/studio-executive-dashboard-page';
import { ExecutiveDashboardPage } from '@/features/marketing/intelligence/pages/executive-dashboard-page';
import { CampaignAnalyticsPage } from '@/features/marketing/intelligence/pages/campaign-analytics-page';
import { AdAnalyticsPage } from '@/features/marketing/intelligence/pages/ad-analytics-page';
import { CreativeAnalyticsPage } from '@/features/marketing/intelligence/pages/creative-analytics-page';
import { PerformanceTrendsPage } from '@/features/marketing/intelligence/pages/performance-trends-page';
import { BudgetAnalysisPage } from '@/features/marketing/intelligence/pages/budget-analysis-page';
import { ReportsPage } from '@/features/marketing/intelligence/pages/reports-page';
import { MarketingSettingsPage } from '@/features/marketing/pages/marketing-settings-page';
import { MyPreferencesPage } from '@/features/notifications/pages/my-preferences-page';
import { NotificationSettingsPage } from '@/features/notifications/pages/notification-settings-page';
import { AutomationWorkspacePage } from '@/features/marketing/automation/pages/automation-workspace-page';
import { WorkflowBuilderPage } from '@/features/marketing/automation/pages/workflow-builder-page';
import { AudienceSegmentsPage } from '@/features/marketing/automation/pages/audience-segments-page';
import { AutomationDashboardPage } from '@/features/marketing/automation/pages/automation-dashboard-page';
import { AutomationGovernancePage } from '@/features/marketing/automation/pages/automation-governance-page';
import { DriverHomePage } from '@/features/operations/driver-mobile/pages/driver-home-page';
import { DriverTripDashboardPage } from '@/features/operations/driver-mobile/pages/driver-trip-dashboard-page';
import { DriverStopListPage } from '@/features/operations/driver-mobile/pages/driver-stop-list-page';
import { DriverStopDetailPage } from '@/features/operations/driver-mobile/pages/driver-stop-detail-page';
import { DriverExceptionsPage } from '@/features/operations/driver-mobile/pages/driver-exceptions-page';
import { DriverReturnsPage } from '@/features/operations/driver-mobile/pages/driver-returns-page';
// DriverCollectionsPage / DriverSettlementPage / DriverCustodyReturnPage / DriverTripTimelinePage
// are no longer routed (TASK-DRIVER-APP-FINAL-GAPS-CLOSURE-001, CTO D2/D3): their trip-scoped
// destinations are aliased to canonical read-only/working surfaces or retired — see the driver
// route block below. The page files remain in the tree, unreferenced (retired dead code).
import { DriverMapPage } from '@/features/operations/driver-mobile/pages/driver-map-page';
// Operational-flow driver pages (committed in feat 3f0b7e00; route-key constants committed in
// 1111f43b "required by committed pages"). These were committed WITHOUT their router
// registration — the flat driver journey below closes that source-level wiring gap.
import { DriverLoadingPage } from '@/features/operations/driver-mobile/pages/driver-loading-page';
import { DriverOrdersPage } from '@/features/operations/driver-mobile/pages/driver-orders-page';
import { DriverOrdersMapPage } from '@/features/operations/driver-mobile/pages/driver-orders-map-page';
import { DriverVehicleInventoryPage } from '@/features/operations/driver-mobile/pages/driver-vehicle-inventory-page';
import { DriverWalletPage } from '@/features/operations/driver-mobile/pages/driver-wallet-page';
import { DriverReportsPage } from '@/features/operations/driver-mobile/pages/driver-reports-page';
import { DriverStatementPage } from '@/features/operations/driver-mobile/pages/driver-statement-page';
import { DriverTripExpensesPage } from '@/features/operations/driver-mobile/pages/driver-trip-expenses-page';
import { DriverTasksPage } from '@/features/operations/driver-mobile/pages/driver-tasks-page';
import { CollaborationWorkspacePage } from '@/features/collaboration/pages/collaboration-workspace-page';
import { JourneyExplorerPage } from '@/features/core/business-attribution/pages/journey-explorer-page';
import { BaeTimelinePage } from '@/features/core/business-attribution/pages/bae-timeline-page';
import { UnifiedInboxPage } from '@/features/customer-engagement/pages/unified-inbox-page';
import { CepDashboardPage } from '@/features/customer-engagement/pages/cep-dashboard-page';
import { CepLeadsPage } from '@/features/customer-engagement/pages/cep-leads-page';
import { NotFoundPage } from '@/features/core/pages/not-found-page';
import { BridgeDashboardPage } from '@/features/claude-bridge/pages/bridge-dashboard-page';
import { BridgeTasksPage } from '@/features/claude-bridge/pages/bridge-tasks-page';
import { BridgeCreateTaskPage } from '@/features/claude-bridge/pages/bridge-create-task-page';
import { BridgeTaskDetailPage } from '@/features/claude-bridge/pages/bridge-task-detail-page';
import { BridgeSettingsPage } from '@/features/claude-bridge/pages/bridge-settings-page';
import { EngineeringDashboardPage } from '@/features/engineering/pages/engineering-dashboard-page';
import { EngineeringRunsPage } from '@/features/engineering/pages/engineering-runs-page';
import { EngineeringFindingsPage } from '@/features/engineering/pages/engineering-findings-page';
import { EngineeringPipelinePage } from '@/features/engineering/pages/engineering-pipeline-page';
import { EngineeringPipelineHistoryPage } from '@/features/engineering/pages/engineering-pipeline-history-page';
import { EngineeringNotificationsPage } from '@/features/engineering/pages/engineering-notifications-page';
import { EngineeringAnalyticsPage } from '@/features/engineering/pages/engineering-analytics-page';
import ClusterDashboardPage from '@/features/engineering/pages/ClusterDashboardPage';
import ReleaseDashboardPage from '@/features/engineering/pages/ReleaseDashboardPage';
import AIEngineeringWorkspacePage from '@/features/engineering/pages/AIEngineeringWorkspacePage';
import RepairSessionsPage from '@/features/engineering/pages/RepairSessionsPage';
import EnterpriseWorkspacePage from '@/features/engineering/pages/EnterpriseWorkspacePage';
import { OmnichannelInboxPage } from '@/features/conversational-commerce/pages/omnichannel-inbox-page';
import { MacrosPage } from '@/features/conversational-commerce/pages/macros-page';
import { RoutingRulesPage } from '@/features/conversational-commerce/pages/routing-rules-page';
import { ChannelProvidersPage } from '@/features/conversational-commerce/pages/channel-providers-page';
import { ConversationsDashboardPage } from '@/features/conversational-commerce/pages/conversations-dashboard-page';
import { WorkforceDashboardPage } from '@/features/hr/pages/workforce-dashboard-page';
import { EmployeesPage } from '@/features/hr/pages/employees-page';
import { Employee360Page } from '@/features/hr/pages/employee-360-page';
import { OrganizationChartPage } from '@/features/hr/pages/organization-chart-page';
import { WorkforceStructurePage } from '@/features/hr/pages/workforce-structure-page';
import { AttendanceWorkspacePage } from '@/features/hr/pages/attendance-workspace-page';
import { LeaveRequestsPage } from '@/features/hr/pages/leave-requests-page';
import { CompensationWorkspacePage } from '@/features/hr/pages/compensation-workspace-page';
import { Compensation360Page } from '@/features/hr/pages/compensation-360-page';
import { CommissionRulesPage } from '@/features/hr/pages/commission-rules-page';
import { PerformanceWorkspacePage } from '@/features/hr/pages/performance-workspace-page';
import { EmployeePerformancePage } from '@/features/hr/pages/employee-performance-page';
import { DepartmentPerformancePage } from '@/features/hr/pages/department-performance-page';
import { DriverPerformancePage } from '@/features/hr/pages/driver-performance-page';
import { CareersPortalPage } from '@/features/hr/pages/careers-portal-page';
import { CareersApplyPage } from '@/features/hr/pages/careers-apply-page';
import { RecruitmentWorkspacePage } from '@/features/hr/pages/recruitment-workspace-page';
import { ApplicationDetailPage } from '@/features/hr/pages/application-detail-page';
import { HrExecutivePage } from '@/features/hr/pages/hr-executive-page';
import { HrAnalyticsPage } from '@/features/hr/pages/hr-analytics-page';
// HR V1 enhancements (TASK-HR-V1-ENHANCEMENTS-001)
import { RecruitmentAnalyticsPage } from '@/features/hr/pages/recruitment-analytics-page';
import { ApplicantTagsPage } from '@/features/hr/pages/applicant-tags-page';
import { OffersWorkspacePage } from '@/features/hr/pages/offers-workspace-page';
import { ExitManagementPage } from '@/features/hr/pages/exit-management-page';
import { CompensationExplainabilityPage } from '@/features/hr/pages/compensation-explainability-page';
import { AuthLayout } from '@/layouts/auth-layout';
import { GuestRoute } from '@/router/guards/guest-route';
import { ProtectedRoute } from '@/router/guards/protected-route';
import { EnterpriseAppShell } from '@/components/layout/enterprise-app-shell';
import { ROUTES } from '@/router/routes';
// ROUTES.settings is deliberately absent: the real settings workspace already
// exists at ROUTES.configurationOs, so a Coming Soon placeholder here was a dead
// link competing with it in the same menu (UAT BUG-04). It redirects instead.
// ROUTES.crm is deliberately absent too: CRM is complete and its rail entry
// resolves to /crm/customers, but the bare /crm URL still rendered a Coming Soon
// placeholder for anyone arriving by bookmark, deep link or typed address
// (BUG-GL-004). It redirects instead.
// ROUTES.users/ROUTES.roles are ALSO deliberately absent now (TASK-ECOS-IAM-
// ADMINISTRATION-WORKSPACE-003) — they were Coming Soon placeholders behind the sidebar's
// existing "Users"/"Roles & Permissions" nav items; both now render the real
// IamWorkspacePage instead, registered explicitly below alongside ROUTES.roleTemplates.
const moduleRoutes = [ROUTES.sales].map((path) => ({
  path,
  Component: ComingSoonPage,
}));

export const router = createBrowserRouter(
  [
    { path: ROUTES.home, Component: HomePage },
    // PUBLIC careers portal — deliberately outside ProtectedRoute and AppShell.
    // A visitor has no session, no company context and no navigation rail.
    { path: ROUTES.careers, Component: CareersPortalPage },
    { path: ROUTES.careersJob, Component: CareersApplyPage },
    // CORE-02 Task 1 — PUBLIC invitation acceptance, same reasoning: the invitee has no
    // session yet, so this sits outside ProtectedRoute (and outside GuestRoute too — it is
    // not "log in", it is "set up the account before you can").
    { path: ROUTES.acceptInvitation, Component: AcceptInvitationPage },
    {
      path: ROUTES.login,
      Component: GuestRoute,
      children: [{ Component: AuthLayout, children: [{ index: true, Component: LoginPage }] }],
    },
    {
      Component: ProtectedRoute,
      children: [
        // POS is full-screen — outside AppShell
        { path: ROUTES.pos, Component: PosPage },
        // Driver application — a SIBLING shell to AppShell (TASK-DRIVER-SHELL-FINAL-CLOSURE-001,
        // CTO decision D1). /driver/* resolves through DriverShell, which imports NO ERP chrome,
        // so a driver never receives enterprise navigation. Routes moved here unchanged — no
        // business destination altered. Trip-scoped screens are reached by navigating into a
        // trip from these pages, so only the flat destinations appear in the shell's own nav.
        {
          Component: DriverShell,
          children: [
            // Trip-scoped execution screens (TASK-DIST-005)
            { path: ROUTES.driverHome, Component: DriverHomePage },
            { path: ROUTES.driverTrip, Component: DriverTripDashboardPage },
            { path: ROUTES.driverTripStops, Component: DriverStopListPage },
            { path: ROUTES.driverTripStop, Component: DriverStopDetailPage },
            // TASK-DRIVER-APP-FINAL-GAPS-CLOSURE-001 (CTO D2/D3): the driver is not the settlement
            // authority, and the custody/timeline driver backends never existed. The frozen (403)
            // and absent (404) trip-scoped destinations are aliased to canonical read-only/working
            // surfaces — or retired — so no visible driver route leads to an actionable 403/404.
            // D2: self-settlement retired; /settlement submit + /collections read → read-only Wallet.
            { path: ROUTES.driverTripCollections, loader: () => redirect(ROUTES.driverWallet) },
            { path: ROUTES.driverTripExceptions, Component: DriverExceptionsPage },
            { path: ROUTES.driverTripReturns, Component: DriverReturnsPage },
            { path: ROUTES.driverTripSettlement, loader: () => redirect(ROUTES.driverWallet) },
            // D3A: custody-returns' backend never existed (404); its capability is the canonical
            // Driver Returns / Vehicle Reconciliation surface — alias to it (no new authority).
            {
              path: ROUTES.driverTripCustody,
              loader: ({ params }) =>
                redirect(ROUTES.driverTripReturns.replace(':tripId', params.tripId ?? '')),
            },
            // D3B: driverTripTimeline retired — no canonical driver timeline read authority (404).
            { path: ROUTES.driverTripMap, Component: DriverMapPage },
            // Flat operational-flow destinations (pages/constants: feat 3f0b7e00, chore 1111f43b;
            // registered in FINAL-FUNCTIONAL-CLOSURE-001). /driver/map → DriverOrdersMapPage is
            // the canonical GPS-gated map; the driverTripMap→DriverMapPage placeholder stays.
            { path: ROUTES.driverLoading, Component: DriverLoadingPage },
            { path: ROUTES.driverOrders, Component: DriverOrdersPage },
            { path: ROUTES.driverMap, Component: DriverOrdersMapPage },
            { path: ROUTES.driverVehicleInventory, Component: DriverVehicleInventoryPage },
            { path: ROUTES.driverWallet, Component: DriverWalletPage },
            { path: ROUTES.driverReports, Component: DriverReportsPage },
            { path: ROUTES.driverStatement, Component: DriverStatementPage },
            { path: ROUTES.driverTripExpenses, Component: DriverTripExpensesPage },
            // Internal Collaboration & Tasks (TASK-ECOS-COLLABORATION-WORKSPACE-DRIVER-
            // EXPOSURE-CLOSURE-005) — nav entry + page only, DriverShell unchanged.
            { path: ROUTES.driverTasks, Component: DriverTasksPage },
          ],
        },
        // Enterprise shell — EnterpriseAppShell guards AppShell so a driver-only user who
        // deep-links here is redirected to /driver/home (the same isDriverOnly predicate as
        // post-login); enterprise & mixed users pass through. UX boundary only; every API route
        // stays independently permission-gated.
        {
          Component: EnterpriseAppShell,
          children: [
            { path: ROUTES.dashboard, Component: DashboardPage },
            // Internal Collaboration & Tasks (TASK-ECOS-COLLABORATION-WORKSPACE-DRIVER-
            // EXPOSURE-CLOSURE-005) — Conversations + Tasks are tabs of this one route.
            { path: ROUTES.collaborationWorkspace, Component: CollaborationWorkspacePage },
            // Executive Platform (EPIC-EXECUTIVE-UI-001)
            { path: ROUTES.executiveDashboard, Component: ExecutivePlatformPage },
            // Reporting V1 (TASK-ECOS-REPORTING-V1-USER-VISIBLE-NAVIGATION-CLOSURE-010)
            { path: ROUTES.reports, Component: ReportingCataloguePage },
            { path: ROUTES.reportDetail, Component: ReportDetailPage },
            { path: ROUTES.audit, Component: AuditLogPage },
            // Finance workspace (EPIC-FINANCE-UI-001). /accounting = Executive Finance.
            { path: ROUTES.accounting, Component: FinanceExecutivePage },
            { path: ROUTES.financeChartOfAccounts, Component: ChartOfAccountsPage },
            { path: ROUTES.financeJournals, Component: JournalsPage },
            { path: ROUTES.financeStatements, Component: FinancialStatementsPage },
            { path: ROUTES.financeReceivables, Component: AccountsReceivablePage },
            { path: ROUTES.financePayables, Component: AccountsPayablePage },
            { path: ROUTES.financeCashBanking, Component: CashBankingPage },
            { path: ROUTES.financeFiscalClosing, Component: FiscalClosingPage },
            { path: ROUTES.financeBudgets, Component: BudgetsPage },
            { path: ROUTES.financeTaxVat, Component: TaxVatPage },
            { path: ROUTES.financeExpenses, Component: ExpensesPage },
            { path: ROUTES.financeCosting, Component: CostingProfitabilityPage },
            // Organization workspace + sub-pages
            { path: ROUTES.organization, Component: OrganizationWorkspace },
            { path: ROUTES.orgSearch, Component: OrgSearchPage },
            { path: ROUTES.companies, Component: CompaniesPage },
            { path: ROUTES.brands, Component: BrandsPage },
            { path: ROUTES.businessAccounts, Component: BusinessAccountsPage },
            { path: ROUTES.teams, Component: TeamsPage },
            { path: ROUTES.branches, Component: BranchesPage },
            { path: ROUTES.branchCoverage, Component: BranchCoveragePage },
            // HR & Workforce OS — EPIC H1 + H2
            { path: ROUTES.hr, Component: WorkforceDashboardPage },
            { path: ROUTES.hrEmployees, Component: EmployeesPage },
            { path: ROUTES.hrEmployee360, Component: Employee360Page },
            { path: ROUTES.hrOrganizationChart, Component: OrganizationChartPage },
            { path: ROUTES.hrStructure, Component: WorkforceStructurePage },
            { path: ROUTES.hrAttendance, Component: AttendanceWorkspacePage },
            { path: ROUTES.hrLeave, Component: LeaveRequestsPage },
            // HR & Workforce OS — EPIC H3 + H4
            { path: ROUTES.hrCompensation, Component: CompensationWorkspacePage },
            { path: ROUTES.hrCommissionRules, Component: CommissionRulesPage },
            { path: ROUTES.hrCompensation360, Component: Compensation360Page },
            { path: ROUTES.hrPerformance, Component: PerformanceWorkspacePage },
            { path: ROUTES.hrEmployeePerformance, Component: EmployeePerformancePage },
            { path: ROUTES.hrDepartmentPerformance, Component: DepartmentPerformancePage },
            { path: ROUTES.hrDriverPerformance, Component: DriverPerformancePage },
            // HR & Workforce OS — EPIC H5 + H6
            // Static segments before the :applicationId pattern, or /analytics
            // and /tags would be matched as application ids.
            { path: ROUTES.hrRecruitmentAnalytics, Component: RecruitmentAnalyticsPage },
            { path: ROUTES.hrApplicantTags, Component: ApplicantTagsPage },
            { path: ROUTES.hrOffers, Component: OffersWorkspacePage },
            { path: ROUTES.hrExits, Component: ExitManagementPage },
            {
              path: ROUTES.hrCompensationExplainability,
              Component: CompensationExplainabilityPage,
            },
            { path: ROUTES.hrRecruitment, Component: RecruitmentWorkspacePage },
            { path: ROUTES.hrApplication, Component: ApplicationDetailPage },
            { path: ROUTES.hrExecutive, Component: HrExecutivePage },
            { path: ROUTES.hrAnalytics, Component: HrAnalyticsPage },
            { path: ROUTES.warehouses, Component: WarehousesPage },
            { path: ROUTES.channels, Component: ChannelsPage },
            // Inventory workspace + sub-pages
            // Old hub URL redirects to Products workspace
            { path: ROUTES.inventoryProducts, loader: () => redirect(ROUTES.products) },
            { path: ROUTES.products, Component: ProductsPage },
            { path: ROUTES.rawMaterials, Component: RawMaterialsPage },
            { path: ROUTES.stockLedger, Component: StockLedgerPage },
            // Legacy flat routes redirect to new Master Data paths
            { path: ROUTES.categories, loader: () => redirect(ROUTES.inventoryCategories) },
            { path: ROUTES.units, loader: () => redirect(ROUTES.inventoryUnits) },
            // Old scoped routes redirect to unified categories with scope query param
            {
              path: ROUTES.inventoryProductCategories,
              loader: () => redirect(`${ROUTES.inventoryCategories}?scope=product`),
            },
            {
              path: ROUTES.inventoryMaterialCategories,
              loader: () => redirect(`${ROUTES.inventoryCategories}?scope=material`),
            },
            // Inventory Master Data — unified categories workspace
            { path: ROUTES.inventoryCategories, Component: CategoriesPage },
            { path: ROUTES.inventoryUnits, Component: UnitsPage },
            // Inventory Control
            { path: ROUTES.inventoryDashboard, Component: InventoryDashboardPage },
            { path: ROUTES.inventoryAbcClassifications, Component: AbcClassificationPage },
            { path: ROUTES.inventoryCycleCountPlanner, Component: CycleCountPlannerPage },
            { path: ROUTES.inventoryVarianceAnalytics, Component: VarianceAnalyticsPage },
            { path: ROUTES.inventoryWarehousePerformance, Component: WarehousePerformancePage },
            // Inventory Count Sessions + Waste / Liability
            { path: ROUTES.inventoryCount, Component: InventoryCountPage },
            { path: ROUTES.wasteInvestigations, Component: WasteInvestigationsPage },
            { path: ROUTES.warehouseLiabilities, Component: WarehouseLiabilityPage },
            // Stock Transfers (placeholder)
            { path: ROUTES.stockTransfers, Component: StockTransfersPage },
            // Procurement — full suite
            { path: ROUTES.procurementHub, Component: ProcurementHubPage },
            { path: ROUTES.materialRequests, Component: MaterialRequestsPage },
            { path: ROUTES.purchases, Component: PurchasesPage },
            { path: ROUTES.supplierInvoices, Component: SupplierInvoicesPage },
            { path: ROUTES.receivingCenter, Component: ReceivingCenterPage },
            { path: ROUTES.supplierReturns, Component: SupplierReturnsPage },
            // Legacy redirects
            { path: ROUTES.purchaseMaterials, loader: () => redirect(ROUTES.purchases) },
            { path: ROUTES.purchaseOrders, loader: () => redirect(ROUTES.purchases) },
            { path: ROUTES.purchaseOrdersNew, loader: () => redirect(ROUTES.purchases) },
            { path: `${ROUTES.purchaseOrders}/:id`, loader: () => redirect(ROUTES.purchases) },
            { path: `${ROUTES.purchaseOrders}/:id/edit`, loader: () => redirect(ROUTES.purchases) },
            // Goods receipts — legacy paths redirect to Receiving Center
            { path: ROUTES.goodsReceipts, loader: () => redirect(ROUTES.receivingCenter) },
            { path: ROUTES.goodsReceiptsNew, Component: CreateGoodsReceiptPage },
            { path: `${ROUTES.goodsReceipts}/:id`, Component: ViewGoodsReceiptPage },
            { path: `${ROUTES.goodsReceipts}/:id/edit`, Component: EditGoodsReceiptPage },
            { path: ROUTES.suppliers, Component: SuppliersPage },
            // Sales
            { path: ROUTES.orders, Component: OrdersPage },
            { path: ROUTES.ordersNew, Component: OrderWorkspacePage },
            { path: `${ROUTES.orders}/:id/edit`, Component: OrderWorkspacePage },
            { path: `${ROUTES.orders}/:id`, Component: OrderWorkspacePage },
            { path: ROUTES.fulfillments, Component: FulfillmentsPage },
            { path: ROUTES.fulfillmentsNew, Component: CreateFulfillmentPage },
            { path: `${ROUTES.fulfillments}/:id`, Component: ViewFulfillmentPage },
            { path: ROUTES.customers, Component: CustomersPage },
            { path: ROUTES.crm, loader: () => redirect(ROUTES.crmCustomers) },
            { path: ROUTES.crmCustomers, Component: CrmCustomersWorkspacePage },
            { path: ROUTES.crmExecutive, Component: CrmExecutiveWorkspacePage },
            { path: ROUTES.crmPortfolio, Component: CrmPortfolioPage },
            { path: ROUTES.customerDetail, Component: CustomerProfilePage },
            // Configuration OS
            { path: ROUTES.configurationOs, Component: ConfigurationOsPage },
            { path: ROUTES.configurationBrand, Component: BrandConfigurationPage },
            // Pre-Live / Go-Live Preparation (TASK-...-026)
            { path: ROUTES.golive, Component: GoLivePreparationPage },
            // Distribution OS
            // Distribution Board's backend (/api/distribution/*) no longer exists in
            // routes/api.php — every one of its calls 404s unconditionally. Retired the
            // same way logisticsDistributionPlanning was below: redirect the stale nav
            // entry/deep link to the canonical workspace rather than leave a broken page
            // reachable from navigation. (TASK-ECOS-DISTRIBUTION-FINAL-SOURCE-CLOSURE-002)
            { path: ROUTES.distributionBoard, loader: () => redirect(ROUTES.logisticsDistributionWorkspace) },
            { path: `${ROUTES.loadingWorkspace}/:tripId/loading`, Component: LoadingWorkspacePage },
            // Loading OS
            { path: ROUTES.loadingOsDashboard, Component: LoadingDashboardPage },
            // Canonical GROUP-grain Loading Execution workspace (approved Stack B backend).
            { path: ROUTES.loadingOsWorkspace, Component: LoadingOsWorkspacePage },
            // Shipping Orders — TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-IMPLEMENTATION-002.
            { path: ROUTES.shippingOrders, Component: ShippingOrdersPage },
            // Dispatch Gate OS
            { path: ROUTES.dispatchGate, Component: DispatchGatePage },
            { path: `${ROUTES.dispatchGate}/:tripId`, Component: DispatchGateWorkspacePage },
            // Logistics OS
            // Shipping OS Redesign (TASK-ECOS-SHIPPING-OS-REDESIGN-001) — the six approved
            // primary Shipping workspaces (nav order in module-navigation.ts). Full old→new
            // reconciliation matrix: E:\ECOS\reports\TASK-ECOS-SHIPPING-OS-REDESIGN-001-REPORT.md
            { path: ROUTES.shippingControlTower, Component: ShippingControlTowerPage },
            { path: ROUTES.shippingDispatchExecution, Component: ShippingDispatchExecutionPage },
            { path: ROUTES.shippingLiveDriverMap, Component: ShippingLiveDriverMapPage },
            { path: ROUTES.shippingReturnsSettlement, Component: ShippingReturnsSettlementPage },
            { path: ROUTES.shippingFleetConfiguration, Component: ShippingFleetConfigurationPage },
            // Fleet & Configuration's tabs render Geography/Zones/Carriers/Automation/Fuel/
            // Companies/Drivers/Vehicles/Fleet directly (same components, imported inside that
            // workspace's own feature folder) — old standalone routes redirect, no functionality
            // lost, no component deleted.
            { path: ROUTES.logisticsGeography, loader: () => redirect(`${ROUTES.shippingFleetConfiguration}?tab=geography`) },
            { path: ROUTES.logisticsDistributionZones, loader: () => redirect(`${ROUTES.shippingFleetConfiguration}?tab=zones`) },
            // Distribution Planning = the canonical Distribution Workspace redesign (Group-first:
            // Eligible Orders → Window → Group + Loading Prep → Vehicle/Driver → Review/Finalize),
            // backed by the current canonical DistributionWindowController (/logistics/distribution/*).
            // Kept live, unredirected: Dispatch & Execution deep-links into it (full multi-step
            // workspace, not re-embedded as a compact tab — task §4 domain-authority separation).
            { path: ROUTES.logisticsDistributionWorkspace, Component: DistributionWorkspacePage },
            // The old zone-status planning page is retired as the primary workspace; its deep link
            // redirects to the canonical workspace. The DistributionPlanningController + its
            // /logistics/distribution/planning API remain untouched (CTO retirement decision pending).
            { path: ROUTES.logisticsDistributionPlanning, loader: () => redirect(ROUTES.logisticsDistributionWorkspace) },
            // Driver-Day-Settlement — the enterprise-side settlement workspace + per-assignment
            // detail. Kept live, unredirected: Returns & Settlement deep-links into it.
            { path: ROUTES.logisticsDriverSettlement, Component: DriverSettlementWorkspacePage },
            { path: ROUTES.logisticsDriverSettlementDetail, Component: DriverSettlementDetailPage },
            // Trips Workspace — previously orphaned (no nav entry anywhere in the app). Now
            // reachable via Dispatch & Execution's Active Trips tab and Returns & Settlement's
            // Returning-to-Warehouse/Discrepancies/Cash-Handover tabs. Kept live, unredirected.
            { path: ROUTES.logisticsTrips, Component: TripsWorkspacePage },
            { path: ROUTES.logisticsCarrierAccounts, loader: () => redirect(`${ROUTES.shippingFleetConfiguration}?tab=carriers`) },
            { path: ROUTES.logisticsAutomation, loader: () => redirect(`${ROUTES.shippingFleetConfiguration}?tab=automation`) },
            // Intelligence → Control Tower's secondary Executive/Analytics tab (task §7 — must
            // not dominate the main operational view).
            { path: ROUTES.logisticsIntelligence, loader: () => redirect(`${ROUTES.shippingControlTower}?tab=analytics`) },
            { path: ROUTES.logisticsFuelReview, loader: () => redirect(`${ROUTES.shippingFleetConfiguration}?tab=fuel`) },
            // Dispatch Board → the new unified Dispatch & Execution workspace (task §3).
            { path: ROUTES.logisticsDispatchBoard, loader: () => redirect(ROUTES.shippingDispatchExecution) },
            { path: ROUTES.logisticsShippingCompanies, loader: () => redirect(`${ROUTES.shippingFleetConfiguration}?tab=companies`) },
            { path: ROUTES.logisticsDrivers, loader: () => redirect(`${ROUTES.shippingFleetConfiguration}?tab=drivers`) },
            { path: ROUTES.logisticsVehicles, loader: () => redirect(`${ROUTES.shippingFleetConfiguration}?tab=vehicles`) },
            // Delivery & Tracking's own content (out-for-delivery/failed/SLA-breached/returning
            // exceptions) is superseded by Shipping Orders' classification tabs (the same
            // assigned/out-for-delivery/delivered/postponed/no-answer/cancelled exception set,
            // backend-authoritative) plus Control Tower's Needs Attention deep links. Not named
            // in the task's own consolidation table (§3) — a judgment call made during full
            // reconciliation ("reconcile ALL current Shipping pages" — §3); page file untouched.
            { path: ROUTES.logisticsDelivery, loader: () => redirect(ROUTES.shippingControlTower) },
            { path: ROUTES.logisticsFleet, loader: () => redirect(`${ROUTES.shippingFleetConfiguration}?tab=fleet`) },
            { path: ROUTES.logisticsNetwork, Component: ServiceAreasPage },
            // Command Center → Control Tower (task §3).
            { path: ROUTES.logisticsDispatch, loader: () => redirect(ROUTES.shippingControlTower) },
            // Operations Center → Control Tower (task §3 — reconciles into Control Tower).
            { path: ROUTES.logisticsOperations, loader: () => redirect(ROUTES.shippingControlTower) },
            // Execution (old) → the new unified Dispatch & Execution workspace (task §3).
            { path: ROUTES.logisticsDispatchExecution, loader: () => redirect(ROUTES.shippingDispatchExecution) },
            // Dashboards / Enterprise Readiness → Control Tower's Executive/Analytics tab.
            { path: ROUTES.logisticsOpsDashboards, loader: () => redirect(`${ROUTES.shippingControlTower}?tab=analytics`) },
            // Alert Center → Control Tower's Needs Attention tab (task §3/§6).
            { path: ROUTES.logisticsOpsAlerts, loader: () => redirect(`${ROUTES.shippingControlTower}?tab=attention`) },
            // Activity & Audit is deliberately NOT redirected — task §3 makes it a contextual
            // history/audit surface, not primary navigation. Stays live at its own URL, linked
            // contextually from Control Tower's Analytics tab.
            { path: ROUTES.logisticsOpsActivity, Component: ActivityCenterPage },
            { path: ROUTES.logisticsOpsReadiness, loader: () => redirect(`${ROUTES.shippingControlTower}?tab=analytics`) },
            // Enterprise Workspace → Control Tower's Executive/Analytics tab (task §3/§7).
            { path: ROUTES.logisticsEnterprise, loader: () => redirect(`${ROUTES.shippingControlTower}?tab=analytics`) },
            // Marketing OS
            { path: ROUTES.marketing, Component: MarketingDashboardPage },
            { path: ROUTES.marketingAssets, Component: MarketingAssetsPage },
            { path: ROUTES.marketingConnectMeta, Component: MetaConnectPage },
            { path: ROUTES.marketingMetaConnection, Component: MetaConnectionPage },
            { path: ROUTES.marketingCampaigns, Component: CampaignsWorkspacePage },
            { path: ROUTES.marketingCampaignDash, Component: CampaignExecutiveDashboardPage },
            { path: ROUTES.marketingInitiatives, Component: InitiativesWorkspacePage },
            { path: ROUTES.marketingInitiativeDash, Component: InitiativeExecutiveViewPage },
            // Marketing Intelligence
            { path: ROUTES.marketingIntelligence, Component: ExecutiveDashboardPage },
            { path: ROUTES.marketingCampaignAnalytics, Component: CampaignAnalyticsPage },
            { path: ROUTES.marketingAdAnalytics, Component: AdAnalyticsPage },
            { path: ROUTES.marketingCreativeAnalytics, Component: CreativeAnalyticsPage },
            { path: ROUTES.marketingTrends, Component: PerformanceTrendsPage },
            { path: ROUTES.marketingBudget, Component: BudgetAnalysisPage },
            { path: ROUTES.marketingReports, Component: ReportsPage },
            // Marketing Settings
            { path: ROUTES.marketingSettings, Component: MarketingSettingsPage },
            // Campaign Studio
            { path: ROUTES.campaignStudio, Component: CampaignStudioPage },
            { path: ROUTES.campaignGovernance, Component: CampaignGovernancePage },
            { path: ROUTES.campaignStudioDashboard, Component: StudioExecutiveDashboardPage },
            // Core Platform — Business Attribution Engine
            { path: ROUTES.businessAttribution, Component: JourneyExplorerPage },
            { path: ROUTES.baeTimeline, Component: BaeTimelinePage },
            // Customer Engagement Platform
            { path: ROUTES.customerEngagement, Component: UnifiedInboxPage },
            { path: ROUTES.cepDashboard, Component: CepDashboardPage },
            { path: ROUTES.cepLeads, Component: CepLeadsPage },
            // Omnichannel Commerce (MKT-007)
            { path: ROUTES.omnichannelInbox, Component: OmnichannelInboxPage },
            { path: ROUTES.omnichannelDashboard, Component: ConversationsDashboardPage },
            { path: ROUTES.omnichannelMacros, Component: MacrosPage },
            { path: ROUTES.omnichannelRouting, Component: RoutingRulesPage },
            { path: ROUTES.omnichannelProviders, Component: ChannelProvidersPage },
            // Marketing Automation Platform
            { path: ROUTES.automationWorkspace, Component: AutomationWorkspacePage },
            { path: ROUTES.workflowBuilder, Component: WorkflowBuilderPage },
            { path: ROUTES.audienceSegments, Component: AudienceSegmentsPage },
            { path: ROUTES.automationDashboard, Component: AutomationDashboardPage },
            { path: ROUTES.automationGovernance, Component: AutomationGovernancePage },
            // Commerce
            { path: ROUTES.productMappings, Component: ProductMappingsPage },
            { path: ROUTES.syncLogs, Component: SyncLogsPage },
            // Materials sub-sections
            { path: ROUTES.packagingMaterials, Component: PackagingMaterialsPage },
            { path: ROUTES.consumables, Component: ConsumablesPage },
            { path: ROUTES.semiFinishedMaterials, Component: SemiFinishedMaterialsPage },
            // Recipes (canonical home: Inventory)
            { path: ROUTES.recipes, Component: RecipesPage },
            { path: ROUTES.recipesNew, Component: RecipeWorkspacePage },
            { path: `${ROUTES.recipes}/:id/edit`, Component: RecipeWorkspacePage },
            { path: `${ROUTES.recipes}/:id`, Component: RecipeWorkspacePage },
            // Legacy BOM routes — redirect to canonical recipe paths
            { path: ROUTES.boms, loader: () => redirect(ROUTES.recipes) },
            { path: ROUTES.bomsNew, loader: () => redirect(ROUTES.recipesNew) },
            { path: `${ROUTES.boms}/:id/edit`, Component: BomWorkspacePage },
            { path: `${ROUTES.boms}/:id`, Component: BomWorkspacePage },
            // Cost management
            { path: ROUTES.costManagement, Component: CostManagementDashboardPage },
            { path: ROUTES.costManagementPriceReview, Component: CostPricingCenterPage },
            { path: ROUTES.costManagementCostHistory, Component: CostHistoryPage },
            // Preparation Workspace (TASK-PREPARATION-WORKSPACE-FIX-003 §3) — one
            // sidebar destination whose top-level tabs are Today's Preparation,
            // Archive and Settings. Child paths are unchanged, so existing deep
            // links keep resolving; only the shell around them is new.
            {
              path: ROUTES.preparationWorkspace,
              Component: PreparationWorkspaceLayout,
              children: [
                // Bare /operations/preparation lands on Today's Preparation rather
                // than rendering the shell with an empty body.
                { index: true, loader: () => redirect(ROUTES.waveWorkspace) },
                // Fulfillment Wave Workspace (TASK-PREP-UI-003 + TASK-PREP-UI-004)
                {
                  path: ROUTES.waveWorkspace,
                  Component: WaveWorkspaceLayout,
                  children: [
                    // Today's Preparation opens directly on the Active tab (§8), preserving
                    // any wave_id already on the URL. The layout then resolves the current
                    // wave when none is supplied (§3).
                    {
                      index: true,
                      loader: ({ request }) => {
                        const w = new URL(request.url).searchParams.get('wave_id');
                        return redirect(
                          w
                            ? `${ROUTES.waveProductDemand}?wave_id=${encodeURIComponent(w)}`
                            : ROUTES.waveProductDemand,
                        );
                      },
                    },
                    { path: 'products', Component: WaveProductDemandPage },
                    // The former default landing, preserved as an explicit Overview tab.
                    { path: 'overview', Component: FulfillmentWaveWorkspacePage },
                    { path: 'materials', Component: WaveRawMaterialsPage },
                    { path: 'missing', Component: WaveMissingMaterialsPage },
                    { path: 'deficit-decisions', Component: DeficitDecisionsPage },
                    { path: 'wave-orders', Component: WaveOrdersPage },
                    { path: 'settings', Component: WaveSettingsPage },
                  ],
                },
                // Wave Archive / History — older and terminal waves stay reachable here
                // once the operational selector narrows to the 3 most recent active waves.
                { path: ROUTES.waveArchive, Component: WaveArchivePage },
                // Wave Engine — the operational cycle configuration (start / cutoff / end).
                { path: ROUTES.waveEngine, Component: WaveEngineSettingsPage },
              ],
            },
            // Claude Bridge
            { path: ROUTES.claudeBridge, Component: BridgeDashboardPage },
            { path: ROUTES.claudeBridgeTasks, Component: BridgeTasksPage },
            { path: ROUTES.claudeBridgeTasksNew, Component: BridgeCreateTaskPage },
            { path: ROUTES.claudeBridgeTaskDetail, Component: BridgeTaskDetailPage },
            { path: ROUTES.claudeBridgeSettings, Component: BridgeSettingsPage },
            // Engineering OS (TASK-ENG-005 / TASK-ENG-006)
            { path: ROUTES.engineeringDashboard, Component: EngineeringDashboardPage },
            { path: ROUTES.engineeringRuns, Component: EngineeringRunsPage },
            { path: ROUTES.engineeringFindings, Component: EngineeringFindingsPage },
            { path: ROUTES.engineeringPipeline, Component: EngineeringPipelinePage },
            { path: ROUTES.engineeringPipelineHistory, Component: EngineeringPipelineHistoryPage },
            { path: ROUTES.engineeringNotifications, Component: EngineeringNotificationsPage },
            { path: ROUTES.engineeringAnalytics, Component: EngineeringAnalyticsPage },
            { path: ROUTES.engineeringCluster, Component: ClusterDashboardPage },
            { path: ROUTES.engineeringReleases, Component: ReleaseDashboardPage },
            { path: ROUTES.engineeringAiSupervisor, Component: AIEngineeringWorkspacePage },
            { path: ROUTES.engineeringRepair, Component: RepairSessionsPage },
            { path: ROUTES.engineeringWorkspace, Component: EnterpriseWorkspacePage },
            // IAM Management (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §4) — one
            // shared page, three routes, now behind ONE sidebar entry ("IAM Management")
            // instead of the duplicate "Users" + "Roles & Permissions" pair that pointed at
            // this same page. The three routes are kept: each tab stays independently
            // deep-linkable and bookmarkable, and existing links keep resolving.
            { path: ROUTES.users, Component: IamWorkspacePage },
            { path: ROUTES.roles, Component: IamWorkspacePage },
            { path: ROUTES.roleTemplates, Component: IamWorkspacePage },
            // Driver routes moved to the DriverShell sibling above (TASK-DRIVER-SHELL-FINAL-CLOSURE-001).
            ...moduleRoutes,
            // UAT BUG-04 — the menu's "Settings" entry pointed at a Coming Soon
            // placeholder while the real workspace lived elsewhere. Redirect so
            // the existing link resolves instead of dead-ending.
            { path: ROUTES.settings, loader: () => redirect(ROUTES.configurationOs) },
            // TASK-ECOS-NOTIFICATIONS-USER-REVIEW-VISIBILITY-REMEDIATION-009 — personal,
            // ownership-scoped (no module/permission gate: every authenticated user may
            // reach their own preferences, matching /me/preferences/{category}'s own
            // auth:sanctum-only backend contract). Deliberately not nested under
            // moduleRoutes/Administration, which would inherit that module's iam/
            // organization/configuration permission gate and hide this from exactly the
            // ordinary users who need it.
            { path: ROUTES.myPreferences, Component: MyPreferencesPage },
            // D1 (TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005)
            // — same personal, gate-free registration as myPreferences directly above:
            // every authenticated user may reach their own notification settings.
            { path: ROUTES.notificationSettings, Component: NotificationSettingsPage },
            { path: '*', Component: NotFoundPage },
          ],
        },
      ],
    },
  ],
  { basename: import.meta.env.BASE_URL },
);
