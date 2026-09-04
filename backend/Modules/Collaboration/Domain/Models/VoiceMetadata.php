<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Core\Documents\Document;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per voice-type Document (architecture report §10). Duration/format
 * are display metadata only — never used for any authorization decision
 * (playback authorization is entirely participation-based, see
 * MessageAttachmentController).
 *
 * @property string $id
 * @property string $document_id
 * @property int|null $duration_seconds
 * @property string|null $format
 * @property \Illuminate\Support\Carbon $created_at
 */
class VoiceMetadata extends Model
{
    use HasUuids;

    protected $table = 'collaboration_voice_metadata';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'document_id',
        'duration_seconds',
        'format',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
