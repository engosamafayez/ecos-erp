import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Eye, Plus, UserMinus } from 'lucide-react';

import {
  ActionMenu,
  ConfirmDialog,
  EntityTable,
  EntityToolbar,
  PageHeader,
  Pagination,
  StatusBadge,
} from '@/components/crud';
import type { ColumnDef, StatusVariant } from '@/components/crud/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmployeeFormDrawer } from '@/features/hr/components/employee-form-drawer';
import { useDepartmentsQuery, useEmployeesQuery, useTerminateEmployee } from '@/features/hr/hooks/use-hr';
import type { Employee, EmployeeStatus } from '@/features/hr/types/hr';

const PER_PAGE = 15;

/** Employed statuses read as active or pending; leaving reads as archived. */
const STATUS_TONE: Record<EmployeeStatus, StatusVariant> = {
  active: 'active',
  probation: 'pending',
  on_leave: 'pending',
  suspended: 'inactive',
  resigned: 'archived',
  terminated: 'archived',
};

export function EmployeesPage() {
  const navigate = useNavigate();

  const [search, setSearch] = useState('');
  const [departmentFilter, setDepartmentFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [page, setPage] = useState(1);
  const [formOpen, setFormOpen] = useState(false);
  const [terminating, setTerminating] = useState<Employee | null>(null);

  const params = useMemo(
    () => ({
      search: search || undefined,
      department_id: departmentFilter || undefined,
      status: statusFilter || undefined,
      page,
      per_page: PER_PAGE,
    }),
    [search, departmentFilter, statusFilter, page],
  );

  const { data, isLoading, isError, isFetching, refetch } = useEmployeesQuery(params);
  const { data: departments } = useDepartmentsQuery();
  const terminate = useTerminateEmployee();

  const items = data?.items ?? [];
  const meta = data?.meta;

  const activeCount = items.filter((e) => e.status === 'active').length;
  const onLeaveCount = items.filter((e) => e.status === 'on_leave').length;

  const columns: ColumnDef<Employee>[] = [
    {
      key: 'employee_number',
      header: 'Number',
      cell: (e) => <span className="font-mono text-xs font-medium">{e.employee_number}</span>,
    },
    {
      key: 'name',
      header: 'Name',
      cell: (e) => <span className="font-medium">{e.name}</span>,
    },
    {
      key: 'department',
      header: 'Department',
      cell: (e) => <span className="text-muted-foreground">{e.department?.name ?? '—'}</span>,
    },
    {
      key: 'position',
      header: 'Position',
      cell: (e) => <span className="text-muted-foreground">{e.position?.title ?? '—'}</span>,
    },
    {
      key: 'work_email',
      header: 'Work Email',
      cell: (e) => <span className="text-muted-foreground">{e.work_email ?? '—'}</span>,
    },
    {
      key: 'hire_date',
      header: 'Hired',
      cell: (e) => <span className="tabular-nums">{e.hire_date ?? '—'}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      cell: (e) => <StatusBadge status={STATUS_TONE[e.status]} label={e.status_label} />,
    },
  ];

  const confirmTerminate = async () => {
    if (!terminating) return;
    await terminate.mutateAsync({ id: terminating.id, reason: 'Terminated from the employee list' });
    setTerminating(null);
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Employees"
        subtitle="The single source of truth for workforce identity — referenced by every other module."
        actions={
          <Button size="sm" onClick={() => setFormOpen(true)}>
            <Plus className="size-4" />
            New Employee
          </Button>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Total Employees</div>
            <div className="text-2xl font-bold">{isLoading ? '—' : (meta?.total ?? 0)}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Active (this page)</div>
            <div className="text-2xl font-bold text-emerald-600">{isLoading ? '—' : activeCount}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">On Leave (this page)</div>
            <div className="text-2xl font-bold text-amber-600">{isLoading ? '—' : onLeaveCount}</div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <EntityToolbar
            searchPlaceholder="Search by name, number or email…"
            onSearchChange={(value) => {
              setSearch(value);
              setPage(1);
            }}
            onRefresh={() => void refetch()}
            isRefreshing={isFetching}
            onClearFilters={() => {
              setDepartmentFilter('');
              setStatusFilter('');
              setPage(1);
            }}
            filterPanel={
              <>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Department</span>
                  <select
                    value={departmentFilter}
                    onChange={(e) => {
                      setDepartmentFilter(e.target.value);
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    <option value="">All Departments</option>
                    {(departments ?? []).map((d) => (
                      <option key={d.id} value={d.id}>
                        {d.name}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="flex flex-col gap-1.5">
                  <span className="text-sm font-medium">Status</span>
                  <select
                    value={statusFilter}
                    onChange={(e) => {
                      setStatusFilter(e.target.value);
                      setPage(1);
                    }}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
                  >
                    <option value="">All</option>
                    <option value="active">Active</option>
                    <option value="probation">Probation</option>
                    <option value="on_leave">On Leave</option>
                    <option value="suspended">Suspended</option>
                    <option value="resigned">Resigned</option>
                    <option value="terminated">Terminated</option>
                  </select>
                </div>
              </>
            }
          />

          <EntityTable<Employee>
            columns={columns}
            data={items}
            getRowId={(e) => e.id}
            isLoading={isLoading}
            isError={isError}
            rowActions={(employee) => (
              <ActionMenu
                label={`Actions for ${employee.name}`}
                items={[
                  {
                    key: 'view',
                    label: 'Employee 360',
                    icon: Eye,
                    onSelect: () => navigate(`/hr/employees/${employee.id}`),
                  },
                  {
                    key: 'terminate',
                    label: 'End Employment',
                    icon: UserMinus,
                    variant: 'destructive',
                    onSelect: () => setTerminating(employee),
                  },
                ]}
              />
            )}
          />

          {meta ? (
            <Pagination
              meta={{
                page: meta.current_page,
                perPage: meta.per_page,
                total: meta.total,
                lastPage: meta.last_page,
              }}
              onPageChange={setPage}
            />
          ) : null}
        </CardContent>
      </Card>

      <EmployeeFormDrawer open={formOpen} onOpenChange={setFormOpen} />

      <ConfirmDialog
        open={terminating !== null}
        onOpenChange={(open) => {
          if (!open) setTerminating(null);
        }}
        title="End Employment"
        description={`End ${terminating?.name ?? ''}'s employment? This is final — a returning employee is rehired, not restored.`}
        confirmLabel="End Employment"
        variant="destructive"
        loading={terminate.isPending}
        onConfirm={confirmTerminate}
      />
    </div>
  );
}
