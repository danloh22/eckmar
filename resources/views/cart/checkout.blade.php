@extends('master.main')

@section('title', 'Secure checkout')

@section('content')
    @include('includes.flash.error')
    @include('includes.flash.invalid')
    <div class="market-page-heading mb-3"><span class="market-eyebrow">Final review</span><h2>Secure checkout</h2></div>
    <div class="row align-items-start">
        <div class="col-lg-8">
            @foreach($items as $productId => $item)
                <div class="market-panel checkout-line mb-3">
                    <div class="d-flex flex-wrap justify-content-between">
                        <div><a class="cart-item-title" href="{{ route('product.show', $productId) }}">{{ $item->offer->product->name }}</a><div class="small text-muted">{{ $item->quantity }} × @include('includes.currency', ['usdValue' => $item->offer->price])</div></div>
                        <div class="text-right"><strong>@include('includes.currency', ['usdValue' => $item->value_sum])</strong><div><span class="badge badge-info">{{ strtoupper(\App\Purchase::coinDisplayName($item->coin_name)) }}</span> <span class="badge badge-success">{{ \App\Purchase::$types[$item->type] }}</span></div></div>
                    </div>
                    <hr>
                    <div class="row small">
                        <div class="col-md-5"><strong>Delivery</strong><div class="text-muted">@if($item->shipping){{ $item->shipping->name }} · @include('includes.currency', ['usdValue' => $item->shipping->price])@else Automatic digital delivery @endif</div></div>
                        <div class="col-md-7"><strong>Vendor note</strong><div class="text-muted text-break">{{ $item->message ?: 'No note supplied.' }}</div></div>
                    </div>
                </div>
            @endforeach
            <a href="{{ route('profile.cart') }}" class="btn btn-outline-secondary"><i class="fas fa-chevron-left mr-2"></i>Back to cart</a>
        </div>
        <aside class="col-lg-4 mt-3 mt-lg-0">
            <div class="market-panel cart-summary sticky-lg-top">
                <h5>Pay from wallet</h5>
                <p class="small text-muted">Only confirmed, available wallet funds can be used. Each order is reserved in escrow until delivery or dispute resolution.</p>
                @foreach(['btc', 'xmr', 'ltc'] as $coin)
                    <div class="cart-summary-row"><span>{{ strtoupper($coin) }} balance</span><strong>{{ optional($wallets->get($coin))->available_display ?: number_format(0, $coin === 'xmr' ? 12 : 8, '.', '') }}</strong></div>
                @endforeach
                <div class="cart-summary-total"><span>Order total</span><strong>@include('includes.currency', ['usdValue' => $totalSum])</strong></div>
                <form action="{{ route('profile.cart.make.purchases') }}" method="POST">
                    {{ csrf_field() }}
                    <button type="submit" class="btn btn-success btn-lg btn-block"><i class="fas fa-shield-alt mr-2"></i>Confirm protected purchase</button>
                </form>
                <small class="text-muted d-block mt-3"><i class="fas fa-lock mr-1"></i>The exact coin amount is calculated at confirmation using the current market rate.</small>
            </div>
        </aside>
    </div>
@stop
