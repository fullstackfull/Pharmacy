@extends('layouts.admin.app')

@section('title', translate('Theme_Management'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
            <h2 class="h1 mb-0 text-capitalize">{{ translate('Theme_Management') }}</h2>
            <a href="{{ route('admin.theme.builder.index') }}" class="btn btn-primary">
                {{ translate('Open_Theme_Builder') }}
            </a>
        </div>

        <div class="row g-3">
            {{-- Create theme --}}
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('Create_Theme') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.theme.store') }}" method="post">
                            @csrf
                            <div class="form-group">
                                <label class="form-label">{{ translate('theme_name') }} <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" value="{{ old('name') }}" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">{{ translate('description') }}</label>
                                <input type="text" name="description" class="form-control" value="{{ old('description') }}">
                            </div>
                            <button type="submit" class="btn btn-primary">{{ translate('create') }}</button>
                        </form>
                        <hr>
                        <p class="text-muted mb-0 small">
                            {{ translate('a_new_theme_starts_with_an_empty_draft_version_publishing_a_draft_archives_the_previous_published_version_so_you_can_always_roll_back') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Themes list --}}
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h5 class="mb-0">{{ translate('Themes') }} <span class="badge badge-soft-primary">{{ $themes->total() }}</span></h5>
                        <form action="{{ route('admin.theme.index') }}" method="get" class="d-flex gap-2">
                            <input type="text" name="searchValue" class="form-control form-control-sm" value="{{ $search }}" placeholder="{{ translate('search') }}">
                            <button type="submit" class="btn btn-sm btn-primary">{{ translate('search') }}</button>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>{{ translate('theme') }}</th>
                                        <th class="text-center">{{ translate('versions') }}</th>
                                        <th class="text-center">{{ translate('status') }}</th>
                                        <th class="text-center">{{ translate('action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @forelse($themes as $theme)
                                    @php
                                        $published = $theme->versions->firstWhere('status', 'published');
                                        $latestDraft = $theme->versions->where('status', 'draft')->sortByDesc('id')->first();
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="fw-bold">{{ $theme->name }}</div>
                                            <small class="text-muted"><code dir="ltr">{{ $theme->slug }}</code></small>
                                            @if($theme->is_system)
                                                <span class="badge badge-soft-secondary">{{ translate('system') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <span class="badge badge-soft-info">{{ $theme->versions->count() }}</span>
                                            @if($published)
                                                <span class="badge badge-soft-success">{{ translate('published') }} #{{ $published->id }}</span>
                                            @endif
                                            @if($latestDraft)
                                                <span class="badge badge-soft-warning">{{ translate('draft') }} #{{ $latestDraft->id }}</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($theme->is_active)
                                                <span class="badge badge-soft-success">{{ translate('active') }}</span>
                                            @else
                                                <span class="badge badge-soft-secondary">{{ translate('inactive') }}</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                @if(!$theme->is_active)
                                                    <form action="{{ route('admin.theme.activate') }}" method="post">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $theme->id }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-primary">{{ translate('activate') }}</button>
                                                    </form>
                                                @endif
                                                @if($latestDraft)
                                                    <form action="{{ route('admin.theme.version.publish') }}" method="post"
                                                          onsubmit="return confirm('{{ translate('publish_this_draft') }}?')">
                                                        @csrf
                                                        <input type="hidden" name="version_id" value="{{ $latestDraft->id }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-success">{{ translate('publish_draft') }}</button>
                                                    </form>
                                                @endif
                                                @if($published)
                                                    <form action="{{ route('admin.theme.version.duplicate') }}" method="post">
                                                        @csrf
                                                        <input type="hidden" name="version_id" value="{{ $published->id }}">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary">{{ translate('duplicate_to_draft') }}</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center py-4 text-muted">{{ translate('no_themes_yet') }}</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @if($themes->hasPages())
                        <div class="card-footer">{{ $themes->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
