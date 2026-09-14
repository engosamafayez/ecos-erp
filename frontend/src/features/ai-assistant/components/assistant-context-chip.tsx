import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { Badge } from '@/components/ui/badge';
import type { AssistantContextHints } from '@/features/ai-assistant/types/assistant';

/**
 * §6 — the context ECOS is sending the assistant must never be invisible.
 * Renders only the already-approved, already-bounded hints (never full page
 * state) that useAssistantContext resolved.
 */
export function AssistantContextChip({ context }: { context: AssistantContextHints }) {
  const { t } = useTranslation('ai-assistant');

  const moduleLabels = useMemo<Record<string, string>>(
    () => ({
      commerce: t($ => $.context.module.commerce),
      crm: t($ => $.context.module.crm),
      inventory: t($ => $.context.module.inventory),
      finance: t($ => $.context.module.finance),
      reporting: t($ => $.context.module.reporting),
      logistics: t($ => $.context.module.logistics),
      hr: t($ => $.context.module.hr),
      procurement: t($ => $.context.module.procurement),
    }),
    [t],
  );

  const label = context.module ? (moduleLabels[context.module] ?? context.module) : t($ => $.context.none);
  const entity = context.entity_type && context.entity_id ? `${context.entity_type} ${context.entity_id.slice(0, 8)}` : null;

  return (
    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
      <span>{t($ => $.context.label)}:</span>
      <Badge variant="outline" className="text-[10px]">{label}</Badge>
      {entity ? <Badge variant="secondary" className="text-[10px]">{entity}</Badge> : null}
    </div>
  );
}
