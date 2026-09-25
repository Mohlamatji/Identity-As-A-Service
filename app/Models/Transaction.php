<?php

namespace App\Models;

use PDO;

class Transaction
{
    public static function create(
        PDO $pdo,
        int $userId,
        string $bankPartnerId,
        int $amountCents,
        float $riskScore,
        string $decision,
        string $decisionReason,
        string $currency = 'ZAR'
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO transactions (user_id, bank_partner_id, amount_cents, currency, risk_score, decision, decision_reason)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $bankPartnerId, $amountCents, $currency, $riskScore, $decision, $decisionReason]);
        return (int) $pdo->lastInsertId();
    }

    /** Formats cents as a Rand display string, e.g. 500000 -> "R 5,000.00". */
    public static function formatZar(int $amountCents): string
    {
        return 'R ' . number_format($amountCents / 100, 2);
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function recentForUser(PDO $pdo, int $userId, int $limit = 20): array
    {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** For the admin dashboard - most recent transactions across all users, joined with the user's name. */
    public static function recentAll(PDO $pdo, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT t.*, u.full_name FROM transactions t
             JOIN users u ON u.id = t.user_id
             ORDER BY t.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
