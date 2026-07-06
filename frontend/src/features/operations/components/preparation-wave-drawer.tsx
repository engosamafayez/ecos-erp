import { useState } from 'react';
import {
  AlertTriangle,
  Bot,
  CheckCircle2,
  ChevronRight,
  ClipboardList,
  Clock,
  FileText,
  History,
  Loader2,
  Package,
  ShieldCheck,
  UserPlus,
  Users,
  X,
  Zap,
} from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useToastStore } from '@/components/ds/use-toast';
import {
  usePreparationWave,
  useGenerateDemand,
  useAnalyzeMaterials,
  useStartPreparation,
  useCompleteItem,
  useCompleteWave,
  useCancelWave,
  useApproveWave,
  useAssignWorker,
  useReleaseWorker,
  useResolveShortage,
  useWaveTimeline,
  useWaveDocuments,
} from '../hooks/use-preparation';
import type {
  PreparationWave,
  WaveStatus,
  WaveItemStatus,
  WorkerRole,
} from '../types/preparation';

// ── Status helpers ─────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<WaveStatus, string> = {
  draft:            'bg-gray-100 text-gray-700',
  planning:         'bg-blue-100 text-blue-700',
  shortage_blocked: 'bg-amber-100 text-amber-700',
  preparing:        'bg-purple-100 text-purple-700',
  completed:        'bg-green-100 text-green-700',
  cancelled:        'bg-red-100 text-red-700',
};

const STATUS_LABELS: Record<WaveStatus, string> = {
  draft:            'Draft',
  planning:         'Planning',
  shortage_blocked: 'Shortage Blocked',
  preparing:        'Preparing',
  completed:        'Completed',
  cancelled:        'Cancelled',
};

const ITEM_STATUS_COLORS: Record<WaveItemStatus, string> = {
  pending:    'bg-gray-100 text-gray-600',
  in_progress:'bg-blue-100 text-blue-700',
  prepared:   'bg-green-100 text-green-700',
  short:      'bg-amber-100 text-amber-700',
  blocked:    'bg-red-100 text-red-700',
};

function fmt(n: number) {
  return n.toLocaleString(undefined, { maximumFractionDigits: 2 });
}

// ── Summary Tab ───────────────────────────────────────────────────────────────

function SummaryTab({ wave, onAction }: { wave: PreparationWave; onAction: () => void }) {
  const toast    = useToastStore((s) => s.toast);
  const generate = useGenerateDemand();
  const analyze  = useAnalyzeMaterials();
  const start    = useStartPreparation();
  const complete = useCompleteWave();
  const cancel   = useCancelWave();
  const approve  = useApproveWave();

  const [cancelReason, setCancelReason] = useState('');
  const [showCancel, setShowCancel]     = useState(false);
  const [showApprove, setShowApprove]   = useState(false);
  const [approveNotes, setApproveNotes] = useState('');

  async function handleGenerate() {
    await generate.mutateAsync(wave.id);
    toast({ type: 'success', title: 'Demand generated', description: 'Product demand calculated from orders.' });
    onAction();
  }
  async function handleAnalyze() {
    await analyze.mutateAsync(wave.id);
    toast({ type: 'success', title: 'Materials analyzed', description: 'Material requirements analysis complete.' });
    onAction();
  }
  async function handleStart() {
    await start.mutateAsync({ id: wave.id, payload: { worker_ids: [], override_shortage: false } });
    toast({ type: 'success', title: 'Preparation started' });
    onAction();
  }
  async function handleComplete() {
    await complete.mutateAsync(wave.id);
    toast({ type: 'success', title: 'Wave completed', description: 'Products added to Prepared Pool.' });
    onAction();
  }
  async function handleCancel() {
    if (cancelReason.length < 10) {
      toast({ type: 'error', title: 'Reason required', description: 'Provide at least 10 characters.' });
      return;
    }
    await cancel.mutateAsync({ id: wave.id, payload: { reason: cancelReason } });
    toast({ type: 'success', title: 'Wave cancelled' });
    setShowCancel(false);
    onAction();
  }
  async function handleApprove() {
    await approve.mutateAsync({ id: wave.id, payload: { notes: approveNotes || undefined } });
    toast({ type: 'success', title: 'Wave approved' });
    setShowApprove(false);
    onAction();
  }

  const loading = generate.isPending || analyze.isPending || start.isPending
    || complete.isPending || cancel.isPending || approve.isPending;

  return (
    <div className="space-y-6">
      {/* Progress */}
      <div className="rounded-lg border p-4 space-y-3">
        <div className="flex items-center justify-between">
          <span className="text-sm font-medium text-gray-700">Preparation Progress</span>
          <span className="text-sm text-gray-500">{wave.completion_pct.toFixed(1)}%</span>
        </div>
        <Progress value={wave.completion_pct} className="h-2" />
        <div className="grid grid-cols-2 gap-3 text-sm">
          <div><p className="text-gray-500">Orders</p><p className="font-medium">{wave.orders_count}</p></div>
          <div><p className="text-gray-500">Products</p><p className="font-medium">{wave.products_count}</p></div>
          <div><p className="text-gray-500">Units Required</p><p className="font-medium">{fmt(wave.total_units_required)}</p></div>
          <div><p className="text-gray-500">Units Prepared</p><p className="font-medium">{fmt(wave.total_units_prepared)}</p></div>
        </div>
      </div>

      {/* Shortage alert */}
      {wave.shortage_detected && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 flex gap-2">
          <AlertTriangle className="w-4 h-4 text-amber-600 mt-0.5 shrink-0" />
          <p className="text-sm text-amber-800">Material shortage detected. Resolve before starting preparation.</p>
        </div>
      )}

      {/* Workers */}
      {wave.workers && wave.workers.length > 0 && (
        <div className="rounded-lg border p-4">
          <p className="text-sm font-medium text-gray-700 mb-2">Active Workers ({wave.workers.length})</p>
          <div className="space-y-1">
            {wave.workers.map((w) => (
              <div key={w.id} className="flex items-center justify-between text-sm">
                <span className="text-gray-800">{w.user_name ?? w.user_id}</span>
                <Badge className={`text-xs ${STATUS_COLORS.preparing}`}>{w.role}</Badge>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Open exceptions */}
      {wave.exceptions && wave.exceptions.filter((e) => e.status === 'open').length > 0 && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-3">
          <p className="text-sm font-medium text-red-800 mb-1">
            {wave.exceptions.filter((e) => e.status === 'open').length} Open Exception(s)
          </p>
          {wave.exceptions.filter((e) => e.status === 'open').map((ex) => (
            <p key={ex.id} className="text-xs text-red-700">{ex.description}</p>
          ))}
        </div>
      )}

      {/* Actions */}
      <div className="space-y-2">
        {wave.status === 'draft' && (
          <Button className="w-full" onClick={handleGenerate} disabled={loading}>
            {generate.isPending && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
            Generate Product Demand
          </Button>
        )}
        {wave.status === 'planning' && (
          <Button className="w-full" onClick={handleAnalyze} disabled={loading}>
            {analyze.isPending && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
            Analyze Materials (MRP)
          </Button>
        )}
        {(wave.status === 'planning' || wave.status === 'shortage_blocked') && !wave.approved_at && (
          <>
            {!showApprove ? (
              <Button className="w-full" variant="outline" onClick={() => setShowApprove(true)} disabled={loading}>
                <ShieldCheck className="w-4 h-4 mr-1.5" />
                Approve Wave
              </Button>
            ) : (
              <div className="space-y-2 rounded-lg border p-3">
                <Label className="text-sm">Approval Notes (optional)</Label>
                <Input
                  value={approveNotes}
                  onChange={(e) => setApproveNotes(e.target.value)}
                  placeholder="Notes for approval..."
                />
                <div className="flex gap-2">
                  <Button size="sm" onClick={handleApprove} disabled={loading}>
                    {approve.isPending && <Loader2 className="w-3 h-3 mr-1 animate-spin" />}
                    Confirm Approval
                  </Button>
                  <Button size="sm" variant="outline" onClick={() => setShowApprove(false)}>Cancel</Button>
                </div>
              </div>
            )}
          </>
        )}
        {(wave.status === 'planning' || wave.status === 'shortage_blocked') && (
          <Button
            className="w-full"
            variant="outline"
            onClick={handleStart}
            disabled={loading || (wave.shortage_detected && wave.status === 'shortage_blocked')}
          >
            {start.isPending && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
            Start Preparation
          </Button>
        )}
        {wave.status === 'preparing' && (
          <Button className="w-full bg-green-600 hover:bg-green-700" onClick={handleComplete} disabled={loading}>
            {complete.isPending && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
            Complete Wave
          </Button>
        )}
        {!['completed', 'cancelled'].includes(wave.status) && !showCancel && (
          <Button className="w-full" variant="ghost" onClick={() => setShowCancel(true)} disabled={loading}>
            Cancel Wave
          </Button>
        )}
        {showCancel && (
          <div className="space-y-2 rounded-lg border p-3">
            <Label className="text-sm">Cancellation Reason (min 10 chars)</Label>
            <Input
              value={cancelReason}
              onChange={(e) => setCancelReason(e.target.value)}
              placeholder="Explain why this wave is being cancelled..."
            />
            <div className="flex gap-2">
              <Button size="sm" variant="destructive" onClick={handleCancel} disabled={loading}>
                {cancel.isPending && <Loader2 className="w-3 h-3 mr-1 animate-spin" />}
                Confirm Cancel
              </Button>
              <Button size="sm" variant="outline" onClick={() => setShowCancel(false)}>Keep Wave</Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// ── Products Tab ───────────────────────────────────────────────────────────────

function ProductsTab({ wave }: { wave: PreparationWave }) {
  const toast        = useToastStore((s) => s.toast);
  const completeItem = useCompleteItem();
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editQty, setEditQty]     = useState('');
  const items = wave.wave_items ?? [];

  async function handleComplete(itemId: string) {
    const qty = parseFloat(editQty);
    if (isNaN(qty) || qty < 0) {
      toast({ type: 'error', title: 'Invalid quantity' });
      return;
    }
    await completeItem.mutateAsync({ waveId: wave.id, itemId, payload: { quantity_prepared: qty } });
    toast({ type: 'success', title: 'Item updated' });
    setEditingId(null);
    setEditQty('');
  }

  if (items.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-12 text-gray-400">
        <Package className="w-8 h-8 mb-2" />
        <p className="text-sm">No products yet. Generate demand first.</p>
      </div>
    );
  }

  return (
    <div className="space-y-1">
      {items.map((item) => (
        <div key={item.id} className="rounded-lg border p-3 space-y-2">
          <div className="flex items-start justify-between gap-2">
            <div className="min-w-0">
              <p className="text-sm font-medium text-gray-900 truncate">{item.name_snapshot}</p>
              <p className="text-xs text-gray-500">{item.sku_snapshot}</p>
            </div>
            <Badge className={`text-xs shrink-0 ${ITEM_STATUS_COLORS[item.status]}`}>
              {item.status.replace('_', ' ')}
            </Badge>
          </div>
          <Progress value={item.completion_pct} className="h-1.5" />
          <div className="flex items-center justify-between text-xs text-gray-500">
            <span>{fmt(item.quantity_prepared)} / {fmt(item.quantity_required)} units</span>
            {item.quantity_short > 0 && (
              <span className="text-amber-600">{fmt(item.quantity_short)} short</span>
            )}
          </div>
          {item.zone && (
            <p className="text-xs text-gray-400">
              Location: {item.zone}{item.shelf_location ? ` · ${item.shelf_location}` : ''}
            </p>
          )}
          {wave.status === 'preparing' && (
            editingId === item.id ? (
              <div className="flex gap-2">
                <Input
                  type="number"
                  min="0"
                  value={editQty}
                  onChange={(e) => setEditQty(e.target.value)}
                  className="h-7 text-sm"
                  placeholder="Qty prepared"
                />
                <Button size="sm" className="h-7 text-xs" onClick={() => handleComplete(item.id)} disabled={completeItem.isPending}>
                  {completeItem.isPending && <Loader2 className="w-3 h-3 mr-1 animate-spin" />}
                  Save
                </Button>
                <Button size="sm" variant="ghost" className="h-7 text-xs" onClick={() => setEditingId(null)}>Cancel</Button>
              </div>
            ) : (
              <Button
                size="sm" variant="outline" className="h-7 text-xs"
                onClick={() => { setEditingId(item.id); setEditQty(String(item.quantity_required)); }}
              >
                Update Qty
              </Button>
            )
          )}
        </div>
      ))}
    </div>
  );
}

// ── Materials Tab ─────────────────────────────────────────────────────────────

function MaterialsTab({ wave, onAction }: { wave: PreparationWave; onAction: () => void }) {
  const toast           = useToastStore((s) => s.toast);
  const resolveShortage = useResolveShortage();
  const materials = wave.material_requirements ?? [];

  async function handleResolveAll() {
    const shortIds = materials.filter((m) => m.shortage && !m.resolved).map((m) => m.id);
    if (shortIds.length === 0) return;
    await resolveShortage.mutateAsync({ waveId: wave.id, payload: { requirement_ids: shortIds, notes: 'Resolved by operator' } });
    toast({ type: 'success', title: 'Shortages resolved' });
    onAction();
  }

  if (materials.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-12 text-gray-400">
        <ClipboardList className="w-8 h-8 mb-2" />
        <p className="text-sm">No material analysis yet. Run MRP analysis first.</p>
      </div>
    );
  }

  const hasUnresolved = materials.some((m) => m.shortage && !m.resolved);

  return (
    <div className="space-y-2">
      {hasUnresolved && (
        <Button size="sm" variant="outline" className="w-full h-8 text-xs" onClick={handleResolveAll} disabled={resolveShortage.isPending}>
          {resolveShortage.isPending && <Loader2 className="w-3 h-3 mr-1.5 animate-spin" />}
          Mark All Shortages Resolved
        </Button>
      )}
      <div className="space-y-1">
        {materials.map((mat) => (
          <div
            key={mat.id}
            className={`rounded-lg border p-3 ${mat.shortage && !mat.resolved ? 'border-amber-200 bg-amber-50' : ''}`}
          >
            <div className="flex items-start justify-between gap-2 mb-1">
              <div>
                <p className="text-sm font-medium text-gray-900">{mat.material_name_snapshot}</p>
                <p className="text-xs text-gray-500">{mat.unit_snapshot}</p>
              </div>
              {mat.shortage && !mat.resolved && (
                <Badge className="text-xs bg-amber-100 text-amber-700 shrink-0">Shortage</Badge>
              )}
              {mat.resolved && <CheckCircle2 className="w-4 h-4 text-green-600 shrink-0" />}
            </div>
            <div className="grid grid-cols-3 gap-2 text-xs text-gray-500">
              <div><p>Required</p><p className="font-medium text-gray-800">{fmt(mat.quantity_required)}</p></div>
              <div>
                <p>Available</p>
                <p className={`font-medium ${mat.quantity_available < mat.quantity_required ? 'text-amber-700' : 'text-gray-800'}`}>
                  {fmt(mat.quantity_available)}
                </p>
              </div>
              <div>
                <p>To Purchase</p>
                <p className={`font-medium ${mat.quantity_to_purchase > 0 ? 'text-red-700' : 'text-gray-800'}`}>
                  {fmt(mat.quantity_to_purchase)}
                </p>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Orders Tab ────────────────────────────────────────────────────────────────

function OrdersTab({ wave }: { wave: PreparationWave }) {
  const orders = wave.orders ?? [];
  if (orders.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-12 text-gray-400">
        <ClipboardList className="w-8 h-8 mb-2" />
        <p className="text-sm">No orders in this wave.</p>
      </div>
    );
  }
  return (
    <div className="space-y-1">
      {orders.map((order) => (
        <div key={order.id} className="flex items-center justify-between rounded-lg border p-3 text-sm">
          <div>
            <p className="font-medium text-gray-900">{order.order_number}</p>
            {order.customer_name_snapshot && <p className="text-xs text-gray-500">{order.customer_name_snapshot}</p>}
          </div>
          <div className="text-right text-xs text-gray-400">
            {order.delivery_zone && <p>{order.delivery_zone}</p>}
            <p>{new Date(order.added_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</p>
          </div>
        </div>
      ))}
    </div>
  );
}

// ── Workers Tab ───────────────────────────────────────────────────────────────

const WORKER_ROLES: WorkerRole[] = ['operator', 'supervisor', 'quality_checker', 'lead_picker'];

function WorkersTab({ wave, onAction }: { wave: PreparationWave; onAction: () => void }) {
  const toast   = useToastStore((s) => s.toast);
  const assign  = useAssignWorker();
  const release = useReleaseWorker();
  const [showAssign, setShowAssign] = useState(false);
  const [userId, setUserId]         = useState('');
  const [role, setRole]             = useState<WorkerRole>('operator');

  const workers = wave.workers ?? [];

  async function handleAssign() {
    if (!userId.trim()) {
      toast({ type: 'error', title: 'User ID required' });
      return;
    }
    await assign.mutateAsync({ waveId: wave.id, payload: { user_id: userId.trim(), role } });
    toast({ type: 'success', title: 'Worker assigned' });
    setUserId('');
    setShowAssign(false);
    onAction();
  }

  async function handleRelease(uid: string) {
    await release.mutateAsync({ waveId: wave.id, userId: uid });
    toast({ type: 'success', title: 'Worker released' });
    onAction();
  }

  return (
    <div className="space-y-2">
      {['draft', 'planning', 'shortage_blocked', 'preparing'].includes(wave.status) && (
        <>
          {!showAssign ? (
            <Button size="sm" variant="outline" className="w-full h-8 text-xs" onClick={() => setShowAssign(true)}>
              <UserPlus className="w-3.5 h-3.5 mr-1.5" />
              Assign Worker
            </Button>
          ) : (
            <div className="rounded-lg border p-3 space-y-2">
              <Label className="text-xs">User ID</Label>
              <Input value={userId} onChange={(e) => setUserId(e.target.value)} placeholder="Enter user ID" className="h-7 text-sm" />
              <Label className="text-xs">Role</Label>
              <Select value={role} onValueChange={(v) => setRole(v as WorkerRole)}>
                <SelectTrigger className="h-7 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  {WORKER_ROLES.map((r) => <SelectItem key={r} value={r}>{r}</SelectItem>)}
                </SelectContent>
              </Select>
              <div className="flex gap-2">
                <Button size="sm" className="h-7 text-xs" onClick={handleAssign} disabled={assign.isPending}>
                  {assign.isPending && <Loader2 className="w-3 h-3 mr-1 animate-spin" />}
                  Assign
                </Button>
                <Button size="sm" variant="outline" className="h-7 text-xs" onClick={() => setShowAssign(false)}>Cancel</Button>
              </div>
            </div>
          )}
        </>
      )}

      {workers.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-12 text-gray-400">
          <Users className="w-8 h-8 mb-2" />
          <p className="text-sm">No workers assigned yet.</p>
        </div>
      ) : (
        workers.map((w) => (
          <div key={w.id} className="flex items-center justify-between rounded-lg border p-3">
            <div>
              <p className="text-sm font-medium text-gray-900">{w.user_name ?? w.user_id}</p>
              <Badge className="text-xs mt-0.5 bg-gray-100 text-gray-700">{w.role}</Badge>
            </div>
            <div className="flex items-center gap-2">
              <div className="text-right text-xs text-gray-400">
                <p>Assigned {new Date(w.assigned_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</p>
                {w.released_at && (
                  <p className="text-green-600">Released</p>
                )}
              </div>
              {!w.released_at && wave.status === 'preparing' && (
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-7 text-xs"
                  onClick={() => void handleRelease(w.user_id)}
                  disabled={release.isPending}
                >
                  Release
                </Button>
              )}
            </div>
          </div>
        ))
      )}
    </div>
  );
}

// ── Exceptions Tab ────────────────────────────────────────────────────────────

function ExceptionsTab({ wave }: { wave: PreparationWave }) {
  const exceptions = wave.exceptions ?? [];
  return (
    <div className="space-y-1">
      {exceptions.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-12 text-gray-400">
          <CheckCircle2 className="w-8 h-8 mb-2 text-green-400" />
          <p className="text-sm">No exceptions. All clear.</p>
        </div>
      ) : (
        exceptions.map((ex) => (
          <div key={ex.id} className={`rounded-lg border p-3 ${ex.status === 'open' ? 'border-red-200 bg-red-50' : ''}`}>
            <div className="flex items-start justify-between gap-2">
              <div>
                <p className="text-sm font-medium text-gray-900 capitalize">{ex.exception_type.replace(/_/g, ' ')}</p>
                <p className="text-xs text-gray-600 mt-0.5">{ex.description}</p>
              </div>
              <div className="flex flex-col items-end gap-1 shrink-0">
                <Badge className={`text-xs ${ex.severity === 'blocking' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'}`}>
                  {ex.severity}
                </Badge>
                <Badge className={`text-xs ${ex.status === 'open' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'}`}>
                  {ex.status}
                </Badge>
              </div>
            </div>
            {ex.resolution_notes && (
              <p className="text-xs text-gray-400 mt-1">Resolution: {ex.resolution_notes}</p>
            )}
          </div>
        ))
      )}
    </div>
  );
}

// ── Timeline Tab ──────────────────────────────────────────────────────────────

function TimelineTab({ waveId }: { waveId: string }) {
  const { data: entries = [], isLoading } = useWaveTimeline(waveId);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="w-5 h-5 animate-spin text-gray-400" />
      </div>
    );
  }

  if (entries.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-12 text-gray-400">
        <History className="w-8 h-8 mb-2" />
        <p className="text-sm">No timeline events yet.</p>
      </div>
    );
  }

  return (
    <div className="relative">
      <div className="absolute left-3.5 top-0 bottom-0 w-px bg-gray-200" />
      <div className="space-y-4">
        {entries.map((entry) => (
          <div key={entry.id} className="relative pl-8">
            <div className="absolute left-2.5 top-1 w-2 h-2 rounded-full bg-primary border-2 border-background" />
            <div>
              <p className="text-sm font-medium text-gray-900">{entry.title}</p>
              {entry.description && (
                <p className="text-xs text-gray-500 mt-0.5">{entry.description}</p>
              )}
              <div className="flex items-center gap-2 mt-1 text-xs text-gray-400">
                {entry.actor_name && <span>{entry.actor_name}</span>}
                <Clock className="w-3 h-3" />
                <span>{new Date(entry.occurred_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })}</span>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Documents Tab ─────────────────────────────────────────────────────────────

function DocumentsTab({ waveId }: { waveId: string }) {
  const { data: docs = [], isLoading } = useWaveDocuments(waveId);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="w-5 h-5 animate-spin text-gray-400" />
      </div>
    );
  }

  if (docs.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-12 text-gray-400">
        <FileText className="w-8 h-8 mb-2" />
        <p className="text-sm">No documents attached.</p>
      </div>
    );
  }

  return (
    <div className="space-y-1">
      {docs.map((doc) => (
        <div key={doc.id} className="flex items-center justify-between rounded-lg border p-3">
          <div className="min-w-0">
            <p className="text-sm font-medium text-gray-900 truncate">{doc.title}</p>
            <p className="text-xs text-gray-500">{doc.document_type}</p>
            {doc.file_name && <p className="text-xs text-gray-400">{doc.file_name}</p>}
          </div>
          {doc.url && (
            <a
              href={doc.url}
              target="_blank"
              rel="noopener noreferrer"
              className="ml-3 shrink-0"
              onClick={(e) => e.stopPropagation()}
            >
              <Button size="sm" variant="outline" className="h-7 text-xs">
                <ChevronRight className="w-3.5 h-3.5" />
              </Button>
            </a>
          )}
        </div>
      ))}
    </div>
  );
}

// ── AI Insights Tab ───────────────────────────────────────────────────────────

function AiInsightsTab({ wave }: { wave: PreparationWave }) {
  return (
    <div className="space-y-3">
      <div className="rounded-lg border border-blue-200 bg-blue-50 p-4">
        <div className="flex items-center gap-2 mb-2">
          <Bot className="w-4 h-4 text-blue-600" />
          <p className="text-sm font-medium text-blue-900">AI Analysis</p>
          <Badge className="text-xs bg-blue-100 text-blue-700 ml-auto">Coming Soon</Badge>
        </div>
        <p className="text-xs text-blue-700">
          AI-powered insights for wave {wave.wave_number} will appear here — bottleneck predictions,
          optimization suggestions, and completion forecasts.
        </p>
      </div>
      <div className="grid grid-cols-2 gap-2">
        {[
          { label: 'Completion Forecast', value: '—' },
          { label: 'Bottleneck Risk', value: '—' },
          { label: 'Throughput Score', value: '—' },
          { label: 'Next Best Action', value: '—' },
        ].map(({ label, value }) => (
          <div key={label} className="rounded-lg border p-3">
            <p className="text-xs text-gray-500">{label}</p>
            <p className="text-sm font-medium text-gray-400 mt-0.5">{value}</p>
          </div>
        ))}
      </div>
    </div>
  );
}

// ── Main Drawer ───────────────────────────────────────────────────────────────

type Props = {
  waveId: string | null;
  onClose: () => void;
};

export function PreparationWaveDrawer({ waveId, onClose }: Props) {
  const [tab, setTab] = useState('summary');
  const { data: wave, isLoading, refetch } = usePreparationWave(waveId);

  function handleAction() {
    void refetch();
  }

  const openExceptions = wave?.exceptions?.filter((e) => e.status === 'open').length ?? 0;

  return (
    <Sheet open={!!waveId} onOpenChange={(open) => !open && onClose()}>
      <SheetContent side="right" className="w-full sm:max-w-3xl flex flex-col p-0" aria-label="Wave detail">
        {isLoading || !wave ? (
          <div className="flex items-center justify-center h-full">
            <Loader2 className="w-6 h-6 animate-spin text-gray-400" />
          </div>
        ) : (
          <>
            {/* Header */}
            <SheetHeader className="px-6 py-4 border-b shrink-0">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-2 mb-1">
                    <SheetTitle className="text-base font-semibold">{wave.wave_number}</SheetTitle>
                    <Badge className={`text-xs ${STATUS_COLORS[wave.status]}`}>
                      {STATUS_LABELS[wave.status]}
                    </Badge>
                  </div>
                  <p className="text-sm text-gray-500">
                    {new Date(wave.planning_date).toLocaleDateString()} ·{' '}
                    {wave.orders_count} orders · {wave.products_count} products
                  </p>
                  <div className="mt-2">
                    <Progress value={wave.completion_pct} className="h-1.5 w-64" />
                    <p className="text-xs text-gray-400 mt-0.5">
                      {wave.completion_pct.toFixed(1)}% · {fmt(wave.total_units_prepared)} of {fmt(wave.total_units_required)} units
                    </p>
                  </div>
                </div>
                <Button variant="ghost" size="icon" className="shrink-0" onClick={onClose} aria-label="Close drawer">
                  <X className="w-4 h-4" />
                </Button>
              </div>
            </SheetHeader>

            {/* Tabs */}
            <Tabs value={tab} onValueChange={setTab} className="flex flex-col flex-1 overflow-hidden">
              <TabsList className="px-6 py-0 h-10 rounded-none border-b bg-transparent justify-start gap-0 shrink-0 overflow-x-auto">
                {[
                  { value: 'summary',    label: 'Summary',    icon: Zap },
                  { value: 'products',   label: 'Products',   icon: Package },
                  { value: 'materials',  label: 'Materials',  icon: ClipboardList },
                  { value: 'orders',     label: 'Orders',     icon: ChevronRight },
                  { value: 'workers',    label: 'Workers',    icon: Users },
                  { value: 'exceptions', label: 'Exceptions', icon: AlertTriangle },
                  { value: 'timeline',   label: 'Timeline',   icon: History },
                  { value: 'documents',  label: 'Documents',  icon: FileText },
                  { value: 'ai',         label: 'AI',         icon: Bot },
                ].map(({ value, label, icon: Icon }) => (
                  <TabsTrigger
                    key={value}
                    value={value}
                    className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent px-3 h-full text-xs gap-1"
                  >
                    <Icon className="w-3 h-3" />
                    {label}
                    {value === 'exceptions' && openExceptions > 0 && (
                      <span className="ml-1 rounded-full bg-red-500 text-white text-[10px] w-4 h-4 flex items-center justify-center">
                        {openExceptions}
                      </span>
                    )}
                  </TabsTrigger>
                ))}
              </TabsList>

              <div className="flex-1 overflow-y-auto px-6 py-4">
                <TabsContent value="summary" forceMount className={tab !== 'summary' ? 'hidden' : ''}>
                  <SummaryTab wave={wave} onAction={handleAction} />
                </TabsContent>
                <TabsContent value="products" forceMount className={tab !== 'products' ? 'hidden' : ''}>
                  <ProductsTab wave={wave} />
                </TabsContent>
                <TabsContent value="materials" forceMount className={tab !== 'materials' ? 'hidden' : ''}>
                  <MaterialsTab wave={wave} onAction={handleAction} />
                </TabsContent>
                <TabsContent value="orders" forceMount className={tab !== 'orders' ? 'hidden' : ''}>
                  <OrdersTab wave={wave} />
                </TabsContent>
                <TabsContent value="workers" forceMount className={tab !== 'workers' ? 'hidden' : ''}>
                  <WorkersTab wave={wave} onAction={handleAction} />
                </TabsContent>
                <TabsContent value="exceptions" forceMount className={tab !== 'exceptions' ? 'hidden' : ''}>
                  <ExceptionsTab wave={wave} />
                </TabsContent>
                <TabsContent value="timeline" forceMount className={tab !== 'timeline' ? 'hidden' : ''}>
                  <TimelineTab waveId={wave.id} />
                </TabsContent>
                <TabsContent value="documents" forceMount className={tab !== 'documents' ? 'hidden' : ''}>
                  <DocumentsTab waveId={wave.id} />
                </TabsContent>
                <TabsContent value="ai" forceMount className={tab !== 'ai' ? 'hidden' : ''}>
                  <AiInsightsTab wave={wave} />
                </TabsContent>
              </div>
            </Tabs>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
