<?php

namespace Tests\Feature;

use App\Models\IncomeWalletTransaction;
use App\Models\User;
use App\Models\VirtualStakeCompound;
use App\Services\Income\VirtualIncomeWalletService;
use App\Services\Income\WalletBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FailedVirtualCompoundRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_refunds_overlay_compounds_that_never_minted(): void
    {
        $user = User::factory()->create();
        $wallet = app(VirtualIncomeWalletService::class);
        $wallet->credit($user, IncomeWalletTransaction::TYPE_INCOME_CLAIM, '10.0000', 'seed', 'seed-'.$user->id);
        $debit = $wallet->debit(
            $user,
            IncomeWalletTransaction::TYPE_COMPOUND,
            '10.0000',
            'engine_stake:0',
            'overlay-'.$user->id,
            [
                'phase' => 'compounded_to_stake',
                'on_chain_required' => false,
                'stake_index' => 0,
            ],
        );
        VirtualStakeCompound::query()->create([
            'user_id' => $user->id,
            'stake_index' => 0,
            'usdt_amount' => '10.0000',
            'race_amount' => '0',
            'race_price_usdt' => '0',
            'income_wallet_transaction_id' => $debit->id,
        ]);

        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user->fresh()), '0', 4));

        $this->artisan('income:refund-failed-virtual-compounds')
            ->assertSuccessful();

        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user->fresh()), '10', 4));
        $this->assertSame(0, VirtualStakeCompound::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', IncomeWalletTransaction::TYPE_COMPOUND_REFUND)
            ->count());

        $this->artisan('income:refund-failed-virtual-compounds')->assertSuccessful();
        $this->assertSame(1, IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', IncomeWalletTransaction::TYPE_COMPOUND_REFUND)
            ->count());
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user->fresh()), '10', 4));
    }

    public function test_does_not_refund_minted_compound(): void
    {
        $user = User::factory()->create();
        $wallet = app(VirtualIncomeWalletService::class);
        $wallet->credit($user, IncomeWalletTransaction::TYPE_INCOME_CLAIM, '5.0000', 'seed', 'seed-mint-'.$user->id);
        $wallet->debit(
            $user,
            IncomeWalletTransaction::TYPE_COMPOUND,
            '5.0000',
            'engine_stake:0',
            'minted-'.$user->id,
            [
                'phase' => 'minted_awaiting_stake',
                'on_chain_required' => true,
                'mint_tx_hash' => '0x'.str_repeat('c', 64),
            ],
        );

        $this->artisan('income:refund-failed-virtual-compounds')->assertSuccessful();

        $this->assertSame(0, IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', IncomeWalletTransaction::TYPE_COMPOUND_REFUND)
            ->count());
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user->fresh()), '0', 4));
    }

    public function test_does_not_refund_virtual_into_last_stake_compound(): void
    {
        $user = User::factory()->create();
        $wallet = app(VirtualIncomeWalletService::class);
        $wallet->credit($user, IncomeWalletTransaction::TYPE_INCOME_CLAIM, '8.0000', 'seed', 'seed-virt-'.$user->id);
        $wallet->debit(
            $user,
            IncomeWalletTransaction::TYPE_COMPOUND,
            '8.0000',
            'engine_stake:0',
            'virt-'.$user->id,
            [
                'phase' => 'virtual_into_last_stake',
                'on_chain_required' => false,
                'user_wallet_credited' => false,
            ],
        );

        $this->artisan('income:refund-failed-virtual-compounds')->assertSuccessful();

        $this->assertSame(0, IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', IncomeWalletTransaction::TYPE_COMPOUND_REFUND)
            ->count());
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user->fresh()), '0', 4));
    }
}
