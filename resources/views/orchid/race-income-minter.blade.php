@php
    $minter = $minter_address ?? '';
    $readyAddress = is_string($minter) && preg_match('/^0x[a-f0-9]{40}$/i', $minter);
    $calldata = $set_minter_calldata ?? '';
@endphp

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('Live status') }}</h5>
    <p class="mb-1 small"><strong>{{ __('Mint on withdraw') }}:</strong>
        @if (!empty($mint_on_withdraw)) <span class="text-success">ON</span>
        @else <span class="text-danger">OFF</span> @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('Service ready') }}:</strong>
        @if (!empty($service_ready)) <span class="text-success">yes</span>
        @else <span class="text-danger">no</span> @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('Income minter EOA') }}:</strong>
        @if ($readyAddress) <code class="user-select-all">{{ $minter }}</code>
        @else <span class="text-danger">{{ __('Not set') }}</span> @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('On-chain isMinter') }}:</strong>
        @if ($is_minter === true) <span class="text-success font-weight-bold">true</span>
        @elseif ($is_minter === false) <span class="text-danger font-weight-bold">false</span>
        @else <span class="text-muted">—</span> @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('Minter BNB') }}:</strong> {{ $bnb_balance !== null ? $bnb_balance.' BNB' : '—' }}</p>
    <p class="mb-1 small"><strong>{{ __('RaceCoin') }}:</strong> <code class="user-select-all">{{ $race_token ?? '' }}</code></p>
    <p class="mb-0 small"><strong>{{ __('Multisig') }}:</strong> <code class="user-select-all">{{ $multisig_contract ?? '' }}</code></p>
    @if (!empty($rpc_error))
        <p class="small text-danger mt-2 mb-0">{{ __('RPC') }}: {{ $rpc_error }}</p>
    @endif
</div>

@if ($readyAddress && $calldata)
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('Income minter — Multisig setMinter (same as ICO / claim on-off)') }}</h5>
    <p class="small mb-2">
        {{ __('Inner call') }}: <code>setMinter({{ $minter }}, true)</code>
    </p>
    <p class="small mb-3" style="word-break:break-all"><code class="user-select-all">{{ $calldata }}</code></p>
    <ol class="small mb-3">
        <li>{{ __('TokenPocket mein Multisig signer wallet (5 mein se). Admin USDT wallet mat use karo.') }}</li>
        <li>{{ __('1. Submit — To address Multisig hona chahiye. NUMBER auto nikalega (events nahi).') }}</li>
        <li>{{ __('2nd / 3rd signer: 2. Confirm — same Multisig id. 3/3 pe execute.') }}</li>
        <li>{{ __('Refresh status → isMinter = true.') }}</li>
    </ol>
    <p class="small mb-2"><strong>{{ __('Signers') }}:</strong></p>
    <ul class="small mb-3">
        @foreach ($multisig_signers ?? [] as $signer)
            <li><code class="user-select-all">{{ $signer }}</code></li>
        @endforeach
    </ul>

    <p class="small mb-2" id="race-minter-status"></p>
    <p class="small mb-3">
        <strong>{{ __('Multisig id') }}:</strong>
        <span class="fs-4 fw-bold user-select-all" id="race-minter-id-big">—</span>
    </p>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-success" id="race-minter-submit"
            data-multisig="{{ $multisig_contract }}"
            data-token="{{ $race_token }}"
            data-calldata="{{ $calldata }}">{{ __('1. Submit setMinter') }}</button>
        <button type="button" class="btn btn-outline-success" id="race-minter-confirm"
            data-multisig="{{ $multisig_contract }}"
            data-token="{{ $race_token }}"
            data-calldata="{{ $calldata }}">{{ __('2. Confirm setMinter') }}</button>
        <a class="btn btn-outline-primary" href="{{ url()->current() }}">{{ __('Refresh status') }}</a>
    </div>
</div>
@elseif (!$readyAddress)
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <p class="small text-danger mb-0">{{ __('Set RACE_INCOME_MINTER_ADDRESS on server .env') }}</p>
</div>
@endif

<script>
(function () {
    // Same Multisig helpers as ico-contract-setup (claim on/off) — getTransaction scan, no events.
    const FALLBACK_MULTISIG = '0x81BffBF2C2a258E662e9df0a0f6e34150b33F3df';
    let multiSig = String(@json($multisig_contract ?? '') || '').trim();
    if (!/^0x[a-fA-F0-9]{40}$/.test(multiSig)) multiSig = FALLBACK_MULTISIG;
    multiSig = multiSig.toLowerCase();

    const raceToken = String(@json($race_token ?? '') || '').toLowerCase();
    const setMinterData = String(@json($calldata ?? '') || '').toLowerCase();
    const signers = (@json($multisig_signers ?? [])).map((s) => String(s).toLowerCase());
    const chainId = 56;
    const storageKey = 'race.multisig.setIncomeMinter.txId';

    const submitSel = 'c6427474';
    const confirmSel = 'c01a8c84';
    const txCountSel = '0xb77bf600';
    const isSignerSel = '0x7df73e27';
    const getTxSel = '0x33ea3dc8';
    const isConfirmedSel = '0x80f59a65';

    const statusEl = document.getElementById('race-minter-status');
    const bigId = document.getElementById('race-minter-id-big');
    const submitBtn = document.getElementById('race-minter-submit');
    const confirmBtn = document.getElementById('race-minter-confirm');

    function setStatus(msg) {
        if (statusEl) statusEl.textContent = msg || '';
    }

    function showId(id) {
        if (id === null || id === undefined || id === '') return;
        const n = String(id);
        if (bigId) bigId.textContent = n;
        try { localStorage.setItem(storageKey, n); } catch (e) {}
    }

    try {
        const saved = localStorage.getItem(storageKey);
        if (saved) showId(saved);
    } catch (e) {}

    function padAddr(addr) {
        return String(addr).toLowerCase().replace(/^0x/, '').padStart(64, '0');
    }
    function padUint(dec) {
        return BigInt(dec).toString(16).padStart(64, '0');
    }
    function toHexChain(id) {
        return '0x' + Number(id).toString(16);
    }

    function encodeSubmit(to, innerData) {
        const raw = String(innerData || '').replace(/^0x/, '');
        const padBytes = (32 - ((raw.length / 2) % 32)) % 32;
        return '0x' + submitSel + padAddr(to) + padUint(0) + padUint(96) + padUint(raw.length / 2) + raw + '00'.repeat(padBytes);
    }

    async function ethCall(to, data) {
        return window.ethereum.request({
            method: 'eth_call',
            params: [{ to: to, data: data }, 'latest'],
        });
    }

    async function waitReceipt(hash) {
        for (let i = 0; i < 40; i++) {
            const receipt = await window.ethereum.request({
                method: 'eth_getTransactionReceipt',
                params: [hash],
            });
            if (receipt && receipt.blockNumber) {
                if (receipt.status === '0x0') {
                    throw new Error('Transaction reverted: ' + hash);
                }
                return receipt;
            }
            await new Promise((r) => setTimeout(r, 3000));
        }
        throw new Error('Transaction not mined yet. Wait and tap Confirm. Hash: ' + hash);
    }

    // Find pending Multisig tx by matching inner calldata + target (no events)
    async function findPendingSetMinter() {
        if (!raceToken || !setMinterData) return null;
        const want = setMinterData.toLowerCase();
        const target = raceToken.toLowerCase();
        const count = BigInt(await ethCall(multiSig, txCountSel) || '0x0');
        let best = null;
        for (let i = 0; i < count; i++) {
            const raw = await ethCall(multiSig, getTxSel + padUint(i));
            if (!raw || raw === '0x') continue;
            const hex = raw.replace(/^0x/, '').padStart(320, '0');
            const to = ('0x' + hex.slice(24, 64)).toLowerCase();
            const executed = BigInt('0x' + hex.slice(192, 256)) === 1n;
            const confirmations = Number(BigInt('0x' + hex.slice(256, 320)));
            const dataOffset = Number(BigInt('0x' + hex.slice(128, 192))) * 2;
            const dataLen = Number(BigInt('0x' + hex.slice(dataOffset, dataOffset + 64))) * 2;
            const data = '0x' + hex.slice(dataOffset + 64, dataOffset + 64 + dataLen);
            if (executed) continue;
            if (to !== target) continue;
            if (data.toLowerCase() !== want) continue;
            if (!best || confirmations > best.confirmations) {
                best = { id: String(i), confirmations: confirmations };
            }
        }
        return best;
    }

    async function connectSigner() {
        if (!window.ethereum) {
            throw new Error('TokenPocket / MetaMask not found. Open this page inside TokenPocket DApp browser.');
        }
        if (!multiSig) throw new Error('Multisig address missing.');
        if (!raceToken || !setMinterData) throw new Error('RaceCoin / setMinter calldata missing.');

        const accounts = await window.ethereum.request({ method: 'eth_requestAccounts' });
        const from = (accounts[0] || '').toLowerCase();
        const liveChain = await window.ethereum.request({ method: 'eth_chainId' });
        if (Number(liveChain) !== chainId) {
            await window.ethereum.request({
                method: 'wallet_switchEthereumChain',
                params: [{ chainId: toHexChain(chainId) }],
            });
        }
        if (!signers.includes(from)) {
            throw new Error('Wrong wallet. Switch to one of the 5 Multisig signer accounts. Not admin USDT wallet.');
        }
        const signerHex = await window.ethereum.request({
            method: 'eth_call',
            params: [{ to: multiSig, data: isSignerSel + padAddr(from) }, 'latest'],
        });
        if (BigInt(signerHex || '0x0') !== 1n) {
            throw new Error('This account is not a Multisig signer on-chain. Multisig=' + multiSig);
        }
        return from;
    }

    if (submitBtn) {
        submitBtn.addEventListener('click', async function () {
            try {
                const from = await connectSigner();
                const pending = await findPendingSetMinter();
                if (pending) {
                    showId(pending.id);
                    setStatus(
                        'setMinter already submitted. Do NOT submit again. Switch signer and tap Confirm. Multisig id: ' +
                        pending.id + ' · confirms ' + pending.confirmations + '/3'
                    );
                    return;
                }
                setStatus('Submitting setMinter to Multisig… To must be ' + multiSig);
                const hash = await window.ethereum.request({
                    method: 'eth_sendTransaction',
                    params: [{
                        from: from,
                        to: multiSig,
                        data: encodeSubmit(raceToken, setMinterData),
                    }],
                });
                setStatus('Waiting for submit to mine… ' + hash);
                await waitReceipt(hash);
                const created = await findPendingSetMinter();
                const txId = created ? created.id : '';
                if (txId !== '') showId(txId);
                setStatus('Submit OK. Switch to a SECOND Multisig signer and tap Confirm setMinter. id: ' + txId);
            } catch (err) {
                setStatus(err && err.message ? err.message : String(err));
            }
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', async function () {
            try {
                const from = await connectSigner();
                const pending = await findPendingSetMinter();
                if (!pending) {
                    throw new Error('No pending setMinter Multisig tx. Tap Submit setMinter first.');
                }
                const alreadyHex = await ethCall(
                    multiSig,
                    isConfirmedSel + padUint(pending.id) + padAddr(from),
                );
                if (BigInt(alreadyHex || '0x0') === 1n) {
                    showId(pending.id);
                    setStatus(
                        'This wallet already confirmed. Switch to another signer and Confirm again. id: ' +
                        pending.id + ' · ' + pending.confirmations + '/3'
                    );
                    return;
                }
                showId(pending.id);
                setStatus('Confirming setMinter… id ' + pending.id + ' · ' + pending.confirmations + '/3');
                const hash = await window.ethereum.request({
                    method: 'eth_sendTransaction',
                    params: [{
                        from: from,
                        to: multiSig,
                        data: '0x' + confirmSel + padUint(pending.id),
                    }],
                });
                setStatus('Waiting for confirm to mine… ' + hash);
                await waitReceipt(hash);
                const after = await findPendingSetMinter();
                if (!after) {
                    setStatus('setMinter should be executed. Refresh status — isMinter must be true.');
                    return;
                }
                showId(after.id);
                setStatus(
                    'Confirm saved. Switch to NEXT signer and Confirm again. Same id: ' +
                    after.id + ' · ' + after.confirmations + '/3'
                );
            } catch (err) {
                setStatus(err && err.message ? err.message : String(err));
            }
        });
    }
})();
</script>
