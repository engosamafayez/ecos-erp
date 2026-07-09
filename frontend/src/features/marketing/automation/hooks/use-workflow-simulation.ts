import { useMutation } from '@tanstack/react-query';
import axios from 'axios';
import type { SimulationResult } from '../types/automation';

export function useSimulateWorkflow(workflowId: string) {
  return useMutation({
    mutationFn: async (sampleContext?: Record<string, unknown>) => {
      const { data } = await axios.post<SimulationResult>(
        `/api/marketing/automation/workflows/${workflowId}/simulate`,
        { sample_context: sampleContext ?? {} },
      );
      return data;
    },
  });
}
