import PrimaryButton from '@/Components/PrimaryButton';
import PanelCard from '@/Components/PanelCard';
import TextInput from '@/Components/TextInput';
import MemberPageHero from '@/Components/Member/MemberPageHero';
import MemberPageShell from '@/Components/Member/MemberPageShell';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useWalletNetwork } from '@/hooks/useWalletNetwork';
import {
    assertOfficialUsdtContract,
    sendUsdtTransfer,
    verifyOnChainDeposit,
    waitForConfirmations,
} from '@/lib/web3Deposit';
import { linkWalletToAccount } from '@/lib/walletAccountLink';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function formatUsd(n, withSign = false) {
    const v = Number(n);
    if (Number.isNaN(v)) {
        return '—';
    }
    const abs = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Math.abs(v));
    if (!withSign) return abs;
    if (v > 0) return `+ ${abs}`;
    if (v < 0) return `− ${abs}`;
    return abs;
}

function formatRowDate(createdAt) {
    if (!createdAt) return '—';
    return new Date(createdAt).toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function Deposit({
    depositAddress = { address: '', network: '', usdt_contract: '', chain_id: 56, min_confirmations: 12 },
    deposit_entries = [],
}) {
    const { auth, blockchain } = usePage().props;
    const wallet = auth.user?.wallet_address;
    const balanceUsd = auth.user?.balance_usd ?? auth.user?.user_wallet?.balance_usd;

    const {
        chainOk,
        switching: networkSwitching,
        networkError: networkSwitchError,
        switchNetwork,
        connectWallet: connectWalletOnNetwork,
    } = useWalletNetwork({ expectedChainId: depositAddress?.chain_id ?? blockchain?.chain_id });

    const [connecting, setConnecting] = useState(false);
    const [walletError, setWalletError] = useState(null);
    const [amountUsd, setAmountUsd] = useState('');
    const [depositStep, setDepositStep] = useState(null);
    const [depositError, setDepositError] = useState(null);
    const [depositSuccess, setDepositSuccess] = useState(null);

    const treasuryAddress = (depositAddress?.address ?? '').trim();
    const treasuryNetwork = (depositAddress?.network ?? '').trim();
    const usdtContract = (depositAddress?.usdt_contract ?? '').trim();
    const minConfirmations = Number(depositAddress?.min_confirmations ?? 12);
    let officialUsdtConfigured = false;
    try {
        assertOfficialUsdtContract(usdtContract);
        officialUsdtConfigured = true;
    } catch {
        officialUsdtConfigured = false;
    }
    const canDeposit = !wallet
        ? !connecting && !networkSwitching
        : Boolean(treasuryAddress) &&
          officialUsdtConfigured &&
          Number(amountUsd) >= 1 &&
          !depositStep &&
          !connecting &&
          !networkSwitching;

    const connectWallet = async () => {
        setWalletError(null);
        if (typeof window === 'undefined') {
            setWalletError('Open this page in a browser, then connect any crypto wallet.');
            return;
        }
        setConnecting(true);
        try {
            const address = await connectWalletOnNetwork();
            await linkWalletToAccount(address);
        } catch (e) {
            setWalletError(
                e?.message || 'Wallet connection was cancelled or failed.',
            );
        } finally {
            setConnecting(false);
        }
    };

    const submitCryptoDeposit = async (e) => {
        e.preventDefault();
        setDepositError(null);
        setDepositSuccess(null);

        if (!wallet) {
            await connectWallet();
            return;
        }
        if (!treasuryAddress || !officialUsdtConfigured) {
            setDepositError('Only official BEP20 USDT deposits are enabled.');
            return;
        }
        if (Number(amountUsd) < 1) {
            setDepositError('Minimum deposit is $1 USDT.');
            return;
        }

        try {
            setDepositStep('network');
            if (!chainOk) {
                await switchNetwork();
            }

            setDepositStep('sending');
            const txHash = await sendUsdtTransfer({
                from: wallet,
                to: treasuryAddress,
                usdtContract,
                amountUsd,
            });

            setDepositStep('confirming');
            await waitForConfirmations(txHash, { minConfirmations });

            setDepositStep('crediting');
            const result = await verifyOnChainDeposit({
                txHash,
                amountUsd: Number(amountUsd),
                verifyUrl: route('deposits.verify_onchain'),
            });

            setDepositSuccess(result.message || 'Deposit credited to your wallet.');
            setAmountUsd('');
            router.reload({ preserveScroll: true });
        } catch (err) {
            setDepositError(err?.message || 'Deposit could not be completed.');
        } finally {
            setDepositStep(null);
        }
    };

    const depositStepLabel =
        depositStep === 'network'
            ? 'Switching to BNB Smart Chain…'
            : depositStep === 'sending'
              ? 'Confirm USDT transfer in your wallet…'
              : depositStep === 'confirming'
                ? `Waiting for ${minConfirmations} block confirmations…`
                : depositStep === 'crediting'
                  ? 'Crediting your wallet balance…'
                  : null;

    return (
        <AuthenticatedLayout
            pageTitle="Deposit"
            memberSurface="race"
            showMobileFintechNav
            mobileFintechPageTitle="Wallet"
        >
            <Head title="Deposit" />

            <MemberPageShell contentClassName="space-y-5 sm:space-y-6">
                <MemberPageHero kicker="Wallet" title="Deposit USDT" variant="pdf" icon="wallet" />

                {balanceUsd != null && (
                    <div className="rounded-2xl border border-sky-200/80 bg-gradient-to-br from-white to-sky-50/80 px-4 py-4 shadow-sm">
                        <p className="text-xs font-bold uppercase tracking-wide text-fintech-muted">
                            Wallet balance
                        </p>
                        <p className="mt-1 font-mono text-2xl font-bold text-fintech-ink">
                            {formatUsd(balanceUsd)}
                        </p>
                    </div>
                )}

                <div>
                    <PanelCard title="Deposit USDT from wallet" icon="deposit">
                        {!treasuryAddress || !officialUsdtConfigured ? (
                            <p className="text-sm text-amber-600">
                                Deposit is not available — treasury address or official USDT contract is not
                                configured.
                            </p>
                        ) : (
                            <form onSubmit={submitCryptoDeposit} className="space-y-4">
                                <div>
                                    <label className="block text-sm font-medium text-fintech-ink">
                                        Amount (USDT)
                                    </label>
                                    <TextInput
                                        type="number"
                                        min="1"
                                        step="0.01"
                                        value={amountUsd}
                                        className="mt-2 block w-full"
                                        placeholder="100"
                                        disabled={Boolean(depositStep)}
                                        onChange={(e) => setAmountUsd(e.target.value)}
                                    />
                                    <p className="mt-1 text-xs text-fintech-muted">
                                        Minimum $1 · Only official configured USDT contract · Flash / fake USDT rejected ·{' '}
                                        {minConfirmations} confirmations
                                    </p>
                                </div>

                                <PrimaryButton
                                    type="submit"
                                    className="w-full justify-center border-emerald-500 bg-emerald-600 py-3 text-white hover:bg-emerald-500 focus:ring-emerald-400"
                                    disabled={!canDeposit}
                                >
                                    {connecting || networkSwitching
                                        ? 'Opening wallet…'
                                        : depositStep
                                          ? depositStepLabel
                                          : !wallet
                                            ? 'Connect wallet to deposit'
                                            : 'Deposit USDT from wallet'}
                                </PrimaryButton>
                            </form>
                        )}

                        {(walletError || networkSwitchError) && (
                            <p className="mt-3 text-sm text-red-400">{walletError || networkSwitchError}</p>
                        )}
                        {depositError && (
                            <p className="mt-3 text-sm text-red-500">{depositError}</p>
                        )}
                        {depositSuccess && (
                            <p className="mt-3 text-sm text-emerald-600">{depositSuccess}</p>
                        )}
                    </PanelCard>
                </div>

                <PanelCard title="Recent deposits" icon="transactions" className="mt-6">
                    {deposit_entries.length === 0 ? (
                        <p className="rx-empty">No deposit transactions yet.</p>
                    ) : (
                        <div className="space-y-3">
                            <div className="flex items-center justify-between gap-3 text-xs text-fintech-muted">
                                <p>Showing latest {deposit_entries.length} deposit transaction(s).</p>
                                <Link
                                    href={route('transactions', { income: 'wallet' })}
                                    className="font-semibold text-[#2563EB] hover:underline"
                                >
                                    View full ledger →
                                </Link>
                            </div>
                            <div className="overflow-hidden rounded-xl border border-fintech-line bg-white">
                                <ul className="divide-y divide-fintech-line">
                                    {deposit_entries.map((r) => (
                                        <li
                                            key={r.id}
                                            className="flex items-start justify-between gap-3 px-4 py-3"
                                        >
                                            <div className="min-w-0">
                                                <p className="text-xs font-semibold text-fintech-ink">
                                                    {formatRowDate(r.created_at)}
                                                </p>
                                                <p className="mt-0.5 text-xs text-fintech-muted">
                                                    {r.detail ?? 'Wallet deposit'}
                                                </p>
                                            </div>
                                            <p className="shrink-0 font-mono text-sm font-bold text-emerald-600">
                                                {formatUsd(r.amount_usd, true)}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                </PanelCard>
            </MemberPageShell>
        </AuthenticatedLayout>
    );
}
