import PrimaryButton from '@/Components/PrimaryButton';
import PanelCard from '@/Components/PanelCard';
import TextInput from '@/Components/TextInput';
import MemberAlert from '@/Components/Member/MemberAlert';
import MemberPageHero from '@/Components/Member/MemberPageHero';
import MemberPageShell from '@/Components/Member/MemberPageShell';
import MemberTableScroll from '@/Components/Member/MemberTableScroll';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useWalletNetwork } from '@/hooks/useWalletNetwork';
import {
    configureWeb3Network,
    sendUsdtTransfer,
    waitForConfirmations,
} from '@/lib/web3Deposit';
import { raceEquivLabel } from '@/lib/raceQuote';
import { walletRequest } from '@/lib/web3Wallet';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const USDT_NETWORK = 'BEP20 (BNB Smart Chain)';

function formatUsdtBalance(n) {
    const s = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number.isFinite(n) ? n : 0);
    return `${s} USDT`;
}

function formatRowDate(createdAt) {
    if (!createdAt) {
        return '—';
    }
    return new Date(createdAt).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function statusBadgeClass(status) {
    switch (status) {
        case 'completed':
            return 'bg-emerald-100 text-emerald-800';
        case 'rejected':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-amber-100 text-amber-900';
    }
}

function formatCountdown(ms) {
    if (ms <= 0) {
        return '00:00:00';
    }
    const totalSec = Math.floor(ms / 1000);
    const h = Math.floor(totalSec / 3600);
    const m = Math.floor((totalSec % 3600) / 60);
    const s = totalSec % 60;
    return [h, m, s].map((n) => String(n).padStart(2, '0')).join(':');
}

function useReverseCountdown(isoTarget) {
    const [remainingMs, setRemainingMs] = useState(() => {
        if (!isoTarget) return 0;
        return Math.max(0, new Date(isoTarget).getTime() - Date.now());
    });

    useEffect(() => {
        if (!isoTarget) {
            setRemainingMs(0);
            return undefined;
        }
        const tick = () => {
            setRemainingMs(Math.max(0, new Date(isoTarget).getTime() - Date.now()));
        };
        tick();
        const id = window.setInterval(tick, 1000);
        return () => window.clearInterval(id);
    }, [isoTarget]);

    return remainingMs;
}

function WithdrawalRowMobile({ row }) {
    return (
        <div className="px-4 py-3.5">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        {formatRowDate(row.created_at)}
                    </p>
                    <p className="mt-1 font-mono text-sm font-bold text-fintech-ink">#{row.id}</p>
                    <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-fintech-muted">
                        <span>
                            Gross: <strong className="text-fintech-ink">{formatUsdtBalance(row.amount_usd)}</strong>
                        </span>
                        <span>
                            Net: <strong className="text-fintech-ink">{formatUsdtBalance(row.net_usd)}</strong>
                        </span>
                        {row.race_amount ? (
                            <span>
                                RACE:{' '}
                                <strong className="text-fintech-ink">
                                    {Number(row.race_amount).toLocaleString(undefined, {
                                        maximumFractionDigits: 4,
                                    })}
                                </strong>
                            </span>
                        ) : null}
                    </div>
                </div>
                <span
                    className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide ${statusBadgeClass(row.status)}`}
                >
                    {row.status_label ?? row.status}
                </span>
            </div>
        </div>
    );
}

export default function Withdrawal({
    balance_usd = '0.00',
    income_buckets = {
        total_usd: '0.00',
        daily_claim_usd: '0.00',
        other_usd: '0.00',
        daily_claim_earned_usd: '0.00',
        other_earned_usd: '0.00',
    },
    stake_auto_claim_live = false,
    income_policy = {
        accrued_reward_usd: '0.0000',
        virtual_income_usd: '0.00',
        can_claim: false,
        next_claim_at: null,
    },
    wallet_address = null,
    withdrawal_fee = {
        min_amount_usd: '1.00',
        percent: '10.00',
        team_reward_percent: '10.00',
        mode: 'dual',
        destination: 'community_team_rewards',
        flat_fee_usd: '1.00',
        flat_fee_max_gross_usd: '99.99',
        percent_from_usd: '100.00',
        admin_fee_percent: '2.00',
    },
    admin_wallet = {
        address: '',
        network: 'BEP20 (BNB Smart Chain)',
        usdt_contract: '',
        min_confirmations: 12,
        chain_id: 56,
    },
    withdrawals_disabled = false,
    withdrawal_lock_message = null,
    withdrawals_locked_until = null,
    rate_limit = {
        enabled: true,
        max_per_window: 2,
        window_hours: 24,
        used_in_window: 0,
        remaining: 2,
        can_request: true,
        next_available_at: null,
        slot_frees_at: [],
    },
    race_mint_on_withdraw = false,
    race_price_usd = null,
    withdrawals = [],
}) {
    const { auth, flash, blockchain } = usePage().props;
    const raceMintOnWithdraw = Boolean(race_mint_on_withdraw);
    const raceOnlyIncome = Boolean(blockchain?.blockchain_only) && !raceMintOnWithdraw;
    const withdrawalAddressLocked = Boolean((wallet_address ?? auth?.user?.wallet_address ?? '').trim());
    const totalBalance = Number(income_buckets?.total_usd ?? balance_usd ?? 0);
    const dailyClaimBal = Number(income_buckets?.daily_claim_usd ?? 0);
    const otherBal = Number(income_buckets?.other_usd ?? 0);
    const dailyClaimEarned = Number(income_buckets?.daily_claim_earned_usd ?? 0);
    const percent = Number(withdrawal_fee.team_reward_percent ?? withdrawal_fee.percent) || 10;
    const adminFlat = Number(withdrawal_fee.flat_fee_usd) || 1;
    const adminPercentFrom = Number(withdrawal_fee.percent_from_usd) || 100;
    const adminPercent = Number(withdrawal_fee.admin_fee_percent) || 2;
    const adminAddress = (admin_wallet?.address || '').trim();
    const usdtContract = (admin_wallet?.usdt_contract || blockchain?.contracts?.usdt || '').trim();
    const minConfirmations = Number(admin_wallet?.min_confirmations ?? 12);
    const feeChainId = Number(admin_wallet?.chain_id ?? blockchain?.chain_id ?? 56);

    const { chainOk, switchNetwork } = useWalletNetwork({ expectedChainId: feeChainId });

    const rateLimited = Boolean(rate_limit?.enabled && !rate_limit?.can_request);
    const countdownMs = useReverseCountdown(rateLimited ? rate_limit?.next_available_at : null);
    const formBlocked = withdrawals_disabled || rateLimited;

    const { data, setData, post, processing, errors, reset, transform } = useForm({
        amount_usd: '',
        destination_address: wallet_address ?? '',
        income_bucket: 'other',
        admin_fee_tx_hash: '',
    });

    const [feeStep, setFeeStep] = useState(null);
    const [feeError, setFeeError] = useState(null);

    const selectedBucketBal =
        data.income_bucket === 'daily_claim' ? dailyClaimBal : otherBal;

    const gross = Number(data.amount_usd) || 0;
    const teamReward = gross > 0 ? (gross * percent) / 100 : 0;
    // Same 2-decimal truncation as server (RewardPlan::calculatePayoutAdminFee).
    const adminFeeRaw =
        gross > 0 ? (gross < adminPercentFrom ? adminFlat : (gross * adminPercent) / 100) : 0;
    const adminFee = Math.floor(adminFeeRaw * 100 + 1e-6) / 100;
    // Fee-first (RACE mint): Admin Fee is paid in USDT separately — income net = gross − 10% only.
    const net = gross > 0 ? (raceMintOnWithdraw ? gross - teamReward : gross - teamReward - adminFee) : 0;

    useEffect(() => {
        configureWeb3Network({
            chainId: feeChainId,
            usdtContract: usdtContract || undefined,
            rpcUrl: blockchain?.rpc_url || undefined,
        });
    }, [feeChainId, usdtContract, blockchain?.rpc_url]);

    const submit = async (e) => {
        e.preventDefault();
        if (formBlocked) {
            return;
        }
        setFeeError(null);

        // Race mint path: pay Admin Fee USDT on-chain first, then server mints RACE.
        if (raceMintOnWithdraw && adminFee > 0) {
            const from = String(data.destination_address || auth?.user?.wallet_address || '')
                .trim()
                .toLowerCase();
            if (!/^0x[a-f0-9]{40}$/.test(from)) {
                setFeeError('Connect / enter your BEP20 wallet address first.');
                return;
            }
            if (!adminAddress || !usdtContract) {
                setFeeError('Admin treasury / USDT contract is not configured. Contact support.');
                return;
            }

            // Wallet must sign with the same account that holds the USDT (withdraw address).
            try {
                const accounts = await walletRequest({ method: 'eth_accounts' });
                const active = String(accounts?.[0] || '').trim().toLowerCase();
                if (active && active !== from) {
                    setFeeError(
                        `Wallet ka active account ${active.slice(0, 6)}…${active.slice(-4)} hai, lekin withdraw address ${from.slice(0, 6)}…${from.slice(-4)} hai. Wallet mein wahi account select karo jisme USDT hai, phir dobara try karo.`,
                    );
                    return;
                }
            } catch {
                // If the wallet can't report accounts, continue; sendUsdtTransfer will surface any error.
            }

            try {
                setFeeStep('network');
                if (!chainOk) {
                    await switchNetwork();
                }

                setFeeStep('sending');
                const txHash = await sendUsdtTransfer({
                    from,
                    to: adminAddress,
                    usdtContract,
                    amountUsd: adminFee.toFixed(2),
                });

                setFeeStep('confirming');
                await waitForConfirmations(txHash, { minConfirmations });

                setFeeStep('minting');
                transform((form) => ({ ...form, admin_fee_tx_hash: txHash }));
                post(route('withdrawals.store'), {
                    preserveScroll: true,
                    onSuccess: () => {
                        reset('amount_usd', 'admin_fee_tx_hash');
                        setFeeStep(null);
                    },
                    onError: () => setFeeStep(null),
                    onFinish: () => {
                        transform((form) => form);
                        setFeeStep(null);
                    },
                });
            } catch (err) {
                setFeeStep(null);
                setFeeError(err?.message || 'Admin Fee USDT payment failed.');
            }
            return;
        }

        post(route('withdrawals.store'), {
            preserveScroll: true,
            onSuccess: () => reset('amount_usd', 'admin_fee_tx_hash'),
        });
    };

    const feeStepLabel =
        feeStep === 'network'
            ? 'Switching to BNB Smart Chain…'
            : feeStep === 'sending'
              ? `Confirm Admin Fee ${formatUsdtBalance(adminFee)} in your wallet…`
              : feeStep === 'confirming'
                ? `Waiting for ${minConfirmations} confirmations…`
                : feeStep === 'minting'
                  ? 'Admin Fee received — minting RACE…'
                  : null;

    return (
        <AuthenticatedLayout
            pageTitle="Withdrawal"
            memberSurface="race"
            showMobileFintechNav
            mobileFintechPageTitle="Withdrawal"
        >
            <Head title="Withdrawal" />

            <MemberPageShell contentClassName="space-y-5 sm:space-y-6">
                {raceOnlyIncome ? (
                    <>
                        <MemberPageHero kicker="Withdrawal" title="Bring income to your wallet" variant="pdf" icon="withdrawal">
                            Held RACE only. Daily claim USDT uses the payout form when rewards engine is hybrid.
                        </MemberPageHero>
                        {withdrawals_disabled || withdrawal_lock_message ? (
                            <div className="mx-auto w-full min-w-0 max-w-lg">
                                <MemberAlert variant="warning" className="mb-3">
                                    {withdrawal_lock_message
                                        || 'Withdrawals are temporarily disabled for your account. Please contact support.'}
                                </MemberAlert>
                            </div>
                        ) : null}
                    </>
                ) : (
                <>
                <MemberPageHero kicker="Withdrawal" title="Withdrawal" variant="pdf" icon="withdrawal">
                    {raceMintOnWithdraw
                        ? 'Pay Admin Fee in USDT from your wallet. From income only 10% Team Reward is cut; net mints as RACE.'
                        : 'Withdraw income to your BEP20 wallet. Team Reward + Admin Fee apply once per withdraw.'}
                </MemberPageHero>

                <div className="mx-auto w-full min-w-0 max-w-lg">
                    {flash?.status ? (
                        <MemberAlert variant="success" className="mb-3">
                            {flash.status}
                        </MemberAlert>
                    ) : null}
                    {flash?.error ? (
                        <MemberAlert variant="error" className="mb-3">
                            {flash.error}
                        </MemberAlert>
                    ) : null}

                    {withdrawals_disabled || withdrawal_lock_message ? (
                        <MemberAlert variant="warning" className="mb-3">
                            {withdrawal_lock_message
                                || 'Withdrawals are temporarily disabled for your account. Please contact support.'}
                            {withdrawals_locked_until && !withdrawal_lock_message?.includes('24 hours') ? (
                                <span className="mt-1 block text-xs opacity-90">
                                    Locked until {new Date(withdrawals_locked_until).toLocaleString()}
                                </span>
                            ) : null}
                        </MemberAlert>
                    ) : null}

                    {feeError ? (
                        <MemberAlert variant="error" className="mb-3">
                            {feeError}
                        </MemberAlert>
                    ) : null}
                    {feeStepLabel ? (
                        <MemberAlert variant="info" className="mb-3">
                            {feeStepLabel}
                        </MemberAlert>
                    ) : null}
                    {errors.admin_fee_tx_hash ? (
                        <MemberAlert variant="error" className="mb-3">
                            {errors.admin_fee_tx_hash}
                        </MemberAlert>
                    ) : null}
                </div>

                <div className="mx-auto w-full min-w-0 max-w-lg">

                    {rate_limit?.enabled ? (
                        <div
                            className={`mb-3 rounded-lg border px-3 py-2.5 text-xs sm:text-sm ${
                                rateLimited
                                    ? 'border-amber-400/70 bg-amber-950/50 text-amber-100'
                                    : 'border-sky-400/50 bg-[#0a1838]/80 text-sky-100'
                            }`}
                        >
                            <p className="font-semibold">
                                Withdrawal limit: {rate_limit.used_in_window ?? 0} /{' '}
                                {rate_limit.max_per_window ?? 2} used in last{' '}
                                {rate_limit.window_hours ?? 24}h
                                {!rateLimited ? (
                                    <span className="font-normal text-sky-200/90">
                                        {' '}
                                        · {rate_limit.remaining ?? 0} left
                                    </span>
                                ) : null}
                            </p>
                            {rateLimited ? (
                                <p className="mt-2 font-mono text-lg font-bold tracking-wider text-amber-100 sm:text-xl">
                                    Next in {formatCountdown(countdownMs)}
                                </p>
                            ) : null}
                            {Array.isArray(rate_limit.slot_frees_at) && rate_limit.slot_frees_at.length > 0 ? (
                                <ul className="mt-2 space-y-0.5 text-[11px] text-sky-300/80 sm:text-xs">
                                    {rate_limit.slot_frees_at.map((iso, i) => (
                                        <li key={`${iso}-${i}`}>
                                            Slot {i + 1} frees:{' '}
                                            {new Date(iso).toLocaleString(undefined, {
                                                month: 'short',
                                                day: 'numeric',
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </li>
                                    ))}
                                </ul>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="mb-3 space-y-3">
                        <div className="rounded-xl border border-fintech-line bg-white px-3 py-2.5 shadow-sm sm:px-4 sm:py-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-fintech-primary">
                                Total income
                            </p>
                            <p className="mt-1 break-words text-2xl font-bold text-fintech-ink">
                                {formatUsdtBalance(totalBalance)}
                            </p>
                            {raceEquivLabel(totalBalance, race_price_usd) ? (
                                <p className="mt-0.5 font-mono text-sm font-semibold text-sky-700">
                                    {raceEquivLabel(totalBalance, race_price_usd)}
                                </p>
                            ) : null}
                            <p className="mt-1 text-[11px] text-fintech-muted">
                                Daily claim + other income (withdrawable).
                            </p>
                        </div>
                        <div className="rounded-xl border border-fintech-line bg-gradient-to-br from-sky-50/80 to-white px-3 py-2.5 shadow-sm ring-1 ring-[#2563EB]/25 sm:px-4 sm:py-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-fintech-primary">
                                Daily claim hold
                            </p>
                            <p className="mt-1 break-words text-xl font-bold text-fintech-ink">
                                {formatUsdtBalance(dailyClaimBal)}
                            </p>
                            {raceEquivLabel(dailyClaimBal, race_price_usd) ? (
                                <p className="mt-0.5 font-mono text-sm font-semibold text-sky-700">
                                    {raceEquivLabel(dailyClaimBal, race_price_usd)}
                                </p>
                            ) : null}
                            <p className="mt-1 text-[11px] text-fintech-muted">
                                From daily claims to date, {formatUsdtBalance(dailyClaimBal)} is on hold
                                {dailyClaimEarned > 0
                                    ? ` · Lifetime credited ${formatUsdtBalance(dailyClaimEarned)}`
                                    : ''}
                                . Team Reward + Admin Fee apply on withdraw.
                            </p>
                        </div>
                        <div className="rounded-xl border border-fintech-line bg-white px-3 py-2.5 shadow-sm sm:px-4 sm:py-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-fintech-primary">
                                Other income
                            </p>
                            <p className="mt-1 break-words text-xl font-bold text-fintech-ink">
                                {formatUsdtBalance(otherBal)}
                            </p>
                            {raceEquivLabel(otherBal, race_price_usd) ? (
                                <p className="mt-0.5 font-mono text-sm font-semibold text-sky-700">
                                    {raceEquivLabel(otherBal, race_price_usd)}
                                </p>
                            ) : null}
                            <p className="mt-1 text-[11px] text-fintech-muted">
                                Referral / leadership / team — withdraw anytime. Fees apply once on this amount.
                            </p>
                        </div>
                        {Number(income_policy?.accrued_reward_usd ?? 0) > 0 ? (
                            <p className="text-[11px] text-fintech-muted">
                                Accrued not yet in hold: {formatUsdtBalance(Number(income_policy.accrued_reward_usd))}{' '}
                                (moves to hold after auto-claim / claim).
                            </p>
                        ) : null}
                    </div>

                    <PanelCard
                        title={raceMintOnWithdraw ? 'Withdraw as RACE' : 'Request USDT payout'}
                        icon="payout"
                    >
                        <form onSubmit={submit} className="space-y-4">
                            <div>
                                <p className="block text-sm font-medium text-fintech-ink">Income type</p>
                                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                    <button
                                        type="button"
                                        disabled={formBlocked}
                                        onClick={() => setData('income_bucket', 'daily_claim')}
                                        className={`rounded-xl border px-3 py-2.5 text-left text-sm transition ${
                                            data.income_bucket === 'daily_claim'
                                                ? 'border-[#2563EB] bg-sky-50 text-fintech-ink'
                                                : 'border-fintech-line bg-white text-fintech-muted'
                                        }`}
                                    >
                                        <span className="block font-semibold text-fintech-ink">Daily claim</span>
                                        <span className="mt-0.5 block text-xs">
                                            {formatUsdtBalance(dailyClaimBal)} available
                                        </span>
                                    </button>
                                    <button
                                        type="button"
                                        disabled={formBlocked}
                                        onClick={() => setData('income_bucket', 'other')}
                                        className={`rounded-xl border px-3 py-2.5 text-left text-sm transition ${
                                            data.income_bucket === 'other'
                                                ? 'border-sky-500 bg-sky-50 text-fintech-ink'
                                                : 'border-fintech-line bg-white text-fintech-muted'
                                        }`}
                                    >
                                        <span className="block font-semibold text-fintech-ink">Other income</span>
                                        <span className="mt-0.5 block text-xs">
                                            {formatUsdtBalance(otherBal)} available · anytime
                                        </span>
                                    </button>
                                </div>
                                {errors.income_bucket ? (
                                    <p className="mt-1 text-xs text-red-600">{errors.income_bucket}</p>
                                ) : null}
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-fintech-ink">
                                    Amount (USDT) — gross
                                </label>
                                <TextInput
                                    type="number"
                                    min="1"
                                    step="0.01"
                                    max={selectedBucketBal > 0 ? selectedBucketBal : undefined}
                                    value={data.amount_usd}
                                    className="mt-2 block w-full"
                                    onChange={(e) => setData('amount_usd', e.target.value)}
                                    disabled={formBlocked || selectedBucketBal <= 0}
                                />
                                <p className="mt-1 text-[11px] text-fintech-muted">
                                    Max for this type: {formatUsdtBalance(selectedBucketBal)}
                                </p>
                                {errors.amount_usd ? (
                                    <p className="mt-1 text-xs text-red-600">{errors.amount_usd}</p>
                                ) : null}
                                {gross > 0 ? (
                                    <p className="mt-2 text-xs text-fintech-muted">
                                        Team Reward ({percent}%):{' '}
                                        <strong>{formatUsdtBalance(teamReward)}</strong>
                                        {raceMintOnWithdraw ? (
                                            <>
                                                {' '}
                                                · Admin Fee (USDT wallet):{' '}
                                                <strong>{formatUsdtBalance(adminFee)}</strong> · From
                                                income you receive:{' '}
                                                <strong>{formatUsdtBalance(net)}</strong>
                                            </>
                                        ) : (
                                            <>
                                                {' '}
                                                · Admin Fee:{' '}
                                                <strong>{formatUsdtBalance(adminFee)}</strong> · Net
                                                withdrawal: <strong>{formatUsdtBalance(net)}</strong>
                                            </>
                                        )}
                                        {raceEquivLabel(net, race_price_usd) ? (
                                            <>
                                                {' '}
                                                · You receive{' '}
                                                <strong className="text-sky-700">
                                                    {raceEquivLabel(net, race_price_usd)}
                                                </strong>
                                            </>
                                        ) : null}
                                    </p>
                                ) : null}
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-fintech-ink">
                                    {raceMintOnWithdraw
                                        ? `RACE wallet address (${USDT_NETWORK})`
                                        : `USDT wallet address (${USDT_NETWORK})`}
                                </label>
                                <TextInput
                                    value={data.destination_address}
                                    className="mt-2 block w-full font-mono text-sm"
                                    placeholder={
                                        raceMintOnWithdraw
                                            ? '0x… your BEP20 wallet for RACE'
                                            : '0x… your BEP20 USDT address'
                                    }
                                    onChange={(e) => setData('destination_address', e.target.value)}
                                    readOnly={withdrawalAddressLocked}
                                    disabled={withdrawalAddressLocked || formBlocked}
                                    autoComplete="off"
                                />
                                {withdrawalAddressLocked ? (
                                    <p className="mt-1 text-xs text-fintech-muted">
                                        Fixed withdrawal address for your account (see Profile).
                                    </p>
                                ) : null}
                                {errors.destination_address ? (
                                    <p className="mt-1 text-xs text-red-600">{errors.destination_address}</p>
                                ) : null}
                            </div>
                            <PrimaryButton
                                type="submit"
                                className="mt-2 w-full justify-center border-red-500 bg-red-600 py-3 text-white hover:bg-red-500 focus:ring-red-400"
                                disabled={
                                    processing ||
                                    Boolean(feeStep) ||
                                    formBlocked ||
                                    !data.amount_usd ||
                                    !data.destination_address
                                }
                            >
                                {feeStepLabel
                                    ? feeStepLabel
                                    : processing
                                      ? 'Processing…'
                                      : rateLimited
                                        ? `Wait ${formatCountdown(countdownMs)}`
                                        : raceMintOnWithdraw
                                          ? data.income_bucket === 'daily_claim'
                                            ? 'Pay Admin Fee → mint daily claim as RACE'
                                            : 'Pay Admin Fee → mint other income as RACE'
                                          : data.income_bucket === 'daily_claim'
                                            ? 'Withdraw daily claim'
                                            : 'Withdraw other income'}
                            </PrimaryButton>
                        </form>
                    </PanelCard>

                    <PanelCard title="Withdrawal history" icon="withdrawal" className="mt-5">
                        {withdrawals.length === 0 ? (
                            <p className="rx-empty">No withdrawal requests yet.</p>
                        ) : (
                            <div className="-mx-1 overflow-hidden rounded-xl border border-fintech-line bg-white sm:mx-0">
                                <div className="divide-y divide-fintech-line md:hidden">
                                    {withdrawals.map((row) => (
                                        <WithdrawalRowMobile key={row.id} row={row} />
                                    ))}
                                </div>

                                <div className="hidden md:block">
                                    <MemberTableScroll>
                                        <table className="w-full text-left text-xs text-fintech-muted sm:text-sm">
                                            <thead className="border-b border-fintech-line bg-fintech-soft text-[0.65rem] font-semibold uppercase tracking-wide sm:text-xs">
                                                <tr>
                                                    <th className="px-3 py-2.5">Date</th>
                                                    <th className="px-3 py-2.5">ID</th>
                                                    <th className="px-3 py-2.5">Gross</th>
                                                    <th className="px-3 py-2.5">Net</th>
                                                    <th className="px-3 py-2.5">RACE</th>
                                                    <th className="px-3 py-2.5">Status</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-fintech-line">
                                                {withdrawals.map((row) => (
                                                    <tr key={row.id} className="hover:bg-fintech-soft/80">
                                                        <td className="whitespace-nowrap px-3 py-2.5">
                                                            {formatRowDate(row.created_at)}
                                                        </td>
                                                        <td className="whitespace-nowrap px-3 py-2.5 font-mono text-fintech-ink">
                                                            #{row.id}
                                                        </td>
                                                        <td className="whitespace-nowrap px-3 py-2.5 font-medium text-fintech-ink">
                                                            {formatUsdtBalance(row.amount_usd)}
                                                        </td>
                                                        <td className="whitespace-nowrap px-3 py-2.5 text-fintech-ink">
                                                            {formatUsdtBalance(row.net_usd)}
                                                        </td>
                                                        <td className="whitespace-nowrap px-3 py-2.5 text-fintech-ink">
                                                            {row.race_amount
                                                                ? Number(row.race_amount).toLocaleString(undefined, {
                                                                      maximumFractionDigits: 4,
                                                                  })
                                                                : '—'}
                                                        </td>
                                                        <td className="whitespace-nowrap px-3 py-2.5">
                                                            <span
                                                                className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide sm:text-xs ${statusBadgeClass(row.status)}`}
                                                            >
                                                                {row.status_label ?? row.status}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </MemberTableScroll>
                                </div>
                            </div>
                        )}
                    </PanelCard>
                </div>
                </>
                )}
            </MemberPageShell>
        </AuthenticatedLayout>
    );
}
