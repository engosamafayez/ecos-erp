import { z } from 'zod';

export const recipeLineSchema = z.object({
  raw_material_id: z.string().min(1, 'Raw material is required'),
  quantity: z.number().min(0.0001, 'Quantity must be greater than 0'),
  waste_percentage: z.number().min(0).max(100),
});

export const recipeFormSchema = z.object({
  product_id: z.string().min(1, 'Finished good is required'),
  notes: z.string().max(2000).nullable().optional(),
  lines: z.array(recipeLineSchema).min(1, 'At least one material line is required'),
});

export type RecipeFormValues = z.infer<typeof recipeFormSchema>;
