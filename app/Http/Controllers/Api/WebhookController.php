<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookLog;
use App\Services\DigiflazzH2HService;
use App\Services\MidtransWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __construct(
        private readonly MidtransWebhookService $midtransWebhookService,
        private readonly DigiflazzH2HService $digiflazzH2HService,
    ) {}

    public function midtrans(Request $request): JsonResponse
    {
        $result = $this->midtransWebhookService->handleNotification($request->all());

        return response()->json($result, (int) ($result['http_status'] ?? 200));
    }

    public function digiflazz(Request $request): JsonResponse
    {
        $data = $request->input('data', $request->all());
        $refId = (string) ($data['ref_id'] ?? $request->input('ref_id', ''));
        $status = (string) ($data['status'] ?? $request->input('status', ''));
        $snOrMessage = (string) ($data['sn'] ?? $data['message'] ?? $request->input('sn', ''));
        $signField = (string) ($data['sign'] ?? $request->input('sign', ''));

        $signatureValid = $this->digiflazzH2HService->verifyWebhookSignature(
            rawBody: (string) $request->getContent(),
            hubSignatureHeader: $request->header('X-Hub-Signature'),
            refId: $refId,
            signField: $signField,
        );

        if (! $signatureValid) {
            PaymentWebhookLog::create([
                'provider' => 'digiflazz_h2h',
                'idempotency_key' => 'invalid_df_sig_'.substr($refId !== '' ? $refId : 'unknown', 0, 50).'_'.Str::uuid(),
                'signature_valid' => false,
                'http_status' => 403,
                'raw_payload' => $request->all(),
                'processing_notes' => 'REJECTED_INVALID_DIGIFLAZZ_SIGNATURE',
                'created_at' => now(),
            ]);

            return response()->json([
                'ok' => false,
                'signature_valid' => false,
                'message' => 'Forbidden: Invalid Digiflazz webhook signature.',
            ], 403);
        }

        if ($refId === '' || $status === '') {
            return response()->json(['message' => 'ref_id dan status wajib disertakan.'], 422);
        }

        $result = $this->digiflazzH2HService->handleH2HWebhook($refId, $status, $snOrMessage, $request->all());

        return response()->json($result, (int) ($result['http_status'] ?? 200));
    }
}
