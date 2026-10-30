<?php

namespace App\Services;

use App\Jobs\SendSmsJob;
use App\Models\AppNotification;
use App\Models\B2bOffer;
use App\Models\Contract;
use App\Models\FreightPool;
use App\Models\HarvestListing;
use App\Models\QualityInspection;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Sms\SemaphoreClient;
use Illuminate\Support\Facades\DB;

/**
 * Multi-channel notification engine.
 *
 * Every notification is first written to the persistent `notifications`
 * ledger (powers the web bell drawer). Types listed in
 * config('agrimatch.notifications.sms_types') additionally create an
 * `sms_logs` row and dispatch SendSmsJob → Semaphore, so farmers on basic
 * feature phones get offers and contract updates as text messages.
 *
 * SMS jobs are dispatched after the surrounding DB transaction commits, so a
 * rolled-back offer never texts anyone.
 */
class NotificationService
{
    public function notify(
        User $user,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $actionUrl = null,
        string $priority = 'normal',
        ?bool $sms = null,
    ): AppNotification {
        $user->loadMissing('profile');
        $wantsSms = ($sms ?? in_array($type, config('agrimatch.notifications.sms_types', []), true))
            && ($user->profile?->sms_opt_in ?? false);
        $number = $wantsSms ? SemaphoreClient::normalizeNumber($user->phone) : null;

        return DB::transaction(function () use ($user, $type, $title, $body, $data, $actionUrl, $priority, $number) {
            $notification = AppNotification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => mb_substr($title, 0, 160),
                'body' => mb_substr($body, 0, 500),
                'data' => $data ?: null,
                'action_url' => $actionUrl,
                'priority' => $priority,
                'channels' => $number ? 'database,sms' : 'database',
            ]);

            if ($number) {
                $message = SemaphoreClient::sanitize("AgriMatch: {$title}. {$body}", (int) config('agrimatch.notifications.sms_max_length', 160));
                $log = SmsLog::create([
                    'notification_id' => $notification->id,
                    'user_id' => $user->id,
                    'recipient' => $number,
                    'message' => $message,
                    'sender_name' => config('services.semaphore.sender_name', 'AGRIMATCH'),
                    'provider' => 'semaphore',
                    'segments' => (int) ceil(max(1, mb_strlen($message)) / 160),
                ]);
                SendSmsJob::dispatch($log->id);
            }

            return $notification;
        });
    }

    /* ------------------------------------------------------------------ */
    /* Domain events                                                       */
    /* ------------------------------------------------------------------ */

    public function offerReceived(B2bOffer $offer): void
    {
        $offer->loadMissing(['listing.variety', 'buyer.profile']);
        $recipient = User::find($offer->counterpartyId());
        if (! $recipient) {
            return;
        }
        $from = $offer->buyer?->profile?->business_name ?? $offer->buyer?->name;
        $crop = $offer->listing?->variety?->name ?? 'your listing';

        $this->notify(
            $recipient,
            'offer.received',
            "New offer on {$crop}",
            sprintf('%s offered %s kg at ₱%s/kg (₱%s total). Reply within 5 days. Ref %s.',
                $from, number_format($offer->volume_kg), number_format($offer->unit_price, 2), number_format($offer->total_amount, 0), $offer->reference_code),
            ['offer_id' => $offer->id, 'listing_id' => $offer->harvest_listing_id],
            '/offers',
            'high',
        );
    }

    public function offerResponded(B2bOffer $offer, string $action, ?B2bOffer $counter = null): void
    {
        $offer->loadMissing('listing.variety');
        $author = User::find($offer->offered_by_id);
        if (! $author) {
            return;
        }
        $crop = $offer->listing?->variety?->name ?? 'listing';

        [$type, $title, $body] = match ($action) {
            'accept' => ['offer.accepted', "Offer accepted — {$crop}", "Your offer {$offer->reference_code} was accepted. Sign the draft contract to lock it in."],
            'reject' => ['offer.rejected', "Offer declined — {$crop}", "Your offer {$offer->reference_code} was declined. Browse similar lots on the marketplace."],
            'counter' => ['offer.countered', "Counter-offer on {$crop}", sprintf('Counter at ₱%s/kg for %s kg. Ref %s.',
                number_format($counter?->unit_price ?? 0, 2), number_format($counter?->volume_kg ?? 0), $counter?->reference_code)],
            default => [null, null, null],
        };

        if ($type) {
            $this->notify($author, $type, $title, $body, ['offer_id' => $offer->id, 'counter_offer_id' => $counter?->id], '/offers', 'high');
        }
    }

    public function contractAwaitingSignature(Contract $contract, User $pendingSigner): void
    {
        $this->notify(
            $pendingSigner,
            'contract.awaiting_signature',
            "Sign contract {$contract->contract_number}",
            sprintf('%s kg at ₱%s/kg — ₱%s. Review and sign to activate delivery.',
                number_format($contract->agreed_volume_kg), number_format($contract->agreed_unit_price, 2), number_format($contract->total_value, 0)),
            ['contract_id' => $contract->id],
            '/contracts',
            'high',
        );
    }

    public function contractActivated(Contract $contract): void
    {
        foreach ([$contract->buyer_id, $contract->seller_id] as $userId) {
            if ($user = User::find($userId)) {
                $this->notify(
                    $user,
                    'contract.activated',
                    "Contract {$contract->contract_number} is active",
                    sprintf('Both parties signed. Delivery window %s – %s.',
                        $contract->delivery_start?->format('M j'), $contract->delivery_end?->format('M j, Y')),
                    ['contract_id' => $contract->id],
                    '/contracts',
                );
            }
        }
    }

    public function poolAllocations(Contract $contract): void
    {
        $contract->loadMissing(['allocations.farmer.profile', 'listing.variety']);
        foreach ($contract->allocations as $allocation) {
            $this->notify(
                $allocation->farmer,
                'coop_pool.allocation',
                'Your coop share was sold',
                sprintf('%s kg of your %s allocated to contract %s. Net payout ₱%s after %s%% coop fee.',
                    number_format($allocation->allocated_volume_kg), $contract->listing?->variety?->name ?? 'produce',
                    $contract->contract_number, number_format($allocation->net_payout, 0), rtrim(rtrim(number_format($allocation->coop_fee_pct, 2), '0'), '.')),
                ['contract_id' => $contract->id, 'allocation_id' => $allocation->id],
                '/contracts',
            );
        }
    }

    public function joinedCoopPool(HarvestListing $member, HarvestListing $parent): void
    {
        $member->loadMissing('seller.profile', 'variety');
        $this->notify(
            $member->seller,
            'coop_pool.joined',
            'Added to a coop pool',
            sprintf('%s kg of your %s was pooled into %s (%s kg total). You will be paid pro-rata when it sells.',
                number_format($member->pooled_volume_kg), $member->variety?->name, $parent->reference_code, number_format($parent->stock_volume_kg)),
            ['listing_id' => $member->id, 'parent_listing_id' => $parent->id],
            '/marketplace',
        );
    }

    public function inspectionCompleted(QualityInspection $inspection): void
    {
        $inspection->loadMissing('listing.seller.profile');
        $listing = $inspection->listing;
        $passed = $inspection->status === QualityInspection::STATUS_PASSED;
        $grade = config("agrimatch.inspection.grades.{$inspection->certified_grade}", 'Ungraded');

        $this->notify(
            $listing->seller,
            'inspection.completed',
            $passed ? "{$listing->reference_code} certified {$grade}" : "{$listing->reference_code} failed inspection",
            $passed
                ? "Certificate {$inspection->certificate_no}. Your listing now shows the {$grade} badge."
                : 'Reason: ' . mb_substr((string) $inspection->remarks, 0, 120) . ' Re-sort and request re-inspection.',
            ['inspection_id' => $inspection->id, 'listing_id' => $listing->id],
            '/marketplace',
            $passed ? 'normal' : 'high',
        );
    }

    public function inspectionDeclined(QualityInspection $inspection): void
    {
        $inspection->loadMissing('listing.seller.profile');
        $this->notify(
            $inspection->listing->seller,
            'inspection.declined',
            "Inspection request declined: {$inspection->listing->reference_code}",
            'Reason: ' . mb_substr((string) $inspection->remarks, 0, 350),
            ['inspection_id' => $inspection->id, 'listing_id' => $inspection->harvest_listing_id],
            '/marketplace',
            'high',
        );
    }

    public function inspectionStarted(QualityInspection $inspection): void
    {
        $inspection->loadMissing('listing.seller.profile');
        $this->notify(
            $inspection->listing->seller,
            'inspection.started',
            "Inspection accepted: {$inspection->listing->reference_code}",
            'An inspector accepted your quality inspection request and has started the review.',
            ['inspection_id' => $inspection->id, 'listing_id' => $inspection->harvest_listing_id],
            '/marketplace',
            'normal',
        );
    }

    public function inspectionRequested(QualityInspection $inspection): void
    {
        $inspectors = User::where('role', User::ROLE_INSPECTOR)->where('status', 'active')
            ->when($inspection->inspector_id, fn ($q) => $q->whereKey($inspection->inspector_id))
            ->limit(5)->get();

        foreach ($inspectors as $inspector) {
            $this->notify(
                $inspector,
                'inspection.requested',
                "Inspection requested: {$inspection->listing->reference_code}",
                sprintf('%s — %s, %s. Schedule a visit.', $inspection->listing->title, $inspection->listing->municipality, $inspection->listing->province?->name),
                ['inspection_id' => $inspection->id],
                '/inspections',
            );
        }
    }

    public function freightPoolUpdated(FreightPool $pool, string $type, string $title, string $body): void
    {
        $pool->loadMissing('activeMembers.user.profile');
        $notified = [];
        foreach ($pool->activeMembers as $member) {
            if (isset($notified[$member->user_id])) {
                continue;
            }
            $notified[$member->user_id] = true;
            $this->notify($member->user, $type, $title, $body, ['freight_pool_id' => $pool->id], '/freight');
        }
        if ($pool->driver_id && ($driver = User::find($pool->driver_id)) && ! isset($notified[$driver->id])) {
            $this->notify($driver, $type, $title, $body, ['freight_pool_id' => $pool->id], '/freight');
        }
    }
}
