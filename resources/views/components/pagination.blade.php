{{--
    Pagination controls around the Laravel paginator links.

    Usage:
        <x-pagination :paginator="$payments" />

    @props:
        paginator   an Illuminate\Pagination\LengthAwarePaginator (or Paginator)
--}}
@props([
    'paginator',
])

@if ($paginator->hasPages())
    <nav class="d-flex justify-content-end mt-4" aria-label="Pagination">
        <div class="text-secondary-custom small me-3 align-self-center">
            Showing {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }}
            of {{ $paginator->total() }}
        </div>
        {{ $paginator->links('pagination::bootstrap-5') }}
    </nav>
@endif