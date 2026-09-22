/* Section specifications for the TouchWood admin panel.
   Keyed by the English sidebar label. Cells: "text" | [en, ar] | {p:[en, ar, tone]}
   tone: green | amber | red | blue | grey        kinds: table | form | report */

const P = (en, ar, tone) => ({ p: [en, ar, tone] });

export const SECTIONS = {

/* ---------------------------------------------------------------- products */
'Category discounts': {
  kind: 'table', add: ['New discount', 'خصم جديد'],
  cols: [['Category', 'القسم'], ['Discount', 'الخصم'], ['Applies to', 'يشمل'], ['Window', 'الفترة'], ['Status', 'الحالة']],
  rows: [
    [['Slides and runners', 'المجاري والسحّابات'], '15%', ['All variants', 'كل التركيبات'], '01–30 Sep', P('Active', 'نشط', 'green')],
    [['Handles', 'المقابض'], '10%', ['Retail only', 'التجزئة فقط'], '10–20 Sep', P('Active', 'نشط', 'green')],
    [['Wardrobe organizers', 'منظمات خزائن الملابس'], '20%', ['Selected brands', 'ماركات محددة'], '01–15 Oct', P('Scheduled', 'مجدول', 'blue')],
    [['Lighting and electrical', 'الإضاءة والكهرباء'], '5%', ['Wholesale only', 'الجملة فقط'], '01–31 Aug', P('Ended', 'منتهي', 'grey')]
  ]
},
'Brands': {
  kind: 'table', add: ['Add brand', 'إضافة ماركة'],
  cols: [['Brand', 'الماركة'], ['Origin', 'المنشأ'], ['Products', 'المنتجات'], ['Agency', 'الوكالة'], ['Status', 'الحالة']],
  rows: [
    ['Tallsen', ['China', 'الصين'], '240', ['Exclusive agent', 'وكيل حصري'], P('Published', 'منشور', 'green')],
    ['TouchWood', ['Saudi Arabia', 'السعودية'], '402', ['House brand', 'ماركة المتجر'], P('Published', 'منشور', 'green')],
    ['Hettich', ['Germany', 'ألمانيا'], '96', ['Distributor', 'موزع'], P('Published', 'منشور', 'green')],
    ['Blum', ['Austria', 'النمسا'], '64', ['Distributor', 'موزع'], P('Published', 'منشور', 'green')],
    ['Häfele', ['Germany', 'ألمانيا'], '40', ['Distributor', 'موزع'], P('Hidden', 'مخفي', 'grey')]
  ]
},
'Custom labels': {
  kind: 'table', add: ['Add label', 'إضافة تسمية'],
  cols: [['Label', 'التسمية'], ['Colour', 'اللون'], ['Placement', 'الموضع'], ['Products', 'المنتجات'], ['Status', 'الحالة']],
  rows: [
    [['New', 'جديد'], '#0B3B63', ['Card corner', 'زاوية الكارت'], '48', P('Active', 'نشط', 'green')],
    [['Clearance', 'تصفية'], '#9A3A32', ['Card corner', 'زاوية الكارت'], '12', P('Active', 'نشط', 'green')],
    [['Best seller', 'الأكثر مبيعاً'], '#8A5130', ['Under price', 'أسفل السعر'], '30', P('Active', 'نشط', 'green')],
    [['Special order', 'طلب خاص'], '#64737C', ['Under title', 'أسفل العنوان'], '6', P('Hidden', 'مخفي', 'grey')]
  ]
},
'Variations': {
  kind: 'table', add: ['Add attribute', 'إضافة خاصية'],
  cols: [['Attribute', 'الخاصية'], ['Type', 'النوع'], ['Values', 'القيم'], ['Used by', 'مستخدم في'], ['Affects', 'يؤثر على']],
  rows: [
    [['Measurement', 'القياس'], ['List', 'قائمة'], '250 / 300 / 350 / 400 / 450 / 500 mm', ['214 products', '٢١٤ منتجاً'], ['Price and stock', 'السعر والمخزون']],
    [['Finish', 'التشطيب'], ['Swatch', 'درجة لون'], ['Silver · Black · Brass · White', 'فضي · أسود · نحاس · أبيض'], ['186 products', '١٨٦ منتجاً'], ['Price and stock', 'السعر والمخزون']],
    [['Opening angle', 'زاوية الفتح'], ['List', 'قائمة'], '95° / 110° / 165°', ['168 products', '١٦٨ منتجاً'], ['Stock only', 'المخزون فقط']],
    [['Load rating', 'قدرة التحميل'], ['List', 'قائمة'], '25 / 35 / 45 kg', ['92 products', '٩٢ منتجاً'], ['Price and stock', 'السعر والمخزون']]
  ]
},
'Colours': {
  kind: 'table', add: ['Add colour', 'إضافة لون'],
  cols: [['Colour', 'اللون'], ['Code', 'الرمز'], ['Hex', 'القيمة'], ['Variants', 'التركيبات'], ['Status', 'الحالة']],
  rows: [
    [['Brushed silver', 'فضي مصنفر'], 'SLV-01', '#C9CDD1', '412', P('Active', 'نشط', 'green')],
    [['Matte black', 'أسود مطفي'], 'BLK-02', '#1D1F20', '388', P('Active', 'نشط', 'green')],
    [['Brushed brass', 'نحاس مصنفر'], 'BRS-03', '#B08D57', '164', P('Active', 'نشط', 'green')],
    [['Pure white', 'أبيض نقي'], 'WHT-04', '#F7F8F9', '96', P('Active', 'نشط', 'green')],
    [['Antique bronze', 'برونز عتيق'], 'BRZ-05', '#6B4326', '18', P('Hidden', 'مخفي', 'grey')]
  ]
},
'Warranty': {
  kind: 'table', add: ['Add warranty', 'إضافة ضمان'],
  cols: [['Warranty', 'الضمان'], ['Period', 'المدة'], ['Covers', 'يغطي'], ['Products', 'المنتجات'], ['Status', 'الحالة']],
  rows: [
    [['Tallsen mechanism', 'ميكانيزم تالسن'], ['10 years', '١٠ سنوات'], ['Mechanism failure', 'عطل الميكانيزم'], '240', P('Active', 'نشط', 'green')],
    [['Standard hardware', 'تجهيزات قياسية'], ['2 years', 'سنتان'], ['Manufacturing defects', 'عيوب الصناعة'], '402', P('Active', 'نشط', 'green')],
    [['Lighting', 'الإضاءة'], ['1 year', 'سنة'], ['Driver and strip', 'المحول والشريط'], '94', P('Active', 'نشط', 'green')],
    [['Clearance items', 'أصناف التصفية'], ['None', 'لا يوجد'], ['—', '—'], '12', P('Active', 'نشط', 'green')]
  ]
},
'Smart bar': {
  kind: 'form', save: ['Save smart bar', 'حفظ الشريط'],
  secs: [
    { t: ['Content', 'المحتوى'], f: [
      { l: ['Message (English)', 'النص (إنجليزي)'], k: 'text', v: 'Free delivery on orders above 500 SAR' },
      { l: ['Message (Arabic)', 'النص (عربي)'], k: 'text', v: 'توصيل مجاني للطلبات فوق ٥٠٠ ريال' },
      { l: ['Link', 'الرابط'], k: 'text', v: '/categories/slides' },
      { l: ['Icon', 'الأيقونة'], k: 'sel', v: 0, o: [['Delivery truck', 'شاحنة توصيل'], ['Discount tag', 'شارة خصم'], ['None', 'بدون']] }
    ] },
    { t: ['Placement and behaviour', 'الموضع والسلوك'], f: [
      { l: ['Position', 'الموضع'], k: 'sel', v: 0, o: [['Above the header', 'أعلى الهيدر'], ['Above the listing', 'أعلى القائمة'], ['Product page only', 'صفحة المنتج فقط']] },
      { l: ['Stores', 'المتاجر'], k: 'sel', v: 0, o: [['All stores', 'كل المتاجر'], ['Saudi Arabia', 'السعودية'], ['Egypt', 'مصر']] },
      { l: ['Dismissible by the customer', 'يمكن للعميل إغلاقه'], k: 'tog', v: true },
      { l: ['Show to company accounts', 'إظهاره لحسابات الشركات'], k: 'tog', v: false }
    ] }
  ]
},
'Product apps': {
  kind: 'table', add: ['Add add-on', 'إضافة خدمة'],
  cols: [['Add-on', 'الخدمة'], ['Price', 'السعر'], ['Attached to', 'مرتبطة بـ'], ['Fulfilled by', 'ينفذها'], ['Status', 'الحالة']],
  rows: [
    [['Installation service', 'خدمة التركيب'], '150.00 SAR', ['Wardrobe organizers', 'منظمات الخزائن'], ['Partner technician', 'فني شريك'], P('Active', 'نشط', 'green')],
    [['Cut to size', 'قص حسب القياس'], '35.00 SAR', ['Slides and runners', 'المجاري والسحّابات'], ['Workshop', 'الورشة'], P('Active', 'نشط', 'green')],
    [['Extended warranty', 'تمديد الضمان'], '80.00 SAR', ['Lighting', 'الإضاءة'], ['TouchWood', 'تَتْش وود'], P('Active', 'نشط', 'green')],
    [['Gift wrapping', 'تغليف هدايا'], '20.00 SAR', ['Handles', 'المقابض'], ['Warehouse', 'المستودع'], P('Hidden', 'مخفي', 'grey')]
  ]
},

/* ------------------------------------------------------------------- notes */
'Add a note': {
  kind: 'form', save: ['Save note', 'حفظ الملحوظة'],
  secs: [
    { t: ['Note', 'الملحوظة'], f: [
      { l: ['Attached to', 'مرتبطة بـ'], k: 'sel', v: 0, o: [['Product', 'منتج'], ['Order', 'طلب'], ['Company account', 'حساب شركة'], ['Customer', 'عميل']] },
      { l: ['Record', 'السجل'], k: 'text', v: 'TW-SLD-4502' },
      { l: ['Subject', 'الموضوع'], k: 'text', v: 'Supplier lead time changed' },
      { l: ['Note', 'النص'], k: 'area', v: 'Tallsen confirmed 450mm runners now ship in 18 days instead of 12. Adjust restock thresholds before the next purchase order.' }
    ] },
    { t: ['Visibility', 'الظهور'], f: [
      { l: ['Visible to', 'ظاهرة لـ'], k: 'sel', v: 0, o: [['All staff', 'كل الفريق'], ['Catalog managers', 'مديري الكتالوج'], ['Owner only', 'المالك فقط']] },
      { l: ['Pin to the record', 'تثبيتها على السجل'], k: 'tog', v: true },
      { l: ['Notify the assignee', 'إشعار المسؤول'], k: 'tog', v: true }
    ] }
  ]
},
'Notes list': {
  kind: 'table', add: ['Add a note', 'أضف ملحوظة'],
  cols: [['Subject', 'الموضوع'], ['Attached to', 'مرتبطة بـ'], ['Author', 'الكاتب'], ['Date', 'التاريخ'], ['Visibility', 'الظهور']],
  rows: [
    [['Supplier lead time changed', 'تغير مدة توريد المورد'], 'TW-SLD-4502', 'Sara Al-Amri', '14 Sep 2026', P('All staff', 'كل الفريق', 'blue')],
    [['Consolidate shipping to Sunday', 'توحيد الشحن ليوم الأحد'], 'TW-10427', 'Omar Bassam', '14 Sep 2026', P('All staff', 'كل الفريق', 'blue')],
    [['Documents need re-upload', 'المستندات تحتاج إعادة رفع'], ['Al-Nakheel Joinery', 'نجارة النخيل'], 'Layla Nasser', '13 Sep 2026', P('Owner only', 'المالك فقط', 'amber')],
    [['Price review after tariff', 'مراجعة السعر بعد الرسوم'], ['Hinges', 'المفصلات'], 'Sara Al-Amri', '11 Sep 2026', P('Catalog', 'الكتالوج', 'grey')]
  ]
},

/* --------------------------------------------------------------- wholesale */
'Add wholesale product': {
  kind: 'form', save: ['Publish wholesale product', 'نشر منتج الجملة'],
  secs: [
    { t: ['Product', 'المنتج'], f: [
      { l: ['Name (English)', 'الاسم (إنجليزي)'], k: 'text', v: 'Cabinet fixing kit, 200 pcs' },
      { l: ['Name (Arabic)', 'الاسم (عربي)'], k: 'text', v: 'طقم تثبيت خزائن، ٢٠٠ قطعة' },
      { l: ['SKU', 'الرمز'], k: 'text', v: 'TW-FIX-0200' },
      { l: ['Category', 'القسم'], k: 'sel', v: 0, o: [['Fittings and fixings', 'التجهيزات والتثبيت'], ['Slides and runners', 'المجاري'], ['Hinges', 'المفصلات']] }
    ] },
    { t: ['Volume pricing', 'تسعير الكميات'], f: [
      { l: ['Unit price', 'سعر الوحدة'], k: 'num', v: '76.00' },
      { l: ['Minimum quantity', 'الحد الأدنى'], k: 'num', v: '20' },
      { l: ['Tier 2 — from 100 units', 'الشريحة ٢ — من ١٠٠ وحدة'], k: 'num', v: '68.00' },
      { l: ['Tier 3 — from 500 units', 'الشريحة ٣ — من ٥٠٠ وحدة'], k: 'num', v: '61.00' }
    ] },
    { t: ['Eligibility', 'الأهلية'], f: [
      { l: ['Approved companies only', 'الشركات المعتمدة فقط'], k: 'tog', v: true },
      { l: ['Show price to guests', 'إظهار السعر للزوار'], k: 'tog', v: false },
      { l: ['Allow mixed retail purchase', 'السماح بالشراء بالتجزئة'], k: 'tog', v: false }
    ] }
  ]
},
'All wholesale products': {
  kind: 'table', add: ['Add wholesale product', 'إضافة منتج جملة'],
  cols: [['Product', 'المنتج'], ['SKU', 'الرمز'], ['Unit price', 'سعر الوحدة'], ['Min qty', 'الحد الأدنى'], ['Tiers', 'الشرائح'], ['Status', 'الحالة']],
  rows: [
    [['Cabinet fixing kit, 200 pcs', 'طقم تثبيت خزائن، ٢٠٠ قطعة'], 'TW-FIX-0200', '76.00', '20', '3', P('Published', 'منشور', 'green')],
    [['Tandem concealed runner', 'مجرى تاندم مخفي'], 'TW-TDM-2200', '148.00', '10', '2', P('Published', 'منشور', 'green')],
    [['Soft-close hinge, bulk', 'مفصلة إغلاق ناعم، جملة'], 'TW-HNG-3401', '21.50', '100', '3', P('Published', 'منشور', 'green')],
    [['LED strip, 50m reel', 'شريط LED، بكرة ٥٠م'], 'TW-LED-5000', '84.00', '5', '2', P('Draft', 'مسودة', 'grey')]
  ]
},

/* ------------------------------------------------------------------- sales */
'Store orders': {
  kind: 'table',
  cols: [['Order', 'الطلب'], ['Customer', 'العميل'], ['Channel', 'القناة'], ['Payment', 'الدفع'], ['Total', 'الإجمالي'], ['Status', 'الحالة']],
  rows: [
    ['TW-10428', 'Fahad Al-Otaibi', ['Web store', 'المتجر الإلكتروني'], P('Paid', 'مدفوع', 'green'), '1,337.45 SAR', P('New', 'جديد', 'blue')],
    ['TW-10426', 'Noura Al-Harbi', ['Web store', 'المتجر الإلكتروني'], P('Paid', 'مدفوع', 'green'), '255.00 SAR', P('Shipped', 'تم الشحن', 'green')],
    ['TW-10424', 'Abdulaziz Al-Shammari', ['Web store', 'المتجر الإلكتروني'], P('Paid', 'مدفوع', 'green'), '1,928.00 SAR', P('Delivered', 'تم التسليم', 'grey')],
    ['TW-10419', 'Mishal Al-Qahtani', ['Mobile app', 'التطبيق'], P('Paid', 'مدفوع', 'green'), '640.00 SAR', P('Delivered', 'تم التسليم', 'grey')]
  ]
},
'Warehouse pickup': {
  kind: 'table',
  cols: [['Order', 'الطلب'], ['Customer', 'العميل'], ['Warehouse', 'المستودع'], ['Ready since', 'جاهز منذ'], ['Collection code', 'رمز الاستلام'], ['Status', 'الحالة']],
  rows: [
    ['TW-10425', ['Cairo Furniture Co.', 'شركة أثاث القاهرة'], ['Cairo 3PL', 'مشغل القاهرة'], '13 Sep 2026', 'PU-4412', P('Awaiting pickup', 'بانتظار الاستلام', 'amber')],
    ['TW-10421', ['Jeddah Cabinet Studio', 'استوديو خزائن جدة'], ['Jeddah branch', 'فرع جدة'], '12 Sep 2026', 'PU-4408', P('Collected', 'تم الاستلام', 'green')],
    ['TW-10417', ['Riyadh Kitchen Works', 'أعمال مطابخ الرياض'], ['Riyadh main', 'الرياض الرئيسي'], '10 Sep 2026', 'PU-4396', P('Collected', 'تم الاستلام', 'green')],
    ['TW-10412', ['Al-Nakheel Joinery', 'نجارة النخيل'], ['Riyadh main', 'الرياض الرئيسي'], '06 Sep 2026', 'PU-4381', P('Not collected', 'لم يُستلم', 'red')]
  ]
},
'Unpaid orders': {
  kind: 'table',
  cols: [['Order', 'الطلب'], ['Account', 'الحساب'], ['Terms', 'السداد'], ['Due', 'الاستحقاق'], ['Amount', 'المبلغ'], ['Status', 'الحالة']],
  rows: [
    ['TW-10427', ['Riyadh Kitchen Works', 'أعمال مطابخ الرياض'], 'Net 30', '14 Oct 2026', '38,910.00 SAR', P('On terms', 'سداد آجل', 'blue')],
    ['TW-10423', ['Gulf Interiors LLC', 'الخليج للتصميم الداخلي'], 'Net 30', '12 Oct 2026', '17,510.00 AED', P('On terms', 'سداد آجل', 'blue')],
    ['TW-10422', 'Salem Al-Dosari', ['Card', 'بطاقة'], '12 Sep 2026', '660.00 SAR', P('Payment failed', 'فشل الدفع', 'red')],
    ['TW-10409', ['Madinah Storage Systems', 'أنظمة تخزين المدينة'], ['Bank transfer', 'تحويل بنكي'], '08 Sep 2026', '9,420.00 SAR', P('Overdue', 'متأخر', 'red')]
  ]
},

/* --------------------------------------------------------------- customers */
'Favourited products': {
  kind: 'table',
  cols: [['Product', 'المنتج'], ['SKU', 'الرمز'], ['Favourites', 'الإضافات'], ['Added to cart', 'أُضيف للسلة'], ['Conversion', 'التحويل']],
  rows: [
    [['Full-extension slide 450mm', 'مجرى سحاب كامل ٤٥٠مم'], 'TW-SLD-4502', '1,204', '486', '40%'],
    [['Hydraulic soft-close hinge', 'مفصلة هيدروليك بإغلاق ناعم'], 'TW-HNG-3401', '980', '402', '41%'],
    [['Wardrobe pull-out organizer', 'منظم خزانة قابل للسحب'], 'TW-ORG-7710', '742', '164', '22%'],
    [['Brushed brass bar handle', 'مقبض قضيب نحاس مصنفر'], 'TW-HDL-1608', '610', '288', '47%']
  ]
},
'Loyalty packages': {
  kind: 'table', add: ['Add package', 'إضافة باقة'],
  cols: [['Package', 'الباقة'], ['Entry', 'الدخول'], ['Earn rate', 'نسبة النقاط'], ['Reward', 'المكافأة'], ['Members', 'الأعضاء'], ['Status', 'الحالة']],
  rows: [
    [['Silver', 'الفضية'], ['0 SAR', '٠ ريال'], '1%', ['Free delivery above 300', 'توصيل مجاني فوق ٣٠٠'], '3,180', P('Active', 'نشط', 'green')],
    [['Gold', 'الذهبية'], ['5,000 SAR', '٥٬٠٠٠ ريال'], '2%', ['Free delivery, early access', 'توصيل مجاني ووصول مبكر'], '812', P('Active', 'نشط', 'green')],
    [['Contractor', 'المقاولين'], ['20,000 SAR', '٢٠٬٠٠٠ ريال'], '3%', ['Wholesale pricing', 'تسعير الجملة'], '164', P('Active', 'نشط', 'green')],
    [['Founding customers', 'عملاء التأسيس'], ['Invitation', 'بالدعوة'], '4%', ['Dedicated account manager', 'مدير حساب مخصص'], '26', P('Closed', 'مغلق', 'grey')]
  ]
},

/* ----------------------------------------------------------------- library */
'Media files': {
  kind: 'table', add: ['Upload files', 'رفع ملفات'],
  cols: [['File', 'الملف'], ['Type', 'النوع'], ['Size', 'الحجم'], ['Used in', 'مستخدم في'], ['Uploaded', 'تاريخ الرفع']],
  rows: [
    ['slide-450-silver-01.jpg', ['Product photo', 'صورة منتج'], '1.8 MB', ['TW-SLD-4502', 'TW-SLD-4502'], '14 Sep 2026'],
    ['tallsen-catalog-2026.pdf', ['Document', 'مستند'], '14.2 MB', ['Brand page', 'صفحة الماركة'], '12 Sep 2026'],
    ['ramadan-hero-ar.png', ['Campaign artwork', 'عمل حملة'], '2.4 MB', ['Ramadan campaign', 'حملة رمضان'], '10 Sep 2026'],
    ['cr-4030118552.pdf', ['Company document', 'مستند شركة'], '1.2 MB', ['Jeddah Cabinet Studio', 'استوديو خزائن جدة'], '13 Sep 2026']
  ]
},

/* ----------------------------------------------------------------- reports */
'Profit report': {
  kind: 'report',
  stats: [
    { l: ['Revenue', 'الإيرادات'], v: '482,910 SAR', d: '+12.4%' },
    { l: ['Cost of goods', 'كلفة المبيعات'], v: '311,640 SAR', d: '+9.1%' },
    { l: ['Gross profit', 'الربح الإجمالي'], v: '171,270 SAR', d: '+18.2%' },
    { l: ['Margin', 'الهامش'], v: '35.5%', d: '+1.8pt' }
  ],
  cols: [['Category', 'القسم'], ['Revenue', 'الإيرادات'], ['Cost', 'الكلفة'], ['Profit', 'الربح'], ['Margin', 'الهامش']],
  rows: [
    [['Slides and runners', 'المجاري والسحّابات'], '164,190', '104,080', '60,110', '36.6%'],
    [['Hinges', 'المفصلات'], '112,400', '73,060', '39,340', '35.0%'],
    [['Handles', 'المقابض'], '88,620', '54,140', '34,480', '38.9%'],
    [['Lighting', 'الإضاءة'], '62,310', '43,420', '18,890', '30.3%'],
    [['Organizers', 'المنظمات'], '55,390', '36,940', '18,450', '33.3%']
  ]
},
'Store product sales': {
  kind: 'report',
  stats: [
    { l: ['Units sold', 'الوحدات المبيعة'], v: '18,402', d: '+7.4%' },
    { l: ['Orders', 'الطلبات'], v: '1,284', d: '+6.1%' },
    { l: ['Units per order', 'وحدات لكل طلب'], v: '14.3', d: '+1.2%' },
    { l: ['Returns', 'المرتجع'], v: '1.8%', d: '−0.4pt' }
  ],
  cols: [['Product', 'المنتج'], ['SKU', 'الرمز'], ['Units', 'الوحدات'], ['Value', 'القيمة'], ['Share', 'النسبة']],
  rows: [
    [['Hydraulic soft-close hinge', 'مفصلة هيدروليك بإغلاق ناعم'], 'TW-HNG-3401', '6,240', '134,160 SAR', '27.8%'],
    [['Full-extension slide 450mm', 'مجرى سحاب كامل ٤٥٠مم'], 'TW-SLD-4502', '2,880', '178,560 SAR', '37.0%'],
    [['Brushed brass bar handle', 'مقبض قضيب نحاس مصنفر'], 'TW-HDL-1608', '2,140', '98,440 SAR', '20.4%'],
    [['Under-cabinet LED strip', 'شريط إضاءة أسفل الخزائن'], 'TW-LED-5000', '1,460', '122,640 SAR', '25.4%']
  ]
},
'Favourites report': {
  kind: 'table',
  cols: [['Product', 'المنتج'], ['Favourites', 'الإضافات'], ['Period change', 'التغير'], ['In stock', 'متوفر'], ['Price', 'السعر']],
  rows: [
    [['Full-extension slide 450mm', 'مجرى سحاب كامل ٤٥٠مم'], '1,204', '+184', P('Yes', 'نعم', 'green'), '89.00 SAR'],
    [['Hydraulic soft-close hinge', 'مفصلة هيدروليك بإغلاق ناعم'], '980', '+96', P('Yes', 'نعم', 'green'), '32.00 SAR'],
    [['Wardrobe pull-out organizer', 'منظم خزانة قابل للسحب'], '742', '+210', P('Out of stock', 'نفد', 'red'), '410.00 SAR'],
    [['Gas piston lift support', 'مكبس غاز مساند'], '318', '−24', P('Low', 'منخفض', 'amber'), '58.00 SAR']
  ]
},
'Customer searches': {
  kind: 'report',
  stats: [
    { l: ['Searches', 'عمليات البحث'], v: '42,180', d: '+14.2%' },
    { l: ['No results', 'بلا نتائج'], v: '6.4%', d: '−1.1pt' },
    { l: ['Search to cart', 'بحث إلى سلة'], v: '11.8%', d: '+2.2pt' },
    { l: ['Top term share', 'نسبة أعلى كلمة'], v: '4.9%', d: '+0.3pt' }
  ],
  cols: [['Term', 'الكلمة'], ['Searches', 'المرات'], ['Results', 'النتائج'], ['Clicked', 'نُقر عليها'], ['Outcome', 'النتيجة']],
  rows: [
    [['soft close hinge', 'مفصلة اغلاق ناعم'], '2,064', '48', '71%', P('Good', 'جيد', 'green')],
    [['450 slide', 'مجرى ٤٥٠'], '1,810', '22', '68%', P('Good', 'جيد', 'green')],
    [['tallsen catalogue', 'كتالوج تالسن'], '940', '4', '38%', P('Thin', 'ضعيف', 'amber')],
    [['kitchen tap', 'خلاط مطبخ'], '612', '0', '0%', P('No results', 'بلا نتائج', 'red')],
    [['wardrobe lift', 'رافعة خزانة'], '388', '2', '24%', P('Thin', 'ضعيف', 'amber')]
  ]
},
'Wallet top-up history': {
  kind: 'table',
  cols: [['Reference', 'المرجع'], ['Customer', 'العميل'], ['Method', 'الوسيلة'], ['Amount', 'المبلغ'], ['Date', 'التاريخ'], ['Status', 'الحالة']],
  rows: [
    ['WL-8841', 'Mishal Al-Qahtani', ['Bank transfer', 'تحويل بنكي'], '20,000.00 SAR', '14 Sep 2026', P('Credited', 'تم الإيداع', 'green')],
    ['WL-8840', 'Fahad Al-Otaibi', ['Mada card', 'بطاقة مدى'], '1,500.00 SAR', '13 Sep 2026', P('Credited', 'تم الإيداع', 'green')],
    ['WL-8839', ['Gulf Interiors LLC', 'الخليج للتصميم الداخلي'], ['Bank transfer', 'تحويل بنكي'], '30,000.00 AED', '12 Sep 2026', P('Under review', 'قيد المراجعة', 'amber')],
    ['WL-8838', 'Salem Al-Dosari', ['Mada card', 'بطاقة مدى'], '400.00 SAR', '11 Sep 2026', P('Failed', 'فاشل', 'red')]
  ]
},

/* -------------------------------------------------------------------- blog */
'All posts': {
  kind: 'table', add: ['Write a post', 'كتابة مقال'],
  cols: [['Title', 'العنوان'], ['Category', 'التصنيف'], ['Author', 'الكاتب'], ['Date', 'التاريخ'], ['Status', 'الحالة']],
  rows: [
    [['Choosing the right drawer runner', 'كيف تختار مجرى الأدراج المناسب'], ['Guides', 'أدلة'], 'Sara Al-Amri', '12 Sep 2026', P('Published', 'منشور', 'green')],
    [['Soft-close hinges explained', 'شرح مفصلات الإغلاق الناعم'], ['Guides', 'أدلة'], 'Sara Al-Amri', '05 Sep 2026', P('Published', 'منشور', 'green')],
    [['Kitchen lighting that lasts', 'إضاءة مطبخ تدوم'], ['Projects', 'مشاريع'], 'Khalid Aziz', '28 Aug 2026', P('Published', 'منشور', 'green')],
    [['Wholesale ordering for contractors', 'الشراء بالجملة للمقاولين'], ['Business', 'أعمال'], 'Layla Nasser', '—', P('Draft', 'مسودة', 'grey')]
  ]
},
'Post categories': {
  kind: 'table', add: ['Add category', 'إضافة تصنيف'],
  cols: [['Category', 'التصنيف'], ['Handle', 'المسار'], ['Posts', 'المقالات'], ['Status', 'الحالة']],
  rows: [
    [['Guides', 'أدلة'], 'guides', '14', P('Published', 'منشور', 'green')],
    [['Projects', 'مشاريع'], 'projects', '8', P('Published', 'منشور', 'green')],
    [['Business', 'أعمال'], 'business', '5', P('Published', 'منشور', 'green')],
    [['News', 'أخبار'], 'news', '2', P('Hidden', 'مخفي', 'grey')]
  ]
},

/* --------------------------------------------------------------- marketing */
'Pop-up offers': {
  kind: 'form', save: ['Save pop-up', 'حفظ النافذة'],
  secs: [
    { t: ['Content', 'المحتوى'], f: [
      { l: ['Headline (English)', 'العنوان (إنجليزي)'], k: 'text', v: 'Ramadan offers are live' },
      { l: ['Headline (Arabic)', 'العنوان (عربي)'], k: 'text', v: 'عروض رمضان متاحة الآن' },
      { l: ['Body', 'النص'], k: 'area', v: 'Up to 30% off kitchen fittings until 28 September.' },
      { l: ['Button label', 'نص الزر'], k: 'text', v: 'Shop the offers' },
      { l: ['Destination', 'الوجهة'], k: 'text', v: '/campaigns/ramadan' }
    ] },
    { t: ['Trigger', 'التفعيل'], f: [
      { l: ['Show after', 'يظهر بعد'], k: 'sel', v: 1, o: [['Immediately', 'فوراً'], ['5 seconds', '٥ ثوانٍ'], ['50% scroll', '٥٠٪ تمرير'], ['Exit intent', 'نية المغادرة']] },
      { l: ['Frequency', 'التكرار'], k: 'sel', v: 0, o: [['Once per visitor', 'مرة لكل زائر'], ['Once per session', 'مرة لكل جلسة'], ['Every visit', 'كل زيارة']] },
      { l: ['Show to guests', 'إظهارها للزوار'], k: 'tog', v: true },
      { l: ['Show to company accounts', 'إظهارها لحسابات الشركات'], k: 'tog', v: false }
    ] }
  ]
},
'Alert offers': {
  kind: 'table', add: ['Add alert', 'إضافة تنبيه'],
  cols: [['Alert', 'التنبيه'], ['Placement', 'الموضع'], ['Window', 'الفترة'], ['Impressions', 'الظهور'], ['Clicks', 'النقرات'], ['Status', 'الحالة']],
  rows: [
    [['Free delivery above 500', 'توصيل مجاني فوق ٥٠٠'], ['Announcement bar', 'شريط الإعلان'], '01–30 Sep', '84,120', '3,180', P('Active', 'نشط', 'green')],
    [['Ramadan 30% off', 'رمضان خصم ٣٠٪'], ['Listing banner', 'بانر القائمة'], '20–28 Sep', '12,400', '910', P('Scheduled', 'مجدول', 'blue')],
    [['Last units — organizers', 'آخر الكميات — المنظمات'], ['Product page', 'صفحة المنتج'], '10–14 Sep', '6,240', '420', P('Ended', 'منتهي', 'grey')],
    [['Wholesale minimums updated', 'تحديث حدود الجملة'], ['Cart', 'السلة'], '01–30 Sep', '2,180', '160', P('Active', 'نشط', 'green')]
  ]
},
'Custom sell alert': {
  kind: 'form', save: ['Save rule', 'حفظ القاعدة'],
  secs: [
    { t: ['Rule', 'القاعدة'], f: [
      { l: ['Rule name', 'اسم القاعدة'], k: 'text', v: 'Cart above 2,000 — offer wholesale' },
      { l: ['Trigger', 'المُفعّل'], k: 'sel', v: 0, o: [['Cart value threshold', 'حد قيمة السلة'], ['Quantity threshold', 'حد الكمية'], ['Repeat visit', 'زيارة متكررة'], ['Abandoned cart', 'سلة متروكة']] },
      { l: ['Threshold', 'الحد'], k: 'num', v: '2000' },
      { l: ['Message', 'الرسالة'], k: 'area', v: 'You are close to wholesale pricing. Company accounts save 30% on this cart.' }
    ] },
    { t: ['Delivery', 'الإيصال'], f: [
      { l: ['Channel', 'القناة'], k: 'sel', v: 0, o: [['In-cart notice', 'تنبيه داخل السلة'], ['Email', 'بريد إلكتروني'], ['Both', 'الاثنان']] },
      { l: ['Audience', 'الجمهور'], k: 'sel', v: 0, o: [['Guests and individuals', 'الزوار والأفراد'], ['Individuals only', 'الأفراد فقط'], ['Pending companies', 'الشركات المعلقة']] },
      { l: ['Rule active', 'القاعدة نشطة'], k: 'tog', v: true },
      { l: ['Stop after conversion', 'التوقف بعد التحويل'], k: 'tog', v: true }
    ] }
  ]
},
'Newsletters': {
  kind: 'table', add: ['New newsletter', 'نشرة جديدة'],
  cols: [['Newsletter', 'النشرة'], ['Audience', 'الجمهور'], ['Sent', 'أُرسلت'], ['Opened', 'فُتحت'], ['Clicked', 'نُقرت'], ['Status', 'الحالة']],
  rows: [
    [['Ramadan offers', 'عروض رمضان'], ['All subscribers', 'كل المشتركين'], '18,240', '42%', '11%', P('Sent', 'أُرسلت', 'green')],
    [['New Tallsen arrivals', 'وصل تالسن الجديد'], ['Contractors', 'المقاولون'], '1,180', '58%', '22%', P('Sent', 'أُرسلت', 'green')],
    [['September wholesale list', 'قائمة جملة سبتمبر'], ['Companies', 'الشركات'], '412', '61%', '28%', P('Sent', 'أُرسلت', 'green')],
    [['Founding Day preview', 'معاينة يوم التأسيس'], ['Gold members', 'أعضاء الذهبية'], '—', '—', '—', P('Draft', 'مسودة', 'grey')]
  ]
},
'Subscribers': {
  kind: 'table', add: ['Import list', 'استيراد قائمة'],
  cols: [['Email', 'البريد'], ['Name', 'الاسم'], ['Source', 'المصدر'], ['Segment', 'الشريحة'], ['Joined', 'الانضمام'], ['Status', 'الحالة']],
  rows: [
    ['f.alotaibi@gmail.com', 'Fahad Al-Otaibi', ['Checkout', 'الدفع'], ['Individuals', 'الأفراد'], '14 Sep 2026', P('Subscribed', 'مشترك', 'green')],
    ['purchasing@rkw.com.sa', 'Mishal Al-Qahtani', ['Company signup', 'تسجيل شركة'], ['Companies', 'الشركات'], '12 Sep 2026', P('Subscribed', 'مشترك', 'green')],
    ['noura.h@outlook.com', 'Noura Al-Harbi', ['Footer form', 'نموذج التذييل'], ['Individuals', 'الأفراد'], '11 Sep 2026', P('Subscribed', 'مشترك', 'green')],
    ['s.dosari@icloud.com', 'Salem Al-Dosari', ['Footer form', 'نموذج التذييل'], ['Individuals', 'الأفراد'], '02 Sep 2026', P('Unsubscribed', 'ألغى الاشتراك', 'grey')]
  ]
},
'Coupons': {
  kind: 'table', add: ['Create coupon', 'إنشاء كوبون'],
  cols: [['Code', 'الكود'], ['Value', 'القيمة'], ['Conditions', 'الشروط'], ['Used', 'الاستخدام'], ['Expires', 'ينتهي'], ['Status', 'الحالة']],
  rows: [
    ['RAMADAN30', '30%', ['Cart above 500 SAR', 'سلة فوق ٥٠٠ ريال'], '412 / 1,000', '28 Sep 2026', P('Active', 'نشط', 'green')],
    ['FIRST15', '15 SAR', ['First order only', 'أول طلب فقط'], '1,840 / ∞', '31 Dec 2026', P('Active', 'نشط', 'green')],
    ['CONTRACTOR5', '5%', ['Approved companies', 'الشركات المعتمدة'], '96 / 500', '31 Oct 2026', P('Active', 'نشط', 'green')],
    ['SUMMER20', '20%', ['Lighting category', 'قسم الإضاءة'], '780 / 800', '31 Aug 2026', P('Expired', 'منتهي', 'grey')]
  ]
},
'Custom visitors': {
  kind: 'table', add: ['Create segment', 'إنشاء شريحة'],
  cols: [['Segment', 'الشريحة'], ['Built from', 'مبنية على'], ['Visitors', 'الزوار'], ['Offer', 'العرض'], ['Status', 'الحالة']],
  rows: [
    [['Repeat viewers — slides', 'مشاهدون متكررون — المجاري'], ['3+ views, no purchase', '٣ مشاهدات بلا شراء'], '4,180', ['Alert offer', 'عرض تنبيهي'], P('Active', 'نشط', 'green')],
    [['Abandoned wholesale carts', 'سلات جملة متروكة'], ['Cart above 5,000 SAR', 'سلة فوق ٥٬٠٠٠ ريال'], '312', ['Custom sell alert', 'تنبيه بيع مخصص'], P('Active', 'نشط', 'green')],
    [['Guests from Jeddah', 'زوار من جدة'], ['City, first visit', 'المدينة، أول زيارة'], '9,640', ['Pop-up offer', 'نافذة عرض'], P('Paused', 'موقوف', 'amber')],
    [['Pending companies', 'شركات معلقة'], ['Application submitted', 'قدمت الطلب'], '4', ['Email', 'بريد'], P('Active', 'نشط', 'green')]
  ]
},

/* ----------------------------------------------------------------- support */
'Tickets': {
  kind: 'table', add: ['New ticket', 'تذكرة جديدة'],
  cols: [['Ticket', 'التذكرة'], ['Subject', 'الموضوع'], ['Customer', 'العميل'], ['Assignee', 'المسؤول'], ['Opened', 'فُتحت'], ['Status', 'الحالة']],
  rows: [
    ['TK-2841', [ 'Missing slide from order', 'مجرى ناقص في الطلب'], 'Fahad Al-Otaibi', 'Khalid Aziz', '14 Sep 2026', P('Open', 'مفتوحة', 'blue')],
    ['TK-2840', ['Wholesale price query', 'استفسار سعر جملة'], ['Gulf Interiors LLC', 'الخليج للتصميم الداخلي'], 'Layla Nasser', '13 Sep 2026', P('Waiting on customer', 'بانتظار العميل', 'amber')],
    ['TK-2839', ['Hinge warranty claim', 'مطالبة ضمان مفصلة'], 'Abdulaziz Al-Shammari', 'Khalid Aziz', '12 Sep 2026', P('Resolved', 'محلولة', 'green')],
    ['TK-2838', ['Invoice tax number', 'الرقم الضريبي بالفاتورة'], ['Cairo Furniture Co.', 'شركة أثاث القاهرة'], 'Omar Bassam', '11 Sep 2026', P('Resolved', 'محلولة', 'green')]
  ]
},
'Product chats': {
  kind: 'table',
  cols: [['Customer', 'العميل'], ['Product', 'المنتج'], ['Last message', 'آخر رسالة'], ['Waiting', 'الانتظار'], ['Status', 'الحالة']],
  rows: [
    ['Noura Al-Harbi', [ 'Brushed brass handle', 'مقبض نحاس مصنفر'], ['Is 160mm in stock in Jeddah?', 'هل ١٦٠مم متوفر في جدة؟'], ['12 min', '١٢ دقيقة'], P('Unanswered', 'بلا رد', 'red')],
    ['Fahad Al-Otaibi', ['Full-extension slide', 'مجرى سحاب كامل'], ['Thanks, that helps.', 'شكراً، هذا مفيد.'], '—', P('Answered', 'تم الرد', 'green')],
    ['Turki Al-Ghamdi', ['LED strip 2m', 'شريط LED ٢م'], ['Can I get 50 metres?', 'هل يمكن ٥٠ متراً؟'], ['2 h', 'ساعتان'], P('Answered', 'تم الرد', 'green')],
    ['Amal Al-Sharif', ['Pull-out organizer', 'منظم قابل للسحب'], ['Any restock date?', 'ما تاريخ التزويد؟'], ['1 d', 'يوم'], P('Answered', 'تم الرد', 'green')]
  ]
},
'Product enquiries': {
  kind: 'table',
  cols: [['Enquiry', 'الاستفسار'], ['Product', 'المنتج'], ['From', 'من'], ['Received', 'وصل'], ['Status', 'الحالة']],
  rows: [
    [['Load rating for 500mm?', 'قدرة التحميل لـ ٥٠٠مم؟'], 'TW-SLD-4505', 'Yousef Al-Mutairi', '14 Sep 2026', P('Awaiting answer', 'بانتظار الرد', 'amber')],
    [['Is the finish salt resistant?', 'هل التشطيب مقاوم للملوحة؟'], 'TW-HDL-1608', 'Rashid Al-Suwaidi', '13 Sep 2026', P('Answered', 'تم الرد', 'green')],
    [['Bulk price for 500 hinges', 'سعر جملة ٥٠٠ مفصلة'], 'TW-HNG-3401', ['Jeddah Cabinet Studio', 'استوديو خزائن جدة'], '12 Sep 2026', P('Answered', 'تم الرد', 'green')],
    [['Does it fit 18mm board?', 'هل يناسب لوح ١٨مم؟'], 'TW-TDM-2200', 'Bader Al-Rashid', '10 Sep 2026', P('Answered', 'تم الرد', 'green')]
  ]
},
'Contact channels': {
  kind: 'form', save: ['Save channels', 'حفظ القنوات'],
  secs: [
    { t: ['Channels shown to customers', 'القنوات المعروضة للعملاء'], f: [
      { l: ['WhatsApp number', 'رقم واتساب'], k: 'text', v: '+966 55 000 1180' },
      { l: ['Support phone', 'هاتف الدعم'], k: 'text', v: '+966 11 400 2200' },
      { l: ['Support email', 'بريد الدعم'], k: 'text', v: 'support@touchwoodksa.com' },
      { l: ['Wholesale email', 'بريد الجملة'], k: 'text', v: 'b2b@touchwoodksa.com' }
    ] },
    { t: ['Availability', 'أوقات العمل'], f: [
      { l: ['Working hours', 'ساعات العمل'], k: 'text', v: 'Sun–Thu, 09:00–18:00' },
      { l: ['Show WhatsApp button in the store', 'إظهار زر واتساب في المتجر'], k: 'tog', v: true },
      { l: ['Show live chat', 'إظهار المحادثة المباشرة'], k: 'tog', v: true },
      { l: ['Auto-reply outside hours', 'رد تلقائي خارج الأوقات'], k: 'tog', v: true }
    ] }
  ]
},

/* ---------------------------------------------------------------- cashback */
'Cashback settings': {
  kind: 'form', save: ['Save cashback settings', 'حفظ إعدادات الكاش باك'],
  secs: [
    { t: ['Earning', 'الاستحقاق'], f: [
      { l: ['Earn rate', 'نسبة الاستحقاق'], k: 'num', v: '2' },
      { l: ['Points per 1 SAR', 'نقاط لكل ريال'], k: 'num', v: '1' },
      { l: ['Minimum order to earn', 'أدنى طلب للاستحقاق'], k: 'num', v: '100' },
      { l: ['Earn on wholesale orders', 'الاستحقاق على طلبات الجملة'], k: 'tog', v: false }
    ] },
    { t: ['Redemption', 'الاستبدال'], f: [
      { l: ['Minimum points to redeem', 'أدنى نقاط للاستبدال'], k: 'num', v: '500' },
      { l: ['Maximum share of an order', 'أقصى نسبة من الطلب'], k: 'num', v: '30' },
      { l: ['Points expire after', 'انتهاء النقاط بعد'], k: 'sel', v: 2, o: [['6 months', '٦ أشهر'], ['1 year', 'سنة'], ['2 years', 'سنتان'], ['Never', 'لا تنتهي']] },
      { l: ['Allow redemption with a coupon', 'السماح بالاستبدال مع كوبون'], k: 'tog', v: false }
    ] }
  ]
},
'Per-product cashback': {
  kind: 'table', add: ['Add override', 'إضافة استثناء'],
  cols: [['Applies to', 'يشمل'], ['Scope', 'النطاق'], ['Earn rate', 'النسبة'], ['Window', 'الفترة'], ['Status', 'الحالة']],
  rows: [
    [['Tallsen products', 'منتجات تالسن'], ['Brand', 'ماركة'], '4%', ['01–30 Sep', '٠١–٣٠ سبتمبر'], P('Active', 'نشط', 'green')],
    [['Lighting and electrical', 'الإضاءة والكهرباء'], ['Category', 'قسم'], '3%', ['Always', 'دائماً'], P('Active', 'نشط', 'green')],
    [['Clearance items', 'أصناف التصفية'], ['Label', 'تسمية'], '0%', ['Always', 'دائماً'], P('Active', 'نشط', 'green')],
    [['TW-ORG-7710', 'TW-ORG-7710'], ['Product', 'منتج'], '5%', ['10–20 Sep', '١٠–٢٠ سبتمبر'], P('Ended', 'منتهي', 'grey')]
  ]
},
'Point redemptions': {
  kind: 'table',
  cols: [['Order', 'الطلب'], ['Customer', 'العميل'], ['Points used', 'النقاط المستخدمة'], ['Value', 'القيمة'], ['Share of order', 'نسبة الطلب'], ['Status', 'الحالة']],
  rows: [
    ['TW-10428', 'Fahad Al-Otaibi', '1,200', '120.00 SAR', '9%', P('Applied', 'مُطبق', 'green')],
    ['TW-10424', 'Abdulaziz Al-Shammari', '3,400', '340.00 SAR', '18%', P('Applied', 'مُطبق', 'green')],
    ['TW-10422', 'Salem Al-Dosari', '600', '60.00 SAR', '9%', P('Reversed', 'ملغى', 'red')],
    ['TW-10418', 'Noura Al-Harbi', '500', '50.00 SAR', '14%', P('Applied', 'مُطبق', 'green')]
  ]
},

/* ---------------------------------------------------------------- payments */
'Bank payment system': {
  kind: 'form', save: ['Save payment settings', 'حفظ إعدادات الدفع'],
  secs: [
    { t: ['Bank account', 'الحساب البنكي'], f: [
      { l: ['Account name', 'اسم الحساب'], k: 'text', v: 'TouchWood Trading Est.' },
      { l: ['IBAN', 'الآيبان'], k: 'text', v: 'SA03 8000 0000 6080 1016 7519' },
      { l: ['Bank', 'البنك'], k: 'sel', v: 0, o: [['Al Rajhi Bank', 'مصرف الراجحي'], ['Saudi National Bank', 'البنك الأهلي'], ['Riyad Bank', 'بنك الرياض']] },
      { l: ['Store', 'المتجر'], k: 'sel', v: 0, o: [['Saudi Arabia', 'السعودية'], ['Egypt', 'مصر'], ['United Arab Emirates', 'الإمارات']] }
    ] },
    { t: ['Gateways', 'بوابات الدفع'], f: [
      { l: ['Mada', 'مدى'], k: 'tog', v: true },
      { l: ['Visa and Mastercard', 'فيزا وماستركارد'], k: 'tog', v: true },
      { l: ['Apple Pay', 'أبل باي'], k: 'tog', v: true },
      { l: ['Tamara — pay in instalments', 'تمارا — التقسيط'], k: 'tog', v: true },
      { l: ['Bank transfer', 'تحويل بنكي'], k: 'tog', v: true },
      { l: ['Cash on delivery', 'الدفع عند الاستلام'], k: 'tog', v: false }
    ] },
    { t: ['Verification', 'التحقق'], f: [
      { l: ['Hours to verify a transfer', 'ساعات التحقق من التحويل'], k: 'num', v: '24' },
      { l: ['Require the receipt image', 'طلب صورة الإيصال'], k: 'tog', v: true },
      { l: ['Hold stock while verifying', 'حجز المخزون أثناء التحقق'], k: 'tog', v: true }
    ] }
  ]
},
'Bank transfer requests': {
  kind: 'table',
  cols: [['Reference', 'المرجع'], ['Order', 'الطلب'], ['Account', 'الحساب'], ['Amount', 'المبلغ'], ['Receipt', 'الإيصال'], ['Status', 'الحالة']],
  rows: [
    ['BT-1184', 'TW-10427', ['Riyadh Kitchen Works', 'أعمال مطابخ الرياض'], '38,910.00 SAR', P('Attached', 'مرفق', 'green'), P('Awaiting review', 'بانتظار المراجعة', 'amber')],
    ['BT-1183', 'TW-10421', ['Jeddah Cabinet Studio', 'استوديو خزائن جدة'], '9,320.00 SAR', P('Attached', 'مرفق', 'green'), P('Verified', 'مُتحقق', 'green')],
    ['BT-1182', 'TW-10409', ['Madinah Storage Systems', 'أنظمة تخزين المدينة'], '9,420.00 SAR', P('Missing', 'ناقص', 'red'), P('Rejected', 'مرفوض', 'red')],
    ['BT-1181', 'TW-10404', ['Hail Kitchen Depot', 'مستودع مطابخ حائل'], '4,180.00 SAR', P('Attached', 'مرفق', 'green'), P('Verified', 'مُتحقق', 'green')]
  ]
},
'Wallet top-up requests': {
  kind: 'table',
  cols: [['Reference', 'المرجع'], ['Customer', 'العميل'], ['Method', 'الوسيلة'], ['Amount', 'المبلغ'], ['Requested', 'تاريخ الطلب'], ['Status', 'الحالة']],
  rows: [
    ['WL-8839', ['Gulf Interiors LLC', 'الخليج للتصميم الداخلي'], ['Bank transfer', 'تحويل بنكي'], '30,000.00 AED', '12 Sep 2026', P('Awaiting approval', 'بانتظار الموافقة', 'amber')],
    ['WL-8841', 'Mishal Al-Qahtani', ['Bank transfer', 'تحويل بنكي'], '20,000.00 SAR', '14 Sep 2026', P('Approved', 'موافق عليه', 'green')],
    ['WL-8840', 'Fahad Al-Otaibi', ['Mada card', 'بطاقة مدى'], '1,500.00 SAR', '13 Sep 2026', P('Approved', 'موافق عليه', 'green')],
    ['WL-8838', 'Salem Al-Dosari', ['Mada card', 'بطاقة مدى'], '400.00 SAR', '11 Sep 2026', P('Failed', 'فاشل', 'red')]
  ]
},
'Offline subscription payments': {
  kind: 'table',
  cols: [['Subscription', 'الاشتراك'], ['Account', 'الحساب'], ['Period', 'الفترة'], ['Amount', 'المبلغ'], ['Collected by', 'استلمها'], ['Status', 'الحالة']],
  rows: [
    [['Contractor plan', 'خطة المقاولين'], ['Riyadh Kitchen Works', 'أعمال مطابخ الرياض'], ['Sep 2026', 'سبتمبر ٢٠٢٦'], '2,400.00 SAR', 'Layla Nasser', P('Recorded', 'مُسجل', 'green')],
    [['Contractor plan', 'خطة المقاولين'], ['Jeddah Cabinet Studio', 'استوديو خزائن جدة'], ['Sep 2026', 'سبتمبر ٢٠٢٦'], '2,400.00 SAR', 'Layla Nasser', P('Recorded', 'مُسجل', 'green')],
    [['Showroom plan', 'خطة المعارض'], ['Gulf Interiors LLC', 'الخليج للتصميم الداخلي'], ['Q3 2026', 'الربع الثالث ٢٠٢٦'], '9,000.00 AED', 'Omar Bassam', P('Awaiting receipt', 'بانتظار الإيصال', 'amber')],
    [['Contractor plan', 'خطة المقاولين'], ['Al-Nakheel Joinery', 'نجارة النخيل'], ['Sep 2026', 'سبتمبر ٢٠٢٦'], '2,400.00 SAR', '—', P('Unpaid', 'غير مدفوع', 'red')]
  ]
},

/* ------------------------------------------------------------------- staff */
'Employee permissions': {
  kind: 'table',
  cols: [['Role', 'الدور'], ['Members', 'الأعضاء'], ['Catalog', 'الكتالوج'], ['Orders', 'الطلبات'], ['Companies', 'الشركات'], ['Settings', 'الإعدادات']],
  rows: [
    [['Owner', 'مالك'], '1', P('Full', 'كامل', 'green'), P('Full', 'كامل', 'green'), P('Full', 'كامل', 'green'), P('Full', 'كامل', 'green')],
    [['Catalog manager', 'مدير الكتالوج'], '1', P('Full', 'كامل', 'green'), P('Read', 'قراءة', 'blue'), P('None', 'لا شيء', 'grey'), P('None', 'لا شيء', 'grey')],
    [['Order fulfilment', 'تنفيذ الطلبات'], '1', P('Read', 'قراءة', 'blue'), P('Full', 'كامل', 'green'), P('None', 'لا شيء', 'grey'), P('None', 'لا شيء', 'grey')],
    [['Company accounts', 'حسابات الشركات'], '1', P('None', 'لا شيء', 'grey'), P('Read', 'قراءة', 'blue'), P('Full', 'كامل', 'green'), P('None', 'لا شيء', 'grey')],
    [['Support', 'الدعم'], '1', P('Read', 'قراءة', 'blue'), P('Comment', 'تعليق', 'amber'), P('None', 'لا شيء', 'grey'), P('None', 'لا شيء', 'grey')]
  ]
}

};
