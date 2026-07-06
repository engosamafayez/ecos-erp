import { useState } from 'react';
import {
  CheckCircle2,
  Clock,
  DollarSign,
  FileText,
  Package,
  Send,
  XCircle,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import {
  useApproveSupplierReturn,
  useCancelSupplierReturn,
  useSubmitSupplierReturn,
  useSupplierReturn,
} from '@/features/supplier-returns/hooks/use-supplier-returns';
import type { SupplierReturnStatus } from '@/features/supplier-returns/types/supplier-return';

const STATUS_COLORS: Record<SupplierReturnStatus, string> = {
  draft:            'bg-gray-100 text-gray-700',
  waiting_approval: 'bg-yellow-100 text-yellow-800',
  approved:         'bg-blue-100 text-blue-800',
  sent:             'bg-purple-100 text-purple-800',
  credit_pending:   'bg-orange-100 text-orange-800',
  completed:        'bg-green-100 text-green-800',
  cancelled:        'bg-red-100 text-red-700',
  rejected:         'bg-red-100 text-red-700',
};

type Props = {
  id: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  mode?: 'view' | 'create';
};

export function SupplierReturnDrawer({ id, open, onOpenChange, mode = 'view' }: Props) {
  const [activeTab, setActiveTab] = useState<'overview' | 'lines' | 'timeline'>('overview');

  const { data: returnRecord, isLoading } = useSupplierReturn(id);
  const submitMutation  = useSubmitSupplierReturn();
  const approveMutation = useApproveSupplierReturn();
  const cancelMutation  = useCancelSupplierReturn();

  if (mode === 'create') {
    return (
      <Sheet open={open} onOpenChange={onOpenChange}>
        <SheetContent className="w-full sm:max-w-2xl overflow-y-auto">
          <SheetHeader>
            <SheetTitle>New Supplier Return</SheetTitle>
          </SheetHeader>
          <div className="mt-6 p-4 bg-gray-50 rounded-lg text-center text-sm text-gray-500">
            Return creation form — use the backend API POST /supplier-returns
          </div>
        </SheetContent>
      </Sheet>
    );
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full sm:max-w-2xl overflow-y-auto">
        {isLoading || !returnRecord ? (
          <div className="flex items-center justify-center h-48">
            <p className="text-sm text-gray-400">Loading…</p>
          </div>
        ) : (
          <>
            <SheetHeader className="pb-4">
              <div className="flex items-start justify-between gap-4">
                <div>
                  <SheetTitle className="text-base font-semibold">
                    {returnRecord.return_number}
                  </SheetTitle>
                  <p className="text-sm text-gray-500 mt-0.5">
                    {returnRecord.supplier?.name ?? '—'} · {returnRecord.return_date}
                  </p>
                </div>
                <Badge
                  className={`${STATUS_COLORS[returnRecord.status]} border-0 text-xs flex-shrink-0`}
                  variant="secondary"
                >
                  {returnRecord.status_label}
                </Badge>
              </div>

              {/* Action buttons */}
              <div className="flex gap-2 pt-2">
                {returnRecord.status === 'draft' && (
                  <Button
                    size="sm"
                    variant="outline"
                    className="gap-1.5"
                    onClick={() => submitMutation.mutate(returnRecord.id)}
                    disabled={submitMutation.isPending}
                  >
                    <Send className="w-3.5 h-3.5" />
                    Submit
                  </Button>
                )}
                {returnRecord.status === 'waiting_approval' && (
                  <Button
                    size="sm"
                    className="gap-1.5"
                    onClick={() => approveMutation.mutate(returnRecord.id)}
                    disabled={approveMutation.isPending}
                  >
                    <CheckCircle2 className="w-3.5 h-3.5" />
                    Approve
                  </Button>
                )}
                {['draft', 'waiting_approval'].includes(returnRecord.status) && (
                  <Button
                    size="sm"
                    variant="outline"
                    className="gap-1.5 text-red-600 hover:text-red-700"
                    onClick={() => cancelMutation.mutate(returnRecord.id)}
                    disabled={cancelMutation.isPending}
                  >
                    <XCircle className="w-3.5 h-3.5" />
                    Cancel
                  </Button>
                )}
              </div>
            </SheetHeader>

            {/* Tabs */}
            <div className="flex border-b border-gray-200 mb-4">
              {([
                { key: 'overview', label: 'Overview',   icon: FileText },
                { key: 'lines',    label: 'Items',      icon: Package },
                { key: 'timeline', label: 'Timeline',   icon: Clock },
              ] as const).map(({ key, label, icon: Icon }) => (
                <button
                  key={key}
                  onClick={() => setActiveTab(key)}
                  className={`flex items-center gap-1.5 px-3 py-2 text-xs font-medium border-b-2 transition-colors ${
                    activeTab === key
                      ? 'border-blue-500 text-blue-600'
                      : 'border-transparent text-gray-500 hover:text-gray-700'
                  }`}
                >
                  <Icon className="w-3.5 h-3.5" />
                  {label}
                </button>
              ))}
            </div>

            {/* Overview Tab */}
            {activeTab === 'overview' && (
              <div className="space-y-4">
                <div className="grid grid-cols-2 gap-3 text-sm">
                  <div>
                    <p className="text-xs text-gray-500">Supplier</p>
                    <p className="font-medium mt-0.5">{returnRecord.supplier?.name ?? '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500">Warehouse</p>
                    <p className="font-medium mt-0.5">{returnRecord.warehouse?.name ?? '—'}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500">Return Date</p>
                    <p className="font-medium mt-0.5">{returnRecord.return_date}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500">Reason</p>
                    <p className="font-medium mt-0.5 capitalize">
                      {returnRecord.reason?.replace(/_/g, ' ') ?? '—'}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500">Credit Method</p>
                    <p className="font-medium mt-0.5 capitalize">
                      {returnRecord.credit_method?.replace(/_/g, ' ') ?? '—'}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500">Expected Credit</p>
                    <p className="font-medium mt-0.5">{returnRecord.expected_credit_date ?? '—'}</p>
                  </div>
                </div>

                <Separator />

                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2 text-gray-700">
                    <DollarSign className="w-4 h-4" />
                    <span className="text-sm font-medium">Total Return Value</span>
                  </div>
                  <span className="text-base font-semibold text-gray-900">
                    SAR {returnRecord.total_return_value.toLocaleString()}
                  </span>
                </div>

                {returnRecord.notes && (
                  <>
                    <Separator />
                    <div>
                      <p className="text-xs text-gray-500 mb-1">Notes</p>
                      <p className="text-sm text-gray-700">{returnRecord.notes}</p>
                    </div>
                  </>
                )}
              </div>
            )}

            {/* Lines Tab */}
            {activeTab === 'lines' && (
              <div className="space-y-2">
                {returnRecord.lines.length === 0 ? (
                  <p className="text-sm text-gray-400 text-center py-8">No items on this return</p>
                ) : (
                  returnRecord.lines.map(line => (
                    <div key={line.id} className="p-3 bg-gray-50 rounded-lg">
                      <div className="flex items-start justify-between">
                        <div>
                          <p className="text-sm font-medium">{line.product?.name ?? line.product_id}</p>
                          <p className="text-xs text-gray-500 mt-0.5">SKU: {line.product?.sku ?? '—'}</p>
                          {line.reason && (
                            <p className="text-xs text-gray-500 mt-0.5 capitalize">
                              Reason: {line.reason.replace(/_/g, ' ')}
                            </p>
                          )}
                        </div>
                        <div className="text-right">
                          <p className="text-sm font-semibold">
                            SAR {line.total_cost.toLocaleString()}
                          </p>
                          <p className="text-xs text-gray-500 mt-0.5">
                            {line.return_quantity} {line.uom_symbol_snapshot ?? 'units'} × SAR {line.unit_cost}
                          </p>
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>
            )}

            {/* Timeline Tab */}
            {activeTab === 'timeline' && (
              <div className="space-y-3">
                {[
                  { label: 'Created',   at: returnRecord.created_at,  color: 'bg-gray-400' },
                  { label: 'Submitted', at: returnRecord.submitted_at, color: 'bg-yellow-400' },
                  { label: 'Approved',  at: returnRecord.approved_at,  color: 'bg-blue-400' },
                  { label: 'Completed', at: returnRecord.completed_at, color: 'bg-green-400' },
                ].filter(e => e.at !== null).map(event => (
                  <div key={event.label} className="flex items-center gap-3">
                    <div className={`w-2 h-2 rounded-full flex-shrink-0 ${event.color}`} />
                    <div>
                      <p className="text-sm font-medium">{event.label}</p>
                      <p className="text-xs text-gray-400">
                        {event.at ? new Date(event.at).toLocaleString() : '—'}
                      </p>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
