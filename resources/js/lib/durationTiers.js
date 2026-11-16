/**
 * Find a lock-duration tier row by days value.
 * Ensures `seconds` matches on-chain lock periods (days * 86400).
 * @param {string|number} durationDays
 * @param {Array<{ days: number|string, seconds?: number|string }>} tiers
 * @returns {object|null}
 */
export function durationTiersForDays(durationDays, tiers) {
    const days = Number(durationDays);
    if (!Number.isFinite(days) || days < 0) return null;
    const row = (tiers ?? []).find((tier) => Number(tier.days) === days) ?? null;
    if (!row) return null;
    const seconds = Number(row.seconds);
    if (Number.isFinite(seconds) && seconds >= 0) {
        return row;
    }
    return {
        ...row,
        seconds: days > 0 ? days * 86400 : 0,
    };
}
