<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\LogBroadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * TASK-ECOS-V1-PRIVATE-CONVERSATION-BROADCAST-SECURITY-038.
 *
 * Stock Illuminate\Broadcasting\Broadcasters\LogBroadcaster::auth() is an
 * unconditional no-op (empty method body — see vendor source): it never
 * calls the inherited Broadcaster::verifyUserCanAccessChannel(), so every
 * channel registered in routes/channels.php — private or not — was left
 * completely unauthorized under the 'log' driver, this application's
 * default (config/broadcasting.php). Every real driver (Pusher, Reverb via
 * UsePusherChannelConventions, Redis, Ably) already calls that same
 * inherited method from its own auth(); this class makes the 'log' driver
 * do exactly the same, so the security boundary does not depend on which
 * broadcast transport happens to be configured. broadcast() (outbound
 * log-based event delivery) is inherited unchanged — message delivery
 * semantics are not touched, only the previously-missing authorization
 * check on /broadcasting/auth.
 */
final class FailClosedLogBroadcaster extends LogBroadcaster
{
    use UsePusherChannelConventions;

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     *
     * @throws AccessDeniedHttpException
     */
    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if (empty($request->channel_name) ||
            ($this->isGuardedChannel($request->channel_name) &&
            ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $channelName);
    }

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  mixed  $result
     * @return mixed
     */
    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }
}
