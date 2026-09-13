import { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import {
  Archive,
  ArchiveRestore,
  Building2,
  CheckCircle,
  FileText,
  Link2,
  Loader2,
  Pencil,
  Plus,
  Trash2,
  Truck,
  Warehouse,
  XCircle,
} from 'lucide-react';

import { EntityDrawer } from '@/components/crud';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/ecos-select';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/components/ds/use-toast';

import { companiesService } from '@/features/companies/services/companies-service';
import {
  useActivateShippingContract,
  useCreateShippingCompany,
  useCreateShippingContract,
  useCreateShippingMapping,
  useDeleteShippingContract,
  useDeleteShippingMapping,
  useNextShippingCompanyCode,
  useSetShippingCompanyStatus,
  useShippingCompany,
  useUpdateShippingCompany,
  useUpdateShippingContract,
} from '../hooks/use-shipping-companies';
import type {
  ShippingCompany,
  ShippingCompanyPayload,
  ShippingCompanyType,
  ShippingContract,
  ShippingContractPayload,
} from '../types/shipping-company';

// ── Helpers ────────────────────────────────────────────────────────────────────

function apiErrorMessage(err: unknown, fallback: string): string {
  if (typeof err === 'object' && err !== null) {
    const res = (err as { response?: { data?: { message?: string } } }).response;
    if (res?.data?.message) return res.data.message;
  }
  return fallback;
}

function TypeBadge({ type }: { type: ShippingCompanyType }) {
  const { t } = useTranslation('logistics');

  return type === 'internal' ? (
    <Badge variant="secondary" className="gap-1 text-xs">
      <Warehouse className="size-3" />
      {t(($) => $.shippingCompanies.drawer.typeInternalFleet)}
    </Badge>
  ) : (
    <Badge variant="outline" className="gap-1 text-xs">
      <Truck className="size-3" />
      {t(($) => $.shippingCompanies.drawer.typeExternalProvider)}
    </Badge>
  );
}

// ── Company Form (create + edit details) ───────────────────────────────────────

type CompanyFormState = {
  name: string;
  code: string;
  type: ShippingCompanyType;
  contact_person: string;
  phone: string;
  email: string;
  address: string;
  notes: string;
  is_active: boolean;
};

const EMPTY_FORM: CompanyFormState = {
  name: '',
  code: '',
  type: 'external',
  contact_person: '',
  phone: '',
  email: '',
  address: '',
  notes: '',
  is_active: true,
};

function toPayload(form: CompanyFormState, isCreate: boolean): ShippingCompanyPayload {
  return {
    name: form.name.trim(),
    code: form.code.trim(),
    ...(isCreate ? { type: form.type } : {}),
    contact_person: form.contact_person.trim() || null,
    phone: form.phone.trim() || null,
    email: form.email.trim() || null,
    address: form.address.trim() || null,
    notes: form.notes.trim() || null,
    ...(isCreate ? { status: form.is_active ? 'active' : 'inactive' } : {}),
  } as ShippingCompanyPayload;
}

function CompanyFormFields({
  form,
  setForm,
  isCreate,
  disabled,
}: {
  form: CompanyFormState;
  setForm: (updater: (prev: CompanyFormState) => CompanyFormState) => void;
  isCreate: boolean;
  disabled?: boolean;
}) {
  const { t } = useTranslation('logistics');

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="sc-name">{t(($) => $.shippingCompanies.drawer.companyName)} *</Label>
          <Input
            id="sc-name"
            value={form.name}
            disabled={disabled}
            placeholder={t(($) => $.shippingCompanies.drawer.companyNamePlaceholder)}
            onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="sc-code">{t(($) => $.common.code)} *</Label>
          <Input
            id="sc-code"
            value={form.code}
            disabled={disabled}
            placeholder="SHC-001"
            className="font-mono"
            onChange={(e) => setForm((p) => ({ ...p, code: e.target.value.toUpperCase() }))}
          />
        </div>
      </div>

      {isCreate ? (
        <div className="space-y-1.5">
          <Label>{t(($) => $.common.type)} *</Label>
          <Select
            value={form.type}
            onValueChange={(v) => setForm((p) => ({ ...p, type: v as ShippingCompanyType }))}
          >
            <SelectTrigger disabled={disabled}>
              <SelectValue placeholder={t(($) => $.shippingCompanies.drawer.selectType)} />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="external">{t(($) => $.shippingCompanies.drawer.typeExternalOption)}</SelectItem>
              <SelectItem value="internal">{t(($) => $.shippingCompanies.drawer.typeInternalOption)}</SelectItem>
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            {t(($) => $.shippingCompanies.drawer.typeLockedNote)}
          </p>
        </div>
      ) : (
        <div className="flex items-center gap-2">
          <Label className="text-muted-foreground">{t(($) => $.common.type)}</Label>
          <TypeBadge type={form.type} />
          <span className="text-xs text-muted-foreground">{t(($) => $.shippingCompanies.drawer.typeLockedSuffix)}</span>
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-1.5">
          <Label htmlFor="sc-contact">{t(($) => $.shippingCompanies.drawer.contactPerson)}</Label>
          <Input
            id="sc-contact"
            value={form.contact_person}
            disabled={disabled}
            onChange={(e) => setForm((p) => ({ ...p, contact_person: e.target.value }))}
          />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="sc-phone">{t(($) => $.common.phone)}</Label>
          <Input
            id="sc-phone"
            value={form.phone}
            disabled={disabled}
            placeholder="01xxxxxxxxx"
            onChange={(e) => setForm((p) => ({ ...p, phone: e.target.value }))}
          />
        </div>
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="sc-email">{t(($) => $.shippingCompanies.drawer.email)}</Label>
        <Input
          id="sc-email"
          type="email"
          value={form.email}
          disabled={disabled}
          placeholder="ops@carrier.com"
          onChange={(e) => setForm((p) => ({ ...p, email: e.target.value }))}
        />
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="sc-address">{t(($) => $.common.address)}</Label>
        <Input
          id="sc-address"
          value={form.address}
          disabled={disabled}
          onChange={(e) => setForm((p) => ({ ...p, address: e.target.value }))}
        />
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="sc-notes">{t(($) => $.common.notes)}</Label>
        <Textarea
          id="sc-notes"
          value={form.notes}
          disabled={disabled}
          rows={3}
          onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))}
        />
      </div>

      {isCreate && (
        <div className="flex items-center justify-between rounded-lg border px-3 py-2.5">
          <div>
            <p className="text-sm font-medium">{t(($) => $.common.active)}</p>
            <p className="text-xs text-muted-foreground">{t(($) => $.shippingCompanies.drawer.activeHint)}</p>
          </div>
          <Switch
            checked={form.is_active}
            disabled={disabled}
            onCheckedChange={(v) => setForm((p) => ({ ...p, is_active: v }))}
          />
        </div>
      )}
    </div>
  );
}

// ── Contracts Tab ──────────────────────────────────────────────────────────────

type ContractFormState = {
  name: string;
  start_date: string;
  end_date: string;
  payment_terms: string;
  notes: string;
};

const EMPTY_CONTRACT: ContractFormState = {
  name: '',
  start_date: '',
  end_date: '',
  payment_terms: '',
  notes: '',
};

function ContractsTab({ company }: { company: ShippingCompany }) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();
  const createContract = useCreateShippingContract();
  const updateContract = useUpdateShippingContract();
  const deleteContract = useDeleteShippingContract();
  const activateContract = useActivateShippingContract();

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<ShippingContract | null>(null);
  const [form, setForm] = useState<ContractFormState>(EMPTY_CONTRACT);
  const [deleteTarget, setDeleteTarget] = useState<ShippingContract | null>(null);

  const contracts = company.contracts ?? [];
  const isArchived = company.status === 'archived';
  const saving = createContract.isPending || updateContract.isPending;

  function openCreateForm() {
    setEditing(null);
    setForm(EMPTY_CONTRACT);
    setFormOpen(true);
  }

  function openEditForm(contract: ShippingContract) {
    setEditing(contract);
    setForm({
      name: contract.name,
      start_date: contract.start_date ?? '',
      end_date: contract.end_date ?? '',
      payment_terms: contract.payment_terms ?? '',
      notes: contract.notes ?? '',
    });
    setFormOpen(true);
  }

  async function handleSave() {
    if (!form.name.trim()) {
      toast({ title: t(($) => $.shippingCompanies.drawer.contracts.nameRequired), variant: 'destructive' });
      return;
    }
    const payload: ShippingContractPayload = {
      name: form.name.trim(),
      start_date: form.start_date || null,
      end_date: form.end_date || null,
      payment_terms: form.payment_terms.trim() || null,
      notes: form.notes.trim() || null,
    };
    try {
      if (editing) {
        await updateContract.mutateAsync({ companyId: company.id, contractId: editing.id, payload });
        toast({ title: t(($) => $.shippingCompanies.drawer.contracts.updated) });
      } else {
        await createContract.mutateAsync({ companyId: company.id, payload });
        toast({ title: t(($) => $.shippingCompanies.drawer.contracts.added) });
      }
      setFormOpen(false);
    } catch (err) {
      toast({ title: apiErrorMessage(err, t(($) => $.shippingCompanies.drawer.contracts.saveFailed)), variant: 'destructive' });
    }
  }

  async function handleActivate(contract: ShippingContract) {
    try {
      await activateContract.mutateAsync({ companyId: company.id, contractId: contract.id });
      toast({ title: t(($) => $.shippingCompanies.drawer.contracts.activatedToast, { name: contract.name }) });
    } catch (err) {
      toast({ title: apiErrorMessage(err, t(($) => $.shippingCompanies.drawer.contracts.activateFailed)), variant: 'destructive' });
    }
  }

  async function handleDeactivate(contract: ShippingContract) {
    try {
      await updateContract.mutateAsync({
        companyId: company.id,
        contractId: contract.id,
        payload: { status: 'inactive' },
      });
      toast({ title: t(($) => $.shippingCompanies.drawer.contracts.deactivatedToast, { name: contract.name }) });
    } catch (err) {
      toast({ title: apiErrorMessage(err, t(($) => $.shippingCompanies.drawer.contracts.deactivateFailed)), variant: 'destructive' });
    }
  }

  async function handleDelete() {
    if (!deleteTarget) return;
    try {
      await deleteContract.mutateAsync({ companyId: company.id, contractId: deleteTarget.id });
      toast({ title: t(($) => $.shippingCompanies.drawer.contracts.deletedToast, { name: deleteTarget.name }) });
    } catch (err) {
      toast({ title: apiErrorMessage(err, t(($) => $.shippingCompanies.drawer.contracts.deleteFailed)), variant: 'destructive' });
    } finally {
      setDeleteTarget(null);
    }
  }

  return (
    <div className="space-y-4">
      {isArchived && (
        <Alert>
          <AlertDescription className="text-sm">
            {t(($) => $.shippingCompanies.drawer.contracts.archivedNotice)}
          </AlertDescription>
        </Alert>
      )}

      <div className="flex items-center justify-between">
        <p className="text-sm text-muted-foreground">
          {t(($) => $.shippingCompanies.drawer.contracts.count, { count: contracts.length })}
        </p>
        {!formOpen && (
          <Button size="sm" className="gap-1.5" onClick={openCreateForm} disabled={isArchived}>
            <Plus className="size-3.5" />
            {t(($) => $.shippingCompanies.drawer.contracts.add)}
          </Button>
        )}
      </div>

      {formOpen && (
        <div className="space-y-3 rounded-lg border bg-muted/30 p-4">
          <p className="text-sm font-semibold">
            {editing
              ? t(($) => $.shippingCompanies.drawer.contracts.editTitle)
              : t(($) => $.shippingCompanies.drawer.contracts.newTitle)}
          </p>
          <div className="space-y-1.5">
            <Label htmlFor="ct-name">{t(($) => $.shippingCompanies.drawer.contracts.nameLabel)} *</Label>
            <Input
              id="ct-name"
              value={form.name}
              placeholder={t(($) => $.shippingCompanies.drawer.contracts.namePlaceholder)}
              onChange={(e) => setForm((p) => ({ ...p, name: e.target.value }))}
            />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="space-y-1.5">
              <Label htmlFor="ct-start">{t(($) => $.shippingCompanies.drawer.contracts.startDate)}</Label>
              <Input
                id="ct-start"
                type="date"
                value={form.start_date}
                onChange={(e) => setForm((p) => ({ ...p, start_date: e.target.value }))}
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="ct-end">{t(($) => $.shippingCompanies.drawer.contracts.endDate)}</Label>
              <Input
                id="ct-end"
                type="date"
                value={form.end_date}
                min={form.start_date || undefined}
                onChange={(e) => setForm((p) => ({ ...p, end_date: e.target.value }))}
              />
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="ct-terms">{t(($) => $.shippingCompanies.drawer.contracts.paymentTerms)}</Label>
            <Input
              id="ct-terms"
              value={form.payment_terms}
              placeholder={t(($) => $.shippingCompanies.drawer.contracts.paymentTermsPlaceholder)}
              onChange={(e) => setForm((p) => ({ ...p, payment_terms: e.target.value }))}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="ct-notes">{t(($) => $.common.notes)}</Label>
            <Textarea
              id="ct-notes"
              rows={2}
              value={form.notes}
              onChange={(e) => setForm((p) => ({ ...p, notes: e.target.value }))}
            />
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" size="sm" onClick={() => setFormOpen(false)} disabled={saving}>
              {t(($) => $.common.cancel)}
            </Button>
            <Button size="sm" onClick={handleSave} disabled={saving} className="gap-1.5">
              {saving && <Loader2 className="size-3.5 animate-spin" />}
              {editing ? t(($) => $.common.saveChanges) : t(($) => $.shippingCompanies.drawer.contracts.add)}
            </Button>
          </div>
        </div>
      )}

      {contracts.length === 0 && !formOpen ? (
        <div className="flex flex-col items-center justify-center rounded-lg border py-12 text-center">
          <FileText className="mb-2 size-8 text-muted-foreground/30" />
          <p className="text-sm font-medium">{t(($) => $.shippingCompanies.drawer.contracts.emptyTitle)}</p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {t(($) => $.shippingCompanies.drawer.contracts.emptyHint)}
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {contracts.map((contract) => (
            <div
              key={contract.id}
              className={`rounded-lg border p-3 transition-colors ${
                contract.status === 'active' ? 'border-emerald-500/40 bg-emerald-500/5' : ''
              }`}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate text-sm font-medium">{contract.name}</p>
                    {contract.status === 'active' && (
                      <Badge className="gap-1 bg-emerald-600 text-xs hover:bg-emerald-600">
                        <CheckCircle className="size-3" />
                        {t(($) => $.common.active)}
                      </Badge>
                    )}
                    {contract.is_expired && (
                      <Badge variant="destructive" className="text-xs">{t(($) => $.shippingCompanies.drawer.contracts.expired)}</Badge>
                    )}
                  </div>
                  <p className="mt-1 text-xs text-muted-foreground">
                    {contract.start_date ?? '—'} → {contract.end_date ?? t(($) => $.shippingCompanies.drawer.contracts.openEnded)}
                    {contract.payment_terms ? ` · ${contract.payment_terms}` : ''}
                  </p>
                  {contract.notes && (
                    <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{contract.notes}</p>
                  )}
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  {contract.status !== 'active' ? (
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-7 gap-1 text-xs"
                      onClick={() => handleActivate(contract)}
                      disabled={activateContract.isPending}
                    >
                      <CheckCircle className="size-3" />
                      {t(($) => $.shippingCompanies.drawer.contracts.activate)}
                    </Button>
                  ) : (
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-7 gap-1 text-xs"
                      onClick={() => handleDeactivate(contract)}
                      disabled={updateContract.isPending}
                    >
                      <XCircle className="size-3" />
                      {t(($) => $.shippingCompanies.drawer.contracts.deactivate)}
                    </Button>
                  )}
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 w-7 p-0"
                    aria-label={t(($) => $.shippingCompanies.drawer.contracts.editTitle)}
                    onClick={() => openEditForm(contract)}
                  >
                    <Pencil className="size-3.5" />
                  </Button>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 w-7 p-0 text-destructive hover:text-destructive"
                    aria-label={t(($) => $.shippingCompanies.drawer.contracts.deleteTitle)}
                    onClick={() => setDeleteTarget(contract)}
                  >
                    <Trash2 className="size-3.5" />
                  </Button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      <AlertDialog open={deleteTarget !== null} onOpenChange={(o) => { if (!o) setDeleteTarget(null); }}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t(($) => $.shippingCompanies.drawer.contracts.deleteTitle)}</AlertDialogTitle>
            <AlertDialogDescription>
              {t(($) => $.shippingCompanies.drawer.contracts.deleteBodyPrefix)} <strong>{deleteTarget?.name}</strong>{t(($) => $.shippingCompanies.drawer.contracts.deleteBodySuffix)}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t(($) => $.common.cancel)}</AlertDialogCancel>
            <AlertDialogAction
              onClick={handleDelete}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t(($) => $.common.delete)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

// ── Company Mapping Tab ────────────────────────────────────────────────────────

function CompanyMappingTab({ company }: { company: ShippingCompany }) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();
  const createMapping = useCreateShippingMapping();
  const deleteMapping = useDeleteShippingMapping();
  const [selectedCompanyId, setSelectedCompanyId] = useState<string>('');

  const isArchived = company.status === 'archived';
  const mappings = useMemo(() => company.mappings ?? [], [company.mappings]);

  const { data: ecosCompanies, isLoading: companiesLoading } = useQuery({
    queryKey: ['companies', 'for-shipping-mapping'],
    queryFn: () => companiesService.list({ per_page: 100, status: 'active' }),
    staleTime: 60_000,
  });

  const mappedIds = useMemo(() => new Set(mappings.map((m) => m.company_id)), [mappings]);
  const available = (ecosCompanies?.items ?? []).filter((c) => !mappedIds.has(c.id));

  async function handleLink() {
    if (!selectedCompanyId) return;
    try {
      await createMapping.mutateAsync({ companyId: company.id, ecosCompanyId: selectedCompanyId });
      setSelectedCompanyId('');
      toast({ title: t(($) => $.shippingCompanies.drawer.mapping.linked) });
    } catch (err) {
      toast({ title: apiErrorMessage(err, t(($) => $.shippingCompanies.drawer.mapping.linkFailed)), variant: 'destructive' });
    }
  }

  async function handleUnlink(mappingId: number, name: string | null | undefined) {
    try {
      await deleteMapping.mutateAsync({ companyId: company.id, mappingId });
      toast({ title: t(($) => $.shippingCompanies.drawer.mapping.unlinked, { name: name ?? t(($) => $.shippingCompanies.drawer.mapping.companyFallback) }) });
    } catch (err) {
      toast({ title: apiErrorMessage(err, t(($) => $.shippingCompanies.drawer.mapping.unlinkFailed)), variant: 'destructive' });
    }
  }

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        {t(($) => $.shippingCompanies.drawer.mapping.intro)}
      </p>

      {isArchived ? (
        <Alert>
          <AlertDescription className="text-sm">
            {t(($) => $.shippingCompanies.drawer.mapping.archivedNotice)}
          </AlertDescription>
        </Alert>
      ) : (
        <div className="flex items-end gap-2">
          <div className="flex-1 space-y-1.5">
            <Label>{t(($) => $.shippingCompanies.drawer.mapping.ecosCompanyLabel)}</Label>
            <Select value={selectedCompanyId} onValueChange={setSelectedCompanyId}>
              <SelectTrigger disabled={companiesLoading || available.length === 0}>
                <SelectValue
                  placeholder={
                    companiesLoading
                      ? t(($) => $.shippingCompanies.drawer.mapping.loadingCompanies)
                      : available.length === 0
                        ? t(($) => $.shippingCompanies.drawer.mapping.allLinked)
                        : t(($) => $.shippingCompanies.drawer.mapping.selectToLink)
                  }
                />
              </SelectTrigger>
              <SelectContent>
                {available.map((c) => (
                  <SelectItem key={c.id} value={c.id}>
                    {c.name} ({c.code})
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Button
            className="gap-1.5"
            onClick={handleLink}
            disabled={!selectedCompanyId || createMapping.isPending}
          >
            {createMapping.isPending ? (
              <Loader2 className="size-3.5 animate-spin" />
            ) : (
              <Link2 className="size-3.5" />
            )}
            {t(($) => $.shippingCompanies.drawer.mapping.link)}
          </Button>
        </div>
      )}

      {mappings.length === 0 ? (
        <div className="flex flex-col items-center justify-center rounded-lg border py-12 text-center">
          <Building2 className="mb-2 size-8 text-muted-foreground/30" />
          <p className="text-sm font-medium">{t(($) => $.shippingCompanies.drawer.mapping.emptyTitle)}</p>
          <p className="mt-0.5 text-xs text-muted-foreground">
            {t(($) => $.shippingCompanies.drawer.mapping.emptyHint)}
          </p>
        </div>
      ) : (
        <div className="space-y-2">
          {mappings.map((m) => (
            <div key={m.id} className="flex items-center justify-between rounded-lg border p-3">
              <div className="flex items-center gap-2.5">
                <Building2 className="size-4 text-muted-foreground" />
                <div>
                  <p className="text-sm font-medium">{m.company_name ?? m.company_id}</p>
                  {m.company_code && (
                    <p className="font-mono text-xs text-muted-foreground">{m.company_code}</p>
                  )}
                </div>
              </div>
              <Button
                variant="ghost"
                size="sm"
                className="h-7 gap-1 text-xs text-destructive hover:text-destructive"
                onClick={() => handleUnlink(m.id, m.company_name)}
                disabled={deleteMapping.isPending}
              >
                <Trash2 className="size-3" />
                {t(($) => $.shippingCompanies.drawer.mapping.unlink)}
              </Button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ── Main Drawer ────────────────────────────────────────────────────────────────

export function ShippingCompanyDrawer({
  open,
  onOpenChange,
  editCompany,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editCompany: ShippingCompany | null;
}) {
  const { t } = useTranslation('logistics');
  const { toast } = useToast();
  const isCreate = editCompany === null;

  const [form, setForm] = useState<CompanyFormState>(EMPTY_FORM);
  const [tab, setTab] = useState('details');
  const [archiveConfirm, setArchiveConfirm] = useState(false);

  const { data: detail, isLoading: detailLoading } = useShippingCompany(
    open && editCompany ? editCompany.id : null,
  );
  const { data: nextCode } = useNextShippingCompanyCode(open && isCreate);

  const createCompany = useCreateShippingCompany();
  const updateCompany = useUpdateShippingCompany();
  const setStatus = useSetShippingCompanyStatus();

  const company = detail ?? editCompany;
  const saving = createCompany.isPending || updateCompany.isPending;

  // Hydrate form when the drawer opens or the loaded record changes.
  useEffect(() => {
    if (!open) return;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setTab('details');
    if (editCompany === null) {
      setForm(EMPTY_FORM);
    } else {
      const src = detail ?? editCompany;
      setForm({
        name: src.name,
        code: src.code,
        type: src.type,
        contact_person: src.contact_person ?? '',
        phone: src.phone ?? '',
        email: src.email ?? '',
        address: src.address ?? '',
        notes: src.notes ?? '',
        is_active: src.status === 'active',
      });
    }
  }, [open, editCompany, detail]);

  // Suggest next code for new companies.
  useEffect(() => {
    if (open && isCreate && nextCode) {
      // eslint-disable-next-line react-hooks/set-state-in-effect
      setForm((p) => (p.code === '' ? { ...p, code: nextCode } : p));
    }
  }, [open, isCreate, nextCode]);

  async function handleSave() {
    if (!form.name.trim() || !form.code.trim()) {
      toast({ title: t(($) => $.shippingCompanies.drawer.nameCodeRequired), variant: 'destructive' });
      return;
    }
    try {
      if (isCreate) {
        await createCompany.mutateAsync(toPayload(form, true));
        toast({ title: t(($) => $.shippingCompanies.drawer.createdToast, { name: form.name }) });
        onOpenChange(false);
      } else {
        await updateCompany.mutateAsync({ id: editCompany.id, payload: toPayload(form, false) });
        toast({ title: t(($) => $.shippingCompanies.drawer.changesSaved) });
      }
    } catch (err) {
      toast({ title: apiErrorMessage(err, t(($) => $.shippingCompanies.drawer.savingFailed)), variant: 'destructive' });
    }
  }

  async function handleSetStatus(status: 'active' | 'inactive' | 'archived') {
    if (!editCompany) return;
    try {
      await setStatus.mutateAsync({ id: editCompany.id, status });
      toast({
        title:
          status === 'archived'
            ? t(($) => $.shippingCompanies.drawer.archivedToast)
            : status === 'active'
              ? t(($) => $.shippingCompanies.drawer.activatedToast)
              : t(($) => $.shippingCompanies.drawer.deactivatedToast),
      });
      if (status === 'archived') setArchiveConfirm(false);
    } catch (err) {
      toast({ title: apiErrorMessage(err, t(($) => $.shippingCompanies.drawer.statusChangeFailed)), variant: 'destructive' });
    }
  }

  const statusBadge =
    company &&
    (company.status === 'active' ? (
      <Badge className="bg-emerald-600 text-xs hover:bg-emerald-600">{t(($) => $.common.active)}</Badge>
    ) : company.status === 'inactive' ? (
      <Badge variant="secondary" className="text-xs">{t(($) => $.common.inactive)}</Badge>
    ) : (
      <Badge variant="outline" className="gap-1 text-xs text-muted-foreground">
        <Archive className="size-3" />
        {t(($) => $.shippingCompanies.status.archived)}
      </Badge>
    ));

  return (
    <>
      <EntityDrawer
        open={open}
        onOpenChange={onOpenChange}
        title={isCreate ? t(($) => $.shippingCompanies.drawer.newTitle) : (company?.name ?? t(($) => $.shippingCompanies.drawer.fallbackTitle))}
        description={
          isCreate
            ? t(($) => $.shippingCompanies.drawer.createDescription)
            : t(($) => $.shippingCompanies.drawer.editDescription, { code: company?.code ?? '' })
        }
      >
        <div className="flex h-full flex-col">
          {!isCreate && (
            <div className="mb-3 flex items-center gap-2">
              {statusBadge}
              {company && <TypeBadge type={company.type} />}
            </div>
          )}

          {isCreate ? (
            <>
              <div className="min-h-0 flex-1 overflow-y-auto pe-1">
                <CompanyFormFields form={form} setForm={setForm} isCreate disabled={saving} />
              </div>
              <Separator className="my-4" />
              <div className="flex shrink-0 justify-end gap-2">
                <Button variant="ghost" onClick={() => onOpenChange(false)} disabled={saving}>
                  {t(($) => $.common.cancel)}
                </Button>
                <Button onClick={handleSave} disabled={saving} className="gap-1.5">
                  {saving && <Loader2 className="size-4 animate-spin" />}
                  {t(($) => $.shippingCompanies.drawer.createCompany)}
                </Button>
              </div>
            </>
          ) : detailLoading && !company ? (
            <div className="space-y-3">
              <Skeleton className="h-8 w-full" />
              <Skeleton className="h-32 w-full" />
              <Skeleton className="h-32 w-full" />
            </div>
          ) : (
            <Tabs value={tab} onValueChange={setTab} className="flex min-h-0 flex-1 flex-col">
              <TabsList className="grid w-full shrink-0 grid-cols-3">
                <TabsTrigger value="details">{t(($) => $.shippingCompanies.drawer.tabs.details)}</TabsTrigger>
                <TabsTrigger value="contracts" className="gap-1.5">
                  {t(($) => $.shippingCompanies.drawer.tabs.contracts)}
                  {company?.contracts_count != null && company.contracts_count > 0 && (
                    <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">
                      {company.contracts_count}
                    </Badge>
                  )}
                </TabsTrigger>
                <TabsTrigger value="companies" className="gap-1.5">
                  {t(($) => $.shippingCompanies.drawer.tabs.companies)}
                  {company?.companies_count != null && company.companies_count > 0 && (
                    <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">
                      {company.companies_count}
                    </Badge>
                  )}
                </TabsTrigger>
              </TabsList>

              <div className="min-h-0 flex-1 overflow-y-auto pt-4 pe-1">
                <TabsContent value="details" className="mt-0 space-y-4">
                  <CompanyFormFields
                    form={form}
                    setForm={setForm}
                    isCreate={false}
                    disabled={saving || company?.status === 'archived'}
                  />

                  <Separator />

                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      {company?.status === 'archived' ? (
                        <Button
                          variant="outline"
                          size="sm"
                          className="gap-1.5"
                          onClick={() => handleSetStatus('inactive')}
                          disabled={setStatus.isPending}
                        >
                          <ArchiveRestore className="size-3.5" />
                          {t(($) => $.shippingCompanies.drawer.actions.restoreFromArchive)}
                        </Button>
                      ) : (
                        <>
                          {company?.status === 'active' ? (
                            <Button
                              variant="outline"
                              size="sm"
                              className="gap-1.5"
                              onClick={() => handleSetStatus('inactive')}
                              disabled={setStatus.isPending}
                            >
                              <XCircle className="size-3.5" />
                              {t(($) => $.shippingCompanies.drawer.actions.deactivate)}
                            </Button>
                          ) : (
                            <Button
                              variant="outline"
                              size="sm"
                              className="gap-1.5 text-emerald-700"
                              onClick={() => handleSetStatus('active')}
                              disabled={setStatus.isPending}
                            >
                              <CheckCircle className="size-3.5" />
                              {t(($) => $.shippingCompanies.drawer.actions.activate)}
                            </Button>
                          )}
                          <Button
                            variant="outline"
                            size="sm"
                            className="gap-1.5 text-destructive hover:text-destructive"
                            onClick={() => setArchiveConfirm(true)}
                            disabled={setStatus.isPending}
                          >
                            <Archive className="size-3.5" />
                            {t(($) => $.shippingCompanies.drawer.actions.archive)}
                          </Button>
                        </>
                      )}
                    </div>
                    {company?.status !== 'archived' && (
                      <Button onClick={handleSave} disabled={saving} className="gap-1.5">
                        {saving && <Loader2 className="size-4 animate-spin" />}
                        {t(($) => $.common.saveChanges)}
                      </Button>
                    )}
                  </div>
                </TabsContent>

                <TabsContent value="contracts" className="mt-0">
                  {company && <ContractsTab company={company} />}
                </TabsContent>

                <TabsContent value="companies" className="mt-0">
                  {company && <CompanyMappingTab company={company} />}
                </TabsContent>
              </div>
            </Tabs>
          )}
        </div>
      </EntityDrawer>

      <AlertDialog open={archiveConfirm} onOpenChange={setArchiveConfirm}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>{t(($) => $.shippingCompanies.drawer.archiveDialog.title)}</AlertDialogTitle>
            <AlertDialogDescription>
              {t(($) => $.shippingCompanies.drawer.archiveDialog.bodyPrefix)} <strong>{company?.name}</strong>{t(($) => $.shippingCompanies.drawer.archiveDialog.bodySuffix)}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>{t(($) => $.common.cancel)}</AlertDialogCancel>
            <AlertDialogAction
              onClick={() => handleSetStatus('archived')}
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
            >
              {t(($) => $.shippingCompanies.drawer.actions.archive)}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
