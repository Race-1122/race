import { Link } from '@inertiajs/react';

function fmt(value, digits = 4) {
    const n = Number(value ?? 0);
    if (!Number.isFinite(n)) return '0';
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: digits });
}

function fmtDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/**
 * Dashboard section: daily on-chain claims — coins received, value at claim time,
 * value at today's RACE rate, and the claim date.
 */
export default function DailyClaimsSection({ data }) {
    const claims = data ?? {};
    const items = claims.items ?? [];
    const hasClaims = items.length > 0;
    const rate = claims.rate_usd ?? '0';

    return (
        <section className="relative overflow-hidden rounded-[1.25rem] border bg-gradient-to-br from-blue-500/10 to-sky-400/5 border-sky-200/80 bg-white/80 p-4 shadow-[0_8px_32px_-12px_rgba(37,99,235,0.12)] backdrop-blur-sm sm:p-5">
            <div className="pointer-events-none absolute -right-8 -top-8 h-24 w-24 rounded-full bg-sky-300/20 blur-2xl" />
            <div className="relative flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 flex flex-1 gap-3">
                    <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-sky-100 bg-sky-50/90 text-[#2563EB] shadow-sm">
                        <svg
                            className="h-5 w-5"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            strokeWidth="1.75"
                            aria-hidden="true"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l5.94-2.28m-5.94 2.28l2.28 5.941"
                            />
                        </svg>
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-[0.65rem] font-bold uppercase tracking-wider text-[#2563EB]">Daily claims</p>
                        <h3 className="mt-1 font-poppins text-base font-extrabold leading-snug text-[#0F172A] sm:text-lg">
                            Daily Claim Rewards
                        </h3>
                        <p className="mt-1 text-xs text-slate-400">
                            {hasClaims
                                ? `${claims.count} claim${claims.count === 1 ? '' : 's'} so far — latest first`
                                : 'No claims yet — your claims appear here after you claim'}
                        </p>
                        <p className="mt-2 text-[11px] leading-relaxed text-slate-500">
                            Coins received on each claim, its value at claim time, and its value at today&apos;s rate
                            (1 RACE = ${fmt(rate, 4)}).
                        </p>
                    </div>
                </div>
                <div className="shrink-0 rounded-xl border border-white/90 bg-white/90 px-4 py-2.5 text-right shadow-sm">
                    <p className="text-[0.6rem] font-bold uppercase tracking-wider text-slate-500">Total claimed</p>
                    <p className={`font-mono text-xl font-bold sm:text-2xl ${hasClaims ? 'text-[#0F172A]' : 'text-slate-400'}`}>
                        {fmt(claims.total_race ?? 0, 6)} RACE
                    </p>
                    <p className="font-mono text-xs text-slate-500">
                        ≈ ${fmt(claims.total_value_now_usd ?? 0, 2)} at today&apos;s rate
                    </p>
                </div>
            </div>

            {hasClaims ? (
                <div className="relative mt-4 overflow-x-auto">
                    <table className="w-full min-w-[34rem] text-left text-xs sm:text-sm">
                        <thead>
                            <tr className="border-b border-sky-100 text-[0.65rem] uppercase tracking-wider text-slate-500">
                                <th className="py-2 pr-3 font-semibold">Date</th>
                                <th className="py-2 pr-3 font-semibold">Stake</th>
                                <th className="py-2 pr-3 text-right font-semibold">Coins (RACE)</th>
                                <th className="py-2 pr-3 text-right font-semibold">Value at claim</th>
                                <th className="py-2 text-right font-semibold">Value at today&apos;s rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((row) => (
                                <tr key={row.id} className="border-b border-sky-50 text-[#0F172A]">
                                    <td className="whitespace-nowrap py-2.5 pr-3">{fmtDate(row.claimed_at)}</td>
                                    <td className="py-2.5 pr-3">#{row.stake_index}</td>
                                    <td className="py-2.5 pr-3 text-right font-mono font-semibold">{fmt(row.race, 6)}</td>
                                    <td className="py-2.5 pr-3 text-right font-mono">${fmt(row.value_at_claim_usd, 4)}</td>
                                    <td className="py-2.5 text-right font-mono font-semibold text-[#2563EB]">
                                        ${fmt(row.value_now_usd, 4)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}

            <div className="relative mt-4 flex flex-wrap gap-3 border-t border-white/80 pt-3 text-xs font-semibold">
                <Link className="text-[#2563EB] hover:underline" href={route('investment')}>
                    Program details →
                </Link>
                <Link className="text-slate-600 hover:underline" href={route('transactions')}>
                    View ledger →
                </Link>
            </div>
        </section>
    );
}
