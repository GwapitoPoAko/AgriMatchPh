<?php

namespace App\Listeners;

use App\Events\ContractSigned;
use App\Events\InspectionCompleted;
use App\Events\OfferResponded;
use App\Events\OfferSubmitted;
use App\Models\User;
use App\Services\NotificationService;

/**
 * Routes domain events to the multi-channel NotificationService
 * (web bell ledger + Semaphore SMS). Auto-discovered by Laravel:
 * each handle* method is bound to the event in its type-hint.
 */
class SendTransactionalNotifications
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function handleOfferSubmitted(OfferSubmitted $event): void
    {
        $this->notifications->offerReceived($event->offer);
    }

    public function handleOfferResponded(OfferResponded $event): void
    {
        $this->notifications->offerResponded($event->offer, $event->action, $event->counter);

        // A counter-offer is itself a new offer waiting on the other side.
        if ($event->counter) {
            $this->notifications->offerReceived($event->counter);
        }
    }

    public function handleContractSigned(ContractSigned $event): void
    {
        $contract = $event->contract;

        if ($event->activated) {
            $this->notifications->contractActivated($contract);

            return;
        }

        $pendingId = $event->party === 'buyer' ? $contract->seller_id : $contract->buyer_id;
        if ($pending = User::find($pendingId)) {
            $this->notifications->contractAwaitingSignature($contract, $pending);
        }
    }

    public function handleInspectionCompleted(InspectionCompleted $event): void
    {
        $this->notifications->inspectionCompleted($event->inspection);
    }
}
