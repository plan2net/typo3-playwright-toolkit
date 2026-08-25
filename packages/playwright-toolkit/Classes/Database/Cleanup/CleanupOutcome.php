<?php

declare(strict_types=1);

namespace Plan2net\PlaywrightToolkit\Database\Cleanup;

enum CleanupOutcome: string
{
    case Dropped = 'dropped';
    case Absent = 'absent';
    case Unclaimed = 'unclaimed';
    case Refused = 'refused';
    case Failed = 'failed';
}
