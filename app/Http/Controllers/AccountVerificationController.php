<?php

namespace App\Http\Controllers;

use App\Enums\VerificationChannel;
use App\Exceptions\OtpRateLimitExceededException;
use App\Http\Requests\BindPhoneRequest;
use App\Http\Requests\IssueOtpChallengeRequest;
use App\Http\Requests\VerifyOtpChallengeRequest;
use App\Services\UserVerificationService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class AccountVerificationController extends Controller
{
    public function __construct(
        private readonly UserVerificationService $verificationService,
    ) {}

    public function bindPhone(BindPhoneRequest $request): RedirectResponse|JsonResponse
    {
        $user = $this->requireUser($request);

        try {
            $canonical = $this->verificationService->bindPhoneNumber(
                $user,
                (string) $request->validated('phone'),
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'canonical_phone' => $canonical,
                    'message' => "Nomor telepon {$canonical} berhasil disimpan. Silakan minta dan verifikasi kode OTP.",
                ]);
            }

            return back()->with('success', "Nomor telepon {$canonical} berhasil disimpan. Silakan klik Kirim OTP untuk memverifikasi nomor Anda.");
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function sendOtp(IssueOtpChallengeRequest $request): RedirectResponse|JsonResponse
    {
        $user = $this->requireUser($request);
        $channel = VerificationChannel::from((string) $request->validated('channel'));

        try {
            $plainOtp = $this->verificationService->issueChallenge(
                $user,
                $channel,
                (string) $request->ip(),
            );
            $label = $channel === VerificationChannel::EMAIL ? 'Email' : 'Nomor Telepon';

            if ($request->expectsJson()) {
                $payload = [
                    'ok' => true,
                    'channel' => $channel->value,
                    'message' => "Kode OTP 6-digit verifikasi {$label} telah dikirim (berlaku 10 menit).",
                ];
                if (config('nexatrade.demo_switcher_enabled', false)) {
                    $payload['otp_preview'] = $plainOtp;
                }

                return response()->json($payload);
            }

            $flashMsg = "Kode OTP 6-digit verifikasi {$label} telah dikirim (berlaku 10 menit).";
            if (config('nexatrade.demo_switcher_enabled', false)) {
                $flashMsg .= " [Simulasi OTP: {$plainOtp}]";
            }

            return back()->with('success', $flashMsg);
        } catch (OtpRateLimitExceededException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'retry_after' => $e->retryAfterSeconds,
                    'limit_layer' => $e->limitLayer,
                    'message' => $e->getMessage(),
                ], 429)->header('Retry-After', (string) $e->retryAfterSeconds);
            }

            return back()
                ->with('error', $e->getMessage())
                ->header('Retry-After', (string) $e->retryAfterSeconds);
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function verifyOtp(VerifyOtpChallengeRequest $request): RedirectResponse|JsonResponse
    {
        $user = $this->requireUser($request);
        $channel = VerificationChannel::from((string) $request->validated('channel'));

        try {
            $this->verificationService->verifyChallenge(
                $user,
                $channel,
                (string) $request->validated('code'),
            );

            $label = $channel === VerificationChannel::EMAIL ? 'Email' : 'Nomor Telepon';

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'channel' => $channel->value,
                    'is_fully_verified_for_promo' => $user->isFullyVerifiedForPromo(),
                    'message' => "Verifikasi OTP {$label} berhasil!",
                ]);
            }

            return back()->with('success', "Verifikasi OTP {$label} berhasil! Status keamanan akun Anda telah diperbarui.");
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }
}
