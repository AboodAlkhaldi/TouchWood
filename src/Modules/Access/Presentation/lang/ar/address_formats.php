<?php

declare(strict_types=1);

// محرّر نماذج عناوين المتاجر (المرحلة 2ب، frontend.md §3.7، قرار 2026-09-19).
return [
    'title' => 'نماذج العناوين',
    'subtitle' => 'ما تطلبه كل دولة من العميل، وكيف يُطبع العنوان.',
    'intro' => 'غيّر نموذج عنوان الدولة هنا، فيتبعه العنوان التالي الذي يُحفظ فيها.',

    'store' => 'الدولة',
    'no_stores' => 'لا يمكنك تغيير نموذج عنوان أي دولة.',
    'no_format' => 'لا يمكن حفظ عنوان في هذه الدولة حتى تضيف الحقول التي تطلبها.',

    'fields' => 'الحقول',
    'fields_hint' => 'بالترتيب الذي يملؤها به العميل، وبحد أقصى :count حقلًا.',
    'field_key' => 'الاسم في النظام',
    'field_key_hint' => 'حروف صغيرة وأرقام وشرطات سفلية، مثل postal_code؛ وتغييره في حقل مستعمل يترك تلك العناوين بلا ذلك الجزء.',
    // Said under the box as it is typed (frontend.md §1.7), the domain's own rule (AddressField::KEY).
    'check' => [
        'key' => 'يقبل الحقل «:field» من 2 إلى 40 من الحروف اللاتينية الصغيرة والأرقام والشرطات السفلية، على أن يبدأ بحرف.',
    ],
    'label_ar' => 'التسمية بالعربية',
    'label_en' => 'التسمية بالإنجليزية',
    // Each field's group of inputs is named, so a screen reader hears which field it is in.
    'field_number' => 'الحقل :number',
    'required' => 'مطلوب',
    'max_length' => 'أقصى طول',
    'max_length_hint' => 'بين 1 و:count محرفًا.',
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
    'no_fields' => 'يحتاج النموذج حقلًا واحدًا على الأقل.',

    'template' => 'شكل الطباعة',
    // The template named inside a sentence that says what is wrong with it (frontend.md §1.7).
    'template_subject' => 'شكل الطباعة',
    'template_hint' => 'اكتب ‎{city}‎ لقيمة ذلك الحقل؛ والحقل الفارغ يختفي، وكذلك السطر الذي يفرغ.',
    'template_fields' => 'الحقول التي يمكنك استعمالها: :keys',

    'save' => 'حفظ نموذج العنوان',
    'saved' => 'تم حفظ نموذج العنوان',
    'existing_addresses' => 'العناوين المحفوظة التي لم تعد تطابق هذا النموذج لا تصلح للطلب حتى يكملها أصحابها.',

    // تطلبها شاشات Geist (frontend.md 1.10): عناوين الحالات الفارغة، وأسباب الأزرار المعطّلة،
    // وكلمات النوافذ نفسها.
    'no_stores_title' => 'لا نماذج عناوين يمكنك تغييرها',
    'no_fields_title' => 'لا حقول بعد',
    'too_many_fields' => 'يحمل النموذج :count حقلًا على الأكثر.',
];
