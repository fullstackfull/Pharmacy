<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The approved Seller Center copy, in both languages (design handoff 12).
 *
 * The panel's `translate()` writes a title-cased guess for any key it has never seen, which is why
 * an unseeded panel reads "Nav orders" and "Sla risk". Seeding the approved strings is the only way
 * the shipped wording is the wording that was signed off — and it keeps English and Arabic in step,
 * since a key added to one file and forgotten in the other is the usual way a bilingual product
 * drifts.
 *
 * Run with `php artisan db:seed --class=SellerCenterCopySeeder`. It only ever adds and corrects the
 * keys listed here; every other line in those files is left exactly as it was.
 */
class SellerCenterCopySeeder extends Seeder
{
    /** Arabic ships in the `sy` locale folder; `sa` carries the same strings. */
    private const ARABIC_LOCALES = ['sy', 'sa'];

    public function run(): void
    {
        $this->write('en', array_map(static fn (array $pair) => $pair[0], self::COPY));

        $arabic = array_map(static fn (array $pair) => $pair[1], self::COPY);
        foreach (self::ARABIC_LOCALES as $locale) {
            $this->write($locale, $arabic);
        }
    }

    /** @param array<string, string> $strings */
    private function write(string $locale, array $strings): void
    {
        $path = base_path('resources/lang/' . $locale . '/new-messages.php');

        if (!is_file($path)) {
            return;
        }

        $existing = include $path;
        if (!is_array($existing)) {
            return;
        }

        $merged = array_merge($existing, $strings);
        ksort($merged, SORT_NATURAL | SORT_FLAG_CASE);

        // var_export, not concatenation: a string carrying a quote or a backslash written into a
        // double-quoted PHP literal makes this file — which is included on every translate() call —
        // unparsable, and takes the whole site down with it.
        $contents = "<?php\n\nreturn [\n";
        foreach ($merged as $key => $value) {
            $contents .= "\t" . var_export((string) $key, true) . ' => ' . var_export((string) $value, true) . ",\n";
        }
        $contents .= "];\n";

        file_put_contents($path, $contents);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($path, true);
        }

        $this->command?->info($locale . ': ' . count($strings) . ' Seller Center strings written');
    }

    /**
     * key => [English, Arabic]. Sections follow handoff 12.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const COPY = [
        // ── 1. navigation ────────────────────────────────────────────────
        'nav_home' => ['Home', 'الرئيسية'],
        'nav_seller_home' => ['Seller Home', 'لوحة البائع'],
        'nav_control_tower' => ['Control Tower', 'مركز التحكم'],
        'nav_action_center' => ['Action Center', 'مركز المهام'],
        'nav_orders' => ['Orders', 'الطلبات'],
        'nav_all_orders' => ['All orders', 'كل الطلبات'],
        'nav_ready_to_ship' => ['Ready to ship', 'جاهز للشحن'],
        'nav_shipped' => ['Shipped', 'تم الشحن'],
        'nav_delivered' => ['Delivered', 'تم التسليم'],
        'nav_cancelled' => ['Cancelled', 'ملغى'],
        'nav_returns' => ['Returns', 'المرتجعات'],
        'nav_refunds' => ['Refunds', 'المبالغ المستردة'],
        'nav_messages' => ['Messages', 'المحادثات'],
        'nav_catalog' => ['Catalog', 'الكتالوج'],
        'nav_products' => ['Products', 'المنتجات'],
        'nav_add_product' => ['Add product', 'إضافة منتج'],
        'nav_drafts' => ['Drafts', 'المسودات'],
        'nav_product_issues' => ['Product issues', 'مشاكل المنتجات'],
        'nav_bulk_operations' => ['Bulk operations', 'العمليات الجماعية'],
        'nav_inventory' => ['Inventory', 'المخزون'],
        'nav_overview' => ['Overview', 'نظرة عامة'],
        'nav_stock' => ['Stock', 'الكميات'],
        'nav_low_stock' => ['Low stock', 'مخزون منخفض'],
        'nav_movements' => ['Movement ledger', 'سجل الحركات'],
        'nav_warehouse_ops' => ['Warehouse operations', 'عمليات المستودع'],
        'nav_pricing' => ['Pricing', 'الأسعار'],
        'nav_scheduled_pricing' => ['Scheduled pricing', 'تغييرات مجدولة'],
        'nav_price_history' => ['Price history', 'سجل الأسعار'],
        'nav_fee_simulator' => ['Fee simulator', 'حاسبة العمولات'],
        'nav_fulfilment' => ['Fulfilment', 'التجهيز والشحن'],
        'nav_shipments' => ['Shipments', 'الشحنات'],
        'nav_picking' => ['Picking', 'التجميع'],
        'nav_packing' => ['Packing', 'التغليف'],
        'nav_shipping' => ['Shipping', 'الشحن'],
        'nav_exceptions' => ['Exceptions', 'الحالات الاستثنائية'],
        'nav_finance' => ['Finance', 'المالية'],
        'nav_transactions' => ['Transactions', 'المعاملات'],
        'nav_balance' => ['Balance', 'الرصيد'],
        'nav_payouts' => ['Payouts', 'السحوبات'],
        'nav_statements' => ['Statements', 'كشوف الحساب'],
        'nav_reconciliation' => ['Reconciliation', 'التسويات المالية'],
        'nav_fees' => ['Fees', 'العمولات والرسوم'],
        'nav_operations' => ['Operations', 'العمليات'],
        'nav_issue_center' => ['Issue Center', 'مركز المشاكل'],
        'nav_incidents' => ['Incidents', 'الأعطال'],
        'nav_automation_rules' => ['Automation rules', 'قواعد الأتمتة'],
        'nav_scheduled_ops' => ['Scheduled operations', 'عمليات مجدولة'],
        'nav_automation_history' => ['Automation history', 'سجل الأتمتة'],
        'nav_opportunities' => ['Opportunities', 'فرص التحسين'],
        'nav_growth' => ['Growth', 'النمو'],
        'nav_campaigns' => ['Campaigns', 'الحملات'],
        'nav_coupons' => ['Coupons', 'الكوبونات'],
        'nav_advertising' => ['Advertising', 'الإعلانات'],
        'nav_trust' => ['Trust', 'الموثوقية'],
        'nav_seller_performance' => ['Seller performance', 'أداء البائع'],
        'nav_account_health' => ['Account health', 'صحة الحساب'],
        'nav_sla' => ['SLA performance', 'مستوى الخدمة'],
        'nav_brand_registry' => ['Brand registry', 'سجل العلامات'],
        'nav_brand_authorization' => ['Brand authorization', 'تفويض العلامة'],
        'nav_brand_protection' => ['Brand protection', 'حماية العلامة'],
        'nav_compliance' => ['Compliance documents', 'مستندات الامتثال'],
        'nav_platform' => ['Platform', 'المنصة'],
        'nav_reports' => ['Reports', 'التقارير'],
        'nav_report_builder' => ['Report builder', 'منشئ التقارير'],
        'nav_exports' => ['Exports', 'الملفات المصدّرة'],
        'nav_connected_apps' => ['Connected apps', 'التطبيقات المرتبطة'],
        'nav_api' => ['API credentials', 'مفاتيح الـ API'],
        'nav_webhooks' => ['Webhooks', 'الويب هوك'],
        'nav_integration_health' => ['Integration health', 'صحة التكامل'],
        'nav_organization' => ['Organization', 'المؤسسة'],
        'nav_team' => ['Team', 'الفريق'],
        'nav_roles' => ['Roles & permissions', 'الأدوار والصلاحيات'],
        'nav_approvals' => ['Approvals', 'الموافقات'],
        'nav_audit' => ['Audit log', 'سجل التدقيق'],
        'nav_security' => ['Security', 'الأمان'],
        'nav_cases' => ['Cases', 'الحالات'],
        'nav_appeals' => ['Appeals', 'الاعتراضات'],

        // ── 2. buttons & actions ─────────────────────────────────────────
        'open' => ['Open', 'فتح'],
        'details' => ['Details', 'التفاصيل'],
        'retry' => ['Retry', 'إعادة المحاولة'],
        'refresh_tracking' => ['Refresh tracking', 'تحديث التتبّع'],
        'mark_packed' => ['Mark packed', 'تم التغليف'],
        'print_label' => ['Print label', 'طباعة البوليصة'],
        'print_labels' => ['Print labels', 'طباعة البوالص'],
        'print_invoice' => ['Print invoice', 'طباعة الفاتورة'],
        'create_shipment' => ['Create shipment', 'إنشاء شحنة'],
        'accept' => ['Accept', 'قبول'],
        'cancel_order' => ['Cancel order', 'إلغاء الطلب'],
        'keep_order' => ['Keep order', 'إبقاء الطلب'],
        'fix_product' => ['Fix product', 'إصلاح المنتج'],
        'restock' => ['Restock', 'تعزيز المخزون'],
        'adjust' => ['Adjust', 'تعديل'],
        'count' => ['Count', 'جرد'],
        'answer_returns' => ['Answer returns', 'الرد على المرتجعات'],
        'review_inventory' => ['Review inventory', 'مراجعة المخزون'],
        'open_reconciliation' => ['Open reconciliation', 'فتح التسوية'],
        'request_payout' => ['Request payout', 'طلب سحب'],
        'download_statement' => ['Download statement', 'تنزيل الكشف'],
        'download_errors' => ['Download errors', 'تنزيل الأخطاء'],
        'add_filter' => ['Add filter', 'إضافة فلتر'],
        'clear_all' => ['Clear all', 'مسح الكل'],
        'clear_all_filters' => ['Clear all filters', 'مسح كل الفلاتر'],
        'columns' => ['Columns', 'الأعمدة'],
        'save_current_view' => ['Save current view', 'حفظ العرض الحالي'],
        'export' => ['Export', 'تصدير'],
        'assign' => ['Assign…', 'إسناد…'],
        'snooze' => ['Snooze', 'تأجيل'],
        'resolve' => ['Resolve', 'إغلاق'],
        'open_full_page' => ['Open full page', 'فتح كصفحة كاملة'],
        'create_automation' => ['Create automation', 'إنشاء أتمتة'],
        'preview_matches' => ['Preview matches', 'معاينة المطابقات'],
        'save_and_activate' => ['Save & activate', 'حفظ وتنشيط'],
        'resume' => ['Resume', 'استئناف'],
        'undo' => ['Undo', 'تراجع'],
        'submit_for_verification' => ['Submit for verification', 'إرسال للتحقق'],
        'upload_document' => ['Upload document', 'رفع مستند'],
        'provide_evidence' => ['Provide evidence', 'تقديم إثبات'],
        'renew_document' => ['Renew document', 'تجديد المستند'],
        'renew_authorisation' => ['Renew authorisation', 'تجديد التفويض'],
        'join_campaign' => ['Join campaign', 'الانضمام للحملة'],
        'request_access' => ['Request access', 'طلب صلاحية'],
        'submit_appeal' => ['Submit appeal', 'تقديم اعتراض'],
        'revoke' => ['Revoke', 'إبطال'],
        'rotate' => ['Rotate', 'تدوير المفتاح'],
        'view_trail' => ['View trail', 'عرض السجل'],
        'view_affected_orders' => ['View affected orders', 'عرض الطلبات المتأثرة'],
        'apply' => ['Apply', 'تطبيق'],
        'remove_filter' => ['Remove filter', 'إزالة الفلتر'],
        'mark_all_read' => ['Mark all read', 'تعليم الكل كمقروء'],
        'open_issue_center' => ['Open issue center', 'فتح مركز المشاكل'],
        'open_search' => ['Open search', 'فتح البحث'],
        'resolve_shortage' => ['Resolve shortage', 'معالجة النقص'],
        'back_to_seller_home' => ['Back to Seller Home', 'العودة إلى لوحة البائع'],
        'select_row' => ['Select row', 'تحديد الصف'],
        'select_all_on_this_page' => ['Select all on this page', 'تحديد كل ما في هذه الصفحة'],

        // ── 3. statuses ──────────────────────────────────────────────────
        'under_review' => ['Under review', 'قيد المراجعة'],
        'out_of_stock' => ['Out of stock', 'نفد المخزون'],
        'low_stock' => ['Low stock', 'مخزون منخفض'],
        'healthy' => ['Healthy', 'سليم'],
        'discrepancy' => ['Discrepancy', 'فرق جرد'],
        'ready_to_ship' => ['Ready to ship', 'جاهز للشحن'],
        'packed' => ['Packed', 'تم التغليف'],
        'picking' => ['Picking', 'جاري التجميع'],
        'in_transit' => ['In transit', 'في الطريق'],
        'out_for_delivery' => ['Out for delivery', 'خرج للتسليم'],
        'late' => ['Late', 'متأخر'],
        'return_open' => ['Return open', 'مرتجع مفتوح'],
        'partially_completed' => ['Partially completed', 'مكتمل جزئياً'],
        'matched' => ['Matched', 'مطابق'],
        'unmatched' => ['Unmatched', 'غير مطابق'],
        'requires_review' => ['Requires review', 'يحتاج مراجعة'],
        'expiring_soon' => ['Expiring soon', 'ينتهي قريباً'],
        'monitoring' => ['Monitoring', 'قيد المراقبة'],
        'degraded' => ['Degraded', 'متأثر'],
        'watch' => ['Watch', 'تحت المراقبة'],
        'at_risk' => ['At risk', 'في خطر'],
        'no_data' => ['No data', 'لا توجد بيانات'],
        'unknown' => ['No data', 'لا توجد بيانات'],
        'cod' => ['COD', 'عند الاستلام'],
        'card' => ['Card', 'بطاقة'],
        'critical' => ['Critical', 'حرج'],
        'high' => ['High', 'مرتفع'],
        'medium' => ['Medium', 'متوسط'],
        'low' => ['Low', 'منخفض'],
        'label_created' => ['Label created', 'تم إنشاء البوليصة'],
        'stopped_by_marketplace' => ['Stopped by the marketplace', 'تم إيقافها من قبل السوق'],
        'more_information_required' => ['More information required', 'مطلوب معلومات إضافية'],
        'seller_fulfilled' => ['Seller fulfilled', 'تجهيز البائع'],

        // ── 4. brand relationships (never collapse these) ────────────────
        'brand_owner' => ['Verified brand owner', 'مالك علامة موثّق'],
        'brand_manufacturer' => ['Manufacturer', 'الجهة المصنّعة'],
        'brand_distributor' => ['Authorised distributor', 'موزّع معتمد'],
        'brand_seller' => ['Authorised seller', 'بائع معتمد'],
        'brand_pending' => ['Pending verification', 'بانتظار التحقق'],
        'brand_more_info' => ['More information required', 'مطلوب معلومات إضافية'],
        'brand_rejected' => ['Verification rejected', 'تم رفض التحقق'],
        'brand_expired' => ['Authorisation expired', 'انتهى التفويض'],

        // ── 5. empty states ──────────────────────────────────────────────
        'nothing_needs_attention' => ['Nothing needs attention', 'لا شيء يحتاج انتباهك'],
        'no_critical_issues' => ['No critical issues', 'لا مشاكل حرجة'],
        'everything_requiring_immediate_attention_is_currently_under_control' => [
            'Everything requiring immediate attention is currently under control.',
            'كل ما يحتاج تدخلاً فورياً تحت السيطرة حالياً.',
        ],
        'no_orders_yet' => ['No orders yet', 'لا طلبات بعد'],
        'orders_appear_here_as_soon_as_customers_buy' => [
            'Orders appear here as soon as customers buy.',
            'تظهر الطلبات هنا فور شراء العملاء.',
        ],
        'no_orders_match_these_filters' => ['No orders match these filters', 'لا طلبات تطابق هذه الفلاتر'],
        'adjust_or_clear_the_filters_to_see_more' => [
            'Adjust or clear the filters to see more.',
            'عدّل الفلاتر أو امسحها لعرض المزيد.',
        ],
        'no_products_yet' => ['No products yet', 'لا منتجات بعد'],
        'add_your_first_product_to_start_selling' => [
            'Add your first product to start selling.',
            'أضف أول منتج لتبدأ البيع.',
        ],
        'no_movements_recorded_in_this_period' => [
            'No movements recorded in this period',
            'لا حركات في هذه الفترة',
        ],
        'stock_changes_appear_here_with_the_balance_they_left_behind' => [
            'Stock changes appear here with the balance they left behind.',
            'تظهر تغييرات المخزون هنا مع الرصيد الناتج عنها.',
        ],
        'no_automations' => ['No automations', 'لا قواعد أتمتة'],
        'create_rules_to_handle_repetitive_operational_tasks_automatically' => [
            'Create rules to handle repetitive operational tasks automatically.',
            'أنشئ قواعد لتنفيذ المهام المتكررة تلقائياً.',
        ],
        'no_opportunities_detected' => ['No opportunities detected', 'لا فرص مرصودة'],
        'everything_matched' => ['Everything matched', 'كل شيء مطابق'],
        'no_brands_registered' => ['No brands registered', 'لا علامات مسجّلة'],
        'all_documents_approved' => ['All documents approved', 'كل المستندات مقبولة'],
        'you_are_up_to_date' => ["You're up to date", 'لا إشعارات جديدة'],
        'notifications_about_orders_stock_payouts_and_compliance_appear_here' => [
            'Notifications about orders, stock, payouts and compliance appear here.',
            'تظهر هنا إشعارات الطلبات والمخزون والسحوبات والامتثال.',
        ],
        'no_match_for' => ['No match for', 'لا نتائج لـ'],
        'orders_and_shipments_search_by_full_reference_try_an_order_number_a_tracking_code_or_an_sku' => [
            'Orders and shipments search by full reference. Try an order number, a tracking code or an SKU.',
            'البحث في الطلبات والشحنات يتم بالمرجع الكامل. جرّب رقم طلب أو رقم تتبّع أو SKU.',
        ],
        'search_is_unavailable_retry' => ['Search is unavailable — retry', 'البحث غير متاح — أعد المحاولة'],
        'see_all' => ['See all', 'عرض الكل'],

        // ── 6. errors & warnings ─────────────────────────────────────────
        'this_list_could_not_be_loaded' => ['This list could not be loaded', 'تعذّر تحميل هذه القائمة'],
        'the_orders_service_did_not_answer' => ['The orders service did not answer', 'لم تستجب خدمة الطلبات'],
        'some_carrier_data_could_not_be_loaded' => [
            'Some carrier data could not be loaded.',
            'تعذّر تحميل بعض بيانات الناقل.',
        ],
        'some_columns_are_hidden_by_your_role' => [
            'Some columns are hidden by your role.',
            'بعض الأعمدة مخفية بحسب دورك.',
        ],
        'you_do_not_have_access_to' => ["You don't have access to", 'لا تملك صلاحية'],
        'this_page_requires_the_permission' => ['This page requires the permission', 'تتطلب هذه الصفحة الصلاحية'],
        'ask_an_owner_or_manager_to_grant_it' => [
            'Ask an owner or manager to grant it.',
            'اطلب من المالك أو المدير منحك الصلاحية.',
        ],
        'this_page_requires_a_permission_your_role_does_not_have_ask_an_owner_or_manager_to_grant_it' => [
            'This page requires a permission your role does not have. Ask an owner or manager to grant it.',
            'تتطلب هذه الصفحة صلاحية لا يملكها دورك. اطلب من المالك أو المدير منحك إياها.',
        ],
        'a_reason_is_required_to_reject' => ['A reason is required to reject.', 'سبب الرفض مطلوب.'],
        'ship_by_sla_at_risk' => ['Ship-by SLA at risk', 'مهلة الشحن في خطر'],
        'breached_by' => ['Breached by', 'تجاوز بـ'],
        'left' => ['left', 'يتبقى'],
        'selection_cleared_filters_changed' => [
            'Selection cleared — filters changed',
            'أُلغي التحديد — تغيّرت الفلاتر',
        ],
        'no_comparable_data_in_the_previous_period' => [
            'No comparable data in the previous period',
            'لا توجد بيانات مقارنة في الفترة السابقة',
        ],

        // ── 7. disclaimers (verbatim, required) ──────────────────────────
        'disclaimer_fee_simulator' => [
            'Estimated values based on current marketplace fee rules. Actual fees are calculated at the time of sale.',
            'قيم تقديرية وفق قواعد العمولات الحالية في السوق. تُحسب العمولات الفعلية عند إتمام البيع.',
        ],
        'disclaimer_coverage' => [
            'Coverage is available stock divided by the average daily sales of the last 14 days.',
            'التغطية هي المخزون المتاح مقسوماً على متوسط البيع اليومي لآخر ١٤ يوماً.',
        ],
        'disclaimer_customer_contact' => [
            'Full contact details are released to the carrier at pickup.',
            'تُسلَّم بيانات التواصل الكاملة للناقل عند الاستلام.',
        ],
        'disclaimer_api_secret' => [
            'This is the only time the secret is shown.',
            'هذه المرة الوحيدة التي يُعرض فيها المفتاح السري.',
        ],
        'disclaimer_claim_declaration' => [
            'False claims can suspend your account.',
            'البيانات غير الصحيحة قد تؤدي إلى إيقاف حسابك.',
        ],
        'disclaimer_return_window' => [
            "Approved automatically in the buyer's favour if unanswered.",
            'تُقبل تلقائياً لمصلحة المشتري إذا لم يتم الرد.',
        ],

        // ── 8. shell chrome ──────────────────────────────────────────────
        'seller_center' => ['Seller Center', 'مركز البائع'],
        'seller_id' => ['Seller ID', 'رقم البائع'],
        'search_orders_products_shipments_finance' => [
            'Search orders, products, shipments, finance…',
            'ابحث في الطلبات والمنتجات والشحنات والمالية…',
        ],
        'order_phone_tracking_sku' => ['Order, phone, tracking, SKU', 'رقم الطلب أو الهاتف أو التتبّع أو SKU'],
        'table_density' => ['Table density', 'كثافة الجدول'],
        'compact' => ['Compact', 'مضغوط'],
        'comfortable' => ['Comfortable', 'مريح'],
        'commands' => ['Commands', 'الأوامر'],
        'sections' => ['Sections', 'الأقسام'],
        'move' => ['move', 'تنقّل'],
        'jump_to_a_section' => ['Jump to a section', 'الانتقال إلى قسم'],
        'close_the_top_overlay' => ['Close the top overlay', 'إغلاق الطبقة العليا'],
        'move_and_open_in_search' => ['Move and open in search', 'التنقّل والفتح في البحث'],
        'keyboard_shortcuts' => ['Keyboard shortcuts', 'اختصارات لوحة المفاتيح'],
        'language_and_numerals' => ['Language & numerals', 'اللغة والأرقام'],
        'notification_preferences' => ['Notification preferences', 'تفضيلات الإشعارات'],
        'my_profile' => ['My profile', 'ملفي الشخصي'],
        'store_profile' => ['Store profile', 'ملف المتجر'],
        'store_settings' => ['Store settings', 'إعدادات المتجر'],
        'switch_staff_account' => ['Switch staff account', 'تبديل حساب الموظف'],
        'go_to_control_tower' => ['Go to Control Tower', 'الذهاب إلى مركز التحكم'],
        'open_ship_today_queue' => ['Open ship-today queue', 'فتح قائمة الشحن اليوم'],
        'create_stock_adjustment' => ['Create stock adjustment', 'إنشاء تعديل مخزون'],
        'new_report' => ['New report', 'تقرير جديد'],
        'rows_per_page' => ['Rows per page', 'صفوف في الصفحة'],
        'previous_page' => ['Previous page', 'الصفحة السابقة'],
        'next_page' => ['Next page', 'الصفحة التالية'],
        'choose_a_file_or_drop_it_here' => ['Choose a file or drop it here', 'اختر ملفاً أو أفلته هنا'],
        'impact' => ['Impact', 'الأثر'],
        'normal_operations_stay_quiet_problems_and_required_actions_become_prominent' => [
            'Normal operations stay quiet. Problems and required actions become prominent.',
            'العمليات الطبيعية تبقى هادئة. المشاكل والإجراءات المطلوبة تظهر بوضوح.',
        ],
        'every_problem_carries_a_severity_an_affected_count_a_deadline_one_action_and_a_direct_drill_down' => [
            'Every problem carries a severity, an affected count, a deadline, one action and a direct drill-down.',
            'كل مشكلة تحمل درجة خطورة وعدد المتأثرين ومهلة وإجراءً واحداً ورابطاً مباشراً للتفاصيل.',
        ],
        'how_this_panel_works' => ['How this panel works', 'كيف تعمل هذه اللوحة'],
    ];
}
