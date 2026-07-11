<?php

declare(strict_types=1);

namespace Modules\Marketing\MetaConnector\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Marketing\MetaConnector\Application\Services\MetaOAuthService;
use Modules\Marketing\Synchronization\Application\Actions\RunSyncAction;
use Modules\Marketing\Synchronization\Domain\Enums\SyncType;

final class MetaAuthController extends Controller
{
    public function __construct(
        private readonly MetaOAuthService $oauthService,
        private readonly RunSyncAction    $runSync,
    ) {}

    /**
     * Generate a Meta OAuth URL for the frontend to redirect the user to.
     *
     * GET /marketing/meta/auth/redirect?company_id=xxx
     */
    public function redirect(Request $request): JsonResponse
    {
        $companyId = $request->string('company_id')->toString();

        ['url' => $url, 'state' => $state] = $this->oauthService->buildAuthUrl($companyId);

        return response()->json(['url' => $url, 'state' => $state]);
    }

    /**
     * Handle the OAuth callback from Meta.
     *
     * GET /marketing/meta/auth/callback
     *
     * After exchange + token extension, triggers an initial full sync.
     */
    public function callback(Request $request): JsonResponse
    {
        $request->validate([
            'code'  => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $connection = $this->oauthService->handleCallback(
            code:    $request->string('code')->toString(),
            state:   $request->string('state')->toString(),
            actorId: (string) (string) $request->user()->id,
        );

        // Kick off initial discovery in the background
        $syncLog = $this->runSync->execute($connection, SyncType::Full, (string) (string) $request->user()->id);

        return response()->json([
            'message'    => 'Meta connection established successfully.',
            'connection' => [
                'id'             => $connection->id,
                'label'          => $connection->label,
                'status'         => $connection->status,
                'connector_type' => $connection->connector_type,
            ],
            'sync_log_id' => $syncLog->id,
        ], 201);
    }
}
