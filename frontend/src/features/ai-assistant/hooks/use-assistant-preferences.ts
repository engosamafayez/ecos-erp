import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { assistantService } from '@/features/ai-assistant/services/assistant-service';
import type { AssistantPreferences } from '@/features/ai-assistant/types/assistant';

const PREFERENCES_KEY = ['ai-assistant', 'preferences'];

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §9 — server is
 * always authoritative (never localStorage); react-query's own cache is the only
 * client-side caching layer, and it is invalidated on every successful save so a
 * stale value never lingers after a change.
 */
export function useAssistantPreferencesQuery() {
  return useQuery({
    queryKey: PREFERENCES_KEY,
    queryFn: assistantService.getPreferences,
    staleTime: 5 * 60 * 1000,
  });
}

export function useUpdateAssistantPreferencesMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: AssistantPreferences) => assistantService.updatePreferences(payload),
    onSuccess: (saved) => {
      queryClient.setQueryData(PREFERENCES_KEY, saved);
    },
  });
}
