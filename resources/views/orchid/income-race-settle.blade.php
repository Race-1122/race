@php
    /** @var \App\Models\IncomeRaceSettlement|null $active_settlement */
    $active = $active_settlement ?? null;
@endphp

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('How this works') }}</h5>
    <ol class="mb-2 small">
        <li>{{ __('Member virtual income stays USDT-notional until settled.') }}</li>
        <li>{{ __('Admin queues a settlement (USDT → RACE at live price).') }}</li>
        <li>{{ __('3-of-5 Multisig signers submit + confirm an ERC-20 transfer of that RACE from the Multisig wallet to the member.') }}</li>
        <li>{{ __('After the on-chain tx succeeds, paste the tx hash and Mark completed — virtual USDT is debited so it cannot be paid twice.') }}</li>
    </ol>
    <p class="small mb-0 text-danger">
        <strong>{{ __('Important') }}:</strong>
        {{ __('RaceRewardVault mint-from-Laravel is locked. This path transfers RACE the Multisig already holds — it does not mint new supply via admin key.') }}
    </p>
</div>

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('Snapshot') }}</h5>
    <p class="mb-1 small"><strong>{{ __('Live RACE price') }}:</strong> ${{ $race_price_usd ?? '—' }}</p>
    <p class="mb-1 small"><strong>{{ __('Eligible members (≥ $1 virtual income)') }}:</strong> {{ $eligible_count ?? 0 }}</p>
    <p class="mb-1 small"><strong>{{ __('Multisig') }}:</strong> <code class="user-select-all">{{ $multisig_contract ?? '' }}</code></p>
    <p class="mb-0 small"><strong>{{ __('RACE token') }}:</strong> <code class="user-select-all">{{ $race_token ?? '' }}</code></p>
</div>

@if (!empty($eligible_rows))
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('Eligible (top scan)') }}</h5>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>{{ __('Member') }}</th>
                    <th>USDT</th>
                    <th>RACE</th>
                    <th>{{ __('Wallet') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($eligible_rows as $row)
                    <tr>
                        <td>{{ $row['id'] }}</td>
                        <td>{{ $row['member'] }}</td>
                        <td>{{ $row['usdt'] }}</td>
                        <td>{{ $row['race'] }}</td>
                        <td><code class="user-select-all">{{ $row['wallet'] }}</code></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div class="bg-white rounded shadow-sm p-4 mb-3">
    <h5 class="mb-2">{{ __('Multisig RACE transfer — TokenPocket') }}</h5>
    @if ($active)
        <p class="small mb-2">
            <strong>{{ __('Active settlement') }}:</strong> #{{ $active->id }}
            · {{ $active->usdt_amount }} USDT → {{ $active->race_amount }} RACE
            · {{ __('status') }}: {{ $active->status }}
        </p>
        <p class="small mb-2"><strong>{{ __('To') }}:</strong> <code class="user-select-all">{{ $active->wallet_address }}</code></p>
        <p class="small mb-3"><strong>{{ __('Calldata') }}:</strong> <code class="user-select-all" style="word-break:break-all">{{ $active->transfer_calldata }}</code></p>
        <ol class="small mb-3">
            <li>{{ __('Multisig wallet must hold at least this RACE amount.') }}</li>
            <li>{{ __('Open BNB Smart Chain in TokenPocket with a Multisig signer (not a random admin hot wallet).') }}</li>
            <li>{{ __('Tap 1. Submit — calls Multisig.submitTransaction(RACE, 0, transferCalldata).') }}</li>
            <li>{{ __('Save the Multisig tx id in the form below.') }}</li>
            <li>{{ __('Two more signers tap 2. Confirm until execution.') }}</li>
            <li>{{ __('Paste the BSC execution tx hash and Mark completed.') }}</li>
        </ol>
        <p class="small mb-2"><strong>{{ __('Signers (3-of-5)') }}:</strong></p>
        <ul class="small mb-3">
            @foreach ($multisig_signers ?? [] as $signer)
                <li><code class="user-select-all">{{ $signer }}</code></li>
            @endforeach
        </ul>
        <p class="small mb-2" id="race-settle-status"></p>
        <div class="d-flex flex-wrap gap-2 mb-2">
            <button type="button" class="btn btn-success" id="race-settle-submit"
                data-multisig="{{ $multisig_contract }}"
                data-token="{{ $race_token }}"
                data-calldata="{{ $active->transfer_calldata }}">
                {{ __('1. Submit Multisig transfer') }}
            </button>
            <button type="button" class="btn btn-outline-success" id="race-settle-confirm"
                data-multisig="{{ $multisig_contract }}"
                data-txid="{{ $active->multisig_tx_id }}">
                {{ __('2. Confirm Multisig tx') }}
            </button>
        </div>
    @else
        <p class="small mb-0 text-muted">{{ __('Queue a settlement first (member user ID + Queue settlement).') }}</p>
    @endif
</div>

<script>
(function () {
    const statusEl = document.getElementById('race-settle-status');
    const setStatus = (msg, ok) => {
        if (!statusEl) return;
        statusEl.textContent = msg || '';
        statusEl.className = 'small mb-2 ' + (ok ? 'text-success' : 'text-danger');
    };

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

    // submitTransaction(address,uint256,bytes) — selector 0xc6427474
    function encodeSubmit(token, calldataHex) {
        const to = token.replace(/^0x/i, '').toLowerCase().padStart(64, '0');
        const value = ''.padStart(64, '0');
        const data = (calldataHex || '').replace(/^0x/i, '').toLowerCase();
        const dataLen = (data.length / 2);
        // dynamic bytes offset = 3 * 32 = 96
        const offset = (96).toString(16).padStart(64, '0');
        const len = dataLen.toString(16).padStart(64, '0');
        const padded = data + (data.length % 64 === 0 ? '' : '0'.repeat(64 - (data.length % 64)));
        return '0xc6427474' + to + value + offset + len + padded;
    }

    // confirmTransaction(uint256) — selector 0xc01a8c84
    function encodeConfirm(txId) {
        const id = Number(txId);
        if (!Number.isFinite(id) || id < 0) throw new Error('Set Multisig tx id first (Save Multisig tx id).');
        return '0xc01a8c84' + id.toString(16).padStart(64, '0');
    }

    const submitBtn = document.getElementById('race-settle-submit');
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
                setStatus('Submitted. Tx ' + txHash + ' — read Multisig tx id from contract events / BscScan, save it in the form, then confirm with 2 more signers.', true);
            } catch (e) {
                setStatus(e && e.message ? e.message : String(e), false);
            }
        });
    }

    const confirmBtn = document.getElementById('race-settle-confirm');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', async () => {
            try {
                setStatus('Confirming…', true);
                const provider = await eth();
                await ensureChain(provider);
                const from = await accounts(provider);
                const multisig = confirmBtn.getAttribute('data-multisig');
                const txId = confirmBtn.getAttribute('data-txid') || document.querySelector('input[name="multisig_tx_id"]')?.value;
                const data = encodeConfirm(txId);
                const txHash = await provider.request({
                    method: 'eth_sendTransaction',
                    params: [{ from, to: multisig, data, value: '0x0' }],
                });
                setStatus('Confirm sent. Tx ' + txHash + ' — after 3rd confirmation paste execution hash and Mark completed.', true);
            } catch (e) {
                setStatus(e && e.message ? e.message : String(e), false);
            }
        });
    }
})();
</script>
