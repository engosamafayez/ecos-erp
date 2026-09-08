import { useCallback } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';

import { PageHeader } from '@/components/crud';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

import { VehiclesPage } from '@/features/logistics/vehicles/pages/vehicles-page';
import { DriversPage } from '@/features/logistics/drivers/pages/drivers-page';
import { ShippingCompaniesPage } from '@/features/logistics/shipping-companies/pages/shipping-companies-page';
import { CarrierAccountsPage } from '@/features/logistics/carriers/pages/carrier-accounts-page';
import { FuelReviewPage } from '@/features/logistics/fleet/pages/fuel-review-page';
import { DistributionZonesPage } from '@/features/logistics/distribution-zones/pages/distribution-zones-page';
import { EgyptGeographyPage } from '@/features/logistics/geography/pages/egypt-geography-page';
import { AutomationMonitoringPage } from '@/features/logistics/automation/pages/automation-monitoring-page';
import { FleetDashboardPage } from '@/features/logistics/fleet/pages/fleet-dashboard-page';

/**
 * Fleet Configuration workspace.
 *
 * TASK-ECOS-SHIPPING-OS-REDESIGN-001 — this workspace consolidates nine
 * previously-standalone configuration screens under one set of tabs, selected
 * via `?tab=`. Unlike the other new Shipping workspaces, every tab here
 * renders an EXISTING, unmodified page component as-is: this file owns no
 * data-fetching and no CRUD logic of its own, only the tab shell and the
 * routing between tabs. Each embedded page still renders its own internal
 * header — a minor visual nesting accepted deliberately, since editing those
 * live pages is out of scope here.
 *
 * The old standalone routes (e.g. `/logistics/vehicles`) redirect here with
 * a matching `tab` value wired elsewhere, so the tab key strings below are a
 * contract with those redirects — do not rename them.
 *
 * Radix's TabsContent only mounts the active panel's children by default
 * (no `forceMount` is passed here), so switching tabs — not page load — is
 * what triggers each embedded page's own data hooks. That keeps nine
 * independent CRUD screens from all fetching simultaneously on first render.
 */

const TAB_KEYS = [
  'vehicles',
  'drivers',
  'companies',
  'carriers',
  'fuel',
  'zones',
  'geography',
  'automation',
  'fleet',
] as const;

type TabKey = (typeof TAB_KEYS)[number];

const DEFAULT_TAB: TabKey = 'vehicles';

function isTabKey(value: string | null): value is TabKey {
  return value !== null && (TAB_KEYS as readonly string[]).includes(value);
}

export function FleetConfigurationPage() {
  const { t } = useTranslation('fleet-configuration');
  const [searchParams, setSearchParams] = useSearchParams();

  const requestedTab = searchParams.get('tab');
  const tab: TabKey = isTabKey(requestedTab) ? requestedTab : DEFAULT_TAB;

  const handleTabChange = useCallback(
    (next: string) => {
      setSearchParams({ tab: next }, { replace: true });
    },
    [setSearchParams],
  );

  return (
    <div className="flex flex-col gap-4 p-4">
      <PageHeader title={t(($) => $.pageTitle)} />

      <Tabs value={tab} onValueChange={handleTabChange}>
        <TabsList className="flex-wrap">
          <TabsTrigger value="vehicles" data-testid="tab-vehicles">
            {t(($) => $.tabs.vehicles)}
          </TabsTrigger>
          <TabsTrigger value="drivers" data-testid="tab-drivers">
            {t(($) => $.tabs.drivers)}
          </TabsTrigger>
          <TabsTrigger value="companies" data-testid="tab-companies">
            {t(($) => $.tabs.companies)}
          </TabsTrigger>
          <TabsTrigger value="carriers" data-testid="tab-carriers">
            {t(($) => $.tabs.carriers)}
          </TabsTrigger>
          <TabsTrigger value="fuel" data-testid="tab-fuel">
            {t(($) => $.tabs.fuel)}
          </TabsTrigger>
          <TabsTrigger value="zones" data-testid="tab-zones">
            {t(($) => $.tabs.zones)}
          </TabsTrigger>
          <TabsTrigger value="geography" data-testid="tab-geography">
            {t(($) => $.tabs.geography)}
          </TabsTrigger>
          <TabsTrigger value="automation" data-testid="tab-automation">
            {t(($) => $.tabs.automation)}
          </TabsTrigger>
          <TabsTrigger value="fleet" data-testid="tab-fleet">
            {t(($) => $.tabs.fleet)}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="vehicles" className="mt-3">
          <VehiclesPage />
        </TabsContent>
        <TabsContent value="drivers" className="mt-3">
          <DriversPage />
        </TabsContent>
        <TabsContent value="companies" className="mt-3">
          <ShippingCompaniesPage />
        </TabsContent>
        <TabsContent value="carriers" className="mt-3">
          <CarrierAccountsPage />
        </TabsContent>
        <TabsContent value="fuel" className="mt-3">
          <FuelReviewPage />
        </TabsContent>
        <TabsContent value="zones" className="mt-3">
          <DistributionZonesPage />
        </TabsContent>
        <TabsContent value="geography" className="mt-3">
          <EgyptGeographyPage />
        </TabsContent>
        <TabsContent value="automation" className="mt-3">
          <AutomationMonitoringPage />
        </TabsContent>
        <TabsContent value="fleet" className="mt-3">
          <FleetDashboardPage />
        </TabsContent>
      </Tabs>
    </div>
  );
}
