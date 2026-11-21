<?php

namespace App\Services;

use App\Models\DisputeMessage;
use App\Models\Order;
use App\Models\User;

class ChatModerationService
{
    private const REPLACEMENT_TOKEN = '[SENSOR ANTI-BYPASS NEXATRADE]';

    /**
     * @return array{
     *   raw_message: string,
     *   sanitized_message: string,
     *   is_flagged_bypass: bool,
     *   detected_violation_type: ?string,
     *   violations: list<string>
     * }
     */
    public function moderate(string $rawMessage): array
    {
        $sanitized = $rawMessage;
        $violations = [];

        $rules = [
            'INDONESIAN_PHONE_NUMBER' => '/(?<!\d)(?:\+62|62|0)8[1-9](?:[\s\-\.]?\d){6,11}(?!\d)/',
            'EXTERNAL_URL_OR_CONTACT' => '/(?:https?:\/\/\S+|\b(?:wa\.me|t\.me|bit\.ly|linktr\.ee)\/\S+|\b[a-z0-9\-]{2,}\.(?:com|id|net|org|co\.id|xyz|gg|me)\b(?:\/\S*)?)/i',
            'BANK_ACCOUNT_NUMBER' => '/(?<!\d)\d{10,16}(?!\d)/',
            'OFF_PLATFORM_PAYMENT_KEYWORD' => '/\b(?:whatsapp|whatsap|wa\s+aja|chat\s+wa|telegram|tele\s+aja|dana|ovo|gopay|go-pay|shopeepay|shopee\s?pay|transfer\s+langsung|tf\s+langsung|rekber\s+luar|transaksi\s+luar)\b/i',
        ];

        foreach ($rules as $violationType => $pattern) {
            $count = 0;
            $replaced = preg_replace($pattern, self::REPLACEMENT_TOKEN, $sanitized, -1, $count);
            if ($count > 0 && $replaced !== null) {
                $sanitized = $replaced;
                $violations[] = $violationType;
            }
        }

        $isFlagged = count($violations) > 0;
        $violationSummary = $isFlagged ? mb_substr(implode(',', $violations), 0, 80) : null;

        return [
            'raw_message' => $rawMessage,
            'sanitized_message' => $sanitized,
            'is_flagged_bypass' => $isFlagged,
            'detected_violation_type' => $violationSummary,
            'violations' => $violations,
        ];
    }

    public function postOrderMessage(
        Order $order,
        User $sender,
        string $rawMessage,
        ?string $attachmentProofUrl = null,
        ?string $senderRoleOverride = null,
    ): DisputeMessage {
        $role = $senderRoleOverride ?? match (true) {
            $sender->isAdmin() => 'admin',
            (int) $order->seller_id === (int) $sender->id => 'seller',
            default => 'buyer',
        };

        if ($role === 'system' || $role === 'admin') {
            return DisputeMessage::create([
                'order_id' => $order->id,
                'sender_id' => $sender->id,
                'sender_role' => $role,
                'raw_message' => $rawMessage,
                'sanitized_message' => $rawMessage,
                'attachment_proof_url' => $attachmentProofUrl,
                'is_flagged_bypass' => false,
                'detected_violation_type' => null,
                'created_at' => now(),
            ]);
        }

        $result = $this->moderate($rawMessage);

        return DisputeMessage::create([
            'order_id' => $order->id,
            'sender_id' => $sender->id,
            'sender_role' => $role,
            'raw_message' => $result['raw_message'],
            'sanitized_message' => $result['sanitized_message'],
            'attachment_proof_url' => $attachmentProofUrl,
            'is_flagged_bypass' => $result['is_flagged_bypass'],
            'detected_violation_type' => $result['detected_violation_type'],
            'created_at' => now(),
        ]);
    }
}
