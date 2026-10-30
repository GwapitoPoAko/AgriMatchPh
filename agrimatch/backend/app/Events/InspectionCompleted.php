<?php

namespace App\Events;

use App\Models\QualityInspection;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** An inspector recorded a pass/fail result with a certified grade. */
class InspectionCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly QualityInspection $inspection)
    {
    }
}
