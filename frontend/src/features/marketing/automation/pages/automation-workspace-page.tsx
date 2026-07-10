import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Play, Pause, Archive, Copy, MoreHorizontal, Zap, Activity, Clock, XCircle } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useAutomationWorkflows, useWorkflowKpis, useActivateWorkflow, usePauseWorkflow, useArchiveWorkflow, useDuplicateWorkflow } from '../hooks/use-automation-workflows';
import { WorkflowDrawer } from '../drawers/workflow-drawer';
import { WorkflowTemplatePicker } from '../drawers/workflow-template-picker';
import type { AutomationWorkflow, WorkflowStatus } from '../types/automation';
import { ROUTES } from '@/router/routes';

const STATUS_TABS: { label: string; value: WorkflowStatus | 'all' }[] = [
  { label: 'All',             value: 'all' },
  { label: 'Draft',           value: 'draft' },
  { label: 'Active',          value: 'active' },
  { label: 'Paused',          value: 'paused' },
  { label: 'Pending Approval', value: 'pending_approval' },
  { label: 'Archived',        value: 'archived' },
];

const STATUS_BADGE: Record<WorkflowStatus, string> = {
  draft:            'bg-gray-100 text-gray-700',
  pending_approval: 'bg-yellow-100 text-yellow-700',
  approved:         'bg-blue-100 text-blue-700',
  active:           'bg-green-100 text-green-700',
  paused:           'bg-orange-100 text-orange-700',
  archived:         'bg-gray-200 text-gray-500',
  failed:           'bg-red-100 text-red-700',
};

function KpiCard({ icon: Icon, label, value, color }: { icon: React.ElementType; label: string; value: number; color: string }) {
  return (
    <div className="bg-card border rounded-lg p-4 flex items-center gap-3">
      <div className={`p-2 rounded-lg ${color}`}>
        <Icon className="h-4 w-4" />
      </div>
      <div>
        <p className="text-2xl font-semibold">{value.toLocaleString()}</p>
        <p className="text-xs text-muted-foreground">{label}</p>
      </div>
    </div>
  );
}

function WorkflowCard({ workflow, onOpen }: { workflow: AutomationWorkflow; onOpen: () => void }) {
  const activate  = useActivateWorkflow();
  const pause     = usePauseWorkflow();
  const archive   = useArchiveWorkflow();
  const duplicate = useDuplicateWorkflow();
  const navigate  = useNavigate();

  return (
    <div className="bg-card border rounded-lg p-4 hover:shadow-sm transition-shadow">
      <div className="flex items-start justify-between mb-2">
        <div className="flex-1 min-w-0">
          <button
            onClick={() => navigate(ROUTES.workflowBuilder.replace(':workflowId', workflow.id))}
            className="text-sm font-medium hover:underline truncate text-left block"
          >
            {workflow.name}
          </button>
          {workflow.description && (
            <p className="text-xs text-muted-foreground mt-0.5 line-clamp-1">{workflow.description}</p>
          )}
        </div>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="sm" className="h-7 w-7 p-0">
              <MoreHorizontal className="h-3.5 w-3.5" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem onClick={onOpen}>Edit Details</DropdownMenuItem>
            <DropdownMenuItem onClick={() => navigate(ROUTES.workflowBuilder.replace(':workflowId', workflow.id))}>
              Open Builder
            </DropdownMenuItem>
            <DropdownMenuItem onClick={() => duplicate.mutate(workflow.id)}>
              <Copy className="h-3.5 w-3.5 mr-2" /> Duplicate
            </DropdownMenuItem>
            {workflow.can_activate && (
              <DropdownMenuItem onClick={() => activate.mutate(workflow.id)}>
                <Play className="h-3.5 w-3.5 mr-2" /> Activate
              </DropdownMenuItem>
            )}
            {workflow.can_pause && (
              <DropdownMenuItem onClick={() => pause.mutate(workflow.id)}>
                <Pause className="h-3.5 w-3.5 mr-2" /> Pause
              </DropdownMenuItem>
            )}
            {workflow.can_archive && (
              <DropdownMenuItem
                className="text-destructive"
                onClick={() => archive.mutate(workflow.id)}
              >
                <Archive className="h-3.5 w-3.5 mr-2" /> Archive
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      <div className="flex items-center gap-2 mt-3">
        <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${STATUS_BADGE[workflow.status]}`}>
          {workflow.status.replace('_', ' ')}
        </span>
        <span className="text-xs text-muted-foreground">
          {workflow.trigger_type.replace('_', ' ')}
        </span>
      </div>

      <div className="flex items-center gap-3 mt-3 text-xs text-muted-foreground">
        <span>{workflow.execution_count.toLocaleString()} runs</span>
        {workflow.last_executed_at && (
          <span>Last: {new Date(workflow.last_executed_at).toLocaleDateString()}</span>
        )}
      </div>
    </div>
  );
}

export function AutomationWorkspacePage() {
  const [statusFilter, setStatusFilter] = useState<WorkflowStatus | 'all'>('all');
  const [search, setSearch] = useState('');
  const [drawerOpen, setDrawerOpen]       = useState(false);
  const [templatePickerOpen, setTemplatePickerOpen] = useState(false);
  const [selectedWorkflow, setSelectedWorkflow] = useState<AutomationWorkflow | undefined>();

  const { data: kpis }      = useWorkflowKpis();
  const { data, isLoading } = useAutomationWorkflows({
    status: statusFilter === 'all' ? undefined : statusFilter,
    search: search || undefined,
  });

  const workflows = data?.data ?? [];

  return (
    <div className="flex flex-col h-full">
      {/* Header */}
      <div className="flex items-center justify-between px-6 py-4 border-b">
        <div>
          <h1 className="text-lg font-semibold">Marketing Automation</h1>
          <p className="text-xs text-muted-foreground">Event-driven workflow orchestration</p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => setTemplatePickerOpen(true)}>
            Templates
          </Button>
          <Button size="sm" onClick={() => { setSelectedWorkflow(undefined); setDrawerOpen(true); }}>
            <Plus className="h-3.5 w-3.5 mr-1" /> New Workflow
          </Button>
        </div>
      </div>

      {/* KPIs */}
      {kpis && (
        <div className="grid grid-cols-4 gap-3 px-6 py-4 border-b">
          <KpiCard icon={Activity}  label="Active Workflows"    value={kpis.active}   color="bg-green-50 text-green-600" />
          <KpiCard icon={Zap}       label="Total Executions"    value={kpis.total_executions} color="bg-blue-50 text-blue-600" />
          <KpiCard icon={Clock}     label="Pending Approval"    value={kpis.pending_approval} color="bg-yellow-50 text-yellow-600" />
          <KpiCard icon={XCircle}   label="Failed"              value={kpis.failed}   color="bg-red-50 text-red-600" />
        </div>
      )}

      {/* Toolbar */}
      <div className="flex items-center gap-3 px-6 py-3 border-b">
        <Input
          placeholder="Search workflows..."
          value={search}
          onChange={e => setSearch(e.target.value)}
          className="h-8 w-64"
        />
        <div className="flex gap-1">
          {STATUS_TABS.map(tab => (
            <button
              key={tab.value}
              onClick={() => setStatusFilter(tab.value as WorkflowStatus | 'all')}
              className={`px-3 py-1 text-xs rounded-md transition-colors ${
                statusFilter === tab.value
                  ? 'bg-primary text-primary-foreground'
                  : 'text-muted-foreground hover:bg-muted'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* Grid */}
      <div className="flex-1 overflow-y-auto p-6">
        {isLoading ? (
          <div className="text-sm text-muted-foreground">Loading workflows...</div>
        ) : workflows.length === 0 ? (
          <div className="flex flex-col items-center justify-center h-48 gap-3">
            <Zap className="h-8 w-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">No workflows yet. Create one or start from a template.</p>
            <Button size="sm" onClick={() => setTemplatePickerOpen(true)}>Browse Templates</Button>
          </div>
        ) : (
          <div className="grid grid-cols-3 gap-3">
            {workflows.map(wf => (
              <WorkflowCard
                key={wf.id}
                workflow={wf}
                onOpen={() => { setSelectedWorkflow(wf); setDrawerOpen(true); }}
              />
            ))}
          </div>
        )}
      </div>

      <WorkflowDrawer
        open={drawerOpen}
        onClose={() => { setDrawerOpen(false); setSelectedWorkflow(undefined); }}
        workflow={selectedWorkflow}
      />

      <WorkflowTemplatePicker
        open={templatePickerOpen}
        onClose={() => setTemplatePickerOpen(false)}
      />
    </div>
  );
}

