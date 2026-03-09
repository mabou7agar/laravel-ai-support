<?php

return [
    'api' => [
        'request_completed' => 'تم تنفيذ الطلب بنجاح.',
        'request_failed' => 'فشل تنفيذ الطلب.',
    ],

    'agent' => [
        'no_results_found' => 'لم يتم العثور على نتائج.',
        'no_more_results' => 'لا توجد نتائج إضافية للعرض. لقد تم عرض جميع النتائج وعددها :count.',
        'reached_end_of_results' => 'وصلت إلى نهاية النتائج. تم عرض جميع النتائج وعددها :count.',
        'rag_no_relevant_info' => 'لم أتمكن من العثور على معلومات ذات صلة. هل يمكنك إعادة صياغة سؤالك؟',
        'rag_remote_unreachable' => 'تعذر الوصول إلى العقدة البعيدة الآن. يرجى المحاولة بعد قليل.',
        'rag_node_not_found' => 'لم أجد عقدة متصلة تملك هذه البيانات.',
        'rag_model_not_found' => 'لم أتمكن من مطابقة هذا الطلب مع نموذج بيانات متاح.',
        'rag_lookup_failed' => 'لم أتمكن من إكمال البحث عن البيانات الآن. يرجى المحاولة مرة أخرى.',

        'node_no_remote_specified' => 'لم يتم تحديد عقدة بعيدة.',
        'node_matching_remote_not_found' => 'لم أجد عقدة بعيدة مطابقة لـ :resource.',
        'node_unreachable' => 'تعذر الوصول إلى العقدة البعيدة :node:location (:summary). يرجى التأكد من أن العقدة تعمل ثم المحاولة مرة أخرى.',

        'selection_unrecognized' => 'لم أفهم أي عنصر تقصده. هل يمكنك التوضيح أكثر؟',
        'selection_item_not_found' => 'لم أجد العنصر رقم :position في القائمة السابقة. يرجى التحقق من الرقم والمحاولة مرة أخرى.',
        'selection_details_unavailable' => 'وجدت :type لكن تعذر جلب تفاصيله. يرجى المحاولة مرة أخرى.',
    ],
];
