import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PageHeader } from '@/components/crud';

export function BridgeSettingsPage() {
  const [defaultRepo, setDefaultRepo]     = useState(
    () => localStorage.getItem('cb_last_repo_path') ?? '',
  );
  const [defaultBranch, setDefaultBranch] = useState('main');

  function saveDefaults() {
    localStorage.setItem('cb_last_repo_path', defaultRepo);
  }

  return (
    <div className="space-y-6 p-6 max-w-2xl mx-auto">
      <PageHeader title="Claude Bridge Settings" />

      {/* Worker status */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Worker</CardTitle>
        </CardHeader>
        <CardContent>
          <div>
            <p className="text-muted-foreground text-sm mb-3">
              No worker registered. Register one to start using Claude Bridge.
            </p>
            <Button size="sm" disabled>
              Register Worker
            </Button>
            <p className="text-muted-foreground text-xs mt-2">
              Worker registration available in Sprint 2.
            </p>
          </div>
        </CardContent>
      </Card>

      {/* Default settings */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Default Settings</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="default-repo">Default Repository Path</Label>
            <Input
              id="default-repo"
              placeholder="C:\Projects\ecos-erp"
              value={defaultRepo}
              onChange={(e) => setDefaultRepo(e.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="default-branch">Default Branch</Label>
            <Input
              id="default-branch"
              value={defaultBranch}
              onChange={(e) => setDefaultBranch(e.target.value)}
            />
          </div>
          <Button size="sm" onClick={saveDefaults}>
            Save Defaults
          </Button>
          <p className="text-muted-foreground text-xs">
            Saved to browser — used to pre-fill the Create Task form.
          </p>
        </CardContent>
      </Card>

      {/* Worker setup instructions */}
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Worker Setup</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <ol className="text-sm space-y-1 list-decimal pl-4">
            <li>Install Node.js (LTS) on the worker machine</li>
            <li>Install PM2: <code className="bg-muted px-1 rounded">npm install -g pm2</code></li>
            <li>Download the worker package below</li>
            <li>Extract to <code className="bg-muted px-1 rounded">C:\claude-bridge\</code></li>
            <li>Edit <code className="bg-muted px-1 rounded">config.json</code> with ECOS URL and API token</li>
            <li>Run: <code className="bg-muted px-1 rounded">pm2 start worker.js --name claude-bridge</code></li>
            <li>Run: <code className="bg-muted px-1 rounded">pm2 save &amp;&amp; pm2 startup</code></li>
          </ol>
          <Button size="sm" variant="outline" disabled>
            Download Worker Package
          </Button>
          <p className="text-muted-foreground text-xs">
            Available in Sprint 2.
          </p>
        </CardContent>
      </Card>
    </div>
  );
}
