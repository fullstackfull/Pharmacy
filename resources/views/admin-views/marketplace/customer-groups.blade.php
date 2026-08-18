@extends('layouts.admin.app')

@section('title', translate('customer_groups'))

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2"><i class="fi fi-rr-users-alt"></i> {{ translate('customer_groups') }} <span class="fs-12 text-muted">(B2B)</span></h2>
            <p class="mb-0 fs-12">{{ translate('wholesale_and_contract_pricing_a_customer_in_no_group_keeps_the_base_price') }}.</p>
        </div>

        <div class="row">
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('add_group') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.marketplace.customer-groups.store') }}" method="post" class="row g-2">
                            @csrf
                            <div class="col-12"><label class="form-label fs-12">{{ translate('name') }} *</label>
                                <input type="text" name="name" class="form-control form-control-sm" required maxlength="191"></div>
                            <div class="col-7"><label class="form-label fs-12">{{ translate('type') }}</label>
                                <input type="text" name="type" class="form-control form-control-sm" value="wholesale" maxlength="40"></div>
                            <div class="col-5"><label class="form-label fs-12">{{ translate('default_discount') }} %</label>
                                <input type="number" step="0.01" name="default_discount_percent" class="form-control form-control-sm" min="0" max="100" value="0"></div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary btn-sm">{{ translate('save') }}</button></div>
                        </form>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="mb-0">{{ translate('groups') }}</h5></div>
                    <div class="list-group list-group-flush">
                        @forelse ($groups as $g)
                            <a href="{{ route('admin.marketplace.customer-groups.index', ['group_id' => $g->id]) }}"
                               class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ ($selected && $selected->id === $g->id) ? 'active' : '' }}">
                                <span>{{ $g->name }} <span class="fs-10">({{ $g->type }})</span></span>
                                <span class="k-badge">{{ rtrim(rtrim(number_format((float)$g->default_discount_percent,2),'0'),'.') }}%</span>
                            </a>
                        @empty
                            <div class="list-group-item text-muted text-center py-3">{{ translate('no_groups_yet') }}</div>
                        @endforelse
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('price_preview') }}</h5></div>
                    <div class="card-body">
                        <form method="get" class="row g-2">
                            <div class="col-4"><input type="number" name="preview_product_id" value="{{ $preview['input']['preview_product_id'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('product_id') }}"></div>
                            <div class="col-4"><input type="number" name="preview_customer_id" value="{{ $preview['input']['preview_customer_id'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('customer_id') }}"></div>
                            <div class="col-4"><input type="number" step="0.01" name="preview_base" value="{{ $preview['input']['preview_base'] ?? '' }}" class="form-control form-control-sm" placeholder="{{ translate('base_price') }}"></div>
                            <div class="col-12 d-flex justify-content-end"><button class="btn btn-outline-primary btn-sm">{{ translate('preview') }}</button></div>
                        </form>
                        @if ($preview)
                            <hr class="my-2">
                            <div class="fs-13">{{ translate('base') }}: {{ number_format($preview['result']['base'], 2) }} →
                                <strong class="{{ $preview['result']['price'] < $preview['result']['base'] ? 'text-success' : '' }}">{{ number_format($preview['result']['price'], 2) }}</strong>
                                <span class="fs-10 text-muted">({{ translate($preview['result']['source']) }})</span>
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                @if ($selected)
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">{{ $selected->name }}</h5>
                            <form action="{{ route('admin.marketplace.customer-groups.destroy', $selected->id) }}" method="post" onsubmit="return confirm('{{ translate('are_you_sure') }}')">
                                @csrf @method('DELETE') <button class="btn btn-sm btn-outline-danger">{{ translate('delete_group') }}</button>
                            </form>
                        </div>
                        <div class="card-body">
                            <form action="{{ route('admin.marketplace.customer-groups.update', $selected->id) }}" method="post" class="row g-2 align-items-end mb-3">
                                @csrf @method('PUT')
                                <div class="col-md-4"><label class="form-label fs-12">{{ translate('name') }}</label><input type="text" name="name" value="{{ $selected->name }}" class="form-control form-control-sm"></div>
                                <div class="col-md-3"><label class="form-label fs-12">{{ translate('default_discount') }} %</label><input type="number" step="0.01" name="default_discount_percent" value="{{ $selected->default_discount_percent }}" class="form-control form-control-sm" min="0" max="100"></div>
                                <div class="col-md-3"><label class="form-label fs-12">{{ translate('status') }}</label>
                                    <select name="status" class="form-control form-control-sm">
                                        <option value="active" {{ $selected->status === 'active' ? 'selected' : '' }}>{{ translate('active') }}</option>
                                        <option value="inactive" {{ $selected->status === 'inactive' ? 'selected' : '' }}>{{ translate('inactive') }}</option>
                                    </select></div>
                                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">{{ translate('update') }}</button></div>
                            </form>

                            <div class="row">
                                <div class="col-md-5">
                                    <h6 class="fs-13">{{ translate('members') }}</h6>
                                    <form action="{{ route('admin.marketplace.customer-groups.members.add', $selected->id) }}" method="post" class="d-flex gap-1 mb-2">
                                        @csrf <input type="number" name="customer_id" class="form-control form-control-sm" placeholder="{{ translate('customer_id') }}" required>
                                        <button class="btn btn-sm btn-primary">{{ translate('add') }}</button>
                                    </form>
                                    <div class="d-flex flex-wrap gap-1">
                                        @forelse ($members as $cid)
                                            <form action="{{ route('admin.marketplace.customer-groups.members.remove', $selected->id) }}" method="post" class="d-inline">
                                                @csrf <input type="hidden" name="customer_id" value="{{ $cid }}">
                                                <button class="btn btn-sm btn-light border">#{{ $cid }} <span class="text-danger">&times;</span></button>
                                            </form>
                                        @empty
                                            <span class="text-muted fs-12">{{ translate('no_members_yet') }}</span>
                                        @endforelse
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <h6 class="fs-13">{{ translate('per_product_prices') }}</h6>
                                    <form action="{{ route('admin.marketplace.customer-groups.prices.set', $selected->id) }}" method="post" class="row g-1 mb-2">
                                        @csrf
                                        <div class="col-4"><input type="number" name="product_id" class="form-control form-control-sm" placeholder="{{ translate('product_id') }}" required></div>
                                        <div class="col-3"><input type="number" step="0.01" name="price" class="form-control form-control-sm" placeholder="{{ translate('price') }}"></div>
                                        <div class="col-3"><input type="number" step="0.01" name="discount_percent" class="form-control form-control-sm" placeholder="{{ translate('discount') }}%"></div>
                                        <div class="col-2"><button class="btn btn-sm btn-primary w-100">＋</button></div>
                                    </form>
                                    <table class="k-table">
                                        <tbody>
                                        @forelse ($prices as $p)
                                            <tr>
                                                <td>#{{ $p->product_id }}</td>
                                                <td>{{ $p->price !== null ? number_format((float)$p->price,2) : ($p->discount_percent . '%') }}</td>
                                                <td class="text-end"><form action="{{ route('admin.marketplace.customer-groups.prices.remove', [$selected->id, $p->id]) }}" method="post">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0">&times;</button></form></td>
                                            </tr>
                                        @empty
                                            <tr><td class="text-muted fs-12">{{ translate('no_product_prices_yet') }}</td></tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="card"><div class="card-body text-center text-muted py-5">{{ translate('select_a_group_to_manage_its_members_and_prices') }}.</div></div>
                @endif
            </div>
        </div>
    </div>
@endsection
