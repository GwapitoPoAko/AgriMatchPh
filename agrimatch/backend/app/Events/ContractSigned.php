<?php

namespace App\Events;

use App\Models\Contract;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** One party signed a contract. `$activated` is true when that completed both signatures. */
class ContractSigned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Contract $contract,
        public readonly string $party,
        public readonly bool $activated,
    ) {
    }
}
