const TICKER_ITEMS = [
    'All income programs are active, live, and well organized',
    'Daily staking ROI credits Virtual Income every morning at 04:00 IST',
    'Each stake is paid on its own amount and ROI rate — 4, 5 or 6 stakes all credited',
    'Staking Reward Rates · Flexible 0.35% · 180D 0.50% · 365D 0.70% · 730D 0.90% · 1095D 1.00%',
    'Community Referrals · 10-level one-time referral on qualifying stakes',
    'ROI Sharing · Direct-line ROI-on-ROI from daily staking',
    'Community Leadership · Rank rewards on the daily schedule',
    'Community Team Rewards · Team matching income is live',
    'Compound Virtual Income into your stake, or withdraw through the existing Income wallet',
];

function TickerLoop() {
    return (
        <span className="rx-income-ticker-loop">
            {TICKER_ITEMS.map((text) => (
                <span key={text} className="rx-income-ticker-item">
                    {text}
                </span>
            ))}
        </span>
    );
}

export default function LiveIncomeTicker() {
    return (
        <div className="rx-income-ticker" role="status" aria-live="polite">
            <span className="rx-income-ticker-live">
                <span className="rx-income-ticker-dot" aria-hidden />
                LIVE
            </span>
            <div className="rx-income-ticker-viewport">
                <div className="rx-income-ticker-track">
                    <TickerLoop />
                    <TickerLoop />
                </div>
            </div>
        </div>
    );
}
