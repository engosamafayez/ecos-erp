<?php

declare(strict_types=1);

namespace Modules\Core\UserPreferences\Domain\Enums;

/**
 * Known preference categories across the ERP.
 *
 * The API accepts ANY valid category string (not restricted to this enum),
 * so new modules can introduce their own categories without touching this file.
 * This enum serves as the canonical reference for first-party categories and
 * their documented payload shapes.
 *
 * TABLE CATEGORIES (products, orders, customers, …)
 *   Expected payload shape:
 *   {
 *     "columns":     { "image": true, "sku": false, … },  // ColKey → visible
 *     "column_order": ["name", "sku", "price", …],        // display order
 *     "column_widths": { "name": 240, "sku": 120, … },    // px overrides
 *     "density":     "comfortable" | "compact",
 *     "sort":        { "field": "name", "direction": "asc" },
 *     "page_size":   25,
 *     "filter_presets": [
 *       { "id": "uuid", "name": "Low Stock", "filters": { … } }
 *     ]
 *   }
 *
 * THEME
 *   { "theme": "light" | "dark" | "system", "language": "en", "timezone": "UTC" }
 *
 * WORKSPACE
 *   { "default_company": "uuid", "default_branch": "uuid", "default_warehouse": "uuid" }
 */
enum PreferenceCategory: string
{
    // ── Table categories ───────────────────────────────────────────────────────
    case Products = 'products';
    case Orders = 'orders';
    case Customers = 'customers';
    case Suppliers = 'suppliers';
    case Inventory = 'inventory';
    case Purchasing = 'purchasing';
    case Manufacturing = 'manufacturing';
    case Reports = 'reports';
    case Dashboard = 'dashboard';

    // ── User-level settings ────────────────────────────────────────────────────
    case Theme = 'theme';
    case Workspace = 'workspace';

    // TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §9 — written
    // through the dedicated, validated Modules\AI\Presentation\Http\Controllers\
    // AssistantPreferenceController (not the generic upsert() here directly),
    // which is why its payload shape is documented in
    // Modules\AI\Application\ValueObjects\AssistantPreferences rather than below.
    case AiAssistant = 'ai_assistant';

    // ── Default payload shapes ────────────────────────────────────────────────

    /**
     * Return the default payload for this category so reset actions have a
     * source of truth. Returning null means "remove the row entirely".
     *
     * @return array<string, mixed>|null
     */
    public function defaultPayload(): ?array
    {
        return match ($this) {
            self::Theme => self::defaultTheme(),
            self::Workspace => self::defaultWorkspace(),
            // Factory-seeding convenience only (see UserPreferenceFactory) — the real runtime
            // default authority is Modules\AI\Application\ValueObjects\AssistantPreferences;
            // kept as a plain literal here rather than importing the AI module to avoid a
            // Core → feature-module dependency for a test-data helper.
            self::AiAssistant => self::defaultAiAssistant(),
            default => self::defaultTablePreferences(),
        };
    }

    /** @return array<string, mixed> */
    private static function defaultTablePreferences(): array
    {
        return [
            'columns' => [],
            'column_order' => [],
            'column_widths' => [],
            'density' => 'comfortable',
            'sort' => ['field' => null, 'direction' => 'asc'],
            'page_size' => 25,
            'filter_presets' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultTheme(): array
    {
        return [
            'theme' => 'system',
            'language' => 'en',
            'timezone' => 'UTC',
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultWorkspace(): array
    {
        return [
            'default_company' => null,
            'default_branch' => null,
            'default_warehouse' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function defaultAiAssistant(): array
    {
        return [
            'avatar_key' => 'ecos_blue_bot',
            'name' => 'ECOS Assistant',
            'persona' => 'neutral',
            'speaking_style' => 'friendly',
            'language' => 'bilingual',
            'voice_input_enabled' => false,
            'spoken_responses_enabled' => false,
            'wake_by_name_enabled' => false,
            'voice_choice' => null,
        ];
    }
}
