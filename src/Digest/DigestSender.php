<?php
declare(strict_types=1);

namespace Landlords\Digest;

use Landlords\Mail\Mailer;
use Landlords\Subscribers\SignupService;
use Landlords\Subscribers\SubscriberStore;
use PDO;

/**
 * The Monday job. Each digest covers changes since the previous digest (or the
 * last 7 days, the first time), so none are missed or repeated even if a Monday
 * is skipped. Nothing is sent in a week with no changes.
 *
 * Every send is recorded, so if a run stops part-way (an email limit, a mail
 * server problem) the next run finishes the same digest without emailing anyone twice.
 */
final class DigestSender
{
    public const STOP_AFTER_FAILURES = 3;

    /** @var list<string> */
    private array $log = [];

    /** @var callable(int): void */
    private $sleep;

    public function __construct(
        private PDO $pdo,
        private SubscriberStore $subscribers,
        private SignupService $signup,
        private Mailer $mailer,
        private DigestBuilder $builder,
        private ChangeReport $report,
        private int $maxPerRun = 200,
        private int $pauseMs = 500,
        ?callable $sleep = null
    ) {
        $this->sleep = $sleep ?? static fn(int $ms) => usleep($ms * 1000);
    }

    /**
     * @return array{status: string, items: int, sent: int, failed: int, remaining: int, log: list<string>}
     */
    public function run(string $now): array
    {
        $digest = $this->openDigest($now);
        $items = $this->report->between($digest['period_start'], $digest['period_end']);

        if (!$items) {
            $this->pdo->prepare("UPDATE digests SET status = 'empty' WHERE id = ?")->execute([$digest['id']]);
            $this->note('No changes since ' . $digest['period_start'] . ' UTC; nothing sent');
            return $this->summary('empty', 0, 0, 0, 0);
        }

        $done = $this->pdo->prepare('SELECT subscriber_id FROM digest_sends WHERE digest_id = ? AND sent_at IS NOT NULL');
        $done->execute([$digest['id']]);
        $already = array_flip($done->fetchAll(PDO::FETCH_COLUMN));
        $todo = array_values(array_filter($this->subscribers->active(), fn($s) => !isset($already[$s['id']])));

        $this->note(sprintf('%d change%s; %d subscriber%s to send to%s',
            count($items), count($items) === 1 ? '' : 's', count($todo), count($todo) === 1 ? '' : 's',
            $already ? ' (' . count($already) . ' already sent in an earlier run)' : ''));

        $weekTo = $this->ukDate($digest['period_end']);
        $sent = $failed = $consecutive = 0;
        foreach (array_slice($todo, 0, $this->maxPerRun) as $i => $sub) {
            if ($i > 0 && $this->pauseMs > 0) {
                ($this->sleep)($this->pauseMs);
            }
            $message = $this->builder->message($items, $weekTo, $sub['email'], $sub['name'],
                $this->signup->unsubscribeUrl((int) $sub['id']));
            try {
                $this->mailer->send($message);
                $this->recordSend((int) $digest['id'], (int) $sub['id'], $now, null);
                $sent++;
                $consecutive = 0;
            } catch (\RuntimeException $e) {
                $this->recordSend((int) $digest['id'], (int) $sub['id'], null, $e->getMessage());
                $failed++;
                if (++$consecutive >= self::STOP_AFTER_FAILURES) {
                    $this->note("Stopped after {$consecutive} failures in a row: " . $e->getMessage());
                    break;
                }
            }
        }

        $remaining = count($todo) - $sent;
        $status = $remaining === 0 ? 'sent' : 'sending';
        $this->pdo->prepare('UPDATE digests SET status = ? WHERE id = ?')->execute([$status, $digest['id']]);
        $this->note("Sent {$sent}" . ($failed ? ", {$failed} failed" : '') . ($remaining ? ", {$remaining} still to send" : ''));
        return $this->summary($status === 'sent' ? 'sent' : ($consecutive >= self::STOP_AFTER_FAILURES ? 'failed' : 'partial'),
            count($items), $sent, $failed, $remaining);
    }

    /**
     * Send this week's digest to one address only, marked as a test. Nothing is
     * recorded. With no changes this week, the last 90 days are used instead.
     */
    public function test(string $now, string $to): array
    {
        $last = $this->pdo->query('SELECT period_end FROM digests ORDER BY id DESC LIMIT 1')->fetchColumn();
        $start = $last ?: gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - 7 * 86400);
        $items = $this->report->between((string) $start, $now);
        if (!$items) {
            $items = $this->report->between(gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - 90 * 86400), $now);
            $this->note('No changes this week, so the test covers the last 90 days');
        }
        // Subscriber 0 does not exist, so the test's unsubscribe link safely does nothing.
        $this->mailer->send($this->builder->message($items, $this->ukDate($now), $to, null,
            $this->signup->unsubscribeUrl(0), true));
        $this->note('Test digest with ' . count($items) . ' changes sent to the site’s own mailbox');
        return $this->summary('test', count($items), 1, 0, 0);
    }

    public function log(): array
    {
        return $this->log;
    }

    /** Resume an unfinished digest from the last two days, or start a new one. */
    private function openDigest(string $now): array
    {
        $twoDaysAgo = gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - 2 * 86400);
        $stmt = $this->pdo->prepare("SELECT * FROM digests WHERE status = 'sending' AND created_at >= ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$twoDaysAgo]);
        $open = $stmt->fetch();
        if ($open) {
            $this->note('Finishing the digest started ' . $open['created_at'] . ' UTC');
            return $open;
        }

        $last = $this->pdo->query('SELECT period_end FROM digests ORDER BY id DESC LIMIT 1')->fetchColumn();
        $start = $last ?: gmdate('Y-m-d H:i:s', strtotime($now . ' UTC') - 7 * 86400);
        $count = count($this->report->between((string) $start, $now));
        $this->pdo->prepare(
            "INSERT INTO digests (period_start, period_end, created_at, items, status) VALUES (?, ?, ?, ?, 'sending')"
        )->execute([$start, $now, $now, $count]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'period_start' => (string) $start, 'period_end' => $now, 'created_at' => $now];
    }

    private function recordSend(int $digestId, int $subscriberId, ?string $sentAt, ?string $error): void
    {
        $this->pdo->prepare('DELETE FROM digest_sends WHERE digest_id = ? AND subscriber_id = ?')->execute([$digestId, $subscriberId]);
        $this->pdo->prepare('INSERT INTO digest_sends (digest_id, subscriber_id, sent_at, error) VALUES (?, ?, ?, ?)')
            ->execute([$digestId, $subscriberId, $sentAt, $error]);
    }

    private function ukDate(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/London'))->format('Y-m-d');
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    private function summary(string $status, int $items, int $sent, int $failed, int $remaining): array
    {
        return ['status' => $status, 'items' => $items, 'sent' => $sent, 'failed' => $failed,
                'remaining' => $remaining, 'log' => $this->log];
    }
}
