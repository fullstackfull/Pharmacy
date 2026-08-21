# دليل الثيم — Theme Guide

> **الخلاصة في سطر:** المتجر يعمل بثيم واحد مدمج (`default`)، وكل ما يخص مظهره يُدار من مكان
> واحد في لوحة الأدمن: **Theme Management** على المسار `/admin/theme`.
>
> **One line:** the storefront runs a single built-in theme (`default`), and everything about its
> look is managed from one admin surface: **Theme Management** at `/admin/theme`.

---

## 1. الفكرة — لماذا ثيم واحد؟

سابقاً كان في المشروع نظامان متوازيان للثيم:

| | النظام القديم (أُزيل) | النظام الحالي |
|---|---|---|
| التبديل | متغير `WEB_THEME` في `.env` | لا تبديل — ثيم واحد ثابت |
| الثيمات | مجلدات `default` و`theme_aster` + فروع كود ميتة لـ`theme_fashion` | مجلد `resources/themes/default` فقط |
| واجهة الأدمن | صفحتان: «Theme Setup» (تثبيت zip + فحص ترخيص خارجي) و«Theme Management» | صفحة واحدة: **Theme Management** |
| تخصيص المظهر | تعديل ملفات Blade يدوياً | نسخ وأقسام وإعدادات من قاعدة البيانات |

الازدواجية كانت مصدر الغموض: صفحتا أدمن باسم «Theme»، وسويتشات على أسماء ثيمات في ٦٠+ موقع
كود. أُزيل كل ذلك؛ `theme_root_path()` الآن ثابت يرجع `'default'` دائماً، والرابط القديم
`/admin/system-setup/theme/setup` يحوّل تلقائياً إلى `/admin/theme`.

---

## 2. أين يعيش الثيم؟ (بنية الملفات)

```
resources/themes/default/
├── file_names.php          ← خريطة «اسم منطقي → ملف Blade» (يستخدمها الكود عبر VIEW_FILE_NAMES)
├── layouts/front-end/      ← الهيكل العام: app.blade.php + الهيدر والفوتر والمودالات
│   └── partials/           ← _header.blade.php, _footer.blade.php, _cart.blade.php ...
└── web-views/              ← صفحات المتجر
    ├── home.blade.php      ← الصفحة الرئيسية (مع جسر أقسام الـBuilder — انظر §4)
    ├── products/           ← قوائم المنتجات وتفاصيل المنتج
    ├── cart/  checkout/    ← السلة والدفع
    ├── customer-views/     ← التسجيل والدخول
    ├── users-profile/      ← حساب الزبون
    └── partials/           ← مكونات الصفحة الرئيسية (_flash-deal, _best-selling ...)
```

الأصول (CSS/JS/صور):

```
public/assets/front-end/        ← أصول الواجهة القديمة (تُحمَّل أولاً)
resources/css/kohl/store.scss   ← طبقة Kohl: ستايل الواجهة الحديث (يُبنى إلى public/assets/kohl/css/store.css ويُحمَّل أخيراً)
resources/css/kohl/_storefront-refresh.scss ← «التحديث البصري»: الحواف، البطاقات، الأزرار، البحث...
```

**قاعدة ذهبية:** أي تعديل ستايل جديد يوضع في طبقة Kohl (`store.scss` وشركاؤها) لأنها آخر ما
يُحمَّل فتكسب دائماً — لا تعدل ملفات CSS القديمة في `public/assets/front-end/css`.

بعد أي تعديل SCSS:

```bash
npm run production      # أو npm run watch أثناء التطوير
```

---

## 3. واجهة الأدمن الواحدة — Theme Management (`/admin/theme`)

ثلاث شاشات مترابطة (كلها تحت صلاحية `themes_and_addons`):

### أ) Theme Management — الثيمات والنسخ
- **الثيم النشط** واحد دائماً (تفعيل ثيم يعطّل البقية ذرياً).
- كل ثيم له **نسخ (Versions)** بدورة حياة: `draft ← published ← archived`.
  - **Publish**: النسخة المنشورة السابقة تُؤرشف — لا شيء يُحذف، والرجوع = نشر نسخة أقدم.
  - **Duplicate / Restore**: أي نسخة تُنسخ إلى مسودة جديدة للتعديل الآمن.
- **استيراد/تصدير** ثيم كملف JSON + **Presets** جاهزة (مثل Minimal Luxury) + ملف مثال للتنزيل.
- **أصول الثيم**: رفع شعارات/أيقونات تُستخدم داخل الأقسام.
- المبذور افتراضياً: ثيم النظام **«الثيم الأساسي» (base-storefront)** بنسخة مسودة — جاهز
  للتجربة والنشر متى شئت.

### ب) Theme Builder — محرر أقسام الصفحات
تركيب الصفحة الرئيسية (والهيدر والفوتر) من **أقسام** جاهزة: hero banner، شبكة فئات، سلايدر
منتجات (بمصادر: مميز/الأكثر مبيعاً/وصل حديثاً/فئة/ماركة...)، بانرات بعروض متعددة
(carousel/grid/mosaic/strip/split)، شريط ثقة، ماركات، نشرة بريدية، HTML مخصص...

- إضافة/ترتيب/إخفاء الأقسام بالسحب، ولكل قسم إعدادات (خلفية، هوامش، أعمدة… مع قيم مستقلة
  للتابلت والموبايل).
- **المعاينة** داخل المحرر على مقاسات الأجهزة قبل النشر.
- كتالوج الأقسام في الكود: `app/Services/Theme/SectionRegistry.php` — إضافة نوع قسم جديد =
  إدخال تعريفه هناك + جزئية Blade تعرضه (بلا أي تعديل على المحرر نفسه).

### ج) Theme Settings — الهوية البصرية
الألوان (الأساسي/الثانوي/…)، الخطوط وأحجامها، عرض الحاوية، استدارة الحواف… تُحقن في
الواجهة كمتغيرات CSS (`--web-primary`، `--theme-border-radius`…) عبر
`resources/views/partials/theme-global-tokens.blade.php` — **فقط عندما توجد نسخة منشورة**.

---

## 4. كيف تصل أقسام الـBuilder إلى الواجهة؟

`web-views/home.blade.php` يجرب أولاً تصيير `theme-sections.home`:

- **إن وُجدت نسخة منشورة بأقسام** ← أقسام الـBuilder **تستبدل** الصفحة الرئيسية المكتوبة
  يدوياً بالكامل (لا تكديس فوقها).
- **إن لم توجد** (الوضع الافتراضي) ← تُعرض الصفحة الرئيسية الافتراضية كما هي.
- أي خطأ داخل قسم لا يُسقط المتجر أبداً: يُسجَّل ويُعرض الاحتياطي.

**الهيدر والفوتر قابلان للتحرير أيضاً:**
- صفحة **header** في المحرر: قسم «شريط الإعلان» يظهر فوق هيدر المتجر مباشرة (نص + رابط + زر إغلاق يتذكر إغلاقه).
- صفحة **footer** في المحرر: أقسام «أعمدة الفوتر» (روابط/نص/تواصل/سوشال/تطبيقات) و«النشرة البريدية» و«HTML مخصص» — عند نشرها **تستبدل** الفوتر المدمج بالكامل (سطر الحقوق يبقى تلقائياً)، وعند عدم وجودها يبقى الفوتر المدمج كما هو.

بهذا يكون **سير العمل الكامل لتغيير شكل الرئيسية**:

```
/admin/theme  ← فعِّل «الثيم الأساسي» (أو أنشئ ثيماً)
   └─ Theme Builder ← ركّب الأقسام وعاين
        └─ Publish ← صارت هي الصفحة الرئيسية live
             └─ (تراجع؟) انشر النسخة المؤرشفة السابقة
```

---

## 5. البانرات وعلاقتها بالثيم

تُدار البانرات من **Promotion → Banners** (`/admin/banner/list`) بأنواع: Main Banner،
Popup، Footer، Main Section، Category Banner، Category Section Banner، Home Promo Banner،
Brand Banner.

- الصفحة الافتراضية تعرضها في مواضعها المدمجة.
- وفي الـBuilder يوجد قسم **«بانرات من لوحة التحكم» (store_banner)** يعرض أي نوع منها في أي
  موضع تختاره وبأي تخطيط — وعندها يقف الموضع المدمج تلقائياً كي لا تتكرر.

**الربط الذكي في الاتجاهين:**
- كل بلوك بانر في المحرر (سلايد الهيرو، بلاطة إعلانية، موزاييك، سبليت) فيه حقل
  **«بانر مربوط من لوحة البانرات»**: اربطه بأي بانر موجود فتُعرض صورته ورابطه ونصوصه
  **مباشرة من صف البانر** — أي تعديل لاحق في شاشة البانرات يظهر في المتجر فوراً، وإيقاف نشره
  يخفي البطاقة من الثيم.
- وإن رفعت صورة داخل المحرر مباشرة، تُسجَّل تلقائياً كبانر بنوع **«بانر الثيم» (Theme Banner)**
  في شاشة البانرات ويُربط البلوك بها — فلا يوجد بانر في الثيم لا تعرفه شاشة البانرات.
  والبلوكات القديمة (المضافة قبل هذه الميزة) تُسجَّل تلقائياً بمجرد فتح المحرر أو عند النشر.
- حذف البانر من شاشة البانرات = إزالته من الثيم أيضاً (البطاقة المرتبطة تختفي) — مصدر حقيقة
  واحد بلا نسخ قديمة تعود للظهور.
- شاشة البانرات تعرض شارة **«معروض عبر الثيم»** على كل بانر يستخدمه المحرر (بالرابط المباشر أو
  عبر قسم store_banner لنوعه)، مع رابط يفتح المحرر.

---

## 6. وصفات عملية سريعة

| أريد أن… | افعل |
|---|---|
| أغيّر اللون الأساسي/الخط | Theme Settings ← عدّل وانشر النسخة |
| أعيد ترتيب أقسام الرئيسية | Theme Builder ← اسحب الأقسام ← Publish |
| أضيف سلايدر «وصل حديثاً» | Builder ← Add Section ← Product Slider ← source: new_arrival |
| أجرب تعديلاً دون لمس الموقع | Duplicate النسخة المنشورة ← عدّل المسودة ← عاين ← انشر |
| أرجع عن نشر خاطئ | Versions ← Restore للنسخة السابقة ← Publish |
| أعدّل ستايل بطاقة المنتج عالمياً | `resources/css/kohl/_storefront-refresh.scss` ثم `npm run production` |
| أضيف نوع قسم جديد للمطورين | `SectionRegistry.php` + جزئية Blade في `resources/views/theme-sections/` |

---

## 7. English summary (for developers)

- **Single theme.** `theme_root_path()` is a constant `'default'`; there is no `WEB_THEME`
  switching, no theme zip installer, no licence callback. `resources/themes/default` is the only
  Blade theme; view resolution still goes through `VIEW_FILE_NAMES` (`file_names.php`).
- **One admin surface.** `/admin/theme` = theme registry + versions (draft/publish/archive,
  non-destructive), the section Builder, and design-token Settings. The legacy
  `/admin/system-setup/theme/setup` URL redirects there.
- **Rendering bridge.** `web-views/home.blade.php` renders `theme-sections.home`; published
  sections replace the hardcoded home, otherwise the default home renders. Failures inside a
  section are caught and logged — the storefront never goes down because of a theme section.
- **Styling layers.** Legacy CSS loads first; the Kohl layer
  (`resources/css/kohl/store.scss` → `public/assets/kohl/css/store.css`) loads last and is where
  all new storefront styling belongs (see `_storefront-refresh.scss` for the current visual
  language: white canvas, hairline borders, 16px radius, pill CTAs, brand accent from
  `--web-primary`). Build with `npm run production`.
- **Sections catalogue.** `app/Services/Theme/SectionRegistry.php` is the contract between the
  Builder UI and the storefront renderer (`StorefrontThemeRenderer`); add a type there plus its
  Blade partial and the Builder picks it up automatically.
- **Header & footer.** `theme-sections/header.blade.php` renders published header sections
  (announcement bar) above the built-in header; `theme-sections/footer.blade.php` REPLACES the
  built-in footer when footer sections are published (copyright bar always appended), falling back
  otherwise — same replace-not-stack contract as the home page.
- **Banner smart link.** `app/Services/Theme/ThemeBannerLink.php`: banner-shaped blocks carry a
  `banner_id` picker over Promotion -> Banners rows and render the linked row live (unpublished =
  hidden); an image uploaded straight in the builder is auto-registered as a `Theme Banner` row and
  linked back; the Banner Setup list badges every banner the theme shows.
