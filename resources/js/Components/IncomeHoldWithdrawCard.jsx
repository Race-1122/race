import MemberAlert from '@/Components/Member/MemberAlert';
import { useWalletNetwork } from '@/hooks/useWalletNetwork';
import { configureWeb3Network } from '@/lib/web3Deposit';
import {
    readIncomeHoldMeta,
    readIncomeHoldQuote,
    withdrawIncomeHold,
} from '@/lib/web3IncomeHold';
import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function IncomeHoldWithdrawCard({
    raceLevelIncome = null,
}) {
    const { auth, blockchain } = usePage().props;
    const incomeHold = (
        blockchain?.web3?.income_hold ||
        blockchain?.contracts?.income_hold ||
        blockchain?.token?.contracts?.income_hold ||
        ''
    ).trim();
    const usdtFromConfig = (
        blockchain?.web3?.usdt_contract ||
        blockchain?.contracts?.usdt ||
        ''
    ).trim();
    const rpcUrl = blockchain?.rpc_url || '';
    const expectedChainId = Number(blockchain?.chain_id || (blockchain?.is_testnet ? 97 : 56));
    const boundWallet = auth?.user?.wallet_address || '';

    const { networkError, connectedLabel } = useWalletNetwork({ expectedChainId });

    const [walletAddress, setWalletAddress] = useState(boundWallet);
    const [quote, setQuote] = useState({ raceAmount: 0n, valueUsdt: 0n, feeUsdt: 0n, teamRace: 0n, netRace: 0n });
    const [meta, setMeta] = useState({ adminWallet: '', usdt: '', oneUsdt: 0n });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [status, setStatus] = useState('');

    useEffect(() => {
        configureWeb3Network({
            chainId: expectedChainId,
            usdtContract: usdtFromConfig,
            rpcUrl,
        });
    }, [expectedChainId, usdtFromConfig, rpcUrl]);

    const loadQuote = async (wallet) => {
        if (!incomeHold || !wallet) {
            setQuote({ raceAmount: 0n, valueUsdt: 0n, feeUsdt: 0n, teamRace: 0n, netRace: 0n });
            return;
        }
        const [nextQuote, nextMeta] = await Promise.all([
            readIncomeHoldQuote({ incomeHold, walletAddress: wallet, rpcUrl }),
            readIncomeHoldMeta({ incomeHold, rpcUrl }),
        ]);
        setQuote(nextQuote);
        setMeta(nextMeta);
    };

    useEffect(() => {
        loadQuote(walletAddress).catch(() => {});
    }, [incomeHold, walletAddress, rpcUrl]);

    const raceIndexed = Number(raceLevelIncome?.race ?? 0);
    const usdtIndexed = Number(raceLevelIncome?.usdt ?? 0);

    if (!incomeHold) {
        return (
            <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                Income wallet is not set up yet. After deploy you can bring income to your wallet with one tap.
            </div>
        );
    }

    return (
        <div className="space-y-3">
            {walletAddress ? (
                <p className="text-[11px] text-fintech-muted">
                    Wallet {walletAddress.slice(0, 6)}…{walletAddress.slice(-4)}
                    {connectedLabel ? ` · ${connectedLabel}` : ''}
                </p>
            ) : null}

            {error ? <MemberAlert variant="error">{error}</MemberAlert> : null}
            {status ? <MemberAlert variant="success">{status}</MemberAlert> : null}
            {networkError ? <MemberAlert variant="warning">{networkError}</MemberAlert> : null}

            {raceIndexed > 0 ? (
                <p className="text-[11px] text-fintech-muted">
                    Indexed level income: {raceIndexed.toLocaleString(undefined, { maximumFractionDigits: 4 })} RACE ≈{' '}
                    {usdtIndexed.toLocaleString(undefined, { style: 'currency', currency: 'USD' })}
                </p>
            ) : null}
        </div>
    );
}
