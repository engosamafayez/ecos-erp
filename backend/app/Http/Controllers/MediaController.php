<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Handles file upload to Laravel public storage.
 * Returns a relative path for DB storage and a full URL for immediate display.
 *
 * 035B-R1: this endpoint previously required only auth:sanctum — any
 * authenticated user, for any context. Authorization is context-aware: each
 * legitimate context maps to the SAME permission(s) that already govern its
 * owning entity's create/update routes (see routes/api.php), checked through
 * this platform's single authorization entry point
 * (AuthorizationGatewayInterface — never a second, parallel check). The map
 * lives here rather than in config: every context value it covers is already
 * hardcoded in this file's own validation rule below, and nothing else
 * consumes it.
 *
 * Every real caller uploads the file before calling its entity's create-or-
 * update endpoint, using the same context for both forms, so upload time
 * cannot yet know which one will follow. Requiring EITHER permission is the
 * narrowest check that does not block a legitimate caller: the upload itself
 * creates no DB row and attaches to nothing, so holding only one of the two
 * grants no more than the ability to write an orphan file — the entity's own
 * create/update endpoint still independently enforces its own permission
 * before anything is actually persisted or attached.
 */
final class MediaController extends Controller
{
    use HasApiResponse;

    /** Contexts that allow document uploads (images + PDF). */
    private const DOCUMENT_CONTEXTS = ['order-proof'];

    /**
     * Context → any-of permissions. A context absent here is denied outright,
     * regardless of the validation whitelist below drifting in the future.
     *
     * @var array<string, list<string>>
     */
    private const CONTEXT_PERMISSIONS = [
        'raw-materials' => ['inventory.products.create', 'inventory.products.update'],
        'packaging-materials' => ['inventory.products.create', 'inventory.products.update'],
        'products' => ['inventory.products.create', 'inventory.products.update'],
        'brands' => ['organization.brands.create', 'organization.brands.update'],
        'companies' => ['organization.companies.create', 'organization.companies.update'],
        'business-accounts' => ['organization.business_accounts.create', 'organization.business_accounts.update'],
    ];

    public function __construct(
        private readonly AuthorizationGatewayInterface $authorizationGateway,
    ) {}

    public function upload(Request $request): JsonResponse
    {
        $context = $request->input('context', 'raw-materials');

        if (in_array($context, self::DOCUMENT_CONTEXTS, true)) {
            $request->validate([
                'file' => ['required', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp,gif,pdf'],
                'context' => ['nullable', 'string'],
            ]);
        } else {
            $request->validate([
                'file' => ['required', 'file', 'image', 'max:5120', 'mimes:jpeg,jpg,png,webp,gif'],
                'context' => ['nullable', 'string', 'in:raw-materials,products,packaging-materials,brands,companies,business-accounts'],
            ]);
        }

        $this->authorizeContext($request, (string) $context);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'webp');
        $path = $context.'/'.Str::ulid().'.'.$ext;

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        return $this->success([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }

    /**
     * @throws HttpException 401 unauthenticated, 403 unknown context or
     *                       missing permission for the resolved context.
     */
    private function authorizeContext(Request $request, string $context): void
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $permissions = self::CONTEXT_PERMISSIONS[$context] ?? null;

        if ($permissions === null) {
            abort(403, "Media upload is not permitted for context '{$context}'.");
        }

        foreach ($permissions as $permission) {
            // inspect(), not can()/authorize(): only inspect()/decision() carry
            // this platform's is_system bypass (ADR-038 Part 1) — can() would
            // wrongly deny a system-role actor holding no explicit grant.
            if (! $this->authorizationGateway->inspect($user, $permission)->isDenied()) {
                return;
            }
        }

        abort(403, "You do not have permission to upload media for context '{$context}'.");
    }
}
