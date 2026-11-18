@php
    $minter = $minter_address ?? '';
    $readyAddress = is_string($minter) && preg_match('/^0x[a-f0-9]{40}$/i', $minter);
    $calldata = $set_minter_calldata ?? '';
    $nextNumberUrl = route('platform.data.income-minter-multisig.next-number');
@endphp

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('How this works') }}</h5>
    <ol class="mb-2 small">
        <li>{{ __('Submit → NUMBER auto box mein → Confirm (3 wallets).') }}</li>
        <li>{{ __('To hamesha Multisig 0x81Bf…F3df hona chahiye — galat contract mat bhejo.') }}</li>
        <li>{{ __('isMinter=true ke baad withdraw RaceCoin.mint karta hai.') }}</li>
    </ol>
</div>

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
    <h5 class="mb-2">{{ __('Multisig setMinter') }}</h5>
    <p class="small mb-2"><code>setMinter({{ $minter }}, true)</code></p>
    <p class="small mb-3" style="word-break:break-all"><code class="user-select-all">{{ $calldata }}</code></p>

    <div class="alert alert-info mb-3">
        <strong>{{ __('Next NUMBER') }}:</strong>
        <span class="fs-3 fw-bold user-select-all" id="race-minter-next-id">{{ $next_tx_id !== null ? $next_tx_id : '…' }}</span>
        <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="race-minter-refresh-count">{{ __('Refresh') }}</button>
    </div>

    <div class="alert alert-success mb-3">
        <div class="fw-bold">{{ __('Submit ke baad Confirm NUMBER') }}</div>
        <div class="display-3 user-select-all fw-bold" id="race-minter-id-big">—</div>
        <div class="small text-muted" id="race-minter-bsc-hash">{{ __('BSC hash: —') }}</div>
    </div>

    <div class="form-group mb-3" style="max-width:320px">
        <label class="form-label small" for="race-minter-txid">{{ __('Confirm NUMBER') }}</label>
        <input type="text" inputmode="numeric" class="form-control form-control-lg" id="race-minter-txid" autocomplete="off">
    </div>

    <p class="small mb-2" id="race-minter-status"></p>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-success" id="race-minter-submit"
            data-multisig="{{ $multisig_contract }}"
            data-token="{{ $race_token }}"
            data-calldata="{{ $calldata }}">{{ __('1. Submit') }}</button>
        <button type="button" class="btn btn-outline-success" id="race-minter-confirm"
            data-multisig="{{ $multisig_contract }}">{{ __('2. Confirm') }}</button>
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
    const EXPECTED_MULTISIG = String(@json($multisig_contract ?? '')).toLowerCase();
    const SERVER_NEXT_ID = @json($next_tx_id);
    const NEXT_URL = @json($nextNumberUrl);
    const SUBMISSION_TOPIC = '0xc0ba8fe4b176c1714197d43b9cc6bcf797a4a7461c5fe8d0ef6e184ae7601e51';
    const storageKey = 'race.multisig.setIncomeMinter.txId';

    const statusEl = document.getElementById('race-minter-status');
    const bigId = document.getElementById('race-minter-id-big');
    const hashEl = document.getElementById('race-minter-bsc-hash');
    const nextIdEl = document.getElementById('race-minter-next-id');
    const txInput = document.getElementById('race-minter-txid');
    const submitBtn = document.getElementById('race-minter-submit');
    const confirmBtn = document.getElementById('race-minter-confirm');
    const refreshBtn = document.getElementById('race-minter-refresh-count');

    function setStatus(msg, ok) {
        if (!statusEl) return;
        statusEl.innerHTML = msg || '';
        statusEl.className = 'small mb-2 ' + (ok ? 'text-success' : 'text-danger');
    }

    function showNumber(id) {
        const n = String(id == null ? '' : id).trim();
        if (!n) return '';
        if (txInput) txInput.value = n;
        if (bigId) bigId.textContent = n;
        if (nextIdEl) nextIdEl.textContent = n;
        try { localStorage.setItem(storageKey, n); } catch (e) {}
        return n;
    }

    function showHash(h) {
        if (hashEl) hashEl.textContent = 'BSC hash: ' + (h || '—');
    }

    try {
        const saved = localStorage.getItem(storageKey);
        if (saved && txInput) showNumber(saved);
    } catch (e) {}

    function normalizeAddr(a) {
        return String(a || '').trim().toLowerCase();
    }

    async function eth() {
        if (!window.ethereum) throw new Error('Wallet connect karo.');
        return window.ethereum;
    }

    async function ensureChain(p) {
        const chainId = await p.request({ method: 'eth_chainId' });
        if (String(chainId).toLowerCase() !== '0x38') {
            await p.request({ method: 'wallet_switchEthereumChain', params: [{ chainId: '0x38' }] });
        }
    }

    async function accounts(p) {
        const acc = await p.request({ method: 'eth_requestAccounts' });
        if (!acc || !acc[0]) throw new Error('Signer connect karo.');
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
        if (!Number.isFinite(id) || id < 0) throw new Error('NUMBER box khali.');
        return '0xc01a8c84' + id.toString(16).padStart(64, '0');
    }

    function hexToUint(hex) {
        if (!hex || hex === '0x') return null;
        try { return Number(BigInt(hex)); } catch (e) { return null; }
    }

    // Server pe count — browser public RPC use mat karo
    async function fetchNextFromServer() {
        const res = await fetch(NEXT_URL, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const json = await res.json();
        if (!json || !json.ok) throw new Error((json && json.error) || 'server count fail');
        return json.next;
    }

    async function waitReceipt(provider, hash) {
        for (let i = 0; i < 45; i++) {
            try {
                const r = await provider.request({ method: 'eth_getTransactionReceipt', params: [hash] });
                if (r) return r;
            } catch (e) {}
            await new Promise((x) => setTimeout(x, 1000));
        }
        throw new Error('Receipt timeout.');
    }

    function numberFromReceipt(receipt, multisig) {
        const m = normalizeAddr(multisig);
        for (const log of (receipt.logs || [])) {
            if (normalizeAddr(log.address) !== m) continue;
            const t0 = String(log.topics && log.topics[0] ? log.topics[0] : '').toLowerCase();
            if (t0 === SUBMISSION_TOPIC && log.topics[1]) {
                const id = hexToUint(log.topics[1]);
                if (id !== null) return id;
            }
        }
        for (const log of (receipt.logs || [])) {
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
            const n = await fetchNextFromServer();
            if (nextIdEl) nextIdEl.textContent = String(n);
            setStatus('Next NUMBER = <strong>' + n + '</strong>', true);
            return n;
        } catch (e) {
            if (SERVER_NEXT_ID !== null && SERVER_NEXT_ID !== undefined) {
                if (nextIdEl) nextIdEl.textContent = String(SERVER_NEXT_ID);
                setStatus('Next NUMBER = <strong>' + SERVER_NEXT_ID + '</strong> (page load)', true);
                return SERVER_NEXT_ID;
            }
            setStatus(e && e.message ? e.message : String(e), false);
            return null;
        }
    }

    if (refreshBtn) refreshBtn.addEventListener('click', () => { refreshNext(); });
    setTimeout(() => { refreshNext(); }, 150);

    if (submitBtn) {
        submitBtn.addEventListener('click', async () => {
            try {
                setStatus('Submit…', true);
                const provider = await eth();
                await ensureChain(provider);
                const from = await accounts(provider);
                const multisig = normalizeAddr(submitBtn.getAttribute('data-multisig') || EXPECTED_MULTISIG);
                if (!multisig || multisig !== EXPECTED_MULTISIG) {
                    throw new Error('Multisig mismatch. Expected ' + EXPECTED_MULTISIG);
                }
                const token = submitBtn.getAttribute('data-token');
                const calldata = submitBtn.getAttribute('data-calldata');
                if (!token || !calldata) throw new Error('Token/calldata missing.');

                // Predicted number — server se; fail ho to page value; Submit mat roko
                let predicted = SERVER_NEXT_ID;
                try { predicted = await fetchNextFromServer(); } catch (e) {}
                if (predicted !== null && predicted !== undefined) showNumber(predicted);

                const data = encodeSubmit(token, calldata);
                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{ from, to: multisig, data, value: '0x0' }],
                });
                showHash(txHash);
                setStatus('Submit bhej diya… NUMBER nikaal rahe hain…', true);

                const receipt = await waitReceipt(provider, txHash);
                const to = normalizeAddr(receipt.to || '');
                if (to && to !== multisig) {
                    throw new Error('Galat contract: ' + to + ' — Multisig ' + multisig + ' pe Submit karo.');
                }
                if (String(receipt.status) !== '0x1' && Number(receipt.status) !== 1) {
                    throw new Error('Submit FAIL on-chain.');
                }

                let actual = numberFromReceipt(receipt, multisig);
                if (actual === null && predicted !== null && predicted !== undefined) actual = predicted;
                if (actual === null) {
                    try { actual = await fetchNextFromServer(); if (actual > 0) actual = actual - 1; } catch (e) {}
                }
                if (actual === null) throw new Error('NUMBER nahi mila. Refresh dabao.');

                showNumber(actual);
                setStatus('<strong>NUMBER = ' + actual + '</strong> box mein ready. Ab Confirm dabao.', true);
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

                const provider = await eth();
                await ensureChain(provider);
                const from = await accounts(provider);
                const multisig = normalizeAddr(confirmBtn.getAttribute('data-multisig') || EXPECTED_MULTISIG);
                if (multisig !== EXPECTED_MULTISIG) throw new Error('Multisig mismatch.');

                setStatus('Confirm… NUMBER ' + n + ' → ' + multisig, true);
                const data = encodeConfirm(n);
                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{ from, to: multisig, data, value: '0x0' }],
                });
                showHash(txHash);
                const receipt = await waitReceipt(provider, txHash);
                const to = normalizeAddr(receipt.to || '');
                if (to && to !== multisig) throw new Error('Confirm galat contract pe: ' + to);
                setStatus('Confirm OK — NUMBER <strong>' + n + '</strong>. 3 signers ke baad Refresh status.', true);
            } catch (e) {
                setStatus(e && e.message ? e.message : String(e), false);
            }
        });
    }
})();
</script>
