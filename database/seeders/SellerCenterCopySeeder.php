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

        // ── what the detectors call their findings, and the health domains ──
        'insight_body_order_stuck' => ['Order :order has sat in :status for :elapsed.', 'الطلب :order بقي في حالة :status لمدة :elapsed.'],
        'insight_body_order_late' => ['Order :order is :elapsed past its ship-by time.', 'الطلب :order تجاوز موعد شحنه بـ :elapsed.'],
        'insight_body_order_due_soon' => ['Order :order must ship within :elapsed.', 'يجب شحن الطلب :order خلال :elapsed.'],
        'insight_body_refund_overdue' => ['The refund request on order :order has been waiting :elapsed for an answer.', 'طلب الاسترداد على الطلب :order ينتظر رداً منذ :elapsed.'],
        'insight_body_shipments_silent_one' => ['1 shipment has not moved for :elapsed, holding :value.', 'شحنة واحدة لم تتحرّك منذ :elapsed، وتحتجز :value.'],
        'insight_body_shipments_silent' => [':count shipments have not moved for :elapsed, holding :value.', ':count شحنات لم تتحرّك منذ :elapsed، وتحتجز :value.'],
        'insight_body_product_rejected' => ['":product" was rejected by the marketplace. Reason: :reason', 'رُفض المنتج «:product» من السوق. السبب: :reason'],
        'no_reason_was_recorded' => ['no reason was recorded', 'لم يُسجَّل سبب'],
        'insight_body_listing_incomplete' => ['":product" scores :score/100 — missing :missing.', 'المنتج «:product» حصل على :score/100 — ينقصه :missing.'],
        'insight_body_out_of_stock' => ['":product" is out of stock and sold :sold units in the last :days days.', 'نفد مخزون «:product» وقد بيع منه :sold وحدة خلال آخر :days يوماً.'],
        'insight_body_running_out' => ['":product" has :stock units left — about :cover days of cover.', 'تبقّى من «:product» :stock وحدة — نحو :cover يوماً من التغطية.'],
        'insight_body_brand' => [':brand covers :count of your listings and has no authorisation on file.', 'تغطي العلامة :brand :count من منتجاتك ولا يوجد تفويض مسجّل لها.'],
        'images' => ['images', 'الصور'],
        'details' => ['description', 'الوصف'],
        'meta' => ['search text', 'نص البحث'],
        'unit' => ['unit', 'الوحدة'],
        'insight_body_order_state_one' => ['1 order is :state, holding :value.', 'طلب واحد :state، ويحتجز :value.'],
        'insight_body_order_state' => [':count orders are :state, holding :value.', ':count طلبات :state، وتحتجز :value.'],
        'insight_body_returns_waiting_one' => ['1 approved return has not been processed for over :hours hours.', 'مرتجع واحد مقبول لم يُعالَج منذ أكثر من :hours ساعة.'],
        'insight_body_returns_waiting' => [':count approved returns have not been processed for over :hours hours.', ':count مرتجعات مقبولة لم تُعالَج منذ أكثر من :hours ساعة.'],
        'insight_body_duplicate_barcodes_one' => ['1 barcode is used by more than one product, so scans resolve to the wrong item.', 'باركود واحد مستخدم لأكثر من منتج، فتشير عمليات المسح إلى الصنف الخطأ.'],
        'insight_body_duplicate_barcodes' => [':count barcodes are used by more than one product, so scans resolve to the wrong item.', ':count باركودات مستخدمة لأكثر من منتج، فتشير عمليات المسح إلى الصنف الخطأ.'],
        'insight_body_missing_attributes_one' => ['1 product is missing a required attribute — :attribute. It will be rejected on submission.', 'منتج واحد تنقصه خاصية مطلوبة — :attribute. سيُرفض عند الإرسال.'],
        'insight_body_missing_attributes' => [':count products are missing required attributes, starting with :attribute. They will be rejected on submission.', ':count منتجات تنقصها خصائص مطلوبة، أولها :attribute. ستُرفض عند الإرسال.'],
        'insight_body_price_moved' => ['Price moved from :from to :to — a :percent% change, made by :source.', 'تغيّر السعر من :from إلى :to — بنسبة :percent%، عبر :source.'],
        'insight_body_below_cost_one' => ['1 product is priced below its recorded cost — selling the stock on hand would lose :value.', 'منتج واحد سعره دون تكلفته المسجّلة — بيع المخزون الحالي سيخسر :value.'],
        'insight_body_below_cost' => [':count products are priced below their recorded cost — selling the stock on hand would lose :value.', ':count منتجات أسعارها دون تكلفتها المسجّلة — بيع المخزون الحالي سيخسر :value.'],
        'manual' => ['Manual', 'يدوي'],
        'bulk' => ['Bulk', 'جماعي'],
        'api' => ['API', 'API'],
        'promotion' => ['Promotion', 'عرض ترويجي'],
        'automation' => ['Automation', 'أتمتة'],
        'seller_ui' => ['Seller panel', 'لوحة البائع'],
        'insight_body_stale_inventory_one' => ['1 product has not sold in :days days, holding :value in stock.', 'منتج واحد لم يُبَع خلال :days يوماً، ويحتجز :value في المخزون.'],
        'insight_body_stale_inventory' => [':count products have not sold in :days days, holding :value in stock.', ':count منتجات لم تُبَع خلال :days يوماً، وتحتجز :value في المخزون.'],
        'insight_body_stale_inventory_one_no_cost' => ['1 product has not sold in :days days.', 'منتج واحد لم يُبَع خلال :days يوماً.'],
        'insight_body_stale_inventory_no_cost' => [':count products have not sold in :days days.', ':count منتجات لم تُبَع خلال :days يوماً.'],
        'insight_body_delivered_without_earning_one' => ['1 delivered order line has no earning recorded — :value is uncredited.', 'سطر طلب مُسلّم واحد بلا أرباح مسجّلة — :value غير مقيّدة.'],
        'insight_body_delivered_without_earning' => [':count delivered order lines have no earning recorded — :value is uncredited.', ':count سطر طلب مُسلّم بلا أرباح مسجّلة — :value غير مقيّدة.'],
        'insight_brand_listings_blocked' => ['Listings blocked by brand authorisation', 'منتجات موقوفة بسبب تفويض العلامة'],
        'insight_brand_not_claimed' => ['Brand has no claim on file', 'لا يوجد تفويض مسجّل لهذه العلامة'],
        'insight_delivered_without_earning' => ['Delivered orders with no earning recorded', 'طلبات سُلّمت ولم تُسجّل أرباحها'],
        'insight_duplicate_barcodes' => ['Duplicate barcodes in the catalogue', 'باركودات مكررة في الكتالوج'],
        'insight_inventory_not_moving' => ['Stock that has not moved', 'مخزون لم يتحرّك'],
        'insight_listing_hidden_with_stock' => ['Hidden listing that has stock', 'منتج مخفي ولديه مخزون'],
        'insight_listing_incomplete' => ['Listing is missing information', 'المنتج ينقصه معلومات'],
        'insight_listing_live_without_stock' => ['Listing is live with no stock', 'منتج معروض بلا مخزون'],
        'insight_missing_required_attributes' => ['Missing required attributes', 'خصائص مطلوبة ناقصة'],
        'insight_order_due_soon' => ['Orders due to ship soon', 'طلبات يقترب موعد شحنها'],
        'insight_order_late' => ['Orders past their ship-by time', 'طلبات تجاوزت موعد شحنها'],
        'insight_order_stuck' => ['Order has not moved', 'طلب لم يتحرّك'],
        'insight_out_of_stock' => ['Out of stock', 'نفد المخزون'],
        'insight_price_below_cost' => ['Price is below cost', 'السعر أقل من التكلفة'],
        'insight_price_dropped_sharply' => ['Price dropped sharply', 'انخفض السعر بشدة'],
        'insight_price_rose_sharply' => ['Price rose sharply', 'ارتفع السعر بشدة'],
        'insight_product_rejected' => ['Product rejected by the marketplace', 'رفض السوق المنتج'],
        'insight_refund_response_overdue' => ['Refund response is overdue', 'تأخر الرد على طلب الاسترداد'],
        'insight_returns_awaiting_processing' => ['Returns awaiting processing', 'مرتجعات بانتظار المعالجة'],
        'insight_running_out' => ['Running out of stock', 'المخزون يوشك على النفاد'],
        'insight_shipments_not_moving' => ['Shipments have not moved', 'شحنات لم تتحرّك'],
        'orders' => ['Orders', 'الطلبات'],
        'inventory' => ['Inventory', 'المخزون'],
        'catalog' => ['Catalog', 'الكتالوج'],
        'pricing' => ['Pricing', 'الأسعار'],
        'returns' => ['Returns', 'المرتجعات'],
        'shipping' => ['Shipping', 'الشحن'],
        'finance' => ['Finance', 'المالية'],
        'integrations' => ['Integrations', 'التكاملات'],
        'products' => ['Products', 'المنتجات'],

        // ── sentences with placeholders (see Copy::line) ─────────────────
        'n_minutes' => [':count min', ':count دقيقة'],
        'n_hours' => [':counth', ':count ساعة'],
        'n_hours_n_minutes' => [':hoursh :minutesm', ':hours ساعة و:minutes دقيقة'],
        'n_days' => [':countd', ':count يوم'],
        'n_days_n_hours' => [':daysd :hoursh', ':days يوم و:hours ساعة'],
        'breached_by_time' => ['Breached by :time', 'تجاوز بـ :time'],
        'time_left' => [':time left', 'يتبقى :time'],
        'showing_range' => [':from–:to of :total', ':from–:to من :total'],
        'one_issue_n_affected' => ['1 issue · :affected affected', 'مشكلة واحدة · :affected متأثر'],
        'n_issues_n_affected' => [':count issues · :affected affected', ':count مشاكل · :affected متأثر'],
        'one_resolved' => ['1 resolved', 'أُغلقت واحدة'],
        'n_resolved' => [':count resolved', 'أُغلقت :count'],
        'n_open_n_due_today' => [':open open · :due due today', ':open مفتوحة · :due مستحقة اليوم'],
        'n_affected' => [':count affected', ':count متأثر'],
        'n_orders' => [':count orders', ':count طلب'],
        'n_products' => [':count products', ':count منتج'],
        'n_issues' => [':count issues', ':count مشكلة'],
        'n_skus' => [':count SKUs', ':count رمز منتج'],
        'n_movements' => [':count movements', ':count حركة'],
        'across_n_skus' => ['across :count SKUs', 'عبر :count رمز منتج'],
        'at_or_under_n_units' => ['at or under :count units', 'عند أو دون :count وحدة'],
        'n_lines_running_low' => [':count lines are running low', ':count أسطر توشك على النفاد'],
        'review_n_lines' => ['Review :count lines', 'مراجعة :count سطر'],
        'n_of_limit' => [':value of a :limit limit', ':value من حد :limit'],
        'n_days_cover' => [':count days', ':count يوم'],
        'value_of_limit' => [':value of :limit', ':value من :limit'],
        'impact_n' => ['Impact :score', 'الأثر :score'],
        'detected_at' => ['Detected :time', 'رُصد :time'],
        'n_sections_hidden_by_your_role' => [':count sections are hidden by your role', ':count أقسام مخفية بحسب دورك'],
        'this_issue_affects_n' => ['This issue affects :count :entity.', 'تؤثر هذه المشكلة على :count :entity.'],
        'open_for_n_hours' => ['Open for :count hours', 'مفتوحة منذ :count ساعة'],
        'nothing_needs_attention_body' => ['No critical issues, no SLA risk and no financial exceptions in the last check at :time. Monitoring continues.', 'لا مشاكل حرجة ولا مخاطر على مستوى الخدمة ولا استثناءات مالية في آخر فحص :time. المراقبة مستمرة.'],
        'n_items_payment' => [':count items · :payment', ':count عناصر · :payment'],
        'sku_and_cover' => [':sku · :cover', ':sku · :cover'],
        'sku_and_stock' => [':sku · stock :stock', ':sku · المخزون :stock'],

        // ── wave 2 · the core operations screens ─────────────────────────
        'sla' => ['SLA', 'مستوى الخدمة'],
        'sla_risk' => ['SLA risk', 'مخاطر مستوى الخدمة'],
        'sla_at_risk' => ['SLA at risk', 'مستوى الخدمة في خطر'],
        'sla_risk_today' => ['SLA risk today', 'مخاطر مستوى الخدمة اليوم'],
        'pos' => ['POS', 'نقطة البيع'],
        'sku' => ['SKU', 'رمز المنتج'],
        'skus' => ['SKUs', 'رموز المنتجات'],
        'cod_only' => ['COD only', 'الدفع عند الاستلام فقط'],
        'no_decimal_places_in_syp' => ['No decimal places in SYP', 'بدون كسور عشرية بالليرة السورية'],
        'name_sku_barcode' => ['Name, SKU, barcode', 'الاسم أو SKU أو الباركود'],
        'of' => ['of', 'من'],
        'to' => ['to', 'إلى'],
        'by' => ['by', 'بواسطة'],
        'affected' => ['affected', 'متأثر'],
        'issues' => ['issues', 'مشاكل'],
        'issue' => ['Issue', 'مشكلة'],
        'items' => ['items', 'عناصر'],
        'lines' => ['lines', 'أسطر'],
        'days' => ['days', 'أيام'],
        'hours' => ['hours', 'ساعات'],
        'units' => ['units', 'وحدات'],
        'vs_prev' => ['vs prev', 'مقارنة بالسابق'],
        'left' => ['left', 'متبقٍ'],
        'account_health' => ['Account health', 'صحة الحساب'],
        'across' => ['across', 'عبر'],
        'at_or_under' => ['at or under', 'عند أو دون'],
        'available' => ['Available', 'المتاح'],
        'awaiting_shipment' => ['Awaiting shipment', 'بانتظار الشحن'],
        'brand' => ['Brand', 'العلامة'],
        'bulk_import' => ['Bulk import', 'استيراد جماعي'],
        'cancellation' => ['Cancellation', 'الإلغاء'],
        'carrier' => ['Carrier', 'الناقل'],
        'category' => ['Category', 'الفئة'],
        'change' => ['Change', 'التغيير'],
        'city' => ['City', 'المدينة'],
        'clear' => ['Clear', 'مسح'],
        'close' => ['Close', 'إغلاق'],
        'commission' => ['Commission', 'العمولة'],
        'compare_previous' => ['Compare previous', 'مقارنة بالسابق'],
        'coverage' => ['Coverage', 'التغطية'],
        'customer' => ['Customer', 'العميل'],
        'customer_and_delivery' => ['Customer & delivery', 'العميل والتوصيل'],
        'customer_pays_cod' => ['Customer pays (COD)', 'يدفع العميل (عند الاستلام)'],
        'daily_sales' => ['Daily sales', 'المبيع اليومي'],
        'detected' => ['Detected', 'رُصد'],
        'detected_by' => ['Detected by', 'رصده'],
        'discount' => ['Discount', 'الخصم'],
        'due' => ['Due', 'الاستحقاق'],
        'due_today' => ['Due today', 'مستحق اليوم'],
        'fulfilment' => ['Fulfilment', 'التجهيز'],
        'fulfilment_and_shipping' => ['Fulfilment & shipping', 'التجهيز والشحن'],
        'gross' => ['Gross', 'الإجمالي'],
        'held_by_open_orders' => ['held by open orders', 'محجوز بطلبات مفتوحة'],
        'impact_score' => ['Impact score', 'درجة الأثر'],
        'inventory_overview' => ['Inventory overview', 'نظرة عامة على المخزون'],
        'items_subtotal' => ['Items subtotal', 'مجموع العناصر'],
        'line' => ['Line', 'السطر'],
        'listing_quality' => ['Listing quality', 'جودة الإدراج'],
        'low_stock_lines' => ['Low stock lines', 'أسطر مخزون منخفض'],
        'manage_order' => ['Manage order', 'إدارة الطلب'],
        'marketplace' => ['Marketplace', 'السوق'],
        'met' => ['Met', 'تم الالتزام'],
        'model' => ['Model', 'النموذج'],
        'movements' => ['movements', 'حركات'],
        'net_to_balance' => ['Net to balance', 'الصافي إلى الرصيد'],
        'on_time' => ['On time', 'في الوقت'],
        'open_for' => ['Open for', 'مفتوحة منذ'],
        'order' => ['Order', 'الطلب'],
        'order_total' => ['Order total', 'إجمالي الطلب'],
        'paid_out' => ['Paid out', 'المدفوع'],
        'payment' => ['Payment', 'الدفع'],
        'payment_status' => ['Payment status', 'حالة الدفع'],
        'payout' => ['Payout', 'السحب'],
        'pending_clearance' => ['Pending clearance', 'قيد التصفية'],
        'phone' => ['Phone', 'الهاتف'],
        'physical' => ['Physical', 'الفعلي'],
        'placed' => ['Placed', 'تاريخ الطلب'],
        'previous' => ['Previous', 'السابق'],
        'price' => ['Price', 'السعر'],
        'product' => ['Product', 'المنتج'],
        'promised_delivery' => ['Promised delivery', 'التسليم الموعود'],
        'qty' => ['Qty', 'الكمية'],
        'reason' => ['Reason', 'السبب'],
        'reference' => ['Reference', 'المرجع'],
        'refund_rate' => ['Refund rate', 'معدل الاسترداد'],
        'reserved' => ['Reserved', 'محجوز'],
        'reserved_open_payout' => ['Reserved (open payout)', 'محجوز (طلب سحب مفتوح)'],
        'return_rate' => ['Return rate', 'معدل الإرجاع'],
        'revenue' => ['Revenue', 'الإيراد'],
        'reversed' => ['Reversed', 'معكوس'],
        'review' => ['Review', 'مراجعة'],
        'running_low' => ['Running low', 'يوشك على النفاد'],
        'sales_trend' => ['Sales trend', 'اتجاه المبيعات'],
        'search_issues' => ['Search issues', 'ابحث في المشاكل'],
        'severity' => ['Severity', 'الخطورة'],
        'ship_by' => ['Ship by', 'الشحن قبل'],
        'shipping_charged' => ['Shipping charged', 'الشحن المحصّل'],
        'state' => ['State', 'الحالة'],
        'status' => ['Status', 'الحالة'],
        'stock' => ['Stock', 'المخزون'],
        'system_health' => ['System health', 'صحة النظام'],
        'this_period' => ['This period', 'هذه الفترة'],
        'timeline' => ['Timeline', 'الخط الزمني'],
        'timestamp' => ['Timestamp', 'الوقت'],
        'todays_queue' => ['Today\'s queue', 'قائمة اليوم'],
        'top_products' => ['Top products', 'أفضل المنتجات'],
        'total' => ['Total', 'الإجمالي'],
        'type' => ['Type', 'النوع'],
        'unit' => ['Unit', 'الوحدة'],
        'units_on_hand' => ['Units on hand', 'الوحدات المتوفرة'],
        'walk_in_customer' => ['Walk-in customer', 'عميل مباشر'],
        'what_happened' => ['What happened', 'ما الذي حدث'],
        'what_needs_you_today' => ['What needs you today', 'ما يحتاج انتباهك اليوم'],
        'why_it_matters' => ['Why it matters', 'لماذا يهم'],
        'withdrawable_now' => ['Withdrawable now', 'قابل للسحب الآن'],
        'your_earnings' => ['Your earnings', 'أرباحك'],
        'selling_price' => ['Selling price', 'سعر البيع'],
        'operations_control_tower' => ['Operations control tower', 'مركز التحكم بالعمليات'],
        'critical_now' => ['Critical now', 'حرج الآن'],
        'needs_action_today' => ['Needs action today', 'يحتاج إجراءً اليوم'],
        'fulfilment_exceptions' => ['Fulfilment exceptions', 'استثناءات التجهيز'],
        'returns_requiring_action' => ['Returns requiring action', 'مرتجعات تحتاج إجراءً'],
        'inventory_risk' => ['Inventory risk', 'مخاطر المخزون'],
        'financial_exceptions' => ['Financial exceptions', 'استثناءات مالية'],
        'catalog_and_pricing' => ['Catalog and pricing', 'الكتالوج والأسعار'],
        'fixed_automatically_last_24h' => ['Fixed automatically · last 24h', 'أُصلح تلقائياً · آخر ٢٤ ساعة'],
        'raised_from' => ['Raised from', 'رُفعت من'],
        'severity_raised_from' => ['Severity raised from', 'رُفعت الخطورة من'],
        'resolved_automatically' => ['Resolved automatically', 'أُغلقت تلقائياً'],
        'needs_immediate_attention' => ['Needs immediate attention', 'يحتاج تدخلاً فورياً'],
        'orders_inside_the_ship_by_window' => ['Orders inside the ship-by window', 'طلبات داخل مهلة الشحن'],
        'accepted_and_not_yet_shipped' => ['Accepted and not yet shipped', 'مقبولة ولم تُشحن بعد'],
        'awaiting_your_answer' => ['Awaiting your answer', 'بانتظار ردّك'],
        'returns_to_answer' => ['Returns to answer', 'مرتجعات تحتاج رداً'],
        'open_control_tower' => ['Open Control Tower', 'فتح مركز التحكم'],
        'open_order_queue' => ['Open order queue', 'فتح قائمة الطلبات'],
        'open_order' => ['Open order', 'فتح الطلب'],
        'open_refund' => ['Open refund', 'فتح الاسترداد'],
        'open_statement' => ['Open statement', 'فتح الكشف'],
        'open_brand_registry' => ['Open brand registry', 'فتح سجل العلامات'],
        'fix_products' => ['Fix products', 'إصلاح المنتجات'],
        'sections_in_server_reading_order_empty_ones_hidden' => ['sections in server reading order, empty ones hidden', 'الأقسام بترتيب الخادم، والفارغة مخفية'],
        'sections_are_hidden_by_your_role' => ['sections are hidden by your role', 'أقسام مخفية بحسب دورك'],
        'monitoring_continues' => ['Monitoring continues.', 'المراقبة مستمرة.'],
        'control_tower_could_not_load' => ['Control Tower could not load', 'تعذّر تحميل مركز التحكم'],
        'the_operations_service_did_not_answer_order_and_inventory_screens_are_unaffected_this_page_only_aggregates_them' => ['The operations service did not answer. Order and inventory screens are unaffected — this page only aggregates them.', 'لم تستجب خدمة العمليات. شاشات الطلبات والمخزون غير متأثرة — هذه الصفحة تجمّعها فقط.'],
        'no_critical_issues_no_sla_risk_and_no_financial_exceptions_in_the_last_check_at' => ['No critical issues, no SLA risk and no financial exceptions in the last check at', 'لا مشاكل حرجة ولا مخاطر على مستوى الخدمة ولا استثناءات مالية في آخر فحص'],
        'no_sales_in_this_period' => ['No sales in this period', 'لا مبيعات في هذه الفترة'],
        'sales_appear_here_once_orders_are_delivered' => ['Sales appear here once orders are delivered.', 'تظهر المبيعات هنا بعد تسليم الطلبات.'],
        'the_products_that_sell_appear_here_ranked_by_revenue' => ['The products that sell appear here, ranked by revenue.', 'تظهر هنا المنتجات التي تُباع مرتبة حسب الإيراد.'],
        'the_balance_service_did_not_answer' => ['The balance service did not answer.', 'لم تستجب خدمة الرصيد.'],
        'no_products_match_these_filters' => ['No products match these filters', 'لا منتجات تطابق هذه الفلاتر'],
        'no_issues_match_these_filters' => ['No issues match these filters', 'لا مشاكل تطابق هذه الفلاتر'],
        'no_movements_match_these_filters' => ['No movements match these filters', 'لا حركات تطابق هذه الفلاتر'],
        'no_stock_matches_these_filters' => ['No stock matches these filters', 'لا مخزون يطابق هذه الفلاتر'],
        'no_stock_to_track_yet' => ['No stock to track yet', 'لا مخزون لتتبعه بعد'],
        'physical_products_appear_here_with_their_cover_and_their_movements' => ['Physical products appear here with their cover and their movements.', 'تظهر المنتجات الفعلية هنا مع تغطيتها وحركاتها.'],
        'lines_are_running_low' => ['lines are running low', 'أسطر توشك على النفاد'],
        'not_sellable_right_now' => ['not sellable right now', 'غير قابل للبيع حالياً'],
        'every_change_carries_the_balance_it_left_behind_the_log_reads_without_replaying_it' => ['Every change carries the balance it left behind — the log reads without replaying it.', 'كل تغيير يحمل الرصيد الناتج عنه — يُقرأ السجل دون إعادة تشغيله.'],
        'nothing_resolved_in_this_period' => ['Nothing resolved in this period', 'لا شيء أُغلق في هذه الفترة'],
        'resolved_issues_stay_here_with_what_closed_them' => ['Resolved issues stay here with what closed them.', 'تبقى المشاكل المُغلقة هنا مع ما أغلقها.'],
        'detection_runs_continuously_and_writes_only_what_it_finds' => ['Detection runs continuously and writes only what it finds.', 'يعمل الرصد باستمرار ولا يكتب إلا ما يجده.'],
        'nothing_recorded_yet' => ['Nothing recorded yet', 'لا شيء مسجّل بعد'],
        'every_status_change_appears_here_with_who_made_it' => ['Every status change appears here with who made it.', 'يظهر كل تغيير حالة هنا مع من قام به.'],
        'closes_when_the_condition_stops_being_true' => ['Closes when the condition stops being true', 'تُغلق عندما يتوقف الشرط عن الصحة'],
        'this_issue_affects' => ['This issue affects', 'تؤثر هذه المشكلة على'],
        'estimated_exposure' => ['Estimated exposure', 'التعرّض التقديري'],
        'impact_is_scored_against_this_shops_own_business_not_an_absolute_figure' => ['Impact is scored against this shop\'s own business, not an absolute figure.', 'تُحسب درجة الأثر مقارنة بأعمال هذا المتجر نفسه، لا برقم مطلق.'],
        'affected_items' => ['Affected items', 'العناصر المتأثرة'],
        'recorded_in_the_ledger' => ['Recorded in the ledger', 'مسجّل في الدفتر'],
        'not_settled_yet_this_is_what_it_will_be' => ['Not settled yet — this is what it will be.', 'لم تُسوَّ بعد — هذا ما ستكون عليه.'],
        'this_order_is_inside_its_ship_by_window_the_countdown_is_measured_from_when_it_was_placed' => ['This order is inside its ship-by window. The countdown is measured from when it was placed.', 'هذا الطلب داخل مهلة الشحن. يُحسب العد التنازلي من وقت الطلب.'],
        'assigned' => ['Assigned', 'مُسند إلى'],
        'system' => ['System', 'النظام'],
        'owner' => ['Owner', 'المالك'],
        'staff' => ['Staff', 'موظف'],
        'more' => ['More', 'المزيد'],
        'menu' => ['Menu', 'القائمة'],
        'help' => ['Help', 'المساعدة'],
        'search' => ['Search', 'بحث'],
        'settings' => ['Settings', 'الإعدادات'],
        'support' => ['Support', 'الدعم'],
        'notifications' => ['Notifications', 'الإشعارات'],
        'account' => ['Account', 'الحساب'],
        'language' => ['Language', 'اللغة'],
        'sign_out' => ['Sign out', 'تسجيل الخروج'],
        'back' => ['Back', 'رجوع'],
        'breadcrumb' => ['Breadcrumb', 'مسار التنقل'],
        'selected' => ['selected', 'محدد'],
        'yes' => ['Yes', 'نعم'],
        'no' => ['No', 'لا'],
        'any' => ['Any', 'أي'],
        'all' => ['All', 'الكل'],
        'access_denied' => ['Access denied', 'الوصول مرفوض'],

        // ── wave 3 · automation (handoff 08 A1–A5, 12) ────────────────────
        'rule' => ['Rule', 'القاعدة'],
        'rules' => ['Rules', 'القواعد'],
        'trigger' => ['Trigger', 'المُشغِّل'],
        'action' => ['Action', 'الإجراء'],
        'scope' => ['Scope', 'النطاق'],
        'limits' => ['Limits', 'الحدود'],
        'when' => ['When', 'عندما'],
        'then' => ['Then', 'عندها'],
        'runs' => ['Runs', 'مرات التشغيل'],
        'applied' => ['Applied', 'طُبِّق'],
        'matched' => ['Matched', 'مطابق'],
        'skipped' => ['Skipped', 'مُتخطّى'],
        'failed' => ['Failed', 'فشل'],
        'result' => ['Result', 'النتيجة'],
        'duration' => ['Duration', 'المدة'],
        'time' => ['Time', 'الوقت'],
        'automation' => ['Automation', 'الأتمتة'],
        'last_run' => ['Last run', 'آخر تشغيل'],
        'success_rate' => ['Success rate', 'نسبة النجاح'],
        'pause' => ['Pause', 'إيقاف مؤقت'],
        'resume' => ['Resume', 'استئناف'],
        'activate' => ['Activate', 'تفعيل'],
        'undo' => ['Undo', 'تراجع'],
        'undone' => ['Undone', 'تم التراجع'],
        'run_now' => ['Run now', 'شغّل الآن'],
        'run_preview' => ['Preview', 'معاينة'],
        'preview_matches' => ['Preview matches', 'معاينة المطابقات'],
        'would_match' => ['Would match', 'سيطابق'],
        'would_apply' => ['Would apply', 'سيُطبَّق'],
        'before' => ['Before', 'قبل'],
        'after' => ['After', 'بعد'],
        'save' => ['Save', 'حفظ'],
        'save_and_activate' => ['Save and activate', 'حفظ وتفعيل'],
        'save_paused' => ['Save paused', 'حفظ موقوفاً'],
        'delete_rule' => ['Delete rule', 'حذف القاعدة'],
        'edit_automation' => ['Edit automation', 'تعديل الأتمتة'],
        'create_automation' => ['Create automation', 'إنشاء أتمتة'],
        'rule_name' => ['Rule name', 'اسم القاعدة'],
        'the_rule' => ['The rule', 'القاعدة'],
        'in_plain_words' => ['In plain words', 'بالكلمات الواضحة'],
        'what_it_has_done' => ['What it has done', 'ما الذي فعلته'],
        'whole_catalogue' => ['Whole catalogue', 'كامل الكتالوج'],
        'contact_support' => ['Contact support', 'تواصل مع الدعم'],
        'stopped_by_the_marketplace' => ['Stopped by the marketplace', 'أوقفها السوق'],
        'a_deleted_rule' => ['A deleted rule', 'قاعدة محذوفة'],
        'back_to_the_rule' => ['Back to the rule', 'العودة إلى القاعدة'],
        'max_actions_per_run' => ['Most changes per run', 'أقصى عدد تغييرات في التشغيلة'],
        'cooldown_minutes' => ['Wait between runs (minutes)', 'الانتظار بين التشغيلات (بالدقائق)'],
        'comma_separated_ids' => ['Ids separated by commas', 'معرّفات مفصولة بفواصل'],
        'what_this_rule_is_for' => ['What this rule is for', 'ما الغرض من هذه القاعدة'],

        // Rule sentence, assembled from whole sentences rather than words.
        'automation_rule_sentence' => [
            ':when, :then. At most :cap per run, waiting :cooldown between runs.',
            ':when، :then. بحدّ أقصى :cap في التشغيلة الواحدة، مع انتظار :cooldown بين التشغيلات.',
        ],
        'automation_when_low_stock' => [
            'When available stock falls to :threshold or fewer',
            'عندما ينخفض المخزون المتاح إلى :threshold أو أقل',
        ],
        'automation_when_out_of_stock' => [
            'When a product runs out of stock',
            'عندما ينفد مخزون المنتج',
        ],
        'automation_when_restocked_after_automation_hid_it' => [
            'When a product automation hid is back in stock',
            'عندما يعود إلى المخزون منتج أخفته الأتمتة',
        ],
        'automation_when_stale_stock' => [
            'When a product has not sold for :days days',
            'عندما لا يُباع المنتج مدة :days يوماً',
        ],
        'automation_then_hide_listing' => ['hide the listing', 'أخفِ العرض'],
        'automation_then_publish_listing' => ['publish the listing again', 'أعِد نشر العرض'],
        'automation_then_set_discount' => [
            'mark it down by :discount_value :discount_type, never below :min_price_after_discount',
            'خفّض سعره بمقدار :discount_value :discount_type، دون النزول تحت :min_price_after_discount',
        ],

        // Trigger and action names, as a person reads them.
        'automation_trigger_low_stock' => ['Stock running low', 'المخزون على وشك النفاد'],
        'automation_trigger_out_of_stock' => ['Out of stock', 'نفد المخزون'],
        'automation_trigger_restocked_after_automation_hid_it' => ['Back in stock', 'عاد إلى المخزون'],
        'automation_trigger_stale_stock' => ['Not selling', 'لا يُباع'],
        'automation_action_hide_listing' => ['Hide the listing', 'إخفاء العرض'],
        'automation_action_publish_listing' => ['Publish the listing', 'نشر العرض'],
        'automation_action_set_discount' => ['Set a discount', 'تطبيق خصم'],

        // Settings, named by the field they configure.
        'automation_field_threshold' => ['Stock threshold', 'حدّ المخزون'],
        'automation_field_days' => ['Days without a sale', 'أيام بلا بيع'],
        'automation_field_discount_type' => ['Discount type', 'نوع الخصم'],
        'automation_field_discount_value' => ['Discount amount', 'قيمة الخصم'],
        'automation_field_min_price_after_discount' => ['Never price below', 'لا تُنزل السعر تحت'],
        'automation_field_brand_ids' => ['Brands', 'العلامات التجارية'],
        'automation_field_category_ids' => ['Categories', 'الفئات'],
        'automation_field_product_ids' => ['Products', 'المنتجات'],
        'automation_option_percent' => ['Percent', 'نسبة مئوية'],
        'automation_option_flat' => ['Fixed amount', 'مبلغ ثابت'],
        'automation_discount_percent' => ['percent', 'بالمئة'],
        'automation_discount_flat' => ['off the price', 'من السعر'],

        // The safety class the server decides, never the screen.
        'automation_class_safe' => ['Runs automatically', 'يعمل تلقائياً'],
        'automation_class_restricted' => ['Cannot be automated', 'لا يمكن أتمتته'],
        'automation_class_restricted_reason' => [
            'Your role does not allow this action, so it cannot be run unattended on your behalf.',
            'دورك لا يسمح بهذا الإجراء، لذا لا يمكن تنفيذه نيابةً عنك دون إشراف.',
        ],
        'automation_class_not_revertible_reason' => [
            'This action cannot be put back, so it is not offered unattended.',
            'لا يمكن التراجع عن هذا الإجراء، لذلك لا يُتاح دون إشراف.',
        ],

        // Run outcomes. `matched` is never called `applied`.
        'automation_outcome_applied' => ['Applied', 'طُبِّق'],
        'automation_outcome_no_match' => ['Nothing matched', 'لا مطابقات'],
        'automation_outcome_capped' => ['Capped', 'تجاوز الحدّ'],
        'automation_outcome_failed' => ['Failed', 'فشل'],
        'automation_run_applied_one' => [
            'Changed 1 of :matched matched, skipping :skipped.',
            'غيّر 1 من :matched مطابقاً، وتخطّى :skipped.',
        ],
        'automation_run_applied_many' => [
            'Changed :count of :matched matched, skipping :skipped.',
            'غيّر :count من :matched مطابقاً، وتخطّى :skipped.',
        ],
        'automation_run_no_match_body' => [
            'The rule ran and found nothing to act on.',
            'عملت القاعدة ولم تجد ما تتصرف حياله.',
        ],
        'automation_run_capped_body' => [
            'This would have changed :matched products, more than the rule allows in one run, so nothing was changed.',
            'كان هذا سيغيّر :matched منتجاً، وهو أكثر مما تسمح به القاعدة في تشغيلة واحدة، فلم يُغيَّر شيء.',
        ],
        'automation_run_failed_body' => [
            'The run stopped before finishing.',
            'توقفت التشغيلة قبل أن تكتمل.',
        ],
        'automation_reason_skipped' => ['Skipped', 'مُتخطّى'],
        'ran_once_no_matches_yet' => ['Ran once · no matches yet', 'عملت مرة · لا مطابقات بعد'],
        'ran_n_times_no_matches_yet' => ['Ran :count times · no matches yet', 'عملت :count مرات · لا مطابقات بعد'],
        'n_seconds' => [':count seconds', ':count ثانية'],
        'n_selected' => [':count selected', ':count محدد'],
        'and_n_more' => ['and :count more', 'و:count أخرى'],
        'automation_scope_brands' => ['Brands: :names :more', 'العلامات: :names :more'],
        'automation_scope_categories' => ['Categories: :names :more', 'الفئات: :names :more'],
        'automation_scope_products' => ['Products: :names :more', 'المنتجات: :names :more'],

        // Screen copy.
        'rules_you_write_the_marketplace_runs_every_change_is_recorded_and_most_can_be_undone' => [
            'Rules you write, the marketplace runs. Every change is recorded, and most can be undone.',
            'قواعد تكتبها أنت وينفّذها السوق. كل تغيير مُسجَّل، ومعظمها قابل للتراجع.',
        ],
        'no_automations' => ['No automations', 'لا توجد أتمتة'],
        'create_rules_to_handle_repetitive_operational_tasks_automatically' => [
            'Create rules to handle repetitive operational tasks automatically.',
            'أنشئ قواعد لتتولّى المهام التشغيلية المتكررة تلقائياً.',
        ],
        'a_rule_watches_for_one_thing_and_does_one_thing_the_preview_shows_exactly_what_it_would_touch' => [
            'A rule watches for one thing and does one thing. The preview shows exactly what it would touch.',
            'القاعدة تراقب شيئاً واحداً وتفعل شيئاً واحداً. المعاينة تُظهر بالضبط ما ستمسّه.',
        ],
        'this_rule_was_not_saved' => ['This rule was not saved', 'لم تُحفظ هذه القاعدة'],
        'a_run_that_would_touch_more_than_the_cap_does_nothing_at_all_and_asks_for_a_person' => [
            'A run that would touch more than the cap does nothing at all, and asks for a person.',
            'التشغيلة التي ستمسّ أكثر من الحدّ لا تفعل شيئاً إطلاقاً، وتطلب تدخّل شخص.',
        ],
        'leave_empty_and_the_rule_applies_to_the_whole_catalogue' => [
            'Leave empty and the rule applies to the whole catalogue.',
            'اترك الحقل فارغاً وستُطبَّق القاعدة على كامل الكتالوج.',
        ],
        'choose_a_trigger_and_an_action_to_see_what_this_rule_would_do' => [
            'Choose a trigger and an action to see what this rule would do.',
            'اختر مُشغِّلاً وإجراءً لترى ما ستفعله هذه القاعدة.',
        ],
        'runs_the_rule_without_changing_anything_and_lists_what_it_would_touch' => [
            'Runs the rule without changing anything, and lists what it would touch.',
            'تُشغّل القاعدة دون تغيير أي شيء، وتسرد ما ستمسّه.',
        ],
        'delete_this_rule_the_record_of_what_it_did_stays' => [
            'Delete this rule? The record of what it did stays.',
            'هل تحذف هذه القاعدة؟ سجلّ ما فعلته يبقى.',
        ],
        'nothing_on_this_page_has_been_changed_this_is_what_the_rule_would_do_if_it_ran_now' => [
            'Nothing on this page has been changed. This is what the rule would do if it ran now.',
            'لم يُغيَّر أي شيء في هذه الصفحة. هذا ما ستفعله القاعدة لو عملت الآن.',
        ],
        'nothing_would_be_applied' => ['Nothing would be applied', 'لن يُطبَّق أي شيء'],
        'this_would_match_n_products_more_than_the_n_allowed_per_run' => [
            'This would match :matched products, more than the :cap allowed per run — nothing would be applied.',
            'سيطابق هذا :matched منتجاً، وهو أكثر من :cap المسموح بها في التشغيلة — لن يُطبَّق أي شيء.',
        ],
        'at_most_n_per_run' => ['At most :count per run', ':count كحدّ أقصى في التشغيلة'],
        'nothing_matches_right_now' => ['Nothing matches right now', 'لا مطابقات في هذه اللحظة'],
        'the_rule_is_written_correctly_it_simply_has_nothing_to_act_on_at_this_moment' => [
            'The rule is written correctly. It simply has nothing to act on at this moment.',
            'القاعدة مكتوبة بشكل صحيح، لكن لا يوجد ما تتصرف حياله في هذه اللحظة.',
        ],
        'every_run_of_every_rule_including_the_ones_that_matched_nothing' => [
            'Every run of every rule, including the ones that matched nothing.',
            'كل تشغيلة لكل قاعدة، بما فيها التي لم تطابق شيئاً.',
        ],
        'nothing_has_run_yet' => ['Nothing has run yet', 'لم يعمل شيء بعد'],
        'once_a_rule_is_active_every_run_it_makes_appears_here_with_what_it_touched' => [
            'Once a rule is active, every run it makes appears here with what it touched.',
            'بمجرد تفعيل قاعدة، تظهر هنا كل تشغيلة تقوم بها مع ما مسّته.',
        ],
        'no_runs_match_these_filters' => ['No runs match these filters', 'لا تشغيلات تطابق هذه المرشحات'],
        'this_run_touched_nothing' => ['This run touched nothing', 'لم تمسّ هذه التشغيلة شيئاً'],
        'the_rule_ran_and_found_nothing_to_act_on' => [
            'The rule ran and found nothing to act on.',
            'عملت القاعدة ولم تجد ما تتصرف حياله.',
        ],

        // ── wave 3 · opportunities (handoff 08 A5) ────────────────────────
        'opportunity_fast_sellers_at_stock_risk' => [
            'Fast-selling products at stock risk',
            'منتجات سريعة البيع مهدّدة بنفاد المخزون',
        ],
        'opportunity_high_traffic_low_conversion' => [
            'High traffic, low conversion',
            'زيارات كثيرة، مبيعات قليلة',
        ],
        'opportunity_priced_below_category_median' => [
            'Priced below the category median',
            'مُسعَّرة تحت وسيط فئتها',
        ],
        'opportunity_stock_risk_evidence' => [
            'From :days days of sales — less than :cover days of cover left at the current rate.',
            'من مبيعات :days يوماً — يتبقى أقل من :cover يوماً من التغطية بالمعدل الحالي.',
        ],
        'opportunity_conversion_evidence' => [
            'From :days days of product views against orders.',
            'من :days يوماً من مشاهدات المنتجات مقابل الطلبات.',
        ],
        'opportunity_price_evidence' => [
            'Compared against active listings in the same category.',
            'بالمقارنة مع العروض الفعّالة في الفئة نفسها.',
        ],
        'detected_from_the_last_n_days_of_your_own_shop_data' => [
            'Detected from the last :days days of your own shop data.',
            'مُكتشَفة من بيانات متجرك خلال آخر :days يوماً.',
        ],
        'no_opportunities_detected' => ['No opportunities detected', 'لا فرص مُكتشَفة'],
        'nothing_in_the_last_n_days_of_views_sales_and_prices_suggests_a_change_worth_making' => [
            'Nothing in the last :days days of views, sales and prices suggests a change worth making.',
            'لا شيء في آخر :days يوماً من المشاهدات والمبيعات والأسعار يشير إلى تغيير يستحق.',
        ],
        'review_stock' => ['Review stock', 'مراجعة المخزون'],
        'review_products' => ['Review products', 'مراجعة المنتجات'],
        'review_prices' => ['Review prices', 'مراجعة الأسعار'],
        'one_product' => ['1 product', 'منتج واحد'],
        'n_products' => [':count products', ':count منتجاً'],
        'nav_classic_dashboard' => ['Classic dashboard', 'اللوحة الكلاسيكية'],

        // ── admin · putting a failed job back on the queue ─────────────────
        'discard' => ['Discard', 'تجاهل'],
        'the_job_was_queued_again' => ['The job was queued again.', 'أُعيد وضع المهمة في الطابور.'],
        'the_failed_job_was_discarded' => ['The failed job was discarded.', 'تم تجاهل المهمة الفاشلة.'],
        'that_job_is_no_longer_in_the_failed_list' => [
            'That job is no longer in the failed list — somebody may have already dealt with it.',
            'لم تعد هذه المهمة في قائمة الفاشلة — ربما عالجها شخص آخر.',
        ],
        'discard_this_failed_job_without_running_it_again' => [
            'Discard this failed job without running it again?',
            'هل تتجاهل هذه المهمة الفاشلة دون إعادة تشغيلها؟',
        ],

        // ── admin · the operations windows the detectors judge by ──────────
        'operations_windows' => ['Operations windows', 'نوافذ التشغيل'],
        'these_windows_are_what_the_action_center_raises_by_and_what_the_countdown_colours_by' => [
            'These windows are what the Action Center raises findings by, and what the seller countdown colours by.',
            'هذه النوافذ هي ما يرفع مركز المهام تنبيهاته وفقه، وما يلوّن العدّ التنازلي لدى البائع بحسبه.',
        ],
        'hours_without_movement_before_an_order_is_raised_as_stuck' => [
            'Hours without movement before an order is raised as stuck',
            'ساعات بلا حركة قبل اعتبار الطلب متوقفاً',
        ],
        'stop_raising_a_stuck_order_after_days' => [
            'Stop raising a stuck order after (days)',
            'التوقف عن رفع الطلب المتوقف بعد (أيام)',
        ],
        'call_an_order_urgent_when_this_share_of_its_window_is_left' => [
            'Call an order urgent when this share of its window is left (0–1)',
            'اعتبار الطلب عاجلاً عندما يتبقى هذا الجزء من نافذته (0–1)',
        ],
        'minutes_left_when_the_countdown_turns_red' => [
            'Minutes left when the countdown turns red',
            'الدقائق المتبقية عندما يتحول العدّ التنازلي إلى الأحمر',
        ],
        'minutes_left_when_the_countdown_turns_amber' => [
            'Minutes left when the countdown turns amber',
            'الدقائق المتبقية عندما يتحول العدّ التنازلي إلى البرتقالي',
        ],
        'hours_to_answer_a_refund_request' => [
            'Hours to answer a refund request',
            'ساعات للردّ على طلب استرداد',
        ],
        'hours_to_process_an_authorised_return' => [
            'Hours to process an authorised return',
            'ساعات لمعالجة إرجاع مُعتمَد',
        ],
        'hours_after_delivery_before_a_missing_earning_is_raised' => [
            'Hours after delivery before a missing earning is raised',
            'ساعات بعد التسليم قبل رفع تنبيه بأرباح غير مُقيّدة',
        ],
        'days_ahead_expiring_stock_is_surfaced' => [
            'Days ahead expiring stock is surfaced',
            'عدد الأيام التي يُظهر خلالها المخزون القارب على انتهاء الصلاحية',
        ],

        // ── platform · issue policy, commerce switch and sweep health ──────
        'seller_issue_policy' => [
            'Seller issue policy',
            'سياسة مشكلات البائعين',
        ],
        'how_a_problem_is_scored_when_it_is_escalated_and_how_often_a_seller_may_be_interrupted' => [
            'How a problem is scored, when it is escalated, and how often a seller may be interrupted',
            'كيف تُسجَّل درجة المشكلة، ومتى تُصعَّد، وكم مرة يُقاطَع البائع',
        ],
        'score_at_which_an_issue_is_critical' => [
            'Score at which an issue is critical',
            'الدرجة التي تصبح عندها المشكلة حرجة',
        ],
        'score_at_which_an_issue_is_high' => [
            'Score at which an issue is high',
            'الدرجة التي تصبح عندها المشكلة مرتفعة',
        ],
        'score_at_which_an_issue_is_medium' => [
            'Score at which an issue is medium',
            'الدرجة التي تصبح عندها المشكلة متوسطة',
        ],
        'hours_a_low_issue_may_stand_before_it_is_promoted' => [
            'Hours a low issue may stand before it is promoted',
            'ساعات بقاء المشكلة المنخفضة قبل تصعيدها',
        ],
        'hours_a_medium_issue_may_stand_before_it_is_promoted' => [
            'Hours a medium issue may stand before it is promoted',
            'ساعات بقاء المشكلة المتوسطة قبل تصعيدها',
        ],
        'hours_a_high_issue_may_stand_before_it_is_promoted' => [
            'Hours a high issue may stand before it is promoted',
            'ساعات بقاء المشكلة المرتفعة قبل تصعيدها',
        ],
        'how_many_times_one_issue_may_be_promoted' => [
            'How many times one issue may be promoted',
            'كم مرة يمكن تصعيد المشكلة الواحدة',
        ],
        'hours_between_interruptions_of_the_same_seller' => [
            'Hours between interruptions of the same seller',
            'الساعات بين مقاطعة وأخرى للبائع نفسه',
        ],
        'the_difference_between_a_useful_alert_and_the_reason_a_seller_switches_notifications_off' => [
            'The difference between a useful alert and the reason a seller switches notifications off',
            'الفرق بين تنبيه مفيد وسبب إيقاف البائع للإشعارات',
        ],
        'the_storefront_personalisation_engine_is_running' => [
            'The storefront personalisation engine is running',
            'محرّك تخصيص الواجهة يعمل',
        ],
        'off_means_collections_fall_back_to_their_catalogue_ordering_and_no_campaign_segment_or_experiment_logic_runs' => [
            'Off means collections fall back to their catalogue ordering, and no campaign, segment or experiment logic runs',
            'الإيقاف يعني عودة المجموعات إلى ترتيب الكتالوج، وتوقّف منطق الحملات والشرائح والتجارب',
        ],
        'what_the_seller_sweeps_did' => [
            'What the seller sweeps did',
            'ما فعلته جولات البائعين',
        ],
        'automation_runs' => [
            'Automation runs',
            'جولات الأتمتة',
        ],
        'runs_that_failed' => [
            'Runs that failed',
            'الجولات الفاشلة',
        ],
        'bulk_jobs' => [
            'Bulk jobs',
            'المهام الجماعية',
        ],
        'jobs_that_failed' => [
            'Jobs that failed',
            'المهام الفاشلة',
        ],
        'jobs_stuck_over_an_hour' => [
            'Jobs stuck over an hour',
            'مهام عالقة أكثر من ساعة',
        ],
        'the_seller_work_ledgers_could_not_be_read' => [
            'The seller work ledgers could not be read',
            'تعذّرت قراءة سجلات عمل البائعين',
        ],
        'Collections' => [
            'Collections',
            'المجموعات',
        ],
        'Campaigns' => [
            'Campaigns',
            'الحملات',
        ],
        'Segments' => [
            'Segments',
            'الشرائح',
        ],
        'Experiments' => [
            'Experiments',
            'التجارب',
        ],

        // ── analytics · privacy settings, data quality and the fold ────────
        'analytics_and_privacy' => [
            'Analytics and privacy',
            'التحليلات والخصوصية',
        ],
        'what_is_measured_about_live_customer_traffic_who_is_excluded_and_how_long_it_is_kept' => [
            'What is measured about live customer traffic, who is excluded, and how long it is kept',
            'ما يُقاس من حركة العملاء الفعلية، ومن يُستبعَد، ومدة الاحتفاظ',
        ],
        'measure_visits_at_all' => [
            'Measure visits at all',
            'قياس الزيارات أصلاً',
        ],
        'honour_the_do_not_track_header' => [
            'Honour the Do Not Track header',
            'احترام ترويسة عدم التتبّع',
        ],
        'refused_visits_are_counted_on_the_data_quality_screen_so_the_drop_has_an_explanation' => [
            'Refused visits are counted on the Data quality screen, so the drop has an explanation',
            'تُحصى الزيارات المرفوضة في شاشة جودة البيانات، فيكون للانخفاض تفسير',
        ],
        'measure_nothing_until_a_visitor_accepts_cookies' => [
            'Measure nothing until a visitor accepts cookies',
            'لا تقس شيئاً حتى يقبل الزائر ملفات تعريف الارتباط',
        ],
        'mask_the_ip_to_its_network_before_hashing_it' => [
            'Mask the IP to its network before hashing it',
            'إخفاء عنوان IP إلى شبكته قبل تجزئته',
        ],
        'store_the_visitors_country' => [
            'Store the visitor’s country',
            'تخزين بلد الزائر',
        ],
        'leave_bots_out_of_the_reports' => [
            'Leave bots out of the reports',
            'استبعاد الروبوتات من التقارير',
        ],
        'leave_your_own_staffs_browsing_out_of_the_reports' => [
            'Leave your own staff’s browsing out of the reports',
            'استبعاد تصفّح موظفيك من التقارير',
        ],
        'minutes_of_inactivity_that_end_a_visit' => [
            'Minutes of inactivity that end a visit',
            'دقائق الخمول التي تنهي الزيارة',
        ],
        'seconds_on_the_shop_before_a_visit_counts_as_engaged' => [
            'Seconds on the shop before a visit counts as engaged',
            'الثواني في المتجر قبل اعتبار الزيارة متفاعلة',
        ],
        'days_individual_events_are_kept' => [
            'Days individual events are kept',
            'عدد أيام الاحتفاظ بالأحداث الفردية',
        ],
        'days_sessions_are_kept' => [
            'Days sessions are kept',
            'عدد أيام الاحتفاظ بالجلسات',
        ],
        'days_the_daily_rollups_are_kept' => [
            'Days the daily rollups are kept',
            'عدد أيام الاحتفاظ بالتجميعات اليومية',
        ],
        'the_rollups_are_small_and_are_what_every_long_range_chart_reads' => [
            'The rollups are small, and are what every long-range chart reads',
            'التجميعات صغيرة الحجم، وهي ما تقرأه كل الرسوم البيانية الممتدة',
        ],
        'what_this_shop_measures' => [
            'What this shop measures',
            'ما يقيسه هذا المتجر',
        ],
        'the_pipeline_itself' => [
            'The pipeline itself',
            'خط المعالجة نفسه',
        ],
        'events_written' => [
            'Events written',
            'الأحداث المكتوبة',
        ],
        'dropped_buffer_full' => [
            'Dropped — buffer full',
            'مهملة — الذاكرة المؤقتة ممتلئة',
        ],
        'write_failures' => [
            'Write failures',
            'إخفاقات الكتابة',
        ],
        'events_reached_the_recorder_and_were_thrown_away_because_one_request_produced_more_than_the_buffer_holds' => [
            'Events reached the recorder and were thrown away because one request produced more than the buffer holds',
            'وصلت أحداث إلى المسجّل وأُهملت لأن طلباً واحداً أنتج أكثر مما تتسع له الذاكرة المؤقتة',
        ],
        'every_number_on_every_analytics_screen_is_short_by_that_much' => [
            'Every number on every analytics screen is short by that much',
            'كل رقم في كل شاشة تحليلات ناقص بهذا المقدار',
        ],
        'visits_we_chose_not_to_measure' => [
            'Visits we chose not to measure',
            'زيارات اخترنا ألا نقيسها',
        ],
        'neither_privacy_control_is_switched_on' => [
            'Neither privacy control is switched on',
            'لم يُفعَّل أي من ضابطي الخصوصية',
        ],
        'no_visit_is_being_refused_so_nothing_is_missing_from_the_figures_for_this_reason' => [
            'No visit is being refused, so nothing is missing from the figures for this reason',
            'لا تُرفض أي زيارة، فلا ينقص من الأرقام شيء لهذا السبب',
        ],
        'do_not_track' => [
            'Do Not Track',
            'عدم التتبّع',
        ],
        'consent_not_given' => [
            'Consent not given',
            'لم تُمنح الموافقة',
        ],
        'total_refused' => [
            'Total refused',
            'إجمالي المرفوض',
        ],
        'these_visits_were_deliberately_not_measured_they_are_the_reason_the_figures_are_lower_than_your_server_logs' => [
            'These visits were deliberately not measured. They are the reason the figures are lower than your server logs',
            'لم تُقَس هذه الزيارات عمداً. وهي سبب كون الأرقام أقل من سجلات خادمك',
        ],
        'what_each_link_did_in_this_window' => [
            'What each link did in this window',
            'ما فعله كل رابط في هذه النافذة',
        ],
        'short_link' => [
            'Short link',
            'الرابط المختصر',
        ],

        // ── integrations · the portal's reference sections and webhooks ────
        'developer_portal' => [
            'Developer portal',
            'بوابة المطوّرين',
        ],
        'what_the_api_console_may_do_and_whether_response_shapes_are_learned_from_traffic' => [
            'What the API console may do, and whether response shapes are learned from traffic',
            'ما يُسمح لوحدة تجربة الواجهة بفعله، وهل تُستنتج أشكال الاستجابات من حركة المرور الفعلية',
        ],
        'the_api_console_is_available' => [
            'The API console is available',
            'وحدة تجربة الواجهة متاحة',
        ],
        'the_console_may_send_writes' => [
            'The console may send writes',
            'يمكن للوحدة إرسال طلبات كتابة',
        ],
        'off_by_default_everywhere_a_console_on_an_admin_panel_sends_real_requests_at_the_shop_that_takes_the_orders' => [
            'Off by default everywhere — a console on an admin panel sends real requests at the shop that takes the orders',
            'معطّل افتراضياً في كل مكان — الوحدة في لوحة الإدارة ترسل طلبات حقيقية إلى المتجر الذي يستقبل الطلبات',
        ],
        'console_requests_per_minute_per_administrator' => [
            'Console requests per minute, per administrator',
            'عدد طلبات الوحدة في الدقيقة لكل مسؤول',
        ],
        'learn_response_shapes_from_real_traffic' => [
            'Learn response shapes from real traffic',
            'استنتاج أشكال الاستجابات من حركة المرور الفعلية',
        ],
        'only_keys_and_types_are_stored_never_a_value_from_any_response' => [
            'Only keys and types are stored — never a value from any response',
            'تُخزَّن المفاتيح والأنواع فقط — ولا تُخزَّن أي قيمة من أي استجابة',
        ],
        'a_console_on_an_admin_panel_sends_real_requests_at_the_shop_that_takes_the_orders' => [
            'A console on an admin panel sends real requests at the shop that takes the orders',
            'الوحدة في لوحة الإدارة ترسل طلبات حقيقية إلى المتجر الذي يستقبل الطلبات',
        ],
        'some_endpoints_are_never_sent_at_any_setting_money_identity_removal_and_that_list_is_deliberately_not_configurable' => [
            'Some endpoints are never sent at any setting — money, identity, removal — and that list is deliberately not configurable',
            'بعض النقاط لا تُرسَل مهما كانت الإعدادات — المال والهوية والحذف — وهذه القائمة غير قابلة للضبط عمداً',
        ],
        'api_snapshots' => [
            'API snapshots',
            'لقطات الواجهة البرمجية',
        ],
        'snapshots_are_being_taken_so_the_change_log_and_the_breaking_change_detection_have_something_to_compare_against' => [
            'Snapshots are being taken, so the change log and the breaking-change detection have something to compare against',
            'تُلتقط اللقطات، فيصبح لسجل التغييرات وكشف التغييرات الكاسرة ما يُقارَن به',
        ],
        'no_snapshot_has_been_stored_yet_so_the_change_log_has_nothing_to_compare_against' => [
            'No snapshot has been stored yet, so the change log has nothing to compare against',
            'لم تُخزَّن أي لقطة بعد، فليس لدى سجل التغييرات ما يُقارن به',
        ],
        'take_a_snapshot_now' => [
            'Take a snapshot now',
            'التقط لقطة الآن',
        ],
        'the_events_you_can_subscribe_to' => [
            'The events you can subscribe to',
            'الأحداث التي يمكنك الاشتراك بها',
        ],
        'sent_when' => [
            'Sent when',
            'تُرسَل عندما',
        ],
        'a_customer_placed_an_order_containing_at_least_one_of_your_products' => [
            'A customer placed an order containing at least one of your products',
            'قدّم عميل طلباً يحوي منتجاً واحداً على الأقل من منتجاتك',
        ],
        'an_order_of_yours_moved_to_a_new_status' => [
            'An order of yours moved to a new status',
            'انتقل أحد طلباتك إلى حالة جديدة',
        ],
        'a_customer_asked_for_a_refund_on_one_of_your_lines' => [
            'A customer asked for a refund on one of your lines',
            'طلب عميل استرداداً على أحد بنودك',
        ],
        'one_of_your_products_fell_under_the_low_stock_threshold' => [
            'One of your products fell under the low-stock threshold',
            'انخفض أحد منتجاتك دون عتبة المخزون المنخفض',
        ],
        'one_of_your_automation_rules_hid_a_listing' => [
            'One of your automation rules hid a listing',
            'أخفت إحدى قواعد الأتمتة لديك إدراجاً',
        ],
        'a_payout_of_yours_moved_to_a_new_status' => [
            'A payout of yours moved to a new status',
            'انتقلت إحدى دفعاتك إلى حالة جديدة',
        ],
        'verifying_a_delivery' => [
            'Verifying a delivery',
            'التحقق من التسليم',
        ],
        'every_delivery_is_signed_without_a_signature_a_webhook_endpoint_is_a_url_that_does_something_when_anybody_posts_to_it' => [
            'Every delivery is signed. Without a signature, a webhook endpoint is a URL that does something when anybody posts to it',
            'كل تسليم موقّع. بلا توقيع، تصبح نقطة الويب هوك رابطاً يفعل شيئاً عندما يرسل إليه أي أحد',
        ],
        'header' => [
            'Header',
            'الترويسة',
        ],
        'algorithm' => [
            'Algorithm',
            'الخوارزمية',
        ],
        'signed_over' => [
            'Signed over',
            'التوقيع على',
        ],
        'the_exact_request_body_as_bytes_not_a_reserialisation_of_it' => [
            'The exact request body as bytes, not a re-serialisation of it',
            'جسم الطلب بالضبط كبايتات، لا إعادة تسلسل له',
        ],
        'the_secret' => [
            'The secret',
            'السر',
        ],
        'once_when_the_endpoint_is_created_and_never_again' => [
            'Shown once when the endpoint is created, and never again',
            'يُعرض مرة عند إنشاء النقطة، ولا يُعرض بعدها',
        ],
        'other_headers' => [
            'Other headers',
            'ترويسات أخرى',
        ],
        'what_happens_when_your_endpoint_is_down' => [
            'What happens when your endpoint is down',
            'ما يحدث عندما تكون نقطتك متوقفة',
        ],
        'attempts' => [
            'Attempts',
            'المحاولات',
        ],
        'first_retry' => [
            'First retry',
            'أول إعادة محاولة',
        ],
        'doubling_each_attempt' => [
            'Doubling each attempt',
            'تتضاعف مع كل محاولة',
        ],
        'total_window' => [
            'Total window',
            'النافذة الكلية',
        ],
        'plan_your_outage_window_against_this' => [
            'Plan your outage window against this',
            'خطّط لفترة انقطاعك بناءً على هذا',
        ],
        'timeout' => [
            'Timeout',
            'المهلة',
        ],
        'switched_off_after' => [
            'Switched off after',
            'يُطفأ بعد',
        ],
        'consecutive_failures' => [
            'Consecutive failures',
            'إخفاقات متتالية',
        ],
        'a_switched_off_endpoint_is_cleared_by' => [
            'A switched-off endpoint is cleared by',
            'تُستعاد النقطة المطفأة عبر',
        ],
        're_saving_the_endpoint_which_resets_its_failure_run' => [
            'Re-saving the endpoint, which resets its failure run',
            'إعادة حفظ النقطة، ما يصفّر سلسلة إخفاقاتها',
        ],
        'the_retry_sweep_is' => [
            'The retry sweep is',
            'جولة إعادة المحاولة هي',
        ],
        'where_we_will_and_will_not_deliver' => [
            'Where we will and will not deliver',
            'إلى أين نُسلّم وإلى أين لا نُسلّم',
        ],
        'https_only' => [
            'HTTPS only',
            'HTTPS فقط',
        ],
        'refused' => [
            'Refused',
            'مرفوض',
        ],
        'private_addresses_loopback_and_cloud_metadata_endpoints' => [
            'Private addresses, loopback, and cloud metadata endpoints',
            'العناوين الخاصة والاسترجاع ونقاط بيانات السحابة الوصفية',
        ],
        'redirects_are_not_followed' => [
            'Redirects are not followed',
            'لا تُتبع عمليات إعادة التوجيه',
        ],
        'delivery_on_this_deployment' => [
            'Delivery on this deployment',
            'التسليم في هذا التثبيت',
        ],
        'endpoints' => [
            'Endpoints',
            'النقاط',
        ],
        'active' => [
            'Active',
            'نشط',
        ],
        'switched_off_by_us' => [
            'Switched off by us',
            'أطفأناها',
        ],
        'waiting_to_retry' => [
            'Waiting to retry',
            'بانتظار إعادة المحاولة',
        ],
        'given_up_on' => [
            'Given up on',
            'تم التخلي عنها',
        ],
        'delivered_today' => [
            'Delivered today',
            'سُلّمت اليوم',
        ],
        'this_enumeration_could_not_be_read' => [
            'This enumeration could not be read',
            'تعذّرت قراءة هذا التعداد',
        ],
        'the_constant_it_is_declared_in_has_been_renamed_or_removed' => [
            'The constant it is declared in has been renamed or removed',
            'الثابت المُعلَن فيه أُعيدت تسميته أو حُذف',
        ],
        'where_a_sellers_withdrawal_has_got_to' => [
            'Where a seller’s withdrawal has got to',
            'إلى أين وصل طلب سحب البائع',
        ],
        'the_events_an_endpoint_may_subscribe_to' => [
            'The events an endpoint may subscribe to',
            'الأحداث التي يمكن للنقطة الاشتراك بها',
        ],
        'the_states_a_seller_may_put_their_own_endpoint_into' => [
            'The states a seller may put their own endpoint into',
            'الحالات التي يمكن للبائع وضع نقطته فيها',
        ],
        'endpoints_other_systems_post_into' => [
            'Endpoints other systems post into',
            'النقاط التي ترسل إليها أنظمة أخرى',
        ],
        'nothing_posts_into_this_shop' => [
            'Nothing posts into this shop',
            'لا شيء يرسل إلى هذا المتجر',
        ],
        'endpoint' => [
            'Endpoint',
            'النقطة',
        ],
        'method' => [
            'Method',
            'الطريقة',
        ],
        'authentication' => [
            'Authentication',
            'المصادقة',
        ],
        'guarded' => [
            'Guarded',
            'محمية',
        ],
        'unauthenticated' => [
            'Unauthenticated',
            'غير مُصادَق عليها',
        ],
        'payment_gateways_this_shop_calls_out_to' => [
            'Payment gateways this shop calls out to',
            'بوابات الدفع التي يتصل بها هذا المتجر',
        ],
        'no_payment_gateway_is_configured' => [
            'No payment gateway is configured',
            'لا توجد بوابة دفع مضبوطة',
        ],
        'gateway' => [
            'Gateway',
            'البوابة',
        ],
        'switched' => [
            'Switched',
            'الحالة',
        ],
        'ready' => [
            'Ready',
            'جاهزة',
        ],
        'test_mode_no_money_moves' => [
            'Test mode — no money moves',
            'وضع الاختبار — لا ينتقل أي مال',
        ],
        'outbound_webhooks_to_sellers' => [
            'Outbound webhooks to sellers',
            'الويب هوك الصادر إلى البائعين',
        ],
        'sellers_affected' => [
            'sellers affected',
            'بائعين متأثرين',
        ],
        'seller_webhook_delivery_could_not_be_read' => [
            'Seller webhook delivery could not be read',
            'تعذّرت قراءة تسليم ويب هوك البائعين',
        ],
        'temperature' => [
            'Temperature',
            'درجة الإبداع',
        ],
        'left_empty_the_shipped_default_is_used' => [
            'Left empty, the shipped default is used',
            'إن تُرك فارغاً، يُستخدَم الافتراضي المرفق',
        ],
        'lower_is_more_literal_higher_is_more_inventive' => [
            'Lower is more literal, higher is more inventive',
            'الأقل أكثر حرفية، والأعلى أكثر ابتكاراً',
        ],

        // ── finance · payout terms, failed transfers and gateway readiness ─
        'the_platforms_anti_account_takeover_hold_the_length_is_what_a_risk_team_retunes_after_an_incident' => [
            'The platform\'s anti-account-takeover hold — the length is what a risk team retunes after an incident',
            'حجز المنصة ضد استيلاء الحسابات — مدّته ما يعيد فريق المخاطر ضبطه بعد أي حادثة',
        ],
        'mark_failed' => [
            'Mark failed',
            'وضع كفاشلة',
        ],
        'mark_payout_failed' => [
            'Mark payout failed',
            'وضع الدفعة كفاشلة',
        ],
        'a_failed_transfer_means_the_money_never_left_so_it_goes_back_to_the_sellers_available_balance' => [
            'A failed transfer means the money never left, so it goes back to the seller\'s available balance',
            'التحويل الفاشل يعني أن المال لم يغادر، فيعود إلى الرصيد المتاح للبائع',
        ],
        'what_the_bank_said' => [
            'What the bank said',
            'ما قالته البنك',
        ],
        'the_payout_was_marked_failed_and_the_money_is_back_in_the_sellers_available_balance' => [
            'The payout was marked failed and the money is back in the seller\'s available balance.',
            'تم وضع الدفعة كفاشلة وعاد المال إلى الرصيد المتاح للبائع.',
        ],
        'only_an_approved_processing_or_paid_payout_can_be_marked_failed' => [
            'Only an approved, processing or paid payout can be marked failed.',
            'لا يمكن وضع كفاشلة إلا دفعة معتمدة أو قيد المعالجة أو مدفوعة.',
        ],
        'send_again' => [
            'Send again',
            'إعادة الإرسال',
        ],
        'open_a_new_payout_request_for_the_same_amount' => [
            'Open a new payout request for the same amount',
            'فتح طلب صرف جديد بالمبلغ نفسه',
        ],
        'a_new_payout_request_was_opened' => [
            'A new payout request was opened',
            'تم فتح طلب صرف جديد',
        ],
        'that_payout_could_not_be_sent_again' => [
            'That payout could not be sent again.',
            'تعذّر إعادة إرسال هذه الدفعة.',
        ],
        'only_a_failed_payout_can_be_sent_again' => [
            'Only a failed payout can be sent again.',
            'لا يمكن إعادة إرسال سوى دفعة فاشلة.',
        ],
        'these_gateways_are_switched_on_and_cannot_take_a_payment' => [
            'These gateways are switched on and cannot take a payment',
            'هذه البوابات مفعّلة ولا يمكنها استقبال أي دفعة',
        ],
        'these_gateways_are_live_on_your_checkout_in_test_mode_so_no_money_moves' => [
            'These gateways are live on your checkout in test mode, so no money moves',
            'هذه البوابات ظاهرة في صفحة الدفع بوضع الاختبار، فلا ينتقل أي مال',
        ],
        'currency_model' => [
            'Currency model',
            'نموذج العملة',
        ],
        'whether_this_marketplace_runs_on_one_currency_or_several_with_exchange_rates' => [
            'Whether this marketplace runs on one currency, or several with exchange rates',
            'هل يعمل هذا السوق بعملة واحدة أم بعدّة عملات مع أسعار صرف',
        ],
        'single_currency' => [
            'Single currency',
            'عملة واحدة',
        ],
        'multi_currency' => [
            'Multi currency',
            'عدّة عملات',
        ],
        'on_single_currency_exchange_rates_are_not_applied_anywhere_even_where_they_are_stored' => [
            'On single currency, exchange rates are not applied anywhere — even where they are stored',
            'في وضع العملة الواحدة لا تُطبَّق أسعار الصرف في أي مكان — حتى حيث تكون مخزّنة',
        ],
        'the_currency_model_was_updated' => [
            'The currency model was updated.',
            'تم تحديث نموذج العملة.',
        ],
        'the_payment_terms_were_saved' => [
            'The payment terms were saved.',
            'تم حفظ شروط الدفع.',
        ],

        // ── security · the audit trail, sign-in events and bot defence ────
        'what_changed' => [
            'What changed',
            'ما الذي تغيّر',
        ],
        'who' => [
            'Who',
            'مَن',
        ],
        'field' => [
            'Field',
            'الحقل',
        ],
        'no_field_changed' => [
            'No field changed',
            'لم يتغيّر أي حقل',
        ],
        'everything_you_your_staff_and_your_api_keys_did_and_everything_the_platform_recorded_about_your_shop' => [
            'Everything you, your staff and your API keys did — and everything the platform recorded about your shop',
            'كل ما فعلته أنت وموظفوك ومفاتيح الواجهة البرمجية — وكل ما سجّلته المنصة عن متجرك',
        ],
        'actions_appear_here_as_they_happen_there_is_nothing_to_switch_on' => [
            'Actions appear here as they happen; there is nothing to switch on.',
            'تظهر الإجراءات هنا فور حدوثها؛ لا شيء بحاجة إلى تفعيل.',
        ],
        'showing_n_of_m_recorded_actions' => [
            'Showing :shown of :total recorded actions.',
            'عرض :shown من أصل :total إجراء مُسجَّل.',
        ],
        'authentication_security' => [
            'Authentication security',
            'أمن تسجيل الدخول',
        ],
        'Authentication_Security' => [
            'Authentication Security',
            'أمن تسجيل الدخول',
        ],
        'the_bot_defence_on_your_sign_in_forms_and_how_a_customer_recovers_their_account' => [
            'The bot defence on your sign-in forms, and how a customer recovers their account',
            'دفاع النماذج ضد الروبوتات، وكيفية استعادة العميل لحسابه',
        ],
        'recaptcha' => [
            'reCAPTCHA',
            'reCAPTCHA',
        ],
        'applies_to_every_sign_in_and_password_reset_form_admin_vendor_and_customer' => [
            'Applies to every sign-in and password-reset form — admin, vendor and customer',
            'يُطبَّق على كل نماذج تسجيل الدخول واستعادة كلمة المرور — الإدارة والبائع والعميل',
        ],
        'enforced' => [
            'Enforced',
            'مُطبَّق',
        ],
        'not_enforced' => [
            'Not enforced',
            'غير مُطبَّق',
        ],
        'while_this_is_off_the_sign_in_forms_are_protected_by_rate_limiting_alone' => [
            'While this is off, the sign-in forms are protected by rate limiting alone',
            'ما دام هذا معطّلاً، فنماذج الدخول محميّة بتحديد المعدّل وحده',
        ],
        'the_limit_is_on_the_platform_policies_page_under_access_policy' => [
            'The limit is on the Platform Policies page, under Access policy',
            'الحد موجود في صفحة سياسات المنصة ضمن سياسة الوصول',
        ],
        'site_key' => [
            'Site key',
            'مفتاح الموقع',
        ],
        'secret_key' => [
            'Secret key',
            'المفتاح السري',
        ],
        'lowest_score_let_through' => [
            'Lowest score let through',
            'أدنى درجة مسموح بمرورها',
        ],
        'lowest_recaptcha_score_a_visitor_may_have_and_still_be_let_through' => [
            'Lowest reCAPTCHA score a visitor may have and still be let through',
            'أدنى درجة reCAPTCHA يمكن للزائر أن يحملها ويُسمح له بالمرور',
        ],
        'higher_turns_away_more_bots_and_more_people' => [
            'Higher turns away more bots, and more people',
            'الأعلى يمنع روبوتات أكثر، وأشخاصاً أكثر',
        ],
        'recaptcha_needs_both_a_site_key_and_a_secret_key_before_it_can_be_switched_on' => [
            'reCAPTCHA needs both a site key and a secret key before it can be switched on.',
            'يحتاج reCAPTCHA إلى مفتاح موقع ومفتاح سري قبل تفعيله.',
        ],
        'the_authentication_settings_were_saved' => [
            'The authentication settings were saved.',
            'تم حفظ إعدادات تسجيل الدخول.',
        ],
        'customer_password_recovery' => [
            'Customer password recovery',
            'استعادة كلمة مرور العميل',
        ],
        'the_vendor_and_delivery_man_equivalents_are_on_their_own_settings_pages' => [
            'The vendor and delivery-man equivalents are on their own settings pages',
            'ما يقابلها للبائع ومندوب التوصيل موجود في صفحات إعداداتهما',
        ],
        'send_the_reset_through' => [
            'Send the reset through',
            'أرسل الاستعادة عبر',
        ],
        'sms_otp' => [
            'SMS OTP',
            'رمز عبر رسالة نصية',
        ],
        'this_value_is_also_shipped_to_the_mobile_apps_in_the_config_payload_so_they_ask_for_the_same_thing_the_website_does' => [
            'This value is also shipped to the mobile apps in the config payload, so they ask for the same thing the website does',
            'تُرسَل هذه القيمة أيضاً إلى تطبيقات الجوال ضمن حزمة الضبط، فتطلب ما يطلبه الموقع نفسه',
        ],

        // ── admin · the monitoring console's write actions ────────────────
        'a_note_needs_something_to_say' => [
            'A note needs something to say.',
            'الملاحظة تحتاج نصاً.',
        ],
        'a_probe_needs_a_name_and_a_url' => [
            'A probe needs a name and a URL.',
            'الفحص يحتاج اسماً ورابطاً.',
        ],
        'a_request_id_is_hexadecimal_this_one_cannot_have_come_from_this_system' => [
            'A request id is hexadecimal; this one cannot have come from this system',
            'معرّف الطلب سداسي عشري؛ هذا المعرّف لا يمكن أن يكون من هذا النظام',
        ],
        'a_rule_key_is_letters_numbers_dots_and_dashes' => [
            'A rule key is letters, numbers, dots and dashes.',
            'مفتاح القاعدة يتكوّن من أحرف وأرقام ونقاط وشرطات.',
        ],
        'a_rule_needs_a_metric_to_watch' => [
            'A rule needs a metric to watch.',
            'القاعدة تحتاج مقياساً تراقبه.',
        ],
        'a_rule_needs_a_warning_or_a_critical_threshold' => [
            'A rule needs a warning or a critical threshold, or it can never fire.',
            'القاعدة تحتاج عتبة تحذير أو عتبة حرجة، وإلا لن تُطلق أبداً.',
        ],
        'acknowledge' => [
            'Acknowledge',
            'استلام',
        ],
        'acknowledged' => [
            'Acknowledged',
            'مُستلَم',
        ],
        'add' => [
            'Add',
            'إضافة',
        ],
        'add_a_note' => [
            'Add a note',
            'إضافة ملاحظة',
        ],
        'add_a_note_to_the_timeline' => [
            'Add a note to the timeline',
            'إضافة ملاحظة إلى الخط الزمني',
        ],
        'add_or_change_a_rule' => [
            'Add or change a rule',
            'إضافة قاعدة أو تعديلها',
        ],
        'an_existing_key_edits_that_rule' => [
            'An existing key edits that rule',
            'المفتاح الموجود يعدّل تلك القاعدة',
        ],
        'backup_id_optional' => [
            'Backup id (optional)',
            'معرّف النسخة الاحتياطية (اختياري)',
        ],
        'body_must_contain' => [
            'Body must contain',
            'يجب أن يحتوي المحتوى على',
        ],
        'branch' => [
            'Branch',
            'الفرع',
        ],
        'cannot_be_told_apart' => [
            'Cannot be told apart',
            'لا يمكن التمييز',
        ],
        'captured_because' => [
            'Captured because',
            'سبب الحفظ',
        ],
        'checkout_page' => [
            'Checkout page',
            'صفحة الدفع',
        ],
        'close_this_incident' => [
            'Close this incident',
            'إغلاق هذه الحادثة',
        ],
        'cooldown_seconds' => [
            'Cooldown (seconds)',
            'فترة التهدئة (ثوانٍ)',
        ],
        'database_override' => [
            'Database override',
            'تجاوز من قاعدة البيانات',
        ],
        'delete' => [
            'Delete',
            'حذف',
        ],
        'delete_this_rule_nothing_will_watch_that_metric_afterwards' => [
            'Delete this rule? Nothing will watch that metric afterwards',
            'هل تحذف هذه القاعدة؟ لن يراقب أحد ذلك المقياس بعدها',
        ],
        'description' => [
            'Description',
            'الوصف',
        ],
        'detail_optional' => [
            'Detail (optional)',
            'التفاصيل (اختياري)',
        ],
        'either_they_are_older_than_the_window_above_or_the_timeline_is_not_being_written' => [
            'Either they are older than the window above, or the timeline is not being written',
            'إمّا أنها أقدم من النافذة أعلاه، أو أن الخط الزمني لا يُكتب',
        ],
        'email' => [
            'Email',
            'البريد',
        ],
        'email_these_addresses' => [
            'Email these addresses',
            'أرسل إلى هذه العناوين',
        ],
        'enable' => [
            'Enable',
            'تفعيل',
        ],
        'enabled' => [
            'Enabled',
            'مُفعَّل',
        ],
        'environment_variable' => [
            'Environment variable',
            'متغيّر بيئة',
        ],
        'every_value_below_is_the_one_configuration_holds_but_whether_any_of_them_has_been_overridden_in_the_database_is_unknown' => [
            'Every value below is the one configuration holds, but whether any of them has been overridden in the database is unknown',
            'كل قيمة أدناه هي ما يحمله الضبط، لكن لا يُعرف إن كان أيّ منها متجاوزاً في قاعدة البيانات',
        ],
        'everything_here_is_redacted_before_it_is_drawn_a_stack_trace_is_a_reliable_place_to_find_a_token' => [
            'Everything here is redacted before it is drawn — a stack trace is a reliable place to find a token',
            'كل ما هنا مُنقّح قبل عرضه — أثر الاستدعاء مكان شائع لتسرّب المفاتيح',
        ],
        'evidence' => [
            'Evidence',
            'الدليل',
        ],
        'expect_status' => [
            'Expected status',
            'الحالة المتوقعة',
        ],
        'expects' => [
            'Expects',
            'يتوقع',
        ],
        'external' => [
            'External',
            'خارجي',
        ],
        'files' => [
            'Files',
            'الملفات',
        ],
        'firings_in_total_and_none_of_them_appear_on_this_timeline' => [
            'firings in total, and none of them appear on this timeline',
            'إطلاقاً في المجموع، ولا يظهر أيّ منها على هذا الخط الزمني',
        ],
        'firings_the_timeline_could_not_be_read_so_the_two_cannot_be_compared' => [
            'firings; the timeline could not be read, so the two cannot be compared',
            'إطلاقاً؛ تعذّرت قراءة الخط الزمني، فلا يمكن المقارنة',
        ],
        'fresh' => [
            'Fresh',
            'حديث',
        ],
        'in_effect' => [
            'In effect',
            'السارية',
        ],
        'in_its_own_database' => [
            'In its own database',
            'في قاعدة بياناتها الخاصة',
        ],
        'information_schema_is_readable_on_most_deployments_but_a_locked_down_grant_will_refuse_it' => [
            'information_schema is readable on most deployments, but a locked-down grant will refuse it',
            'جدول information_schema مقروء في معظم البيئات، لكن صلاحية مقيّدة سترفضه',
        ],
        'kept_because' => [
            'Kept because',
            'سبب الاحتفاظ',
        ],
        'key' => [
            'Key',
            'المفتاح',
        ],
        'kind' => [
            'Kind',
            'النوع',
        ],
        'look_up' => [
            'Look up',
            'بحث',
        ],
        'look_up_a_request' => [
            'Look up a request',
            'ابحث عن طلب',
        ],
        'mark_resolved' => [
            'Mark resolved',
            'وضع كمُعالَجة',
        ],
        'memory_peak_kb' => [
            'Peak memory (KB)',
            'ذروة الذاكرة (كيلوبايت)',
        ],
        'must_hold_for_seconds' => [
            'Must hold for (seconds)',
            'يجب أن تستمر (ثوانٍ)',
        ],
        'name' => [
            'Name',
            'الاسم',
        ],
        'newest_successful' => [
            'Newest successful',
            'أحدث ناجحة',
        ],
        'no_alert_rule_could_be_listed' => [
            'No alert rule could be listed',
            'تعذّر إدراج أي قاعدة تنبيه',
        ],
        'no_reading' => [
            'No reading',
            'لا قراءة',
        ],
        'no_release_was_recorded_near_this_incident' => [
            'No release was recorded near this incident',
            'لم تُسجَّل أي إصدارة قرب هذه الحادثة',
        ],
        'no_rule_was_past_its_threshold_at_the_last_evaluation' => [
            'No rule was past its threshold at the last evaluation',
            'لم تتجاوز أي قاعدة عتبتها في آخر تقييم',
        ],
        'no_trace_was_kept_for_this_request' => [
            'No trace was kept for this request',
            'لم يُحفَظ أثر لهذا الطلب',
        ],
        'none' => [
            'None',
            'لا شيء',
        ],
        'not_a_number' => [
            'Not a number',
            'ليس رقماً',
        ],
        'not_a_setting_the_running_code_reads_back' => [
            'Not a setting the running code reads back',
            'ليس إعداداً يقرأه الكود العامل',
        ],
        'noted_on_the_timeline' => [
            'Noted on the timeline.',
            'سُجّلت على الخط الزمني.',
        ],
        'nothing_is_configured_in_this_group' => [
            'Nothing is configured in this group',
            'لا شيء مضبوط في هذه المجموعة',
        ],
        'nothing_is_firing' => [
            'Nothing is firing',
            'لا شيء يُطلق تنبيهاً',
        ],
        'nothing_to_do_there_the_incident_may_already_be_in_that_state' => [
            'Nothing to do there — the incident may already be in that state.',
            'لا شيء لفعله — قد تكون الحادثة في تلك الحالة أصلاً.',
        ],
        'nothing_was_recorded_under_that_id_a_request_that_neither_failed_nor_was_sampled_leaves_no_row_and_rows_are_pruned_at_the_retention_window' => [
            'Nothing was recorded under that id. A request that neither failed nor was sampled leaves no row, and rows are pruned at the retention window',
            'لم يُسجَّل شيء تحت هذا المعرّف. الطلب الذي لم يفشل ولم يُؤخذ كعيّنة لا يترك سجلاً، والسجلات تُحذف عند نافذة الاحتفاظ',
        ],
        'notify_channels' => [
            'Notify channels',
            'قنوات الإشعار',
        ],
        'notify_email' => [
            'Notify by email',
            'الإشعار بالبريد',
        ],
        'off' => [
            'Off',
            'معطّل',
        ],
        'on' => [
            'On',
            'مفعّل',
        ],
        'only_http_urls_can_be_probed_and_never_a_cloud_metadata_address' => [
            'Only http(s) URLs can be probed, and never a cloud metadata address.',
            'لا يمكن فحص سوى روابط http(s)، وليس عناوين بيانات السحابة الوصفية أبداً.',
        ],
        'only_the_first_rules_are_listed_here' => [
            'Only the first rules are listed here',
            'القواعد الأولى فقط مدرجة هنا',
        ],
        'open_this_error_group' => [
            'Open this error group',
            'افتح مجموعة الأخطاء هذه',
        ],
        'operator' => [
            'Operator',
            'المعامل',
        ],
        'outcome' => [
            'Outcome',
            'النتيجة',
        ],
        'paste_a_request_id_from_a_response_header_or_a_log_line' => [
            'Paste a request id from a response header or a log line',
            'ألصق معرّف طلب من ترويسة استجابة أو سطر سجل',
        ],
        'probable_cause' => [
            'Probable cause',
            'السبب المرجّح',
        ],
        'probe_a_customer_journey' => [
            'Probe a customer journey',
            'فحص رحلة عميل',
        ],
        'queries' => [
            'Queries',
            'الاستعلامات',
        ],
        'read_from_the_build_if_left_empty' => [
            'Read from the build if left empty',
            'يُقرأ من البناء إن تُرك فارغاً',
        ],
        'record' => [
            'Record',
            'تسجيل',
        ],
        'record_a_backup_that_has_already_run' => [
            'Record a backup that has already run',
            'سجّل نسخة احتياطية تمّت بالفعل',
        ],
        'record_a_release' => [
            'Record a release',
            'سجّل إصدارة',
        ],
        'record_restore_test' => [
            'Record restore test',
            'سجّل اختبار الاستعادة',
        ],
        'reinstall_the_shipped_rules' => [
            'Reinstall the shipped rules',
            'أعد تثبيت القواعد المرفقة',
        ],
        'release_that_caused_it' => [
            'Release that caused it',
            'الإصدارة المسبّبة',
        ],
        'remove' => [
            'Remove',
            'إزالة',
        ],
        'request_context' => [
            'Request context',
            'سياق الطلب',
        ],
        'request_id' => [
            'Request id',
            'معرّف الطلب',
        ],
        'restored_to_staging_in_four_minutes' => [
            'Restored to staging in four minutes',
            'استُعيدت إلى بيئة الاختبار في أربع دقائق',
        ],
        'save_settings' => [
            'Save settings',
            'حفظ الإعدادات',
        ],
        'setting' => [
            'Setting',
            'الإعداد',
        ],
        'silence' => [
            'Silence',
            'إسكات',
        ],
        'size' => [
            'Size',
            'الحجم',
        ],
        'size_in_bytes' => [
            'Size in bytes',
            'الحجم بالبايت',
        ],
        'slow_above_ms' => [
            'Slow above (ms)',
            'بطيء فوق (مللي ثانية)',
        ],
        'some_settings_were_refused' => [
            'Some settings were refused',
            'رُفضت بعض الإعدادات',
        ],
        'span' => [
            'Span',
            'المقطع',
        ],
        'stack_trace' => [
            'Stack trace',
            'أثر الاستدعاء',
        ],
        'start_offset_ms' => [
            'Offset (ms)',
            'الإزاحة (مللي ثانية)',
        ],
        'started_at' => [
            'Started at',
            'بدأت في',
        ],
        'stop_probing_this_journey' => [
            'Stop probing this journey',
            'أوقف فحص هذه الرحلة',
        ],
        'stored_overrides_could_not_be_read' => [
            'Stored overrides could not be read',
            'تعذّرت قراءة التجاوزات المخزّنة',
        ],
        'supplier_import_started' => [
            'Supplier import started',
            'بدأ استيراد المورّد',
        ],
        'switched_off' => [
            'Switched off',
            'مُطفأ',
        ],
        'that_probe_is_no_longer_configured' => [
            'That probe is no longer configured.',
            'لم يعد هذا الفحص مضبوطاً.',
        ],
        'the_alert_engine_has_never_run_here' => [
            'The alert engine has never run here',
            'لم يعمل محرّك التنبيهات هنا قط',
        ],
        'the_alert_engine_is_not_evaluating_anything' => [
            'The alert engine is not evaluating anything',
            'محرّك التنبيهات لا يقيّم شيئاً',
        ],
        'the_alert_history_could_not_be_read' => [
            'The alert history could not be read',
            'تعذّرت قراءة سجل التنبيهات',
        ],
        'the_backup_could_not_be_recorded' => [
            'The backup could not be recorded',
            'تعذّر تسجيل النسخة الاحتياطية',
        ],
        'the_backup_was_recorded' => [
            'The backup was recorded.',
            'تم تسجيل النسخة الاحتياطية.',
        ],
        'the_deployment_could_not_be_recorded' => [
            'The deployment could not be recorded',
            'تعذّر تسجيل النشر',
        ],
        'the_deployment_was_recorded' => [
            'The deployment was recorded.',
            'تم تسجيل النشر.',
        ],
        'the_incident_was_updated' => [
            'The incident was updated.',
            'تم تحديث الحادثة.',
        ],
        'the_monitoring_settings_were_saved' => [
            'The monitoring settings were saved.',
            'تم حفظ إعدادات المراقبة.',
        ],
        'the_origin_of_some_values_cannot_be_told_apart_on_this_deployment' => [
            'The origin of some values cannot be told apart on this deployment',
            'لا يمكن تمييز مصدر بعض القيم في هذه البيئة',
        ],
        'the_probe_was_added' => [
            'The probe was added.',
            'تمت إضافة الفحص.',
        ],
        'the_probe_was_removed' => [
            'The probe was removed.',
            'تمت إزالة الفحص.',
        ],
        'the_request' => [
            'The request',
            'الطلب',
        ],
        'the_restore_did_not_work' => [
            'The restore did not work',
            'لم تنجح الاستعادة',
        ],
        'the_restore_test_was_recorded' => [
            'The restore test was recorded.',
            'تم تسجيل اختبار الاستعادة.',
        ],
        'the_self_health_block_could_not_be_read' => [
            'The self-health block could not be read',
            'تعذّرت قراءة كتلة صحة المراقبة نفسها',
        ],
        'the_settings_below_are_still_exact_only_monitoring_own_state_is_missing' => [
            'The settings below are still exact; only monitoring’s own state is missing',
            'الإعدادات أدناه دقيقة؛ الناقص فقط حالة المراقبة نفسها',
        ],
        'the_settings_table_holds_more_rows_than_this_page_reads_so_the_list_above_is_incomplete' => [
            'The settings table holds more rows than this page reads, so the list above is incomplete',
            'جدول الإعدادات يحوي صفوفاً أكثر مما تقرأه هذه الصفحة، فالقائمة أعلاه غير مكتملة',
        ],
        'the_shop_default_address_if_left_empty' => [
            'The shop default address if left empty',
            'عنوان المتجر الافتراضي إن تُرك فارغاً',
        ],
        'the_storage_footprint_could_not_be_read' => [
            'The storage footprint could not be read',
            'تعذّرت قراءة حجم التخزين',
        ],
        'there_are_already_as_many_probes_as_one_run_will_fetch_remove_one_first' => [
            'There are already as many probes as one run will fetch. Remove one first.',
            'عدد الفحوصات وصل إلى ما تجلبه الجولة الواحدة. أزل واحداً أولاً.',
        ],
        'these_enabled_rules_watch_a_metric_that_has_not_been_recorded_in_the_last_two_days_so_they_cannot_fire' => [
            'These enabled rules watch a metric that has not been recorded in the last two days, so they cannot fire',
            'هذه القواعد المفعّلة تراقب مقياساً لم يُسجَّل خلال آخر يومين، فلا يمكنها الإطلاق',
        ],
        'this_part_could_not_be_read' => [
            'This part could not be read',
            'تعذّرت قراءة هذا الجزء',
        ],
        'this_records_that_a_backup_happened_it_does_not_take_one' => [
            'This records that a backup happened; it does not take one',
            'هذا يسجّل أن نسخة احتياطية جرت؛ وهو لا ينشئ واحدة',
        ],
        'took' => [
            'Took',
            'استغرق',
        ],
        'traces_carry_a_correlation_id_rather_than_a_request_id_so_a_request_that_did_not_fail_has_no_join_to_its_trace' => [
            'Traces carry a correlation id rather than a request id, so a request that did not fail has no join to its trace',
            'تحمل الآثار معرّف ارتباط بدل معرّف الطلب، فالطلب الذي لم يفشل لا رابط له بأثره',
        ],
        'url' => [
            'URL',
            'الرابط',
        ],
        'value' => [
            'Value',
            'القيمة',
        ],
        'warning' => [
            'Warning',
            'تحذير',
        ],
        'what_the_test_found' => [
            'What the test found',
            'ما وجده الاختبار',
        ],
        'what_was_tried_and_what_it_did' => [
            'What was tried, and what it did',
            'ما جُرّب، وما أدّى إليه',
        ],
        'when_if_not_now' => [
            'When, if not now',
            'متى، إن لم يكن الآن',
        ],
        'where_it_was_written' => [
            'Where it was written',
            'أين كُتبت',
        ],
        'info' => [
            'Info',
            'معلومة',
        ],
        'success' => [
            'Success',
            'نجاح',
        ],

        // ── admin · the platform policy registry ──────────────────────────
        'a_floor_on_top_of_the_category_return_window_zero_leaves_the_return_window_alone' => [
            'A floor on top of the category return window — zero leaves the return window alone',
            'حدّ أدنى فوق نافذة إرجاع الفئة — القيمة صفر تترك نافذة الإرجاع كما هي',
        ],
        'days_of_cover_below_which_a_restock_is_raised_in_the_briefing' => [
            'Days of cover below which a restock is raised in the briefing',
            'أيام التغطية التي يُرفع دونها تنبيه إعادة التخزين في الملخّص',
        ],
        'days_of_cover_below_which_a_restock_is_offered_as_an_opportunity' => [
            'Days of cover below which a restock is offered as an opportunity',
            'أيام التغطية التي يُعرض دونها إعادة التخزين كفرصة',
        ],
        'every_cover_figure_on_every_screen_is_measured_over_this_window' => [
            'Every cover figure on every screen is measured over this window',
            'كل أرقام التغطية في كل الشاشات تُقاس على هذه النافذة',
        ],
        'access_policy' => [
            'Access policy',
            'سياسة الوصول',
        ],
        'api_requests_allowed_per_minute_per_client' => [
            'API requests allowed per minute, per client',
            'عدد طلبات الواجهة البرمجية المسموحة في الدقيقة لكل عميل',
        ],
        'applies_to_every_sign_up_reset_and_staff_account_across_web_and_api' => [
            'Applies to every sign-up, reset and staff account, on the web and over the API',
            'تُطبَّق على كل تسجيل واستعادة وحساب موظف، على الويب وعبر الواجهة البرمجية',
        ],
        'audit_rows_a_seller_may_page_back_through' => [
            'Audit rows a seller may page back through',
            'عدد سجلات التدقيق التي يمكن للبائع تصفّحها للخلف',
        ],
        'automation_rules_evaluated_in_one_sweep' => [
            'Automation rules evaluated in one sweep',
            'عدد قواعد الأتمتة التي تُقيَّم في الجولة الواحدة',
        ],
        'boosted_products_per_collection' => [
            'Boosted products per collection',
            'عدد المنتجات المعزّزة في المجموعة',
        ],
        'cancellation_rate_that_puts_a_seller_at_risk' => [
            'Cancellation rate that puts a seller at risk',
            'نسبة الإلغاء التي تضع البائع في دائرة الخطر',
        ],
        'cancellation_rate_that_puts_a_seller_on_watch' => [
            'Cancellation rate that puts a seller on watch',
            'نسبة الإلغاء التي تضع البائع تحت المراقبة',
        ],
        'catalogue_policy' => [
            'Catalogue policy',
            'سياسة الكتالوج',
        ],
        'days_a_sellers_reconciliation_looks_back_by_default' => [
            'Days a seller’s reconciliation looks back by default',
            'عدد الأيام التي تعود إليها تسوية البائع افتراضياً',
        ],
        'days_an_earning_is_held_before_it_becomes_available' => [
            'Days an earning is held before it becomes available',
            'عدد أيام حجز الأرباح قبل أن تصبح متاحة',
        ],
        'days_of_cover_below_which_stock_is_called_critical' => [
            'Days of cover below which stock is called critical',
            'أيام التغطية التي يُعتبر المخزون دونها حرجاً',
        ],
        'days_of_cover_below_which_stock_is_called_low' => [
            'Days of cover below which stock is called low',
            'أيام التغطية التي يُعتبر المخزون دونها منخفضاً',
        ],
        'days_of_notice_before_a_verification_document_expires' => [
            'Days of notice before a verification document expires',
            'عدد أيام التنبيه قبل انتهاء صلاحية وثيقة التوثيق',
        ],
        'days_of_sales_used_to_work_out_how_fast_stock_moves' => [
            'Days of sales used to work out how fast stock moves',
            'عدد أيام المبيعات المستخدمة لحساب سرعة حركة المخزون',
        ],
        'days_unsold_before_stock_is_called_dead_capital' => [
            'Days unsold before stock is called dead capital',
            'عدد أيام عدم البيع قبل اعتبار المخزون رأس مال راكد',
        ],
        'delivery_attempts_before_a_webhook_is_given_up_on' => [
            'Delivery attempts before a webhook is given up on',
            'عدد محاولات التسليم قبل التخلي عن الويب هوك',
        ],
        'excluded_products_per_collection' => [
            'Excluded products per collection',
            'عدد المنتجات المستبعدة في المجموعة',
        ],
        'fulfilment_policy' => [
            'Fulfilment policy',
            'سياسة التنفيذ والشحن',
        ],
        'highest_boost_weight_allowed' => [
            'Highest boost weight allowed',
            'أعلى وزن تعزيز مسموح',
        ],
        'hours_a_price_change_is_watched_for' => [
            'Hours a price change is watched for',
            'عدد ساعات مراقبة تغيّر السعر',
        ],
        'hours_of_courier_silence_before_a_shipment_is_raised' => [
            'Hours of courier silence before a shipment is raised',
            'ساعات صمت شركة الشحن قبل رفع تنبيه بالشحنة',
        ],
        'hours_payouts_are_frozen_after_a_seller_changes_their_bank_details' => [
            'Hours payouts are frozen after a seller changes their bank details',
            'ساعات تجميد الدفعات بعد تغيير البائع لبياناته البنكية',
        ],
        'how_deep_a_fallback_chain_may_go' => [
            'How deep a fallback chain may go',
            'عمق سلسلة البدائل المسموح',
        ],
        'how_hard_the_platform_tries_before_a_sellers_event_is_lost' => [
            'How hard the platform tries before a seller’s event is lost',
            'مدى إصرار المنصة قبل ضياع حدث البائع',
        ],
        'how_large_a_curated_collection_campaign_or_experiment_may_get' => [
            'How large a curated collection, campaign or experiment may get',
            'الحجم الأقصى للمجموعة المنسّقة أو الحملة أو التجربة',
        ],
        'how_long_a_parcel_may_go_without_courier_movement_before_it_is_an_exception' => [
            'How long a parcel may go without courier movement before it is an exception',
            'المدة التي تبقى فيها الشحنة بلا حركة قبل اعتبارها استثناء',
        ],
        'how_much_the_platform_looks_at_in_one_pass_raise_these_as_the_marketplace_grows' => [
            'How much the platform looks at in one pass — raise these as the marketplace grows',
            'حجم ما تفحصه المنصة في المرور الواحد — ارفعها مع نمو السوق',
        ],
        'listing_score_below_which_a_product_is_raised_for_improvement' => [
            'Listing score below which a product is raised for improvement',
            'درجة الإدراج التي يُرفع المنتج دونها للتحسين',
        ],
        'merchandising_limits' => [
            'Merchandising limits',
            'حدود العرض والتنسيق',
        ],
        'minutes_before_the_first_retry_doubling_each_attempt' => [
            'Minutes before the first retry, doubling each attempt',
            'الدقائق قبل أول إعادة محاولة، وتتضاعف مع كل محاولة',
        ],
        'one_definition_of_low_stock_read_by_the_briefing_the_inventory_screen_and_the_opportunity_cards' => [
            'One definition of low stock, read by the briefing, the inventory screen and the opportunity cards',
            'تعريف واحد للمخزون المنخفض، يقرأه الملخّص اليومي وشاشة المخزون وبطاقات الفرص',
        ],
        'one_password_rule_and_one_brute_force_tolerance_for_every_surface' => [
            'One password rule and one brute-force tolerance for every surface',
            'قاعدة كلمة مرور واحدة وحدّ محاولات واحد لكل الواجهات',
        ],
        'open_issues_and_deadlines_read_per_control_tower_load' => [
            'Open issues and deadlines read per Control Tower load',
            'عدد المشكلات والمواعيد المقروءة عند فتح مركز التحكم',
        ],
        'overrides_per_campaign' => [
            'Overrides per campaign',
            'عدد التجاوزات في الحملة',
        ],
        'payment_terms' => [
            'Payment terms',
            'شروط الدفع',
        ],
        'payout_amount_above_which_a_second_approver_is_required' => [
            'Payout amount above which a second approver is required',
            'قيمة الدفعة التي تستوجب موافقاً ثانياً',
        ],
        'pinned_products_per_collection' => [
            'Pinned products per collection',
            'عدد المنتجات المثبّتة في المجموعة',
        ],
        'rating_below_which_a_seller_is_at_risk' => [
            'Rating below which a seller is at risk',
            'التقييم الذي يصبح البائع دونه في دائرة الخطر',
        ],
        'rating_below_which_a_seller_is_on_watch' => [
            'Rating below which a seller is on watch',
            'التقييم الذي يصبح البائع دونه تحت المراقبة',
        ],
        'refund_rate_that_puts_a_seller_at_risk' => [
            'Refund rate that puts a seller at risk',
            'نسبة الاسترداد التي تضع البائع في دائرة الخطر',
        ],
        'refund_rate_that_puts_a_seller_on_watch' => [
            'Refund rate that puts a seller on watch',
            'نسبة الاسترداد التي تضع البائع تحت المراقبة',
        ],
        'return_rate_that_puts_a_seller_at_risk' => [
            'Return rate that puts a seller at risk',
            'نسبة الإرجاع التي تضع البائع في دائرة الخطر',
        ],
        'return_rate_that_puts_a_seller_on_watch' => [
            'Return rate that puts a seller on watch',
            'نسبة الإرجاع التي تضع البائع تحت المراقبة',
        ],
        'rules_per_collection' => [
            'Rules per collection',
            'عدد القواعد في المجموعة',
        ],
        'rules_per_segment' => [
            'Rules per segment',
            'عدد القواعد في الشريحة',
        ],
        'seconds_to_wait_for_the_receiving_endpoint' => [
            'Seconds to wait for the receiving endpoint',
            'عدد الثواني لانتظار نقطة الاستقبال',
        ],
        'seller_standing' => [
            'Seller standing',
            'وضع البائع',
        ],
        'sellers_included_in_the_admin_issue_rollup' => [
            'Sellers included in the admin issue rollup',
            'عدد البائعين في تجميعة المشكلات لدى الإدارة',
        ],
        'share_of_the_previous_price_a_change_must_exceed_to_be_called_extreme' => [
            'Share of the previous price a change must exceed to be called extreme',
            'نسبة من السعر السابق يجب أن يتجاوزها التغيير ليُعتبر متطرفاً',
        ],
        'shortest_password_the_platform_accepts' => [
            'Shortest password the platform accepts',
            'أقصر كلمة مرور تقبلها المنصة',
        ],
        'sign_in_attempts_allowed_per_minute' => [
            'Sign-in attempts allowed per minute',
            'عدد محاولات تسجيل الدخول المسموحة في الدقيقة',
        ],
        'smallest_balance_a_seller_may_request_a_payout_for' => [
            'Smallest balance a seller may request a payout for',
            'أصغر رصيد يمكن للبائع طلب صرفه',
        ],
        'stock_policy' => [
            'Stock policy',
            'سياسة المخزون',
        ],
        'stop_raising_a_silent_shipment_after_days' => [
            'Stop raising a silent shipment after (days)',
            'التوقف عن رفع الشحنة الصامتة بعد (أيام)',
        ],
        'strikes_that_put_a_seller_at_risk' => [
            'Strikes that put a seller at risk',
            'عدد المخالفات التي تضع البائع في دائرة الخطر',
        ],
        'strikes_that_put_a_seller_on_watch' => [
            'Strikes that put a seller on watch',
            'عدد المخالفات التي تضع البائع تحت المراقبة',
        ],
        'sweep_and_page_limits' => [
            'Sweep and page limits',
            'حدود المسح والصفحات',
        ],
        'the_notice_a_seller_gets_before_a_document_expires_and_the_bands_that_label_their_account' => [
            'The notice a seller gets before a document expires, and the bands that label their account',
            'التنبيه الذي يصل البائع قبل انتهاء وثيقة، والنطاقات التي تصنّف حسابه',
        ],
        'the_quality_bar_a_listing_must_clear_and_the_limits_a_merchandiser_works_within' => [
            'The quality bar a listing must clear, and the limits a merchandiser works within',
            'معيار الجودة الذي يجب أن يجتازه الإدراج، والحدود التي يعمل ضمنها منسّق العرض',
        ],
        'units_on_hand_before_unsold_stock_is_worth_raising' => [
            'Units on hand before unsold stock is worth raising',
            'عدد الوحدات المتوفرة قبل أن يستحق المخزون الراكد التنبيه',
        ],
        'variants_per_storefront_experiment' => [
            'Variants per storefront experiment',
            'عدد المتغيرات في تجربة الواجهة',
        ],
        'webhook_delivery' => [
            'Webhook delivery',
            'تسليم الويب هوك',
        ],
        'what_the_marketplace_promises_its_sellers_about_when_they_are_paid' => [
            'What the marketplace promises its sellers about when they are paid',
            'ما تعد به المنصة بائعيها بشأن موعد الدفع',
        ],
        'zero_switches_the_second_approver_off' => [
            'Zero switches the second approver off',
            'القيمة صفر تُلغي اشتراط الموافق الثاني',
        ],
        'platform_policies' => [
            'Platform policies',
            'سياسات المنصة',
        ],
        'Platform_Policies' => [
            'Platform Policies',
            'سياسات المنصة',
        ],
        'the_rules_the_platform_applies_to_itself_every_one_of_them_settable_bounded_and_audited' => [
            'The rules the platform applies to itself — every one of them settable, bounded and audited',
            'القواعد التي تطبّقها المنصة على نفسها — كلها قابلة للضبط ومحدودة ومُدقَّقة',
        ],
        'the_rules_the_platform_applies_to_itself_thresholds_limits_and_windows' => [
            'The rules the platform applies to itself: thresholds, limits and windows',
            'القواعد التي تطبّقها المنصة على نفسها: العتبات والحدود والنوافذ',
        ],
        'the_policy_was_updated' => [
            'The policy was updated.',
            'تم تحديث السياسة.',
        ],
        'nothing_changed' => [
            'Nothing changed.',
            'لم يتغيّر شيء.',
        ],
        'allowed' => [
            'Allowed',
            'المسموح',
        ],
        // Telemetry feeds — the machine-readable surfaces the Developer Portal now documents.
        'telemetry_feeds' => [
            'Telemetry Feeds',
            'تدفّقات القياس',
        ],
        'machine_readable_monitoring_metrics_and_traces' => [
            'Machine-readable monitoring, metrics and traces',
            'مراقبة ومقاييس وتتبّعات بصيغة تقرأها الأنظمة',
        ],
        'machine_readable_feeds' => [
            'Machine-readable feeds',
            'التدفّقات التي تقرأها الأنظمة',
        ],
        'endpoints_for_collectors_rather_than_for_people' => [
            'Endpoints meant for collectors rather than for people. None of them sit under the api/ prefix, so no generated document finds them on its own.',
            'نقاط نهاية مُعدّة للأنظمة الجامعة لا للبشر. لا يقع أي منها تحت البادئة api/، لذا لا يعثر عليها أي مستند مُولَّد تلقائيًا.',
        ],
        'monitoring_json' => [
            'Monitoring sections as JSON',
            'أقسام المراقبة بصيغة JSON',
        ],
        'the_same_payload_the_page_renders_so_the_two_can_never_disagree' => [
            'The same payload the page renders, so the feed and the screen can never disagree.',
            'نفس البيانات التي تعرضها الصفحة، فلا يمكن أن يختلف التدفّق عن الشاشة.',
        ],
        'admin_session_and_the_sections_own_permission' => [
            'Admin session, plus the section\'s own permission',
            'جلسة المشرف، إضافةً إلى صلاحية القسم نفسه',
        ],
        'prometheus' => [
            'Prometheus scrape',
            'استخلاص Prometheus',
        ],
        'bearer_token_from_monitoring_prometheus_token' => [
            'Bearer token from MONITORING_PROMETHEUS_TOKEN',
            'رمز Bearer من MONITORING_PROMETHEUS_TOKEN',
        ],
        'gauges_for_the_last_complete_minute_never_labelled_by_route_or_id' => [
            'Gauges for the last complete minute, never labelled by route or id.',
            'مؤشّرات للدقيقة المكتملة الأخيرة، دون أي وسم بالمسار أو المعرّف.',
        ],
        'set_monitoring_prometheus_true_and_a_monitoring_prometheus_token' => [
            'Set MONITORING_PROMETHEUS=true and a MONITORING_PROMETHEUS_TOKEN to switch it on.',
            'اضبط MONITORING_PROMETHEUS=true مع MONITORING_PROMETHEUS_TOKEN لتشغيله.',
        ],
        'otlp_traces' => [
            'OTLP trace export',
            'تصدير التتبّعات بصيغة OTLP',
        ],
        'whatever_otel_exporter_otlp_headers_carries' => [
            'Whatever OTEL_EXPORTER_OTLP_HEADERS carries',
            'ما يحمله OTEL_EXPORTER_OTLP_HEADERS',
        ],
        'outbound_this_shop_posts_finished_traces_to_your_collector' => [
            'Outbound: this shop posts finished traces to your collector.',
            'صادر: يُرسل هذا المتجر التتبّعات المنتهية إلى نظامك الجامع.',
        ],
        'set_otel_exporter_otlp_endpoint_to_your_collector' => [
            'Set OTEL_EXPORTER_OTLP_ENDPOINT to your collector to switch it on.',
            'اضبط OTEL_EXPORTER_OTLP_ENDPOINT على نظامك الجامع لتشغيله.',
        ],
        'monitoring_sections_available_as_json' => [
            'Monitoring sections available as JSON',
            'أقسام المراقبة المتاحة بصيغة JSON',
        ],
        'append_json_1_to_any_monitoring_section_url' => [
            'Append ?json=1 to any monitoring section URL, or send Accept: application/json.',
            'أضف ‎?json=1‎ إلى رابط أي قسم مراقبة، أو أرسل Accept: application/json.',
        ],
        'feed' => [
            'Feed',
            'التدفّق',
        ],
        'format' => [
            'Format',
            'الصيغة',
        ],
        'preview_the_test_mail' => [
            'Preview the test mail',
            'معاينة بريد الاختبار',
        ],
        // The transactional delivery log.
        'notification_delivery_log' => [
            'Notification Delivery Log',
            'سجل تسليم الإشعارات',
        ],
        'delivery_log' => [
            'Delivery Log',
            'سجل التسليم',
        ],
        'every_transactional_email_sms_and_push_this_shop_tried_to_send' => [
            'Every transactional email, SMS and push this shop tried to send — and whether it arrived',
            'كل بريد ورسالة نصية وإشعار معاملات حاول المتجر إرساله — وهل وصل أم لا',
        ],
        'delivered' => [
            'Delivered',
            'تم التسليم',
        ],
        'not_confirmed' => [
            'Not confirmed',
            'غير مؤكّد',
        ],
        'last_24_hours' => [
            'Last 24 hours',
            'آخر ٢٤ ساعة',
        ],
        'an_email_address_or_a_phone_number' => [
            'An email address or a phone number',
            'بريد إلكتروني أو رقم هاتف',
        ],
        'send_again' => [
            'Send again',
            'إرسال مرة أخرى',
        ],
        'resent_from' => [
            'Resent from',
            'أُعيد إرساله من',
        ],
        'no_message_has_been_sent_yet' => [
            'No message has been sent yet.',
            'لم تُرسل أي رسالة بعد.',
        ],
        'the_message_was_sent_again' => [
            'The message was sent again.',
            'تم إرسال الرسالة مرة أخرى.',
        ],
        'the_message_could_not_be_sent' => [
            'The message could not be sent.',
            'تعذّر إرسال الرسالة.',
        ],
        'a_one_time_code_cannot_be_sent_again' => [
            'A one-time code cannot be sent again',
            'لا يمكن إعادة إرسال رمز لمرة واحدة',
        ],
        'an_sms_carries_a_one_time_code_that_has_already_expired' => [
            'An SMS carries a one-time code that has already expired, so sending it again would deliver a secret that no longer works.',
            'تحمل الرسالة النصية رمزًا لمرة واحدة انتهت صلاحيته، فإعادة إرساله تُسلّم رمزًا لم يعد يعمل.',
        ],
        'this_record_has_no_recipient_to_send_to' => [
            'This record has no recipient to send to.',
            'لا يحتوي هذا السجل على مستلم لإرساله إليه.',
        ],
        'this_message_was_not_stored_in_full_so_it_cannot_be_sent_again' => [
            'This message was not stored in full, so it cannot be sent again.',
            'لم تُحفظ هذه الرسالة كاملة، لذا لا يمكن إعادة إرسالها.',
        ],
        'this_push_has_no_stored_payload_to_send_again' => [
            'This push has no stored payload to send again.',
            'لا يوجد محتوى محفوظ لهذا الإشعار لإعادة إرساله.',
        ],
        'days_of_transactional_message_history_to_keep' => [
            'Days of transactional message history to keep',
            'عدد أيام الاحتفاظ بسجل رسائل المعاملات',
        ],
        'the_delivery_log_is_a_support_aid_with_a_shelf_life_not_an_archive_of_what_was_said_to_customers' => [
            'The delivery log is a support aid with a shelf life, not a permanent archive of what was said to customers.',
            'سجل التسليم أداة دعم لها مدة صلاحية، وليس أرشيفًا دائمًا لما قيل للعملاء.',
        ],
        'minutes_before_an_unconfirmed_message_counts_as_failed' => [
            'Minutes before an unconfirmed message counts as failed',
            'عدد الدقائق قبل اعتبار الرسالة غير المؤكّدة فاشلة',
        ],
        'a_send_the_transport_never_came_back_about_reads_as_still_going_until_this_elapses' => [
            'A send the transport never came back about reads as "still going" until this elapses.',
            'الإرسال الذي لم يعد منه ناقل الرسائل بجواب يُقرأ على أنه «قيد التنفيذ» حتى انقضاء هذه المدة.',
        ],
        // Blast radius — how many sellers a failure is reaching.
        'sellers_affected' => [
            'Sellers affected',
            'البائعون المتأثرون',
        ],
        'occurrences_on_a_signed_in_seller' => [
            'occurrences on a signed-in seller',
            'حالة وقعت لبائع مسجّل الدخول',
        ],
        'not_measured' => [
            'Not measured',
            'غير مقيس',
        ],
        'what_this_figure_cannot_see' => [
            'What this figure cannot see',
            'ما لا يستطيع هذا الرقم رؤيته',
        ],
        'the_blast_radius_could_not_be_read' => [
            'The blast radius could not be read',
            'تعذّرت قراءة نطاق التأثير',
        ],
        'request_buckets_are_keyed_by_route_pattern_so_they_stay_bounded_as_the_marketplace_grows' => [
            'Request counters are keyed by route pattern so they stay bounded as the marketplace grows, so traffic cannot be attributed to a seller.',
            'تُفهرَس عدّادات الطلبات بنمط المسار كي تبقى محدودة مع نمو السوق، لذا لا يمكن نسب حركة المرور إلى بائع بعينه.',
        ],
        'a_queued_job_records_its_queue_and_class_not_whose_work_it_was' => [
            'A queued job records its queue and its class, not whose work it was.',
            'تُسجّل المهمة في الطابور اسم الطابور وصنفها، لا صاحب العمل الذي تخصّه.',
        ],
        'an_outbound_call_is_attributed_to_the_service_it_reached_not_to_a_seller' => [
            'An outbound call is attributed to the service it reached, not to a seller.',
            'يُنسب النداء الصادر إلى الخدمة التي وصل إليها، لا إلى بائع.',
        ],
        'requests' => [
            'Requests',
            'الطلبات',
        ],
        'queues' => [
            'Queues',
            'الطوابير',
        ],
        'dependencies' => [
            'Dependencies',
            'الاعتماديات',
        ],
        // Order state policy and the checkout item floor.
        'order_states_that_can_still_be_edited' => [
            'Order states that can still be edited',
            'حالات الطلب التي ما زال يمكن تعديلها',
        ],
        'editing_rebuilds_the_order_lines_and_the_stock_behind_them_so_states_past_dispatch_are_normally_left_out' => [
            'Editing rebuilds the order lines and the stock behind them, so states past dispatch are normally left out.',
            'يعيد التعديل بناء بنود الطلب والمخزون خلفها، لذا تُستبعد عادةً الحالات التي تلي الإرسال.',
        ],
        'order_states_a_customer_may_cancel_from' => [
            'Order states a customer may cancel from',
            'حالات الطلب التي يمكن للعميل الإلغاء منها',
        ],
        'payment_rules_still_apply_on_top_money_already_taken_is_never_undone_by_this_button' => [
            'Payment rules still apply on top: money already taken is never undone by this button.',
            'تبقى قواعد الدفع سارية فوق ذلك: لا يُلغى المال المحصَّل مسبقًا بهذا الزر.',
        ],
        'this_order_can_no_longer_be_edited_in_its_current_status' => [
            'This order can no longer be edited in its current status.',
            'لم يعد بالإمكان تعديل هذا الطلب في حالته الحالية.',
        ],
        'Minimum_Items_Per_Order' => [
            'Minimum Items Per Order',
            'الحد الأدنى لعدد الأصناف في الطلب',
        ],
        'the_fewest_items_a_customer_may_check_out_with_zero_means_no_limit' => [
            'The fewest items a customer may check out with. Zero means no limit.',
            'أقل عدد أصناف يمكن للعميل إتمام الشراء به. الصفر يعني بلا حد.',
        ],
        'this_limit_is_enforced_by_the_mobile_apps_the_web_checkout_does_not_read_it' => [
            'This limit is enforced by the mobile apps; the web checkout does not read it.',
            'تطبّق تطبيقات الجوال هذا الحد؛ أما إتمام الشراء عبر الويب فلا يقرأه.',
        ],
        // Gateway callback receipts.
        'gateway_callbacks_received' => [
            'Gateway callbacks received',
            'استدعاءات بوابات الدفع الواردة',
        ],
        'acted_on_by_nothing' => [
            'Acted on by nothing',
            'لم يُتّخذ عليها أي إجراء',
        ],
        'last_callback' => [
            'Last callback',
            'آخر استدعاء',
        ],
        'no_gateway_callback_landed_in_this_window' => [
            'No gateway callback landed in this window',
            'لم يصل أي استدعاء من بوابة دفع في هذه الفترة',
        ],
        'a_shop_that_took_a_card_payment_in_this_window_and_has_no_row_here_has_a_callback_that_never_arrived' => [
            'A shop that took a card payment in this window and has no row here has a callback that never arrived.',
            'المتجر الذي تلقّى دفعة ببطاقة في هذه الفترة ولا يوجد له سجل هنا لديه استدعاء لم يصل أبدًا.',
        ],
        'succeeded' => [
            'Succeeded',
            'نجحت',
        ],
        // The daily request history, from the rollup nothing was reading.
        'daily_history' => [
            'Daily History',
            'السجل اليومي',
        ],
        'web_requests' => [
            'Web requests',
            'طلبات الويب',
        ],
        'api_requests' => [
            'API requests',
            'طلبات الواجهة البرمجية',
        ],
        'server_errors' => [
            'Server errors',
            'أخطاء الخادم',
        ],
        'average_response_time' => [
            'Average response time',
            'متوسط زمن الاستجابة',
        ],
        'no_daily_history_has_been_rolled_up_yet' => [
            'No daily history has been rolled up yet',
            'لم يُجمَّع أي سجل يومي بعد',
        ],
        'telemetry_rollup_writes_one_row_per_day_per_channel_the_first_appears_after_its_next_run' => [
            'The telemetry rollup writes one row per day per channel; the first appears after its next run.',
            'يكتب تجميع القياسات صفًا واحدًا لكل يوم ولكل قناة؛ يظهر الأول بعد التشغيل التالي.',
        ],
        // Order attributes — the facts every order carried and nothing reported.
        'what_each_order_looked_like' => [
            'What Each Order Looked Like',
            'كيف بدا كل طلب',
        ],
        'orders_with_a_coupon' => [
            'Orders with a coupon',
            'الطلبات التي استخدمت قسيمة',
        ],
        'guest_orders' => [
            'Guest orders',
            'طلبات الزوار',
        ],
        'average_shipping_cost' => [
            'Average shipping cost',
            'متوسط تكلفة الشحن',
        ],
        'share' => [
            'Share',
            'الحصة',
        ],
        'read_from_the_most_recent_orders_in_this_window_not_all_of_them' => [
            'Read from the most recent orders in this window, not from all of them.',
            'مقروء من أحدث الطلبات في هذه الفترة، لا من جميعها.',
        ],
        'no_orders_in_this_window' => [
            'No orders in this window',
            'لا توجد طلبات في هذه الفترة',
        ],
        'order_attributes_are_read_from_the_events_recorded_when_an_order_is_placed' => [
            'Order attributes are read from the events recorded when an order is placed.',
            'تُقرأ خصائص الطلب من الأحداث المسجّلة لحظة إنشائه.',
        ],
        // Feature flags.
        'feature_flags' => [
            'Feature Flags',
            'مفاتيح المزايا',
        ],
        'Feature_Flags' => [
            'Feature Flags',
            'مفاتيح المزايا',
        ],
        'turn_a_change_on_for_some_of_the_marketplace_before_all_of_it' => [
            'Turn a change on for some of the marketplace before all of it',
            'شغّل تغييرًا لجزء من السوق قبل تعميمه على الجميع',
        ],
        'add_or_update_a_flag' => [
            'Add or update a flag',
            'إضافة مفتاح أو تحديثه',
        ],
        'flag_key' => [
            'Flag key',
            'مفتاح المزية',
        ],
        'this_must_match_exactly_what_the_code_asks_for' => [
            'This must match exactly what the code asks for.',
            'يجب أن يطابق تمامًا ما تطلبه الشيفرة.',
        ],
        'rollout_percentage' => [
            'Rollout percentage',
            'نسبة الطرح',
        ],
        'always_on_for_these_sellers' => [
            'Always on for these sellers',
            'مُفعّل دائمًا لهؤلاء البائعين',
        ],
        'the_pilot_group_these_shops_are_in_whatever_the_percentage_says' => [
            'The pilot group: these shops are in whatever the percentage says.',
            'المجموعة التجريبية: هذه المتاجر مشمولة مهما كانت النسبة.',
        ],
        'switched_on' => [
            'Switched on',
            'مُفعّل',
        ],
        'off_means_off_for_everyone_including_the_pilot_group' => [
            'Off means off for everyone, including the pilot group.',
            'الإيقاف يعني الإيقاف للجميع، بمن فيهم المجموعة التجريبية.',
        ],
        'flags_on_this_installation' => [
            'Flags on this installation',
            'المفاتيح على هذا التركيب',
        ],
        'no_flag_has_been_created_yet_a_flag_that_does_not_exist_is_off' => [
            'No flag has been created yet. A flag that does not exist is off',
            'لم يُنشأ أي مفتاح بعد. المفتاح غير الموجود يُعدّ مُطفأً',
        ],
        'rollout' => [
            'Rollout',
            'الطرح',
        ],
        'pilot_group' => [
            'Pilot group',
            'المجموعة التجريبية',
        ],
        'the_flag_was_saved' => [
            'The flag was saved.',
            'تم حفظ المفتاح.',
        ],
        'the_flag_was_removed' => [
            'The flag was removed.',
            'تمت إزالة المفتاح.',
        ],
        'that_flag_does_not_exist' => [
            'That flag does not exist.',
            'هذا المفتاح غير موجود.',
        ],
        'a_flag_key_is_lowercase_letters_numbers_dots_dashes_and_underscores' => [
            'A flag key is lowercase letters, numbers, dots, dashes and underscores.',
            'مفتاح المزية يتكوّن من أحرف صغيرة وأرقام ونقاط وشرطات وشرطات سفلية.',
        ],
        'the_feature_flag_table_has_not_been_created_on_this_installation' => [
            'The feature flag table has not been created on this installation.',
            'لم يُنشأ جدول مفاتيح المزايا على هذا التركيب.',
        ],
    ];
}
