@extends('master.admin')

@section('admin-content')
    @include('includes.flash.success')
    @include('includes.flash.error')
    @include('includes.flash.invalid')
    <h3 class="mb-4">Withdrawal approvals</h3>
    <div class="alert alert-warning">Only administrators can approve, reject, or resolve withdrawals. Before retrying or refunding a failed broadcast, verify the transaction directly in the coin node to prevent a duplicate payment.</div>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Created</th><th>User</th><th>Coin</th><th>Amount</th><th>Destination</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        @forelse($withdrawals as $withdrawal)
            <tr>
                <td>{{ $withdrawal->created_at }}</td>
                <td>{{ optional($withdrawal->wallet->user)->username }}</td>
                <td>{{ strtoupper($withdrawal->coin) }}</td>
                <td>{{ $withdrawal->amount_display }} {{ strtoupper($withdrawal->coin) }}</td>
                <td class="text-break">{{ $withdrawal->destination_address }}</td>
                <td>
                    <span class="badge badge-{{ $withdrawal->status === 'broadcast' ? 'success' : ($withdrawal->status === 'failed' ? 'danger' : 'warning') }}">{{ str_replace('_', ' ', $withdrawal->status) }}</span>
                    @if($withdrawal->transaction_hash)<div class="small text-break mt-1">TX: {{ $withdrawal->transaction_hash }}</div>@endif
                    @if($withdrawal->admin_note)<div class="small text-danger mt-1">{{ $withdrawal->admin_note }}</div>@endif
                </td>
                <td>
                    @if($withdrawal->status === 'pending_approval' && auth()->user()->isAdmin())
                        <form class="d-inline" method="POST" action="{{ route('admin.wallet.withdrawals.approve', $withdrawal) }}">{{ csrf_field() }}<button class="btn btn-sm btn-success" type="submit">Approve</button></form>
                        <form class="d-inline" method="POST" action="{{ route('admin.wallet.withdrawals.reject', $withdrawal) }}">{{ csrf_field() }}<button class="btn btn-sm btn-danger" type="submit">Reject</button></form>
                    @elseif($withdrawal->status === 'failed' && auth()->user()->isAdmin())
                        <form method="POST" action="{{ route('admin.wallet.withdrawals.resolve-failed', $withdrawal) }}" class="wallet-resolution-form">
                            {{ csrf_field() }}
                            <input class="form-control form-control-sm mb-2" name="reason" minlength="10" maxlength="500" placeholder="Node verification and audit reason" required>
                            <button class="btn btn-sm btn-warning" type="submit" name="resolution" value="retry">Retry broadcast</button>
                            <button class="btn btn-sm btn-outline-danger" type="submit" name="resolution" value="refund">Return funds</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center">No withdrawal requests.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $withdrawals->links() }}
@stop
