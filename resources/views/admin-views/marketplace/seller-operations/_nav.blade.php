<div class="mb-3 d-flex flex-wrap gap-2">
    <a class="btn btn--sm {{ Request::is('admin/marketplace/seller-operations') ? 'btn--primary' : 'btn-outline-primary' }}"
       href="{{ route('admin.marketplace.seller-operations.index') }}">{{ translate('overview') }}</a>
    <a class="btn btn--sm {{ Request::is('admin/marketplace/seller-operations/automation*') ? 'btn--primary' : 'btn-outline-primary' }}"
       href="{{ route('admin.marketplace.seller-operations.automation') }}">{{ translate('automation') }}</a>
    <a class="btn btn--sm {{ Request::is('admin/marketplace/seller-operations/integrations*') ? 'btn--primary' : 'btn-outline-primary' }}"
       href="{{ route('admin.marketplace.seller-operations.integrations') }}">{{ translate('keys_and_webhooks') }}</a>
    <a class="btn btn--sm {{ Request::is('admin/marketplace/seller-operations/team*') ? 'btn--primary' : 'btn-outline-primary' }}"
       href="{{ route('admin.marketplace.seller-operations.team') }}">{{ translate('seller_staff') }}</a>
    <a class="btn btn--sm {{ Request::is('admin/marketplace/seller-operations/bulk-jobs*') ? 'btn--primary' : 'btn-outline-primary' }}"
       href="{{ route('admin.marketplace.seller-operations.bulk-jobs') }}">{{ translate('bulk_operations') }}</a>
</div>
