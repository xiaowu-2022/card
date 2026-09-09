<h1>{{ $tenant->branding?->brand_name ?? $tenant->name }}</h1>
<p>Your verification code is:</p>
<p style="font-size: 28px; font-weight: 700; letter-spacing: 6px">{{ $code }}</p>
<p>This code expires in {{ config('user-auth.otp_ttl_minutes') }} minutes. Never share it with anyone.</p>
