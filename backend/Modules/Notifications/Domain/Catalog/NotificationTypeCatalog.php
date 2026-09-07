<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\Catalog;

use Modules\Collaboration\Application\Notifications\MentionedNotification;
use Modules\Collaboration\Application\Notifications\NewMessageNotification;
use Modules\Collaboration\Application\Notifications\TaskAssignedNotification;
use Modules\Collaboration\Application\Notifications\TaskFollowedNotification;
use Modules\Collaboration\Application\Notifications\TaskStatusChangedNotification;
use Modules\CostManagement\Application\Notifications\PricingReviewRequiredNotification;
use Modules\Operations\Loading\Application\Notifications\DriverAssignedNotification;
use Modules\Operations\Preparation\Application\Notifications\ShortageDetectedNotification;
use Modules\Operations\Preparation\Application\Notifications\WaveCompletedNotification;
use Modules\Operations\Preparation\Application\Notifications\WaveStartedNotification;

/**
 * TASK-ECOS-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-010 §8 — the canonical,
 * system-wide Notification Type Catalog. Every entry here describes a real,
 * already-dispatched producer class confirmed present in canonical source at
 * remediation time (a full backend-wide audit — see the task's own report) — nothing
 * on this list is aspirational or invented.
 *
 * Deliberately excluded: `ExceptionRaisedNotification` and `QualityCheckFailedNotification`
 * (Modules\Operations\Preparation) — both exist as classes but have zero production
 * dispatch sites anywhere in the codebase (confirmed by the same audit); cataloguing a
 * type a user could never actually receive would be exactly the "invented condition"
 * §8 says not to do. Same reasoning excludes `LargeSaleNotification`, referenced only in
 * a commented-out line in Modules\POS\Application\Listeners\PosNotificationListener — the
 * class does not even exist — and Marketing\ProviderPlatform's provider-health alerts,
 * which today only `Log::channel('slack')->warning(...)` per that listener's own
 * docblock ("The Notification OS is not yet implemented") rather than writing to the
 * `notifications` table at all.
 *
 * TASK-ECOS-COMMERCE-IAM-NOTIFICATIONS-FINAL-USER-REVIEW-REMEDIATION-005 D2 — a repeat
 * whole-backend dispatch-site audit (`Notification::send(`, `->notify(`) found one more
 * real, already-dispatched producer this catalog had missed: `TaskFollowedNotification`
 * (Modules\Collaboration\Application\Actions\FollowTaskAction). Added below.
 *
 * This is the ONE place a new configurable notification type is registered — do not
 * duplicate this list in a migration, a frontend constant, or anywhere else.
 */
final class NotificationTypeCatalog
{
    /** @return list<NotificationTypeDefinition> */
    public static function all(): array
    {
        return [
            new NotificationTypeDefinition(
                key: 'pricing_review_required',
                notificationClass: PricingReviewRequiredNotification::class,
                module: 'pricing',
                nameAr: 'مراجعة سعر مطلوبة',
                descriptionAr: 'يظهر عندما يؤدي تغيّر تكلفة منتج إلى فتح مراجعة سعر جديدة تحتاج قرارك.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: true,
                recipientAuthority: 'NotifyPricingReviewCreated: every user in the event\'s company for whom '
                    .'AuthorizationGateway::decision($user, "cost.price_review.view") is allowed.',
            ),
            new NotificationTypeDefinition(
                key: 'shortage_detected',
                notificationClass: ShortageDetectedNotification::class,
                module: 'preparation',
                nameAr: 'نقص في المواد الخام',
                descriptionAr: 'يظهر عند تحليل احتياجات موجة تجهيز واكتشاف نقص في المواد الخام المطلوبة، مما يوقف الموجة.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: false,
                recipientAuthority: 'AnalyzeMaterialsAction: the acting user who ran the materials analysis, directly.',
            ),
            new NotificationTypeDefinition(
                key: 'wave_started',
                notificationClass: WaveStartedNotification::class,
                module: 'preparation',
                nameAr: 'تكليف بموجة تجهيز',
                descriptionAr: 'يظهر عند بدء موجة تجهيز وتكليفك بالعمل عليها.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: false,
                recipientAuthority: 'StartPreparationAction: each worker individually assigned to the started wave.',
            ),
            new NotificationTypeDefinition(
                key: 'wave_completed',
                notificationClass: WaveCompletedNotification::class,
                module: 'preparation',
                nameAr: 'اكتمال موجة تجهيز',
                descriptionAr: 'يظهر عند اكتمال موجة تجهيز قمت بإنشائها ووصول منتجاتها إلى مجمع المنتجات الجاهزة.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: false,
                recipientAuthority: 'CompleteWaveAction: the wave\'s original creator only.',
            ),
            new NotificationTypeDefinition(
                key: 'driver_assigned',
                notificationClass: DriverAssignedNotification::class,
                module: 'driver',
                nameAr: 'تكليف بمركبة',
                descriptionAr: 'يظهر للسائق عند تكليفه بقيادة مركبة ضمن جلسة تحميل.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: true,
                recipientAuthority: 'NotifyDriverAssigned: the specific driver named in the DriverAssigned event only '
                    .'(Driver::user() — never a broadcast, never IAM-permission-based).',
            ),
            new NotificationTypeDefinition(
                key: 'collaboration_new_message',
                notificationClass: NewMessageNotification::class,
                module: 'collaboration',
                nameAr: 'رسالة جديدة',
                descriptionAr: 'يظهر عند إرسال رسالة جديدة في محادثة أنت مشارك فيها.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: false,
                recipientAuthority: 'SendMessageAction: every active conversation participant except the sender and except anyone @mentioned in the same message.',
            ),
            new NotificationTypeDefinition(
                key: 'collaboration_mention',
                notificationClass: MentionedNotification::class,
                module: 'collaboration',
                nameAr: 'إشارة باسمك',
                descriptionAr: 'يظهر عند ذكر اسمك تحديدًا داخل رسالة في إحدى المحادثات.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: false,
                recipientAuthority: 'SendMessageAction: active participants explicitly @mentioned in the message.',
            ),
            new NotificationTypeDefinition(
                key: 'collaboration_task_assigned',
                notificationClass: TaskAssignedNotification::class,
                module: 'collaboration',
                nameAr: 'تكليف بمهمة',
                descriptionAr: 'يظهر عند تكليفك بمهمة جديدة أو إعادة تكليفك بمهمة من شخص آخر.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: false,
                recipientAuthority: 'CreateTaskAction / ReassignTaskAction: the task\'s assignee, only when assigned by someone else.',
            ),
            new NotificationTypeDefinition(
                key: 'collaboration_task_status_changed',
                notificationClass: TaskStatusChangedNotification::class,
                module: 'collaboration',
                nameAr: 'تغيّر حالة مهمة',
                descriptionAr: 'يظهر عند تغيير حالة مهمة تشارك فيها (كمُنشئ أو مكلَّف) من الطرف الآخر.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: false,
                recipientAuthority: 'TransitionTaskStatusAction: whichever of {creator, assignee} did not perform the transition.',
            ),
            new NotificationTypeDefinition(
                key: 'collaboration_task_followed',
                notificationClass: TaskFollowedNotification::class,
                module: 'collaboration',
                nameAr: 'متابعة مهمة',
                descriptionAr: 'يظهر عند إضافتك كمتابع لمهمة من قبل شخص آخر.',
                defaultEnabled: true,
                userCanDisable: true,
                hasDestination: false,
                recipientAuthority: 'FollowTaskAction: the newly-added follower, only when added by someone else (never on a self-follow).',
            ),
        ];
    }

    public static function findByNotificationClass(string $notificationClass): ?NotificationTypeDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->notificationClass === $notificationClass) {
                return $definition;
            }
        }

        return null;
    }

    public static function findByKey(string $key): ?NotificationTypeDefinition
    {
        foreach (self::all() as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }

        return null;
    }
}
