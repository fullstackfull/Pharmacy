@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['products'], 'title' => translate('products'), 'label' => translate('product'), 'dimension' => 'product', 'window' => $window])
<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['categories'], 'title' => translate('categories'), 'label' => translate('category'), 'dimension' => 'category', 'window' => $window])
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['brands'], 'title' => translate('brands'), 'label' => translate('brand'), 'dimension' => 'brand', 'window' => $window])
</div>
