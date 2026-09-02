<?php

declare(strict_types=1);

namespace Modules\Collaboration\Infrastructure\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Policies\ConversationPolicy;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;
use Modules\IAM\Domain\Contracts\PermissionRegistryInterface;

final class CollaborationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(InternalTask::class, TaskPolicy::class);

        // ADR-038 self-registration (architecture report §14). The load-bearing
        // catalog today is still config/permissions.php's 'modules' key — see
        // that file's own header comment — so these permissions are also
        // declared there; this call is the forward-compatible path alongside it.
        $this->app->make(PermissionRegistryInterface::class)->register('collaboration', [
            'conversations' => ['create', 'message_drivers'],
            'groups' => ['create'],
            'tasks' => ['create', 'assign_drivers'],
        ]);
    }
}
