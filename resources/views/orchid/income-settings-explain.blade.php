@php
    $tz = config('income.claim.timezone', 'Asia/Kolkata');
    $winStart = config('income.claim.window_start', '07:00');
    $winEnd = config('income.claim.window_end', '12:00');
    $communityAt = config('income.claim.community_release_at', '12:00');
    $roiAt = config('income.auto_claim.pay_roi_at', '00:15');
    $refPct = config('reward_plan.community_referral_percent', []);
    $qualify = (float) config('reward_plan.participation_tiers.qualifying_amount_usd', 50);
    $skipPct = config('reward_plan.community_leadership.skip_level_percent', 5);
    $roiSharePct = config('reward_plan.roi_sharing.direct_roi_of_roi_percent', 10);
    $teamPct = config('reward_plan.community_team_rewards.withdrawal_fee_percent', 10);
@endphp

<div class="bg-white rounded shadow-sm p-4 mb-3 border-start border-4 border-primary">
    <h4 class="mb-2">{{ __('How income works — read this first') }}</h4>
    <p class="mb-2">
        {{ __('Every income is one row in the Ledger (ledger_entries). The "Type" column tells you where it came from. Money is only withdrawable after it reaches the Virtual Income Wallet.') }}
    </p>
    <ol class="mb-0">
        <li><strong>{{ __('Stake') }}</strong> — {{ __('member stakes USDT (min $1). Only a stake of $:q or more activates the member for community income.', ['q' => number_format($qualify)]) }}</li>
        <li><strong>{{ __('ROI accrues') }}</strong> — {{ __('every day at :t (:tz) the system adds the daily ROI to the stake (accrued reward). This is NOT in the wallet yet.', ['t' => $roiAt, 'tz' => $tz]) }}</li>
        <li><strong>{{ __('Member claims manually') }}</strong> — {{ __('only between :s and :e (:tz), once per day. Claim moves accrued ROI into the Virtual Income Wallet. There is NO auto-claim cron.', ['s' => $winStart, 'e' => $winEnd, 'tz' => $tz]) }}</li>
        <li><strong>{{ __('Community income') }}</strong> — {{ __('Referral income is credited straight to the wallet when the downline stakes. ROI Sharing is credited when the direct claims their ROI. Community Leadership is released once a day at :t (:tz).', ['t' => $communityAt, 'tz' => $tz]) }}</li>
        <li><strong>{{ __('Withdraw') }}</strong> — {{ __('member withdraws from the Virtual Income Wallet. Fee = :t% Team Reward (goes to the upline L1–L10) + Admin Fee ($1 below $100, 1% at $100+). Coins are minted to the member wallet.', ['t' => $teamPct]) }}</li>
    </ol>
</div>

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-3">{{ __('Which income is which? (ledger Type → meaning)') }}</h5>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>{{ __('Ledger Type') }}</th>
                    <th>{{ __('Income name') }}</th>
                    <th>{{ __('Where it comes from') }}</th>
                    <th>{{ __('Who gets it / how much') }}</th>
                    <th>{{ __('When it is created') }}</th>
                    <th>{{ __('Effect on wallet') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>roi_accrual</code></td>
                    <td>{{ __('Daily ROI (accrued)') }}</td>
                    <td>{{ __('Member’s OWN stake. Reference = that stake (investment id).') }}</td>
                    <td>{{ __('Stake amount × tier daily rate: Flexible 0.35% · 180d 0.50% · 365d 0.70% · 730d 0.90% · 1095d 1.00%.') }}</td>
                    <td>{{ __('Daily job at :t (:tz).', ['t' => $roiAt, 'tz' => $tz]) }}</td>
                    <td><strong>{{ __('None.') }}</strong> {{ __('Audit row only. Not withdrawable until claimed.') }}</td>
                </tr>
                <tr>
                    <td><code>income_claim</code></td>
                    <td>{{ __('ROI Claim') }}</td>
                    <td>{{ __('Member pressed Claim. One row per stake claimed (a member with 3 stakes gets 3 rows, same minute).') }}</td>
                    <td>{{ __('Equals the accrued ROI of that stake. No admin fee.') }}</td>
                    <td>{{ __('Only when the member claims, :s–:e (:tz).', ['s' => $winStart, 'e' => $winEnd, 'tz' => $tz]) }}</td>
                    <td><strong>{{ __('+ wallet') }}</strong> ({{ __('Virtual wallet type: income_claim') }})</td>
                </tr>
                <tr>
                    <td><code>community_referral</code></td>
                    <td>{{ __('Community Referral (one-time)') }}</td>
                    <td>{{ __('A member in your upline-chain (up to 10 levels above) staked $:q or more. Meta shows level, percent and from_user_id (who staked).', ['q' => number_format($qualify)]) }}</td>
                    <td>
                        {{ __('% of the stake, once:') }}
                        @foreach ($refPct as $lvl => $p) L{{ $lvl }} {{ $p }}%@if (! $loop->last) · @endif @endforeach
                        <br><small class="text-muted">{{ __('Receiver must be activated and not blocked.') }}</small>
                    </td>
                    <td>{{ __('Instantly when the downline stake is created.') }}</td>
                    <td><strong>{{ __('+ wallet') }}</strong> ({{ __('Virtual wallet type: referral_income') }})</td>
                </tr>
                <tr>
                    <td><code>affiliate_network_roi</code></td>
                    <td>{{ __('ROI Sharing') }}</td>
                    <td>{{ __('Daily ROI of your DIRECT (level 1) member. Meta from_user_id = the direct.') }}</td>
                    <td>{{ __(':p% of the direct’s daily ROI.', ['p' => $roiSharePct]) }}</td>
                    <td>{{ __('Same daily job as ROI (:t), right after the direct’s ROI accrues.', ['t' => $roiAt]) }}</td>
                    <td><strong>{{ __('+ wallet') }}</strong> ({{ __('Virtual wallet type: level_income') }})</td>
                </tr>
                <tr>
                    <td><code>community_leadership</code></td>
                    <td>{{ __('Community Leadership — rank / compression') }}</td>
                    <td>{{ __('Team daily ROI of your whole downline (unlimited depth). Meta shows rank, self_hold, team_volume, qualified_directs and each leg.') }}</td>
                    <td>{{ __('Team daily ROI × your rank % (Rank 1 = 10% … Rank 11 = 110%). Rank needs self hold + team volume + direct members (see table below).') }}</td>
                    <td>{{ __('Once per day at :t (:tz).', ['t' => $communityAt, 'tz' => $tz]) }}</td>
                    <td><strong>{{ __('+ wallet') }}</strong> ({{ __('Virtual wallet type: level_income') }})</td>
                </tr>
                <tr>
                    <td><code>community_leadership_skip</code></td>
                    <td>{{ __('Community Leadership — same-rank / skip-level') }}</td>
                    <td>{{ __('A leg where a downline has the SAME rank as you (same-rank) or 2+ same-rank members are in the lineage (skip-level).') }}</td>
                    <td>{{ __(':p% of that leg’s daily ROI. Same-rank from Rank 2 upward is blocked ($0).', ['p' => $skipPct]) }}</td>
                    <td>{{ __('Once per day at :t (:tz), with Leadership.', ['t' => $communityAt, 'tz' => $tz]) }}</td>
                    <td class="text-danger"><strong>{{ __('Counted in the legacy ledger balance but NOT mirrored to the Virtual wallet yet — check before paying out.') }}</strong></td>
                </tr>
                <tr>
                    <td><code>community_leadership_rank11_monthly</code></td>
                    <td>{{ __('Rank 11 monthly royalty') }}</td>
                    <td>{{ __('Rank 11 member with a direct who is also Rank 11, both holding $10,000,000 team volume all month.') }}</td>
                    <td>{{ __('Up to $5,000 per month.') }}</td>
                    <td>{{ __('Day 1 of every month (previous month).') }}</td>
                    <td><strong>{{ __('+ wallet') }}</strong></td>
                </tr>
                <tr>
                    <td><code>community_team_reward</code></td>
                    <td>{{ __('Team Reward (from a downline’s withdrawal)') }}</td>
                    <td>{{ __('When a member in your team withdraws, :t% of the gross is split to the 10 uplines.', ['t' => $teamPct]) }}</td>
                    <td>
                        {{ __('Share of that fee pool:') }}
                        @foreach (config('reward_plan.community_team_rewards.level_percent', []) as $lvl => $p) L{{ $lvl }} {{ $p }}%@if (! $loop->last) · @endif @endforeach
                        <br><small class="text-muted">{{ __('Level N is paid only if the upline has at least N activated direct members; otherwise that share goes to the admin.') }}</small>
                    </td>
                    <td>{{ __('At the moment of the downline’s withdrawal.') }}</td>
                    <td><strong>{{ __('+ wallet') }}</strong> ({{ __('Virtual wallet type: team_income') }})</td>
                </tr>
                <tr>
                    <td><code>withdrawal_admin_fee</code></td>
                    <td>{{ __('Company / admin income') }}</td>
                    <td>{{ __('Admin Fee of each withdrawal, and any Team Reward share nobody qualified for (meta source = team_reward_unallocated).') }}</td>
                    <td>{{ __('$1 if gross < $100, else 1% of gross.') }}</td>
                    <td>{{ __('At withdrawal.') }}</td>
                    <td>{{ __('Admin account only — not a member income.') }}</td>
                </tr>
                <tr>
                    <td><code>wallet_withdrawal</code></td>
                    <td>{{ __('Withdrawal (member)') }}</td>
                    <td>{{ __('Member withdrew from the Virtual wallet. Reference = withdrawal id.') }}</td>
                    <td>{{ __('Gross amount (negative). Net coins = gross − team reward − admin fee.') }}</td>
                    <td>{{ __('When the member requests / completes the withdrawal.') }}</td>
                    <td><strong>{{ __('− wallet') }}</strong> ({{ __('Virtual wallet type: withdrawal') }})</td>
                </tr>
                <tr>
                    <td><code>compound_reinvest</code></td>
                    <td>{{ __('Compound') }}</td>
                    <td>{{ __('Member moved earned ROI back into the same stake (principal grows).') }}</td>
                    <td>{{ __('Amount compounded (negative).') }}</td>
                    <td>{{ __('When the member presses Compound.') }}</td>
                    <td><strong>{{ __('− wallet') }}</strong> ({{ __('Virtual wallet type: compound') }})</td>
                </tr>
                <tr>
                    <td><code>stake_unlock_emi</code> / <code>stake_unlock_admin_fee</code></td>
                    <td>{{ __('Stake unlock') }}</td>
                    <td>{{ __('Unlocking principal after a fixed lock: 10% admin fee, then 3 × 30% EMI to the member.') }}</td>
                    <td>{{ __('See "Staking reward rates" below.') }}</td>
                    <td>{{ __('Daily job 02:30 / when unlocked.') }}</td>
                    <td>{{ __('EMI = + wallet · admin fee = admin only.') }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-3">{{ __('Quick checks when a member says "my income is wrong"') }}</h5>
    <ul class="mb-0">
        <li>{{ __('Income missing after staking? Check the stake exists in Investments (a stake only in the blockchain tables does not create ROI / referral income).') }}</li>
        <li>{{ __('No referral income for an upline? The upline must be activated (stake ≥ $:q) at the moment the downline staked. Later activation does not back-pay.', ['q' => number_format($qualify)]) }}</li>
        <li>{{ __('Balance differs between pages? The Virtual Income Wallet (credits − debits of income_wallet_transactions) is the real withdrawable balance; the legacy wallet balance can differ.') }}</li>
        <li>{{ __('Claim button disabled? It is only open :s–:e (:tz) and once per day.', ['s' => $winStart, 'e' => $winEnd, 'tz' => $tz]) }}</li>
        <li>{{ __('Team Reward going to admin? The withdrawing member’s upline is not activated or has too few activated directs for that level.') }}</li>
    </ul>
</div>
