@extends('master.admin')

@section('admin-content')
    @include('includes.flash.success')
    @include('includes.flash.error')
    <h3>User wallets</h3>
    <form method="GET" class="form-row mb-3">
        <div class="col-md-5"><input class="form-control" name="username" value="{{ request('username') }}" placeholder="Username"></div>
        <div class="col-md-3"><select class="form-control" name="coin"><option value="">All currencies</option>@foreach(['btc','xmr','ltc'] as $coin)<option value="{{ $coin }}" @if(request('coin') === $coin) selected @endif>{{ strtoupper($coin) }}</option>@endforeach</select></div>
        <div class="col-md-2"><button class="btn btn-primary btn-block">Filter</button></div>
    </form>
    @foreach($wallets as $wallet)
        <div class="card mb-3"><div class="card-body">
            <div class="row"><div class="col-md-7"><h5>{{ optional($wallet->user)->username }} — {{ strtoupper($wallet->coin) }}</h5><p>Available: <strong>{{ $wallet->available_display }}</strong> · Reserved: <strong>{{ $wallet->reserved_display }}</strong> · Status: <span class="badge badge-{{ $wallet->status === 'active' ? 'success' : 'danger' }}">{{ $wallet->status }}</span></p><p class="small text-muted text-break">@foreach($wallet->depositAddresses as $address){{ $address->address }}<br>@endforeach</p></div>
                <div class="col-md-5">
                    @if(auth()->user()->isAdmin())
                        <form class="mb-2" method="POST" action="{{ route('admin.wallets.status', [$wallet, $wallet->status === 'active' ? 'frozen' : 'active']) }}">{{ csrf_field() }}<button class="btn btn-sm btn-{{ $wallet->status === 'active' ? 'danger' : 'success' }}">{{ $wallet->status === 'active' ? 'Freeze' : 'Unfreeze' }}</button></form>
                        <form method="POST" action="{{ route('admin.wallets.adjust', $wallet) }}">{{ csrf_field() }}<div class="form-row"><div class="col-3"><select name="action" class="form-control"><option value="credit">Credit</option><option value="debit">Debit</option></select></div><div class="col-3"><input name="amount" type="number" min="0" step="0.000000000001" class="form-control" placeholder="Amount" required></div><div class="col-4"><input name="reason" minlength="10" class="form-control" placeholder="Audit reason" required></div><div class="col-2"><button class="btn btn-outline-primary">Apply</button></div></div></form>
                    @endif
                </div></div>
        </div></div>
    @endforeach
    {{ $wallets->appends(request()->query())->links() }}
@stop
