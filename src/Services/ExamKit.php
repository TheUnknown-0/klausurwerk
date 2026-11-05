<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Context;

/** Kleine Fabrik für die Dienste des Moduls Klausuren und Aufgaben (ein Aufbau an einer Stelle). */
final class ExamKit
{
    public static function materials(Context $ctx): Materials
    {
        return new Materials($ctx->db, (string) $ctx->config['uploads']['dir']);
    }

    public static function tasks(Context $ctx): TaskService
    {
        return new TaskService($ctx->db, self::materials($ctx));
    }

    public static function answerFiles(Context $ctx): AnswerFiles
    {
        return new AnswerFiles($ctx->db, (string) $ctx->config['uploads']['dir']);
    }

    public static function exams(Context $ctx): ExamService
    {
        return new ExamService($ctx->db, self::tasks($ctx), self::materials($ctx), self::answerFiles($ctx));
    }

    public static function export(Context $ctx): ExamExport
    {
        return new ExamExport($ctx->db, self::tasks($ctx), self::materials($ctx));
    }
}
