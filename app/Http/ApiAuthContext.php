<?php

namespace App\Http;

final class ApiAuthContext
{
    /**
     * @param  array<string, mixed>  $token
     */
    public function __construct(
        public array $token,
        public int $auditAdminId
    ) {}

    public function projectId(): ?int
    {
        $id = $this->token['client_project_id'] ?? null;

        return $id !== null ? (int) $id : null;
    }
}
