import * as React from 'react';
import * as PopoverPrimitive from '@radix-ui/react-popover';

import { cn } from '@/lib/utils';

function Popover({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Root>) {
  return <PopoverPrimitive.Root data-slot="popover" {...props} />;
}

function PopoverTrigger({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Trigger>) {
  return <PopoverPrimitive.Trigger data-slot="popover-trigger" {...props} />;
}

function PopoverContent({
  className,
  align = 'center',
  sideOffset = 4,
  container,
  ...props
}: React.ComponentProps<typeof PopoverPrimitive.Content> & {
  /**
   * Forwarded to the underlying Portal (TASK-ECOS-SYSTEM-WIDE-SEARCHABLE-SELECT-
   * FOCUS-REMEDIATION-006, §4/§6). Defaults to Radix's own default
   * (`document.body`) when omitted — every existing caller is unaffected.
   *
   * Pass the nearest ancestor Dialog/Sheet's own content node when this content
   * holds a focusable/typeable control (a search input, a button) AND is
   * rendered inside a Dialog/Sheet/Drawer: that dialog's `FocusScope` (trapped)
   * redirects focus back inside itself the instant it sees focus land on
   * anything outside its own DOM subtree — and Portal's default target,
   * `document.body`, makes this content a DOM SIBLING of the dialog's own
   * portalled content, never a descendant, so the redirect fires on every
   * focus attempt in here. See ecos-combobox.tsx for the fully-documented
   * root cause and the same fix applied there.
   */
  container?: HTMLElement | null;
}) {
  return (
    <PopoverPrimitive.Portal container={container}>
      <PopoverPrimitive.Content
        data-slot="popover-content"
        align={align}
        sideOffset={sideOffset}
        className={cn(
          'bg-popover text-popover-foreground data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 z-50 w-72 rounded-md border p-4 shadow-md outline-hidden',
          className,
        )}
        {...props}
      />
    </PopoverPrimitive.Portal>
  );
}

function PopoverAnchor({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Anchor>) {
  return <PopoverPrimitive.Anchor data-slot="popover-anchor" {...props} />;
}

export { Popover, PopoverAnchor, PopoverContent, PopoverTrigger };
