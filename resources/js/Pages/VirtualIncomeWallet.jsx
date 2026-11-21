import MemberPageHero from '@/Components/Member/MemberPageHero';
import MemberPageShell from '@/Components/Member/MemberPageShell';
import MemberTableScroll from '@/Components/Member/MemberTableScroll';
import PanelCard from '@/Components/PanelCard';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { raceEquivLabel } from '@/lib/raceQuote';
import { Head, Link } from '@inertiajs/react';

function formatUsd(n) {
    const v = Number(n);
    if (Number.isNaN(v)) return '—';
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 4,
    }).format(v);
}

function UsdWithRace({ usd, price }) {
    const line = raceEquivLabel(usd, price);
    return (
        <>
            <p className="mt-1 text-2xl font-bold text-fintech-ink">{formatUsd(usd)}</p>
            {line ? <p className="mt-0.5 font-mono text-sm font-semibold text-sky-700">{line}</p> : null}
        </>
    );
}

export default function VirtualIncomeWallet({
    summary = {
        available_balance_usd: '0.0000',
        today_income_usd: '0.0000',
        total_claimed_usd: '0.0000',
        total_compounded_usd: '0.0000',
        total_withdrawn_usd: '0.0000',
    },
    history = [],
    on_chain_history = [],
    on_chain_income = { enabled: false, authoritative: false, contract: '', settlement_token: '' },
    blockchain_only = false,
    rewards_engine = 'hybrid',
    race_price_usd = null,
}) {
    return (
        <AuthenticatedLayout
            pageTitle="Income wallet"
            memberSurface="race"
            showMobileFintechNav
            mobileFintechPageTitle="Income"
        >
            <Head title="Income wallet" />

            <MemberPageShell contentClassName="space-y-5 sm:space-y-6">
                <MemberPageHero
                    kicker="Earned income"
                    title="Income wallet"
                    variant="pdf"
                    icon="wallet"
                >
                    Combined platform income (USDT). Withdrawals apply Team Reward + Admin Fee; net can mint as RACE
                    to your wallet.
                </MemberPageHero>

                {blockchain_only ? (
                    <div className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                        Rewards engine is <strong>{rewards_engine}</strong>. Accrual and claim on this page are
                        disabled — use staking rewards. This page is read-only for history.
                    </div>
                ) : null}

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <PanelCard
                        title={on_chain_income.authoritative ? 'Income balance' : 'Available balance'}
                        icon="wallet"
                        className="!p-4"
                    >
                        <UsdWithRace usd={summary.available_balance_usd} price={race_price_usd} />
                        {on_chain_income.authoritative ? (
                            <p className="mt-2 text-xs text-fintech-muted">
                                Authoritative ledger: RaceIncomeVault ({on_chain_income.contract || 'not deployed'})
                            </p>
                        ) : null}
                    </PanelCard>
                    <PanelCard title="Today&apos;s income" icon="investment" className="!p-4">
                        <UsdWithRace usd={summary.today_income_usd} price={race_price_usd} />
                    </PanelCard>
                    <PanelCard title="Total claimed" icon="payout" className="!p-4">
                        <UsdWithRace usd={summary.total_claimed_usd} price={race_price_usd} />
                    </PanelCard>
                    <PanelCard title="Total compounded" icon="swap" className="!p-4">
                        <UsdWithRace usd={summary.total_compounded_usd} price={race_price_usd} />
                    </PanelCard>
                    <PanelCard title="Total withdrawn (gross)" icon="withdrawal" className="!p-4">
                        <UsdWithRace usd={summary.total_withdrawn_usd} price={race_price_usd} />
                    </PanelCard>
                </div>

                <p className="text-sm text-fintech-muted">
                    <Link href={route('investment')} className="font-semibold text-sky-700 hover:underline">
                        Claim accrued rewards
                    </Link>
                    {' · '}
                    <Link href={route('withdrawal')} className="font-semibold text-sky-700 hover:underline">
                        Income wallet — bring to my wallet
                    </Link>
                </p>

                <PanelCard title="Income history" icon="transactions">
                    {history.length === 0 ? (
                        <p className="rx-empty">No income ledger entries yet.</p>
                    ) : (
                        <MemberTableScroll>
                            <table className="w-full min-w-[28rem] text-left text-xs sm:text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 text-fintech-muted">
                                        <th className="px-3 py-2 font-semibold">When</th>
                                        <th className="px-3 py-2 font-semibold">Type</th>
                                        <th className="px-3 py-2 font-semibold">Direction</th>
                                        <th className="px-3 py-2 font-semibold text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {history.map((row) => (
                                        <tr key={row.id} className="border-b border-slate-100">
                                            <td className="px-3 py-2">
                                                {row.created_at
                                                    ? new Date(row.created_at).toLocaleString()
                                                    : '—'}
                                            </td>
                                            <td className="px-3 py-2">{row.type_label ?? row.type}</td>
                                            <td className="px-3 py-2 uppercase">{row.direction}</td>
                                            <td className="px-3 py-2 text-right font-mono">
                                                <span className="block">{formatUsd(row.amount)}</span>
                                                {raceEquivLabel(row.amount, race_price_usd) ? (
                                                    <span className="block text-[10px] font-semibold text-sky-700">
                                                        {raceEquivLabel(row.amount, race_price_usd)}
                                                    </span>
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </MemberTableScroll>
                    )}
                </PanelCard>

                {on_chain_income.enabled && on_chain_history.length > 0 ? (
                    <PanelCard title="Indexed income ledger" icon="transactions">
                        <MemberTableScroll>
                            <table className="w-full min-w-[32rem] text-left text-xs sm:text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 text-fintech-muted">
                                        <th className="px-3 py-2 font-semibold">Event</th>
                                        <th className="px-3 py-2 font-semibold">Tx</th>
                                        <th className="px-3 py-2 font-semibold text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {on_chain_history.map((row, idx) => (
                                        <tr key={`${row.tx_hash}-${idx}`} className="border-b border-slate-100">
                                            <td className="px-3 py-2">{row.event_name}</td>
                                            <td className="px-3 py-2 font-mono text-[11px]">
                                                {row.tx_hash ? `${row.tx_hash.slice(0, 10)}…` : '—'}
                                                {row.block_number ? ` · #${row.block_number}` : ''}
                                            </td>
                                            <td className="px-3 py-2 text-right font-mono">
                                                <span className="block">
                                                    {formatUsd(row.net_amount ?? row.amount ?? 0)}
                                                </span>
                                                {raceEquivLabel(row.net_amount ?? row.amount ?? 0, race_price_usd) ? (
                                                    <span className="block text-[10px] font-semibold text-sky-700">
                                                        {raceEquivLabel(
                                                            row.net_amount ?? row.amount ?? 0,
                                                            race_price_usd,
                                                        )}
                                                    </span>
                                                ) : null}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </MemberTableScroll>
                    </PanelCard>
                ) : null}
            </MemberPageShell>
        </AuthenticatedLayout>
    );
}
