<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Core\Documents\Document;
use App\Core\Documents\DocumentService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Collaboration\Domain\Models\InternalTask;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reuses the canonical DocumentService exactly like
 * SupplierInvoiceDocumentController (Task 3 precedent) — a task, unlike a
 * message, may carry several attachments, so this is list+upload+download
 * rather than the single-attachment-per-message shape in
 * MessageAttachmentController. No second attachment store, no public disk.
 */
final class TaskAttachmentController extends Controller
{
    use HasApiResponse;

    private const SUBJECT = 'CollaborationTask';

    private const DISK = 'local';

    private const MAX_FILE_MB = 25;

    public function __construct(private readonly DocumentService $documents) {}

    public function index(Request $request, InternalTask $task): JsonResponse
    {
        $this->authorize('view', $task);

        $docs = $this->documents->getFor(self::SUBJECT, $task->id)
            ->map(fn (Document $d): array => $this->format($d))
            ->values();

        return $this->success($docs);
    }

    public function store(Request $request, InternalTask $task): JsonResponse
    {
        $this->authorize('attach', $task);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(self::MAX_FILE_MB * 1024), 'mimes:pdf,doc,docx,xls,xlsx,csv,txt,zip,jpg,jpeg,png'],
        ]);

        $doc = $this->documents->attach(
            companyId: (string) $task->company_id,
            subjectType: self::SUBJECT,
            subjectId: $task->id,
            documentType: 'task_attachment',
            file: $request->file('file'),
            uploadedBy: $request->user()->id,
        );

        return $this->created($this->format($doc), 'Attachment uploaded.');
    }

    /** Guessed document identifiers do not bypass task authorization (brief §29). */
    public function show(Request $request, InternalTask $task, string $document): StreamedResponse
    {
        $this->authorize('view', $task);

        $doc = Document::query()
            ->where('id', $document)
            ->where('subject_type', self::SUBJECT)
            ->where('subject_id', $task->id)
            ->where('company_id', $task->company_id)
            ->firstOrFail();

        if (! Storage::disk(self::DISK)->exists($doc->file_path)) {
            abort(404, 'File not found on disk.');
        }

        return Storage::disk(self::DISK)->download($doc->file_path, $doc->name);
    }

    /** @return array<string, mixed> */
    private function format(Document $doc): array
    {
        return [
            'id' => $doc->id,
            'name' => $doc->name,
            'mime_type' => $doc->mime_type,
            'file_size' => $doc->file_size !== null ? (int) $doc->file_size : null,
            'uploaded_by' => $doc->uploaded_by,
            'created_at' => $doc->created_at?->toIso8601String(),
        ];
    }
}
