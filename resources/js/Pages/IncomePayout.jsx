import PrimaryButton from '@/Components/PrimaryButton';
import PanelCard from '@/Components/PanelCard';
import TextInput from '@/Components/TextInput';
import MemberAlert from '@/Components/Member/MemberAlert';
import MemberPageHero from '@/Components/Member/MemberPageHero';
import MemberPageShell from '@/Components/Member/MemberPageShell';
import MemberTableScroll from '@/Components/Member/MemberTableScroll';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const NETWORK = 'BEP20 (BNB Smart Chain)';

function fmtUsdt(n) {
    const s = new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number.isFinite(n) ? n : 0);
    return `${s} USDT`;
}

function fmtRace(n) {
    return `${new Intl.NumberFormat(undefined, { maximumFractionDigits: 4 }).format(
        Number.isFinite(n) ? n : 0,
    )} RACE`;
}

function fmtDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function statusClass(status) {
    switch (status) {
        case 'completed':
            return 'bg-emerald-100 text-emerald-800';
        case 'rejected':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-amber-100 text-amber-900';
    }
}

function fmtCountdown(ms) {
    if (ms <= 0) return '00:00:00';
    const total = Math.floor(ms / 1000);
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    return [h, m, s].map((n) => String(n).padStart(2, '0')).join(':');
}

function useCountdown(iso) {
    const [ms, setMs] = useState(() => (iso ? Math.max(0, new Date(iso).getTime() - Date.now()) : 0));
    useEffect(() => {
        if (!iso) {
            setMs(0);
            return undefined;
        }
        const tick = () => setMs(Math.max(0, new Date(iso).getTime() - Date.now()));
        tick();
        const id = window.setInterval(tick, 1000);
        return () => window.clearInterval(id);
    }, [iso]);
    return ms;
}

export default function IncomePayout({
    balance_usd = '0.00',
    wallet_address = null,
    withdrawal_fee = {},
    payout_enabled = false,
    race_price_usd = null,
    withdrawals_disabled = false,
    withdrawal_lock_message = null,
    rate_limit = { enabled: false, can_request: true },
    payouts = [],
}) {
    const { auth, flash } = usePage().props;

    const savedWallet = (wallet_address ?? auth?.user?.wallet_address ?? '').trim();
    const walletLocked = savedWallet !== '';
    const balance = Number(balance_usd) || 0;

    const teamPercent = Number(withdrawal_fee.team_reward_percent ?? withdrawal_fee.percent) || 10;
    const adminFlat = Number(withdrawal_fee.flat_fee_usd) || 1;
    const adminFrom = Number(withdrawal_fee.percent_from_usd) || 100;
    const adminPercent = Number(withdrawal_fee.admin_fee_percent) || 1;
    const minAmount = Number(withdrawal_fee.min_amount_usd) || 1;
    const price = Number(race_price_usd) || 0;

    const rateLimited = Boolean(rate_limit?.enabled && !rate_limit?.can_request);
    const countdownMs = useCountdown(rateLimited ? rate_limit?.next_available_at : null);
    const blocked = !payout_enabled || withdrawals_disabled || rateLimited;

    const { data, setData, post, processing, errors, reset } = useForm({
        amount_usd: '',
        destination_address: savedWallet,
    });

    const gross = Number(data.amount_usd) || 0;
    const teamReward = gross > 0 ? (gross * teamPercent) / 100 : 0;
    const adminFee = gross > 0 ? (gross < adminFrom ? adminFlat : (gross * adminPercent) / 100) : 0;
    const net = gross > 0 ? Math.max(0, gross - teamReward - adminFee) : 0;
    const estRace = price > 0 ? net / price : 0;

    const submit = (e) => {
        e.preventDefault();
        if (blocked) return;
        post(route('income-payout.store'), {
            preserveScroll: true,
            onSuccess: () => reset('amount_usd'),
        });
    };

    return (
        <AuthenticatedLayout
            pageTitle="Income Payout (RACE)"
            memberSurface="race"
            showMobileFintechNav
            mobileFintechPageTitle="Income Payout"
        >
            <Head title="Income Payout (RACE)" />

            <MemberPageShell contentClassName="space-y-5 sm:space-y-6">
                <MemberPageHero kicker="Income" title="Income Payout (RACE)" variant="pdf" icon="withdrawal">
                    Your Virtual Income (USDT) is paid out as real on-chain RACE, minted straight to your BEP20 wallet.
                    No admin approval.
                </MemberPageHero>

                <div className="mx-auto w-full min-w-0 max-w-lg space-y-3">
                    {flash?.status ? <MemberAlert variant="success">{flash.status}</MemberAlert> : null}
                    {flash?.error ? <MemberAlert variant="error">{flash.error}</MemberAlert> : null}

                    {!payout_enabled ? (
                        <MemberAlert variant="warning">
                            RACE payout is not switched on yet. Please check back soon.
                        </MemberAlert>
                    ) : null}

                    {withdrawals_disabled || withdrawal_lock_message ? (
                        <MemberAlert variant="warning">
                            {withdrawal_lock_message
                                || 'Payouts are temporarily disabled for your account. Please contact support.'}
                        </MemberAlert>
                    ) : null}

                    {rate_limit?.enabled ? (
                        <div
                            className={`rounded-lg border px-3 py-2.5 text-xs sm:text-sm ${
                                rateLimited
                                    ? 'border-amber-400/70 bg-amber-950/50 text-amber-100'
                                    : 'border-sky-400/50 bg-[#0a1838]/80 text-sky-100'
                            }`}
                        >
                            <p className="font-semibold">
                                Payout limit: {rate_limit.used_in_window ?? 0} / {rate_limit.max_per_window ?? 2} used
                                in last {rate_limit.window_hours ?? 24}h
                                {!rateLimited ? ` · ${rate_limit.remaining ?? 0} left` : ''}
                            </p>
                            {rateLimited ? (
                                <p className="mt-2 font-mono text-lg font-bold tracking-wider">
                                    Next in {fmtCountdown(countdownMs)}
                                </p>
                            ) : null}
                        </div>
                    ) : null}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-xl border border-fintech-line bg-white px-3 py-2.5 shadow-sm sm:px-4 sm:py-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-fintech-primary">
                                Virtual income
                            </p>
                            <p className="mt-1 break-words text-xl font-bold text-fintech-ink sm:text-2xl">
                                {fmtUsdt(balance)}
                            </p>
                        </div>
                        <div className="rounded-xl border border-fintech-line bg-white px-3 py-2.5 shadow-sm sm:px-4 sm:py-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-fintech-primary">
                                RACE price
                            </p>
                            <p className="mt-1 break-words text-xl font-bold text-fintech-ink sm:text-2xl">
                                {price > 0 ? `$${price.toFixed(6)}` : '—'}
                            </p>
                        </div>
                    </div>

                    <p className="rounded-lg border border-sky-400/50 bg-[#0a1838]/80 px-3 py-2 text-xs leading-relaxed text-sky-100 sm:text-sm">
                        <span className="font-semibold text-sky-50">Fees:</span> {teamPercent}% Team Reward (L1–L10) +
                        Admin Fee (${adminFlat} if under ${adminFrom}, else {adminPercent}%). Minimum ${minAmount}. The net
                        is converted to RACE at the live price and minted to your wallet.
                    </p>

                    <PanelCard title="Pay out as RACE" icon="payout">
                        <form onSubmit={submit} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium text-fintech-ink">
                                    Amount (USDT) — gross
                                </label>
                                <TextInput
                                    type="number"
                                    min={minAmount}
                                    step="0.01"
                                    value={data.amount_usd}
                                    className="mt-2 block w-full"
                                    onChange={(e) => setData('amount_usd', e.target.value)}
                                    disabled={blocked}
                                />
                                <button
                                    type="button"
                                    className="mt-1 text-xs font-semibold text-sky-700 hover:underline disabled:opacity-50"
                                    disabled={blocked || balance <= 0}
                                    onClick={() => setData('amount_usd', balance.toFixed(2))}
                                >
                                    Use full balance
                                </button>
                                {errors.amount_usd ? (
                                    <p className="mt-1 text-xs text-red-600">{errors.amount_usd}</p>
                                ) : null}
                                {gross > 0 ? (
                                    <div className="mt-2 space-y-0.5 text-xs text-fintech-muted">
                                        <p>
                                            Team Reward ({teamPercent}%): <strong>{fmtUsdt(teamReward)}</strong>
                                        </p>
                                        <p>
                                            Admin Fee: <strong>{fmtUsdt(adminFee)}</strong>
                                        </p>
                                        <p>
                                            Net: <strong>{fmtUsdt(net)}</strong>
                                        </p>
                                        <p className="text-sm text-fintech-ink">
                                            You receive ≈ <strong>{fmtRace(estRace)}</strong>
                                        </p>
                                    </div>
                                ) : null}
                            </div>

                            <div>
                                <label className="block text-sm font-medium text-fintech-ink">
                                    RACE wallet ({NETWORK})
                                </label>
                                <TextInput
                                    value={data.destination_address}
                                    className="mt-2 block w-full font-mono text-sm"
                                    placeholder="0x… your BEP20 wallet"
                                    onChange={(e) => setData('destination_address', e.target.value)}
                                    readOnly={walletLocked}
                                    disabled={walletLocked || blocked}
                                    autoComplete="off"
                                />
                                {walletLocked ? (
                                    <p className="mt-1 text-xs text-fintech-muted">
                                        Fixed payout wallet for your account (see Profile).
                                    </p>
                                ) : null}
                                {errors.destination_address ? (
                                    <p className="mt-1 text-xs text-red-600">{errors.destination_address}</p>
                                ) : null}
                            </div>

                            <PrimaryButton
                                type="submit"
                                className="mt-2 w-full justify-center border-red-500 bg-red-600 py-3 text-white hover:bg-red-500 focus:ring-red-400"
                                disabled={processing || blocked || !data.amount_usd || !data.destination_address}
                            >
                                {processing
                                    ? 'Minting…'
                                    : rateLimited
                                      ? `Wait ${fmtCountdown(countdownMs)}`
                                      : 'Mint RACE to my wallet'}
                            </PrimaryButton>
                        </form>
                    </PanelCard>

                    <PanelCard title="Payout history" icon="withdrawal">
                        {payouts.length === 0 ? (
                            <p className="rx-empty">No payouts yet.</p>
                        ) : (
                            <div className="-mx-1 overflow-hidden rounded-xl border border-fintech-line bg-white sm:mx-0">
                                <MemberTableScroll>
                                    <table className="w-full text-left text-xs text-fintech-muted sm:text-sm">
                                        <thead className="border-b border-fintech-line bg-fintech-soft text-[0.65rem] font-semibold uppercase tracking-wide sm:text-xs">
                                            <tr>
                                                <th className="px-3 py-2.5">Date</th>
                                                <th className="px-3 py-2.5">Gross</th>
                                                <th className="px-3 py-2.5">Net</th>
                                                <th className="px-3 py-2.5">RACE</th>
                                                <th className="px-3 py-2.5">Tx</th>
                                                <th className="px-3 py-2.5">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-fintech-line">
                                            {payouts.map((row) => (
                                                <tr key={row.id} className="hover:bg-fintech-soft/80">
                                                    <td className="whitespace-nowrap px-3 py-2.5">
                                                        {fmtDate(row.created_at)}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-2.5 text-fintech-ink">
                                                        {fmtUsdt(Number(row.amount_usd))}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-2.5 text-fintech-ink">
                                                        {fmtUsdt(Number(row.net_usd))}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-2.5 text-fintech-ink">
                                                        {row.race_amount ? fmtRace(Number(row.race_amount)) : '—'}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-2.5 font-mono">
                                                        {row.race_tx_hash ? (
                                                            <a
                                                                href={`https://bscscan.com/tx/${row.race_tx_hash}`}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-sky-700 hover:underline"
                                                            >
                                                                {String(row.race_tx_hash).slice(0, 10)}…
                                                            </a>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </td>
                                                    <td className="whitespace-nowrap px-3 py-2.5">
                                                        <span
                                                            className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide sm:text-xs ${statusClass(row.status)}`}
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
                        )}
                    </PanelCard>
                </div>
            </MemberPageShell>
        </AuthenticatedLayout>
    );
}
