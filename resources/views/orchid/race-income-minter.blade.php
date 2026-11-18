@php
    $minter = $minter_address ?? '';
    $readyAddress = is_string($minter) && preg_match('/^0x[a-f0-9]{40}$/i', $minter);
    $calldata = $set_minter_calldata ?? '';
@endphp

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('How this works') }}</h5>
    <ol class="mb-2 small">
        <li>{{ __('Server .env has RACE_INCOME_MINTER_ADDRESS + PRIVATE_KEY + RACE_INCOME_MINT_ON_WITHDRAW=true.') }}</li>
        <li>{{ __('3 Multisig signers: Submit → NUMBER dikhega → Confirm (3-of-5).') }}</li>
        <li>{{ __('isMinter=true ke baad withdraw RaceCoin.mint karta hai.') }}</li>
    </ol>
</div>

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('Live status') }}</h5>
    <p class="mb-1 small"><strong>{{ __('Mint on withdraw (.env)') }}:</strong>
        @if (!empty($mint_on_withdraw))
            <span class="text-success">ON</span>
        @else
            <span class="text-danger">OFF</span>
        @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('Server mint service ready') }}:</strong>
        @if (!empty($service_ready))
            <span class="text-success">yes</span>
        @else
            <span class="text-danger">no</span>
        @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('Income minter EOA') }}:</strong>
        @if ($readyAddress)
            <code class="user-select-all">{{ $minter }}</code>
        @else
            <span class="text-danger">{{ __('Not set') }}</span>
        @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('On-chain isMinter') }}:</strong>
        @if ($is_minter === true)
            <span class="text-success font-weight-bold">true</span>
        @elseif ($is_minter === false)
            <span class="text-danger font-weight-bold">false</span>
        @else
            <span class="text-muted">—</span>
        @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('Minter BNB (gas)') }}:</strong>
        {{ $bnb_balance !== null ? $bnb_balance.' BNB' : '—' }}
    </p>
    <p class="mb-1 small"><strong>{{ __('RaceCoin') }}:</strong> <code class="user-select-all">{{ $race_token ?? '' }}</code></p>
    <p class="mb-0 small"><strong>{{ __('Multisig') }}:</strong> <code class="user-select-all">{{ $multisig_contract ?? '' }}</code></p>
    @if (!empty($rpc_error))
        <p class="small text-danger mt-2 mb-0">{{ __('RPC') }}: {{ $rpc_error }}</p>
    @endif
</div>

@if ($readyAddress && $calldata)
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('Multisig setMinter') }}</h5>
    <p class="small mb-2"><strong>{{ __('Inner call') }}:</strong> <code>setMinter({{ $minter }}, true)</code></p>
    <p class="small mb-3"><strong>{{ __('Calldata') }}:</strong> <code class="user-select-all" style="word-break:break-all">{{ $calldata }}</code></p>

    <div class="alert alert-info mb-3">
        <strong>{{ __('Next NUMBER (chain)') }}:</strong>
        <span class="fs-3 fw-bold user-select-all" id="race-minter-next-id">{{ $next_tx_id !== null ? $next_tx_id : '…' }}</span>
        <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="race-minter-refresh-count">{{ __('Refresh') }}</button>
    </div>

    <div id="race-minter-id-banner" class="alert alert-success mb-3" role="status">
        <div class="fw-bold">{{ __('Submit ke baad ye NUMBER Confirm mein use karo') }}</div>
        <div class="display-3 user-select-all fw-bold" id="race-minter-id-big" style="letter-spacing:2px;">—</div>
        <div class="small text-muted mb-0" id="race-minter-bsc-hash">{{ __('BSC hash: —') }}</div>
    </div>

    <div class="form-group mb-3" style="max-width: 320px;">
        <label class="form-label small" for="race-minter-txid">{{ __('Confirm NUMBER') }}</label>
        <input type="text" inputmode="numeric" class="form-control form-control-lg" id="race-minter-txid" placeholder="{{ __('Submit ke baad auto') }}" autocomplete="off">
    </div>

    <p class="small mb-2" id="race-minter-status"></p>
    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="button" class="btn btn-success" id="race-minter-submit"
            data-multisig="{{ $multisig_contract }}"
            data-token="{{ $race_token }}"
            data-calldata="{{ $calldata }}">
            {{ __('1. Submit') }}
        </button>
        <button type="button" class="btn btn-outline-success" id="race-minter-confirm"
            data-multisig="{{ $multisig_contract }}">
            {{ __('2. Confirm') }}
        </button>
        <a class="btn btn-outline-primary" href="{{ url()->current() }}">{{ __('Refresh status') }}</a>
    </div>
</div>
@elseif (!$readyAddress)
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <p class="small mb-0 text-danger">
        {{ __('Set RACE_INCOME_MINTER_ADDRESS on server .env, then config:clear.') }}
    </p>
</div>
@endif

<script>
(function () {
    const EXPECTED_MULTISIG = String(@json($multisig_contract ?? '')).toLowerCase();
    const RPC_URL = String(@json($rpc_url ?? 'https://bsc-dataseed.binance.org/'));
    const SERVER_NEXT_ID = @json($next_tx_id);
    const SUBMISSION_TOPIC = '0xc0ba8fe4b176c1714197d43b9cc6bcf797a4a7461c5fe8d0ef6e184ae7601e51';
    const storageKey = 'race.multisig.setIncomeMinter.txId';

    const statusEl = document.getElementById('race-minter-status');
    const bigId = document.getElementById('race-minter-id-big');
    const hashEl = document.getElementById('race-minter-bsc-hash');
    const nextIdEl = document.getElementById('race-minter-next-id');
    const txInput = document.getElementById('race-minter-txid');
    const submitBtn = document.getElementById('race-minter-submit');
    const confirmBtn = document.getElementById('race-minter-confirm');
    const refreshCountBtn = document.getElementById('race-minter-refresh-count');

    function setStatus(msg, ok) {
        if (!statusEl) return;
        statusEl.innerHTML = msg || '';
        statusEl.className = 'small mb-2 ' + (ok ? 'text-success' : 'text-danger');
    }

    // Jo number aaya wahi dikhao + input mein daalo — validate / reject nahi
    function showNumber(id) {
        const n = String(id == null ? '' : id).trim();
        if (!n) return '';
        if (txInput) txInput.value = n;
        try { window.localStorage.setItem(storageKey, n); } catch (e) {}
        if (bigId) bigId.textContent = n;
        if (nextIdEl) nextIdEl.textContent = n;
        return n;
    }

    function showBscHash(hash) {
        if (hashEl) hashEl.textContent = 'BSC hash: ' + (hash || '—');
    }

    // Restore last shown number
    if (txInput) {
        try {
            const saved = window.localStorage.getItem(storageKey);
            if (saved) showNumber(saved);
        } catch (e) {}
    }

    function normalizeAddr(a) {
        return String(a || '').trim().toLowerCase();
    }

    async function eth() {
        if (!window.ethereum) throw new Error('Wallet connect karo (TokenPocket / MetaMask).');
        return window.ethereum;
    }

    async function ensureChain(provider) {
        const chainId = await provider.request({ method: 'eth_chainId' });
        if (String(chainId).toLowerCase() !== '0x38') {
            await provider.request({
                method: 'wallet_switchEthereumChain',
                params: [{ chainId: '0x38' }],
            });
        }
    }

    async function accounts(provider) {
        const acc = await provider.request({ method: 'eth_requestAccounts' });
        if (!acc || !acc[0]) throw new Error('Signer wallet connect karo.');
        return acc[0];
    }

    function encodeSubmit(token, calldataHex) {
        const to = token.replace(/^0x/i, '').toLowerCase().padStart(64, '0');
        const value = ''.padStart(64, '0');
        const data = (calldataHex || '').replace(/^0x/i, '').toLowerCase();
        const offset = (96).toString(16).padStart(64, '0');
        const len = (data.length / 2).toString(16).padStart(64, '0');
        const padded = data + (data.length % 64 === 0 ? '' : '0'.repeat(64 - (data.length % 64)));
        return '0xc6427474' + to + value + offset + len + padded;
    }

    function encodeConfirm(txId) {
        const id = parseInt(String(txId).trim(), 10);
        if (!Number.isFinite(id) || id < 0) throw new Error('NUMBER box khali / galat.');
        return '0xc01a8c84' + id.toString(16).padStart(64, '0');
    }

    function hexToUint(hex) {
        if (!hex || hex === '0x') return null;
        try { return Number(BigInt(hex)); } catch (e) { return null; }
    }

    let rpcId = 1;
    async function publicRpc(method, params) {
        const urls = [RPC_URL, 'https://bsc-dataseed.binance.org/', 'https://bsc.publicnode.com'];
        let lastErr = null;
        for (const url of urls) {
            try {
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ jsonrpc: '2.0', id: rpcId++, method, params }),
                });
                const json = await res.json();
                if (json && json.error) {
                    lastErr = new Error(json.error.message || 'RPC error');
                    continue;
                }
                return json ? json.result : null;
            } catch (e) {
                lastErr = e;
            }
        }
        throw lastErr || new Error('Public BSC RPC fail');
    }

    async function readCount(multisig) {
        const hex = await publicRpc('eth_call', [{ to: multisig, data: '0xb77bf600' }, 'latest']);
        return hexToUint(hex);
    }

    async function waitReceipt(hash) {
        for (let i = 0; i < 40; i++) {
            const r = await publicRpc('eth_getTransactionReceipt', [hash]);
            if (r) return r;
            await new Promise((res) => setTimeout(res, 1000));
        }
        throw new Error('Receipt timeout — BscScan pe Submission event check karo.');
    }

    function numberFromReceipt(receipt, multisig) {
        const m = normalizeAddr(multisig);
        const logs = receipt.logs || [];
        for (const log of logs) {
            if (normalizeAddr(log.address) !== m) continue;
            const t0 = String(log.topics && log.topics[0] ? log.topics[0] : '').toLowerCase();
            if (t0 !== SUBMISSION_TOPIC) continue;
            if (log.topics && log.topics[1]) {
                const id = hexToUint(log.topics[1]);
                if (id !== null) return id;
            }
        }
        for (const log of logs) {
            if (normalizeAddr(log.address) !== m) continue;
            if (log.topics && log.topics[1]) {
                const id = hexToUint(log.topics[1]);
                if (id !== null && id < 1000000) return id;
            }
        }
        return null;
    }

    async function refreshNext() {
        try {
            const multisig = EXPECTED_MULTISIG
                || (submitBtn && normalizeAddr(submitBtn.getAttribute('data-multisig')))
                || '';
            const n = await readCount(multisig);
            if (n === null) throw new Error('count read fail');
            if (nextIdEl) nextIdEl.textContent = String(n);
            setStatus('Next NUMBER = <strong>' + n + '</strong>', true);
            return n;
        } catch (e) {
            if (SERVER_NEXT_ID !== null && SERVER_NEXT_ID !== undefined) {
                if (nextIdEl) nextIdEl.textContent = String(SERVER_NEXT_ID);
                setStatus('Next NUMBER = <strong>' + SERVER_NEXT_ID + '</strong> (server)', true);
                return SERVER_NEXT_ID;
            }
            setStatus(e && e.message ? e.message : String(e), false);
            return null;
        }
    }

    if (refreshCountBtn) refreshCountBtn.addEventListener('click', () => { refreshNext(); });
    setTimeout(() => { refreshNext(); }, 200);

    if (submitBtn) {
        submitBtn.addEventListener('click', async () => {
            try {
                setStatus('Submit…', true);
                const provider = await eth();
                await ensureChain(provider);
                const from = await accounts(provider);
                const multisig = normalizeAddr(submitBtn.getAttribute('data-multisig') || EXPECTED_MULTISIG);
                const token = submitBtn.getAttribute('data-token');
                const calldata = submitBtn.getAttribute('data-calldata');
                if (!token || !calldata) throw new Error('Token / calldata missing.');

                // pehle predicted number dikhao (fast UX), phir chain se actual
                let before = null;
                try { before = await readCount(multisig); } catch (e) { before = SERVER_NEXT_ID; }
                if (before !== null && before !== undefined) showNumber(before);

                const data = encodeSubmit(token, calldata);
                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{ from, to: multisig, data, value: '0x0' }],
                });
                showBscHash(txHash);
                setStatus('Submit bhej diya… NUMBER nikaal rahe hain…', true);

                const receipt = await waitReceipt(txHash);
                let actual = numberFromReceipt(receipt, multisig);
                if (actual === null && before !== null) actual = before;
                if (actual === null) {
                    try {
                        const after = await readCount(multisig);
                        if (after !== null && after > 0) actual = after - 1;
                    } catch (e) {}
                }
                if (actual === null) throw new Error('NUMBER nahi mila. Refresh dabao / BscScan check.');

                showNumber(actual);
                setStatus(
                    '<strong>NUMBER = ' + actual + '</strong> — box mein daal diya.<br>' +
                    'Ab <strong>Confirm</strong> dabao (1/3), phir do aur signers.',
                    true
                );
            } catch (e) {
                setStatus(e && e.message ? e.message : String(e), false);
            }
        });
    }

    if (confirmBtn) {
        confirmBtn.addEventListener('click', async () => {
            try {
                const n = txInput ? String(txInput.value || '').trim() : '';
                if (!n) throw new Error('Pehle Submit — NUMBER box mein aayega.');
                showNumber(n);

                setStatus('Confirm… NUMBER ' + n, true);
                const provider = await eth();
                await ensureChain(provider);
                const from = await accounts(provider);
                const multisig = normalizeAddr(confirmBtn.getAttribute('data-multisig') || EXPECTED_MULTISIG);
                const data = encodeConfirm(n);
                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{ from, to: multisig, data, value: '0x0' }],
                });
                showBscHash(txHash);
                await waitReceipt(txHash);
                setStatus('Confirm OK — NUMBER <strong>' + n + '</strong>. 3 signers ke baad Refresh status.', true);
            } catch (e) {
                setStatus(e && e.message ? e.message : String(e), false);
            }
        });
    }
})();
</script>
