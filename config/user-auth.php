<?php

return [
    'otp_secret' => env('USER_OTP_SECRET', env('APP_KEY')),
    'otp_ttl_minutes' => (int) env('USER_OTP_TTL_MINUTES', 10),
    'otp_max_attempts' => (int) env('USER_OTP_MAX_ATTEMPTS', 5),
    'verify_limit_per_minute' => (int) env('USER_OTP_VERIFY_LIMIT_PER_MINUTE', 5),
    'resend_cooldown_seconds' => (int) env('USER_OTP_RESEND_COOLDOWN_SECONDS', 60),
    'send_limit_per_hour' => (int) env('USER_OTP_SEND_LIMIT_PER_HOUR', 6),
    'login_max_attempts' => (int) env('USER_LOGIN_MAX_ATTEMPTS', 5),
];
