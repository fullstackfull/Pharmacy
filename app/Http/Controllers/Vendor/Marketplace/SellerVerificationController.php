<?php

namespace App\Http\Controllers\Vendor\Marketplace;

use App\Http\Controllers\BaseController;
use App\Services\Marketplace\SellerVerificationService;
use App\Utils\ImageManager;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The seller's own KYC screen (Phase 3, Stage A): see the required documents, what has been
 * submitted, each document's review state and any rejection reason, and submit a new one.
 *
 * Identity always comes from auth('seller') — a seller can only ever act on their own documents,
 * never a supplied id.
 */
class SellerVerificationController extends BaseController
{
    /** The document extensions a seller may upload. Server-controlled — the client extension is mapped
     *  onto this whitelist, never trusted, so an upload can't smuggle an executable extension. */
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    public function __construct(private readonly SellerVerificationService $verification)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $sellerId = auth('seller')->id();

        return view('vendor-views.marketplace.seller-verification', [
            'documents' => $this->verification->documentsFor($sellerId),
            'requiredDocuments' => $this->verification->requiredDocumentTypes(),
            'overallStatus' => $this->verification->overallStatus($sellerId),
            'kycRequiredForPayout' => $this->verification->isKycRequiredForPayout(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|string|max:60',
            'document_number' => 'nullable|string|max:120',
            'expires_at' => 'nullable|date',
            'document_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        $filePath = null;
        if ($request->hasFile('document_file')) {
            $clientExt = strtolower($request->file('document_file')->getClientOriginalExtension());
            $ext = in_array($clientExt, self::ALLOWED_EXTENSIONS, true) ? $clientExt : 'pdf';
            $filePath = ImageManager::file_upload('seller/kyc/', $ext, $request->file('document_file'));
        }

        $this->verification->submit(
            sellerId: auth('seller')->id(),
            type: $validated['document_type'],
            number: $validated['document_number'] ?? null,
            filePath: $filePath,
            expiresAt: $validated['expires_at'] ?? null,
        );

        ToastMagic::success(translate('document_submitted_for_review'));

        return back();
    }
}
