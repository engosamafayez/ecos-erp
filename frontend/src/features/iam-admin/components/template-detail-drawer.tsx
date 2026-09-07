import { useState } from 'react';
import axios from 'axios';
import { useTranslation } from 'react-i18next';

import { ConfirmDialog, EntityDrawer, ErrorState, LoadingState } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { FormField } from '@/components/ui/forms/form-field';
import { Can } from '@/features/authorization';
import {
  useArchiveRoleTemplate,
  useDeleteRoleTemplate,
  useRoleTemplateQuery,
  useTemplateVersionsQuery,
  useUpdateRoleTemplate,
} from '@/features/iam-admin/hooks/use-role-templates';
import type { RoleTemplateDetail, UpdateRoleTemplatePayload } from '@/features/iam-admin/types/role-template';

import { PermissionMatrix } from './permission-matrix';
import { TemplateApplyWorkflow } from './template-apply-workflow';

export function TemplateDetailDrawer({
  templateKey,
  open,
  onOpenChange,
}: {
  templateKey: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const query = useRoleTemplateQuery(templateKey);

  return (
    <EntityDrawer
      open={open}
      onOpenChange={onOpenChange}
      title={query.data?.name_ar ?? t(($) => $.roleTemplates.detail.title)}
      description={query.data?.description_ar ?? query.data?.description ?? undefined}
    >
      {query.isLoading ? (
        <LoadingState />
      ) : query.isError ? (
        <ErrorState description={query.error instanceof Error ? query.error.message : undefined} />
      ) : query.data ? (
        <TemplateDetailContent template={query.data} onDeleted={() => onOpenChange(false)} />
      ) : null}
    </EntityDrawer>
  );
}

function TemplateDetailContent({
  template,
  onDeleted,
}: {
  template: RoleTemplateDetail;
  onDeleted: () => void;
}) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const updateTemplate = useUpdateRoleTemplate(template.key);
  const archiveTemplate = useArchiveRoleTemplate(template.key);
  const deleteTemplate = useDeleteRoleTemplate(template.key);
  const versionsQuery = useTemplateVersionsQuery(template.key);

  const [values, setValues] = useState<UpdateRoleTemplatePayload>({
    name: template.name,
    description: template.description ?? '',
    category: template.category,
    definition: template.definition,
  });
  const [serverError, setServerError] = useState<string | null>(null);
  const [archiveOpen, setArchiveOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [applyOpen, setApplyOpen] = useState(false);

  // Keeps `values` in sync whenever the `template` prop changes identity (refetch, save,
  // switching templates) — adjusted during render (React's documented pattern for this) rather
  // than in a useEffect, which would cost an extra render.
  const [prevTemplate, setPrevTemplate] = useState(template);
  if (template !== prevTemplate) {
    setPrevTemplate(template);
    setValues({
      name: template.name,
      description: template.description ?? '',
      category: template.category,
      definition: template.definition,
    });
  }

  const editable = !template.is_system;

  function handleSave() {
    setServerError(null);
    updateTemplate.mutate(values, {
      onError: (error) =>
        setServerError(
          axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
            ? error.response.data.message
            : t(($) => $.roleTemplates.form.genericError),
        ),
    });
  }

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          {template.is_system ? (
            <Badge variant="outline">{t(($) => $.roleTemplates.systemBadge)}</Badge>
          ) : (
            <Badge variant="secondary">{t(($) => $.roleTemplates.customBadge)}</Badge>
          )}
          <Badge variant="outline">v{template.version}</Badge>
          <Badge variant="outline">{template.status}</Badge>
        </div>
        <span className="text-muted-foreground text-xs">
          {t(($) => $.roleTemplates.detail.assignmentCount, { count: template.assignment_count })}
        </span>
      </div>

      {template.is_system ? (
        <Alert>
          <AlertDescription>{t(($) => $.roleTemplates.detail.systemImmutable)}</AlertDescription>
        </Alert>
      ) : null}

      <Tabs defaultValue="definition">
        <TabsList>
          <TabsTrigger value="definition">{t(($) => $.roleTemplates.detail.tabs.definition)}</TabsTrigger>
          <TabsTrigger value="versions">{t(($) => $.roleTemplates.detail.tabs.versions)}</TabsTrigger>
        </TabsList>

        <TabsContent value="definition" className="flex flex-col gap-4 pt-4">
          {serverError ? (
            <Alert variant="destructive">
              <AlertTitle>{t(($) => $.roleTemplates.form.genericError)}</AlertTitle>
              <AlertDescription>{serverError}</AlertDescription>
            </Alert>
          ) : null}

          <fieldset disabled={!editable} className="contents">
            <FormField name="name" label={t(($) => $.roleTemplates.fields.name)} required>
              <Input value={values.name ?? ''} onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))} />
            </FormField>
            <FormField name="description" label={t(($) => $.roleTemplates.fields.description)} optional>
              <Textarea
                value={values.description ?? ''}
                onChange={(e) => setValues((v) => ({ ...v, description: e.target.value }))}
              />
            </FormField>
          </fieldset>

          {/* §16 — the same grouped editable Permission Matrix the Roles editor uses. Kept
             outside the metadata `fieldset` so its own read-only mode (system templates)
             is expressed via `readOnly`, matching how the matrix is used everywhere else. */}
          <div>
            <p className="mb-1.5 text-sm font-medium">{t(($) => $.roleTemplates.fields.permissions)}</p>
            <PermissionMatrix
              value={values.definition?.permissions ?? []}
              onChange={(permissions) => setValues((v) => ({ ...v, definition: { ...v.definition, permissions } }))}
              readOnly={!editable}
            />
          </div>

          {editable ? (
            <Can permission="iam.role-templates.update">
              <div className="flex items-center justify-between border-t pt-4">
                <Button type="button" onClick={handleSave} disabled={updateTemplate.isPending}>
                  {updateTemplate.isPending ? tCommon(($) => $.actions.working) : tCommon(($) => $.common.save)}
                </Button>
                {/* §12/D12: saving the definition never auto-applies it — the explicit
                   Preview Impact → Apply workflow is the only path that changes holders. */}
                <Button type="button" variant="outline" onClick={() => setApplyOpen(true)}>
                  {t(($) => $.roleTemplates.detail.applyTrigger)}
                </Button>
              </div>
            </Can>
          ) : null}

          {!template.is_system ? (
            <div className="flex items-center gap-2 border-t pt-4">
              <Can permission="iam.role-templates.update">
                <Button type="button" variant="outline" onClick={() => setArchiveOpen(true)}>
                  {t(($) => $.roleTemplates.detail.archiveTrigger)}
                </Button>
              </Can>
              {/* §14/D13: delete is only ever offered when the backend already reports zero
                 assignments — never a raw "delete" affordance for a template that's in use. */}
              {template.assignment_count === 0 ? (
                <Can permission="iam.role-templates.delete">
                  <Button type="button" variant="destructive" onClick={() => setDeleteOpen(true)}>
                    {t(($) => $.roleTemplates.detail.deleteTrigger)}
                  </Button>
                </Can>
              ) : null}
            </div>
          ) : null}
        </TabsContent>

        <TabsContent value="versions" className="pt-4">
          {versionsQuery.isLoading ? (
            <LoadingState />
          ) : versionsQuery.isError ? (
            <ErrorState />
          ) : (
            <div className="flex flex-col gap-2">
              {(versionsQuery.data ?? []).map((entry) => (
                <div key={entry.version} className="rounded-md border px-3 py-2 text-sm">
                  <div className="flex items-center justify-between">
                    <span className="font-medium">v{entry.version}</span>
                    <span className="text-muted-foreground text-xs">
                      {entry.created_at ? new Date(entry.created_at).toLocaleString() : '—'}
                    </span>
                  </div>
                  {entry.change_note ? <p className="text-muted-foreground text-xs">{entry.change_note}</p> : null}
                </div>
              ))}
            </div>
          )}
        </TabsContent>
      </Tabs>

      <ConfirmDialog
        open={archiveOpen}
        onOpenChange={setArchiveOpen}
        title={t(($) => $.roleTemplates.detail.archiveConfirmTitle)}
        description={t(($) => $.roleTemplates.detail.archiveConfirmDescription)}
        loading={archiveTemplate.isPending}
        onConfirm={() => archiveTemplate.mutate(undefined, { onSuccess: () => setArchiveOpen(false) })}
      />
      <ConfirmDialog
        open={deleteOpen}
        onOpenChange={setDeleteOpen}
        title={t(($) => $.roleTemplates.detail.deleteConfirmTitle)}
        description={t(($) => $.roleTemplates.detail.deleteConfirmDescription)}
        variant="destructive"
        loading={deleteTemplate.isPending}
        onConfirm={() => deleteTemplate.mutate(undefined, { onSuccess: onDeleted })}
      />
      <TemplateApplyWorkflow template={template} open={applyOpen} onOpenChange={setApplyOpen} />
    </div>
  );
}
