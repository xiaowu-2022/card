<?php

return [
    // Per function, company and authenticated identity. Financial/provider-specific
    // configured quotas and password/OTP/login protections retain their own limits.
    'read_per_minute' => (int) env('BUSINESS_READ_RATE_LIMIT_PER_MINUTE', 600),
    'write_per_minute' => (int) env('BUSINESS_WRITE_RATE_LIMIT_PER_MINUTE', 60),
];
