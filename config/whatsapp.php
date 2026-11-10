<?php

return [
    // Kolom whatsapp_queue.client di gateway varchar(20).
    // WA_CLIENT_IDS (comma-separated) dipakai round-robin; fallback WA_CLIENT_ID.
    'client_id' => env('WA_CLIENT_ID', 'al_syukro_96240'),
    'client_ids' => array_values(array_filter(array_map(
        static fn ($id) => substr(trim((string) $id), 0, 20),
        explode(',', (string) env('WA_CLIENT_IDS', env('WA_CLIENT_ID', 'al_syukro_96240')))
    ))),
    'max_recipients' => (int) env('WA_MAX_RECIPIENTS', 100),
];
