<?php

namespace App\Support;

/** Who is calling and what they may do. Produced by ClientAuthService. */
final class AuthContext
{
    /**
     * @param string[] $scopes  '*' means every scope
     * @param string   $type    secret | legacy | client_token | open | admin_session
     */
    public function __construct(
        public readonly string $type,
        public readonly string $clientId,
        public readonly string $name,
        public readonly array $scopes,
        public readonly int $rateLimitPerMin = 120,
        public readonly ?int $boundUserId = null
    ) {
    }

    public function can(string $scope): bool
    {
        return in_array('*', $this->scopes, true) || in_array($scope, $this->scopes, true);
    }
}
