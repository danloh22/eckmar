@extends('master.main')

@section('title', 'Your cart')

@section('content')
    @include('includes.flash.error')
    @include('includes.flash.success')
    @include('includes.flash.invalid')

    <div class="market-page-heading d-flex flex-wrap align-items-center justify-content-between mb-3">
        <div>
            <span class="market-eyebrow">Secure checkout</span>
            <h2 class="mb-0">Your cart <span class="text-muted">({{ $numberOfItems }})</span></h2>
        </div>
        @if(!empty($items))
            <form action="{{ route('profile.cart.clear') }}" method="POST">
                {{ csrf_field() }}
                <button class="btn btn-outline-danger" type="submit"><i class="fas fa-trash-alt mr-2"></i>Clear cart</button>
            </form>
        @endif
    </div>

    @if(!empty($items))
        <div class="row align-items-start">
            <div class="col-lg-8">
                @foreach($items as $productId => $item)
                    <article class="market-panel cart-item mb-3">
                        <form action="{{ route('profile.cart.add', \App\Product::find($productId)) }}" method="POST">
                            {{ csrf_field() }}
                            <div class="row">
                                <div class="col-sm-3 col-xl-2 mb-3 mb-sm-0">
                                    <a href="{{ route('product.show', $item->offer->product) }}">
                                        <img class="cart-item-image" src="{{ asset('storage/' . $item->offer->product->frontImage()->image) }}" alt="{{ $item->offer->product->name }}">
                                    </a>
                                </div>
                                <div class="col-sm-9 col-xl-10">
                                    <div class="d-flex flex-wrap justify-content-between">
                                        <div>
                                            <a class="cart-item-title" href="{{ route('product.show', $item->offer->product) }}">{{ $item->offer->product->name }}</a>
                                            <div class="small text-muted">Sold by <a href="{{ route('vendor.show', $item->offer->product->user) }}">{{ $item->vendor->user->username }}</a></div>
                                        </div>
                                        <div class="cart-item-price text-sm-right">
                                            <strong>@include('includes.currency', ['usdValue' => $item->value_sum])</strong>
                                            <small>{{ strtoupper(\App\Purchase::coinDisplayName($item->coin_name)) }}</small>
                                        </div>
                                    </div>

                                    <div class="row mt-3">
                                        <div class="col-md-2 mb-2"><label>Quantity</label><input type="number" class="form-control" name="amount" min="1" max="{{ $item->offer->product->quantity }}" value="{{ $item->quantity }}" required></div>
                                        <div class="col-md-3 mb-2"><label>Pay with</label>
                                            @if(count($item->offer->product->getCoins()) > 1)
                                                <select name="coin" class="form-control">@foreach($item->offer->product->getCoins() as $coin)<option value="{{ $coin }}" {{ $coin == $item->coin_name ? 'selected' : '' }}>{{ strtoupper(\App\Purchase::coinDisplayName($coin)) }}</option>@endforeach</select>
                                            @else
                                                <input type="hidden" name="coin" value="{{ $item->offer->product->getCoins()[0] }}"><input class="form-control" value="{{ strtoupper(\App\Purchase::coinDisplayName($item->offer->product->getCoins()[0])) }}" disabled>
                                            @endif
                                        </div>
                                        <div class="col-md-4 mb-2"><label>Delivery</label>
                                            @if($item->offer->product->isPhysical())
                                                <select name="delivery" class="form-control">@foreach($item->offer->product->specificProduct()->shippings as $shipping)<option value="{{ $shipping->id }}" @if($shipping->id == $item->shipping->id) selected @endif>{{ $shipping->long_name }}</option>@endforeach</select>
                                            @else
                                                <div class="cart-delivery-badge"><i class="fas fa-bolt mr-2"></i>Automatic digital delivery</div>
                                            @endif
                                        </div>
                                        <div class="col-md-3 mb-2"><label>Protection</label>
                                            @if(count($item->offer->product->getTypes()) > 1)
                                                <select name="type" class="form-control">@foreach($item->offer->product->getTypes() as $type)<option value="{{ $type }}" {{ $type == $item->type ? 'selected' : '' }}>{{ \App\Purchase::$types[$type] }}</option>@endforeach</select>
                                            @else
                                                <input type="hidden" name="type" value="{{ $item->offer->product->getTypes()[0] }}"><input class="form-control" value="{{ \App\Purchase::$types[$item->offer->product->getTypes()[0]] }}" disabled>
                                            @endif
                                        </div>
                                    </div>
                                    <label class="mt-2">Encrypted note to vendor</label>
                                    <textarea name="message" rows="2" class="form-control" placeholder="Shipping details or other information for the vendor">{{ $item->message }}</textarea>
                                    <div class="d-flex justify-content-between align-items-center mt-3">
                                        <small class="text-muted"><i class="fas fa-lock mr-1"></i>Your note is protected with the vendor's PGP key.</small>
                                        <div>
                                            <button type="submit" class="btn btn-sm btn-info mr-2"><i class="fas fa-sync-alt mr-1"></i>Update</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </form>
                        <form class="cart-remove-form" action="{{ route('profile.cart.remove', $productId) }}" method="POST">{{ csrf_field() }}<button type="submit" class="btn btn-link text-danger" aria-label="Remove {{ $item->offer->product->name }}"><i class="fas fa-times"></i></button></form>
                    </article>
                @endforeach
            </div>
            <aside class="col-lg-4">
                <div class="market-panel cart-summary sticky-lg-top">
                    <h5>Order summary</h5>
                    <div class="cart-summary-row"><span>Items</span><strong>{{ $numberOfItems }}</strong></div>
                    <div class="cart-summary-row"><span>Wallet payment</span><span class="text-success"><i class="fas fa-shield-alt mr-1"></i>Escrow protected</span></div>
                    <div class="cart-summary-total"><span>Total</span><strong>@include('includes.currency', ['usdValue' => $totalSum])</strong></div>
                    <p class="small text-muted">The required coin amount is fixed at checkout using the current rate. Purchases use your available wallet balance.</p>
                    <a href="{{ route('profile.cart.checkout') }}" class="btn btn-success btn-lg btn-block"><i class="fas fa-lock mr-2"></i>Continue to checkout</a>
                </div>
            </aside>
        </div>
    @else
        <div class="market-panel market-empty-state text-center py-5"><i class="fas fa-shopping-cart fa-3x mb-3"></i><h4>Your cart is empty</h4><p class="text-muted">Explore the marketplace and add a product to continue.</p><a href="{{ route('home') }}" class="btn btn-info">Browse products</a></div>
    @endif
@stop
