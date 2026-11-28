/**
 * USDT → RACE quote helpers for member income UI.
 */

export function raceFromUsdt(usdt, priceUsd) {
    const u = Number(usdt);
    const p = Number(priceUsd);
    if (!Number.isFinite(u) || !Number.isFinite(p) || p <= 0 || u === 0) {
        return null;
    }

    return u / p;
}

export function formatRaceCoins(amount, digits = 4) {
    const n = Number(amount);
    if (!Number.isFinite(n)) {
        return '—';
    }

    return n.toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: digits,
    });
}

/** e.g. "≈ 12.3456 RACE" — null when price missing */
export function raceEquivLabel(usdt, priceUsd, digits = 4) {
    const race = raceFromUsdt(usdt, priceUsd);
    if (race === null) {
        return null;
    }

    return `≈ ${formatRaceCoins(race, digits)} RACE`;
}
