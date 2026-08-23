<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Models\SellerVerificationDocument;
use App\Services\Marketplace\SellerVerificationService;
use App\Utils\Helpers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Seller KYC over the v3 seller API — the mobile twin of the web
 * Vendor\Marketplace\SellerVerificationController: same service, same private
 * storage, same ownership rules.
 */
class SellerVerificationController extends Controller
{
    public function __construct(private readonly SellerVerificationService $verification)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;

        return response()->json([
            'overall_status' => $this->verification->overallStatus($sellerId),
            'kyc_required_for_payout' => $this->verification->isKycRequiredForPayout(),
            'required_documents' => $this->verification->requiredDocumentTypes(),
            'documents' => $this->verification->documentsFor($sellerId)->values(),
        ], 200);
    }

    public function submit(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'document_type' => 'required|string|max:60',
            'document_number' => 'nullable|string|max:120',
            'expires_at' => 'nullable|date',
            'document_file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => Helpers::validationErrorProcessor($validator)], 403);
        }

        $filePath = $request->hasFile('document_file')
            ? $this->verification->storeDocumentFile($request->file('document_file'))
            : null;

        $document = $this->verification->submit(
            sellerId: $request->seller->id,
            type: $request['document_type'],
            number: $request['document_number'] ?? null,
            filePath: $filePath,
            expiresAt: $request['expires_at'] ?? null,
        );

        return response()->json([
            'message' => translate('document_submitted_for_review'),
            'document' => $document,
        ], 200);
    }

    /**
     * Stream one of the token's own KYC documents from the private disk — scoped by seller_id,
     * so a seller can never fetch another shop's document by id.
     */
    public function document(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $document = SellerVerificationDocument::where(['id' => $id, 'seller_id' => $request->seller->id])->first();
        $path = $document?->file_path ? 'seller/kyc/' . $document->file_path : null;
        if (!$path || !Storage::disk('local')->exists($path)) {
            return response()->json(['message' => translate('file_not_found')], 404);
        }

        return Storage::disk('local')->response($path);
    }
}
