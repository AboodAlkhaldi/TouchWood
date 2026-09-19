<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateOwnNotificationPreferences;

use Illuminate\Database\Connection;
use Modules\Access\Application\Authorization\GrantRules;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Public\Enums\StaffNotificationTopic;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

final readonly class UpdateOwnNotificationPreferencesHandler
{
    public const string PERMISSION = AccessPermissions::OWN_ACCOUNT_UPDATE;

    public function __construct(
        private Authorizer $authorizer,
        private GrantRules $rules,
        private NotificationPreferenceRepository $preferences,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function handle(UpdateOwnNotificationPreferences $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $staffId = $this->rules->currentStaffId();

        $wanted = [];

        foreach ($command->preferences as $topic => $toggles) {
            $wanted[] = [
                StaffNotificationTopic::tryFrom($topic) ?? throw new InvalidAccessAttribute('topic', "\"{$topic}\" is not a notification topic"),
                $toggles['email'],
                $toggles['panel'],
            ];
        }

        $this->db->transaction(function () use ($staffId, $wanted): void {
            $current = $this->preferences->of($staffId);
            $changes = AuditChanges::none();

            foreach ($wanted as [$topic, $email, $panel]) {
                $was = $current[$topic->value];

                if ($was['email'] === $email && $was['panel'] === $panel) {
                    continue;
                }

                $this->preferences->set($staffId, $topic, $email, $panel);
                $changes->changed(strtolower($topic->value), [$was['email'], $was['panel']], [$email, $panel]);
            }

            if (! $changes->isEmpty()) {
                $this->platform->recordAudit(new AuditEntryDto('access.staff_user.notification_preferences_updated', 'access.staff_user', $staffId, null, $changes));
            }
        });
    }
}
