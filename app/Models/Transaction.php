<?php

namespace App\Models;

use PDO;

class Transaction
{
    /**
     * $extra may carry: decision_id, factors_json, required_action,
     * engine_version, receipt, client_id, verification_session_id, payee.
     */
    public static function create(
        PDO $pdo,
        int $userId,
        string $bankPartnerId,
        int $amountCents,
        float $riskScore,
        string $decision,
        string $decisionReason,
        string $currency = 'ZAR',
        array $extra = []
    ): int {
        $stmt = $pdo->prepare(
            'INSERT INTO transactions
             (user_id, bank_partner_id, amount_cents, currency, risk_score, decision, decision_reason, created_at,
              decision_id, factors_json, required_action, engine_version, receipt, client_id, verification_session_id, payee)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId, $bankPartnerId, $amountCents, $currency, $riskScore, $decision, $decisionReason, gmdate('Y-m-d H:i:s'),
            $extra['decision_id'] ?? null, $extra['factors_json'] ?? null, $extra['required_action'] ?? null,
            $extra['engine_version'] ?? null, $extra['receipt'] ?? null, $extra['client_id'] ?? null,
            $extra['verification_session_id'] ?? null, $extra['payee'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function setReceipt(PDO $pdo, int $id, string $receipt): void
    {
        $pdo->prepare('UPDATE transactions SET receipt = ? WHERE id = ?')->execute([$receipt, $id]);
    }

    public static function setAiAnalysis(PDO $pdo, int $id, array $analysis): void
    {
        $pdo->prepare('UPDATE transactions SET ai_analysis = ? WHERE id = ?')->execute([json_encode($analysis), $id]);
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

    public static function findByDecisionId(PDO $pdo, string $decisionId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM transactions WHERE decision_id = ?');
        $stmt->execute([$decisionId]);
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

    /** Amounts (cents) of the user's most recent APPROVED transactions - the spending baseline. */
    public static function approvedAmounts(PDO $pdo, int $userId, int $limit = 30): array
    {
        $stmt = $pdo->prepare("SELECT amount_cents FROM transactions WHERE user_id = ? AND decision = 'approved' ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Sum of non-rejected transaction value since a unix timestamp. */
    public static function valueSince(PDO $pdo, int $userId, int $sinceTs): int
    {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM transactions WHERE user_id = ? AND decision != 'rejected' AND created_at >= ?");
        $stmt->execute([$userId, gmdate('Y-m-d H:i:s', $sinceTs)]);
        return (int) $stmt->fetchColumn();
    }

    public static function countSince(PDO $pdo, int $userId, int $sinceTs, ?string $decision = null): int
    {
        $sql = 'SELECT COUNT(*) FROM transactions WHERE user_id = ? AND created_at >= ?' . ($decision ? ' AND decision = ?' : '');
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_filter([$userId, gmdate('Y-m-d H:i:s', $sinceTs), $decision], fn ($v) => $v !== null));
        return (int) $stmt->fetchColumn();
    }

    /** For the admin dashboard - most recent transactions across all users, joined with the user's name. */
    public static function recentAll(PDO $pdo, int $limit = 50): array
    {
        $stmt = $pdo->prepare(
            'SELECT t.id, t.user_id, t.bank_partner_id, t.amount_cents, t.currency, t.risk_score, t.decision, t.decision_reason,
                    t.created_at, t.decision_id, t.required_action, t.engine_version, t.payee, u.full_name,
                    po.paypal_order_id, po.status AS paypal_status
             FROM transactions t
             JOIN users u ON u.id = t.user_id
             LEFT JOIN paypal_orders po ON po.transaction_id = t.id
             ORDER BY t.id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Aggregates for the dashboard header and charts. */
    public static function stats(PDO $pdo): array
    {
        $byDecision = [];
        foreach ($pdo->query('SELECT decision, COUNT(*) c, COALESCE(SUM(amount_cents),0) v, COALESCE(AVG(risk_score),0) s FROM transactions GROUP BY decision') as $r) {
            $byDecision[$r['decision']] = ['count' => (int) $r['c'], 'value_cents' => (int) $r['v'], 'avg_score' => round((float) $r['s'], 1)];
        }
        $buckets = array_fill(0, 10, 0);
        foreach ($pdo->query('SELECT risk_score FROM transactions') as $r) {
            $buckets[min(9, (int) floor(((float) $r['risk_score']) / 10))]++;
        }
        $required = [];
        foreach ($pdo->query("SELECT required_action a, COUNT(*) c FROM transactions WHERE required_action IS NOT NULL GROUP BY required_action") as $r) {
            $required[$r['a']] = (int) $r['c'];
        }
        return ['by_decision' => $byDecision, 'score_histogram' => $buckets, 'required_actions' => $required];
    }
}
