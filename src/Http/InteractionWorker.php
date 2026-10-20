<?php
declare(strict_types=1);
namespace RadioStamp\Http;

use RadioStamp\Cards\CardRenderer;
use RadioStamp\Core\Clock;
use RadioStamp\Core\RadioService;
use RadioStamp\Core\ParticipationDays;
use RadioStamp\Operations\SafeDiagnostics;

final class InteractionWorker
{
    public function __construct(
        private readonly InteractionQueue $queue,
        private readonly RadioService $service,
        private readonly CardRenderer|\Closure $renderer,
        private readonly FollowupSink $sink,
        private readonly Clock $clock,
        private readonly ?\Closure $check = null,
        private readonly string $scopeMode = 'mock',
        private readonly ?\Closure $preflight = null,
        private readonly ?SafeDiagnostics $diagnostics=null,
    ) {}

    public function runOnce(?string $interactionId = null): ?array
    {
        if (in_array($this->scopeMode,['admin_validation','operations'],true) && !PrivateConfig::snowflake($interactionId)) { return null; }
        $now = $this->clock->now()->getTimestamp();
        $this->diagnostics?->context($interactionId);
        try{$job = $this->queue->claim($now, 120, $interactionId, $this->scopeMode);}
        catch(\Throwable $error){$this->diagnostics?->exception('worker_claim',$error);throw $error;}
        if ($job === null) {$this->diagnostics?->record('worker_claim',['ok'=>true,'code'=>'no_job']);return null; }
        $this->diagnostics?->context($job['interaction_id'],$job['user_id'],$job['action']);
        $this->diagnostics?->record('worker_claim',['ok'=>true,'code'=>'claimed']);
        $followingUp = false;
        $followupRetryCode = $this->scopeMode === 'mock' ? 'mock_followup_retry' : 'validation_followup_retry';
        try {
            $token = $this->queue->token($job);
            $png = null;
            $preflight = in_array($this->scopeMode,['admin_validation','operations'],true)
                ? ($this->preflight === null ? ['ok' => false, 'code' => 'validation_identity_required'] : ($this->preflight)())
                : ['ok' => true];
            $result = ($preflight['ok'] ?? false) !== true ? $preflight : match ($job['action']) {
                'start' => $this->service->start($job['target'], $job['user_id'], $job['interaction_id']),
                'attend' => $this->service->attend($job['target'], $job['user_id'], $job['interaction_id']),
                'receipt' => $this->service->openAttendance($job['user_id'], $job['interaction_id'], (int)$job['created_at']),
                'card' => $this->service->cardData($job['user_id'], $job['display_name'], $job['target']),
                'status' => $this->service->statusData($job['user_id']),
                'memorial' => $this->service->cardData($job['user_id'], $job['display_name'], $this->clock->now()->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m')),
                'check' => $this->check === null ? ['ok' => false, 'code' => 'validation_check_unavailable'] : ($this->check)(),
                default => ['ok' => false, 'code' => 'unsupported_action'],
            };
            if ($job['action'] === 'card' && ($result['ok'] ?? false) === true) {
                $png = $this->render($job, fn(): string => $this->renderer()->monthly($result));
                $buttons = [];
                foreach (ParticipationDays::MILESTONES as $threshold) {
                    if (in_array($threshold, $result['awards'] ?? [], true)) {
                        $buttons[] = ['type' => 2, 'style' => 2, 'label' => $threshold . '日 特典カード', 'custom_id' => 'radio:memorial:' . $threshold];
                    }
                }
                $components = array_map(static fn(array $row): array => ['type' => 1, 'components' => $row], array_chunk($buttons, 5));
                // Earned buttons remain usable even if the monthly preview cannot be rendered.
                $result = ['ok' => $png !== null, 'code' => $png === null ? 'card_render_failed' : 'card_ready', 'components' => $components];
            }
            if ($job['action'] === 'memorial' && ($preflight['ok'] ?? false) === true) {
                $milestone = (int) $job['target'];
                if (in_array($milestone, $result['awards'] ?? [], true)) {
                    $png = $this->render($job, fn(): string => $this->renderer()->memorial($result, $milestone));
                    $result = ['ok' => $png !== null, 'code' => $png === null ? 'card_render_failed' : 'memorial_ready'];
                } else {
                    $result = ['ok' => false, 'code' => 'memorial_locked'];
                }
            }
            $code = is_string($result['code'] ?? null) ? $result['code'] : 'operation_complete';
            $this->diagnostics?->record('worker_result',['ok'=>($result['ok']??false)===true,'code'=>$code]);
            // Results passed across this boundary exclude display names and DB details.
            $publicResult = ['ok' => ($result['ok'] ?? false) === true, 'code' => $code];
            if ($code === 'status_ready' && $publicResult['ok']) {
                $publicResult['counts'] = ['total_count' => $result['total_count'], 'total_days' => $result['total_days']];
            }
            if (isset($result['components'])) { $publicResult['components'] = $result['components']; }
            if (isset($result['checks']) && is_array($result['checks'])) {
                $publicResult['checks'] = array_filter($result['checks'], static fn(mixed $value): bool => is_bool($value));
            }
            if (in_array($result['runtime_status'] ?? null, ['disabled', 'pass', 'skip', 'fail'], true)) { $publicResult['runtime_status'] = $result['runtime_status']; }
            $followingUp = true;
            $sent=$this->sink->send($job['interaction_id'], $token, $publicResult, $png);
            $this->diagnostics?->record('followup',['ok'=>$sent,'sent'=>$sent,'code'=>$sent?'sent':'validation_followup_retry']);
            if (!$sent) {
                return $this->retry($job, $followupRetryCode, $now);
            }
            $this->queue->finish($job, $code);
            return ['interaction_id' => $job['interaction_id'], 'state' => 'done', 'code' => $code];
        } catch (\Throwable $error) {
            $this->diagnostics?->exception('worker_result',$error);
            // Never log exception strings: transports and DB errors may contain secrets.
            // A prior PATCH may already have delivered the image. Retain that uncertainty
            // through later local failures, so an error notice cannot erase a delivered PNG.
            $retryCode = $followingUp || $this->hadFollowupAttempt($job) ? $followupRetryCode : 'worker_retry';
            return $this->retry($job, $retryCode, $now);
        }
    }

    private function retry(array $job,string $code,int $now):array
    {
        $transition=$this->queue->retry($job,$code,$now);
        $state=match($transition){'pending'=>'retry','failed'=>'failed',default=>'lost'};
        $code=match($transition){'pending'=>$code,'failed'=>'worker_failed',default=>'worker_claim_lost'};
        $this->diagnostics?->record('worker_result',['ok'=>false,'code'=>$code]);
        return ['interaction_id'=>$job['interaction_id'],'state'=>$state,'code'=>$code];
    }

    private function renderer(): CardRenderer
    {
        return $this->renderer instanceof CardRenderer ? $this->renderer : ($this->renderer)();
    }

    /** Only local image construction is caught here; a transport failure is never relabeled. */
    private function render(array $job, \Closure $build): ?string
    {
        try {
            $png = $build();
            if (strlen($png) > 1048576 || !str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
                throw new \RuntimeException('Card attachment is unavailable.');
            }
            return $png;
        } catch (\Throwable $error) {
            if ((int)$job['attempts'] < 5 || $this->hadFollowupAttempt($job)
                || !$this->queue->owns($job, $this->clock->now()->getTimestamp())) { throw $error; }
            $this->diagnostics?->exception('worker_result', $error);
            return null;
        }
    }

    private function hadFollowupAttempt(array $job): bool
    {
        return in_array($job['result_code'] ?? null, ['mock_followup_retry', 'validation_followup_retry'], true);
    }
}
