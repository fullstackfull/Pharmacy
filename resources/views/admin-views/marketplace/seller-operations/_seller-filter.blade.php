{{-- One shop at a time, when an operator already knows which one they are here about. --}}
<form method="GET" class="d-flex gap-2 align-items-end mb-3">
    <div>
        <label class="form-label fs-12 mb-1">{{ translate('seller_id') }}</label>
        <input type="number" name="seller_id" class="form-control form-control-sm" style="max-width:140px"
               value="{{ request('seller_id') }}" placeholder="{{ translate('all_sellers') }}">
    </div>
    <button class="btn btn--sm btn--primary" type="submit">{{ translate('filter') }}</button>
    @if (request()->hasAny(['seller_id', 'status']))
        <a class="btn btn--sm btn-outline-secondary" href="{{ url()->current() }}">{{ translate('clear') }}</a>
    @endif
</form>
