import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';

import { EMOJI_CATEGORIES, type EmojiCategoryKey } from '../lib/emoji-data';

type Props = {
  onSelect: (emoji: string) => void;
  triggerClassName?: string;
};

/**
 * A plain button grid, deliberately with no text input inside the popover:
 * that sidesteps the nested-Popover-in-Dialog focus-trap class of bug
 * entirely (it only affects sustained typed focus into an input; a single
 * click on a button is unaffected regardless of an ancestor Sheet's focus
 * trap), so this needs none of EmployeeLookupField/TaskLabelPicker's
 * container-prop wiring — it works correctly used from both the full Chat
 * composer and the Quick Chat drawer's composer alike.
 */
export function EmojiPicker({ onSelect, triggerClassName }: Props) {
  const { t } = useTranslation('collaboration');
  const [open, setOpen] = useState(false);
  const [category, setCategory] = useState<EmojiCategoryKey>('smileys');

  const active = EMOJI_CATEGORIES.find((c) => c.key === category) ?? EMOJI_CATEGORIES[0];

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button type="button" variant="ghost" size="icon" className={cn('size-9 shrink-0', triggerClassName)} aria-label={t(($) => $.message.emoji)}>
          <span className="text-base leading-none" aria-hidden>🙂</span>
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-72 p-0">
        <div className="flex items-center gap-0.5 overflow-x-auto border-b p-1.5">
          {EMOJI_CATEGORIES.map((c) => (
            <button
              key={c.key}
              type="button"
              onClick={() => setCategory(c.key)}
              className={cn(
                'shrink-0 rounded-md px-2 py-1 text-base leading-none hover:bg-accent',
                category === c.key && 'bg-accent',
              )}
              aria-label={t(($) => $.emojiCategories[c.key])}
              title={t(($) => $.emojiCategories[c.key])}
            >
              {c.emoji[0]}
            </button>
          ))}
        </div>
        <ScrollArea className="h-48">
          <div className="grid grid-cols-8 gap-0.5 p-2">
            {active.emoji.map((emoji, index) => (
              <button
                key={`${emoji}-${index}`}
                type="button"
                onClick={() => {
                  onSelect(emoji);
                  setOpen(false);
                }}
                className="flex size-8 items-center justify-center rounded-md text-lg leading-none hover:bg-accent"
              >
                {emoji}
              </button>
            ))}
          </div>
        </ScrollArea>
      </PopoverContent>
    </Popover>
  );
}
