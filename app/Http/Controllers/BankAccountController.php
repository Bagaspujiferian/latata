<?php

namespace App\Http\Controllers;

use App\Models\UserBankAccount;
use App\Models\WithdrawalRequest;
use App\Services\BankAccountService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BankAccountController extends Controller
{
    public function __construct(
        private readonly BankAccountService $bankAccountService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user()->load('sellerProfile');

        $accounts = UserBankAccount::where('user_id', $user->id)
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get();

        $pendingBankAccountIds = WithdrawalRequest::where('user_id', $user->id)
            ->where('status', 'PENDING')
            ->whereNotNull('user_bank_account_id')
            ->pluck('user_bank_account_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $supportedBanks = UserBankAccount::SUPPORTED_BANKS;

        return view('wallet.bank-accounts', compact(
            'user',
            'accounts',
            'pendingBankAccountIds',
            'supportedBanks',
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bank_code' => ['required', 'string', Rule::in(array_keys(UserBankAccount::SUPPORTED_BANKS))],
            'account_number' => ['required', 'string', 'min:6', 'max:30'],
            'account_holder_name' => ['required', 'string', 'min:3', 'max:100'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        try {
            $account = $this->bankAccountService->addBankAccount($request->user()->id, [
                'bank_code' => $validated['bank_code'],
                'bank_name' => UserBankAccount::SUPPORTED_BANKS[$validated['bank_code']] ?? $validated['bank_code'],
                'account_number' => $validated['account_number'],
                'account_holder_name' => $validated['account_holder_name'],
                'is_primary' => $request->boolean('is_primary'),
            ]);

            return back()->with('success', "Rekening {$account->bank_name} ({$account->account_number}) berhasil disimpan.");
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function setPrimary(Request $request, int $id): RedirectResponse
    {
        try {
            $account = $this->bankAccountService->setPrimary($request->user()->id, $id);

            return back()->with('success', "Rekening {$account->bank_name} ({$account->account_number}) kini menjadi rekening utama.");
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        try {
            $this->bankAccountService->deleteBankAccount($request->user()->id, $id);

            return back()->with('success', 'Rekening berhasil dihapus dari daftar tersimpan.');
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
