<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Presentation\Http\Controllers;

use App\Core\Documents\Document;
use App\Core\Documents\DocumentService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-PROCUREMENT-SUPPLIER-INVOICE-COMMERCIAL-CONTRACT-001 §3 — the invoice attachment.
 *
 * Reuses the canonical {@see DocumentService} (generic `documents` table, PRIVATE `local` disk,
 * `company_id` + `subject_type`/`subject_id` owner) — no new upload system, no schema change. The
 * invoice is resolved through its own tenant-scoped route binding, and every document query is
 * additionally filtered by `subject`/`company_id` (the `documents` table carries no global tenant
 * scope), following the certified SupplierDocument / PaymentProof download discipline: files are
 * streamed through this auth+permission-gated controller and never exposed as raw public paths.
 */
final class SupplierInvoiceDocumentController extends Controller
{
    use HasApiResponse;

    private const SUBJECT = 'SupplierInvoice';

    private const DOCUMENT_TYPE = 'supplier_invoice';

    private const DISK = 'local';

    private const MAX_FILE_MB = 20;

    public function __construct(private readonly DocumentService $documents) {}

    /** GET /supplier-invoices/{supplierInvoice}/documents */
    public function index(SupplierInvoice $supplierInvoice): JsonResponse
    {
        $docs = $this->documents
            ->getFor(self::SUBJECT, (string) $supplierInvoice->id, self::DOCUMENT_TYPE)
            ->filter(fn (Document $d): bool => (string) $d->company_id === (string) $supplierInvoice->company_id)
            ->map(fn (Document $d): array => $this->format($d))
            ->values();

        return $this->success($docs);
    }

    /** POST /supplier-invoices/{supplierInvoice}/documents */
    public function store(Request $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.(self::MAX_FILE_MB * 1024), 'mimes:pdf,jpg,jpeg,png,webp'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $doc = $this->documents->attach(
            companyId: (string) $supplierInvoice->company_id,
            subjectType: self::SUBJECT,
            subjectId: (string) $supplierInvoice->id,
            documentType: self::DOCUMENT_TYPE,
            file: $request->file('file'),
            uploadedBy: Auth::id(),
            notes: $request->input('notes'),
        );

        return $this->success($this->format($doc), 'Attachment uploaded.', 201);
    }

    /** GET /supplier-invoices/{supplierInvoice}/documents/{document}/download */
    public function download(SupplierInvoice $supplierInvoice, string $document): StreamedResponse
    {
        $doc = $this->resolve($supplierInvoice, $document);

        if (! Storage::disk(self::DISK)->exists($doc->file_path)) {
            abort(404, 'File not found on disk.');
        }

        return Storage::disk(self::DISK)->download($doc->file_path, $doc->name);
    }

    /** DELETE /supplier-invoices/{supplierInvoice}/documents/{document} */
    public function destroy(SupplierInvoice $supplierInvoice, string $document): JsonResponse
    {
        $doc = $this->resolve($supplierInvoice, $document);

        Storage::disk(self::DISK)->delete($doc->file_path);
        $doc->delete();

        return $this->success(null, 'Attachment deleted.');
    }

    /**
     * Load one document proven to belong to THIS invoice and THIS company — the tenant boundary,
     * since the `documents` table has no global scope. A foreign or mismatched id is a 404.
     */
    private function resolve(SupplierInvoice $supplierInvoice, string $documentId): Document
    {
        return Document::query()
            ->where('id', $documentId)
            ->where('subject_type', self::SUBJECT)
            ->where('subject_id', (string) $supplierInvoice->id)
            ->where('company_id', (string) $supplierInvoice->company_id)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function format(Document $doc): array
    {
        return [
            'id' => $doc->id,
            'name' => $doc->name,
            'mime_type' => $doc->mime_type,
            'file_size' => $doc->file_size !== null ? (int) $doc->file_size : null,
            'notes' => $doc->notes,
            'uploaded_by' => $doc->uploaded_by,
            'created_at' => $doc->created_at?->toIso8601String(),
        ];
    }
}
