<?php

namespace App\Services\SellerIntelligence;

use App\Models\SellerInsight;

/**
 * What a producer returns: one thing worth a seller's attention.
 *
 * A draft, not a row — the engine decides whether it is new, an update to one already standing, or
 * unchanged. That keeps producers free of persistence concerns and keeps identity in one place.
 */
final class InsightDraft
{
    /**
     * @param  array<string, mixed>|null  $actionParams
     */
    public function __construct(
        public readonly int|string $sellerId,
        public readonly string $type,
        public readonly string $severity,
        public readonly string $title,
        public readonly ?string $body = null,
        public readonly ?string $entityType = null,
        public readonly int|string|null $entityId = null,
        public readonly ?float $metric = null,
        public readonly ?float $impact = null,
        public readonly ?string $actionKey = null,
        public readonly ?array $actionParams = null,
        public readonly ?\DateTimeInterface $expiresAt = null,
    ) {
    }

    /**
     * Identity: the seller, the kind of problem, and the thing it is about.
     *
     * Deliberately not the title or the numbers — a product that drops from 4 units to 2 is the same
     * warning, updated, not a second one. Producers that address a seller rather than an entity
     * (an account-level warning) collapse to one row per type, which is right.
     */
    public function fingerprint(): string
    {
        return implode('|', [
            $this->sellerId,
            $this->type,
            $this->entityType ?? '-',
            $this->entityId ?? '-',
        ]);
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'seller_id' => $this->sellerId,
            'type' => $this->type,
            'severity' => $this->severity,
            'title' => $this->title,
            'body' => $this->body,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId === null ? null : (string) $this->entityId,
            'metric' => $this->metric,
            'impact' => $this->impact,
            'action_key' => $this->actionKey,
            'action_params' => $this->actionParams,
            'expires_at' => $this->expiresAt,
        ];
    }

    public static function severities(): array
    {
        return array_keys(SellerInsight::SEVERITY_ORDER);
    }
}
