import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import type { AssistantContextHints } from '@/features/ai-assistant/types/assistant';

/**
 * §10 — contextual shortcuts only; selecting one just pre-fills/sends the
 * composer, so every suggested prompt goes through the exact same request path
 * a typed message would. Flat, explicit translation keys per module (rather
 * than an array/returnObjects lookup) so every prompt is a plain, verified
 * string leaf like every other t() call in this codebase.
 */
export function AssistantSuggestedPrompts({
  context,
  onSelect,
}: {
  context: AssistantContextHints;
  onSelect: (prompt: string) => void;
}) {
  const { t } = useTranslation('ai-assistant');

  const byModule: Record<string, string[]> = {
    commerce: [t($ => $.suggestedPrompts.commerce1), t($ => $.suggestedPrompts.commerce2), t($ => $.suggestedPrompts.commerce3)],
    crm: [t($ => $.suggestedPrompts.crm1), t($ => $.suggestedPrompts.crm2)],
    inventory: [t($ => $.suggestedPrompts.inventory1)],
    finance: [t($ => $.suggestedPrompts.finance1)],
    reporting: [t($ => $.suggestedPrompts.reporting1)],
    logistics: [t($ => $.suggestedPrompts.logistics1)],
    procurement: [t($ => $.suggestedPrompts.procurement1)],
  };

  const prompts = (context.module ? byModule[context.module] : undefined) ?? [t($ => $.suggestedPrompts.default1)];

  if (prompts.length === 0) return null;

  return (
    <div className="flex flex-wrap gap-1.5 p-2">
      {prompts.map((prompt) => (
        <Button key={prompt} variant="outline" size="sm" className="h-auto whitespace-normal py-1 text-xs" onClick={() => onSelect(prompt)}>
          {prompt}
        </Button>
      ))}
    </div>
  );
}
