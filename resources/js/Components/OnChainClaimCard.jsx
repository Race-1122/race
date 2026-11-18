import MemberAlert from '@/Components/Member/MemberAlert';
import PrimaryButton from '@/Components/PrimaryButton';
import StakeMintClaimPanel from '@/Components/StakeMintClaimPanel';
import { useWalletNetwork } from '@/hooks/useWalletNetwork';
import { notifyError, notifySuccess } from '@/lib/appNotify';
import { configureWeb3Network, syncBlockchainTx } from '@/lib/web3Deposit';
import {
    claimOnChainReward,
    claimPolicyMessage,
    formatClaimCountdown,
    friendlyEngineError,
    readAllOnChainStakes,
    readClaimPolicyState,
} from '@/lib/web3Engine';
import { formatTokenWei, readIcoCompleted } from '@/lib/web3RaceICO';
import { Link, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';

/**
 * Dashboard "Claim rewards" card — on-chain Engine claim (moved from the ICO page).
 * Contract rule: one claim per user per 24h, claim settles ONE stake at a time.
 * While the wallet cooldown runs, remaining stakes show an "unlocks in" countdown; rewards keep accumulating.
 */
export default function OnChainClaimCard() {
    const { auth, blockchain } = usePage().props;
    const web3 = blockchain?.web3 ?? {};
    const ico = blockchain?.ico ?? {};

    const engineContract = (web3.engine_contract || web3.contract || ico.community_engine || '').trim();
    const icoContract = (ico.ico_contract || blockchain?.contracts?.ico || '').trim();
    const usdtContract = (web3.usdt_contract || blockchain?.contracts?.usdt || '').trim();
    const rpcUrl = ico.rpc_url || blockchain?.rpc_url || '';
    const expectedChainId = Number(
        ico.chain_id || web3.chain_id || blockchain?.chain_id || (blockchain?.is_testnet ? 97 : 56),
    );
    const boundWallet = auth?.user?.wallet_address || '';

    const {
        chainOk,
        switching: networkSwitching,
        networkError,
        switchNetwork,
        connectWallet: connectWalletOnNetwork,
        switchButtonLabel,
    } = useWalletNetwork({ expectedChainId });

    const [walletAddress, setWalletAddress] = useState(boundWallet);
    const [stakes, setStakes] = useState([]);
    const [icoCompleted, setIcoCompleted] = useState(false);
    const [policy, setPolicy] = useState({ supported: false, claimEnabled: false, canClaim: false, nextAllowedAt: 0 });
    const [loading, setLoading] = useState(false);
    const [busyIndex, setBusyIndex] = useState(null);
    const [error, setError] = useState('');
    const [tick, setTick] = useState(0);
    const [panelRefresh, setPanelRefresh] = useState(0);

    useEffect(() => {
        configureWeb3Network({ chainId: expectedChainId, usdtContract, rpcUrl });
    }, [expectedChainId, usdtContract, rpcUrl]);

    useEffect(() => {
        const timer = setInterval(() => setTick((t) => t + 1), 1000);
        return () => clearInterval(timer);
    }, []);

    const refresh = useCallback(async () => {
        if (!engineContract || !walletAddress) {
            setStakes([]);
            return;
        }
        setLoading(true);
        try {
            const [completed, pol, list] = await Promise.all([
                icoContract ? readIcoCompleted({ icoContract, rpcUrl }).catch(() => false) : Promise.resolve(true),
                readClaimPolicyState({ walletAddress, engineContract, rpcUrl }).catch(() => ({
                    supported: false,
                    claimEnabled: false,
                    canClaim: false,
                    nextAllowedAt: 0,
                })),
                readAllOnChainStakes({ engineContract, walletAddress, rpcUrl }).catch(() => []),
            ]);
            setIcoCompleted(Boolean(completed));
            setPolicy(pol);
            setStakes(list || []);
        } finally {
            setLoading(false);
        }
    }, [engineContract, icoContract, rpcUrl, walletAddress]);

    useEffect(() => {
        refresh();
    }, [refresh]);

    const message = useMemo(
        () =>
            claimPolicyMessage({
                icoCompleted,
                claimEnabled: policy.claimEnabled,
                canClaim: policy.canClaim,
                nextAllowedAt: policy.nextAllowedAt,
                supported: policy.supported,
            }),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [icoCompleted, policy, tick],
    );

    // Biggest pending reward first — the contract lets only ONE stake be claimed per wallet per 24h.
    const claimable = stakes
        .filter((s) => !s.withdrawn && (s.pendingRewardRace || 0n) > 0n)
        .sort((a, b) => {
            const x = a.pendingRewardRace || 0n;
            const y = b.pendingRewardRace || 0n;
            if (x === y) return 0;
            return x > y ? -1 : 1;
        });
    const totalPending = claimable.reduce((sum, s) => sum + (s.pendingRewardRace || 0n), 0n);
    const globalAllowed =
        icoCompleted && (policy.supported ? policy.claimEnabled && policy.canClaim : true);
    // Wallet-level 24h cooldown running (recomputed every second via `tick`).
    const cooldownLeft =
        icoCompleted && policy.supported && policy.claimEnabled && tick >= 0
            ? formatClaimCountdown(policy.nextAllowedAt)
            : null;

    const connectWallet = async () => {
        setError('');
        try {
            const address = await connectWalletOnNetwork();
            if (address) setWalletAddress(address);
        } catch (err) {
            setError(err?.message || 'Could not connect wallet.');
        }
    };

    const claim = async (stake) => {
        setError('');
        setBusyIndex(stake.index);
        try {
            const txHash = await claimOnChainReward({
                walletAddress,
                engineContract,
                stakeIndex: stake.index,
            });
            try {
                await syncBlockchainTx({ txHash });
            } catch (syncErr) {
                console.warn('claim sync-tx:', syncErr?.message || syncErr);
            }
            notifySuccess(
                claimable.length > 1
                    ? 'Claim sent. Reward added to your income hold. Your other stakes can be claimed after the 24h wallet cooldown — their rewards keep accumulating, nothing is lost.'
                    : 'Claim sent. Reward added to your income hold.',
                'Claim',
            );
            await refresh();
            setPanelRefresh((n) => n + 1);
        } catch (err) {
            const msg = friendlyEngineError(err);
            setError(msg);
            notifyError(msg, 'Claim');
        } finally {
            setBusyIndex(null);
        }
    };

    if (!engineContract) {
        return null;
    }

    return (
        <div className="space-y-3 rounded-2xl border border-sky-200 bg-white px-4 py-5 shadow-sm">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-fintech-primary">Claim rewards</p>
                    <p className="mt-1 text-3xl font-bold text-fintech-ink">{formatTokenWei(totalPending, 18, 6)} RACE</p>
                    <p className="mt-1 text-sm text-fintech-muted">
                        {cooldownLeft ? 'Pending' : 'Claimable now'} across {claimable.length} stake
                        {claimable.length === 1 ? '' : 's'}
                    </p>
                </div>
                <button
                    type="button"
                    className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50"
                    onClick={refresh}
                    disabled={loading || busyIndex !== null}
                >
                    {loading ? 'Loading…' : 'Refresh'}
                </button>
            </div>

            <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">{message}</p>
            <p className="text-[11px] text-fintech-muted">
                Claimed income stays in hold — open Income wallet to bring it to your wallet. One stake can be claimed
                per wallet every 24 hours; unclaimed days keep accumulating on every stake.
            </p>

            {cooldownLeft && claimable.length > 0 ? (
                <MemberAlert variant="warning">
                    Wallet cooldown active: you already claimed a stake. The other {claimable.length} stake
                    {claimable.length === 1 ? '' : 's'} unlock{claimable.length === 1 ? 's' : ''} in {cooldownLeft}. Their
                    rewards are safe and keep accumulating — nothing is lost.
                </MemberAlert>
            ) : null}
            {globalAllowed && claimable.length > 1 ? (
                <p className="text-[11px] text-fintech-muted">
                    Tip: only one stake can be claimed now. Claim the highest reward first, the rest follow on the next
                    days.
                </p>
            ) : null}

            {error ? <MemberAlert variant="error">{error}</MemberAlert> : null}
            {networkError ? <MemberAlert variant="warning">{networkError}</MemberAlert> : null}

            {!walletAddress ? (
                <PrimaryButton type="button" className="w-full justify-center py-3" onClick={connectWallet}>
                    Connect wallet
                </PrimaryButton>
            ) : !chainOk ? (
                <PrimaryButton
                    type="button"
                    className="w-full justify-center py-3"
                    onClick={switchNetwork}
                    disabled={networkSwitching}
                >
                    {networkSwitching ? 'Switching network…' : switchButtonLabel || 'Switch network'}
                </PrimaryButton>
            ) : claimable.length === 0 ? (
                <p className="text-sm text-fintech-muted">{loading ? 'Reading stakes…' : 'No reward to claim right now.'}</p>
            ) : (
                <div className="space-y-2">
                    {claimable.map((stake, i) => (
                        <div
                            key={stake.index}
                            className="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-900/60 px-3 py-2"
                        >
                            <div className="min-w-0 text-sm">
                                <p className="font-semibold text-white">
                                    Stake #{stake.index}
                                    {i === 0 && claimable.length > 1 ? (
                                        <span className="ml-2 rounded bg-emerald-500/20 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-300">
                                            Highest
                                        </span>
                                    ) : null}
                                </p>
                                <p className="text-xs text-white">
                                    {formatTokenWei(stake.pendingRewardRace || 0n, 18, 6)} RACE
                                </p>
                                {cooldownLeft ? (
                                    <p className="text-[10px] text-amber-300">Unlocks in {cooldownLeft}</p>
                                ) : null}
                            </div>
                            <PrimaryButton
                                type="button"
                                disabled={!globalAllowed || busyIndex !== null}
                                onClick={() => claim(stake)}
                            >
                                {busyIndex === stake.index ? 'Claiming…' : 'Claim'}
                            </PrimaryButton>
                        </div>
                    ))}
                </div>
            )}

            {walletAddress ? <StakeMintClaimPanel refreshKey={panelRefresh} onClaimed={refresh} /> : null}

            <Link href={route('withdrawal')} className="inline-block text-xs font-semibold text-fintech-primary underline">
                Income wallet →
            </Link>
        </div>
    );
}
