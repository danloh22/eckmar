@extends('master.admin')

@section('admin-content')
    <h3 class="mb-4">Withdrawal approvals</h3>
    <div class="alert alert-warning">Only administrators can approve or reject withdrawals. Approval queues a request for the secure broadcast worker.</div>
    <div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>Created</th><th>User</th><th>Coin</th><th>Amount</th><th>Destination</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>@forelse($withdrawals as $withdrawal)
            <tr><td>{{ $withdrawal->created_at }}</td><td>{{ optional($withdrawal->wallet->user)->username }}</td><td>{{ strtoupper($withdrawal->coin) }}</td><td>{{ $withdrawal->amount_atomic }}</td><td class="text-break">{{ $withdrawal->destination_address }}</td><td>{{ $withdrawal->status }}</td><td>
                @if($withdrawal->status === 'pending_approval' && auth()->user()->isAdmin())
                    <form class="d-inline" method="POST" action="{{ route('admin.wallet.withdrawals.approve', $withdrawal) }}">{{ csrf_field() }}<button class="btn btn-sm btn-success">Approve</button></form>
                    <form class="d-inline" method="POST" action="{{ route('admin.wallet.withdrawals.reject', $withdrawal) }}">{{ csrf_field() }}<button class="btn btn-sm btn-danger">Reject</button></form>
                @endif
            </td></tr>
        @empty<tr><td colspan="7" class="text-center">No withdrawal requests.</td></tr>@endforelse</tbody>
    </table></div>
    {{ $withdrawals->links() }}
@stop
