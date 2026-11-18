@php
    $minter = $minter_address ?? '';
    $readyAddress = is_string($minter) && preg_match('/^0x[a-f0-9]{40}$/i', $minter);
    $calldata = $set_minter_calldata ?? '';
@endphp

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('How this works') }}</h5>
    <ol class="mb-2 small">
        <li>{{ __('Server .env has RACE_INCOME_MINTER_ADDRESS + PRIVATE_KEY + RACE_INCOME_MINT_ON_WITHDRAW=true.') }}</li>
        <li>{{ __('3 Multisig signers submit + confirm RaceCoin.setMinter(that EOA, true).') }}</li>
        <li>{{ __('After isMinter=true, member withdraw mints net RACE to their wallet via RaceCoin.mint — no admin approve per withdraw.') }}</li>
        <li>{{ __('Gas for every mint is paid by the income minter EOA (BNB on that address).') }}</li>
    </ol>
    <p class="small mb-0 text-muted">
        {{ __('This does not change tokenomics. Mint still respects MAX_SUPPLY. Multisig remains owner; EOA is only isMinter.') }}
    </p>
</div>

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('Live status') }}</h5>
    <p class="mb-1 small"><strong>{{ __('Mint on withdraw (.env)') }}:</strong>
        @if (!empty($mint_on_withdraw))
            <span class="text-success">ON</span>
        @else
            <span class="text-danger">OFF</span>
            <span class="text-muted">— set RACE_INCOME_MINT_ON_WITHDRAW=true</span>
        @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('Server mint service ready') }}:</strong>
        @if (!empty($service_ready))
            <span class="text-success">yes</span>
        @else
            <span class="text-danger">no</span>
            <span class="text-muted">— address + private key + token required</span>
        @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('Income minter EOA') }}:</strong>
        @if ($readyAddress)
            <code class="user-select-all">{{ $minter }}</code>
        @else
            <span class="text-danger">{{ __('Not set in .env (RACE_INCOME_MINTER_ADDRESS)') }}</span>
        @endif
    </p>
    <p class="mb-1 small"><strong>{{ __('On-chain isMinter') }}:</strong>
        @if ($is_minter === true)
            <span class="text-success font-weight-bold">true</span> — {{ __('ready for withdraw mint') }}
        @elseif ($is_minter === false)
            <span class="text-danger font-weight-bold">false</span> — {{ __('Multisig setMinter still required') }}
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
    <h5 class="mb-2">{{ __('Multisig setMinter — TokenPocket / MetaMask') }}</h5>
    <p class="small mb-2"><strong>{{ __('Inner call') }}:</strong> <code>setMinter({{ $minter }}, true)</code></p>
    <p class="small mb-3"><strong>{{ __('Calldata') }}:</strong> <code class="user-select-all" style="word-break:break-all">{{ $calldata }}</code></p>
    <ol class="small mb-3">
        <li>{{ __('BNB Smart Chain (56) + Multisig signer wallet connect karo.') }}</li>
        <li>{{ __('Neeche “Next NUMBER” pehle se dikhega. Submit ke baad wahi number box mein aa jayega.') }}</li>
        <li>{{ __('Signer #1: Submit, phir Confirm (1/3).') }}</li>
        <li>{{ __('Signer #2 aur #3: Confirm (same NUMBER) → 3/3 pe auto execute.') }}</li>
        <li>{{ __('Refresh status → isMinter = true.') }}</li>
    </ol>
    <div class="alert alert-info mb-3">
        <strong>{{ __('Next Multisig NUMBER (predicted)') }}:</strong>
        <span class="fs-3 fw-bold user-select-all" id="race-minter-next-id">…</span>
        <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="race-minter-refresh-count">{{ __('Refresh number') }}</button>
    </div>
    <p class="small mb-2"><strong>{{ __('Signers (3-of-5)') }}:</strong></p>
    <ul class="small mb-3">
        @foreach ($multisig_signers ?? [] as $signer)
            <li><code class="user-select-all">{{ $signer }}</code></li>
        @endforeach
    </ul>

    <div id="race-minter-id-banner" class="alert alert-success mb-3" role="status">
        <div class="fw-bold text-uppercase">{{ __('Multisig NUMBER (not hash)') }}</div>
        <div class="display-3 user-select-all fw-bold" id="race-minter-id-big" style="letter-spacing:2px;">—</div>
        <div class="small mb-1">{{ __('Submit ke baad yahan sirf chhota number aayega (jaise 7). Lamba 0x… hash yahan NAHI aana chahiye.') }}</div>
        <div class="small text-muted mb-0" id="race-minter-bsc-hash">{{ __('BSC hash: —') }}</div>
    </div>

    <div class="form-group mb-3" style="max-width: 320px;">
        <label class="form-label small" for="race-minter-txid">{{ __('Box mein ye NUMBER enter / auto') }}</label>
        <input type="text" inputmode="numeric" pattern="[0-9]*" class="form-control form-control-lg" id="race-minter-txid" placeholder="{{ __('Submit ke baad auto number') }}" readonly autocomplete="off">
        <button type="button" class="btn btn-link btn-sm px-0" id="race-minter-txid-unlock">{{ __('Edit manually (sirf number)') }}</button>
    </div>

    <p class="small mb-2" id="race-minter-status"></p>
    <div class="d-flex flex-wrap gap-2 mb-2">
        <button type="button" class="btn btn-success" id="race-minter-submit"
            data-multisig="{{ $multisig_contract }}"
            data-token="{{ $race_token }}"
            data-calldata="{{ $calldata }}">
            {{ __('1. Submit setMinter') }}
        </button>
        <button type="button" class="btn btn-outline-success" id="race-minter-confirm"
            data-multisig="{{ $multisig_contract }}">
            {{ __('2. Confirm Multisig tx') }}
        </button>
        <a class="btn btn-outline-primary" href="{{ url()->current() }}">{{ __('Refresh status') }}</a>
    </div>
</div>
@elseif (!$readyAddress)
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <p class="small mb-0 text-danger">
        {{ __('Set RACE_INCOME_MINTER_ADDRESS (and private key) on the server .env, then php artisan config:clear, then reload this page.') }}
    </p>
</div>
@endif

<script>
(function () {
    const EXPECTED_MULTISIG = String(@json($multisig_contract ?? '')).toLowerCase();
    const SUBMISSION_TOPIC = '0xc0ba8fe4b176c1714197d43b9cc6bcf797a4a7461c5fe8d0ef6e184ae7601e51';
    const statusEl = document.getElementById('race-minter-status');
    const bigId = document.getElementById('race-minter-id-big');
    const hashEl = document.getElementById('race-minter-bsc-hash');
    const setStatus = (msg, ok) => {
        if (!statusEl) return;
        statusEl.innerHTML = msg || '';
        statusEl.className = 'small mb-2 ' + (ok ? 'text-success' : 'text-danger');
    };

    const storageKey = 'race.multisig.setIncomeMinter.txId';
    const txInput = document.getElementById('race-minter-txid');
    const unlockBtn = document.getElementById('race-minter-txid-unlock');

    function isPureNumber(v) {
        return /^\d+$/.test(String(v || '').trim());
    }

    function showBscHash(hash) {
        if (hashEl) hashEl.textContent = 'BSC hash (sirf reference, box mein mat daalo): ' + (hash || '—');
    }

    function showTxId(id) {
        const raw = String(id == null ? '' : id).trim();
        if (!isPureNumber(raw)) {
            throw new Error('Invalid Multisig id (number chahiye, hash nahi): ' + raw);
        }
        const n = String(parseInt(raw, 10));
        if (txInput) txInput.value = n;
        window.localStorage.setItem(storageKey, n);
        if (bigId) bigId.textContent = n;
        return n;
    }

    // Clear bad localStorage (old hash mistakes)
    if (txInput) {
        const saved = window.localStorage.getItem(storageKey);
        if (saved && isPureNumber(saved)) {
            try { showTxId(saved); } catch (e) {}
        } else if (saved) {
            window.localStorage.removeItem(storageKey);
            txInput.value = '';
            if (bigId) bigId.textContent = '—';
        }
        txInput.addEventListener('change', () => {
            const v = String(txInput.value || '').trim();
            if (!v) return;
            if (v.startsWith('0x') || v.length > 12 || !isPureNumber(v)) {
                setStatus('Box mein sirf NUMBER likho (jaise 7). Hash mat daalo.', false);
                txInput.value = '';
                return;
            }
            try { showTxId(v); } catch (e) { setStatus(e.message, false); }
        });
    }
    if (unlockBtn && txInput) {
        unlockBtn.addEventListener('click', () => {
            txInput.readOnly = false;
            txInput.focus();
        });
    }

    function normalizeAddr(a) {
        return String(a || '').trim().toLowerCase();
    }

    function assertMultisig(addr) {
        const a = normalizeAddr(addr);
        if (!/^0x[a-f0-9]{40}$/.test(a)) {
            throw new Error('Multisig address missing/invalid. Check RACE_MULTISIG_CONTRACT on server.');
        }
        if (EXPECTED_MULTISIG && a !== EXPECTED_MULTISIG) {
            throw new Error('Multisig mismatch. Expected ' + EXPECTED_MULTISIG + ' got ' + a);
        }
        return a;
    }

    async function eth() {
        const p = window.ethereum;
        if (!p) throw new Error('No wallet provider. Open admin in TokenPocket / MetaMask.');
        return p;
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
        if (!acc || !acc[0]) throw new Error('Connect a Multisig signer wallet.');
        return acc[0];
    }

    function encodeSubmit(token, calldataHex) {
        const to = token.replace(/^0x/i, '').toLowerCase().padStart(64, '0');
        const value = ''.padStart(64, '0');
        const data = (calldataHex || '').replace(/^0x/i, '').toLowerCase();
        const dataLen = (data.length / 2);
        const offset = (96).toString(16).padStart(64, '0');
        const len = dataLen.toString(16).padStart(64, '0');
        const padded = data + (data.length % 64 === 0 ? '' : '0'.repeat(64 - (data.length % 64)));
        return '0xc6427474' + to + value + offset + len + padded;
    }

    function encodeConfirm(txId) {
        if (!isPureNumber(txId)) throw new Error('Multisig NUMBER missing. Submit first — number auto box mein aayega.');
        const id = parseInt(String(txId), 10);
        return '0xc01a8c84' + id.toString(16).padStart(64, '0');
    }

    function hexToUint(hex) {
        if (!hex || hex === '0x') return null;
        try {
            return Number(BigInt(hex));
        } catch (e) {
            return null;
        }
    }

    async function ethCallUint(provider, to, data) {
        const hex = await provider.request({
            method: 'eth_call',
            params: [{ to, data }, 'latest'],
        });
        return hexToUint(hex);
    }

    async function waitReceipt(provider, hash) {
        for (let i = 0; i < 60; i++) {
            const r = await provider.request({ method: 'eth_getTransactionReceipt', params: [hash] });
            if (r) return r;
            await new Promise((res) => setTimeout(res, 2000));
        }
        throw new Error('Receipt timeout. BscScan pe Submission event se NUMBER nikaalo, Edit manually.');
    }

    function txIdFromReceipt(receipt, multisig) {
        const m = normalizeAddr(multisig);
        const logs = receipt.logs || [];
        for (const log of logs) {
            if (normalizeAddr(log.address) !== m) continue;
            const t0 = String(log.topics && log.topics[0] ? log.topics[0] : '').toLowerCase();
            if (t0 !== SUBMISSION_TOPIC) continue;
            if (log.topics[1]) {
                const id = hexToUint(log.topics[1]);
                if (id !== null && id >= 0 && id < 1e9) return id;
            }
        }
        // fallback: any log topic1 that looks like small id from this multisig
        for (const log of logs) {
            if (normalizeAddr(log.address) !== m) continue;
            if (log.topics && log.topics[1]) {
                const id = hexToUint(log.topics[1]);
                if (id !== null && id >= 0 && id < 100000) return id;
            }
        }
        return null;
    }

    async function resolveTxId(provider, multisig, receipt, countBefore) {
        let txId = txIdFromReceipt(receipt, multisig);
        if (txId !== null) return txId;

        // poll count until it increases
        for (let i = 0; i < 15; i++) {
            const countAfter = await ethCallUint(provider, multisig, '0xb77bf600');
            if (countBefore !== null && countAfter !== null && countAfter > countBefore) {
                return countBefore; // new proposal id = old count
            }
            if (countAfter !== null && countBefore === null && countAfter > 0) {
                return countAfter - 1;
            }
            await new Promise((res) => setTimeout(res, 1500));
        }
        if (countBefore !== null) return countBefore;
        return null;
    }

    const nextIdEl = document.getElementById('race-minter-next-id');
    const refreshCountBtn = document.getElementById('race-minter-refresh-count');

    async function readNextId(provider, multisig) {
        const count = await ethCallUint(provider, multisig, '0xb77bf600');
        if (count === null) throw new Error('transactionCount() read fail — Multisig RPC error.');
        return count; // next submit id == current length
    }

    async function refreshPredictedNumber() {
        try {
            const provider = await eth();
            await ensureChain(provider);
            const multisig = assertMultisig(
                (submitBtn && submitBtn.getAttribute('data-multisig')) || EXPECTED_MULTISIG
            );
            const nextId = await readNextId(provider, multisig);
            if (nextIdEl) nextIdEl.textContent = String(nextId);
            setStatus('Next NUMBER = <strong>' + nextId + '</strong> (Submit ke baad yahi box mein aayega).', true);
            return nextId;
        } catch (e) {
            if (nextIdEl) nextIdEl.textContent = '?';
            setStatus(e && e.message ? e.message : String(e), false);
            return null;
        }
    }

    if (refreshCountBtn) {
        refreshCountBtn.addEventListener('click', () => { refreshPredictedNumber(); });
    }
    // load predicted number on page open
    setTimeout(() => { refreshPredictedNumber(); }, 300);

    const submitBtn = document.getElementById('race-minter-submit');
    if (submitBtn) {
        submitBtn.addEventListener('click', async () => {
            try {
                setStatus('Submit… pehle Next NUMBER padh rahe hain…', true);
                const provider = await eth();
                await ensureChain(provider);
                const from = await accounts(provider);
                const multisig = assertMultisig(submitBtn.getAttribute('data-multisig'));
                const token = submitBtn.getAttribute('data-token');
                const calldata = submitBtn.getAttribute('data-calldata');
                if (!token || !calldata) throw new Error('Race token / calldata missing on page.');

                const predictedId = await readNextId(provider, multisig);
                if (nextIdEl) nextIdEl.textContent = String(predictedId);
                // Fill NUMBER immediately so admin always sees it (even if receipt parse fails)
                showTxId(predictedId);
                setStatus(
                    'NUMBER <strong>' + predictedId + '</strong> box mein set.<br>' +
                    'Ab wallet Approve karo. To = <code>' + multisig + '</code>',
                    true
                );

                const data = encodeSubmit(token, calldata);
                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{ from, to: multisig, data, value: '0x0' }],
                });
                showBscHash(txHash);
                setStatus(
                    'NUMBER <strong>' + predictedId + '</strong> (box mein hai).<br>' +
                    'Submit hash wait… (hash NUMBER nahi hai)',
                    true
                );

                const receipt = await waitReceipt(provider, txHash);
                const receiptTo = normalizeAddr(receipt.to || receipt.contractAddress || '');
                if (receiptTo && receiptTo !== multisig) {
                    throw new Error(
                        'Galat contract: ' + receiptTo + ' — expected ' + multisig +
                        '. NUMBER ' + predictedId + ' use mat karo; Refresh number dabao aur dubara Submit.'
                    );
                }
                if (String(receipt.status) !== '0x1' && Number(receipt.status) !== 1) {
                    throw new Error('Submit FAIL. NUMBER ' + predictedId + ' invalid ho sakta hai — Refresh number.');
                }

                // Prefer event/count confirmation; keep predictedId as fallback
                const verified = await resolveTxId(provider, multisig, receipt, predictedId);
                const finalId = verified !== null ? verified : predictedId;
                showTxId(finalId);
                if (nextIdEl) nextIdEl.textContent = String(finalId + 1);

                setStatus(
                    '<strong>NUMBER = ' + finalId + '</strong> box mein ready.<br>' +
                    '1) Isi wallet se Confirm (1/3)<br>' +
                    '2) Do aur Multisig wallets se Confirm (2/3, 3/3)<br>' +
                    'Hash mat daalo — sirf ye number.',
                    true
                );
            } catch (e) {
                setStatus(e && e.message ? e.message : String(e), false);
            }
        });
    }

    const confirmBtn = document.getElementById('race-minter-confirm');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', async () => {
            try {
                setStatus('Confirm…', true);
                const provider = await eth();
                await ensureChain(provider);
                const from = await accounts(provider);
                const multisig = assertMultisig(confirmBtn.getAttribute('data-multisig'));
                const txId = txInput ? String(txInput.value || '').trim() : '';
                if (!isPureNumber(txId)) {
                    throw new Error('Pehle Submit karo taaki NUMBER box mein aaye. Hash mat daalo.');
                }
                const n = showTxId(txId);
                const data = encodeConfirm(n);
                setStatus('Confirm Approve karo.<br>To: <code>' + multisig + '</code><br>NUMBER: <strong>' + n + '</strong>', true);
                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{ from, to: multisig, data, value: '0x0' }],
                });
                showBscHash(txHash);
                const receipt = await waitReceipt(provider, txHash);
                const receiptTo = normalizeAddr(receipt.to || '');
                if (receiptTo && receiptTo !== multisig) {
                    throw new Error('Confirm galat contract pe: ' + receiptTo);
                }
                setStatus('Confirm OK (NUMBER ' + n + '). 3 signers ke baad Refresh — isMinter true.', true);
            } catch (e) {
                setStatus(e && e.message ? e.message : String(e), false);
            }
        });
    }
})();
</script>
