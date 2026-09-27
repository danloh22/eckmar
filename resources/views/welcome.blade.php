@extends('master.main')

@section('title', 'Marketplace')

@section('content')
    <section class="market-hero market-panel mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <span class="market-eyebrow">Wallet-funded · Escrow protected</span>
                <h1 class="mt-2">A private marketplace built around safer transactions.</h1>
                <p class="lead text-muted mb-4">Fund your BTC, XMR or LTC wallet, choose a verified offer and keep every purchase protected until delivery or dispute resolution.</p>
                <div class="d-flex flex-wrap">
                    <a href="#market-products" class="btn btn-info btn-lg mr-2 mb-2"><i class="fas fa-store mr-2"></i>Explore offers</a>
                    @auth<a href="{{ route('profile.wallet') }}" class="btn btn-outline-secondary btn-lg mb-2"><i class="fas fa-wallet mr-2"></i>Open wallet</a>@else<a href="{{ route('auth.signup') }}" class="btn btn-outline-secondary btn-lg mb-2">Create account</a>@endauth
                </div>
            </div>
            <div class="col-lg-4 mt-4 mt-lg-0">
                <div class="market-trust-list">
                    <div><i class="fas fa-check-circle"></i><span><strong>10 confirmations</strong><small>before deposits become spendable</small></span></div>
                    <div><i class="fas fa-shield-alt"></i><span><strong>Internal escrow</strong><small>with auditable balance reservations</small></span></div>
                    <div><i class="fas fa-coins"></i><span><strong>Three currencies</strong><small>BTC, XMR and LTC wallets</small></span></div>
                </div>
            </div>
        </div>
    </section>

    @isModuleEnabled('FeaturedProducts')
        @include('featuredproducts::frontpagedisplay')
    @endisModuleEnabled

    <div class="row" id="market-products">
        <aside class="col-lg-3 mb-4">
            <div class="market-panel market-category-panel">@include('includes.categories')</div>
        </aside>
        <div class="col-lg-9">
            <div class="d-flex justify-content-between align-items-end mb-3"><div><span class="market-eyebrow">Marketplace</span><h3 class="mb-0">Latest active offers</h3></div><span class="text-muted small">{{ $products->total() }} listings</span></div>
            <div class="row">
                @forelse($products as $product)
                    <div class="col-md-6 col-xl-4 mb-3">@include('includes.product.card', ['product' => $product])</div>
                @empty
                    <div class="col-12"><div class="market-panel market-empty-state text-center py-5"><i class="fas fa-box-open fa-3x mb-3"></i><h4>No active offers</h4><p class="text-muted">New marketplace listings will appear here.</p></div></div>
                @endforelse
            </div>
            {{ $products->links() }}
        </div>
    </div>

    <section class="row mt-4">
        <div class="col-md-4 mb-3"><div class="market-panel h-100"><span class="market-eyebrow">Reputation</span><h5>Top vendors</h5>@forelse(\App\Vendor::topVendors() as $vendor)<div class="market-stat-row"><a href="{{ route('vendor.show', $vendor) }}">{{ $vendor->user->username }}</a><span>Level {{ $vendor->getLevel() }}</span></div>@empty<p class="text-muted small">No vendor statistics yet.</p>@endforelse</div></div>
        <div class="col-md-4 mb-3"><div class="market-panel h-100"><span class="market-eyebrow">Activity</span><h5>Latest orders</h5>@forelse(\App\Purchase::latestOrders() as $order)<div class="market-stat-row"><span>{{ str_limit($order->offer->product->name, 28, '…') }}</span><strong>{{ $order->getSumLocalCurrency() }} {{ $order->getLocalSymbol() }}</strong></div>@empty<p class="text-muted small">No completed activity yet.</p>@endforelse</div></div>
        <div class="col-md-4 mb-3"><div class="market-panel h-100"><span class="market-eyebrow">Discover</span><h5>Rising vendors</h5>@forelse(\App\Vendor::risingVendors() as $vendor)<div class="market-stat-row"><a href="{{ route('vendor.show', $vendor) }}">{{ $vendor->user->username }}</a><span>Level {{ $vendor->getLevel() }}</span></div>@empty<p class="text-muted small">No rising vendors yet.</p>@endforelse</div></div>
    </section>
@stop
