<?php

return [
    'email_notifications' => (bool) env('EDUQUEST_EMAIL_NOTIFICATIONS', false),
    'registration_organization_id' => (int) env('EDUQUEST_REGISTRATION_ORGANIZATION_ID', 1),
];
