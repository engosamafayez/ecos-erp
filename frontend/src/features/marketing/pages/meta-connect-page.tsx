import { useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useMetaAuthUrl, useMetaCallback } from '../hooks/use-meta-auth';
import { Button } from '@/components/ui/button';
import { useToast } from '@/components/ds/use-toast';
import { ConnectorIcon } from '../components/connector-icon';

/**
 * Two responsibilities:
 * 1. "Connect" button → calls /meta/auth/redirect and redirects browser to Meta OAuth
 * 2. Callback handler → reads ?code=&state= from URL and calls /meta/auth/callback
 */
export function MetaConnectPage() {
  const [params]   = useSearchParams();
  const navigate   = useNavigate();
  const { toast }  = useToast();
  const getUrl     = useMetaAuthUrl();
  const callback   = useMetaCallback();

  const code  = params.get('code');
  const state = params.get('state');

  // Auto-handle callback if URL has code + state
  useEffect(() => {
    if (!code || !state) return;
    if (callback.isPending || callback.isSuccess || callback.isError) return;

    callback.mutate(
      { code, state },
      {
        onSuccess: () => {
          toast({ title: 'Meta connected successfully! Assets are being discovered.' });
          navigate('/marketing', { replace: true });
        },
        onError: (err: unknown) => {
          const msg = (err as { response?: { data?: { message?: string } } })
            ?.response?.data?.message ?? 'Connection failed. Please try again.';
          toast({ title: 'Connection failed', description: msg, variant: 'destructive' });
        },
      },
    );
  }, [code, state]); // eslint-disable-line react-hooks/exhaustive-deps

  function handleConnect() {
    // We use the current company from localStorage / context — simplified here
    const companyId = (window as unknown as Record<string, string>).__ECOS_COMPANY_ID__ ?? '';

    getUrl.mutate(companyId, {
      onSuccess: ({ url }) => {
        window.location.href = url;
      },
      onError: () => {
        toast({ title: 'Failed to generate auth URL', variant: 'destructive' });
      },
    });
  }

  // Callback in progress
  if (code && state) {
    return (
      <div className="flex flex-col items-center justify-center h-64 gap-4">
        <ConnectorIcon connector="meta" size="lg" />
        <p className="text-sm text-muted-foreground">
          {callback.isPending
            ? 'Completing Meta connection…'
            : callback.isError
            ? 'Connection failed. Please close this page and try again.'
            : 'Connected!'}
        </p>
      </div>
    );
  }

  return (
    <div className="max-w-md mx-auto mt-20 space-y-6 p-6">
      <div className="flex flex-col items-center gap-4 text-center">
        <ConnectorIcon connector="meta" size="lg" />
        <div>
          <h1 className="text-xl font-semibold">Connect Meta</h1>
          <p className="text-sm text-muted-foreground mt-1">
            Connect your Meta Business account to discover and manage all your
            Meta assets — Ad Accounts, Pages, Pixels, Catalogs, and more.
          </p>
        </div>
      </div>

      <div className="rounded-lg border bg-muted/30 p-4 text-sm space-y-2">
        <p className="font-medium">Permissions requested:</p>
        <ul className="list-disc list-inside text-muted-foreground space-y-1">
          <li>Business management</li>
          <li>Ads management & read</li>
          <li>Pages & Instagram</li>
          <li>Catalog management</li>
        </ul>
      </div>

      <Button
        className="w-full"
        onClick={handleConnect}
        disabled={getUrl.isPending}
      >
        {getUrl.isPending ? 'Redirecting to Meta…' : 'Connect with Meta'}
      </Button>

      <p className="text-xs text-center text-muted-foreground">
        You will be redirected to Meta to authorize access. No passwords are stored.
      </p>
    </div>
  );
}
