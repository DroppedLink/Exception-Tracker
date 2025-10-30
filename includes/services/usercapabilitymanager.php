<?php

namespace ETracker\Services;

use WP_User;
use function get_userdata;

class UserCapabilityManager
{
    private string $capability;

    public function __construct(string $capability)
    {
        $this->capability = $capability;
    }

    public function grant(int $userId): void
    {
        $user = get_userdata($userId);
        if (! $user instanceof WP_User) {
            return;
        }

        $user->add_cap($this->capability);
    }

    public function revoke(int $userId): void
    {
        $user = get_userdata($userId);
        if (! $user instanceof WP_User) {
            return;
        }

        $user->remove_cap($this->capability);
    }

    public function user_has_cap(int $userId): bool
    {
        $user = get_userdata($userId);
        if (! $user instanceof WP_User) {
            return false;
        }

        return $user->has_cap($this->capability);
    }

    public function get_capability(): string
    {
        return $this->capability;
    }

    public function user_has_direct_cap(int $userId): bool
    {
        $user = get_userdata($userId);
        if (! $user instanceof WP_User) {
            return false;
        }

        return isset($user->caps[$this->capability]) && $user->caps[$this->capability];
    }
}


