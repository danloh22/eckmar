@extends('master.profile')

@section('title', 'Wallet')

@section('profile-content')
    <h3 class="mb-4">Wallet</h3>
    <div class="alert alert-info">Deposits become available after 10 network confirmations. Withdrawals require your PIN and administrator approval.</div>
    <div class="card mb-4"><div class="card-body">
        <h5>Set or reset withdrawal PIN</h5>
        <form method="POST" action="{{ route('profile.wallet.pin') }}" class="row">
            {{ csrf_field() }}
            <div class="col-md-4"><input class="form-control mb-2" name="mnemonic" placeholder="Mnemonic" required></div>
            <div class="col-md-3"><input class="form-control mb-2" name="pin" inputmode="numeric" maxlength="6" placeholder="New 6-digit PIN" required></div>
            <div class="col-md-3"><input class="form-control mb-2" name="pin_confirmation" inputmode="numeric" maxlength="6" placeholder="Confirm PIN" required></div>
            <div class="col-md-2"><button class="btn btn-primary btn-block" type="submit">Save PIN</button></div>
        </form>
    </div></div>
    <div class="card mb-4"><div class="card-body">
        <h5>Exchange currencies</h5>
        <p class="text-muted">The current market rate is applied when you submit. A 0.3% fee is deducted from the credited currency.</p>
        <form method="POST" action="{{ route('profile.wallet.exchange') }}" class="row align-items-end">
            {{ csrf_field() }}
            <div class="col-md-3"><label>From</label><select class="form-control" name="source_coin">@foreach(['btc','xmr','ltc'] as $coin)<option value="{{ $coin }}">{{ strtoupper($coin) }}</option>@endforeach</select></div>
            <div class="col-md-3"><label>To</label><select class="form-control" name="target_coin">@foreach(['btc','xmr','ltc'] as $coin)<option value="{{ $coin }}">{{ strtoupper($coin) }}</option>@endforeach</select></div>
            <div class="col-md-4"><label>Amount</label><input class="form-control" name="amount" type="number" min="0" step="0.000000000001" required></div>
            <div class="col-md-2"><button class="btn btn-primary btn-block" type="submit">Exchange</button></div>
        </form>
    </div></div>
    <div class="row">
        @foreach($wallets as $coin => $data)
            <div class="col-md-4 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h5>{{ strtoupper($coin) }}</h5>
                        <p class="mb-1">Available: <strong>{{ $data['wallet']->available_display }} {{ strtoupper($coin) }}</strong></p>
                        <p>Reserved: <strong>{{ $data['wallet']->reserved_display }} {{ strtoupper($coin) }}</strong></p>
                        @if($data['address'])
                            <label>Deposit address</label>
                            <textarea class="form-control" rows="4" readonly>{{ $data['address']->address }}</textarea>
                        @else
                            <form method="POST" action="{{ route('profile.wallet.address.create', $coin) }}">
                                {{ csrf_field() }}
                                <button class="btn btn-outline-primary btn-block" type="submit">Create deposit address</button>
                            </form>
                        @endif
                        @if($data['deposits']->isNotEmpty())
                            <h6 class="mt-3">Recent deposits</h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Amount</th><th>Confirmations</th><th>Status</th></tr></thead>
                                    <tbody>
                                    @foreach($data['deposits'] as $deposit)
                                        <tr>
                                            <td>{{ $deposit->amount_display }} {{ strtoupper($coin) }}</td>
                                            <td>{{ $deposit->confirmations }} / {{ config('coins.wallet_confirmations.' . $coin) }}</td>
                                            <td><span class="badge badge-{{ $deposit->status === 'credited' ? 'success' : 'warning' }}">{{ ucfirst($deposit->status) }}</span></td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        <hr>
                        <form method="POST" action="{{ route('profile.wallet.withdrawals.create', $coin) }}">
                            {{ csrf_field() }}
                            <label>Withdraw {{ strtoupper($coin) }}</label>
                            <input class="form-control mb-2" name="amount" type="number" min="0" step="{{ $coin === 'xmr' ? '0.000000000001' : '0.00000001' }}" placeholder="Amount in {{ strtoupper($coin) }}" required>
                            <input class="form-control mb-2" name="destination_address" placeholder="Destination address" required>
                            <input class="form-control mb-2" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6-digit withdrawal PIN" required>
                            <button class="btn btn-outline-secondary btn-block" type="submit">Request withdrawal</button>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    @if($exchanges->isNotEmpty())
        <div class="card mt-4"><div class="card-body"><h5>Exchange history</h5><div class="table-responsive"><table class="table table-sm">
            <thead><tr><th>Date</th><th>From</th><th>To credit</th><th>Fee</th><th>Status</th></tr></thead><tbody>
            @foreach($exchanges as $exchange)<tr><td>{{ $exchange->created_at }}</td><td>{{ $exchange->source_amount_display }} {{ strtoupper($exchange->source_coin) }}</td><td>{{ $exchange->target_amount_display }} {{ strtoupper($exchange->target_coin) }}</td><td>{{ $exchange->fee_amount_display }} {{ strtoupper($exchange->target_coin) }}</td><td><span class="badge badge-success">{{ $exchange->status }}</span></td></tr>@endforeach
            </tbody></table></div></div></div>
    @endif
@stop
