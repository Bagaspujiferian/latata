<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DigiflazzH2HService;
use App\Services\MidtransWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        if ($refId === '' || $status === '') {
            return response()->json(['message' => 'ref_id dan status wajib disertakan.'], 422);
        }

        $result = $this->digiflazzH2HService->handleH2HWebhook($refId, $status, $snOrMessage, $request->all());

        return response()->json($result, (int) ($result['http_status'] ?? 200));
    }
}
