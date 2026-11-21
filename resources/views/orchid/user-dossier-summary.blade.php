@php
    $d = $dossier ?? [];
@endphp
<div class="bg-white rounded shadow-sm p-4 mb-3 border-start border-4 border-primary">
    <h4 class="mb-1">{{ __('Member janamkundli') }}</h4>
    <p class="text-muted mb-3">{{ __('Paste a wallet on User Full Data search. This page is the A–Z read-only file for that member.') }}</p>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <tbody>
                <tr><th style="width:38%">{{ __('Registered') }}</th><td>{{ $d['registered_at'] ?? '—' }}</td></tr>
                <tr><th>{{ __('Member ID activated') }}</th><td>{{ $d['activated_at'] ?? '—' }}</td></tr>
                <tr><th>{{ __('Participation activated') }}</th><td>{{ $d['participation_at'] ?? '—' }}</td></tr>
                <tr><th>{{ __('Last platform claim') }}</th><td>{{ $d['last_income_claim_at'] ?? '—' }}</td></tr>
                <tr><th>{{ __('Wallet') }}</th><td><code>{{ ($d['wallet_address'] ?? '') !== '' ? $d['wallet_address'] : '—' }}</code></td></tr>
                <tr><th>{{ __('Laravel stakes') }}</th>
                    <td>{{ $d['laravel_stake_count'] ?? 0 }} · {{ __('invested') }} ${{ $d['laravel_invested_usd'] ?? '0.00' }} · {{ __('active') }} ${{ $d['laravel_active_usd'] ?? '0.00' }}
                        <br><small>{{ __('First') }}: {{ $d['laravel_first_stake_at'] ?? '—' }} · {{ __('Last') }}: {{ $d['laravel_last_stake_at'] ?? '—' }}</small>
                    </td></tr>
                <tr><th>{{ __('On-chain Engine stakes') }}</th>
                    <td>{{ $d['engine_stake_count'] ?? 0 }} · {{ __('principal') }} ${{ $d['engine_principal_usd'] ?? '0.00' }} · {{ __('still open') }} ${{ $d['engine_active_usd'] ?? '0.00' }} · {{ $d['engine_staked_race'] ?? '0' }} RACE</td></tr>
                <tr><th>{{ __('ICO buys') }}</th>
                    <td>{{ $d['ico_buy_count'] ?? 0 }} ({{ __('held') }} {{ $d['ico_held_count'] ?? 0 }} / {{ __('staked') }} {{ $d['ico_staked_count'] ?? 0 }}) · ${{ $d['ico_usdt_usd'] ?? '0.00' }} USDT · {{ $d['ico_race'] ?? '0' }} RACE</td></tr>
                <tr><th>{{ __('Deposits') }}</th><td>{{ $d['deposit_count'] ?? 0 }} · ${{ $d['deposit_usd'] ?? '0.00' }}</td></tr>
                <tr><th>{{ __('Petrol (withdrawals to wallet)') }}</th>
                    <td>{{ $d['petrol_count'] ?? 0 }} {{ __('requests') }} · {{ $d['petrol_completed_count'] ?? 0 }} {{ __('completed') }}
                        <br>{{ __('Gross') }} ${{ $d['petrol_gross_usd'] ?? '0.00' }} · {{ __('Net to wallet') }} ${{ $d['petrol_net_usd'] ?? '0.00' }}
                    </td></tr>
                <tr><th>{{ __('10% team fee on petrol') }}</th>
                    <td>${{ $d['team_10_percent_usd'] ?? '0.00' }} — {{ $d['team_10_percent_split'] ?? '—' }}</td></tr>
                <tr><th>{{ __('Admin fee on petrol') }}</th><td>${{ $d['admin_fee_usd'] ?? '0.00' }}</td></tr>
                <tr><th>{{ __('Income received') }}</th>
                    <td>{{ $d['income_count'] ?? 0 }} {{ __('times') }} · ${{ $d['income_usd'] ?? '0.00' }}</td></tr>
                <tr><th>{{ __('Virtual income wallet') }}</th><td>${{ $d['virtual_income_usd'] ?? '0.00' }}</td></tr>
                <tr><th>{{ __('Ledger wallet balance') }}</th><td>${{ $d['wallet_balance_usd'] ?? '0.00' }}</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-3">{{ __('Income by type') }}</h5>
    @if (empty($d['income_by_type']))
        <p class="text-muted mb-0">{{ __('No income ledger rows.') }}</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ __('Type') }}</th><th>{{ __('Times') }}</th><th>{{ __('USD') }}</th></tr></thead>
                <tbody>
                    @foreach ($d['income_by_type'] as $row)
                        <tr>
                            <td>{{ $row['label'] }} <code>{{ $row['type'] }}</code></td>
                            <td>{{ $row['count'] }}</td>
                            <td>${{ $row['usd'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-3">{{ __('Income through which users') }}</h5>
    @if (empty($d['income_from']))
        <p class="text-muted mb-0">{{ __('No from_user_id on this member’s income rows.') }}</p>
    @else
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>{{ __('From member') }}</th><th>{{ __('Times') }}</th><th>{{ __('USD') }}</th></tr></thead>
                <tbody>
                    @foreach ($d['income_from'] as $row)
                        <tr>
                            <td>{{ $row['member'] }}</td>
                            <td>{{ $row['count'] }}</td>
                            <td>${{ $row['usd'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
