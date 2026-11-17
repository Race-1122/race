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
        <li>{{ __('Open this admin page in TokenPocket / MetaMask on BNB Smart Chain (56).') }}</li>
        <li>{{ __('Connect a Multisig signer wallet (one of the 5 below).') }}</li>
        <li>{{ __('Tap 1. Submit — Multisig.submitTransaction(RaceCoin, 0, setMinterCalldata).') }}</li>
        <li>{{ __('Save Multisig tx id from BscScan event / UI into the box below.') }}</li>
        <li>{{ __('Two more signers tap 2. Confirm until the call executes.') }}</li>
        <li>{{ __('Refresh this page — isMinter should become true.') }}</li>
    </ol>
    <p class="small mb-2"><strong>{{ __('Signers (3-of-5)') }}:</strong></p>
    <ul class="small mb-3">
        @foreach ($multisig_signers ?? [] as $signer)
            <li><code class="user-select-all">{{ $signer }}</code></li>
        @endforeach
    </ul>

    <div class="form-group mb-3" style="max-width: 280px;">
        <label class="form-label small" for="race-minter-txid">{{ __('Multisig tx id (after Submit)') }}</label>
        <input type="number" class="form-control" id="race-minter-txid" min="0" step="1" placeholder="e.g. 12">
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
    const statusEl = document.getElementById('race-minter-status');
    const setStatus = (msg, ok) => {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.className = 'small mb-2 ' + (ok ? 'text-success' : 'text-danger');
    };

    const storageKey = 'race.multisig.setIncomeMinter.txId';
    const txInput = document.getElementById('race-minter-txid');
    if (txInput) {
        const saved = window.localStorage.getItem(storageKey);
        if (saved) txInput.value = saved;
        txInput.addEventListener('change', () => {
            window.localStorage.setItem(storageKey, String(txInput.value || ''));
        });
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
        const id = Number(txId);
        if (!Number.isFinite(id) || id < 0) throw new Error('Enter Multisig tx id first.');
        return '0xc01a8c84' + id.toString(16).padStart(64, '0');
    }

    const submitBtn = document.getElementById('race-minter-submit');
    if (submitBtn) {
        submitBtn.addEventListener('click', async () => {
            try {
                setStatus('Submitting…', true);
                const provider = await eth();
                await ensureChain(provider);
                const from = await accounts(provider);
                const multisig = submitBtn.getAttribute('data-multisig');
                const token = submitBtn.getAttribute('data-token');
                const calldata = submitBtn.getAttribute('data-calldata');
                const data = encodeSubmit(token, calldata);
                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{ from, to: multisig, data, value: '0x0' }],
                });
                setStatus('Submitted. Tx ' + txHash + ' — read Multisig transactionId from BscScan logs, save it below, then 2 more signers Confirm.', true);
            } catch (e) {
                setStatus(e && e.message ? e.message : String(e), false);
            }
        });
    }

    const confirmBtn = document.getElementById('race-minter-confirm');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', async () => {
            try {
                setStatus('Confirming…', true);
                const provider = await eth();
                await ensureChain(provider);
                const from = await accounts(provider);
                const multisig = confirmBtn.getAttribute('data-multisig');
                const txId = txInput ? txInput.value : '';
                if (txId) window.localStorage.setItem(storageKey, String(txId));
                const data = encodeConfirm(txId);
                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{ from, to: multisig, data, value: '0x0' }],
                });
                setStatus('Confirm sent. Tx ' + txHash + ' — after 3rd confirmation refresh page; isMinter should be true.', true);
            } catch (e) {
                setStatus(e && e.message ? e.message : String(e), false);
            }
        });
    }
})();
</script>
