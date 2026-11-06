<?php

// Messages used by this app. Anything missing falls back to English (APP_FALLBACK_LOCALE=en).
return [
    'required' => 'حقل :attribute مطلوب.',
    'email' => 'حقل :attribute يجب أن يكون إيميل صحيح.',
    'url' => 'حقل :attribute يجب أن يكون رابط صحيح.',
    'string' => 'حقل :attribute يجب أن يكون نصاً.',
    'integer' => 'حقل :attribute يجب أن يكون رقماً صحيحاً.',
    'boolean' => 'حقل :attribute يجب أن يكون نعم أو لا.',
    'array' => 'حقل :attribute يجب أن يكون قائمة.',
    'in' => 'قيمة :attribute غير صالحة.',
    'unique' => 'قيمة :attribute مستخدمة من قبل.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'current_password' => 'كلمة المرور الحالية غير صحيحة.',
    'lowercase' => 'حقل :attribute يجب أن يكون بحروف صغيرة.',
    'date_format' => 'حقل :attribute يجب أن يكون بالشكل :format.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'between' => [
        'numeric' => 'حقل :attribute يجب أن يكون بين :min و :max.',
        'string' => 'حقل :attribute يجب أن يكون بين :min و :max حرف.',
    ],
    'min' => [
        'numeric' => 'حقل :attribute يجب ألا يقل عن :min.',
        'string' => 'حقل :attribute يجب ألا يقل عن :min حروف.',
        'array' => 'حقل :attribute يجب أن يحتوي على :min عناصر على الأقل.',
    ],
    'max' => [
        'numeric' => 'حقل :attribute يجب ألا يزيد عن :max.',
        'string' => 'حقل :attribute يجب ألا يزيد عن :max حرف.',
        'array' => 'حقل :attribute يجب ألا يحتوي على أكثر من :max عناصر.',
    ],
    'password' => [
        'letters' => 'كلمة المرور يجب أن تحتوي على حرف واحد على الأقل.',
        'mixed' => 'كلمة المرور يجب أن تحتوي على حرف كبير وحرف صغير.',
        'numbers' => 'كلمة المرور يجب أن تحتوي على رقم.',
        'symbols' => 'كلمة المرور يجب أن تحتوي على رمز.',
        'uncompromised' => 'كلمة المرور ظهرت في تسريب بيانات، اختر كلمة أخرى.',
    ],

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'الإيميل',
        'password' => 'كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'password_confirmation' => 'تأكيد كلمة المرور',
    ],
];
