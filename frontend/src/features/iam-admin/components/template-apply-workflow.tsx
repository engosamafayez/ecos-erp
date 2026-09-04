import { useState } from 'react';
import axios from 'axios';
import { AlertTriangle } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { ErrorState, LoadingState } from '@/components/crud';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useApplyRoleTemplate, useTemplateImpactPreview } from '@/features/iam-admin/hooks/use-role-templates';
import type { RoleTemplateDetail } from '@/features/iam-admin/types/role-template';

/**
 * D12 (TASK-ECOS-IAM-SECURE-ADMIN-API-002 / this task's §15, CTO-ruled): every holder of a
 * template shares exactly ONE compiled Role — applying a new version is necessarily
 * TEMPLATE-WIDE, not a per-holder operation. This component exists specifically to make that
 * scope unmistakable before the actor confirms: it shows the affected-holder COUNT prominently
 * and never offers a "selected users" option, because no such backend operation exists.
 */
export function TemplateApplyWorkflow({
  template,
  open,
  onOpenChange,
}: {
  template: RoleTemplateDetail;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { t } = useTranslation('iam-admin');
  const { t: tCommon } = useTranslation('common');
  const preview = useTemplateImpactPreview(template.key, open);
  const apply = useApplyRoleTemplate(template.key);
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<{ affected_holders: number; permission_count: number } | null>(null);

  function handleClose(next: boolean) {
    if (!next) {
      setError(null);
      setResult(null);
    }
    onOpenChange(next);
  }

  function handleApply() {
    setError(null);
    apply.mutate(undefined, {
      onSuccess: (data) => setResult({ affected_holders: data.affected_holders, permission_count: data.permission_count }),
      onError: (err) =>
        setError(
          axios.isAxiosError(err) && typeof err.response?.data?.message === 'string'
            ? err.response.data.message
            : t(($) => $.roleTemplates.apply.genericError),
        ),
    });
  }

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{t(($) => $.roleTemplates.apply.title)}</DialogTitle>
        </DialogHeader>

        {result ? (
          <Alert>
            <AlertTitle>{t(($) => $.roleTemplates.apply.successTitle)}</AlertTitle>
            <AlertDescription>
              {t(($) => $.roleTemplates.apply.successDescription, { count: result.affected_holders })}
            </AlertDescription>
          </Alert>
        ) : preview.isLoading ? (
          <LoadingState />
        ) : preview.isError ? (
          <ErrorState description={preview.error instanceof Error ? preview.error.message : undefined} />
        ) : preview.data ? (
          <div className="flex flex-col gap-4">
            {/* §15: the scope statement is not optional copy — it's the point of this dialog. */}
            <Alert variant="default" className="border-amber-500/40 bg-amber-500/5">
              <AlertTriangle className="size-4 text-amber-600" />
              <AlertTitle>{t(($) => $.roleTemplates.apply.scopeWarningTitle)}</AlertTitle>
              <AlertDescription>
                {t(($) => $.roleTemplates.apply.scopeWarningDescription, {
                  count: preview.data.affected_holders,
                })}
              </AlertDescription>
            </Alert>

            <div className="grid grid-cols-2 gap-3 text-sm">
              <div>
                <div className="text-muted-foreground text-xs">{t(($) => $.roleTemplates.apply.currentVersion)}</div>
                <div className="font-medium">v{preview.data.template_version}</div>
              </div>
              <div>
                <div className="text-muted-foreground text-xs">{t(($) => $.roleTemplates.apply.affectedHolders)}</div>
                <div className="font-medium">{preview.data.affected_holders}</div>
              </div>
            </div>

            <div>
              <div className="text-muted-foreground mb-1 text-xs">{t(($) => $.roleTemplates.apply.additions)}</div>
              {preview.data.permission_additions.length === 0 ? (
                <p className="text-muted-foreground text-xs">{t(($) => $.roleTemplates.apply.none)}</p>
              ) : (
                <div className="flex flex-wrap gap-1">
                  {preview.data.permission_additions.map((permission) => (
                    <Badge key={permission} className="bg-emerald-500/10 text-emerald-700 font-mono text-xs dark:text-emerald-400">
                      +{permission}
                    </Badge>
                  ))}
                </div>
              )}
            </div>

            <div>
              <div className="text-muted-foreground mb-1 text-xs">{t(($) => $.roleTemplates.apply.removals)}</div>
              {preview.data.permission_removals.length === 0 ? (
                <p className="text-muted-foreground text-xs">{t(($) => $.roleTemplates.apply.none)}</p>
              ) : (
                <div className="flex flex-wrap gap-1">
                  {preview.data.permission_removals.map((permission) => (
                    <Badge key={permission} variant="destructive" className="font-mono text-xs">
                      −{permission}
                    </Badge>
                  ))}
                </div>
              )}
            </div>

            {error ? (
              <Alert variant="destructive">
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            ) : null}
          </div>
        ) : null}

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => handleClose(false)}>
            {result ? tCommon(($) => $.common.close) : tCommon(($) => $.common.cancel)}
          </Button>
          {!result ? (
            <Button
              type="button"
              variant="destructive"
              disabled={apply.isPending || preview.isLoading || preview.isError}
              onClick={handleApply}
            >
              {apply.isPending ? tCommon(($) => $.actions.working) : t(($) => $.roleTemplates.apply.confirm)}
            </Button>
          ) : null}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
