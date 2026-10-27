@extends('master.admin')

@section('admin-content')
    @include('includes.flash.success')
    @include('includes.flash.error')
    @include('includes.flash.invalid')
    <h3 class="mb-4">Wallet exchanges and fee addresses</h3>
    @if(auth()->user()->isAdmin())
        <div class="row mb-4">
            @foreach(['btc','xmr','ltc'] as $coin)
                <div class="col-md-4"><div class="card h-100"><div class="card-body">
                    <h5>{{ strtoupper($coin) }} market wallet</h5>
                    <form method="POST" action="{{ route('admin.wallet.fee-addresses.update', $coin) }}">
                        {{ csrf_field() }}
                        <textarea class="form-control mb-2" name="address" rows="3" required>{{ optional($feeWallets->get($coin))->address }}</textarea>
                        <button class="btn btn-primary btn-block">Save address</button>
                    </form>
                    @if(optional($feeWallets->get($coin))->wallet_id)
                        <hr><p class="small text-warning">Only record liquidity after the same amount has been funded in the RPC hot wallet.</p>
                        <form method="POST" action="{{ route('admin.wallet.liquidity.adjust', $coin) }}">
                            {{ csrf_field() }}
                            <select name="action" class="form-control mb-2"><option value="credit">Credit liquidity</option><option value="debit">Debit liquidity</option></select>
                            <input name="amount" class="form-control mb-2" type="number" min="0" step="0.000000000001" placeholder="Amount" required>
                            <input name="reason" class="form-control mb-2" minlength="10" placeholder="Audit reason" required>
                            <button class="btn btn-outline-secondary btn-block">Record adjustment</button>
                        </form>
                    @endif
                </div></div></div>
            @endforeach
        </div>
    @endif
    <div class="table-responsive"><table class="table table-striped table-sm">
        <thead><tr><th>Date</th><th>User</th><th>Source wallet</th><th>Debit</th><th>Target wallet</th><th>Credit</th><th>Fee</th><th>Rate</th></tr></thead>
        <tbody>@forelse($exchanges as $exchange)<tr>
            <td>{{ $exchange->created_at }}</td><td>{{ optional($exchange->user)->username }}</td>
            <td class="text-break">{{ $exchange->source_wallet_id }}</td><td>{{ $exchange->source_amount_display }} {{ strtoupper($exchange->source_coin) }}</td>
            <td class="text-break">{{ $exchange->target_wallet_id }}</td><td>{{ $exchange->target_amount_display }} {{ strtoupper($exchange->target_coin) }}</td>
            <td>{{ $exchange->fee_amount_display }} {{ strtoupper($exchange->target_coin) }}</td><td>{{ $exchange->rate }}</td>
        </tr>@empty<tr><td colspan="8" class="text-center">No exchanges yet.</td></tr>@endforelse</tbody>
    </table></div>{{ $exchanges->links() }}
    <h4 class="mt-4">Automatic fee transfers</h4>
    <div class="alert alert-warning">Before retrying or refunding a failed fee transfer, verify the destination and transaction history directly in the coin node. An RPC timeout can occur after a transaction was accepted.</div>
    <div class="table-responsive"><table class="table table-sm">
        <thead><tr><th>Date</th><th>Coin</th><th>Amount</th><th>Destination</th><th>Status</th><th>Transaction</th><th>Action</th></tr></thead>
        <tbody>@forelse($feeSweeps as $sweep)<tr><td>{{ $sweep->created_at }}</td><td>{{ strtoupper($sweep->coin) }}</td><td>{{ $sweep->amount_display }} {{ strtoupper($sweep->coin) }}</td><td class="text-break">{{ $sweep->destination_address }}</td><td>{{ $sweep->status }}@if($sweep->resolution)<br><span class="badge badge-secondary">{{ $sweep->resolution }}</span>@endif</td><td class="text-break">{{ $sweep->transaction_hash ?: $sweep->error }}@if($sweep->resolution_note)<div class="small text-muted">{{ $sweep->resolution_note }}</div>@endif</td><td>
            @if($sweep->status === 'failed' && !$sweep->resolution && auth()->user()->isAdmin())
                <form method="POST" action="{{ route('admin.wallet.fee-sweeps.resolve-failed', $sweep) }}">
                    {{ csrf_field() }}
                    <input class="form-control form-control-sm mb-2" name="reason" minlength="10" maxlength="500" placeholder="Node verification and audit reason" required>
                    <button class="btn btn-sm btn-warning" name="resolution" value="retry" type="submit">Retry</button>
                    <button class="btn btn-sm btn-outline-danger" name="resolution" value="refund" type="submit">Return to reserve</button>
                </form>
            @endif
        </td></tr>@empty<tr><td colspan="7" class="text-center">No fee transfers yet.</td></tr>@endforelse</tbody>
    </table></div>
@stop
