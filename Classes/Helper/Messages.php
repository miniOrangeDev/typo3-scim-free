<?php

declare(strict_types=1);

namespace Miniorange\Scim\Helper;

class Messages
{
    const SUPPORT_QUERY_SENT = 'Support query sent! We will get in touch with you shortly.';
    const SUPPORT_QUERY_FAILED = 'Could not send query. Please try again later or mail us at info@xecurify.com';
    const SUPPORT_QUERY_INVALID_EMAIL = 'Please enter a valid Email address.';
    const SUPPORT_QUERY_CURL_ERROR = 'ERROR: PHP cURL extension is not installed or disabled. Query submit failed.';
}
