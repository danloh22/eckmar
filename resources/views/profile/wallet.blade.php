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
    <div class="row">
        @foreach($wallets as $coin => $data)
            <div class="col-md-4 mb-3">
                <div class="card h-100">
                    <div class="card-body">
                        <h5>{{ strtoupper($coin) }}</h5>
                        <p class="mb-1">Available: <strong>{{ $data['wallet']->available_atomic }}</strong> atomic units</p>
                        <p>Reserved: <strong>{{ $data['wallet']->reserved_atomic }}</strong> atomic units</p>
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
                                            <td>{{ $deposit->amount_atomic }}</td>
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
                            <input class="form-control mb-2" name="amount_atomic" inputmode="numeric" placeholder="Amount in atomic units" required>
                            <input class="form-control mb-2" name="destination_address" placeholder="Destination address" required>
                            <input class="form-control mb-2" name="pin" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6-digit withdrawal PIN" required>
                            <button class="btn btn-outline-secondary btn-block" type="submit">Request withdrawal</button>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@stop
