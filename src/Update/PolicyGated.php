<?php

declare(strict_types=1);

namespace TAW\Update;

/**
 * A migration that one of taw.json's on/off settings controls (scaffold,
 * manifests or docs). While that setting is off, the migration doesn't run
 * on its own: `update`, `composer update`'s hook and `upgrade --apply` list
 * it as held back by the policy, with how to do it by hand.
 */
interface PolicyGated
{
    /** The taw.json "update" setting: scaffold, manifests or docs. */
    public function policySetting(): string;
}
