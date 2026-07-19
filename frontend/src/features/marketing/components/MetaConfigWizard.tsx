import { useState } from 'react';
import { Check, ChevronRight, Eye, EyeOff, ExternalLink, Loader2, AlertCircle, CheckCircle2, RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useToast } from '@/components/ds/use-toast';
import { useSaveProviderConfig, useValidateProviderConfig } from '../hooks/use-provider-config';
import type { ProviderConfig } from '../types/provider-config';

interface Props {
  config: ProviderConfig;
  onComplete: () => void;
  onCancel?: () => void;
}

type Step = 1 | 2 | 3 | 4;

const STEPS = [
  { id: 1, label: 'App ID' },
  { id: 2, label: 'App Secret' },
  { id: 3, label: 'Redirect URI' },
  { id: 4, label: 'Validate & Save' },
] as const;

export function MetaConfigWizard({ config, onComplete, onCancel }: Props) {
  const { toast } = useToast();

  const [step, setStep]           = useState<Step>(1);
  const [appId, setAppId]         = useState(config.app_id ?? '');
  const [appSecret, setAppSecret] = useState('');
  const [redirectUri, setRedirectUri] = useState(config.redirect_uri ?? config.default_redirect_uri);
  const [showSecret, setShowSecret]   = useState(false);
  const [validationResult, setValidationResult] = useState<{ valid: boolean; errors: string[] } | null>(null);

  const validateMutation = useValidateProviderConfig('meta');
  const saveMutation     = useSaveProviderConfig('meta');

  const isValidating = validateMutation.isPending;
  const isSaving     = saveMutation.isPending;

  // ── Step navigation ────────────────────────────────────────────────────────

  function canAdvance(): boolean {
    if (step === 1) return appId.trim().length > 0;
    if (step === 2) return appSecret.trim().length > 0 || config.has_app_secret;
    if (step === 3) return redirectUri.trim().length > 0;
    return false;
  }

  function advance() {
    if (step < 4) setStep((s) => (s + 1) as Step);
  }

  function back() {
    if (step > 1) {
      setValidationResult(null);
      setStep((s) => (s - 1) as Step);
    }
  }

  // ── Validate ───────────────────────────────────────────────────────────────

  async function handleValidate() {
    setValidationResult(null);
    const secret = appSecret || (config.has_app_secret ? '__KEEP__' : '');
    if (!secret || secret === '__KEEP__') {
      // Can't validate with masked secret — proceed to save which will validate server-side
      await handleSave();
      return;
    }
    try {
      const result = await validateMutation.mutateAsync({
        app_id:      appId,
        app_secret:  appSecret,
        redirect_uri: redirectUri,
      });
      setValidationResult(result);
      if (result.valid) {
        await handleSave();
      }
    } catch (err: unknown) {
      // On 422, the backend returns {data: {valid, errors}} — extract it.
      const serverResult = (err as { response?: { data?: { data?: { valid?: boolean; errors?: string[] } } } })
        ?.response?.data?.data;
      if (serverResult?.errors?.length) {
        setValidationResult({ valid: false, errors: serverResult.errors });
      } else {
        setValidationResult({ valid: false, errors: ['Cannot reach validation service. Please try again.'] });
      }
    }
  }

  async function handleSave() {
    try {
      await saveMutation.mutateAsync({
        app_id:      appId,
        app_secret:  appSecret,
        redirect_uri: redirectUri,
      });
      toast({ title: 'Meta configuration saved.', description: 'OAuth is now enabled.' });
      onComplete();
    } catch (err: unknown) {
      const errors: string[] = (err as { response?: { data?: { data?: { errors?: string[] } } } })
        ?.response?.data?.data?.errors ?? ['Save failed. Please try again.'];
      setValidationResult({ valid: false, errors });
    }
  }

  // ── Render ─────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-6">
      {/* Step progress */}
      <nav aria-label="Wizard steps" className="flex items-center gap-1">
        {STEPS.map((s, i) => {
          const done    = step > s.id;
          const active  = step === s.id;
          return (
            <div key={s.id} className="flex items-center gap-1 flex-1 min-w-0">
              <div className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold transition-colors ${
                done   ? 'bg-emerald-500 text-white' :
                active ? 'bg-primary text-primary-foreground' :
                         'bg-muted text-muted-foreground'
              }`}>
                {done ? <Check className="h-3 w-3" /> : s.id}
              </div>
              <span className={`hidden sm:block text-xs truncate ${active ? 'font-medium' : 'text-muted-foreground'}`}>
                {s.label}
              </span>
              {i < STEPS.length - 1 && (
                <ChevronRight className="h-3 w-3 text-muted-foreground shrink-0 ml-1" />
              )}
            </div>
          );
        })}
      </nav>

      {/* Step 1 — App ID */}
      {step === 1 && (
        <div className="space-y-4">
          <div>
            <h3 className="text-sm font-semibold">Step 1 — Meta App ID</h3>
            <p className="text-xs text-muted-foreground mt-1">
              Found in{' '}
              <a
                href="https://developers.facebook.com/apps"
                target="_blank"
                rel="noreferrer"
                className="underline underline-offset-2 inline-flex items-center gap-0.5"
              >
                Meta Developer Console
                <ExternalLink className="h-3 w-3" />
              </a>
              {' '}→ your app → App Settings → Basic.
            </p>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="app-id">App ID <span className="text-destructive">*</span></Label>
            <Input
              id="app-id"
              value={appId}
              onChange={(e) => setAppId(e.target.value)}
              placeholder="e.g. 123456789012345"
              autoFocus
            />
          </div>
        </div>
      )}

      {/* Step 2 — App Secret */}
      {step === 2 && (
        <div className="space-y-4">
          <div>
            <h3 className="text-sm font-semibold">Step 2 — App Secret</h3>
            <p className="text-xs text-muted-foreground mt-1">
              Found in Meta Developer Console → App Settings → Basic → App Secret.
              Stored encrypted and never displayed after save.
            </p>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="app-secret">
              App Secret <span className="text-destructive">*</span>
            </Label>
            <div className="relative">
              <Input
                id="app-secret"
                type={showSecret ? 'text' : 'password'}
                value={appSecret}
                onChange={(e) => setAppSecret(e.target.value)}
                placeholder={config.has_app_secret ? '••••••••  (leave blank to keep current)' : 'Enter your App Secret'}
                className="pr-9"
                autoFocus
              />
              <button
                type="button"
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                onClick={() => setShowSecret((v) => !v)}
                tabIndex={-1}
              >
                {showSecret ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
              </button>
            </div>
            {config.has_app_secret && (
              <p className="text-[11px] text-muted-foreground">
                A secret is already saved. Leave blank to keep it, or enter a new value to replace it.
              </p>
            )}
          </div>
        </div>
      )}

      {/* Step 3 — Redirect URI */}
      {step === 3 && (
        <div className="space-y-4">
          <div>
            <h3 className="text-sm font-semibold">Step 3 — Redirect URI</h3>
            <p className="text-xs text-muted-foreground mt-1">
              This URI must be added to your Meta App's{' '}
              <strong>Valid OAuth Redirect URIs</strong> in Facebook Login Settings.
            </p>
          </div>
          <div className="space-y-1.5">
            <div className="flex items-center justify-between">
              <Label htmlFor="redirect-uri">Redirect URI <span className="text-destructive">*</span></Label>
              <button
                type="button"
                className="text-[11px] text-primary hover:underline flex items-center gap-0.5"
                onClick={() => setRedirectUri(config.default_redirect_uri)}
              >
                <RotateCcw className="h-3 w-3" />
                Reset to default
              </button>
            </div>
            <Input
              id="redirect-uri"
              value={redirectUri}
              onChange={(e) => setRedirectUri(e.target.value)}
              placeholder={config.default_redirect_uri}
            />
          </div>
          <div className="rounded-md bg-muted/50 border px-3 py-2 text-xs text-muted-foreground">
            <span className="font-medium text-foreground">Required in Meta Console:</span>{' '}
            Facebook Login → Settings → Valid OAuth Redirect URIs → add the URI above.
          </div>
        </div>
      )}

      {/* Step 4 — Validate & Save */}
      {step === 4 && (
        <div className="space-y-4">
          <div>
            <h3 className="text-sm font-semibold">Step 4 — Validate &amp; Save</h3>
            <p className="text-xs text-muted-foreground mt-1">
              ECOS will verify your credentials against the Meta Graph API before saving.
            </p>
          </div>

          {/* Summary */}
          <dl className="rounded-md border divide-y text-xs">
            {[
              { label: 'App ID',       value: appId },
              { label: 'App Secret',   value: appSecret ? '••••••••' : (config.has_app_secret ? '••••••••  (existing)' : '—') },
              { label: 'Redirect URI', value: redirectUri },
            ].map(({ label, value }) => (
              <div key={label} className="flex gap-3 px-3 py-2">
                <dt className="w-28 shrink-0 text-muted-foreground">{label}</dt>
                <dd className="font-medium truncate">{value}</dd>
              </div>
            ))}
          </dl>

          {/* Validation result */}
          {(isValidating || isSaving) && (
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              {isValidating ? 'Verifying credentials with Meta…' : 'Saving configuration…'}
            </div>
          )}

          {validationResult && !validationResult.valid && (
            <div className="rounded-md bg-destructive/10 border border-destructive/20 p-3 space-y-1">
              <div className="flex items-center gap-1.5 text-sm font-medium text-destructive">
                <AlertCircle className="h-4 w-4 shrink-0" />
                Validation failed
              </div>
              <ul className="text-xs text-destructive/80 space-y-0.5 list-disc list-inside">
                {validationResult.errors.map((e) => <li key={e}>{e}</li>)}
              </ul>
            </div>
          )}

          {validationResult?.valid && (
            <div className="flex items-center gap-1.5 text-sm text-emerald-600">
              <CheckCircle2 className="h-4 w-4 shrink-0" />
              Connection successful — credentials verified.
            </div>
          )}
        </div>
      )}

      {/* Footer buttons */}
      <div className="flex items-center justify-between pt-2 border-t">
        <div className="flex gap-2">
          {step > 1 && (
            <Button variant="ghost" size="sm" onClick={back} disabled={isSaving || isValidating}>
              Back
            </Button>
          )}
          {onCancel && step === 1 && (
            <Button variant="ghost" size="sm" onClick={onCancel}>
              Cancel
            </Button>
          )}
        </div>

        {step < 4 ? (
          <Button size="sm" onClick={advance} disabled={!canAdvance()}>
            Next
          </Button>
        ) : (
          <Button
            size="sm"
            onClick={handleValidate}
            disabled={isValidating || isSaving}
          >
            {(isValidating || isSaving) && <Loader2 className="h-3.5 w-3.5 mr-1.5 animate-spin" />}
            Validate &amp; Save
          </Button>
        )}
      </div>
    </div>
  );
}
