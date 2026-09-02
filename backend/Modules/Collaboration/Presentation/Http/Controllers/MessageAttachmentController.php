<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Secure voice/image/file playback (ADR-044 §1.4/§1.8, brief §4/§8):
 * Authenticate (route middleware) -> resolve message -> verify active
 * conversation participation -> resolve the attached Document via the
 * message id (never a client-supplied document id) -> stream from the
 * private disk. Mirrors SupplierInvoiceDocumentController::download()
 * exactly — never a public-disk URL, never `Document::findOrFail()` on a
 * bare client-supplied id. A non-participant gets 403 regardless of
 * whether they know the message id, the underlying document id, or a
 * copied storage path — none of those alone can reach this file.
 */
final class MessageAttachmentController extends Controller
{
    private const DISK = 'local';

    public function show(Request $request, Message $message): StreamedResponse
    {
        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $request->user()->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isParticipant) {
            abort(403, 'You are not a participant of this conversation.');
        }

        $document = $message->attachment();

        if ($document === null) {
            abort(404, 'This message has no attachment.');
        }

        if (! Storage::disk(self::DISK)->exists($document->file_path)) {
            abort(404, 'File not found on disk.');
        }

        return Storage::disk(self::DISK)->download($document->file_path, $document->name);
    }
}
