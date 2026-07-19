import { useState } from 'react';
import { Settings, Bell, RefreshCw, Filter, Clock, Save } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useToast } from '@/components/ds/use-toast';

interface SettingsState {
  cache_ttl_minutes:          number;
  default_date_preset:        string;
  auto_refresh_interval:      number;
  default_per_page:           number;
  show_growth_indicators:     boolean;
  default_currency:           string;
  default_granularity:        'day' | 'week' | 'month';
  notify_overspend:           boolean;
  notify_sync_failure:        boolean;
}

const DEFAULT_SETTINGS: SettingsState = {
  cache_ttl_minutes:      15,
  default_date_preset:    'last_30d',
  auto_refresh_interval:  0,
  default_per_page:       25,
  show_growth_indicators: true,
  default_currency:       'USD',
  default_granularity:    'day',
  notify_overspend:       true,
  notify_sync_failure:    true,
};

const STORAGE_KEY = 'ecos_marketing_settings';

function loadSettings(): SettingsState {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? { ...DEFAULT_SETTINGS, ...JSON.parse(raw) } : DEFAULT_SETTINGS;
  } catch {
    return DEFAULT_SETTINGS;
  }
}

function SettingRow({ label, description, children }: {
  label:       string;
  description?: string;
  children:    React.ReactNode;
}) {
  return (
    <div className="flex items-start justify-between gap-4 py-4 border-b last:border-0">
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium">{label}</p>
        {description && (
          <p className="text-xs text-muted-foreground mt-0.5">{description}</p>
        )}
      </div>
      <div className="flex-shrink-0">{children}</div>
    </div>
  );
}

function SectionHeader({ icon, title }: { icon: React.ReactNode; title: string }) {
  return (
    <div className="flex items-center gap-2 mb-1 pt-2">
      <span className="text-muted-foreground">{icon}</span>
      <h2 className="text-sm font-semibold text-foreground">{title}</h2>
    </div>
  );
}

export function MarketingSettingsPage() {
  const [settings, setSettings] = useState<SettingsState>(loadSettings);
  const [dirty, setDirty]       = useState(false);
  const { toast }               = useToast();

  function patch(update: Partial<SettingsState>) {
    setSettings((s) => ({ ...s, ...update }));
    setDirty(true);
  }

  function save() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
      setDirty(false);
      toast({ title: 'Settings saved', description: 'Your marketing preferences have been updated.' });
    } catch {
      toast({ title: 'Could not save settings', variant: 'destructive' });
    }
  }

  function reset() {
    setSettings(DEFAULT_SETTINGS);
    setDirty(true);
  }

  return (
    <div className="max-w-2xl mx-auto p-6 space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-semibold flex items-center gap-2">
            <Settings className="h-5 w-5 text-muted-foreground" />
            Marketing Settings
          </h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Configure Intelligence dashboard preferences and defaults
          </p>
        </div>
        {dirty && (
          <Button size="sm" onClick={save} className="flex-shrink-0">
            <Save className="h-3.5 w-3.5 mr-1.5" /> Save
          </Button>
        )}
      </div>

      {/* Cache & Refresh */}
      <div className="rounded-lg border bg-card p-4">
        <SectionHeader icon={<RefreshCw className="h-4 w-4" />} title="Cache & Refresh" />
        <div className="divide-y">
          <SettingRow
            label="Cache TTL"
            description="How long Intelligence data is cached before a fresh fetch."
          >
            <Select
              value={String(settings.cache_ttl_minutes)}
              onValueChange={(v) => patch({ cache_ttl_minutes: Number(v) })}
            >
              <SelectTrigger className="w-32 h-8 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="5">5 minutes</SelectItem>
                <SelectItem value="15">15 minutes</SelectItem>
                <SelectItem value="30">30 minutes</SelectItem>
                <SelectItem value="60">1 hour</SelectItem>
              </SelectContent>
            </Select>
          </SettingRow>

          <SettingRow
            label="Auto-refresh interval"
            description="Automatically reload the dashboard at this interval. Set to Off to disable."
          >
            <Select
              value={String(settings.auto_refresh_interval)}
              onValueChange={(v) => patch({ auto_refresh_interval: Number(v) })}
            >
              <SelectTrigger className="w-32 h-8 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="0">Off</SelectItem>
                <SelectItem value="5">5 minutes</SelectItem>
                <SelectItem value="15">15 minutes</SelectItem>
                <SelectItem value="30">30 minutes</SelectItem>
              </SelectContent>
            </Select>
          </SettingRow>
        </div>
      </div>

      {/* Dashboard Preferences */}
      <div className="rounded-lg border bg-card p-4">
        <SectionHeader icon={<Clock className="h-4 w-4" />} title="Dashboard Preferences" />
        <div className="divide-y">
          <SettingRow
            label="Show growth indicators"
            description="Display % change vs. previous period on KPI cards."
          >
            <Switch
              checked={settings.show_growth_indicators}
              onCheckedChange={(v) => patch({ show_growth_indicators: v })}
              aria-label="Show growth indicators"
            />
          </SettingRow>

          <SettingRow
            label="Default currency"
            description="Currency symbol displayed on monetary values."
          >
            <Select
              value={settings.default_currency}
              onValueChange={(v) => patch({ default_currency: v })}
            >
              <SelectTrigger className="w-24 h-8 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="USD">USD ($)</SelectItem>
                <SelectItem value="EGP">EGP (£)</SelectItem>
                <SelectItem value="EUR">EUR (€)</SelectItem>
                <SelectItem value="GBP">GBP (£)</SelectItem>
              </SelectContent>
            </Select>
          </SettingRow>
        </div>
      </div>

      {/* Default Filters */}
      <div className="rounded-lg border bg-card p-4">
        <SectionHeader icon={<Filter className="h-4 w-4" />} title="Default Filters" />
        <div className="divide-y">
          <SettingRow
            label="Default date range"
            description="Pre-selected date range when opening Intelligence pages."
          >
            <Select
              value={settings.default_date_preset}
              onValueChange={(v) => patch({ default_date_preset: v })}
            >
              <SelectTrigger className="w-36 h-8 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="today">Today</SelectItem>
                <SelectItem value="yesterday">Yesterday</SelectItem>
                <SelectItem value="last_7d">Last 7 days</SelectItem>
                <SelectItem value="last_30d">Last 30 days</SelectItem>
                <SelectItem value="last_90d">Last 90 days</SelectItem>
                <SelectItem value="this_month">This month</SelectItem>
                <SelectItem value="last_month">Last month</SelectItem>
              </SelectContent>
            </Select>
          </SettingRow>

          <SettingRow
            label="Default rows per page"
            description="Number of rows shown per page in analytics tables."
          >
            <Select
              value={String(settings.default_per_page)}
              onValueChange={(v) => patch({ default_per_page: Number(v) })}
            >
              <SelectTrigger className="w-24 h-8 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="10">10</SelectItem>
                <SelectItem value="25">25</SelectItem>
                <SelectItem value="50">50</SelectItem>
                <SelectItem value="100">100</SelectItem>
              </SelectContent>
            </Select>
          </SettingRow>

          <SettingRow
            label="Default chart granularity"
            description="Default time grouping on the Performance Trends page."
          >
            <Select
              value={settings.default_granularity}
              onValueChange={(v) => patch({ default_granularity: v as 'day' | 'week' | 'month' })}
            >
              <SelectTrigger className="w-28 h-8 text-sm">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="day">Daily</SelectItem>
                <SelectItem value="week">Weekly</SelectItem>
                <SelectItem value="month">Monthly</SelectItem>
              </SelectContent>
            </Select>
          </SettingRow>
        </div>
      </div>

      {/* Notifications */}
      <div className="rounded-lg border bg-card p-4">
        <SectionHeader icon={<Bell className="h-4 w-4" />} title="Alerts" />
        <div className="divide-y">
          <SettingRow
            label="Overspend alerts"
            description="Show a warning when a campaign exceeds its budget by more than 5%."
          >
            <Switch
              checked={settings.notify_overspend}
              onCheckedChange={(v) => patch({ notify_overspend: v })}
              aria-label="Overspend alerts"
            />
          </SettingRow>

          <SettingRow
            label="Sync failure alerts"
            description="Show a notice when a Meta sync job fails."
          >
            <Switch
              checked={settings.notify_sync_failure}
              onCheckedChange={(v) => patch({ notify_sync_failure: v })}
              aria-label="Sync failure alerts"
            />
          </SettingRow>
        </div>
      </div>

      {/* Actions */}
      <div className="flex items-center justify-between">
        <Button variant="ghost" size="sm" onClick={reset} className="text-muted-foreground">
          Reset to defaults
        </Button>
        <Button onClick={save} disabled={!dirty}>
          <Save className="h-4 w-4 mr-2" /> Save Settings
        </Button>
      </div>
    </div>
  );
}
