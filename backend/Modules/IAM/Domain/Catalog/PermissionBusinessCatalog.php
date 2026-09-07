<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Catalog;

/**
 * Business-facing presentation metadata for the CANONICAL permission catalog
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §13).
 *
 * This class mints NOTHING. It never writes to `permissions`, never invents a token and is
 * never consulted by an authorization decision. It is a pure, side-effect-free presentation
 * dictionary: given a canonical permission name that already exists in the `permissions`
 * table, it returns the Arabic business name, the Arabic business description, the business
 * module group and a sensitivity band — so the Permission Directory (§13) and the editable
 * Role Permission Matrix (§14) can lead with business language while keeping the canonical
 * key visible as secondary technical detail.
 *
 * Composition, not enumeration: a name is composed as `{action verb} + {resource noun}`
 * from two dictionaries, because the 651-token catalog is itself composed that way
 * (`module.resource.action`). Tokens whose composed reading would be wrong or clumsy get an
 * explicit entry in self::OVERRIDES. An unknown token degrades gracefully to its raw key —
 * it is never hidden and never blocks the directory from rendering.
 */
final class PermissionBusinessCatalog
{
    /** Sensitivity bands, ordered. `critical` is the "requires deliberate care" band. */
    public const SENSITIVITY_NORMAL = 'normal';

    public const SENSITIVITY_ELEVATED = 'elevated';

    public const SENSITIVITY_CRITICAL = 'critical';

    /**
     * Business module groups — the top-level grouping the directory renders (§13:
     * "Group permissions by business module"). Keyed by the permission name's own first
     * segment, which is the only module authority the catalog actually carries.
     *
     * @var array<string,array{ar:string,en:string,sort:int}>
     */
    private const MODULES = [
        'iam' => ['ar' => 'إدارة الهوية والصلاحيات', 'en' => 'Identity & Access', 'sort' => 10],
        'organization' => ['ar' => 'الهيكل التنظيمي', 'en' => 'Organization', 'sort' => 20],
        'configuration' => ['ar' => 'الإعدادات والتهيئة', 'en' => 'Configuration', 'sort' => 30],
        'sales' => ['ar' => 'المبيعات والطلبات', 'en' => 'Sales & Orders', 'sort' => 40],
        'crm' => ['ar' => 'العملاء وإدارة العلاقات', 'en' => 'Customers & CRM', 'sort' => 50],
        'cep' => ['ar' => 'التفاعل مع العملاء', 'en' => 'Customer Engagement', 'sort' => 60],
        'omnichannel' => ['ar' => 'المحادثات متعددة القنوات', 'en' => 'Omnichannel', 'sort' => 70],
        'collaboration' => ['ar' => 'التعاون الداخلي', 'en' => 'Collaboration', 'sort' => 80],
        'inventory' => ['ar' => 'المخازن والأصناف', 'en' => 'Inventory', 'sort' => 90],
        'preparation' => ['ar' => 'التحضير', 'en' => 'Preparation', 'sort' => 100],
        'loading' => ['ar' => 'التحميل', 'en' => 'Loading', 'sort' => 110],
        'purchasing' => ['ar' => 'المشتريات والموردون', 'en' => 'Purchasing & Suppliers', 'sort' => 120],
        'operations' => ['ar' => 'العمليات', 'en' => 'Operations', 'sort' => 130],
        'fulfillment' => ['ar' => 'تنفيذ الطلبات', 'en' => 'Fulfillment', 'sort' => 140],
        'distribution' => ['ar' => 'التوزيع', 'en' => 'Distribution', 'sort' => 150],
        'logistics' => ['ar' => 'الشحن واللوجستيات', 'en' => 'Shipping & Logistics', 'sort' => 160],
        'dispatch' => ['ar' => 'إرسال الرحلات', 'en' => 'Dispatch', 'sort' => 170],
        'delivery' => ['ar' => 'التسليم', 'en' => 'Delivery', 'sort' => 180],
        'routing' => ['ar' => 'تخطيط المسارات', 'en' => 'Routing', 'sort' => 190],
        'fleet' => ['ar' => 'الأسطول والمركبات', 'en' => 'Fleet', 'sort' => 200],
        'carrier' => ['ar' => 'شركات الشحن', 'en' => 'Carriers', 'sort' => 210],
        'network' => ['ar' => 'شبكة التغطية', 'en' => 'Coverage Network', 'sort' => 220],
        'geography' => ['ar' => 'الجغرافيا', 'en' => 'Geography', 'sort' => 230],
        'finance' => ['ar' => 'المالية', 'en' => 'Finance', 'sort' => 240],
        'accounting' => ['ar' => 'المحاسبة', 'en' => 'Accounting', 'sort' => 250],
        'cost' => ['ar' => 'التكاليف', 'en' => 'Cost Management', 'sort' => 260],
        'pos' => ['ar' => 'نقاط البيع', 'en' => 'Point of Sale', 'sort' => 270],
        'marketing' => ['ar' => 'التسويق', 'en' => 'Marketing', 'sort' => 280],
        'bae' => ['ar' => 'تحليل الأثر التجاري', 'en' => 'Business Attribution', 'sort' => 290],
        'hr' => ['ar' => 'الموارد البشرية', 'en' => 'Human Resources', 'sort' => 300],
        'reports' => ['ar' => 'التقارير', 'en' => 'Reports', 'sort' => 310],
        'engineering' => ['ar' => 'منصة الهندسة', 'en' => 'Engineering Platform', 'sort' => 320],
        'claude_bridge' => ['ar' => 'جسر الذكاء الاصطناعي', 'en' => 'AI Bridge', 'sort' => 330],
    ];

    /**
     * Action verbs. Arabic is the verbal-noun form that reads naturally when followed by a
     * resource noun ("عرض الطلبات", "اعتماد أمر الشراء").
     *
     * @var array<string,array{ar:string,en:string}>
     */
    private const ACTIONS = [
        'view' => ['ar' => 'عرض', 'en' => 'View'],
        'create' => ['ar' => 'إنشاء', 'en' => 'Create'],
        'update' => ['ar' => 'تعديل', 'en' => 'Update'],
        'edit' => ['ar' => 'تعديل', 'en' => 'Edit'],
        'delete' => ['ar' => 'حذف', 'en' => 'Delete'],
        'manage' => ['ar' => 'إدارة', 'en' => 'Manage'],
        'approve' => ['ar' => 'اعتماد', 'en' => 'Approve'],
        'reject' => ['ar' => 'رفض', 'en' => 'Reject'],
        'cancel' => ['ar' => 'إلغاء', 'en' => 'Cancel'],
        'post' => ['ar' => 'ترحيل', 'en' => 'Post'],
        'reverse' => ['ar' => 'عكس القيد', 'en' => 'Reverse'],
        'assign' => ['ar' => 'تعيين', 'en' => 'Assign'],
        'revoke' => ['ar' => 'سحب', 'en' => 'Revoke'],
        'start' => ['ar' => 'بدء', 'en' => 'Start'],
        'stop' => ['ar' => 'إيقاف', 'en' => 'Stop'],
        'pause' => ['ar' => 'تعليق مؤقت', 'en' => 'Pause'],
        'resume' => ['ar' => 'استئناف', 'en' => 'Resume'],
        'complete' => ['ar' => 'إنهاء', 'en' => 'Complete'],
        'close' => ['ar' => 'إغلاق', 'en' => 'Close'],
        'open' => ['ar' => 'فتح', 'en' => 'Open'],
        'review' => ['ar' => 'مراجعة', 'en' => 'Review'],
        'resolve' => ['ar' => 'معالجة', 'en' => 'Resolve'],
        'release' => ['ar' => 'إطلاق', 'en' => 'Release'],
        'activate' => ['ar' => 'تنشيط', 'en' => 'Activate'],
        'deactivate' => ['ar' => 'إيقاف التنشيط', 'en' => 'Deactivate'],
        'suspend' => ['ar' => 'إيقاف مؤقت', 'en' => 'Suspend'],
        'lock' => ['ar' => 'قفل', 'en' => 'Lock'],
        'unlock' => ['ar' => 'فتح القفل', 'en' => 'Unlock'],
        'archive' => ['ar' => 'أرشفة', 'en' => 'Archive'],
        'restore' => ['ar' => 'استعادة', 'en' => 'Restore'],
        'sync' => ['ar' => 'مزامنة', 'en' => 'Sync'],
        'submit' => ['ar' => 'إرسال للاعتماد', 'en' => 'Submit'],
        'retry' => ['ar' => 'إعادة المحاولة', 'en' => 'Retry'],
        'recalculate' => ['ar' => 'إعادة الحساب', 'en' => 'Recalculate'],
        'override' => ['ar' => 'تجاوز استثنائي', 'en' => 'Override'],
        'operate' => ['ar' => 'تشغيل', 'en' => 'Operate'],
        'merge' => ['ar' => 'دمج', 'en' => 'Merge'],
        'split' => ['ar' => 'تقسيم', 'en' => 'Split'],
        'export' => ['ar' => 'تصدير', 'en' => 'Export'],
        'import' => ['ar' => 'استيراد', 'en' => 'Import'],
        'execute' => ['ar' => 'تنفيذ', 'en' => 'Execute'],
        'validate' => ['ar' => 'التحقق من', 'en' => 'Validate'],
        'verify' => ['ar' => 'توثيق', 'en' => 'Verify'],
        'reserve' => ['ar' => 'حجز', 'en' => 'Reserve'],
        'reconcile' => ['ar' => 'تسوية ومطابقة', 'en' => 'Reconcile'],
        'preview' => ['ar' => 'معاينة', 'en' => 'Preview'],
        'ingest' => ['ar' => 'استقبال بيانات', 'en' => 'Ingest'],
        'drain' => ['ar' => 'تصريف طابور', 'en' => 'Drain'],
        'connect' => ['ar' => 'ربط', 'en' => 'Connect'],
        'analyze' => ['ar' => 'تحليل', 'en' => 'Analyze'],
        'adjust' => ['ar' => 'تسوية كمية', 'en' => 'Adjust'],
        'writeoff' => ['ar' => 'إعدام وشطب', 'en' => 'Write off'],
        'unload' => ['ar' => 'تنزيل حمولة', 'en' => 'Unload'],
        'transition' => ['ar' => 'تغيير حالة', 'en' => 'Transition'],
        'transact' => ['ar' => 'تنفيذ حركة على', 'en' => 'Transact'],
        'tag' => ['ar' => 'وسم', 'en' => 'Tag'],
        'schedule' => ['ar' => 'جدولة', 'en' => 'Schedule'],
        'run' => ['ar' => 'تشغيل', 'en' => 'Run'],
        'propose' => ['ar' => 'اقتراح', 'en' => 'Propose'],
        'optimize' => ['ar' => 'تحسين', 'en' => 'Optimize'],
        'select_supplier' => ['ar' => 'اختيار المورد في', 'en' => 'Select supplier for'],
        'message_drivers' => ['ar' => 'مراسلة المندوبين عبر', 'en' => 'Message drivers via'],
        'assign_drivers' => ['ar' => 'تعيين المندوبين في', 'en' => 'Assign drivers in'],
        'apply' => ['ar' => 'تطبيق', 'en' => 'Apply'],
        'confirm' => ['ar' => 'تأكيد', 'en' => 'Confirm'],
        'handover' => ['ar' => 'تسليم', 'en' => 'Hand over'],
        'settle' => ['ar' => 'تسوية', 'en' => 'Settle'],
        'print' => ['ar' => 'طباعة', 'en' => 'Print'],
        'download' => ['ar' => 'تنزيل', 'en' => 'Download'],
        'upload' => ['ar' => 'رفع', 'en' => 'Upload'],
    ];

    /**
     * Resource nouns, keyed by the permission name minus its trailing action segment
     * (e.g. `finance.ap.advance` for `finance.ap.advance.apply`). Two-segment tokens
     * (`fleet.manage`) key on their module alone.
     *
     * @var array<string,array{ar:string,en:string}>
     */
    private const RESOURCES = [
        // ── Identity & Access ────────────────────────────────────────────────
        'iam.users' => ['ar' => 'المستخدمين', 'en' => 'Users'],
        'iam.roles' => ['ar' => 'الأدوار', 'en' => 'Roles'],
        'iam.permissions' => ['ar' => 'دليل الصلاحيات', 'en' => 'Permission catalog'],
        'iam.role-templates' => ['ar' => 'قوالب الأدوار', 'en' => 'Role templates'],

        // ── Organization ─────────────────────────────────────────────────────
        'organization.companies' => ['ar' => 'الشركات', 'en' => 'Companies'],
        'organization.brands' => ['ar' => 'العلامات التجارية', 'en' => 'Brands'],
        'organization.branches' => ['ar' => 'الفروع', 'en' => 'Branches'],
        'organization.teams' => ['ar' => 'الفرق', 'en' => 'Teams'],
        'organization.business_accounts' => ['ar' => 'وحدات الأعمال', 'en' => 'Business units'],

        // ── Configuration ────────────────────────────────────────────────────
        'configuration.company' => ['ar' => 'إعدادات الشركة', 'en' => 'Company configuration'],
        'configuration.brand' => ['ar' => 'إعدادات العلامة التجارية', 'en' => 'Brand configuration'],
        'configuration.policies' => ['ar' => 'سياسات النظام', 'en' => 'System policies'],
        'configuration.settings' => ['ar' => 'الإعدادات العامة', 'en' => 'General settings'],
        'configuration.master_geography' => ['ar' => 'البيانات الجغرافية الرئيسية', 'en' => 'Master geography'],

        // ── Sales & Orders ───────────────────────────────────────────────────
        'sales.orders' => ['ar' => 'الطلبات', 'en' => 'Orders'],
        'sales.customers' => ['ar' => 'عملاء المبيعات', 'en' => 'Sales customers'],
        'sales.channels' => ['ar' => 'قنوات البيع', 'en' => 'Sales channels'],
        'sales.fulfillments' => ['ar' => 'تنفيذ طلبات البيع', 'en' => 'Sales fulfillments'],

        // ── CRM ──────────────────────────────────────────────────────────────
        'crm.customers' => ['ar' => 'ملف العميل', 'en' => 'Customer records'],
        'crm.sales' => ['ar' => 'مبيعات العميل', 'en' => 'Customer sales'],
        'crm.service' => ['ar' => 'خدمة العملاء', 'en' => 'Customer service'],
        'crm.engagement' => ['ar' => 'تفاعل العميل', 'en' => 'Customer engagement'],
        'crm.engagement.task' => ['ar' => 'مهمة متابعة العميل', 'en' => 'Customer follow-up task'],
        'crm.loyalty' => ['ar' => 'برنامج الولاء', 'en' => 'Loyalty'],
        'crm.intelligence' => ['ar' => 'تحليلات العملاء', 'en' => 'Customer intelligence'],
        'crm.executive' => ['ar' => 'لوحة العملاء التنفيذية', 'en' => 'CRM executive board'],
        'crm.kb' => ['ar' => 'قاعدة المعرفة', 'en' => 'Knowledge base'],

        // ── Customer Engagement Platform ─────────────────────────────────────
        'cep.inbox' => ['ar' => 'صندوق تفاعل العملاء', 'en' => 'Engagement inbox'],
        'cep.conversations' => ['ar' => 'محادثات العملاء', 'en' => 'Customer conversations'],
        'cep.leads' => ['ar' => 'العملاء المحتملين', 'en' => 'Leads'],
        'cep.notes' => ['ar' => 'ملاحظات التفاعل', 'en' => 'Engagement notes'],

        // ── Omnichannel ──────────────────────────────────────────────────────
        'omnichannel.inbox' => ['ar' => 'صندوق الوارد الموحد', 'en' => 'Unified inbox'],
        'omnichannel.conversations' => ['ar' => 'المحادثات الموحدة', 'en' => 'Conversations'],
        'omnichannel.macros' => ['ar' => 'الردود الجاهزة', 'en' => 'Macros'],
        'omnichannel.providers' => ['ar' => 'مزودي قنوات التواصل', 'en' => 'Channel providers'],
        'omnichannel.routing_rules' => ['ar' => 'قواعد توجيه المحادثات', 'en' => 'Routing rules'],

        // ── Collaboration ────────────────────────────────────────────────────
        'collaboration.conversations' => ['ar' => 'محادثات الفريق', 'en' => 'Team conversations'],
        'collaboration.groups' => ['ar' => 'مجموعات العمل', 'en' => 'Work groups'],
        'collaboration.tasks' => ['ar' => 'مهام الفريق', 'en' => 'Team tasks'],

        // ── Inventory ────────────────────────────────────────────────────────
        'inventory.products' => ['ar' => 'الأصناف والمنتجات', 'en' => 'Products'],
        'inventory.raw_materials' => ['ar' => 'المواد الخام', 'en' => 'Raw materials'],
        'inventory.recipes' => ['ar' => 'التركيبات ومكونات التصنيع', 'en' => 'Recipes / BOM'],
        'inventory.stock' => ['ar' => 'أرصدة المخزون', 'en' => 'Stock balances'],
        'inventory.count' => ['ar' => 'الجرد', 'en' => 'Stock count'],
        'inventory.transfers' => ['ar' => 'التحويلات المخزنية', 'en' => 'Stock transfers'],
        'inventory.warehouses' => ['ar' => 'المخازن', 'en' => 'Warehouses'],
        'inventory.categories' => ['ar' => 'تصنيفات الأصناف', 'en' => 'Item categories'],
        'inventory.units' => ['ar' => 'وحدات القياس', 'en' => 'Units of measure'],
        'inventory.waste' => ['ar' => 'الهالك والفاقد', 'en' => 'Waste'],
        'inventory.abc' => ['ar' => 'تصنيف ABC', 'en' => 'ABC classification'],
        'inventory.liabilities' => ['ar' => 'مسؤوليات المخزن', 'en' => 'Warehouse liabilities'],
        'inventory.price_review' => ['ar' => 'مراجعة أسعار الأصناف', 'en' => 'Item price review'],

        // ── Preparation ──────────────────────────────────────────────────────
        'preparation.waves' => ['ar' => 'موجات التحضير', 'en' => 'Preparation waves'],
        'preparation.sessions' => ['ar' => 'جلسات التحضير', 'en' => 'Preparation sessions'],
        'preparation.batches' => ['ar' => 'دفعات التحضير', 'en' => 'Preparation batches'],

        // ── Loading ──────────────────────────────────────────────────────────
        'loading.session' => ['ar' => 'جلسة التحميل', 'en' => 'Loading session'],
        'loading.allocation' => ['ar' => 'توزيع الحمولة', 'en' => 'Load allocation'],
        'loading.vehicle' => ['ar' => 'تحميل المركبة', 'en' => 'Vehicle loading'],
        'loading.driver' => ['ar' => 'مهام المندوب التشغيلية', 'en' => 'Driver runtime'],

        // ── Purchasing ───────────────────────────────────────────────────────
        'purchasing.suppliers' => ['ar' => 'الموردون', 'en' => 'Suppliers'],
        'purchasing.purchase_orders' => ['ar' => 'أوامر الشراء', 'en' => 'Purchase orders'],
        'purchasing.purchases' => ['ar' => 'طلبات الشراء', 'en' => 'Purchase requests'],
        'purchasing.materials' => ['ar' => 'أصناف المشتريات', 'en' => 'Purchase materials'],
        'purchasing.material_requests' => ['ar' => 'طلبات المواد', 'en' => 'Material requests'],
        'purchasing.receiving' => ['ar' => 'استلام المشتريات', 'en' => 'Purchase receiving'],
        'purchasing.goods_receipts' => ['ar' => 'إشعارات استلام البضاعة', 'en' => 'Goods receipts'],
        'purchasing.invoices' => ['ar' => 'فواتير المشتريات', 'en' => 'Purchase invoices'],
        'purchasing.supplier_invoices' => ['ar' => 'فواتير الموردين', 'en' => 'Supplier invoices'],
        'purchasing.supplier_returns' => ['ar' => 'مرتجعات الموردين', 'en' => 'Supplier returns'],
        'purchasing.expected_incoming' => ['ar' => 'الكميات المتوقعة الواردة', 'en' => 'Expected incoming'],

        // ── Operations ───────────────────────────────────────────────────────
        'operations' => ['ar' => 'لوحة العمليات', 'en' => 'Operations board'],
        'operations.preparation' => ['ar' => 'تشغيل التحضير', 'en' => 'Preparation operations'],
        'operations.fulfillment' => ['ar' => 'تشغيل التنفيذ', 'en' => 'Fulfillment operations'],
        'operations.capacity' => ['ar' => 'الطاقة التشغيلية', 'en' => 'Operational capacity'],
        'operations.pool' => ['ar' => 'مجمعات العمل', 'en' => 'Work pools'],
        'operations.exception' => ['ar' => 'استثناءات التشغيل', 'en' => 'Operational exceptions'],
        'operations.alert' => ['ar' => 'تنبيهات التشغيل', 'en' => 'Operational alerts'],
        'operations.audit' => ['ar' => 'سجل تدقيق التشغيل', 'en' => 'Operations audit trail'],

        // ── Fulfillment ──────────────────────────────────────────────────────
        'fulfillment.waves' => ['ar' => 'موجات التنفيذ', 'en' => 'Fulfillment waves'],
        'fulfillment.pools' => ['ar' => 'مجمعات التنفيذ', 'en' => 'Fulfillment pools'],
        'fulfillment.allocations' => ['ar' => 'تخصيصات التنفيذ', 'en' => 'Fulfillment allocations'],

        // ── Distribution ─────────────────────────────────────────────────────
        'distribution.trips' => ['ar' => 'رحلات التوزيع', 'en' => 'Distribution trips'],
        'distribution.stops' => ['ar' => 'نقاط التوقف', 'en' => 'Trip stops'],
        'distribution.loading' => ['ar' => 'تحميل التوزيع', 'en' => 'Distribution loading'],
        'distribution.returns' => ['ar' => 'مرتجعات التوزيع', 'en' => 'Distribution returns'],
        'distribution.custody' => ['ar' => 'العهدة', 'en' => 'Custody'],
        'distribution.exceptions' => ['ar' => 'استثناءات التوزيع', 'en' => 'Distribution exceptions'],

        // ── Shipping & Logistics ─────────────────────────────────────────────
        'logistics.shipping' => ['ar' => 'أوامر الشحن', 'en' => 'Shipping orders'],
        'logistics.carriers' => ['ar' => 'شركات الشحن الخارجية', 'en' => 'Carriers'],
        'logistics.drivers' => ['ar' => 'المندوبون', 'en' => 'Drivers'],
        'logistics.vehicles' => ['ar' => 'المركبات', 'en' => 'Vehicles'],
        'logistics.distribution' => ['ar' => 'تخطيط التوزيع', 'en' => 'Distribution planning'],
        'logistics.geography' => ['ar' => 'جغرافيا الشحن', 'en' => 'Shipping geography'],

        // ── Dispatch ─────────────────────────────────────────────────────────
        'dispatch' => ['ar' => 'الإرسال', 'en' => 'Dispatch'],
        'dispatch.queue' => ['ar' => 'طابور الإرسال', 'en' => 'Dispatch queue'],
        'dispatch.session' => ['ar' => 'جلسة الإرسال', 'en' => 'Dispatch session'],
        'dispatch.assignment' => ['ar' => 'تعيينات الإرسال', 'en' => 'Dispatch assignment'],
        'dispatch.monitoring' => ['ar' => 'مراقبة الإرسال', 'en' => 'Dispatch monitoring'],
        'dispatch.conflict' => ['ar' => 'تعارضات الإرسال', 'en' => 'Dispatch conflicts'],
        'dispatch.audit' => ['ar' => 'سجل تدقيق الإرسال', 'en' => 'Dispatch audit trail'],

        // ── Delivery ─────────────────────────────────────────────────────────
        'delivery' => ['ar' => 'التسليم', 'en' => 'Delivery'],
        'delivery.pod' => ['ar' => 'إثبات التسليم', 'en' => 'Proof of delivery'],
        'delivery.cod' => ['ar' => 'التحصيل عند التسليم', 'en' => 'Cash on delivery'],
        'delivery.return' => ['ar' => 'مرتجع التسليم', 'en' => 'Delivery return'],
        'delivery.analytics' => ['ar' => 'تحليلات التسليم', 'en' => 'Delivery analytics'],

        // ── Routing / Fleet / Carrier / Network ──────────────────────────────
        'routing' => ['ar' => 'المسارات', 'en' => 'Routing'],
        'fleet' => ['ar' => 'الأسطول', 'en' => 'Fleet'],
        'fleet.maintenance' => ['ar' => 'صيانة الأسطول', 'en' => 'Fleet maintenance'],
        'fleet.inspection' => ['ar' => 'فحص المركبات', 'en' => 'Vehicle inspection'],
        'fleet.fuel' => ['ar' => 'الوقود', 'en' => 'Fuel'],
        'fleet.cost' => ['ar' => 'تكاليف الأسطول', 'en' => 'Fleet cost'],
        'fleet.health' => ['ar' => 'جاهزية الأسطول', 'en' => 'Fleet health'],
        'carrier' => ['ar' => 'شركة الشحن', 'en' => 'Carrier'],
        'network' => ['ar' => 'شبكة التغطية', 'en' => 'Coverage network'],
        'network.capacity' => ['ar' => 'طاقة الشبكة', 'en' => 'Network capacity'],

        // ── Geography ────────────────────────────────────────────────────────
        'geography.governorates' => ['ar' => 'المحافظات', 'en' => 'Governorates'],
        'geography.cities' => ['ar' => 'المدن', 'en' => 'Cities'],
        'geography.zones' => ['ar' => 'المناطق', 'en' => 'Zones'],
        'geography.aliases' => ['ar' => 'المسميات البديلة للمواقع', 'en' => 'Location aliases'],

        // ── Finance ──────────────────────────────────────────────────────────
        'finance.ar' => ['ar' => 'حسابات العملاء المدينة', 'en' => 'Accounts receivable'],
        'finance.ar.invoice' => ['ar' => 'فاتورة عميل', 'en' => 'Customer invoice'],
        'finance.ar.receipt' => ['ar' => 'سند تحصيل من عميل', 'en' => 'Customer receipt'],
        'finance.ap' => ['ar' => 'حسابات الموردين الدائنة', 'en' => 'Accounts payable'],
        'finance.ap.bill' => ['ar' => 'فاتورة مورد', 'en' => 'Supplier bill'],
        'finance.ap.payment' => ['ar' => 'سند دفع لمورد', 'en' => 'Supplier payment'],
        'finance.ap.opening' => ['ar' => 'أرصدة الموردين الافتتاحية', 'en' => 'Supplier opening balances'],
        'finance.gl' => ['ar' => 'الأستاذ العام', 'en' => 'General ledger'],
        'finance.journal' => ['ar' => 'القيود اليومية', 'en' => 'Journal entries'],
        'finance.coa' => ['ar' => 'شجرة الحسابات', 'en' => 'Chart of accounts'],
        'finance.cash' => ['ar' => 'الصندوق النقدي', 'en' => 'Cash'],
        'finance.cash.session' => ['ar' => 'جلسة الصندوق', 'en' => 'Cash session'],
        'finance.bank' => ['ar' => 'الحسابات البنكية', 'en' => 'Banking'],
        'finance.bank.rule' => ['ar' => 'قواعد المطابقة البنكية', 'en' => 'Bank matching rules'],
        'finance.posting' => ['ar' => 'ترحيل القيود', 'en' => 'GL posting'],
        'finance.posting.rule' => ['ar' => 'قواعد الترحيل', 'en' => 'Posting rules'],
        'finance.posting.audit' => ['ar' => 'سجل تدقيق الترحيل', 'en' => 'Posting audit trail'],
        'finance.posting.deadletter' => ['ar' => 'قيود الترحيل المعلّقة', 'en' => 'Posting dead letters'],
        'finance.period' => ['ar' => 'الفترات المالية', 'en' => 'Financial periods'],
        'finance.closing' => ['ar' => 'الإقفال المالي', 'en' => 'Financial closing'],
        'finance.closing.workspace' => ['ar' => 'مساحة الإقفال المالي', 'en' => 'Closing workspace'],
        'finance.yearend' => ['ar' => 'إقفال نهاية السنة', 'en' => 'Year-end closing'],
        'finance.trialbalance' => ['ar' => 'ميزان المراجعة', 'en' => 'Trial balance'],
        'finance.reports' => ['ar' => 'القوائم والتقارير المالية', 'en' => 'Financial reports'],
        'finance.analytics' => ['ar' => 'التحليلات المالية', 'en' => 'Financial analytics'],
        'finance.budget' => ['ar' => 'الموازنات', 'en' => 'Budgets'],
        'finance.scenario' => ['ar' => 'سيناريوهات التخطيط المالي', 'en' => 'Financial scenarios'],
        'finance.tax' => ['ar' => 'الضرائب', 'en' => 'Tax'],
        'finance.vat' => ['ar' => 'ضريبة القيمة المضافة', 'en' => 'VAT'],
        'finance.expense' => ['ar' => 'المصروفات', 'en' => 'Expenses'],
        'finance.expense.category' => ['ar' => 'تصنيفات المصروفات', 'en' => 'Expense categories'],
        'finance.dimension' => ['ar' => 'الأبعاد التحليلية', 'en' => 'Analytical dimensions'],
        'finance.allocation' => ['ar' => 'توزيع التكاليف', 'en' => 'Cost allocation'],
        'finance.cost_allocation' => ['ar' => 'قواعد توزيع التكاليف', 'en' => 'Cost allocation rules'],
        'finance.controls' => ['ar' => 'الضوابط المالية', 'en' => 'Financial controls'],
        'finance.integration' => ['ar' => 'الربط المحاسبي للوحدات', 'en' => 'Finance integration mapping'],
        'finance.driver' => ['ar' => 'الحساب المالي للمندوب', 'en' => 'Driver financial account'],
        'finance.executive.workspace' => ['ar' => 'المساحة المالية التنفيذية', 'en' => 'Finance executive workspace'],
        'finance.cfo.workspace' => ['ar' => 'مساحة المدير المالي', 'en' => 'CFO workspace'],

        // ── Accounting ───────────────────────────────────────────────────────
        'accounting.journals' => ['ar' => 'دفاتر اليومية', 'en' => 'Journals'],
        'accounting.ledgers' => ['ar' => 'دفاتر الأستاذ', 'en' => 'Ledgers'],

        // ── Cost management ──────────────────────────────────────────────────
        'cost.cost_management' => ['ar' => 'إدارة التكاليف', 'en' => 'Cost management'],
        'cost.price_review' => ['ar' => 'مراجعة التكلفة والسعر', 'en' => 'Cost & price review'],

        // ── POS ──────────────────────────────────────────────────────────────
        'pos.terminal' => ['ar' => 'نقطة البيع', 'en' => 'POS terminal'],
        'pos.carts' => ['ar' => 'سلات نقطة البيع', 'en' => 'POS carts'],
        'pos.payments' => ['ar' => 'مدفوعات نقطة البيع', 'en' => 'POS payments'],
        'pos.shifts' => ['ar' => 'ورديات نقطة البيع', 'en' => 'POS shifts'],

        // ── Marketing ────────────────────────────────────────────────────────
        'marketing.workspace' => ['ar' => 'مساحة التسويق', 'en' => 'Marketing workspace'],
        'marketing.campaigns' => ['ar' => 'الحملات التسويقية', 'en' => 'Campaigns'],
        'marketing.initiatives' => ['ar' => 'المبادرات التسويقية', 'en' => 'Initiatives'],
        'marketing.assets' => ['ar' => 'الأصول الإبداعية', 'en' => 'Creative assets'],
        'marketing.segments' => ['ar' => 'شرائح الجمهور', 'en' => 'Audience segments'],
        'marketing.automation' => ['ar' => 'التسويق الآلي', 'en' => 'Marketing automation'],
        'marketing.workflows' => ['ar' => 'مسارات التسويق الآلي', 'en' => 'Marketing workflows'],
        'marketing.templates' => ['ar' => 'قوالب الرسائل التسويقية', 'en' => 'Marketing templates'],
        'marketing.studio' => ['ar' => 'استوديو الحملات', 'en' => 'Campaign studio'],
        'marketing.providers' => ['ar' => 'مزودي منصات التسويق', 'en' => 'Marketing providers'],
        'marketing.meta' => ['ar' => 'تكامل ميتا', 'en' => 'Meta integration'],
        'marketing.mapping_profiles' => ['ar' => 'ملفات ربط البيانات التسويقية', 'en' => 'Mapping profiles'],
        'marketing.relationships' => ['ar' => 'علاقات الجمهور', 'en' => 'Audience relationships'],

        // ── Business attribution ─────────────────────────────────────────────
        'bae.attribution' => ['ar' => 'إحالة الأثر التجاري', 'en' => 'Business attribution'],
        'bae.attributions' => ['ar' => 'سجلات الإحالة', 'en' => 'Attribution records'],
        'bae.timeline' => ['ar' => 'الخط الزمني للأثر', 'en' => 'Attribution timeline'],

        // ── HR ───────────────────────────────────────────────────────────────
        'hr.employees' => ['ar' => 'الموظفون', 'en' => 'Employees'],
        'hr.org' => ['ar' => 'الهيكل الوظيفي', 'en' => 'Org structure'],
        'hr.workforce' => ['ar' => 'لوحة القوى العاملة', 'en' => 'Workforce board'],
        'hr.attendance' => ['ar' => 'الحضور والانصراف', 'en' => 'Attendance'],
        'hr.leave' => ['ar' => 'الإجازات', 'en' => 'Leave'],
        'hr.compensation' => ['ar' => 'الرواتب والأجور', 'en' => 'Compensation'],
        'hr.compensation.adjust' => ['ar' => 'تعديلات الرواتب', 'en' => 'Compensation adjustments'],
        'hr.commission' => ['ar' => 'العمولات', 'en' => 'Commission'],
        'hr.contracts' => ['ar' => 'عقود العمل', 'en' => 'Employment contracts'],
        'hr.performance' => ['ar' => 'تقييم الأداء', 'en' => 'Performance'],
        'hr.kpi' => ['ar' => 'مؤشرات الأداء', 'en' => 'KPIs'],
        'hr.recruitment' => ['ar' => 'التوظيف', 'en' => 'Recruitment'],
        'hr.recruitment.analytics' => ['ar' => 'تحليلات التوظيف', 'en' => 'Recruitment analytics'],
        'hr.recruitment.tags' => ['ar' => 'وسوم المتقدمين', 'en' => 'Applicant tags'],
        'hr.hiring' => ['ar' => 'قرارات التعيين', 'en' => 'Hiring'],
        'hr.interviews' => ['ar' => 'المقابلات', 'en' => 'Interviews'],
        'hr.offers' => ['ar' => 'عروض العمل', 'en' => 'Offers'],
        'hr.exit' => ['ar' => 'إجراءات إنهاء الخدمة', 'en' => 'Exits'],
        'hr.lifecycle' => ['ar' => 'دورة حياة الموظف', 'en' => 'Employee lifecycle'],
        'hr.analytics' => ['ar' => 'تحليلات الموارد البشرية', 'en' => 'HR analytics'],
        'hr.executive' => ['ar' => 'لوحة الموارد البشرية التنفيذية', 'en' => 'HR executive board'],

        // ── Reports ──────────────────────────────────────────────────────────
        'reports.sales' => ['ar' => 'تقارير المبيعات', 'en' => 'Sales reports'],
        'reports.customers' => ['ar' => 'تقارير العملاء', 'en' => 'Customer reports'],
        'reports.products' => ['ar' => 'تقارير الأصناف', 'en' => 'Product reports'],
        'reports.inventory' => ['ar' => 'تقارير المخزون', 'en' => 'Inventory reports'],
        'reports.procurement' => ['ar' => 'تقارير المشتريات', 'en' => 'Procurement reports'],
        'reports.preparation' => ['ar' => 'تقارير التحضير', 'en' => 'Preparation reports'],
        'reports.distribution' => ['ar' => 'تقارير التوزيع', 'en' => 'Distribution reports'],
        'reports.drivers' => ['ar' => 'تقارير المندوبين', 'en' => 'Driver reports'],
        'reports.finance' => ['ar' => 'التقارير المالية', 'en' => 'Finance reports'],
        'reports.executive' => ['ar' => 'التقارير التنفيذية', 'en' => 'Executive reports'],

        // ── Engineering platform ─────────────────────────────────────────────
        'engineering.platform' => ['ar' => 'منصة الهندسة', 'en' => 'Engineering platform'],
        'engineering.pipelines' => ['ar' => 'خطوط التشغيل الهندسية', 'en' => 'Engineering pipelines'],
        'engineering.releases' => ['ar' => 'الإصدارات', 'en' => 'Releases'],
        'engineering.queue' => ['ar' => 'طابور المهام الهندسية', 'en' => 'Engineering queue'],
        'engineering.tasks' => ['ar' => 'المهام الهندسية', 'en' => 'Engineering tasks'],
        'engineering.workers' => ['ar' => 'منفذو المهام الهندسية', 'en' => 'Engineering workers'],
        'engineering.ai_reviews' => ['ar' => 'مراجعات الذكاء الاصطناعي', 'en' => 'AI reviews'],
        'engineering.repair' => ['ar' => 'جلسات الإصلاح', 'en' => 'Repair sessions'],

        // ── AI bridge ────────────────────────────────────────────────────────
        'claude_bridge.platform' => ['ar' => 'منصة جسر الذكاء الاصطناعي', 'en' => 'AI bridge platform'],
        'claude_bridge.tasks' => ['ar' => 'مهام جسر الذكاء الاصطناعي', 'en' => 'AI bridge tasks'],
        'claude_bridge.workers' => ['ar' => 'منفذو جسر الذكاء الاصطناعي', 'en' => 'AI bridge workers'],
        'claude_bridge.settings' => ['ar' => 'إعدادات جسر الذكاء الاصطناعي', 'en' => 'AI bridge settings'],
    ];

    /**
     * Tokens whose composed `{verb} {noun}` reading would be wrong, ambiguous or clumsy.
     * An entry here wins outright and may also pin a sensitivity band.
     *
     * @var array<string,array{ar:string,en:string,ar_desc?:string,sensitivity?:string}>
     */
    private const OVERRIDES = [
        'finance.posting.post' => [
            'ar' => 'ترحيل القيود إلى الأستاذ العام',
            'en' => 'Post entries to the general ledger',
            'ar_desc' => 'ترحيل نهائي يؤثر على الأرصدة المحاسبية ولا يمكن التراجع عنه إلا بقيد عكسي.',
            'sensitivity' => self::SENSITIVITY_CRITICAL,
        ],
        'finance.gl.post' => [
            'ar' => 'ترحيل قيد في الأستاذ العام',
            'en' => 'Post a general ledger entry',
            'ar_desc' => 'ترحيل نهائي يؤثر على الأرصدة المحاسبية.',
            'sensitivity' => self::SENSITIVITY_CRITICAL,
        ],
        'finance.ar.receipt.create' => [
            'ar' => 'إنشاء سند تحصيل من عميل',
            'en' => 'Create a customer receipt',
            'ar_desc' => 'استلام نقدي أو بنكي من عميل — عملية خزينة حساسة.',
            'sensitivity' => self::SENSITIVITY_CRITICAL,
        ],
        'finance.ap.payment.create' => [
            'ar' => 'إنشاء سند دفع لمورد',
            'en' => 'Create a supplier payment',
            'ar_desc' => 'صرف نقدي أو بنكي لمورد — عملية خزينة حساسة.',
            'sensitivity' => self::SENSITIVITY_CRITICAL,
        ],
        'finance.cash.session.close' => [
            'ar' => 'إغلاق جلسة الصندوق',
            'en' => 'Close the cash session',
            'sensitivity' => self::SENSITIVITY_CRITICAL,
        ],
        'finance.yearend.close' => [
            'ar' => 'إقفال السنة المالية',
            'en' => 'Close the financial year',
            'sensitivity' => self::SENSITIVITY_CRITICAL,
        ],
        'inventory.stock.adjust' => [
            'ar' => 'تسوية رصيد المخزون',
            'en' => 'Adjust stock balance',
            'ar_desc' => 'تغيير مباشر لأرصدة المخزون — يؤثر على التكلفة والجرد.',
            'sensitivity' => self::SENSITIVITY_CRITICAL,
        ],
        'inventory.waste.writeoff' => [
            'ar' => 'إعدام وشطب الهالك',
            'en' => 'Write off waste',
            'sensitivity' => self::SENSITIVITY_CRITICAL,
        ],
        'inventory.stock.view' => [
            'ar' => 'عرض أرصدة المخزون المتاحة',
            'en' => 'View available stock balances',
            'ar_desc' => 'إظهار الرصيد الحالي والمتاح في المخازن.',
            'sensitivity' => self::SENSITIVITY_ELEVATED,
        ],
        'inventory.recipes.view' => [
            'ar' => 'عرض التركيبات ومكونات التصنيع',
            'en' => 'View recipes / BOM',
            'ar_desc' => 'المكونات والكميات التشغيلية اللازمة للتحضير — بدون تكلفة أو ربحية.',
        ],
        'purchasing.purchase_orders.approve' => [
            'ar' => 'اعتماد أمر الشراء',
            'en' => 'Approve a purchase order',
            'sensitivity' => self::SENSITIVITY_ELEVATED,
        ],
        'purchasing.receiving.create' => [
            'ar' => 'تسجيل استلام مشتريات فعلي',
            'en' => 'Record a physical purchase receipt',
        ],
        'sales.orders.approve' => [
            'ar' => 'تأكيد واعتماد الطلب',
            'en' => 'Confirm and approve the order',
            'sensitivity' => self::SENSITIVITY_ELEVATED,
        ],
        'loading.driver.operate' => [
            'ar' => 'تشغيل تطبيق المندوب',
            'en' => 'Operate the driver app',
            'ar_desc' => 'الصلاحية التشغيلية التي يعمل بها المندوب على رحلاته المخصّصة له فقط.',
        ],
        'delivery.cod.view' => [
            'ar' => 'عرض مبالغ التحصيل عند التسليم',
            'en' => 'View cash-on-delivery amounts',
            'sensitivity' => self::SENSITIVITY_ELEVATED,
        ],
        'distribution.custody.manage' => [
            'ar' => 'إدارة العهدة',
            'en' => 'Manage custody',
            'sensitivity' => self::SENSITIVITY_ELEVATED,
        ],
    ];

    /**
     * Actions that are always at least elevated, wherever they appear. Delete/archive and
     * final-approval verbs are the §14 "sensitive permissions should be visually
     * identifiable" set.
     *
     * @var array<string,string>
     */
    private const ACTION_SENSITIVITY = [
        'delete' => self::SENSITIVITY_CRITICAL,
        'archive' => self::SENSITIVITY_ELEVATED,
        'restore' => self::SENSITIVITY_ELEVATED,
        'post' => self::SENSITIVITY_CRITICAL,
        'reverse' => self::SENSITIVITY_CRITICAL,
        'writeoff' => self::SENSITIVITY_CRITICAL,
        'adjust' => self::SENSITIVITY_CRITICAL,
        'override' => self::SENSITIVITY_CRITICAL,
        'approve' => self::SENSITIVITY_ELEVATED,
        'close' => self::SENSITIVITY_ELEVATED,
        'assign' => self::SENSITIVITY_ELEVATED,
        'revoke' => self::SENSITIVITY_ELEVATED,
        'manage' => self::SENSITIVITY_ELEVATED,
        'reconcile' => self::SENSITIVITY_ELEVATED,
        'settle' => self::SENSITIVITY_ELEVATED,
        'drain' => self::SENSITIVITY_ELEVATED,
    ];

    /**
     * Whole modules that are sensitive by nature — every token inside them is at least
     * elevated (§14: IAM management).
     *
     * @var array<string,string>
     */
    private const MODULE_SENSITIVITY = [
        'iam' => self::SENSITIVITY_ELEVATED,
    ];

    /** @var array<string,int> */
    private const SENSITIVITY_RANK = [
        self::SENSITIVITY_NORMAL => 0,
        self::SENSITIVITY_ELEVATED => 1,
        self::SENSITIVITY_CRITICAL => 2,
    ];

    /**
     * The full business view of one canonical permission name.
     *
     * @return array{
     *     name:string, module:string, module_label_ar:string, module_label_en:string,
     *     module_sort:int, resource:string, action:string,
     *     label_ar:string, label_en:string, description_ar:string, sensitivity:string
     * }
     */
    public static function describe(string $name, ?string $fallbackDescription = null): array
    {
        $segments = explode('.', $name);
        $module = $segments[0] ?? '';
        $action = count($segments) > 1 ? (string) $segments[count($segments) - 1] : '';
        $resource = count($segments) > 2
            ? implode('.', array_slice($segments, 0, -1))
            : $module;

        $moduleMeta = self::MODULES[$module] ?? ['ar' => $module, 'en' => $module, 'sort' => 900];
        $override = self::OVERRIDES[$name] ?? null;

        $actionMeta = self::ACTIONS[$action] ?? null;
        $resourceMeta = self::RESOURCES[$resource] ?? null;

        if ($override !== null) {
            $labelAr = $override['ar'];
            $labelEn = $override['en'];
        } elseif ($actionMeta !== null && $resourceMeta !== null) {
            $labelAr = $actionMeta['ar'].' '.$resourceMeta['ar'];
            $labelEn = $actionMeta['en'].' '.$resourceMeta['en'];
        } elseif ($resourceMeta !== null) {
            $labelAr = $resourceMeta['ar'].' — '.$action;
            $labelEn = $resourceMeta['en'].' — '.$action;
        } else {
            // Never hide an unmapped token: fall back to the canonical key itself.
            $labelAr = $name;
            $labelEn = $name;
        }

        $descriptionAr = $override['ar_desc']
            ?? ($resourceMeta !== null && $actionMeta !== null
                ? self::composeDescription($actionMeta['ar'], $resourceMeta['ar'], $moduleMeta['ar'])
                : ($fallbackDescription ?? ''));

        return [
            'name' => $name,
            'module' => $module,
            'module_label_ar' => $moduleMeta['ar'],
            'module_label_en' => $moduleMeta['en'],
            'module_sort' => $moduleMeta['sort'],
            'resource' => $resource,
            'action' => $action,
            'label_ar' => $labelAr,
            'label_en' => $labelEn,
            'description_ar' => $descriptionAr,
            'sensitivity' => $override['sensitivity'] ?? self::sensitivityFor($module, $action),
        ];
    }

    /**
     * Module group presentation metadata — used to render a group header consistently even
     * when a filter has emptied the group.
     *
     * @return array{ar:string,en:string,sort:int}
     */
    public static function moduleLabel(string $module): array
    {
        return self::MODULES[$module] ?? ['ar' => $module, 'en' => $module, 'sort' => 900];
    }

    private static function composeDescription(string $verbAr, string $nounAr, string $moduleAr): string
    {
        return "يتيح {$verbAr} {$nounAr} داخل {$moduleAr}.";
    }

    private static function sensitivityFor(string $module, string $action): string
    {
        $byAction = self::ACTION_SENSITIVITY[$action] ?? self::SENSITIVITY_NORMAL;
        $byModule = self::MODULE_SENSITIVITY[$module] ?? self::SENSITIVITY_NORMAL;

        return self::SENSITIVITY_RANK[$byAction] >= self::SENSITIVITY_RANK[$byModule]
            ? $byAction
            : $byModule;
    }
}
