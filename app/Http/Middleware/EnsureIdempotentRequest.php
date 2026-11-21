<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotentRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $rawKey = (string) ($request->header('Idempotency-Key') ?: $request->input('_idempotency_key', ''));
        $idempotencyKey = mb_substr(trim($rawKey), 0, 100);
        $user = $request->user();

        // Backward-compatible: if caller does not send an idempotency key (e.g. legacy tests), pass through cleanly.
        if ($idempotencyKey === '' || ! $user) {
            return $next($request);
        }

        $payload = $request->except(['_token', '_idempotency_key']);
        ksort($payload);
        $payloadHash = hash('sha256', json_encode($payload) ?: '');

        // Clean up any expired key with the same (user_id, idempotency_key) before inserting.
        DB::table('idempotency_keys')
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->where('expires_at', '<=', now())
            ->delete();

        $record = null;
        try {
            $record = IdempotencyKey::create([
                'user_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'http_method' => strtoupper($request->method()),
                'route_path' => mb_substr($request->path(), 0, 160),
                'payload_hash' => $payloadHash,
                'status' => 'processing',
                'expires_at' => now()->addHours(24),
            ]);
        } catch (UniqueConstraintViolationException|QueryException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            if (! ($e instanceof UniqueConstraintViolationException) && $sqlState !== '23000') {
                throw $e;
            }

            $existing = IdempotencyKey::where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $existing) {
                throw $e;
            }

            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                $message = 'Idempotency key dipakai ulang dengan payload berbeda.';
                if ($request->expectsJson() || $request->hasHeader('Idempotency-Key')) {
                    return response()->json(['ok' => false, 'message' => $message], 422);
                }

                return response($message, 422);
            }

            if ($existing->status === 'processing') {
                $message = 'Permintaan sebelumnya masih diproses, mohon tunggu beberapa detik.';
                if ($request->expectsJson() || $request->hasHeader('Idempotency-Key')) {
                    return response()->json(['ok' => false, 'message' => $message], 409);
                }

                return response($message, 409);
            }

            if ($existing->status === 'completed') {
                $snapshot = is_array($existing->response_snapshot) ? $existing->response_snapshot : [];

                if ($request->expectsJson()) {
                    $status = (int) ($existing->http_status ?: 200);
                    $body = $snapshot['json'] ?? ['ok' => true, 'replayed' => true, 'message' => 'Transaksi ini sudah diproses sebelumnya.'];

                    return response()->json($body, $status)
                        ->header('X-Idempotency-Replayed', 'true')
                        ->header('X-Idempotency-Key', $idempotencyKey);
                }

                $targetUrl = (string) ($snapshot['redirect_to'] ?? url()->previous('/'));

                return redirect($targetUrl)
                    ->with('warning', 'Transaksi ini sudah diproses sebelumnya — duplikasi dicegah otomatis.')
                    ->header('X-Idempotency-Replayed', 'true')
                    ->header('X-Idempotency-Key', $idempotencyKey);
            }

            // Previous attempt failed (5xx / exception) -> allow clean retry by reclaiming the record.
            $existing->update([
                'status' => 'processing',
                'payload_hash' => $payloadHash,
                'http_status' => null,
                'response_snapshot' => null,
                'expires_at' => now()->addHours(24),
            ]);
            $record = $existing;
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            if ($record) {
                $record->delete();
            }
            throw $e;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 500) {
            $record->update([
                'status' => 'failed',
                'http_status' => $statusCode,
            ]);

            return $response;
        }

        $snapshot = [
            'status_code' => $statusCode,
        ];

        if ($response->isRedirection()) {
            $snapshot['redirect_to'] = $response->headers->get('Location');
        } elseif (str_contains((string) $response->headers->get('Content-Type'), 'application/json')) {
            $decoded = json_decode((string) $response->getContent(), true);
            if (is_array($decoded)) {
                $snapshot['json'] = $decoded;
            }
        }

        $record->update([
            'status' => 'completed',
            'http_status' => $statusCode,
            'response_snapshot' => $snapshot,
        ]);

        $response->headers->set('X-Idempotency-Key', $idempotencyKey);

        return $response;
    }
}
