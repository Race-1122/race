import PrimaryButton from '@/Components/PrimaryButton';
import Web3NetworkBanner from '@/Components/Web3NetworkBanner';
import PanelCard from '@/Components/PanelCard';
import MemberPageHero from '@/Components/Member/MemberPageHero';
import MemberPageShell from '@/Components/Member/MemberPageShell';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useWalletNetwork } from '@/hooks/useWalletNetwork';
import { linkWalletToAccount } from '@/lib/walletAccountLink';
import { parseTokenAmount } from '@/lib/web3Deposit';
import {
    applySlippage,
    buyRaceWithUsdt,
    expectedNetAfterTransferTax,
    formatTokenWei,
    friendlySwapError,
    getSwapQuote,
    readErc20Balance,
    readRaceUsdtPrice,
    RACE_TRANSFER_TAX_BPS,
    sellRaceForUsdt,
} from '@/lib/web3PancakeSwap';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

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

function formatCoins(n) {
    const v = Number(n);
    if (Number.isNaN(v)) return '—';
    return new Intl.NumberFormat(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 4,
    }).format(v);
}

/**
 * On-chain swap via PancakeSwap Router (BSC).
 * Liquidity stays in the Pancake pair — no internal swap/stake contract.
 */
export default function Swap({
    pancake = {
        chain_id: 56,
        rpc_url: '',
        race_token: '',
        usdt_contract: '',
        pancake_router: '',
        block_explorer: 'https://bscscan.com',
        slippage_bps: 100,
        deadline_seconds: 1200,
    },
    raceCoin = {
        balance: '0.0000',
        id_activation_coins: '500.0000',
        id_activation_usd: '50.00',
        id_active: false,
    },
}) {
    const { auth, blockchain } = usePage().props;
    const wallet = auth.user?.wallet_address || '';

    const raceToken = (pancake.race_token || blockchain?.contracts?.race_token || '').trim();
    const usdtContract = (pancake.usdt_contract || blockchain?.contracts?.usdt || '').trim();
    const router = (pancake.pancake_router || blockchain?.contracts?.pancake_router || '').trim();
    const rpcUrl = (pancake.rpc_url || blockchain?.rpc_url || '').trim();
    const explorer = pancake.block_explorer || 'https://bscscan.com';
    const slippageBps = Number(pancake.slippage_bps ?? 100);
    const deadlineSeconds = Number(pancake.deadline_seconds ?? 1200);
    const expectedChainId = Number(pancake.chain_id || blockchain?.chain_id || 56);

    const {
        chainOk,
        switching: networkSwitching,
        networkError: networkSwitchError,
        switchNetwork,
        connectWallet: connectWalletOnNetwork,
        connectedLabel,
        switchButtonLabel,
    } = useWalletNetwork({ expectedChainId });

    const [connecting, setConnecting] = useState(false);
    const [walletError, setWalletError] = useState(null);
    const [direction, setDirection] = useState('buy'); // buy = USDT→RACE, sell = RACE→USDT
    const [amountIn, setAmountIn] = useState('');
    const [quoteOut, setQuoteOut] = useState(0n);
    const [livePriceUsd, setLivePriceUsd] = useState(0);
    const [usdtBal, setUsdtBal] = useState(0n);
    const [raceBal, setRaceBal] = useState(0n);
    const [swapStep, setSwapStep] = useState(null);
    const [swapError, setSwapError] = useState(null);
    const [swapSuccess, setSwapSuccess] = useState(null);
    const [lastTx, setLastTx] = useState('');

    const activateForm = useForm({});

    const configured = Boolean(raceToken && usdtContract && router);
    const paySymbol = direction === 'buy' ? 'USDT' : 'RACE';
    const receiveSymbol = direction === 'buy' ? 'RACE' : 'USDT';

    const canActivateId =
        !raceCoin.id_active &&
        Number(raceCoin.balance) >= Number(raceCoin.id_activation_coins) &&
        !activateForm.processing;

    useEffect(() => {
        let cancelled = false;
        async function load() {
            if (!wallet || !configured) {
                setUsdtBal(0n);
                setRaceBal(0n);
                setLivePriceUsd(0);
                return;
            }
            try {
                const [u, r, priceWei] = await Promise.all([
                    readErc20Balance({ token: usdtContract, wallet, rpcUrl }),
                    readErc20Balance({ token: raceToken, wallet, rpcUrl }),
                    readRaceUsdtPrice({ router, raceToken, usdtContract, rpcUrl }),
                ]);
                if (cancelled) return;
                setUsdtBal(u ?? 0n);
                setRaceBal(r ?? 0n);
                setLivePriceUsd(priceWei > 0n ? Number(formatTokenWei(priceWei, 18, 8)) : 0);
            } catch {
                if (!cancelled) {
                    setUsdtBal(0n);
                    setRaceBal(0n);
                }
            }
        }
        load();
        return () => {
            cancelled = true;
        };
    }, [wallet, configured, raceToken, usdtContract, router, rpcUrl, swapSuccess]);

    useEffect(() => {
        let cancelled = false;
        async function quote() {
            setQuoteOut(0n);
            if (!configured || !amountIn || Number(amountIn) <= 0) return;
            try {
                const wei = parseTokenAmount(String(amountIn), 18);
                // Sell: only ~96% RACE reaches the pair after transfer tax.
                const quoteIn =
                    direction === 'sell' ? expectedNetAfterTransferTax(wei, RACE_TRANSFER_TAX_BPS) : wei;
                const path = direction === 'buy' ? [usdtContract, raceToken] : [raceToken, usdtContract];
                const out = await getSwapQuote({ router, path, amountIn: quoteIn, rpcUrl });
                // Buy: wallet receives ~96% of router out (RACE tax on transfer from pair).
                const displayOut =
                    direction === 'buy' ? expectedNetAfterTransferTax(out, RACE_TRANSFER_TAX_BPS) : out;
                if (!cancelled) setQuoteOut(displayOut);
            } catch {
                if (!cancelled) setQuoteOut(0n);
            }
        }
        const t = setTimeout(quote, 250);
        return () => {
            cancelled = true;
            clearTimeout(t);
        };
    }, [amountIn, direction, configured, router, raceToken, usdtContract, rpcUrl]);

    const minOut = useMemo(() => applySlippage(quoteOut, slippageBps), [quoteOut, slippageBps]);

    const canSwap =
        Boolean(wallet) &&
        configured &&
        chainOk &&
        Number(amountIn) > 0 &&
        quoteOut > 0n &&
        !swapStep;

    const connectWallet = async () => {
        setWalletError(null);
        setConnecting(true);
        try {
            const address = await connectWalletOnNetwork();
            if (address) await linkWalletToAccount(address);
        } catch (e) {
            setWalletError(e?.message || 'Wallet connection was cancelled or failed.');
        } finally {
            setConnecting(false);
        }
    };

    const flipDirection = () => {
        setDirection((d) => (d === 'buy' ? 'sell' : 'buy'));
        setAmountIn('');
        setQuoteOut(0n);
        setSwapError(null);
        setSwapSuccess(null);
    };

    const submitSwap = async (e) => {
        e.preventDefault();
        setSwapError(null);
        setSwapSuccess(null);
        setLastTx('');

        if (!wallet) {
            setSwapError('Connect your crypto wallet first.');
            return;
        }
        if (!configured) {
            setSwapError('PancakeSwap router / RACE / USDT is not configured.');
            return;
        }
        if (Number(amountIn) <= 0) {
            setSwapError('Enter an amount greater than zero.');
            return;
        }

        try {
            setSwapStep('network');
            if (!chainOk) await switchNetwork();

            setSwapStep('approve');
            const common = {
                walletAddress: wallet,
                router,
                usdtContract,
                raceToken,
                slippageBps,
                deadlineSeconds,
                rpcUrl,
            };
            const txHash =
                direction === 'buy'
                    ? await buyRaceWithUsdt({ ...common, amountUsd: amountIn })
                    : await sellRaceForUsdt({ ...common, amountRace: amountIn });

            setLastTx(txHash);
            setSwapSuccess(
                direction === 'buy'
                    ? 'Swap complete — RACE is in your wallet (PancakeSwap pool).'
                    : 'Swap complete — USDT is in your wallet (PancakeSwap pool).',
            );
            setAmountIn('');
            setQuoteOut(0n);
        } catch (err) {
            setSwapError(friendlySwapError(err));
        } finally {
            setSwapStep(null);
        }
    };

    const activateId = (e) => {
        e.preventDefault();
        activateForm.post(route('swap.activate_id'), { preserveScroll: true });
    };

    const swapStepLabel =
        swapStep === 'network'
            ? 'Switching to BNB Smart Chain…'
            : swapStep === 'approve'
              ? 'Confirm approve + swap in your wallet (PancakeSwap)…'
              : null;

    const payBalance = direction === 'buy' ? usdtBal : raceBal;

    return (
        <AuthenticatedLayout
            pageTitle="Swap"
            memberSurface="race"
            showMobileFintechNav
            mobileFintechPageTitle="Swap"
        >
            <Head title="Swap" />

            <MemberPageShell contentClassName="space-y-5 sm:space-y-6">
                <MemberPageHero
                    kicker="PancakeSwap"
                    title="Swap USDT ↔ RACE"
                    variant="pdf"
                    icon="wallet"
                >
                    Trade on PancakeSwap Router inside this app. Liquidity stays in the Pancake pair —
                    no internal swap or stake contract.
                </MemberPageHero>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-2xl border border-violet-200/80 bg-gradient-to-br from-white to-violet-50/80 px-4 py-4 shadow-sm">
                        <p className="text-xs font-bold uppercase tracking-wide text-fintech-muted">
                            Wallet RACE
                        </p>
                        <p className="mt-1 font-mono text-2xl font-bold text-violet-700">
                            {formatTokenWei(raceBal, 18, 4)} RACE
                        </p>
                        <p className="mt-1 text-xs text-fintech-muted">
                            Live ~{livePriceUsd > 0 ? formatUsd(livePriceUsd) : '—'} / RACE
                        </p>
                    </div>
                    <div className="rounded-2xl border border-sky-200/80 bg-gradient-to-br from-white to-sky-50/80 px-4 py-4 shadow-sm">
                        <p className="text-xs font-bold uppercase tracking-wide text-fintech-muted">
                            Wallet USDT
                        </p>
                        <p className="mt-1 font-mono text-2xl font-bold text-sky-700">
                            {formatTokenWei(usdtBal, 18, 2)} USDT
                        </p>
                        <p className="mt-1 text-xs text-fintech-muted">BEP-20 · PancakeSwap route</p>
                    </div>
                </div>

                <div className="grid gap-4 sm:gap-6 lg:grid-cols-2">
                    <PanelCard title="1 · Connect wallet" icon="wallet">
                        <p className="mb-4 text-sm text-fintech-muted">
                            Connect on BNB Smart Chain. Swaps call the official PancakeSwap Router.
                        </p>
                        {wallet ? (
                            <div className="space-y-3">
                                <Web3NetworkBanner
                                    chainOk={chainOk}
                                    connectedLabel={connectedLabel}
                                    switchButtonLabel={switchButtonLabel}
                                    switching={networkSwitching}
                                    networkError={networkSwitchError}
                                    onSwitch={switchNetwork}
                                />
                                <p className="break-all font-mono text-sm font-medium text-fintech-ink">
                                    {wallet}
                                </p>
                            </div>
                        ) : (
                            <PrimaryButton
                                type="button"
                                className="w-full justify-center py-3 normal-case"
                                disabled={connecting || networkSwitching}
                                onClick={connectWallet}
                            >
                                {connecting || networkSwitching ? 'Opening wallet…' : 'Connect wallet'}
                            </PrimaryButton>
                        )}
                        {walletError && <p className="mt-3 text-sm text-red-400">{walletError}</p>}
                    </PanelCard>

                    <PanelCard title="2 · PancakeSwap" icon="wallet">
                        {!configured ? (
                            <p className="text-sm text-amber-600">
                                Router / RACE / USDT addresses missing. Set PANCAKE_ROUTER, RACE_TOKEN_CONTRACT,
                                USDT_CONTRACT_BEP20.
                            </p>
                        ) : (
                            <form onSubmit={submitSwap} className="space-y-1.5">
                                <div className="mb-2 flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setDirection('buy')}
                                        className={`flex-1 rounded-xl px-3 py-2 text-sm font-bold ${
                                            direction === 'buy'
                                                ? 'bg-violet-600 text-white'
                                                : 'bg-white/5 text-slate-300'
                                        }`}
                                    >
                                        Buy RACE
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setDirection('sell')}
                                        className={`flex-1 rounded-xl px-3 py-2 text-sm font-bold ${
                                            direction === 'sell'
                                                ? 'bg-violet-600 text-white'
                                                : 'bg-white/5 text-slate-300'
                                        }`}
                                    >
                                        Sell RACE
                                    </button>
                                </div>

                                <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                                    <div className="flex items-center justify-between">
                                        <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                            You pay
                                        </span>
                                        <button
                                            type="button"
                                            className="text-[11px] text-sky-300"
                                            onClick={() =>
                                                setAmountIn(formatTokenWei(payBalance, 18, direction === 'buy' ? 2 : 4))
                                            }
                                        >
                                            Max {formatTokenWei(payBalance, 18, direction === 'buy' ? 2 : 4)}
                                        </button>
                                    </div>
                                    <div className="mt-2 flex items-center gap-3">
                                        <input
                                            type="number"
                                            min="0"
                                            step="any"
                                            inputMode="decimal"
                                            value={amountIn}
                                            placeholder="0.00"
                                            disabled={Boolean(swapStep)}
                                            onChange={(e) => setAmountIn(e.target.value)}
                                            className="w-full border-0 bg-transparent p-0 text-3xl font-bold text-white placeholder:text-slate-500 focus:outline-none focus:ring-0"
                                        />
                                        <span className="inline-flex shrink-0 items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5">
                                            <span className="text-sm font-bold text-white">{paySymbol}</span>
                                        </span>
                                    </div>
                                </div>

                                <div className="relative flex h-2 justify-center">
                                    <button
                                        type="button"
                                        onClick={flipDirection}
                                        className="absolute -top-3 z-10 flex h-9 w-9 items-center justify-center rounded-xl border-4 border-[#0F172A] bg-violet-600 text-white shadow-md"
                                        aria-label="Flip direction"
                                    >
                                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2.5}>
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M7 16V4m0 0L3 8m4-4l4 4M17 8v12m0 0l4-4m-4 4l-4-4" />
                                        </svg>
                                    </button>
                                </div>

                                <div className="rounded-2xl border border-violet-400/30 bg-violet-500/10 p-4">
                                    <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                        You receive (Pancake quote)
                                    </span>
                                    <div className="mt-2 flex items-center gap-3">
                                        <span className="w-full truncate text-3xl font-bold text-violet-200">
                                            {quoteOut > 0n ? formatTokenWei(quoteOut, 18, 4) : '0.00'}
                                        </span>
                                        <span className="inline-flex shrink-0 items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5">
                                            <span className="text-sm font-bold text-white">{receiveSymbol}</span>
                                        </span>
                                    </div>
                                </div>

                                <div className="mt-1 space-y-1 rounded-xl border border-white/5 bg-white/5 px-3 py-2 text-xs text-slate-300">
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">
                                            Min received (4% tax + {slippageBps / 100}% slip)
                                        </span>
                                        <span className="font-medium">
                                            {minOut > 0n ? formatTokenWei(minOut, 18, 4) : '—'} {receiveSymbol}
                                        </span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-slate-400">Router</span>
                                        <a
                                            href={`${explorer}/address/${router}`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="truncate font-mono text-[11px] text-sky-300"
                                        >
                                            {router.slice(0, 8)}…{router.slice(-4)}
                                        </a>
                                    </div>
                                </div>

                                <PrimaryButton
                                    type="submit"
                                    className="mt-2 w-full justify-center rounded-2xl border-violet-500 bg-violet-600 py-3.5 text-base font-bold text-white hover:bg-violet-500 focus:ring-violet-400"
                                    disabled={!canSwap}
                                >
                                    {swapStep
                                        ? swapStepLabel
                                        : !wallet
                                          ? 'Connect wallet to swap'
                                          : `Swap on PancakeSwap`}
                                </PrimaryButton>

                                <p className="pt-1 text-center text-[11px] text-slate-400">
                                    On-chain via PancakeSwap · LP stays on Pancake · RACE 4% transfer tax may apply
                                </p>
                            </form>
                        )}

                        {swapError && <p className="mt-3 text-sm text-red-500">{swapError}</p>}
                        {swapSuccess && <p className="mt-3 text-sm text-emerald-600">{swapSuccess}</p>}
                        {lastTx && (
                            <p className="mt-2 text-xs">
                                <a
                                    href={`${explorer}/tx/${lastTx}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="font-mono text-sky-300 underline"
                                >
                                    View tx on BscScan
                                </a>
                            </p>
                        )}
                    </PanelCard>
                </div>

                {!raceCoin.id_active && (
                    <PanelCard title="Member ID (virtual Race Coin balance)" icon="investment">
                        <p className="mb-4 text-sm text-fintech-muted">
                            Optional: activate member ID using the older virtual Race Coin ledger (
                            {formatCoins(raceCoin.id_activation_coins)} RC / {formatUsd(raceCoin.id_activation_usd)}).
                            Pancake swaps credit your wallet BEP-20 RACE, not this virtual balance.
                        </p>
                        <form onSubmit={activateId}>
                            <PrimaryButton
                                type="submit"
                                className="w-full justify-center py-3 normal-case sm:w-auto"
                                disabled={!canActivateId}
                            >
                                {activateForm.processing
                                    ? 'Activating…'
                                    : `Activate ID (${formatCoins(raceCoin.id_activation_coins)} RC)`}
                            </PrimaryButton>
                        </form>
                        <p className="mt-3 text-xs text-fintech-muted">
                            Virtual balance: {formatCoins(raceCoin.balance)} RC
                        </p>
                    </PanelCard>
                )}
            </MemberPageShell>
        </AuthenticatedLayout>
    );
}
