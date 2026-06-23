<?php

declare(strict_types=1);

namespace Modules\Commerce\ProductImport\Application\DTO;

final class ImportResultDTO
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $imported,
        public readonly int $created_products,
        public readonly int $created_mappings,
        public readonly int $failed,
        public readonly array $errors = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'imported' => $this->imported,
            'created_products' => $this->created_products,
            'created_mappings' => $this->created_mappings,
            'failed' => $this->failed,
            'errors' => $this->errors,
        ];
    }

    public function summary(): string
    {
        return sprintf(
            'Import completed. %d processed, %d products created, %d mappings created, %d failed.',
            $this->imported,
            $this->created_products,
            $this->created_mappings,
            $this->failed,
        );
    }
}
