<?php

declare(strict_types=1);

/*
 * The validation messages in Urdu (W-212), for the console in the new frame in Urdu. The structure is Laravel's
 * lang/en/validation.php; a rule without a line here falls back to Laravel's English one. The attributes are the
 * fields' names as the console writes them in English ("The value field is required."), in Urdu.
 */
return [

    'accepted' => ':attribute قبول کرنا لازمی ہے۔',
    'active_url' => ':attribute درست لنک ہونا چاہیے۔',
    'after' => ':attribute :date کے بعد کی تاریخ ہونی چاہیے۔',
    'after_or_equal' => ':attribute :date یا اس کے بعد کی تاریخ ہونی چاہیے۔',
    'alpha' => ':attribute میں صرف حروف ہو سکتے ہیں۔',
    'alpha_dash' => ':attribute میں صرف حروف، ہندسے، ڈیش اور انڈر اسکور ہو سکتے ہیں۔',
    'alpha_num' => ':attribute میں صرف حروف اور ہندسے ہو سکتے ہیں۔',
    'array' => ':attribute فہرست ہونی چاہیے۔',
    'before' => ':attribute :date سے پہلے کی تاریخ ہونی چاہیے۔',
    'before_or_equal' => ':attribute :date یا اس سے پہلے کی تاریخ ہونی چاہیے۔',
    'between' => [
        'array' => ':attribute میں :min سے :max آئٹمز ہونے چاہییں۔',
        'file' => ':attribute کا سائز :min سے :max کلو بائٹ کے درمیان ہونا چاہیے۔',
        'numeric' => ':attribute :min اور :max کے درمیان ہونا چاہیے۔',
        'string' => ':attribute میں :min سے :max حروف ہونے چاہییں۔',
    ],
    'boolean' => ':attribute ہاں یا نہیں ہونا چاہیے۔',
    'confirmed' => ':attribute کی تصدیق میل نہیں کھاتی۔',
    'current_password' => 'پاس ورڈ درست نہیں۔',
    'date' => ':attribute درست تاریخ ہونی چاہیے۔',
    'date_format' => ':attribute اس شکل میں ہونا چاہیے: :format۔',
    'different' => ':attribute اور :other مختلف ہونے چاہییں۔',
    'digits' => ':attribute :digits ہندسوں کا ہونا چاہیے۔',
    'digits_between' => ':attribute :min سے :max ہندسوں کا ہونا چاہیے۔',
    'email' => ':attribute درست ای میل پتہ ہونا چاہیے۔',
    'exists' => 'منتخب :attribute درست نہیں۔',
    'file' => ':attribute فائل ہونی چاہیے۔',
    'filled' => ':attribute خالی نہیں ہو سکتا۔',
    'gt' => [
        'array' => ':attribute میں :value سے زیادہ آئٹمز ہونے چاہییں۔',
        'file' => ':attribute کا سائز :value کلو بائٹ سے زیادہ ہونا چاہیے۔',
        'numeric' => ':attribute :value سے زیادہ ہونا چاہیے۔',
        'string' => ':attribute میں :value سے زیادہ حروف ہونے چاہییں۔',
    ],
    'gte' => [
        'array' => ':attribute میں کم از کم :value آئٹمز ہونے چاہییں۔',
        'file' => ':attribute کا سائز کم از کم :value کلو بائٹ ہونا چاہیے۔',
        'numeric' => ':attribute کم از کم :value ہونا چاہیے۔',
        'string' => ':attribute میں کم از کم :value حروف ہونے چاہییں۔',
    ],
    'image' => ':attribute تصویر ہونی چاہیے۔',
    'in' => 'منتخب :attribute درست نہیں۔',
    'integer' => ':attribute پورا عدد ہونا چاہیے۔',
    'ip' => ':attribute درست IP پتہ ہونا چاہیے۔',
    'json' => ':attribute درست JSON ہونا چاہیے۔',
    'lt' => [
        'array' => ':attribute میں :value سے کم آئٹمز ہونے چاہییں۔',
        'file' => ':attribute کا سائز :value کلو بائٹ سے کم ہونا چاہیے۔',
        'numeric' => ':attribute :value سے کم ہونا چاہیے۔',
        'string' => ':attribute میں :value سے کم حروف ہونے چاہییں۔',
    ],
    'lte' => [
        'array' => ':attribute میں :value سے زیادہ آئٹمز نہیں ہو سکتے۔',
        'file' => ':attribute کا سائز :value کلو بائٹ سے زیادہ نہیں ہو سکتا۔',
        'numeric' => ':attribute :value سے زیادہ نہیں ہو سکتا۔',
        'string' => ':attribute میں :value سے زیادہ حروف نہیں ہو سکتے۔',
    ],
    'max' => [
        'array' => ':attribute میں :max سے زیادہ آئٹمز نہیں ہو سکتے۔',
        'file' => ':attribute کا سائز :max کلو بائٹ سے زیادہ نہیں ہو سکتا۔',
        'numeric' => ':attribute :max سے زیادہ نہیں ہو سکتا۔',
        'string' => ':attribute میں :max سے زیادہ حروف نہیں ہو سکتے۔',
    ],
    'mimes' => ':attribute ان قسموں کی فائل ہونی چاہیے: :values۔',
    'mimetypes' => ':attribute ان قسموں کی فائل ہونی چاہیے: :values۔',
    'min' => [
        'array' => ':attribute میں کم از کم :min آئٹمز ہونے چاہییں۔',
        'file' => ':attribute کا سائز کم از کم :min کلو بائٹ ہونا چاہیے۔',
        'numeric' => ':attribute کم از کم :min ہونا چاہیے۔',
        'string' => ':attribute میں کم از کم :min حروف ہونے چاہییں۔',
    ],
    'not_in' => 'منتخب :attribute درست نہیں۔',
    'numeric' => ':attribute عدد ہونا چاہیے۔',
    'present' => ':attribute موجود ہونا چاہیے۔',
    'regex' => ':attribute کی شکل درست نہیں۔',
    'required' => ':attribute لازمی ہے۔',
    'required_if' => 'جب :other :value ہو تو :attribute لازمی ہے۔',
    'required_unless' => ':attribute لازمی ہے، سوائے اس کے کہ :other :values میں ہو۔',
    'required_with' => 'جب :values موجود ہو تو :attribute لازمی ہے۔',
    'required_with_all' => 'جب :values موجود ہوں تو :attribute لازمی ہے۔',
    'required_without' => 'جب :values موجود نہ ہو تو :attribute لازمی ہے۔',
    'required_without_all' => 'جب :values میں سے کوئی موجود نہ ہو تو :attribute لازمی ہے۔',
    'same' => ':attribute اور :other ایک جیسے ہونے چاہییں۔',
    'size' => [
        'array' => ':attribute میں :size آئٹمز ہونے چاہییں۔',
        'file' => ':attribute کا سائز :size کلو بائٹ ہونا چاہیے۔',
        'numeric' => ':attribute :size ہونا چاہیے۔',
        'string' => ':attribute میں :size حروف ہونے چاہییں۔',
    ],
    'string' => ':attribute متن ہونا چاہیے۔',
    'timezone' => ':attribute درست ٹائم زون ہونا چاہیے۔',
    'unique' => 'یہ :attribute پہلے سے موجود ہے۔',
    'uploaded' => ':attribute اپ لوڈ نہیں ہو سکا۔',
    'url' => ':attribute درست لنک ہونا چاہیے۔',
    'ulid' => ':attribute درست ULID ہونا چاہیے۔',
    'uuid' => ':attribute درست UUID ہونا چاہیے۔',

    'custom' => [],

    // A project's pages (ViewClient) and its form (ClientResource): the fields' English names, in Urdu.
    'attributes' => [
        'name' => 'نام',
        'line' => 'سطر',
        'kind' => 'قسم',
        'file' => 'فائل',
        'instructions' => 'ہدایات',
        'question' => 'سوال',
        'value' => 'قدر',
        'avatar' => 'اوتار',
        'color' => 'رنگ',
        'notes' => 'نوٹس',
    ],

];
