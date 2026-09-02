import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';
import { Trash2 } from 'lucide-react';

import { EntityDrawer } from '@/components/crud';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ds/use-toast';
import {
  useCreateSupplierCategory,
  useDeleteSupplierCategory,
  useSupplierCategoriesQuery,
} from '@/features/suppliers/hooks/use-supplier-categories';

type SupplierCategoryManageDrawerProps = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

function extractMessage(error: unknown): string {
  return axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
    ? error.response.data.message
    : 'Something went wrong. Please try again.';
}

/**
 * Smallest-correct-V1 management surface for the new Supplier Category lookup
 * (TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-MASTER-DATA-002) — list + inline
 * add + delete. No separate edit step; a wrong entry is deleted and recreated.
 */
export function SupplierCategoryManageDrawer({ open, onOpenChange }: SupplierCategoryManageDrawerProps) {
  const { t } = useTranslation('suppliers');
  const { data: categories, isLoading } = useSupplierCategoriesQuery(false);
  const createCategory = useCreateSupplierCategory();
  const deleteCategory = useDeleteSupplierCategory();

  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);

  function handleAdd() {
    setError(null);
    createCategory.mutate(
      { code, name, is_active: true },
      {
        onSuccess: () => {
          setCode('');
          setName('');
          toast.success(t($ => $.categorySelect.manage.added));
        },
        onError: (err) => setError(extractMessage(err)),
      },
    );
  }

  function handleDelete(id: string) {
    deleteCategory.mutate(id, {
      onSuccess: () => toast.success(t($ => $.categorySelect.manage.deleted)),
      onError: (err) => toast.error(extractMessage(err)),
    });
  }

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={t($ => $.categorySelect.manage.title)}
      description={t($ => $.categorySelect.manage.subtitle)}
    >
      {error ? (
        <Alert variant="destructive" className="mb-4">
          <AlertDescription>{error}</AlertDescription>
        </Alert>
      ) : null}

      <div className="flex flex-col gap-3">
        <div className="flex items-end gap-2">
          <div className="flex flex-1 flex-col gap-1.5">
            <label className="text-xs text-muted-foreground">{t($ => $.categorySelect.manage.codeLabel)}</label>
            <Input value={code} onChange={(e) => setCode(e.target.value)} className="font-mono" maxLength={50} />
          </div>
          <div className="flex flex-1 flex-col gap-1.5">
            <label className="text-xs text-muted-foreground">{t($ => $.categorySelect.manage.nameLabel)}</label>
            <Input value={name} onChange={(e) => setName(e.target.value)} maxLength={255} />
          </div>
          <Button
            type="button"
            onClick={handleAdd}
            disabled={createCategory.isPending || !code.trim() || !name.trim()}
          >
            {t($ => $.categorySelect.manage.add)}
          </Button>
        </div>

        <div className="mt-2 flex flex-col divide-y rounded-lg border">
          {isLoading ? (
            <div className="p-4 text-sm text-muted-foreground">{t($ => $.categorySelect.manage.loading)}</div>
          ) : (categories ?? []).length === 0 ? (
            <div className="p-4 text-sm text-muted-foreground">{t($ => $.categorySelect.manage.empty)}</div>
          ) : (
            (categories ?? []).map((c) => (
              <div key={c.id} className="flex items-center justify-between gap-2 p-3">
                <div className="flex flex-col">
                  <span className="text-sm font-medium">{c.name}</span>
                  <span className="font-mono text-xs text-muted-foreground">{c.code}</span>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="text-destructive hover:text-destructive"
                  onClick={() => handleDelete(c.id)}
                  disabled={deleteCategory.isPending}
                >
                  <Trash2 className="size-4" />
                </Button>
              </div>
            ))
          )}
        </div>
      </div>
    </EntityDrawer>
  );
}
