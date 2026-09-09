import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate } from 'react-router-dom';
import { ArrowLeft, ExternalLink, MessageCircle } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { EmptyState, LoadingState } from '@/components/crud';
import { toast } from '@/components/ds/use-toast';
import { ROUTES } from '@/router/routes';

import { useConversation, useConversations } from '../hooks/use-conversations';
import { ConversationList } from './conversation-list';
import { ConversationThread } from './conversation-thread';

/**
 * Global floating entry point (brief §15). Previously navigated straight to
 * the full Collaboration page on click — the user explicitly does not want
 * that as the default; it now opens a compact drawer that reuses the exact
 * same canonical hooks/components (ConversationList, ConversationThread) as
 * the full workspace, never a second messaging backend. "Open Full Chat" is
 * the ONLY action that navigates away — the info-panel and create-task
 * actions inside the reused ConversationThread are deliberately out of this
 * drawer's minimal scope and nudge the user toward that same button instead
 * of silently navigating themselves.
 */
export function FloatingChatLauncher() {
  const { t } = useTranslation('collaboration');
  const location = useLocation();
  const navigate = useNavigate();
  const { data: conversations = [] } = useConversations();
  const [open, setOpen] = useState(false);
  const [activeId, setActiveId] = useState<string | null>(null);

  if (location.pathname.startsWith(ROUTES.collaborationWorkspace)) {
    return null;
  }

  const unread = conversations.reduce((sum, c) => sum + (c.unread_count ?? 0), 0);

  function openFullChat(conversationId?: string) {
    setOpen(false);
    navigate(conversationId ? `${ROUTES.collaborationWorkspace}?tab=conversations&conversationId=${conversationId}` : ROUTES.collaborationWorkspace);
  }

  function nudgeToFullChat() {
    toast.info(t(($) => $.launcher.openFullChatForThis));
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label={unread > 0 ? t(($) => $.launcher.ariaLabelUnread, { count: unread }) : t(($) => $.launcher.ariaLabel)}
        className="no-print fixed bottom-20 end-4 z-30 flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg transition-transform hover:scale-105 hover:opacity-95 md:bottom-6"
      >
        <MessageCircle className="size-6" aria-hidden />
        {unread > 0 ? (
          <span className="absolute -end-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground">
            {unread > 99 ? '99+' : unread}
          </span>
        ) : null}
      </button>

      <Sheet open={open} onOpenChange={(next) => { setOpen(next); if (!next) setActiveId(null); }}>
        <SheetContent side="right" className="flex w-full flex-col gap-0 p-0 sm:max-w-sm">
          <SheetHeader className="flex-row items-center justify-between gap-2 space-y-0 border-b px-3 py-2.5">
            <div className="flex min-w-0 items-center gap-1">
              {activeId ? (
                <Button variant="ghost" size="icon" className="size-8 shrink-0" onClick={() => setActiveId(null)} aria-label={t(($) => $.launcher.back)}>
                  <ArrowLeft className="size-4" />
                </Button>
              ) : null}
              <SheetTitle className="truncate text-sm">{t(($) => $.launcher.quickChatTitle)}</SheetTitle>
            </div>
            <Button variant="outline" size="sm" className="shrink-0 gap-1.5 text-xs" onClick={() => openFullChat(activeId ?? undefined)}>
              <ExternalLink className="size-3.5" />
              {t(($) => $.launcher.openFullChat)}
            </Button>
          </SheetHeader>

          <div className="min-h-0 flex-1">
            {activeId ? (
              <QuickChatThread conversationId={activeId} onNudge={nudgeToFullChat} />
            ) : (
              <ConversationList activeConversationId={activeId} onSelect={(c) => setActiveId(c.id)} />
            )}
          </div>
        </SheetContent>
      </Sheet>
    </>
  );
}

function QuickChatThread({ conversationId, onNudge }: { conversationId: string; onNudge: () => void }) {
  const { t } = useTranslation('collaboration');
  const { data: conversation, isLoading, isError, refetch } = useConversation(conversationId);

  if (isLoading || !conversation) {
    return <div className="flex h-full items-center justify-center">{isError ? <EmptyState title={t(($) => $.conversations.detail.error)} action={<Button size="sm" variant="outline" onClick={() => refetch()}>{t(($) => $.conversations.detail.retry)}</Button>} /> : <LoadingState />}</div>;
  }

  return (
    <ConversationThread
      conversation={conversation}
      onOpenInfo={onNudge}
      onCreateTaskFromMessage={onNudge}
    />
  );
}
