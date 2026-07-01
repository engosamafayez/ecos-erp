<?php

declare(strict_types=1);

namespace Modules\POS\Session\Domain\Contracts;

use Modules\POS\Session\Domain\Models\Session;

interface SessionRepositoryInterface
{
    public function findById(string $id): ?Session;

    /** Returns the currently Open session for the given terminal, or null if none exists. */
    public function findOpenByTerminal(string $terminalId): ?Session;

    /** Returns true if the terminal currently has an Open session. */
    public function hasOpenSessionForTerminal(string $terminalId): bool;

    public function save(Session $session): void;
}
