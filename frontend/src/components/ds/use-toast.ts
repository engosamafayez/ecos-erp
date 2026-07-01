import { create } from 'zustand';

export type ToastType = 'success' | 'error' | 'warning' | 'info';

export type Toast = {
  id: string;
  type: ToastType;
  title: string;
  description?: string;
  durationMs?: number;
};

type ToastStore = {
  toasts: Toast[];
  toast: (t: Omit<Toast, 'id'>) => string;
  dismiss: (id: string) => void;
  dismissAll: () => void;
};

let counter = 0;
function uid() {
  return `toast-${++counter}-${Date.now()}`;
}

export const useToastStore = create<ToastStore>((set) => ({
  toasts: [],

  toast(t) {
    const id = uid();
    set((s) => ({ toasts: [...s.toasts, { ...t, id }] }));
    return id;
  },

  dismiss(id) {
    set((s) => ({ toasts: s.toasts.filter((t) => t.id !== id) }));
  },

  dismissAll() {
    set({ toasts: [] });
  },
}));

/** Convenience hook for dispatching toasts anywhere in the tree. */
export function useToast() {
  const { toast, dismiss, dismissAll } = useToastStore();
  return {
    toast,
    dismiss,
    dismissAll,
    success: (title: string, description?: string) =>
      toast({ type: 'success', title, description }),
    error: (title: string, description?: string) =>
      toast({ type: 'error', title, description }),
    warning: (title: string, description?: string) =>
      toast({ type: 'warning', title, description }),
    info: (title: string, description?: string) =>
      toast({ type: 'info', title, description }),
  };
}

/**
 * Imperative toast helper — safe to call from TanStack Query mutation callbacks,
 * async functions, and anywhere outside React's render phase.
 * Uses Zustand's getState() instead of a React hook so it adds no hooks to the chain.
 */
export const toast = {
  success: (title: string, description?: string) =>
    useToastStore.getState().toast({ type: 'success', title, description }),
  error: (title: string, description?: string) =>
    useToastStore.getState().toast({ type: 'error', title, description }),
  warning: (title: string, description?: string) =>
    useToastStore.getState().toast({ type: 'warning', title, description }),
  info: (title: string, description?: string) =>
    useToastStore.getState().toast({ type: 'info', title, description }),
};
