<ul>
    @foreach($categories as $category)
        <li>
            <a href="{{ route('admin.categories.show', $category -> id) }}">{{ $category -> name }}</a>
            <form method="POST" action="{{ route('admin.categories.delete', $category->id) }}" class="d-inline">{{ csrf_field() }}<button class="btn btn-outline-danger btn-sm" type="submit">Delete</button></form>
            @if($category -> children -> isNotEmpty())
                <ul class="m-0 p-0">
                    @include('includes.admin.listcategories', ['categories' => $category -> children])
                </ul>
            @endif
        </li>
    @endforeach
</ul>
