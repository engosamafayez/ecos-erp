<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Core\Documents\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Modules\Collaboration\Domain\Enums\TaskPriority;
use Modules\Collaboration\Domain\Enums\TaskStatus;
use Modules\Collaboration\Infrastructure\Database\Factories\InternalTaskFactory;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Organization\Teams\Domain\Models\Team;

/**
 * Owned by Modules\Collaboration (ADR-044 §1.5, brief §2) — a lightweight
 * work-coordination object, never reinterpreted as an HR canonical task, a
 * Shipping Trip task, or a project-management aggregate. `search_tsv` is a
 * Postgres STORED generated column (see its migration) — deliberately
 * absent from $fillable, since Eloquent must never attempt to write it.
 *
 * @property string $id
 * @property string $company_id
 * @property string $title
 * @property string|null $description
 * @property int $creator_user_id
 * @property int $assignee_user_id
 * @property string|null $team_id
 * @property TaskPriority $priority
 * @property TaskStatus $status
 * @property \Illuminate\Support\Carbon|null $due_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $source_conversation_id
 * @property string|null $source_message_id
 * @property string|null $source_message_snapshot
 * @property string|null $task_list_id
 * @property int $board_position
 */
class InternalTask extends Model
{
    /** @use HasFactory<InternalTaskFactory> */
    use HasFactory, HasUuids;

    protected $table = 'collaboration_internal_tasks';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'title',
        'description',
        'creator_user_id',
        'assignee_user_id',
        'team_id',
        'priority',
        'status',
        'due_at',
        'completed_at',
        'cancelled_at',
        'source_conversation_id',
        'source_message_id',
        'source_message_snapshot',
        'task_list_id',
        'board_position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'board_position' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Conversation, $this> */
    public function sourceConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'source_conversation_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'source_message_id');
    }

    /** @return HasMany<InternalTaskComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(InternalTaskComment::class, 'task_id');
    }

    /** @return HasMany<InternalTaskActivity, $this> */
    public function activity(): HasMany
    {
        return $this->hasMany(InternalTaskActivity::class, 'task_id')->orderByDesc('created_at');
    }

    /** @return BelongsTo<TaskBoardList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskBoardList::class, 'task_list_id');
    }

    /** @return BelongsToMany<TaskLabel, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(TaskLabel::class, 'collaboration_task_label_task', 'task_id', 'label_id')
            ->withPivot('created_at');
    }

    /** @return HasMany<TaskChecklist, $this> */
    public function checklists(): HasMany
    {
        return $this->hasMany(TaskChecklist::class, 'task_id')->orderBy('position');
    }

    /** @return HasMany<TaskFollower, $this> */
    public function followers(): HasMany
    {
        return $this->hasMany(TaskFollower::class, 'task_id');
    }

    /** @return HasManyThrough<TaskChecklistItem, TaskChecklist, $this> */
    public function checklistItems(): HasManyThrough
    {
        return $this->hasManyThrough(TaskChecklistItem::class, TaskChecklist::class, 'task_id', 'checklist_id');
    }

    /**
     * Same generic document authority every other module uses (brief §14 —
     * reuse DocumentService's polymorphic `documents` table, never a
     * dedicated TaskAttachment table). `subject_type` carries the discriminator
     * string TaskAttachmentController already writes, not a class-name morph.
     *
     * @return HasMany<Document, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Document::class, 'subject_id')->where('subject_type', 'CollaborationTask');
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! in_array($this->status, [TaskStatus::Done, TaskStatus::Cancelled], true);
    }

    protected static function newFactory(): InternalTaskFactory
    {
        return InternalTaskFactory::new();
    }
}
