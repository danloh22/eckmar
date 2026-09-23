<footer class="market-footer mt-5" data-rates-url="{{ route('market.rates') }}">
    <div class="container py-5">
        <div class="row">
            <div class="col-lg-3 mb-4">
                <div class="market-footer-brand">{{ strtoupper(config('app.name')) }}</div>
                <p class="text-muted mt-3 mb-1">Secure marketplace payments with BTC, XMR and LTC.</p>
                <small class="text-muted">Deposits require 10 confirmations. Digital escrow releases after 48 hours if no dispute is open.</small>
            </div>
            <div class="col-lg-3 mb-4">
                <h6 class="market-footer-title">Resources</h6>
                @forelse(config('marketplace.footer_resources', []) as $label => $url)
                    <a class="market-footer-link d-block" href="{{ $url }}" rel="noopener noreferrer nofollow" target="_blank">{{ $label }}</a>
                @empty
                    <span class="text-muted small">Resource links can be configured by the administrator.</span>
                @endforelse
            </div>
            <div class="col-lg-6 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="market-footer-title mb-0">Live rates</h6>
                    <small id="market-rates-status" class="text-muted">Loading current rates…</small>
                </div>
                <div id="market-rates" class="row" aria-live="polite">
                    @foreach(['BTC', 'XMR', 'LTC'] as $coin)
                        <div class="col-md-4 mb-3"><div class="market-rate-card"><strong>{{ $coin }}</strong><div class="market-rate-values text-muted">Waiting for rates…</div></div></div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="market-footer-bottom d-flex flex-wrap justify-content-between align-items-center pt-3">
            <span id="market-clock" class="text-muted"></span>
            <nav>
                <a href="{{ route('home') }}">Home</a>
                @auth
                    <a href="{{ route('profile.tickets') }}">Support</a>
                    <a href="{{ route('profile.wallet') }}">Wallet</a>
                    <a href="{{ route('profile.pgp') }}">PGP</a>
                @else
                    <a href="{{ route('auth.signin') }}">Support</a>
                    <a href="{{ route('auth.signin') }}">Wallet</a>
                @endauth
            </nav>
        </div>
    </div>
</footer>
<script>
(function () {
    var footer = document.querySelector('.market-footer');
    if (!footer) return;
    var currencies = ['USD', 'EUR', 'GBP', 'AUD', 'CAD'];
    var coins = ['BTC', 'XMR', 'LTC'];
    var status = document.getElementById('market-rates-status');
    function updateClock() {
        document.getElementById('market-clock').textContent = new Date().toISOString().replace('T', ' ').slice(0, 19) + ' UTC';
    }
    function loadRates() {
        fetch(footer.getAttribute('data-rates-url'), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (response) { if (!response.ok) throw new Error('rates unavailable'); return response.json(); })
            .then(function (payload) {
                var cards = document.querySelectorAll('.market-rate-values');
                coins.forEach(function (coin, index) {
                    cards[index].innerHTML = currencies.map(function (currency) {
                        return '<div><span>' + currency + '</span><strong>' + Number(payload.rates[coin][currency]).toLocaleString(undefined, {maximumFractionDigits: 2}) + '</strong></div>';
                    }).join('');
                });
                status.textContent = (payload.stale ? 'Last known rates · ' : 'Updated · ') + new Date(payload.updated_at).toLocaleTimeString();
            })
            .catch(function () { status.textContent = 'Rates temporarily unavailable'; });
    }
    updateClock();
    loadRates();
    window.setInterval(updateClock, 1000);
    window.setInterval(loadRates, 60000);
}());
</script>
