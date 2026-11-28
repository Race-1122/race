<div class="bg-white rounded shadow-sm p-4 mb-3">
    <p class="mb-2"><strong>{{ __('RACE token') }}:</strong>
        <code class="user-select-all">{{ ($fee['token'] ?? '') !== '' ? $fee['token'] : '—' }}</code>
    </p>
    <p class="mb-2 small text-muted">
        {{ __('Every taxed RACE transfer pays a 4% fee: 1% to each of the 4 wallets below. Read-only — nothing can be changed or withdrawn from here.') }}
    </p>
    <p class="mb-0 small">
        <strong>{{ __('Total in these 4 wallets') }}:</strong>
        {{ ($fee['total_race'] ?? null) !== null ? $fee['total_race'].' RACE' : '—' }}
        · <span class="text-muted">{{ __('Last updated') }}: <span id="race-fee-updated">{{ $fee['updated_at'] ?? '' }}</span>
        · {{ __('auto-refresh every 30s') }}</span>
    </p>
    @if (! empty($fee['error']))
        <p class="mb-0 mt-2 small text-danger">{{ $fee['error'] }}</p>
    @endif
</div>

@foreach (($fee['recipients'] ?? []) as $row)
    <div class="bg-white rounded shadow-sm p-4 mb-3">
        <h5 class="mb-1">{{ $row['label'] }}</h5>
        <p class="text-muted small mb-2">{{ $row['role'] }} · {{ $row['share'] }}</p>
        <p class="mb-1"><strong>{{ __('Address') }}:</strong>
            <code class="user-select-all">{{ $row['address'] !== '' ? $row['address'] : __('Could not read from contract') }}</code>
            @if ($row['url'] !== '')
                · <a href="{{ $row['url'] }}" target="_blank" rel="noopener">BscScan</a>
            @endif
        </p>
        <p class="mb-0"><strong>{{ __('Current balance') }}:</strong>
            {{ $row['balance'] !== null ? $row['balance'].' RACE' : '—' }}
        </p>
    </div>
@endforeach

<script>
    (function () {
        if (window.__raceFeeWalletsTimer) {
            clearInterval(window.__raceFeeWalletsTimer);
        }
        window.__raceFeeWalletsTimer = setInterval(function () {
            if (!location.pathname.endsWith('/data/race-fee-wallets')) {
                clearInterval(window.__raceFeeWalletsTimer);
                return;
            }
            if (document.hidden) {
                return;
            }
            location.reload();
        }, 30000);
    })();
</script>
