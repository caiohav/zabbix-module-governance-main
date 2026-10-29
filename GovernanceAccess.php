<?php

namespace Modules\Governance;

use CWebUser;

final class GovernanceAccessException extends \RuntimeException {}

/** The Zabbix module manager separately enforces the user's module role grant. */
final class GovernanceAccess {
    public static function canView(): bool {
        return CWebUser::isLoggedIn() && !CWebUser::isGuest()
            && CWebUser::getType() >= USER_TYPE_ZABBIX_USER;
    }

    public static function canConfigure(): bool {
        return self::canView() && CWebUser::getType() == USER_TYPE_SUPER_ADMIN;
    }

    /** A job must not outlive a role change that reduces its owner's privileges. */
    public static function jobContext(): array {
        return ['type' => CWebUser::getType(), 'roleid' => (string) (CWebUser::$data['roleid'] ?? '')];
    }

    public static function assertJobAccess(array $state): void {
        $context = $state['access_context'] ?? null;
        if (!self::canView() || ($context === null ? !self::canConfigure() : $context !== self::jobContext())) {
            throw new GovernanceAccessException('Calculation access changed; start again / O acesso ao cálculo mudou; inicie novamente.');
        }
    }
}
