import { useMemo, useState } from 'react';
import { Check, Plus, X } from 'lucide-react';

import { ConfirmDialog, EntityDrawer, PageHeader, StatusBadge } from '@/components/crud';
import type { StatusVariant } from '@/components/crud/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDecideLeave, useEmployeesQuery, useLeaveRequestsQuery, useSubmitLeave } from '@/features/hr/hooks/use-hr';
import type { LeavePayrollFlag, LeaveRequest, LeaveStatus } from '@/features/hr/types/hr';

const STATUS_TONE: Record<LeaveStatus, StatusVariant> = {
  pending: 'pending',
  approved: 'active',
  rejected: 'inactive',
  cancelled: 'archived',
};

const today = () => new Date().toISOString().slice(0, 10);

/**
 * Leave Requests — submission and manager approval.
 *
 * Approving writes the covered days onto the attendance record, so the
 * availability dashboard reflects the decision immediately. The payroll flag
 * travels with the request: HR states whether the days are deducted, Payroll
 * works out what that costs.
 */
export function LeaveRequestsPage() {
  const [statusFilter, setStatusFilter] = useState('pending');
  const [formOpen, setFormOpen] = useState(false);
  const [deciding, setDeciding] = useState<{ request: LeaveRequest; decision: 'approve' | 'reject' } | null>(null);

  const [form, setForm] = useState({
    employee_id: '',
    start_date: today(),
    end_date: today(),
    payroll_flag: 'deduct_salary' as LeavePayrollFlag,
    reason: '',
  });
  const [error, setError] = useState<string | null>(null);

  const params = useMemo(() => ({ status: statusFilter || undefined }), [statusFilter]);
  const { data: requests, isLoading } = useLeaveRequestsQuery(params);
  const { data: employees } = useEmployeesQuery({ per_page: 100, status: 'active' });
  const submit = useSubmitLeave();
  const decide = useDecideLeave();

  const rows = requests ?? [];
  const pendingCount = rows.filter((r) => r.status === 'pending').length;

  const submitRequest = async () => {
    setError(null);

    if (!form.employee_id) {
      setError('Choose an employee.');
      return;
    }

    try {
      await submit.mutateAsync({ ...form, reason: form.reason || undefined });
      setForm({ ...form, reason: '', employee_id: '' });
      setFormOpen(false);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The request could not be submitted.');
    }
  };

  const confirmDecision = async () => {
    if (!deciding) return;
    await decide.mutateAsync({ id: deciding.request.id, decision: deciding.decision });
    setDeciding(null);
  };

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Leave Requests"
        subtitle="Approving writes the days onto attendance. The payroll flag tells Payroll whether they are deducted."
        actions={
          <Button size="sm" onClick={() => setFormOpen(true)}>
            <Plus className="size-4" />
            New Request
          </Button>
        }
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Showing</div>
            <div className="text-2xl font-bold">{isLoading ? '—' : rows.length}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Awaiting Approval</div>
            <div className="text-2xl font-bold text-amber-600">{isLoading ? '—' : pendingCount}</div>
          </CardContent>
        </Card>
        <Card>
          <CardContent className="pt-6">
            <div className="text-muted-foreground text-sm">Days Requested</div>
            <div className="text-2xl font-bold">
              {isLoading ? '—' : rows.reduce((sum, r) => sum + r.days_count, 0)}
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardContent className="flex flex-col gap-4 pt-6">
          <div className="flex items-center gap-2">
            <span className="text-sm font-medium">Status</span>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
              className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
            >
              <option value="">All</option>
              <option value="pending">Pending</option>
              <option value="approved">Approved</option>
              <option value="rejected">Rejected</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>

          {rows.length === 0 ? (
            <p className="text-muted-foreground py-8 text-center text-sm">No leave requests to show.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="text-muted-foreground border-b text-left text-xs uppercase">
                  <tr>
                    <th className="py-2 pr-4 font-medium">Number</th>
                    <th className="py-2 pr-4 font-medium">Employee</th>
                    <th className="py-2 pr-4 font-medium">Dates</th>
                    <th className="py-2 pr-4 text-right font-medium">Days</th>
                    <th className="py-2 pr-4 font-medium">Payroll</th>
                    <th className="py-2 pr-4 font-medium">Status</th>
                    <th className="py-2 pr-4 font-medium">Decision</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((request) => (
                    <tr key={request.id} className="border-b last:border-0">
                      <td className="py-2 pr-4 font-mono text-xs">{request.request_number}</td>
                      <td className="py-2 pr-4 font-medium">{request.employee?.name ?? '—'}</td>
                      <td className="text-muted-foreground py-2 pr-4 tabular-nums">
                        {request.start_date} → {request.end_date}
                      </td>
                      <td className="py-2 pr-4 text-right tabular-nums">{request.days_count}</td>
                      <td className="py-2 pr-4">
                        <span className={request.deducts_salary ? 'text-red-600' : 'text-emerald-600'}>
                          {request.payroll_flag_label}
                        </span>
                      </td>
                      <td className="py-2 pr-4">
                        <StatusBadge status={STATUS_TONE[request.status]} label={request.status_label} />
                      </td>
                      <td className="py-2 pr-4">
                        {request.status === 'pending' ? (
                          <div className="flex gap-1">
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => setDeciding({ request, decision: 'approve' })}
                            >
                              <Check className="size-3.5" />
                              Approve
                            </Button>
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => setDeciding({ request, decision: 'reject' })}
                            >
                              <X className="size-3.5" />
                              Reject
                            </Button>
                          </div>
                        ) : (
                          <span className="text-muted-foreground text-xs">
                            {request.decided_at ?? '—'}
                          </span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      <EntityDrawer
        open={formOpen}
        onOpenChange={setFormOpen}
        title="New Leave Request"
        description="Days off for one employee, with the instruction Payroll needs."
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setFormOpen(false)}>
              Cancel
            </Button>
            <Button onClick={() => void submitRequest()} disabled={submit.isPending}>
              {submit.isPending ? 'Submitting…' : 'Submit Request'}
            </Button>
          </div>
        }
      >
        <div className="flex flex-col gap-4">
          {error ? <p className="text-destructive text-sm">{error}</p> : null}

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="employee_id">Employee</Label>
            <select
              id="employee_id"
              value={form.employee_id}
              onChange={(e) => setForm({ ...form, employee_id: e.target.value })}
              className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
            >
              <option value="">Choose an employee…</option>
              {(employees?.items ?? []).map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.name} ({employee.employee_number})
                </option>
              ))}
            </select>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="start_date">From</Label>
              <Input
                id="start_date"
                type="date"
                value={form.start_date}
                onChange={(e) => setForm({ ...form, start_date: e.target.value })}
              />
            </div>
            <div className="flex flex-col gap-1.5">
              <Label htmlFor="end_date">To</Label>
              <Input
                id="end_date"
                type="date"
                value={form.end_date}
                onChange={(e) => setForm({ ...form, end_date: e.target.value })}
              />
            </div>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="payroll_flag">Payroll Treatment</Label>
            <select
              id="payroll_flag"
              value={form.payroll_flag}
              onChange={(e) => setForm({ ...form, payroll_flag: e.target.value as LeavePayrollFlag })}
              className="border-input h-9 rounded-md border bg-transparent px-3 text-sm shadow-xs"
            >
              <option value="deduct_salary">Deduct Salary</option>
              <option value="do_not_deduct_salary">Do Not Deduct Salary</option>
            </select>
            <span className="text-muted-foreground text-xs">
              HR states the intent; Payroll calculates the amount.
            </span>
          </div>

          <div className="flex flex-col gap-1.5">
            <Label htmlFor="reason">Reason</Label>
            <Input
              id="reason"
              value={form.reason}
              onChange={(e) => setForm({ ...form, reason: e.target.value })}
              placeholder="Optional"
            />
          </div>
        </div>
      </EntityDrawer>

      <ConfirmDialog
        open={deciding !== null}
        onOpenChange={(open) => {
          if (!open) setDeciding(null);
        }}
        title={deciding?.decision === 'approve' ? 'Approve Leave' : 'Reject Leave'}
        description={
          deciding?.decision === 'approve'
            ? `Approve ${deciding.request.days_count} day(s) for ${deciding.request.employee?.name ?? 'this employee'}? The days will be written onto the attendance record.`
            : `Reject this request for ${deciding?.request.employee?.name ?? 'this employee'}? Rejection is final.`
        }
        confirmLabel={deciding?.decision === 'approve' ? 'Approve' : 'Reject'}
        variant={deciding?.decision === 'reject' ? 'destructive' : 'default'}
        loading={decide.isPending}
        onConfirm={confirmDecision}
      />
    </div>
  );
}
