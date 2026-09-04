import { useTranslation } from 'react-i18next';

import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';

import { TASK_PRIORITY_ORDER, TASK_STATUS_ORDER } from '../lib/task-meta';
import type { TaskFilters as TaskFiltersValue } from '../types';

const SCOPES: NonNullable<TaskFiltersValue['scope']>[] = ['mine', 'created', 'assigned'];

type Props = {
  value: TaskFiltersValue;
  onChange: (next: TaskFiltersValue) => void;
};

/** Every filter maps 1:1 onto `TaskFilters` query params the backend's ListMyTasksAction/SearchTasksAction already accept — no client-side filtering of an unfiltered list. */
export function TaskFilters({ value, onChange }: Props) {
  const { t } = useTranslation('collaboration');

  return (
    <div className="flex flex-wrap items-end gap-3">
      <div className="flex flex-col gap-1">
        <Label className="text-xs text-muted-foreground">{t(($) => $.tasks.filters.scope)}</Label>
        <Select
          value={value.scope ?? 'mine'}
          onValueChange={(scope) => onChange({ ...value, scope: scope as TaskFiltersValue['scope'] })}
        >
          <SelectTrigger className="h-8 w-40 text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {SCOPES.map((scope) => (
              <SelectItem key={scope} value={scope}>
                {t(($) => $.tasks.filters[scope === 'mine' ? 'scopeMine' : scope === 'created' ? 'scopeCreated' : 'scopeAssigned'])}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex flex-col gap-1">
        <Label className="text-xs text-muted-foreground">{t(($) => $.tasks.filters.status)}</Label>
        <Select
          value={value.status ?? 'all'}
          onValueChange={(status) => onChange({ ...value, status: status === 'all' ? undefined : (status as TaskFiltersValue['status']) })}
        >
          <SelectTrigger className="h-8 w-36 text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t(($) => $.tasks.filters.all)}</SelectItem>
            {TASK_STATUS_ORDER.map((status) => (
              <SelectItem key={status} value={status}>
                {t(($) => $.tasks.status[status])}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <div className="flex flex-col gap-1">
        <Label className="text-xs text-muted-foreground">{t(($) => $.tasks.filters.priority)}</Label>
        <Select
          value={value.priority ?? 'all'}
          onValueChange={(priority) => onChange({ ...value, priority: priority === 'all' ? undefined : (priority as TaskFiltersValue['priority']) })}
        >
          <SelectTrigger className="h-8 w-32 text-xs">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t(($) => $.tasks.filters.all)}</SelectItem>
            {TASK_PRIORITY_ORDER.map((priority) => (
              <SelectItem key={priority} value={priority}>
                {t(($) => $.tasks.priority[priority])}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <label className="flex items-center gap-2 pb-1.5">
        <Switch checked={!!value.overdue} onCheckedChange={(overdue) => onChange({ ...value, overdue })} />
        <span className="text-xs text-muted-foreground">{t(($) => $.tasks.filters.overdue)}</span>
      </label>
    </div>
  );
}
