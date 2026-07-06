import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type { Recipe, RecipePayload, RecipesQuery, RecipesResult } from '@/features/recipes/types/recipe';

// Recipes share the /boms backend endpoint — no backend changes required.
export const recipesService = {
  async list(params: RecipesQuery): Promise<RecipesResult> {
    const { data } = await api.get<ApiResponse<RecipesResult>>('/boms', { params });
    return data.data;
  },

  async get(id: string): Promise<Recipe> {
    const { data } = await api.get<ApiResponse<Recipe>>(`/boms/${id}`);
    return data.data;
  },

  async create(payload: RecipePayload): Promise<Recipe> {
    const { data } = await api.post<ApiResponse<Recipe>>('/boms', payload);
    return data.data;
  },

  async update(id: string, payload: RecipePayload): Promise<Recipe> {
    const { data } = await api.put<ApiResponse<Recipe>>(`/boms/${id}`, payload);
    return data.data;
  },

  async remove(id: string): Promise<void> {
    await api.delete(`/boms/${id}`);
  },

  async toggleStatus(recipe: Recipe): Promise<Recipe> {
    const payload: RecipePayload = {
      product_id:              recipe.product_id,
      version:                 recipe.version,
      is_active:               !recipe.is_active,
      notes:                   recipe.notes,
      manufacturing_cost:      recipe.manufacturing_cost ?? 0,
      other_costs:             recipe.other_costs ?? 0,
      execution_instructions:  recipe.execution_instructions,
      lines: (recipe.lines ?? []).map((l) => ({
        raw_material_id:  l.raw_material_id,
        quantity:         l.quantity,
        waste_percentage: l.waste_percentage ?? 0,
      })),
    };
    const { data } = await api.put<ApiResponse<Recipe>>(`/boms/${recipe.id}`, payload);
    return data.data;
  },
};
