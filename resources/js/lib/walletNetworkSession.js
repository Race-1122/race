import {
    BSC_MAINNET_CHAIN_ID,
    bindWalletProviderListeners,
    getActiveChainId,
    isChainIdMatch,
    isInAppWalletBrowser,
    isTrustWalletBrowser,
    parseChainIdDecimal,
    readWalletChainIdHex,
    subscribeWalletEvents,
    switchToConfiguredNetwork,
    trustDisclaimerHint,
    waitUntilChainConfirmed,
} from '@/lib/web3Deposit';
import {
    describeWalletProvider,
    getWalletProvider,
    restorePersistedProvider,
} from '@/lib/web3Wallet';

export const NETWORK_GATE_STATUS = {
    idle: 'idle',
    checking: 'checking',
    prompt: 'prompt',
    waiting: 'waiting',
    ready: 'ready',
    rejected: 'rejected',
    unsupported: 'unsupported',
};

const REJECTED_KEY = 'race.network.gate.rejected';
const AUTO_SWITCH_KEY = 'race.network.gate.autostarted';

const listeners = new Set();
let booted = false;
let switchInFlight = false;
let visibilityBound = false;
let providerUnsubscribe = () => {};

let snapshot = {
    status: NETWORK_GATE_STATUS.idle,
    message: 'Detecting Trust Wallet...',
    chainHex: '',
    chainId: 0,
    address: '',
    hasProvider: false,
    ready: false,
    readyWithoutWallet: false,
    providerName: '',
    hasEthereum: false,
    hasTrustWallet: false,
    waitingConfirm: false,
    manualHint: '',
};

function targetChainId() {
    return getActiveChainId() || BSC_MAINNET_CHAIN_ID;
}

function chainDisplayName(chainId) {
    if (chainId === 56) {
        return 'BNB Smart Chain';
    }
    if (chainId === 1) {
        return 'Ethereum';
    }
    if (chainId === 97) {
        return 'BSC Testnet';
    }
    return chainId ? `Chain ${chainId}` : 'Unknown';
}

function trustManualHint() {
    return `${trustDisclaimerHint()} Then choose BNB Smart Chain if still needed.`;
}

function messageFor(status, extra = snapshot) {
    const trust = isTrustWalletBrowser();
    switch (status) {
        case NETWORK_GATE_STATUS.checking:
            return trust ? 'Detecting Trust Wallet...' : 'Checking wallet network...';
        case NETWORK_GATE_STATUS.prompt:
            if (trust) {
                return 'Trust Wallet Disclaimer required';
            }
            return extra.chainId ? `Current network: ${chainDisplayName(extra.chainId)}` : 'Current network: Unknown';
        case NETWORK_GATE_STATUS.waiting:
            if (trust && !extra.waitingConfirm) {
                return 'Accept Trust Disclaimer, then confirm BNB...';
            }
            return extra.waitingConfirm
                ? 'Waiting for wallet confirmation...'
                : 'Switching to BNB Smart Chain...';
        case NETWORK_GATE_STATUS.ready:
            return extra.hasProvider ? 'BNB Smart Chain connected.' : extra.message || 'BNB Smart Chain connected.';
        case NETWORK_GATE_STATUS.rejected:
            return trust
                ? 'Disclaimer cancelled or incomplete. Tick Terms, Confirm, then Retry.'
                : 'Wallet connection failed. Retry.';
        case NETWORK_GATE_STATUS.unsupported:
            return extra.manualHint || (trust ? trustManualHint() : 'Wallet connection failed. Retry.');
        default:
            return trust ? 'Detecting Trust Wallet...' : 'Checking wallet network...';
    }
}

function providerDebug() {
    const info = describeWalletProvider();
    return {
        providerName: info.name || 'None',
        hasEthereum: Boolean(info.hasEthereum),
        hasTrustWallet: Boolean(info.hasTrustWallet),
    };
}

function preserveAppUrl() {
    if (typeof window === 'undefined') {
        return;
    }
    try {
        window.sessionStorage.setItem('race.app.url', window.location.href);
        window.history.replaceState(window.history.state, document.title, window.location.href);
    } catch {
        // private mode / restricted WebView
    }
}

function emit() {
    const current = { ...snapshot };
    listeners.forEach((fn) => {
        try {
            fn(current);
        } catch {
            // ignore subscriber errors
        }
    });
}

function setSnapshot(patch) {
    const next = { ...snapshot, ...patch };
    if (patch.message === undefined && (patch.status || patch.chainId !== undefined || patch.waitingConfirm !== undefined)) {
        next.message = messageFor(next.status, next);
    }
    next.ready = next.status === NETWORK_GATE_STATUS.ready;
    snapshot = next;
    emit();
}

function fromLive(live, extra = {}) {
    return {
        hasProvider: live.hasProvider,
        chainHex: live.chainHex || '',
        chainId: live.chainId || 0,
        providerName: live.providerName || '',
        hasEthereum: Boolean(live.hasEthereum),
        hasTrustWallet: Boolean(live.hasTrustWallet),
        readyWithoutWallet: false,
        manualHint: '',
        ...extra,
    };
}

function readRejectedFlag() {
    if (typeof window === 'undefined') {
        return false;
    }
    try {
        return window.sessionStorage.getItem(REJECTED_KEY) === '1';
    } catch {
        return false;
    }
}

function writeSessionFlag(key, value) {
    if (typeof window === 'undefined') {
        return;
    }
    try {
        if (value) {
            window.sessionStorage.setItem(key, '1');
        } else {
            window.sessionStorage.removeItem(key);
        }
    } catch {
        // private mode
    }
}

function writeRejectedFlag(value) {
    writeSessionFlag(REJECTED_KEY, value);
}

async function waitForProvider(maxMs = 2500) {
    const started = Date.now();
    restorePersistedProvider();
    while (Date.now() - started < maxMs) {
        if (getWalletProvider()) {
            bindWalletProviderListeners();
            return true;
        }
        await new Promise((resolve) => window.setTimeout(resolve, 200));
    }
    return Boolean(getWalletProvider());
}

async function readLiveNetwork() {
    restorePersistedProvider();
    const debug = providerDebug();
    const hasProvider = Boolean(getWalletProvider());
    if (!hasProvider) {
        return { hasProvider: false, chainHex: '', chainId: 0, match: false, ...debug };
    }
    bindWalletProviderListeners();
    const chainHex = await readWalletChainIdHex().catch(() => '');
    const chainId = parseChainIdDecimal(chainHex);
    return {
        hasProvider: true,
        chainHex,
        chainId,
        match: isChainIdMatch(chainHex, targetChainId()),
        ...debug,
    };
}

export function getNetworkGateSnapshot() {
    return { ...snapshot };
}

export function subscribeNetworkGate(fn) {
    listeners.add(fn);
    fn({ ...snapshot });
    return () => listeners.delete(fn);
}

export function isNetworkGateReady() {
    return snapshot.status === NETWORK_GATE_STATUS.ready;
}

export async function inspectWalletNetwork() {
    preserveAppUrl();
    setSnapshot({ status: NETWORK_GATE_STATUS.checking, readyWithoutWallet: false });
    const found = await waitForProvider();
    const live = await readLiveNetwork();

    if (!found && !live.hasProvider) {
        setSnapshot({
            status: NETWORK_GATE_STATUS.ready,
            hasProvider: false,
            chainHex: '',
            chainId: 0,
            readyWithoutWallet: true,
            ...providerDebug(),
        });
        return getNetworkGateSnapshot();
    }

    if (live.match) {
        writeRejectedFlag(false);
        writeSessionFlag(AUTO_SWITCH_KEY, false);
        setSnapshot({
            status: NETWORK_GATE_STATUS.ready,
            ...fromLive(live),
        });
        return getNetworkGateSnapshot();
    }

    if (readRejectedFlag()) {
        setSnapshot({
            status: NETWORK_GATE_STATUS.rejected,
            ...fromLive(live),
        });
        return getNetworkGateSnapshot();
    }

    setSnapshot({
        status: NETWORK_GATE_STATUS.prompt,
        manualHint: isTrustWalletBrowser() ? trustManualHint() : '',
        ...fromLive(live),
    });
    return getNetworkGateSnapshot();
}

export async function requestBscNetworkSwitch() {
    if (switchInFlight) {
        return getNetworkGateSnapshot();
    }
    switchInFlight = true;
    writeRejectedFlag(false);
    // Always allow Retry after Trust Disclaimer / Cancel — do not stay locked out.
    writeSessionFlag(AUTO_SWITCH_KEY, false);
    preserveAppUrl();
    setSnapshot({
        status: NETWORK_GATE_STATUS.waiting,
        waitingConfirm: false,
        message: isTrustWalletBrowser()
            ? 'Accept Trust Disclaimer, then confirm BNB...'
            : 'Switching to BNB Smart Chain...',
    });

    const manualHint = isTrustWalletBrowser()
        ? trustManualHint()
        : 'Open the wallet network list, choose BNB Smart Chain, then tap Retry.';

    try {
        const before = await readLiveNetwork();
        if (before.match) {
            setSnapshot({
                status: NETWORK_GATE_STATUS.ready,
                waitingConfirm: false,
                ...fromLive(before),
            });
            return getNetworkGateSnapshot();
        }

        await switchToConfiguredNetwork(targetChainId());
        setSnapshot({
            status: NETWORK_GATE_STATUS.waiting,
            waitingConfirm: true,
            message: 'Waiting for wallet confirmation...',
            ...fromLive(before),
        });
        const waited = await waitUntilChainConfirmed(targetChainId());
        const afterId = parseChainIdDecimal(waited.chainHex);
        const after = { ...before, chainHex: waited.chainHex, chainId: afterId, match: waited.confirmed };
        if (!waited.confirmed) {
            setSnapshot({
                status: NETWORK_GATE_STATUS.unsupported,
                waitingConfirm: false,
                manualHint,
                ...fromLive(after),
            });
            return getNetworkGateSnapshot();
        }

        setSnapshot({
            status: NETWORK_GATE_STATUS.ready,
            waitingConfirm: false,
            ...fromLive(after),
        });
        return getNetworkGateSnapshot();
    } catch (error) {
        const after = await readLiveNetwork().catch(() => ({
            hasProvider: Boolean(getWalletProvider()),
            chainHex: '',
            chainId: 0,
            match: false,
            ...providerDebug(),
        }));
        if (after.match) {
            writeRejectedFlag(false);
            setSnapshot({
                status: NETWORK_GATE_STATUS.ready,
                waitingConfirm: false,
                ...fromLive(after),
            });
            return getNetworkGateSnapshot();
        }

        const rejected = /cancel|reject|denied/i.test(String(error?.message || ''));
        // Trust Disclaimer Cancel must not permanently lock the gate — user must Retry.
        if (isTrustWalletBrowser()) {
            writeRejectedFlag(false);
            writeSessionFlag(AUTO_SWITCH_KEY, false);
            setSnapshot({
                ...fromLive(after),
                status: NETWORK_GATE_STATUS.prompt,
                waitingConfirm: false,
                manualHint,
            });
            return getNetworkGateSnapshot();
        }
        writeRejectedFlag(rejected);
        setSnapshot({
            status: rejected ? NETWORK_GATE_STATUS.rejected : NETWORK_GATE_STATUS.unsupported,
            waitingConfirm: false,
            manualHint: rejected ? '' : manualHint,
            ...fromLive(after),
        });
        return getNetworkGateSnapshot();
    } finally {
        switchInFlight = false;
    }
}

async function onProviderSignal() {
    if (switchInFlight) {
        return;
    }
    restorePersistedProvider();
    bindWalletProviderListeners();
    const live = await readLiveNetwork();
    if (!live.hasProvider) {
        setSnapshot({
            status: NETWORK_GATE_STATUS.ready,
            hasProvider: false,
            chainHex: '',
            chainId: 0,
            readyWithoutWallet: true,
            ...providerDebug(),
        });
        return;
    }
    if (live.match) {
        writeRejectedFlag(false);
        setSnapshot({
            status: NETWORK_GATE_STATUS.ready,
            ...fromLive(live),
        });
        return;
    }
    if (snapshot.status === NETWORK_GATE_STATUS.rejected || readRejectedFlag()) {
        setSnapshot({
            status: NETWORK_GATE_STATUS.rejected,
            ...fromLive(live),
        });
        return;
    }
    setSnapshot({
        status: NETWORK_GATE_STATUS.prompt,
        ...fromLive(live),
    });
}

function bindPageLifecycle() {
    if (visibilityBound || typeof window === 'undefined') {
        return;
    }
    visibilityBound = true;

    const recheck = () => {
        onProviderSignal().catch(() => {});
    };

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            recheck();
        }
    });
    window.addEventListener('pageshow', recheck);
    window.addEventListener('focus', recheck);
}

export async function bootNetworkGate() {
    if (booted) {
        return getNetworkGateSnapshot();
    }
    booted = true;
    bindPageLifecycle();
    providerUnsubscribe();
    providerUnsubscribe = subscribeWalletEvents({
        onChainChanged: () => {
            onProviderSignal().catch(() => {});
        },
        onAccountsChanged: () => {
            onProviderSignal().catch(() => {});
        },
        onConnect: () => {
            onProviderSignal().catch(() => {});
        },
        onDisconnect: () => {
            onProviderSignal().catch(() => {});
        },
    });

    const snap = await inspectWalletNetwork();
    // Trust Wallet latest builds show a native Disclaimer before any wallet RPC.
    // Auto-firing wallet_switch on page load races that popup and looks like a site bug.
    // Wait for the user to accept Terms, then tap Retry / Connect.
    if (isTrustWalletBrowser()) {
        if (snap.hasProvider && !snap.ready) {
            setSnapshot({
                ...fromLive({
                    hasProvider: snap.hasProvider,
                    chainHex: snap.chainHex,
                    chainId: snap.chainId,
                    providerName: snap.providerName,
                    hasEthereum: snap.hasEthereum,
                    hasTrustWallet: snap.hasTrustWallet,
                }),
                status: NETWORK_GATE_STATUS.prompt,
                manualHint: trustManualHint(),
                message: 'Trust Wallet Disclaimer required',
            });
        }
        return getNetworkGateSnapshot();
    }

    let autoStarted = false;
    try {
        autoStarted = window.sessionStorage.getItem(AUTO_SWITCH_KEY) === '1';
    } catch {
        autoStarted = false;
    }
    if (
        snap.hasProvider
        && !snap.ready
        && snap.status !== NETWORK_GATE_STATUS.rejected
        && isInAppWalletBrowser()
        && !autoStarted
    ) {
        writeSessionFlag(AUTO_SWITCH_KEY, true);
        await requestBscNetworkSwitch();
    }
    return getNetworkGateSnapshot();
}
