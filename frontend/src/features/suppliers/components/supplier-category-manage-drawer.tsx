import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';
import { Pencil, Trash2 } from 'lucide-react';

import { EntityDrawer } from '@/components/crud';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ds/use-toast';
import {
  useCreateSupplierCategory,
  useDeleteSupplierCategory,
  useSupplierCategoriesQuery,
  useUpdateSupplierCategory,
} from '@/features/suppliers/hooks/use-supplier-categories';
import type { SupplierCategory } from '@/features/suppliers/types/supplier';

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
  const updateCategory = useUpdateSupplierCategory();
  const deleteCategory = useDeleteSupplierCategory();

  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [error, setError] = useState<string | null>(null);

  // §2 — inline edit, since a separate edit page/drawer would be heavier than this small
  // lookup needs. Archive reuses the same update mutation (toggling is_active) as a safer
  // alternative to delete when a category is still assigned to suppliers (delete is now
  // guarded server-side and returns a clear error in that case).
  const [editingId, setEditingId] = useState<string | null>(null);
  const [editCode, setEditCode] = useState('');
  const [editName, setEditName] = useState('');

  function startEdit(c: SupplierCategory) {
    setEditingId(c.id);
    setEditCode(c.code);
    setEditName(c.name);
  }

  function saveEdit(id: string) {
    updateCategory.mutate(
      { id, payload: { code: editCode, name: editName, is_active: true } },
      {
        onSuccess: () => { setEditingId(null); toast.success(t($ => $.categorySelect.manage.updated)); },
        onError: (err) => toast.error(extractMessage(err)),
      },
    );
  }

  function toggleArchive(c: SupplierCategory) {
    updateCategory.mutate(
      { id: c.id, payload: { code: c.code, name: c.name, is_active: !c.is_active } },
      {
        onSuccess: () => toast.success(c.is_active ? t($ => $.categorySelect.manage.archived) : t($ => $.categorySelect.manage.restored)),
        onError: (err) => toast.error(extractMessage(err)),
      },
    );
  }

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
                {editingId === c.id ? (
                  <>
                    <div className="flex flex-1 items-center gap-2">
                      <Input value={editCode} onChange={(e) => setEditCode(e.target.value)} className="w-28 font-mono" maxLength={50} />
                      <Input value={editName} onChange={(e) => setEditName(e.target.value)} className="flex-1" maxLength={255} />
                    </div>
                    <div className="flex items-center gap-1">
                      <Button type="button" size="sm" onClick={() => saveEdit(c.id)} disabled={updateCategory.isPending || !editCode.trim() || !editName.trim()}>
                        {t($ => $.categorySelect.manage.save)}
                      </Button>
                      <Button type="button" size="sm" variant="ghost" onClick={() => setEditingId(null)}>
                        {t($ => $.categorySelect.manage.cancel)}
                      </Button>
                    </div>
                  </>
                ) : (
                  <>
                    <div className={`flex flex-col ${c.is_active ? '' : 'opacity-50'}`}>
                      <span className="text-sm font-medium">
                        {c.name}
                        {!c.is_active && <span className="ms-1.5 rounded bg-muted px-1.5 py-0.5 text-[10px] font-normal uppercase text-muted-foreground">{t($ => $.categorySelect.manage.archivedBadge)}</span>}
                      </span>
                      <span className="font-mono text-xs text-muted-foreground">{c.code}</span>
                    </div>
                    <div className="flex items-center gap-1">
                      <Button type="button" variant="ghost" size="icon" onClick={() => startEdit(c)} aria-label={t($ => $.categorySelect.manage.edit)}>
                        <Pencil className="size-4" />
                      </Button>
                      <Button type="button" variant="ghost" size="sm" className="text-xs" onClick={() => toggleArchive(c)} disabled={updateCategory.isPending}>
                        {c.is_active ? t($ => $.categorySelect.manage.archive) : t($ => $.categorySelect.manage.restore)}
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="text-destructive hover:text-destructive"
                        onClick={() => handleDelete(c.id)}
                        disabled={deleteCategory.isPending}
                        aria-label={t($ => $.categorySelect.manage.delete)}
                      >
                        <Trash2 className="size-4" />
                      </Button>
                    </div>
                  </>
                )}
              </div>
            ))
          )}
        </div>
      </div>
    </EntityDrawer>
  );
}
