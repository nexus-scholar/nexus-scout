<?php

namespace App\Enums;

enum ThreadStatus: string
{
    case ClarificationPending = 'clarification_pending';
    case Interviewing = 'interviewing';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Executing = 'executing';
}
