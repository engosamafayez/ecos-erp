<?php

declare(strict_types=1);

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * TASK-ECOS-V1-PRIVATE-CONVERSATION-BROADCAST-SECURITY-038.
 *
 * Same defect as FailClosedLogBroadcaster (see that class's docblock), one
 * driver over: stock Illuminate\Broadcasting\Broadcasters\NullBroadcaster's
 * auth() is also an unconditional no-op. Fixed the same way, so switching
 * BROADCAST_CONNECTION to 'null' does not silently reopen this gate.
 * broadcast() is inherited unchanged (still a true no-op for the null
 * driver) — only the authorization check is affected.
 */
final class FailClosedNullBroadcaster extends NullBroadcaster
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
