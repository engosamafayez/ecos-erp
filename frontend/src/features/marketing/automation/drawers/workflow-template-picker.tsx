import { useState } from 'react';
import { Search, Zap } from 'lucide-react';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useWorkflowTemplates, useCreateWorkflowFromTemplate } from '../hooks/use-workflow-templates';
import type { AutomationWorkflowTemplate, WorkflowTemplateCategory } from '../types/automation';

interface Props {
  open: boolean;
  onClose: () => void;
}

const CATEGORY_LABELS: Record<WorkflowTemplateCategory, string> = {
  welcome_series:        'Welcome Series',
  abandoned_cart:        'Abandoned Cart',
  lead_nurturing:        'Lead Nurturing',
  no_reply_reminder:     'No Reply Reminder',
  payment_reminder:      'Payment Reminder',
  shipment_notification: 'Shipment Notification',
  order_delivered:       'Order Delivered',
  review_request:        'Review Request',
  birthday_campaign:     'Birthday Campaign',
  vip_upgrade:           'VIP Upgrade',
  win_back_customer:     'Win-Back',
  seasonal_campaign:     'Seasonal Campaign',
  ramadan_journey:       'Ramadan Journey',
  black_friday_journey:  'Black Friday Journey',
  product_launch:        'Product Launch',
  custom:                'Custom',
};

const CATEGORY_ICONS: Record<WorkflowTemplateCategory, string> = {
  welcome_series:        'ðŸ‘‹',
  abandoned_cart:        'ðŸ›’',
  lead_nurturing:        'ðŸŒ±',
  no_reply_reminder:     'ðŸ“©',
  payment_reminder:      'ðŸ’³',
  shipment_notification: 'ðŸ“¦',
  order_delivered:       'âœ…',
  review_request:        'â­',
  birthday_campaign:     'ðŸŽ‚',
  vip_upgrade:           'ðŸ‘‘',
  win_back_customer:     'ðŸ”',
  seasonal_campaign:     'ðŸŒŸ',
  ramadan_journey:       'ðŸŒ™',
  black_friday_journey:  'ðŸ›ï¸',
  product_launch:        'ðŸš€',
  custom:                'âš™ï¸',
};

function TemplateCard({ template, onUse }: { template: AutomationWorkflowTemplate; onUse: () => void }) {
  return (
    <div className="bg-card border rounded-lg p-3 hover:border-primary transition-colors">
      <div className="flex items-start gap-2 mb-2">
        <span className="text-lg">{CATEGORY_ICONS[template.category] ?? 'âš™ï¸'}</span>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-medium truncate">{template.name}</p>
          <p className="text-xs text-muted-foreground">{CATEGORY_LABELS[template.category]}</p>
        </div>
        {template.is_global && (
          <span className="text-xs bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded flex-shrink-0">Global</span>
        )}
      </div>
      {template.description && (
        <p className="text-xs text-muted-foreground line-clamp-2 mb-3">{template.description}</p>
      )}
      <div className="flex items-center justify-between">
        <span className="text-xs text-muted-foreground">Used {template.usage_count}Ã—</span>
        <Button size="sm" className="h-6 text-xs" onClick={onUse}>Use Template</Button>
      </div>
    </div>
  );
}

export function WorkflowTemplatePicker({ open, onClose }: Props) {
  const [search, setSearch]         = useState('');
  const [category, setCategory]     = useState<WorkflowTemplateCategory | undefined>();

  const { data, isLoading }         = useWorkflowTemplates({ search: search || undefined, category });
  const createFromTemplate          = useCreateWorkflowFromTemplate();

  const templates = data?.data ?? [];

  const categories = [...new Set(templates.map(t => t.category))] as WorkflowTemplateCategory[];

  async function handleUse(template: AutomationWorkflowTemplate) {
    await createFromTemplate.mutateAsync({ templateId: template.id, overrides: {} });
    onClose();
  }

  return (
    <Sheet open={open} onOpenChange={v => !v && onClose()}>
      <SheetContent className="w-[540px] flex flex-col">
        <SheetHeader>
          <SheetTitle>Workflow Templates</SheetTitle>
        </SheetHeader>

        <div className="relative mt-4">
          <Search className="absolute left-2.5 top-2 h-3.5 w-3.5 text-muted-foreground" />
          <Input
            placeholder="Search templates..."
            value={search}
            onChange={e => setSearch(e.target.value)}
            className="pl-8 h-8"
          />
        </div>

        {categories.length > 0 && (
          <div className="flex gap-1 flex-wrap mt-3">
            <button
              onClick={() => setCategory(undefined)}
              className={`text-xs px-2.5 py-1 rounded-full transition-colors ${
                !category ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'
              }`}
            >
              All
            </button>
            {categories.map(cat => (
              <button
                key={cat}
                onClick={() => setCategory(cat)}
                className={`text-xs px-2.5 py-1 rounded-full transition-colors ${
                  category === cat ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground hover:bg-muted/80'
                }`}
              >
                {CATEGORY_ICONS[cat]} {CATEGORY_LABELS[cat]}
              </button>
            ))}
          </div>
        )}

        <div className="flex-1 overflow-y-auto mt-4">
          {isLoading ? (
            <div className="text-sm text-muted-foreground">Loading templates...</div>
          ) : templates.length === 0 ? (
            <div className="flex flex-col items-center justify-center h-40 gap-2">
              <Zap className="h-6 w-6 text-muted-foreground" />
              <p className="text-sm text-muted-foreground">No templates found.</p>
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-2">
              {templates.map(t => (
                <TemplateCard key={t.id} template={t} onUse={() => handleUse(t)} />
              ))}
            </div>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

