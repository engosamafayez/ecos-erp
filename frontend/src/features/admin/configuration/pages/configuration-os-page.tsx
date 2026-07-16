import { useState } from 'react';
import { generatePath, useNavigate } from 'react-router-dom';
import {
  Box,
  Brain,
  Building2,
  CheckCircle2,
  ChevronRight,
  Clock,
  Cpu,
  Globe,
  Layers,
  Lock,
  Package,
  Settings,
  Shield,
  Users,
  Zap,
  Loader2,
  Bell,
  Workflow,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { useBrandsQuery } from '@/features/brands/hooks/use-brands';
import { ROUTES }         from '@/router/routes';

type ConfigCategory = {
  key: string;
  label: string;
  description: string;
  icon: LucideIcon;
  color: string;
  policyGroup?: string;
};

const CATEGORIES: ConfigCategory[] = [
  {
    key:         'windows',
    label:       'Delivery Windows',
    description: 'Available time slots for delivery',
    icon:        Clock,
    color:       'text-violet-600 bg-violet-50',
  },
  {
    key:         'preparation',
    label:       'Preparation',
    description: 'Session, wave, and batch configuration',
    icon:        Package,
    color:       'text-orange-600 bg-orange-50',
    policyGroup: 'preparation',
  },
  {
    key:         'inventory',
    label:       'Inventory',
    description: 'Stock, reservation, and costing rules',
    icon:        Box,
    color:       'text-teal-600 bg-teal-50',
    policyGroup: 'inventory',
  },
  {
    key:         'manufacturing',
    label:       'Manufacturing',
    description: 'Recipe, BOM, and production policies',
    icon:        Cpu,
    color:       'text-cyan-600 bg-cyan-50',
    policyGroup: 'manufacturing',
  },
  {
    key:         'logistics',
    label:       'Logistics',
    description: 'Vehicle, driver, and route configuration',
    icon:        Globe,
    color:       'text-sky-600 bg-sky-50',
    policyGroup: 'logistics',
  },
  {
    key:         'crm',
    label:       'CRM',
    description: 'Customer classification and loyalty rules',
    icon:        Users,
    color:       'text-rose-600 bg-rose-50',
    policyGroup: 'crm',
  },
  {
    key:         'marketing',
    label:       'Marketing',
    description: 'Campaign attribution and lead sources',
    icon:        Zap,
    color:       'text-pink-600 bg-pink-50',
    policyGroup: 'marketing',
  },
  {
    key:         'ai',
    label:       'AI Configuration',
    description: 'Prediction rules, thresholds, and permissions',
    icon:        Brain,
    color:       'text-purple-600 bg-purple-50',
    policyGroup: 'ai',
  },
  {
    key:         'workflow',
    label:       'Workflow',
    description: 'Order, preparation, and approval chains',
    icon:        Workflow,
    color:       'text-amber-600 bg-amber-50',
    policyGroup: 'workflow',
  },
  {
    key:         'notification',
    label:       'Notifications',
    description: 'Email, SMS, WhatsApp, and escalation rules',
    icon:        Bell,
    color:       'text-yellow-600 bg-yellow-50',
    policyGroup: 'notification',
  },
  {
    key:         'integration',
    label:       'Integrations',
    description: 'WooCommerce, Meta, payment gateways',
    icon:        Layers,
    color:       'text-slate-600 bg-slate-50',
    policyGroup: 'integration',
  },
  {
    key:         'security',
    label:       'Security',
    description: 'Password policy, session timeout, MFA',
    icon:        Shield,
    color:       'text-red-600 bg-red-50',
    policyGroup: 'security',
  },
  {
    key:         'numbering',
    label:       'Numbering',
    description: 'Document prefix and sequence formats',
    icon:        Settings,
    color:       'text-gray-600 bg-gray-50',
    policyGroup: 'numbering',
  },
  {
    key:         'approval',
    label:       'Approval Policies',
    description: 'Multi-level approval matrices',
    icon:        CheckCircle2,
    color:       'text-lime-600 bg-lime-50',
    policyGroup: 'approval',
  },
];

export function ConfigurationOsPage() {
  const navigate  = useNavigate();
  const [search, setSearch] = useState('');
  const [selectedBrandId, setSelectedBrandId] = useState<string | null>(null);

  const { data: brandsData, isLoading: brandsLoading } = useBrandsQuery({
    per_page: 100,
    status:   'active',
  });

  const brands = brandsData?.items ?? [];

  const filtered = search
    ? CATEGORIES.filter(
        (c) =>
          c.label.toLowerCase().includes(search.toLowerCase()) ||
          c.description.toLowerCase().includes(search.toLowerCase()),
      )
    : CATEGORIES;

  function handleCategoryClick(cat: ConfigCategory) {
    if (!selectedBrandId) return;
    navigate(generatePath(ROUTES.configurationBrand, { brandId: selectedBrandId }) + `?tab=${cat.key}`);
  }

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="px-6 pt-5 pb-4 border-b border-border/60">
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-lg font-semibold">Configuration OS</h1>
            <p className="text-sm text-muted-foreground mt-0.5">
              Enterprise governance platform — every configurable rule across ECOS ERP
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Lock className="h-4 w-4 text-muted-foreground" />
            <span className="text-xs text-muted-foreground">All changes are audited</span>
          </div>
        </div>

        {/* KPI Row */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4">
          <KpiCard icon={<Building2 className="h-4 w-4" />} label="Configuration Areas" value={CATEGORIES.length} />
          <KpiCard icon={<Layers      className="h-4 w-4" />} label="Brands"           value={brands.length} />
          <KpiCard icon={<CheckCircle2 className="h-4 w-4" />} label="Audit Enabled"  value="Yes" />
          <KpiCard icon={<Zap         className="h-4 w-4" />} label="Cache TTL"        value="1 hr" />
        </div>
      </div>

      {/* Content */}
      <div className="flex-1 overflow-auto p-6 space-y-6">

        {/* Brand Selector */}
        <section>
          <h2 className="text-sm font-semibold mb-3 flex items-center gap-2">
            <Layers className="h-4 w-4 text-muted-foreground" />
            Select Brand to Configure
          </h2>
          {brandsLoading ? (
            <div className="flex items-center gap-2 text-muted-foreground text-sm py-3">
              <Loader2 className="h-3.5 w-3.5 animate-spin" /> Loading brands…
            </div>
          ) : (
            <div className="flex flex-wrap gap-2">
              {brands.map((b) => (
                <button
                  key={b.id}
                  onClick={() => setSelectedBrandId(b.id === selectedBrandId ? null : b.id)}
                  className={`px-3 py-1.5 rounded-lg border text-sm font-medium transition-colors ${
                    selectedBrandId === b.id
                      ? 'bg-primary text-primary-foreground border-primary'
                      : 'bg-card border-border/60 hover:bg-muted/50'
                  }`}
                >
                  {b.name}
                  {selectedBrandId === b.id && <span className="ml-1.5 text-xs opacity-75">Selected</span>}
                </button>
              ))}
            </div>
          )}
          {!selectedBrandId && brands.length > 0 && (
            <p className="text-xs text-muted-foreground mt-2">Select a brand above to open its configuration workspace.</p>
          )}
        </section>

        {/* Company Configuration */}
        <section>
          <h2 className="text-sm font-semibold mb-3 flex items-center gap-2">
            <Building2 className="h-4 w-4 text-muted-foreground" />
            Company Configuration
          </h2>
          <button
            onClick={() => navigate(ROUTES.configurationCompany)}
            className="w-full flex items-center gap-3 px-4 py-3 rounded-xl border border-border/60 bg-card hover:bg-muted/30 transition-colors text-left"
          >
            <span className={`p-2 rounded-lg text-blue-600 bg-blue-50`}>
              <Building2 className="h-4 w-4" />
            </span>
            <div className="flex-1 min-w-0">
              <div className="text-sm font-medium">Company Settings</div>
              <div className="text-xs text-muted-foreground">Currency, timezone, fiscal year, default warehouse, language</div>
            </div>
            <ChevronRight className="h-4 w-4 text-muted-foreground shrink-0" />
          </button>
        </section>

        {/* Brand Configuration Categories */}
        {selectedBrandId && (
          <section>
            <div className="flex items-center justify-between mb-3">
              <h2 className="text-sm font-semibold flex items-center gap-2">
                <Settings className="h-4 w-4 text-muted-foreground" />
                Brand Configuration
                <Badge className="text-xs bg-primary/10 text-primary border-0">
                  {brands.find((b) => b.id === selectedBrandId)?.name}
                </Badge>
              </h2>
              <Input
                placeholder="Search categories…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="h-8 w-48 text-xs"
              />
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
              {filtered.map((cat) => (
                <CategoryCard
                  key={cat.key}
                  cat={cat}
                  onClick={() => handleCategoryClick(cat)}
                />
              ))}
            </div>
          </section>
        )}

        {!selectedBrandId && brands.length > 0 && (
          <div className="flex flex-col items-center justify-center py-16 gap-3 text-muted-foreground">
            <Settings className="h-10 w-10 opacity-30" />
            <p className="text-sm">Select a brand above to view its configuration categories.</p>
          </div>
        )}
      </div>
    </div>
  );
}

function CategoryCard({ cat, onClick }: { cat: ConfigCategory; onClick: () => void }) {
  return (
    <button
      onClick={onClick}
      className="flex items-center gap-3 px-4 py-3 rounded-xl border border-border/60 bg-card hover:bg-muted/30 hover:border-border transition-colors text-left w-full"
    >
      <span className={`p-2 rounded-lg shrink-0 ${cat.color}`}>
        <cat.icon className="h-4 w-4" />
      </span>
      <div className="flex-1 min-w-0">
        <div className="text-sm font-medium">{cat.label}</div>
        <div className="text-xs text-muted-foreground truncate">{cat.description}</div>
      </div>
      <ChevronRight className="h-4 w-4 text-muted-foreground shrink-0" />
    </button>
  );
}

function KpiCard({ icon, label, value }: { icon: React.ReactNode; label: string; value: number | string }) {
  return (
    <div className="rounded-lg border border-border/60 bg-card p-3 flex items-center gap-2.5">
      <span className="text-muted-foreground shrink-0">{icon}</span>
      <div>
        <div className="text-base font-bold leading-none">{value}</div>
        <div className="text-[10px] text-muted-foreground mt-0.5">{label}</div>
      </div>
    </div>
  );
}
