<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Context;

/**
 * Baut die Dienste des Live-Moduls aus dem Kontext (je Request einmal).
 * Controller des Moduls holen sich ihre Dienste hier, statt sie einzeln zu verdrahten.
 */
final class LiveServices
{
    private ?EventLog $events = null;
    private ?ParticipationState $states = null;
    private ?DeviceBinding $devices = null;
    private ?SebGuard $seb = null;
    private ?AnswerStore $answers = null;
    private ?ExamContent $content = null;
    private ?RunLimiter $limiter = null;
    private ?LiveStatus $status = null;
    private ?PultActions $actions = null;

    public function __construct(private readonly Context $ctx)
    {
    }

    public function events(): EventLog
    {
        return $this->events ??= new EventLog($this->ctx->db);
    }

    public function states(): ParticipationState
    {
        return $this->states ??= new ParticipationState($this->ctx->db, $this->events());
    }

    public function devices(): DeviceBinding
    {
        return $this->devices ??= new DeviceBinding($this->ctx->db, (bool) ($this->ctx->config['app']['secure_cookies'] ?? false));
    }

    public function seb(): SebGuard
    {
        return $this->seb ??= new SebGuard($this->ctx->configuredPublicBase(), $this->events());
    }

    public function answers(): AnswerStore
    {
        return $this->answers ??= new AnswerStore($this->ctx->db);
    }

    public function content(): ExamContent
    {
        return $this->content ??= new ExamContent($this->ctx->db, (string) ($this->ctx->config['app']['base_url'] ?? ''));
    }

    public function limiter(): RunLimiter
    {
        return $this->limiter ??= new RunLimiter($this->ctx->db);
    }

    public function status(): LiveStatus
    {
        return $this->status ??= new LiveStatus($this->ctx->db, $this->events());
    }

    public function actions(): PultActions
    {
        return $this->actions ??= new PultActions($this->ctx->db, $this->events(), $this->states(), $this->devices(), $this->ctx->audit);
    }
}
