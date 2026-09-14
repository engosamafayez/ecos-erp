<?php

declare(strict_types=1);

namespace Modules\AI\Application\Services;

use LogicException;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Exceptions\UnknownAIToolException;

/**
 * Explicit tool registry (§10), mirroring ReportHandlerRegistry exactly: built
 * once from an explicit list handed in by {@see \Modules\AI\Infrastructure\Providers\AIServiceProvider}
 * — never by scanning/auto-discovering classes or resolving a class name the
 * model supplied. A duplicate tool name is a construction-time bug, not a
 * runtime possibility.
 *
 * Not `final` as of TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 —
 * {@see \Modules\CustomerEngagement\Voice\Application\Services\VoiceAIToolRegistry} is a
 * trivial, no-override subclass that exists purely to give Voice's deliberately smaller tool
 * list its own container-resolvable type (architecture report, CORE-03 REUSE).
 */
class AIToolRegistry
{
    /** @var array<string, AIToolInterface> */
    private array $tools = [];

    /**
     * @param  iterable<AIToolInterface>  $tools
     */
    public function __construct(iterable $tools)
    {
        foreach ($tools as $tool) {
            $name = $tool->name();

            if (isset($this->tools[$name])) {
                throw new LogicException("Duplicate AI tool registration: {$name}");
            }

            $this->tools[$name] = $tool;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @throws UnknownAIToolException
     */
    public function resolve(string $name): AIToolInterface
    {
        return $this->tools[$name] ?? throw UnknownAIToolException::forName($name);
    }

    /** @return list<AIToolInterface> */
    public function all(): array
    {
        return array_values($this->tools);
    }
}
