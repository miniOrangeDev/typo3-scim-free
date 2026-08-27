<?php

declare(strict_types=1);

namespace Miniorange\Scim\Service;

use Miniorange\Scim\Helper\CustomerMo;
use Miniorange\Scim\Helper\Messages;
use Miniorange\Scim\Helper\MoUtilities;

class SupportService
{
    /**
     * @param array<mixed> $post Raw $_POST data; keys are not guaranteed to be strings.
     */
    public function submitSupportQuery(array $post): void
    {
        if (!extension_loaded('curl')) {
            MoUtilities::showErrorFlashMessage(Messages::SUPPORT_QUERY_CURL_ERROR);
            return;
        }

        $email = trim(MoUtilities::stringFromMixed($post['mo_scim_contact_us_email'] ?? null));
        $phone = trim(MoUtilities::stringFromMixed($post['mo_scim_contact_us_phone'] ?? null));
        $query = trim(MoUtilities::stringFromMixed($post['mo_scim_contact_us_query'] ?? null));

        if ($email === '' || $query === '') {
            MoUtilities::showErrorFlashMessage(Messages::SUPPORT_QUERY_INVALID_EMAIL);
            return;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            MoUtilities::showErrorFlashMessage(Messages::SUPPORT_QUERY_INVALID_EMAIL);
            return;
        }

        $customer = new CustomerMo();
        $response = $customer->submit_contact($email, $phone, $query);

        if ($response === 'Query submitted.') {
            MoUtilities::showSuccessFlashMessage(Messages::SUPPORT_QUERY_SENT);
            return;
        }

        MoUtilities::showErrorFlashMessage(Messages::SUPPORT_QUERY_FAILED);
    }
}
