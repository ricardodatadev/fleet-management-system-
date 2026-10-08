<?php

namespace App\Enums;

/** Ações fixas do audit trail (spec F.1). */
enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Restored = 'restored';
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordChanged = 'password_changed';
    case SettingChanged = 'setting_changed';
    case SettingRemoved = 'setting_removed';
}
