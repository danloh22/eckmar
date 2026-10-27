@search
    <div class="market-search-shell">
        <div class="container">
            <form action="{{ route('search') }}" method="POST" class="market-search-form">
                {{ csrf_field() }}
                <i class="fas fa-search" aria-hidden="true"></i>
                <label class="sr-only" for="search">Search marketplace</label>
                <input type="search" class="form-control" id="search" name="search" placeholder="Search products, vendors or categories…" value="{{ app('request')->input('query') }}">
                <button class="btn btn-info" type="submit">Search</button>
            </form>
        </div>
    </div>
@endsearch
