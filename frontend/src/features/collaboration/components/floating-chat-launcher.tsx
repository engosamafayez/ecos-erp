import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate } from 'react-router-dom';
import { MessageCircle } from 'lucide-react';

import { ROUTES } from '@/router/routes';

import { useConversations } from '../hooks/use-conversations';

/**
 * Global floating entry point (architecture report §23): mounted once at the
 * app shell so it is visible from every non-Collaboration page. Reuses the
 * SAME `useConversations()` query (and its existing 15s poll) the workspace
 * itself already runs — unread is summed client-side from the per-
 * conversation counts already returned, so this adds no new backend
 * endpoint, no second realtime transport, and no duplicate notification
 * engine. Hidden while already inside Collaboration, where it would just
 * float over the workspace it links to.
 */
export function FloatingChatLauncher() {
  const { t } = useTranslation('collaboration');
  const location = useLocation();
  const navigate = useNavigate();
  const { data: conversations = [] } = useConversations();

  if (location.pathname.startsWith(ROUTES.collaborationWorkspace)) {
    return null;
  }

  const unread = conversations.reduce((sum, c) => sum + (c.unread_count ?? 0), 0);

  return (
    <button
      type="button"
      onClick={() => navigate(ROUTES.collaborationWorkspace)}
      aria-label={unread > 0 ? t(($) => $.launcher.ariaLabelUnread, { count: unread }) : t(($) => $.launcher.ariaLabel)}
      className="fixed bottom-20 end-4 z-30 flex size-14 items-center justify-center rounded-full bg-primary text-primary-foreground shadow-lg transition-transform hover:scale-105 hover:opacity-95 md:bottom-6"
    >
      <MessageCircle className="size-6" aria-hidden />
      {unread > 0 ? (
        <span className="absolute -end-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground">
          {unread > 99 ? '99+' : unread}
        </span>
      ) : null}
    </button>
  );
}
