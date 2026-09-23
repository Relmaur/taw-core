<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

// No ABSPATH guard: pure class definition (see Editing\Presets).

/**
 * Who is exempt from the content, site and features layers (ADR-0005 § 4).
 *
 * A user bypasses when they have the policy's capability (default
 * taw_unlock_editing). Users listed in TAW_EDITING_BYPASS_USERS
 * (wp-config.php) are granted that capability through user_has_cap, so a
 * single current_user_can() check covers both, and a client with the
 * Administrator role is still locked unless named.
 */
final class Bypass
{
    /**
     * @param list<string> $users Logins from TAW_EDITING_BYPASS_USERS.
     */
    public function __construct(
        public readonly string $capability,
        public readonly array $users,
    ) {
    }

    public static function fromPolicy(Policy $policy): self
    {
        return new self(
            $policy->bypassCapability,
            self::parseUsers(defined('TAW_EDITING_BYPASS_USERS') ? constant('TAW_EDITING_BYPASS_USERS') : null)
        );
    }

    /**
     * TAW_EDITING_BYPASS_USERS as a list of logins. It may be an array or a
     * comma-separated string; anything else means nobody.
     *
     * @return list<string>
     */
    public static function parseUsers(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }

        $users = [];
        foreach ($value as $login) {
            if (is_string($login) && trim($login) !== '') {
                $users[] = trim($login);
            }
        }

        return array_values(array_unique($users));
    }

    public function register(): void
    {
        add_filter('user_has_cap', [$this, 'grant'], 10, 4);
    }

    /**
     * user_has_cap filter: named users get the bypass capability.
     *
     * @param array<string, bool> $allcaps
     * @param array<int, string>  $caps
     * @param array<int, mixed>   $args
     * @return array<string, bool>
     */
    public function grant(array $allcaps, array $caps, array $args, mixed $user): array
    {
        if (in_array($this->capability, $caps, true)
            && $user instanceof \WP_User
            && in_array($user->user_login, $this->users, true)
        ) {
            $allcaps[$this->capability] = true;
        }

        return $allcaps;
    }

    /**
     * Whether the current user is exempt. No user (wp-cli, cron, anonymous
     * requests) is never exempt, but those never open an editor either.
     */
    public function active(): bool
    {
        return current_user_can($this->capability);
    }
}
