<!doctype html>
<html lang="en">
<body style="font-family: sans-serif; color: #0f172a">
    <h1>Admin invitation</h1>
    <p>You have been invited to administer {{ $tenant->name }}.</p>
    <p><a href="{{ $invitationUrl }}">Accept invitation</a></p>
    <p>This single-use link expires at {{ $invitation->expires_at->toIso8601String() }}.</p>
</body>
</html>
