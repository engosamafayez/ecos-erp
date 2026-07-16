import { useState } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router-dom';
import {
  ArrowLeft,
  Bot,
  Brain,
  CheckCircle2,
  ChevronRight,
  ClipboardList,
  Cog,
  History,
  Loader2,
  Package,
  RotateCcw,
  Settings,
  ShieldCheck,
  Truck,
  Users,
  Webhook,
  XCircle,
} from 'lucide-react';

import { Badge }    from '@/components/ui/badge';
import { useBrandQuery } from '@/features/brands/hooks/use-brands';

import { POLICY_GROUP_LABELS, type PolicyGroup } from '../types/configuration';
import {
  useBrandAudit,
  useHealthScore,
} from '../hooks/use-configuration';
import { PolicyWorkspace }           from '../components/policy-workspace';
import type { ConfigHealthScore, ConfigAuditEntry } from '../types/configuration';

// ── Dashboard card definitions ─────────────────────────────────────────────────

type WorkspaceId =
  | 'preparation' | 'inventory' | 'manufacturing'
  | 'pricing' | 'order' | 'logistics' | 'crm' | 'marketing'
  | 'workflow' | 'notification' | 'security' | 'integration' | 'ai'
  | 'numbering' | 'approval' | 'audit';

type WorkspaceCard = {
  id:          WorkspaceId;
  label:       string;
  description: string;
  icon:        React.ReactNode;
  /** Health check keys that determine this card's status */
  checks:      (keyof ConfigHealthScore['checks'])[];
};

const OPERATIONS_CARDS: WorkspaceCard[] = [
  {
    id: 'preparation',
    label: 'Preparation',
    description: 'Wave strategy, batch sizes, and exception handling.',
    icon: <Package className="h-5 w-5" />,
    checks: ['preparation_policy'],
  },
  {
    id: 'inventory',
    label: 'Inventory',
    description: 'Stock reservation, costing method, and alerts.',
    icon: <ClipboardList className="h-5 w-5" />,
    checks: ['inventory_policy'],
  },
  {
    id: 'manufacturing',
    label: 'Manufacturing',
    description: 'Recipe versioning, BOM validation, and production rules.',
    icon: <Cog className="h-5 w-5" />,
    checks: ['manufacturing_policy'],
  },
];

const COMMERCE_CARDS: WorkspaceCard[] = [
  {
    id: 'logistics',
    label: 'Logistics',
    description: 'Vehicle/driver assignment and route planning rules.',
    icon: <Truck className="h-5 w-5" />,
    checks: [],
  },
  {
    id: 'crm',
    label: 'CRM',
    description: 'VIP thresholds, follow-up schedules, and loyalty program.',
    icon: <Users className="h-5 w-5" />,
    checks: [],
  },
  {
    id: 'marketing',
    label: 'Marketing',
    description: 'UTM attribution, campaign windows, and tracking.',
    icon: <Bot className="h-5 w-5" />,
    checks: [],
  },
];

const SYSTEM_CARDS: WorkspaceCard[] = [
  {
    id: 'workflow',
    label: 'Workflow',
    description: 'Workflow modes for orders, preparation, and procurement.',
    icon: <RotateCcw className="h-5 w-5" />,
    checks: ['workflow_policy'],
  },
  {
    id: 'notification',
    label: 'Notifications',
    description: 'Email, SMS, WhatsApp, and escalation timers.',
    icon: <Bot className="h-5 w-5" />,
    checks: [],
  },
  {
    id: 'security',
    label: 'Security',
    description: 'Session timeout, login attempts, and MFA.',
    icon: <ShieldCheck className="h-5 w-5" />,
    checks: [],
  },
  {
    id: 'integration',
    label: 'Integrations',
    description: 'WooCommerce, Meta, and Google Ads integrations.',
    icon: <Webhook className="h-5 w-5" />,
    checks: ['integrations'],
  },
  {
    id: 'ai',
    label: 'AI Configuration',
    description: 'Confidence thresholds and auto-decision settings.',
    icon: <Brain className="h-5 w-5" />,
    checks: ['ai_configuration'],
  },
  {
    id: 'numbering',
    label: 'Numbering',
    description: 'Document number prefixes and sequence padding.',
    icon: <Settings className="h-5 w-5" />,
    checks: [],
  },
  {
    id: 'approval',
    label: 'Approvals',
    description: 'Approval requirements for prices, recipes, and discounts.',
    icon: <CheckCircle2 className="h-5 w-5" />,
    checks: [],
  },
  {
    id: 'audit',
    label: 'Audit Log',
    description: 'Complete history of all configuration changes.',
    icon: <History className="h-5 w-5" />,
    checks: [],
  },
];

// ── Main Page ─────────────────────────────────────────────────────────────────

export function BrandConfigurationPage() {
  const { brandId }           = useParams<{ brandId: string }>();
  const navigate              = useNavigate();
  const [params, setParams]   = useSearchParams();
  const activeWorkspace       = (params.get('tab') ?? '') as WorkspaceId | '';

  const { data: brand }       = useBrandQuery(brandId ?? '');
  const { data: health }      = useHealthScore(brandId ?? null);

  if (!brandId) return null;

  function openWorkspace(id: WorkspaceId) {
    setParams({ tab: id }, { replace: true });
  }

  function closeToDashboard() {
    setParams({}, { replace: true });
  }

  // ── Workspace view ──
  if (activeWorkspace) {
    return (
      <WorkspaceView
        brandId={brandId}
        workspace={activeWorkspace}
        brandName={brand?.name}
        onBack={closeToDashboard}
      />
    );
  }

  // ── Dashboard view ──
  return (
    <div className="flex flex-col h-full overflow-auto">
      {/* Header */}
      <div className="px-6 pt-4 pb-3 border-b border-border/60 flex items-center gap-3 sticky top-0 bg-background z-10">
        <button
          onClick={() => navigate(-1)}
          className="p-1.5 rounded-lg hover:bg-muted/50 text-muted-foreground"
        >
          <ArrowLeft className="h-4 w-4" />
        </button>
        <div className="flex-1 min-w-0">
          <h1 className="text-base font-semibold leading-none">Brand Configuration</h1>
          {brand && (
            <p className="text-xs text-muted-foreground mt-0.5">{brand.name}</p>
          )}
        </div>
        {health && (
          <div className="flex items-center gap-2">
            <span className="text-xs text-muted-foreground">Health</span>
            <span className={`text-sm font-bold ${
              health.score >= 80 ? 'text-emerald-600 dark:text-emerald-400' :
              health.score >= 50 ? 'text-amber-600 dark:text-amber-400' :
              'text-red-600 dark:text-red-400'
            }`}>
              {health.score}%
            </span>
          </div>
        )}
      </div>

      <div className="flex-1 p-6 space-y-6 max-w-5xl">

        {/* Configuration Health — Phase D */}
        <ConfigHealthPanel health={health} />

        {/* OPERATIONS section */}
        <DashboardSection
          label="OPERATIONS"
          cards={OPERATIONS_CARDS}
          health={health}
          onOpen={openWorkspace}
        />

        {/* COMMERCE section */}
        <DashboardSection
          label="COMMERCE"
          cards={COMMERCE_CARDS}
          health={health}
          onOpen={openWorkspace}
        />

        {/* SYSTEM section */}
        <DashboardSection
          label="SYSTEM"
          cards={SYSTEM_CARDS}
          health={health}
          onOpen={openWorkspace}
        />
      </div>
    </div>
  );
}

// ── Config Health Panel ───────────────────────────────────────────────────────

function ConfigHealthPanel({ health }: { health: ConfigHealthScore | undefined }) {
  if (!health) {
    return (
      <div className="rounded-xl border border-border/60 bg-card p-5 animate-pulse">
        <div className="h-4 w-48 bg-muted rounded mb-3" />
        <div className="h-3 w-full bg-muted rounded" />
      </div>
    );
  }

  const color = health.score >= 80 ? 'bg-emerald-500'
    : health.score >= 50 ? 'bg-amber-500'
    : 'bg-red-500';

  const textColor = health.score >= 80 ? 'text-emerald-700 dark:text-emerald-400'
    : health.score >= 50 ? 'text-amber-700 dark:text-amber-400'
    : 'text-red-600 dark:text-red-400';

  const LABELS: Partial<Record<keyof ConfigHealthScore['checks'], string>> = {
    channels:             'Sales Channels',
    delivery_coverage:    'Delivery Coverage',
    delivery_zones:       'Delivery Zones',
    delivery_windows:     'Delivery Windows',
    shipping_prices:      'Shipping Prices',
    pricing_policy:       'Pricing Policy',
    preparation_policy:   'Preparation Policy',
    inventory_policy:     'Inventory Policy',
    manufacturing_policy: 'Manufacturing Policy',
    workflow_policy:      'Workflow',
    ai_configuration:     'AI Configuration',
    integrations:         'Integrations',
  };

  return (
    <div className="rounded-xl border border-border/60 bg-card p-5 space-y-3">
      <div className="flex items-center justify-between gap-4">
        <div>
          <h2 className="text-sm font-semibold">Configuration Health</h2>
          <p className="text-xs text-muted-foreground mt-0.5">
            {health.passed} of {health.total} checks complete
          </p>
        </div>
        <span className={`text-3xl font-bold tabular-nums ${textColor}`}>
          {health.score}%
        </span>
      </div>

      {/* Progress bar */}
      <div className="h-2.5 bg-muted rounded-full overflow-hidden">
        <div
          className={`h-full rounded-full transition-all duration-500 ${color}`}
          style={{ width: `${health.score}%` }}
        />
      </div>

      {/* Check grid */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-4 gap-y-1.5 pt-1">
        {(Object.entries(health.checks) as [keyof ConfigHealthScore['checks'], boolean][]).map(([key, ok]) => (
          <span
            key={key}
            className={`flex items-center gap-1.5 text-xs ${
              ok ? 'text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'
            }`}
          >
            {ok
              ? <CheckCircle2 className="h-3 w-3 shrink-0" />
              : <XCircle      className="h-3 w-3 shrink-0 opacity-40" />
            }
            <span className={!ok ? 'opacity-60' : ''}>{LABELS[key] ?? key}</span>
          </span>
        ))}
      </div>
    </div>
  );
}

// ── Dashboard Section ─────────────────────────────────────────────────────────

function DashboardSection({
  label, cards, health, onOpen,
}: {
  label:   string;
  cards:   WorkspaceCard[];
  health:  ConfigHealthScore | undefined;
  onOpen:  (id: WorkspaceId) => void;
}) {
  return (
    <div className="space-y-2">
      <div className="flex items-center gap-3">
        <span className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground">{label}</span>
        <div className="flex-1 h-px bg-border/60" />
      </div>
      <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        {cards.map((card) => (
          <WorkspaceConfigCard
            key={card.id}
            card={card}
            health={health}
            onOpen={onOpen}
          />
        ))}
      </div>
    </div>
  );
}

// ── Workspace Config Card ─────────────────────────────────────────────────────

function WorkspaceConfigCard({
  card, health, onOpen,
}: {
  card:   WorkspaceCard;
  health: ConfigHealthScore | undefined;
  onOpen: (id: WorkspaceId) => void;
}) {
  // Compute card status from health checks
  let status: 'complete' | 'partial' | 'missing' | 'none' = 'none';
  if (health && card.checks.length > 0) {
    const checkValues = card.checks.map((k) => health.checks[k] ?? false);
    const passCount   = checkValues.filter(Boolean).length;
    if (passCount === checkValues.length)       status = 'complete';
    else if (passCount > 0)                     status = 'partial';
    else                                        status = 'missing';
  }

  return (
    <button
      onClick={() => onOpen(card.id)}
      className="group relative rounded-xl border border-border/60 bg-card p-4 text-left hover:border-primary/40 hover:shadow-sm transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
    >
      <div className="flex items-start justify-between gap-2 mb-2">
        <div className="p-1.5 rounded-lg bg-muted/60 text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary transition-colors">
          {card.icon}
        </div>
        {status === 'complete' && (
          <Badge className="text-[10px] py-0 h-5 bg-emerald-50 text-emerald-700 border-emerald-200 shrink-0">
            Complete
          </Badge>
        )}
        {status === 'partial' && (
          <Badge className="text-[10px] py-0 h-5 bg-amber-50 text-amber-700 border-amber-200 shrink-0">
            Partial
          </Badge>
        )}
        {status === 'missing' && (
          <Badge className="text-[10px] py-0 h-5 bg-muted text-muted-foreground border-0 shrink-0">
            Not Set
          </Badge>
        )}
      </div>
      <h3 className="text-sm font-semibold mb-0.5">{card.label}</h3>
      <p className="text-xs text-muted-foreground line-clamp-2">{card.description}</p>
      <div className="absolute bottom-3 right-3 opacity-0 group-hover:opacity-100 transition-opacity">
        <ChevronRight className="h-4 w-4 text-primary" />
      </div>
    </button>
  );
}

// ── Workspace View (inner page) ───────────────────────────────────────────────

const POLICY_GROUPS_SET = new Set<string>([
  'preparation', 'pricing', 'inventory', 'manufacturing', 'order',
  'logistics', 'crm', 'marketing', 'ai', 'workflow',
  'notification', 'integration', 'security', 'numbering', 'approval',
]);

function WorkspaceView({
  brandId,
  workspace,
  brandName,
  onBack,
}: {
  brandId:    string;
  workspace:  WorkspaceId;
  brandName?: string;
  onBack:     () => void;
}) {
  const workspaceLabel = getWorkspaceLabel(workspace);

  return (
    <div className="flex flex-col h-full">
      {/* Workspace header */}
      <div className="px-6 pt-4 pb-3 border-b border-border/60 flex items-center gap-3 sticky top-0 bg-background z-10">
        <button
          onClick={onBack}
          className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground transition-colors p-1.5 rounded-lg hover:bg-muted/50"
        >
          <ArrowLeft className="h-3.5 w-3.5" />
          Dashboard
        </button>
        <div className="h-4 w-px bg-border/60" />
        <div className="flex-1 min-w-0">
          <h1 className="text-sm font-semibold leading-none">{workspaceLabel}</h1>
          {brandName && (
            <p className="text-xs text-muted-foreground mt-0.5">{brandName}</p>
          )}
        </div>
      </div>

      {/* Workspace content */}
      <div className="flex-1 overflow-auto">
        {workspace === 'audit'             && <AuditWorkspace            brandId={brandId} />}
        {POLICY_GROUPS_SET.has(workspace)  && (
          <PolicyWorkspace brandId={brandId} group={workspace as PolicyGroup} />
        )}
      </div>
    </div>
  );
}

function getWorkspaceLabel(id: string): string {
  if (id === 'windows')           return 'Delivery Windows';
  if (id === 'audit')             return 'Audit Log';
  return POLICY_GROUP_LABELS[id as PolicyGroup] ?? id;
}

// ── Audit Workspace ───────────────────────────────────────────────────────────

function AuditWorkspace({ brandId }: { brandId: string }) {
  const { data: entries = [], isLoading } = useBrandAudit(brandId, 100);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-16 gap-2 text-muted-foreground">
        <Loader2 className="h-4 w-4 animate-spin" />
        <span className="text-sm">Loading audit log…</span>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-4xl space-y-4">
      <div className="flex items-center gap-2">
        <h2 className="text-sm font-semibold">Configuration Audit Log</h2>
        <Badge variant="outline" className="text-xs">{entries.length} entries</Badge>
      </div>

      {entries.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-12 rounded-lg border border-dashed border-border/60">
          <History className="h-8 w-8 text-muted-foreground/30 mb-2" />
          <p className="text-sm text-muted-foreground">No audit entries yet.</p>
        </div>
      ) : (
        <div className="space-y-1">
          {entries.map((e) => <AuditTimelineEntry key={e.id} entry={e} />)}
        </div>
      )}
    </div>
  );
}

function AuditTimelineEntry({ entry }: { entry: ConfigAuditEntry }) {
  const [expanded, setExpanded] = useState(false);

  const dotColor = entry.action === 'create' ? 'bg-emerald-500'
    : entry.action === 'delete' ? 'bg-red-500'
    : 'bg-blue-500';

  const actionLabel = entry.action === 'create' ? 'Created'
    : entry.action === 'delete' ? 'Deleted'
    : 'Updated';

  const hasChanges = entry.old_value || entry.new_value;

  return (
    <div className="flex gap-3 group">
      {/* Timeline line + dot */}
      <div className="flex flex-col items-center">
        <div className={`h-2 w-2 rounded-full mt-2 shrink-0 ${dotColor}`} />
        <div className="flex-1 w-px bg-border/40 mt-1" />
      </div>

      {/* Content */}
      <div className="flex-1 pb-4 min-w-0">
        <div className="flex flex-wrap items-baseline gap-2">
          <span className="text-sm font-medium">{actionLabel}</span>
          <span className="text-xs text-muted-foreground capitalize">{entry.category}</span>
          {entry.config_key && (
            <code className="text-[10px] bg-muted px-1 rounded">{entry.config_key}</code>
          )}
          <span className="text-xs text-muted-foreground ml-auto whitespace-nowrap">
            {new Date(entry.occurred_at).toLocaleString('en', {
              month: 'short', day: 'numeric',
              hour: '2-digit', minute: '2-digit',
            })}
          </span>
        </div>

        <div className="flex items-center gap-2 mt-0.5">
          {entry.actor_name && (
            <span className="text-xs text-muted-foreground">by {entry.actor_name}</span>
          )}
          {entry.reason && (
            <span className="text-xs text-muted-foreground italic">"{entry.reason}"</span>
          )}
          {hasChanges && (
            <button
              onClick={() => setExpanded(!expanded)}
              className="text-[11px] text-primary underline underline-offset-2 hover:no-underline"
            >
              {expanded ? 'Hide diff' : 'Show diff'}
            </button>
          )}
        </div>

        {expanded && hasChanges && (
          <div className="mt-2 grid grid-cols-2 gap-2">
            {entry.old_value && (
              <div className="rounded-md border border-red-200 bg-red-50 dark:bg-red-950/20 dark:border-red-800 p-2">
                <p className="text-[10px] font-medium text-red-700 dark:text-red-400 mb-1">Before</p>
                <pre className="text-[10px] text-muted-foreground overflow-auto max-h-24 whitespace-pre-wrap">
                  {JSON.stringify(entry.old_value, null, 2)}
                </pre>
              </div>
            )}
            {entry.new_value && (
              <div className="rounded-md border border-emerald-200 bg-emerald-50 dark:bg-emerald-950/20 dark:border-emerald-800 p-2">
                <p className="text-[10px] font-medium text-emerald-700 dark:text-emerald-400 mb-1">After</p>
                <pre className="text-[10px] text-muted-foreground overflow-auto max-h-24 whitespace-pre-wrap">
                  {JSON.stringify(entry.new_value, null, 2)}
                </pre>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
