import { useMutation, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';

const BASE = '/api/marketing';

export function useMetaAuthUrl() {
  return useMutation({
    mutationFn: async (companyId: string) => {
      const { data } = await axios.get<{ url: string; state: string }>(
        `${BASE}/meta/auth/redirect`,
        { params: { company_id: companyId } },
      );
      return data;
    },
  });
}

export function useMetaCallback() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async ({ code, state }: { code: string; state: string }) => {
      const { data } = await axios.get(`${BASE}/meta/auth/callback`, {
        params: { code, state },
      });
      return data;
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['marketing-connections'] });
      qc.invalidateQueries({ queryKey: ['marketing-dashboard'] });
    },
  });
}
