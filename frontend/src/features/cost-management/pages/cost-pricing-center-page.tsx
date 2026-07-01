import { useState } from 'react';
import {
  AlertTriangle,
  ArrowUpDown,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Download,
  ExternalLink,
  Loader2,
  MoreHorizontal,
  RefreshCw,
  TrendingDown,
  TrendingUp,
  UserPlus,
  XCircle,
} from 'lucide-react';

import { EntityToolbar, PageHeader } from '@/components/crud';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { ProductCostDrawer } from '@/features/cost-management/components/product-cost-drawer';
import {
  useApproveReview,
  useAssignReview,
  useBulkApprove,
  usePricingReviews,
  useSnoozeReview,
} from '@/features/cost-management/hooks/use-pricing-reviews';
import type {
  ImpactType,
  PricingReview,
  PricingReviewsQuery,
  ReviewStatus,
} from '@/features/cost-management/types/pricing-review';
import { toast } from '@/components/ds/use-toast';
import { cn } from '@/lib/utils';
import { ROUTES } from '@/router/routes';

// ── Constants ─────────────────────────────────────────────────────────────────

const STATUS_OPTIONS: { value: ReviewStatus | 'all'; label: string }[] = [
  { value: 'all',          label: 'All Statuses' },
  { value: 'pending',      label: 'Pending' },
  { value: 'approved',     label: 'Approved' },
  { value: 'kept',         label: 'Kept Current' },
  { value: 'custom_price', label: 'Custom Price' },
  { value: 'snoozed',      label: 'Snoozed' },
];

const IMPACT_OPTIONS: { value: ImpactType | 'all'; label: string }[] = [
  { value: 'all',               label: 'All Impacts' },
  { value: 'margin_below_target', label: 'Below Target' },
  { value: 'cost_increased',    label: 'Cost Increased' },
  { value: 'cost_decreased',    label: 'Cost Decreased' },
  { value: 'recipe_changed',    label: 'Recipe Changed' },
  { value: 'packaging_changed', label: 'Packaging Changed' },
];

// ── Formatting ────────────────────────────────────────────────────────────────

function fmt(n: number) {
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── KPI cards ─────────────────────────────────────────────────────────────────

type KpiCardProps = {
  label: string;
  value: number | string;
  icon: React.ReactNode;
  accent?: 'amber' | 'red' | 'green' | 'blue' | 'default';
  subtext?: string;
};

function KpiCard({ label, value, icon, accent = 'default', subtext }: KpiCardProps) {
  const accentClasses = {
    amber:   'text-amber-600 dark:text-amber-400',
    red:     'text-red-600 dark:text-red-400',
    green:   'text-emerald-600 dark:text-emerald-400',
    blue:    'text-blue-600 dark:text-blue-400',
    default: 'text-foreground',
  };
  return (
    <Card>
      <CardContent className="pt-5 pb-4">
        <div className="flex items-start justify-between">
          <p className="text-sm text-muted-foreground">{label}</p>
          <span className={cn('opacity-70', accentClasses[accent])}>{icon}</span>
        </div>
        <p className={cn('mt-2 text-2xl font-semibold tabular-nums', accentClasses[accent])}>
          {value}
        </p>
        {subtext && <p className="mt-0.5 text-xs text-muted-foreground">{subtext}</p>}
      </CardContent>
    </Card>
  );
}

// ── Status & impact badges ─────────────────────────────────────────────────────

const STATUS_BADGE: Record<ReviewStatus, string> = {
  pending:      'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400',
  approved:     'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
  kept:         'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  custom_price: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
  snoozed:      'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400',
};

const STATUS_LABEL: Record<ReviewStatus, string> = {
  pending:      'Pending',
  approved:     'Approved',
  kept:         'Kept',
  custom_price: 'Custom',
  snoozed:      'Snoozed',
};

function StatusBadge({ status }: { status: ReviewStatus }) {
  return (
    <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', STATUS_BADGE[status])}>
      {STATUS_LABEL[status]}
    </span>
  );
}

function ImpactIcons({ impacts }: { impacts: ImpactType[] }) {
  return (
    <div className="flex items-center gap-1 flex-wrap">
      {impacts.includes('margin_below_target') && (
        <span title="Below target margin">
          <AlertTriangle className="size-3.5 text-amber-500" />
        </span>
      )}
      {impacts.includes('cost_increased') && (
        <span title="Cost increased">
          <TrendingUp className="size-3.5 text-red-500" />
        </span>
      )}
      {impacts.includes('cost_decreased') && (
        <span title="Cost decreased">
          <TrendingDown className="size-3.5 text-emerald-500" />
        </span>
      )}
      {impacts.includes('recipe_changed') && (
        <span title="Recipe changed" className="text-[10px] bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 rounded px-1 font-medium">
          Recipe
        </span>
      )}
    </div>
  );
}

// ── Dialogs ───────────────────────────────────────────────────────────────────

type DialogState =
  | null
  | { type: 'custom_price'; review: PricingReview }
  | { type: 'keep_current'; review: PricingReview }
  | { type: 'snooze';       review: PricingReview }
  | { type: 'assign';       review: PricingReview };

function CustomPriceDialog({
  review,
  onConfirm,
  onCancel,
  isPending,
}: {
  review: PricingReview;
  onConfirm: (price: number, reason: string) => void;
  onCancel: () => void;
  isPending: boolean;
}) {
  const [price, setPrice] = useState(review.suggested_selling_price.toFixed(2));
  const [reason, setReason] = useState('');

  const priceNum = parseFloat(price) || 0;
  const margin = priceNum > 0 ? ((priceNum - review.current_cost) / priceNum) * 100 : 0;
  const profit = priceNum - review.current_cost;
  const valid = priceNum > 0 && reason.trim().length > 0;

  return (
    <Dialog open onOpenChange={(o) => { if (!o) onCancel(); }}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Set Custom Price</DialogTitle>
          <DialogDescription>{review.product.name}</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 py-2">
          <div>
            <Label className="mb-1.5 block text-sm">Custom Selling Price</Label>
            <Input
              type="number"
              min="0"
              step="0.01"
              value={price}
              onChange={(e) => setPrice(e.target.value)}
              className="text-right text-base tabular-nums font-semibold"
            />
            <div className="mt-2 grid grid-cols-3 gap-2 text-xs">
              <div className="rounded-md border p-2 text-center">
                <p className="text-muted-foreground">Margin</p>
                <p className={cn('font-medium', margin >= review.target_margin ? 'text-emerald-600' : 'text-red-600')}>
                  {margin.toFixed(1)}%
                </p>
              </div>
              <div className="rounded-md border p-2 text-center">
                <p className="text-muted-foreground">Profit/unit</p>
                <p className={cn('font-medium', profit >= 0 ? 'text-emerald-600' : 'text-red-600')}>
                  {fmt(profit)}
                </p>
              </div>
              <div className="rounded-md border p-2 text-center">
                <p className="text-muted-foreground">Target</p>
                <p className="font-medium">{review.target_margin.toFixed(1)}%</p>
              </div>
            </div>
          </div>

          <div>
            <Label className="mb-1.5 block text-sm">Reason <span className="text-destructive">*</span></Label>
            <Textarea
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="Why is this price appropriate?"
              rows={3}
            />
          </div>

          <div className="flex gap-2 text-xs">
            {[
              { label: 'Current', value: review.current_selling_price },
              { label: 'Suggested', value: review.suggested_selling_price },
            ].map((ref) => (
              <button
                key={ref.label}
                type="button"
                onClick={() => setPrice(ref.value.toFixed(2))}
                className="flex-1 rounded border px-2 py-1 text-center hover:bg-accent transition-colors"
              >
                <span className="text-muted-foreground">{ref.label}: </span>
                <span className="font-medium tabular-nums">{fmt(ref.value)}</span>
              </button>
            ))}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onCancel} disabled={isPending}>Cancel</Button>
          <Button onClick={() => onConfirm(priceNum, reason)} disabled={!valid || isPending}>
            {isPending && <Loader2 className="size-4 animate-spin" />}
            Confirm Price
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function KeepCurrentDialog({
  review,
  onConfirm,
  onCancel,
  isPending,
}: {
  review: PricingReview;
  onConfirm: (reason: string) => void;
  onCancel: () => void;
  isPending: boolean;
}) {
  const [reason, setReason] = useState('');
  return (
    <Dialog open onOpenChange={(o) => { if (!o) onCancel(); }}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Keep Current Price</DialogTitle>
          <DialogDescription>
            {review.product.name} — current price {fmt(review.current_selling_price)}
          </DialogDescription>
        </DialogHeader>
        <div className="py-2">
          <Label className="mb-1.5 block text-sm">Reason <span className="text-destructive">*</span></Label>
          <Textarea
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="Why are you keeping the current price?"
            rows={4}
          />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onCancel} disabled={isPending}>Cancel</Button>
          <Button onClick={() => onConfirm(reason)} disabled={!reason.trim() || isPending}>
            {isPending && <Loader2 className="size-4 animate-spin" />}
            Confirm
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

const SNOOZE_PRESETS = [
  { label: 'Tomorrow', days: 1 },
  { label: '3 Days',   days: 3 },
  { label: '1 Week',   days: 7 },
];

function addDays(n: number) {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return d.toISOString().slice(0, 10);
}

function SnoozeDialog({
  review,
  onConfirm,
  onCancel,
  isPending,
}: {
  review: PricingReview;
  onConfirm: (until: string) => void;
  onCancel: () => void;
  isPending: boolean;
}) {
  const [selected, setSelected] = useState<string>(addDays(3));
  const [calOpen, setCalOpen] = useState(false);

  return (
    <Dialog open onOpenChange={(o) => { if (!o) onCancel(); }}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Snooze Review</DialogTitle>
          <DialogDescription>{review.product.name}</DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-3 py-2">
          <div className="grid grid-cols-3 gap-2">
            {SNOOZE_PRESETS.map((p) => {
              const val = addDays(p.days);
              return (
                <button
                  key={p.label}
                  type="button"
                  onClick={() => setSelected(val)}
                  className={cn(
                    'rounded-md border px-3 py-2 text-sm transition-colors text-center',
                    selected === val ? 'border-primary bg-primary/10 font-medium' : 'hover:bg-accent',
                  )}
                >
                  {p.label}
                </button>
              );
            })}
          </div>

          <Separator />

          <Popover open={calOpen} onOpenChange={setCalOpen}>
            <PopoverTrigger asChild>
              <Button variant="outline" size="sm" className="justify-start gap-2">
                <span className="text-muted-foreground">Custom:</span>
                <span className="font-medium tabular-nums">{selected}</span>
              </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
              <Calendar
                mode="single"
                selected={selected ? new Date(selected) : undefined}
                onSelect={(d) => {
                  if (d) { setSelected(d.toISOString().slice(0, 10)); setCalOpen(false); }
                }}
                disabled={(d) => d < new Date()}
              />
            </PopoverContent>
          </Popover>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onCancel} disabled={isPending}>Cancel</Button>
          <Button onClick={() => onConfirm(selected)} disabled={!selected || isPending}>
            {isPending && <Loader2 className="size-4 animate-spin" />}
            Snooze Until {selected}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function AssignDialog({
  review,
  onConfirm,
  onCancel,
  isPending,
}: {
  review: PricingReview;
  onConfirm: (name: string) => void;
  onCancel: () => void;
  isPending: boolean;
}) {
  const [name, setName] = useState(review.reviewer?.name ?? '');
  return (
    <Dialog open onOpenChange={(o) => { if (!o) onCancel(); }}>
      <DialogContent className="max-w-sm">
        <DialogHeader>
          <DialogTitle>Assign Reviewer</DialogTitle>
          <DialogDescription>{review.product.name}</DialogDescription>
        </DialogHeader>
        <div className="py-2">
          <Label className="mb-1.5 block text-sm">Reviewer Name <span className="text-destructive">*</span></Label>
          <Input
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder="Enter reviewer name"
          />
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onCancel} disabled={isPending}>Cancel</Button>
          <Button onClick={() => onConfirm(name)} disabled={!name.trim() || isPending}>
            {isPending && <Loader2 className="size-4 animate-spin" />}
            Assign
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export function CostPricingCenterPage() {
  const [query, setQuery] = useState<PricingReviewsQuery>({ status: 'all', impact: 'all', page: 1, per_page: 25 });
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [dialogState, setDialogState] = useState<DialogState>(null);
  const [drawerReview, setDrawerReview] = useState<PricingReview | null>(null);
  const [drawerOpen, setDrawerOpen]     = useState(false);
  const [sortField, setSortField]       = useState<string | null>(null);
  const [sortDir, setSortDir]           = useState<'asc' | 'desc'>('desc');

  const { data, isLoading, isError, refetch, isFetching } = usePricingReviews(query);
  const approveReview  = useApproveReview();
  const snoozeReview   = useSnoozeReview();
  const assignReview   = useAssignReview();
  const bulkApprove    = useBulkApprove();

  const items   = data?.items   ?? [];
  const summary = data?.summary ?? { pending_count: 0, below_target_count: 0, above_target_count: 0, cost_increased_today: 0, cost_decreased_today: 0, expected_profit_change: 0 };

  // ── Selection helpers ───────────────────────────────────────────────────────
  const allSelected   = items.length > 0 && items.every((r) => selectedIds.has(r.id));
  const someSelected  = items.some((r) => selectedIds.has(r.id));

  function toggleAll() {
    if (allSelected) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(items.map((r) => r.id)));
    }
  }

  function toggleOne(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  // ── Sort helpers ────────────────────────────────────────────────────────────
  function handleSort(field: string) {
    if (sortField === field) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortField(field);
      setSortDir('desc');
    }
  }

  function SortIcon({ field }: { field: string }) {
    if (sortField !== field) return <ArrowUpDown className="size-3 ml-1 opacity-40" />;
    return sortDir === 'asc'
      ? <ChevronUp className="size-3 ml-1" />
      : <ChevronDown className="size-3 ml-1" />;
  }

  // ── Action handlers ──────────────────────────────────────────────────────────
  function openDrawer(review: PricingReview) {
    setDrawerReview(review);
    setDrawerOpen(true);
  }

  function handleApprove(review: PricingReview) {
    approveReview.mutate(
      { id: review.id, payload: { action: 'approve_suggested' } },
      { onSuccess: () => toast.success('Approved', `Suggested price applied for ${review.product.name}`) },
    );
  }

  function handleCustomPriceConfirm(review: PricingReview, price: number, reason: string) {
    approveReview.mutate(
      { id: review.id, payload: { action: 'custom_price', custom_price: price, reason } },
      {
        onSuccess: () => {
          toast.success('Custom price set', `${review.product.name} → ${fmt(price)}`);
          setDialogState(null);
        },
      },
    );
  }

  function handleKeepCurrentConfirm(review: PricingReview, reason: string) {
    approveReview.mutate(
      { id: review.id, payload: { action: 'keep_current', reason } },
      {
        onSuccess: () => {
          toast.success('Current price kept', review.product.name);
          setDialogState(null);
        },
      },
    );
  }

  function handleSnoozeConfirm(review: PricingReview, until: string) {
    snoozeReview.mutate(
      { id: review.id, payload: { until } },
      {
        onSuccess: () => {
          toast.success('Review snoozed', `${review.product.name} until ${until}`);
          setDialogState(null);
        },
      },
    );
  }

  function handleAssignConfirm(review: PricingReview, name: string) {
    assignReview.mutate(
      { id: review.id, payload: { reviewer_name: name } },
      {
        onSuccess: () => {
          toast.success('Reviewer assigned', `${review.product.name} → ${name}`);
          setDialogState(null);
        },
      },
    );
  }

  function handleBulkApprove() {
    const ids = Array.from(selectedIds);
    bulkApprove.mutate(
      { ids, action: 'approve_suggested' },
      {
        onSuccess: () => {
          toast.success('Bulk approved', `${ids.length} reviews updated`);
          setSelectedIds(new Set());
        },
      },
    );
  }

  function handleExport() {
    const headers = [
      'Product', 'SKU', 'Company', 'Channel',
      'Current Cost', 'Previous Cost', 'Change%',
      'Current Price', 'Suggested Price', 'Current Margin', 'Target Margin',
      'Status',
    ];
    const rows = items.map((r) => [
      r.product.name, r.product.sku, r.company.name, r.channel.name,
      r.current_cost, r.previous_cost, r.cost_change_pct.toFixed(2),
      r.current_selling_price, r.suggested_selling_price,
      r.current_margin.toFixed(2), r.target_margin.toFixed(2),
      r.status,
    ]);
    const csv = [headers, ...rows].map((r) => r.join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `pricing-review-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  // ── Filter panel ─────────────────────────────────────────────────────────────
  const filterPanel = (
    <div className="flex flex-wrap gap-3">
      <Select
        value={query.status ?? 'all'}
        onValueChange={(v) => setQuery((q) => ({ ...q, status: v as ReviewStatus | 'all', page: 1 }))}
      >
        <SelectTrigger className="w-40">
          <SelectValue placeholder="Status" />
        </SelectTrigger>
        <SelectContent>
          {STATUS_OPTIONS.map((o) => (
            <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
          ))}
        </SelectContent>
      </Select>

      <Select
        value={query.impact ?? 'all'}
        onValueChange={(v) => setQuery((q) => ({ ...q, impact: v as ImpactType | 'all', page: 1 }))}
      >
        <SelectTrigger className="w-44">
          <SelectValue placeholder="Impact" />
        </SelectTrigger>
        <SelectContent>
          {IMPACT_OPTIONS.map((o) => (
            <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  );

  // ── Render ────────────────────────────────────────────────────────────────────

  return (
    <div className="flex flex-col gap-6 p-6">
      {/* Page header */}
      <PageHeader
        title="Cost & Pricing Control Center"
        subtitle="Review product pricing decisions after cost changes across all channels"
        breadcrumbs={[
          { label: 'Inventory', to: ROUTES.inventory },
          { label: 'Cost & Pricing' },
        ]}
        actions={
          <Button variant="outline" size="sm" onClick={handleExport}>
            <Download className="size-4" />
            Export
          </Button>
        }
      />

      {/* KPI cards */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <KpiCard
          label="Pending Reviews"
          value={summary.pending_count}
          icon={<AlertTriangle className="size-5" />}
          accent={summary.pending_count > 0 ? 'amber' : 'default'}
        />
        <KpiCard
          label="Below Target"
          value={summary.below_target_count}
          icon={<XCircle className="size-5" />}
          accent={summary.below_target_count > 0 ? 'red' : 'default'}
          subtext="margin"
        />
        <KpiCard
          label="Above Target"
          value={summary.above_target_count}
          icon={<CheckCircle2 className="size-5" />}
          accent={summary.above_target_count > 0 ? 'green' : 'default'}
          subtext="margin"
        />
        <KpiCard
          label="Cost Up Today"
          value={summary.cost_increased_today}
          icon={<TrendingUp className="size-5" />}
          accent={summary.cost_increased_today > 0 ? 'red' : 'default'}
          subtext="products"
        />
        <KpiCard
          label="Cost Down Today"
          value={summary.cost_decreased_today}
          icon={<TrendingDown className="size-5" />}
          accent={summary.cost_decreased_today > 0 ? 'green' : 'default'}
          subtext="products"
        />
        <KpiCard
          label="Expected Profit Δ"
          value={`${summary.expected_profit_change >= 0 ? '+' : ''}${fmt(summary.expected_profit_change)}`}
          icon={<TrendingUp className="size-5" />}
          accent={summary.expected_profit_change >= 0 ? 'green' : 'red'}
          subtext="if all approved"
        />
      </div>

      {/* Bulk action bar */}
      {someSelected && (
        <div className="flex items-center gap-3 rounded-lg border border-primary/30 bg-primary/5 px-4 py-2">
          <span className="text-sm font-medium">{selectedIds.size} selected</span>
          <Separator orientation="vertical" className="h-4" />
          <Button size="sm" variant="outline" onClick={handleBulkApprove} disabled={bulkApprove.isPending}>
            {bulkApprove.isPending && <Loader2 className="size-4 animate-spin" />}
            <CheckCircle2 className="size-4" />
            Approve Selected
          </Button>
          <Button size="sm" variant="outline" onClick={() => {
            bulkApprove.mutate({ ids: Array.from(selectedIds), action: 'keep_current' }, {
              onSuccess: () => { toast.success('Kept current', `${selectedIds.size} reviews`); setSelectedIds(new Set()); },
            });
          }} disabled={bulkApprove.isPending}>
            Keep Prices
          </Button>
          <Button size="sm" variant="outline" onClick={handleExport}>
            <Download className="size-4" />
            Export
          </Button>
          <Button size="sm" variant="ghost" onClick={() => setSelectedIds(new Set())}>
            Clear
          </Button>
        </div>
      )}

      {/* Toolbar */}
      <EntityToolbar
        searchPlaceholder="Search by product name or SKU…"
        onSearchChange={(s) => setQuery((q) => ({ ...q, search: s || undefined, page: 1 }))}
        onRefresh={() => refetch()}
        isRefreshing={isFetching}
        filterPanel={filterPanel}
        onClearFilters={() => setQuery({ status: 'all', impact: 'all', page: 1, per_page: 25 })}
      />

      {/* Table */}
      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-sm">
            <thead>
              <tr className="border-b bg-muted/40 text-left text-xs font-medium text-muted-foreground uppercase tracking-wider">
                <th className="w-10 px-4 py-3">
                  <Checkbox
                    checked={allSelected}
                    onCheckedChange={toggleAll}
                    aria-label="Select all"
                  />
                </th>
                <th className="px-4 py-3">
                  <button type="button" className="flex items-center" onClick={() => handleSort('product')}>
                    Product <SortIcon field="product" />
                  </button>
                </th>
                <th className="px-4 py-3">Channel</th>
                <th className="px-4 py-3">
                  <button type="button" className="flex items-center" onClick={() => handleSort('current_cost')}>
                    Current Cost <SortIcon field="current_cost" />
                  </button>
                </th>
                <th className="px-4 py-3">Prev. Cost</th>
                <th className="px-4 py-3">
                  <button type="button" className="flex items-center" onClick={() => handleSort('cost_change_pct')}>
                    Change <SortIcon field="cost_change_pct" />
                  </button>
                </th>
                <th className="px-4 py-3">Current Price</th>
                <th className="px-4 py-3">Suggested Price</th>
                <th className="px-4 py-3">
                  <button type="button" className="flex items-center" onClick={() => handleSort('current_margin')}>
                    Margin <SortIcon field="current_margin" />
                  </button>
                </th>
                <th className="px-4 py-3">Impacts</th>
                <th className="px-4 py-3">Status</th>
                <th className="px-4 py-3">Reviewer</th>
                <th className="w-10 px-4 py-3" />
              </tr>
            </thead>
            <tbody className="divide-y">
              {isLoading ? (
                <tr>
                  <td colSpan={13} className="py-16 text-center">
                    <Loader2 className="size-6 animate-spin mx-auto text-muted-foreground" />
                  </td>
                </tr>
              ) : isError ? (
                <tr>
                  <td colSpan={13} className="py-12 text-center text-muted-foreground">
                    Failed to load reviews.
                    <Button variant="link" size="sm" onClick={() => refetch()}>Retry</Button>
                  </td>
                </tr>
              ) : items.length === 0 ? (
                <tr>
                  <td colSpan={13} className="py-16 text-center">
                    <CheckCircle2 className="size-8 mx-auto mb-2 text-emerald-500" />
                    <p className="text-sm text-muted-foreground">No reviews pending — all pricing is up to date.</p>
                  </td>
                </tr>
              ) : (
                items.map((review) => {
                  const priceGap = review.suggested_selling_price - review.current_selling_price;
                  const belowTarget = review.current_margin < review.target_margin;
                  return (
                    <tr
                      key={review.id}
                      className={cn(
                        'hover:bg-muted/30 transition-colors',
                        selectedIds.has(review.id) && 'bg-primary/5',
                      )}
                    >
                      <td className="px-4 py-3">
                        <Checkbox
                          checked={selectedIds.has(review.id)}
                          onCheckedChange={() => toggleOne(review.id)}
                          aria-label={`Select ${review.product.name}`}
                        />
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex items-center gap-2 min-w-0">
                          {review.product.image_url && (
                            <img
                              src={review.product.image_url}
                              alt=""
                              className="size-7 rounded object-cover flex-shrink-0"
                            />
                          )}
                          <div className="min-w-0">
                            <p className="font-medium truncate max-w-[160px]">{review.product.name}</p>
                            <p className="text-xs text-muted-foreground font-mono">{review.product.sku}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <div>
                          <p className="text-xs text-muted-foreground">{review.company.name}</p>
                          <p className="font-medium">{review.channel.name}</p>
                        </div>
                      </td>
                      <td className="px-4 py-3 tabular-nums font-medium">
                        {fmt(review.current_cost)}
                      </td>
                      <td className="px-4 py-3 tabular-nums text-muted-foreground">
                        {fmt(review.previous_cost)}
                      </td>
                      <td className="px-4 py-3">
                        <span className={cn(
                          'text-xs font-medium tabular-nums',
                          review.cost_change_pct > 0 ? 'text-red-600' : review.cost_change_pct < 0 ? 'text-emerald-600' : 'text-muted-foreground',
                        )}>
                          {review.cost_change_pct > 0 ? '+' : ''}{review.cost_change_pct.toFixed(2)}%
                        </span>
                      </td>
                      <td className="px-4 py-3 tabular-nums">
                        {fmt(review.current_selling_price)}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-col">
                          <span className={cn(
                            'tabular-nums font-medium',
                            priceGap !== 0 ? (priceGap > 0 ? 'text-amber-600' : 'text-emerald-600') : '',
                          )}>
                            {fmt(review.suggested_selling_price)}
                          </span>
                          {priceGap !== 0 && (
                            <span className="text-[11px] text-muted-foreground">
                              {priceGap > 0 ? '+' : ''}{fmt(priceGap)}
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-col">
                          <span className={cn(
                            'font-medium tabular-nums',
                            belowTarget ? 'text-red-600' : 'text-emerald-600',
                          )}>
                            {review.current_margin.toFixed(1)}%
                          </span>
                          <span className="text-[11px] text-muted-foreground">
                            target {review.target_margin.toFixed(0)}%
                          </span>
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <ImpactIcons impacts={review.impacts} />
                      </td>
                      <td className="px-4 py-3">
                        <StatusBadge status={review.status} />
                        {review.snooze_until && (
                          <p className="text-[11px] text-muted-foreground mt-0.5">until {review.snooze_until}</p>
                        )}
                      </td>
                      <td className="px-4 py-3 text-sm text-muted-foreground">
                        {review.reviewer?.name ?? '—'}
                      </td>
                      <td className="px-4 py-3">
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="size-8">
                              <MoreHorizontal className="size-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => openDrawer(review)}>
                              <ExternalLink className="size-4" />
                              View Cost Analysis
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => handleApprove(review)}>
                              <CheckCircle2 className="size-4 text-emerald-500" />
                              Approve Suggested ({fmt(review.suggested_selling_price)})
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => setDialogState({ type: 'custom_price', review })}>
                              Set Custom Price
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => setDialogState({ type: 'keep_current', review })}>
                              Keep Current Price
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => setDialogState({ type: 'snooze', review })}>
                              Snooze Review
                            </DropdownMenuItem>
                            <DropdownMenuItem onClick={() => setDialogState({ type: 'assign', review })}>
                              <UserPlus className="size-4" />
                              Assign Reviewer
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </td>
                    </tr>
                  );
                })
              )}
            </tbody>
          </table>
        </div>

        {data && data.meta.total > 0 && (
          <div className="flex items-center justify-between border-t px-4 py-3 text-sm text-muted-foreground">
            <span>
              {data.meta.total} total · page {data.meta.current_page} of {data.meta.last_page}
            </span>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={data.meta.current_page <= 1}
                onClick={() => setQuery((q) => ({ ...q, page: (q.page ?? 1) - 1 }))}
              >
                Previous
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={data.meta.current_page >= data.meta.last_page}
                onClick={() => setQuery((q) => ({ ...q, page: (q.page ?? 1) + 1 }))}
              >
                Next
              </Button>
            </div>
          </div>
        )}
      </Card>

      {/* Dialogs */}
      {dialogState?.type === 'custom_price' && (
        <CustomPriceDialog
          review={dialogState.review}
          onConfirm={(price, reason) => handleCustomPriceConfirm(dialogState.review, price, reason)}
          onCancel={() => setDialogState(null)}
          isPending={approveReview.isPending}
        />
      )}
      {dialogState?.type === 'keep_current' && (
        <KeepCurrentDialog
          review={dialogState.review}
          onConfirm={(reason) => handleKeepCurrentConfirm(dialogState.review, reason)}
          onCancel={() => setDialogState(null)}
          isPending={approveReview.isPending}
        />
      )}
      {dialogState?.type === 'snooze' && (
        <SnoozeDialog
          review={dialogState.review}
          onConfirm={(until) => handleSnoozeConfirm(dialogState.review, until)}
          onCancel={() => setDialogState(null)}
          isPending={snoozeReview.isPending}
        />
      )}
      {dialogState?.type === 'assign' && (
        <AssignDialog
          review={dialogState.review}
          onConfirm={(name) => handleAssignConfirm(dialogState.review, name)}
          onCancel={() => setDialogState(null)}
          isPending={assignReview.isPending}
        />
      )}

      {/* Product cost drawer */}
      <ProductCostDrawer
        review={drawerReview}
        open={drawerOpen}
        onOpenChange={(o) => { setDrawerOpen(o); if (!o) setDrawerReview(null); }}
      />
    </div>
  );
}
