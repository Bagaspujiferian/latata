<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class H2HReconciliationService
{
    public function __construct(
        private readonly DigiflazzH2HService $digiflazzService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Reconcile stale api_h2h orders stuck in PROCESSING using their exact h2h_reference_id.
     *
     * @return array{
     *   checked: int,
     *   delivered: int,
     *   refunded: int,
     *   still_pending: int,
     *   escalated: int,
     *   details: list<array<string, mixed>>
     * }
     */
    public function reconcileStaleOrders(int $staleMinutes = 5, bool $dryRun = false): array
    {
        $query = Order::with(['items.product'])
            ->where('delivery_method', 'api_h2h')
            ->where('status', 'PROCESSING')
            ->whereNotNull('h2h_reference_id')
            ->orderBy('id', 'asc');

        if ($staleMinutes > 0) {
            $query->where('updated_at', '<=', now()->subMinutes($staleMinutes));
        }

        $orders = $query->get();

        $report = [
            'checked' => 0,
            'delivered' => 0,
            'refunded' => 0,
            'still_pending' => 0,
            'escalated' => 0,
            'details' => [],
        ];

        foreach ($orders as $order) {
            $report['checked']++;
            $refId = (string) $order->h2h_reference_id;
            $check = $this->digiflazzService->checkOrderStatus($order);
            $providerStatus = $check['status'];

            if ($dryRun) {
                $report['details'][] = [
                    'order_code' => $order->order_code,
                    'h2h_reference_id' => $refId,
                    'provider_status' => $providerStatus,
                    'action' => 'dry_run',
                ];

                continue;
            }

            if ($providerStatus === 'sukses') {
                $this->digiflazzService->handleH2HWebhook(
                    refId: $refId,
                    status: 'sukses',
                    snOrMessage: $check['sn_or_message'],
                    rawPayload: array_merge($check['raw'], ['reconciled_by' => 'H2HReconciliationService']),
                );
                $report['delivered']++;
                $report['details'][] = [
                    'order_code' => $order->order_code,
                    'h2h_reference_id' => $refId,
                    'provider_status' => 'sukses',
                    'action' => 'DELIVERED',
                ];
            } elseif ($providerStatus === 'gagal') {
                $this->digiflazzService->handleH2HWebhook(
                    refId: $refId,
                    status: 'gagal',
                    snOrMessage: $check['sn_or_message'],
                    rawPayload: array_merge($check['raw'], ['reconciled_by' => 'H2HReconciliationService']),
                );
                $report['refunded']++;
                $report['details'][] = [
                    'order_code' => $order->order_code,
                    'h2h_reference_id' => $refId,
                    'provider_status' => 'gagal',
                    'action' => 'REFUNDED',
                ];
            } else {
                $report['still_pending']++;
                $escalated = false;

                if ($order->sla_deadline_at && now()->greaterThan($order->sla_deadline_at->copy()->addMinutes(10))) {
                    $escalated = true;
                    $report['escalated']++;
                    $logData = is_array($order->h2h_response_log) ? $order->h2h_response_log : [];
                    $logData['reconciliation_escalation'] = [
                        'flagged_at' => now()->toIso8601String(),
                        'reason' => 'Provider masih pending melewati SLA + grace period 10 menit.',
                    ];

                    DB::table('orders')->where('id', $order->id)->update([
                        'h2h_response_log' => json_encode($logData),
                        'updated_at' => now(),
                    ]);

                    $adminIds = User::where('role', 'admin')->pluck('id');
                    foreach ($adminIds as $adminId) {
                        $this->notificationService->notify(
                            userId: (int) $adminId,
                            category: 'order',
                            title: "Eskalasi H2H Pending: {$order->order_code}",
                            body: "Ref {$refId} masih berstatus pending melewati SLA + 10 menit. Mohon tindak lanjut manual.",
                            actionUrl: '/admin/finance',
                            refType: 'order',
                            refId: (int) $order->id,
                        );
                    }
                }

                $report['details'][] = [
                    'order_code' => $order->order_code,
                    'h2h_reference_id' => $refId,
                    'provider_status' => 'pending',
                    'action' => $escalated ? 'ESCALATED' : 'PENDING',
                ];
            }
        }

        return $report;
    }
}
