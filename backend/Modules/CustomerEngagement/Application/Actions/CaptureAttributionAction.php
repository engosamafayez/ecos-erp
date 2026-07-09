<?php

namespace Modules\CustomerEngagement\Application\Actions;

use Modules\CustomerEngagement\Application\Services\AttributionCaptureService;
use Modules\CustomerEngagement\Domain\Models\Conversation;
use Modules\CustomerEngagement\Domain\Models\ConversationAttribution;

class CaptureAttributionAction
{
    public function __construct(private readonly AttributionCaptureService $attributionService) {}

    public function execute(Conversation $conversation, array $attributionData): ConversationAttribution
    {
        return $this->attributionService->capture($conversation, $attributionData);
    }
}
