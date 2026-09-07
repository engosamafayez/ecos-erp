<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use InvalidArgumentException;
use Modules\IAM\Application\DTO\AuthenticatedUserDTO;
use Modules\IAM\Application\DTO\LoginDTO;
use Modules\IAM\Application\Services\UserSessionService;
use Modules\IAM\Domain\Contracts\AuthServiceInterface;
use Modules\IAM\Domain\Exceptions\InvalidCredentialsException;

/**
 * Authenticates a user and issues an API token.
 */
final class LoginAction extends BaseAction
{
    public function __construct(
        private readonly AuthServiceInterface $authService,
        private readonly UserSessionService $sessions,
    ) {}

    /**
     * @param  mixed  ...$arguments  Expects a {@see LoginDTO}, then optionally the request IP
     *                               and User-Agent string (D10, TASK-ECOS-IAM-SECURE-ADMIN-API-002)
     *                               for session recording. Both default to null so any other
     *                               caller passing only the DTO keeps working unchanged.
     *
     * @throws InvalidCredentialsException When the credentials do not match, or the account's
     *                                     lifecycle status does not permit authentication (D7) —
     *                                     both are reported identically, by design.
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;
        $ip = is_string($arguments[1] ?? null) ? $arguments[1] : null;
        $userAgent = is_string($arguments[2] ?? null) ? $arguments[2] : null;

        if (! $dto instanceof LoginDTO) {
            throw new InvalidArgumentException('LoginAction::execute expects a LoginDTO.');
        }

        $user = $this->authService->attemptCredentials($dto->identifier, $dto->password);

        if ($user === null) {
            throw new InvalidCredentialsException;
        }

        $token = $this->authService->issueToken($user, $dto->remember);
        $this->sessions->record($user, $this->authService->lastIssuedTokenId(), $ip, $userAgent);

        return OperationResult::success(
            [
                'token' => $token,
                'token_type' => 'Bearer',
                'user' => AuthenticatedUserDTO::fromModel($user)->toArray(),
            ],
            'Authenticated successfully.',
        );
    }
}
