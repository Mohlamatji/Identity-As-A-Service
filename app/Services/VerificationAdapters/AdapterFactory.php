<?php

namespace App\Services\VerificationAdapters;

class AdapterFactory
{
    public static function make(array $providersConfig): VerificationAdapterInterface
    {
        $active = $providersConfig['active_provider'];

        return match ($active) {
            'datanamix' => new DatanamixAdapter(
                $providersConfig['datanamix']['base_url'],
                $providersConfig['datanamix']['api_key']
            ),
            'verifynow' => new VerifyNowAdapter(
                $providersConfig['verifynow']['base_url'],
                $providersConfig['verifynow']['api_key'],
                $providersConfig['verifynow']['mode'] ?? 'sandbox'
            ),
            default => new MockAdapter(),
        };
    }
}
