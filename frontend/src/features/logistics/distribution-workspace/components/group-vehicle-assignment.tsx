import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Link } from 'react-router-dom';

import { AlertTriangle, IdCard, Truck } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';

import { ROUTES } from '@/router/routes';

import {
  useAssignGroupVehicle,
  useGroupFleetOptions,
  useGroupTrips,
} from '../hooks/use-distribution-workspace';

/**
 * VP-1 — Vehicle + Driver assignment for one Distribution Group.
 *
 * TASK-DISTRIBUTION-VEHICLE-DRIVER-TRIP-FINAL-UX-003 — presentation only. The
 * component now leads with the CURRENT assignment, read from the canonical
 * pairing-aware Trip (`useGroupTrips` → the trip carrying `driver_vehicle_assignment_id`),
 * in one compact card; the vehicle/driver dropdowns open only behind "Change
 * assignment" / "Assign Vehicle & Driver". Three honest states — Assigned, Not
 * assigned, and Unable to load (a read failure is never rendered as "Not assigned").
 *
 * NOTHING ABOUT THE WRITE PATH CHANGES. The same `useAssignGroupVehicle` mutation,
 * the same `assign-vehicle` endpoint, the same server-decided capacity (`fits_group`)
 * and the same certified driver eligibility (`driver_ids`) are reused verbatim. Success
 * is proven by the canonical refetch (the mutation invalidates `KEYS.all`, under which
 * `useGroupTrips` lives), never by local state.
 */
export function GroupVehicleAssignment({
  windowId,
  slotId,
  canPlan,
}: {
  windowId: string | undefined;
  slotId: string | undefined;
  canPlan: boolean;
}) {
  const { t } = useTranslation('logistics');

  const [editing, setEditing] = useState(false);
  const [vehicleId, setVehicleId] = useState<string>('');
  const [driverId, setDriverId] = useState<string>('');

  // The current assignment — the CANONICAL pairing-aware trip, the same query
  // Group Details and the Trip tab read (shared cache, one source of truth).
  const tripsQuery = useGroupTrips(windowId, slotId);
  const currentTrip =
    (tripsQuery.data?.trips ?? []).find((tr) => tr.driver_vehicle_assignment_id !== null) ?? null;
  const hasAssignment =
    currentTrip !== null && (currentTrip.vehicle !== null || currentTrip.driver !== null);

  // Fleet options are fetched ONLY while the form is open.
  const options = useGroupFleetOptions(windowId, slotId, editing);
  const assign = useAssignGroupVehicle();

  const vehicles = useMemo(() => options.data?.vehicles ?? [], [options.data]);
  const allDrivers = useMemo(() => options.data?.drivers ?? [], [options.data]);
  const selected = useMemo(
    () => vehicles.find((v) => v.id === vehicleId) ?? null,
    [vehicles, vehicleId],
  );

  /**
   * The Driver selector DEPENDS on the Vehicle: only drivers actively paired to the
   * chosen vehicle in the canonical ledger are offered (`driver_ids`, server-decided),
   * so the dropdown cannot disagree with what the write path accepts.
   */
  const eligibleDrivers = useMemo(() => {
    if (selected === null) return [];
    const allowed = new Set(selected.driver_ids ?? []);
    return allDrivers.filter((d) => allowed.has(d.id));
  }, [selected, allDrivers]);

  function onVehicleChange(nextVehicleId: string) {
    setVehicleId(nextVehicleId);
    const next = vehicles.find((v) => v.id === nextVehicleId) ?? null;
    const allowed = new Set(next?.driver_ids ?? []);
    setDriverId((current) => (current !== '' && allowed.has(current) ? current : ''));
  }

  const groupOrders = options.data?.group_orders ?? 0;
  const noFleet = vehicles.length === 0 || allDrivers.length === 0;

  function openForm() {
    assign.reset();
    setEditing(true);
  }

  function closeForm() {
    setEditing(false);
    setVehicleId('');
    setDriverId('');
    assign.reset();
  }

  function submit() {
    if (!windowId || !slotId) return;
    assign.mutate(
      { windowId, slotId, vehicleId, driverId },
      {
        // Proof of success is the canonical refetch (the mutation invalidates the
        // workspace root); collapse back to the card and let it read getGroupTrips.
        onSuccess: () => {
          setEditing(false);
          setVehicleId('');
          setDriverId('');
        },
      },
    );
  }

  const header = (
    <div className="flex items-center gap-2">
      <Truck className="size-4 text-muted-foreground" aria-hidden />
      <h3 className="font-medium">{t(($) => $.distributionWorkspace.fleet.title)}</h3>
    </div>
  );

  // ── State 3 — read failure (never "Not assigned") ──────────────────────────
  if (tripsQuery.isError && !editing) {
    return (
      <div className="space-y-3" data-testid="group-vehicle-assignment">
        {header}
        <div className="rounded-lg border p-4" data-testid="group-assignment-unavailable">
          <p className="text-sm text-destructive">
            {t(($) => $.distributionWorkspace.phase1.unableToLoad)}
          </p>
          <Button
            variant="outline"
            size="sm"
            className="mt-2"
            onClick={() => void tripsQuery.refetch()}
            data-testid="group-assignment-retry"
          >
            {t(($) => $.distributionWorkspace.pool.retry)}
          </Button>
        </div>
      </div>
    );
  }

  // ── Editing — the existing certified assignment form ───────────────────────
  if (editing) {
    return (
      <div className="space-y-4" data-testid="group-vehicle-assignment">
        {header}

        {options.isLoading ? (
          <Skeleton className="h-40 w-full" data-testid="group-fleet-loading" />
        ) : options.isError ? (
          <p className="text-sm text-destructive" data-testid="group-fleet-error">
            {t(($) => $.distributionWorkspace.fleet.loadFailed)}
          </p>
        ) : noFleet ? (
          <div className="space-y-3" data-testid="group-fleet-empty">
            <p className="text-sm text-muted-foreground">
              {t(($) => $.distributionWorkspace.fleet.emptyFleet)}
            </p>
            <div className="flex flex-wrap gap-2">
              <Button asChild variant="outline" size="sm" data-testid="group-fleet-manage-vehicles">
                <Link to={ROUTES.logisticsVehicles}>
                  <Truck className="me-1.5 size-3.5" aria-hidden />
                  {t(($) => $.distributionWorkspace.fleet.manageVehicles)}
                </Link>
              </Button>
              <Button asChild variant="outline" size="sm" data-testid="group-fleet-manage-drivers">
                <Link to={ROUTES.logisticsDrivers}>
                  <IdCard className="me-1.5 size-3.5" aria-hidden />
                  {t(($) => $.distributionWorkspace.fleet.manageDrivers)}
                </Link>
              </Button>
            </div>
            <p className="text-xs text-muted-foreground">
              {t(($) => $.distributionWorkspace.fleet.emptyBreakdown, {
                vehicles: vehicles.length,
                drivers: allDrivers.length,
              })}
            </p>
            <Button variant="ghost" size="sm" onClick={closeForm} data-testid="group-assign-cancel">
              {t(($) => $.common.cancel)}
            </Button>
          </div>
        ) : (
          <>
            {/* ── Vehicle ─────────────────────────────────────────────────── */}
            <div className="space-y-1.5">
              <label className="text-xs uppercase tracking-wide text-muted-foreground">
                {t(($) => $.distributionWorkspace.fleet.vehicleLabel)}
              </label>
              <Select value={vehicleId} onValueChange={onVehicleChange} disabled={!canPlan}>
                <SelectTrigger data-testid="group-vehicle-select">
                  <SelectValue
                    placeholder={t(($) => $.distributionWorkspace.fleet.vehiclePlaceholder)}
                  />
                </SelectTrigger>
                <SelectContent>
                  {vehicles.map((v) => (
                    <SelectItem key={v.id} value={v.id}>
                      {v.plate_number ?? v.name ?? v.id}
                      {' · '}
                      {t(($) => $.distributionWorkspace.fleet.capacityOrders, {
                        count: v.capacity_orders,
                      })}
                      {v.fits_group ? '' : ` · ${t(($) => $.distributionWorkspace.fleet.tooSmall)}`}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* ── Driver ──────────────────────────────────────────────────── */}
            <div className="space-y-1.5">
              <label className="text-xs uppercase tracking-wide text-muted-foreground">
                {t(($) => $.distributionWorkspace.fleet.driverLabel)}
              </label>
              <Select
                value={driverId}
                onValueChange={setDriverId}
                disabled={!canPlan || selected === null || eligibleDrivers.length === 0}
              >
                <SelectTrigger data-testid="group-driver-select">
                  <SelectValue
                    placeholder={
                      selected === null
                        ? t(($) => $.distributionWorkspace.fleet.driverSelectVehicleFirst)
                        : t(($) => $.distributionWorkspace.fleet.driverPlaceholder)
                    }
                  />
                </SelectTrigger>
                <SelectContent>
                  {eligibleDrivers.map((d) => (
                    <SelectItem key={d.id} value={d.id}>
                      {d.full_name ?? d.driver_code ?? d.id}
                      {d.driver_code ? ` · ${d.driver_code}` : ''}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              {selected !== null && eligibleDrivers.length === 0 ? (
                <p
                  className="text-xs text-amber-700 dark:text-amber-400"
                  data-testid="group-driver-none"
                >
                  {t(($) => $.distributionWorkspace.fleet.noDriversForVehicle)}
                </p>
              ) : null}
            </div>

            {/* ── The capacity statement ──────────────────────────────────── */}
            {selected ? (
              <dl
                className="grid grid-cols-3 gap-3 rounded-lg border bg-muted/40 p-3 text-sm"
                data-testid="group-capacity-preview"
              >
                <div>
                  <dt className="text-xs uppercase text-muted-foreground">
                    {t(($) => $.distributionWorkspace.fleet.groupOrders)}
                  </dt>
                  <dd className="font-semibold tabular-nums">{groupOrders}</dd>
                </div>
                <div>
                  <dt className="text-xs uppercase text-muted-foreground">
                    {t(($) => $.distributionWorkspace.fleet.vehicleCapacity)}
                  </dt>
                  <dd className="font-semibold tabular-nums">{selected.capacity_orders}</dd>
                </div>
                <div>
                  <dt className="text-xs uppercase text-muted-foreground">
                    {t(($) => $.distributionWorkspace.fleet.remaining)}
                  </dt>
                  <dd
                    className={
                      selected.fits_group
                        ? 'font-semibold tabular-nums'
                        : 'font-semibold tabular-nums text-destructive'
                    }
                  >
                    {selected.capacity_orders - groupOrders}
                  </dd>
                </div>
              </dl>
            ) : null}

            {selected && !selected.fits_group ? (
              <p
                className="flex items-start gap-2 text-sm text-destructive"
                data-testid="group-capacity-warning"
              >
                <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
                {t(($) => $.distributionWorkspace.fleet.overCapacity)}
              </p>
            ) : null}

            {/* The server's rejection, verbatim. */}
            {assign.isError ? (
              <p className="text-sm text-destructive" data-testid="group-assign-error">
                {assign.error instanceof Error
                  ? ((assign.error as { response?: { data?: { message?: string } } }).response?.data
                      ?.message ?? assign.error.message)
                  : t(($) => $.distributionWorkspace.fleet.assignFailed)}
              </p>
            ) : null}

            <div className="flex flex-wrap gap-2">
              <Button
                data-testid="group-assign-vehicle"
                disabled={
                  !canPlan ||
                  !vehicleId ||
                  !driverId ||
                  assign.isPending ||
                  (selected !== null && !selected.fits_group)
                }
                onClick={submit}
              >
                {t(($) => $.distributionWorkspace.fleet.assign)}
              </Button>
              <Button
                variant="ghost"
                onClick={closeForm}
                disabled={assign.isPending}
                data-testid="group-assign-cancel"
              >
                {t(($) => $.common.cancel)}
              </Button>
            </div>
          </>
        )}
      </div>
    );
  }

  // ── State 1 — Assigned (compact card) ──────────────────────────────────────
  if (hasAssignment && currentTrip) {
    return (
      <div className="space-y-3" data-testid="group-vehicle-assignment">
        {header}

        <div className="rounded-lg border p-4" data-testid="group-assignment-card">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            {/* Vehicle */}
            <div className="min-w-0">
              <div className="text-xs uppercase tracking-wide text-muted-foreground">
                {t(($) => $.common.vehicle)}
              </div>
              <div className="mt-0.5 truncate font-semibold" dir="ltr">
                {currentTrip.vehicle?.plate_number ?? currentTrip.vehicle?.name ?? '—'}
              </div>
              {currentTrip.vehicle?.plate_number && currentTrip.vehicle?.name ? (
                <div className="truncate text-xs text-muted-foreground">
                  {currentTrip.vehicle.name}
                </div>
              ) : null}
            </div>

            {/* Driver */}
            <div className="min-w-0">
              <div className="text-xs uppercase tracking-wide text-muted-foreground">
                {t(($) => $.common.driver)}
              </div>
              <div className="mt-0.5 truncate font-semibold">
                {currentTrip.driver?.full_name ?? '—'}
              </div>
              {currentTrip.driver?.mobile ? (
                <div className="truncate text-xs text-muted-foreground" dir="ltr">
                  {currentTrip.driver.mobile}
                </div>
              ) : null}
            </div>
          </div>

          <div className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t pt-3">
            <span className="text-xs uppercase tracking-wide text-muted-foreground">
              {t(($) => $.distributionWorkspace.phase1.assignmentStatus)}
            </span>
            <Badge variant="secondary" data-testid="group-assignment-status">
              {t(($) => $.distributionWorkspace.phase1.assigned)}
            </Badge>
            <span className="ms-2 text-xs uppercase tracking-wide text-muted-foreground">
              {t(($) => $.distributionWorkspace.phase1.tabTrip)}
            </span>
            <span className="font-medium tabular-nums" dir="ltr">
              {currentTrip.trip_number}
            </span>
          </div>
        </div>

        <Button
          variant="outline"
          size="sm"
          onClick={openForm}
          disabled={!canPlan}
          data-testid="group-change-assignment"
        >
          {t(($) => $.distributionWorkspace.phase1.changeAssignment)}
        </Button>
      </div>
    );
  }

  // ── State 2 — Not assigned (empty state) ───────────────────────────────────
  return (
    <div className="space-y-3" data-testid="group-vehicle-assignment">
      {header}

      {tripsQuery.isLoading ? (
        <Skeleton className="h-24 w-full" data-testid="group-assignment-loading" />
      ) : (
        <div
          className="rounded-lg border p-6 text-center"
          data-testid="group-assignment-empty"
        >
          <p className="text-sm text-muted-foreground">
            {t(($) => $.distributionWorkspace.phase1.notAssignedYet)}
          </p>
          <Button
            size="sm"
            className="mt-3"
            onClick={openForm}
            disabled={!canPlan}
            data-testid="group-assign-open"
          >
            {t(($) => $.distributionWorkspace.phase1.assignVehicleDriver)}
          </Button>
        </div>
      )}
    </div>
  );
}
