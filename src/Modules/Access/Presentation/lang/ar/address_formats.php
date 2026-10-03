<?php

declare(strict_types=1);

// محرّر نماذج عناوين المتاجر (المرحلة ٢ب، frontend.md §3.7، قرار ٢٠٢٦-٠٩-١٩).
return [
    'title' => 'نماذج العناوين',
    'subtitle' => 'ما تطلبه كل دولة من العميل، وكيف يُطبع العنوان.',
    'intro' => 'نموذج عنوان الدولة بيانات لا إصدار. غيّره هنا فيتبع العنوان التالي المحفوظ في تلك الدولة الشكل الجديد.',

    'store' => 'الدولة',
    'no_stores' => 'لا يمكنك تغيير نموذج عنوان أي دولة.',
    'no_format' => 'لا يوجد نموذج عنوان لهذه الدولة بعد، فلا يمكن حفظ أي عنوان فيها. أضف الحقول التي تطلبها.',

    'fields' => 'الحقول',
    'fields_hint' => 'بالترتيب الذي يملؤها به العميل. بحد أقصى :count حقلًا.',
    'field_key' => 'الاسم في النظام',
    'field_key_hint' => 'حروف صغيرة وأرقام وشرطات سفلية، مثل postal_code. وهو ما يشير إليه شكل الطباعة أدناه، وتغييره في حقل استُعمل من قبل يترك عنوان صاحبه بلا ذلك الجزء.',
    'label_ar' => 'التسمية بالعربية',
    'label_en' => 'التسمية بالإنجليزية',
    // Each field's group of inputs is named, so a screen reader hears which field it is in.
    'field_number' => 'الحقل :number',
    'required' => 'مطلوب',
    'max_length' => 'أقصى طول',
    'max_length_hint' => 'بين ١ و:count محرفًا.',
    // Fields are reordered by a drag handle, by mouse, touch or keyboard (owner, 2026-10-03: shadcn's
    // dashboard-01 pattern). What a screen reader is told as a field moves, in the page's language:
    // the drag library's own words are English only.
    'reorder' => 'إعادة ترتيب :field',
    // What a screen reader calls the handle in place of "button".
    'drag_role' => 'مقبض سحب',
    'drag_instructions' => 'لنقل حقل، ركّز على مقبضه واضغط المسافة أو Enter، وحرّكه بالأسهم، ثم اضغط المسافة أو Enter لوضعه، أو Escape لإرجاعه.',
    'drag_picked' => 'رُفع :field.',
    'drag_moved' => 'نُقل :field إلى الموضع :position من :total.',
    'drag_dropped' => 'وُضع :field في الموضع :position من :total.',
    'drag_cancelled' => 'أُرجع :field إلى مكانه.',
    'remove_field' => 'إزالة الحقل',
    'add_field' => 'إضافة حقل',
    'no_fields' => 'لا حقل بعد. والنموذج يحتاج حقلًا واحدًا على الأقل.',

    'template' => 'شكل الطباعة',
    'template_hint' => 'نص عادي. اكتب ‎{city}‎ فتحل قيمة ذلك الحقل محلها؛ والحقل الفارغ يختفي، والسطر الذي يفرغ يُحذف. ولا يُنفَّذ شيء هنا كأنه برنامج.',
    'template_fields' => 'الحقول التي يمكنك استعمالها: :keys',

    'save' => 'حفظ نموذج العنوان',
    'saved' => 'تم حفظ نموذج العنوان',
    'existing_addresses' => 'العناوين المحفوظة تبقى كما هي. وما لم يعد يطابق هذا النموذج لا يصلح للطلب حتى يكمله صاحبه، وصفحة عناوينه تقول ذلك.',

    // تطلبها شاشات Geist (frontend.md 1.10): عناوين الحالات الفارغة، وأسباب الأزرار المعطّلة،
    // وكلمات النوافذ نفسها.
    'no_stores_title' => 'لا نماذج عناوين يمكنك تغييرها',
    'no_fields_title' => 'لا حقول بعد',
    'too_many_fields' => 'يحمل النموذج :count حقلًا على الأكثر.',
];
