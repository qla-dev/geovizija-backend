<?php

namespace App\Services;

/**
 * Login for the admin panel (frontend /admin): one fixed account, and a token signed with APP_KEY
 * that admin routes accept next to ADMIN_API_TOKEN (EnsureAdminToken).
 */
class AdminPanel
{
    public const USERNAME = 'qla.dev';

    public const PASSWORD = 'password123';

    private const DAYS = 30;

    public static function check(string $username, string $password): bool
    {
        return hash_equals(self::USERNAME, mb_strtolower(trim($username))) && hash_equals(self::PASSWORD, $password);
    }

    /** "panel.<expiry>.<signature>" */
    public static function issue(): string
    {
        $expires = now()->addDays(self::DAYS)->getTimestamp();

        return "panel.{$expires}.".self::sign($expires);
    }

    public static function valid(string $token): bool
    {
        if (! preg_match('/^panel\.(\d+)\.([a-f0-9]{64})$/', $token, $match)) {
            return false;
        }

        return (int) $match[1] > now()->getTimestamp() && hash_equals(self::sign((int) $match[1]), $match[2]);
    }

    private static function sign(int $expires): string
    {
        return hash_hmac('sha256', "geovizija-admin-panel|{$expires}", (string) config('app.key'));
    }
}
