import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { recipesService } from '@/features/recipes/services/recipes-service';
import type { Recipe, RecipePayload, RecipesQuery } from '@/features/recipes/types/recipe';

const RECIPES_KEY = 'recipes';

export function useRecipesQuery(params: RecipesQuery) {
  return useQuery({
    queryKey: [RECIPES_KEY, params],
    queryFn: () => recipesService.list(params),
    placeholderData: keepPreviousData,
  });
}

export function useRecipeQuery(id: string) {
  return useQuery({
    queryKey: [RECIPES_KEY, id],
    queryFn: () => recipesService.get(id),
    enabled: Boolean(id),
  });
}

export function useCreateRecipe() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: RecipePayload) => recipesService.create(payload),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [RECIPES_KEY] }),
  });
}

export function useUpdateRecipe(id: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: RecipePayload) => recipesService.update(id, payload),
    onSuccess: (updatedRecipe) => {
      queryClient.setQueryData([RECIPES_KEY, id], updatedRecipe);
      queryClient.invalidateQueries({ queryKey: [RECIPES_KEY] });
    },
  });
}

export function useDeleteRecipe() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => recipesService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [RECIPES_KEY] }),
  });
}

export function useToggleRecipeStatus() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (recipe: Recipe) => recipesService.toggleStatus(recipe),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: [RECIPES_KEY] }),
  });
}
