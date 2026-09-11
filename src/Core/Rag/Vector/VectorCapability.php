<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Vector;

/**
 * Runtime detection for the sqlite-vec loadable extension. No ABSPATH
 * guard — pure PDO, no WordPress dependency.
 *
 * Confirmed absent on every target host as of this writing (Local by
 * Flywheel dev sites and the live fleet) — {@see VectorRepository} treats
 * this as the exception path, not the default, and {@see self::setOverrideForTests()}
 * is how tests exercise that branch deterministically without the real
 * extension actually being installed anywhere in CI or local dev.
 *
 * `PDO::loadExtension()` only exists on PHP 8.4+'s `Pdo\Sqlite`
 * driver-specific subclass (PHP RFC "PDO driver-specific subclasses"), and
 * only on connections actually constructed as that subclass — a plain
 * `new PDO('sqlite:...')` (what {@see \TAW\Core\Rag\Storage::openSqlite()}
 * uses today) never has it, on any PHP version. The method is therefore
 * called via reflection behind a `method_exists()` guard rather than a
 * direct `$pdo->loadExtension()` call, which would be a hard error on
 * 8.2/8.3 and on any ordinary `PDO` connection — this degrades cleanly to
 * "unavailable" everywhere today, and starts working transparently the
 * day a caller opens the DB as `Pdo\Sqlite` on PHP 8.4+.
 */
final class VectorCapability
{
    private static ?bool $override = null;

    public static function sqliteVecAvailable(\PDO $pdo): bool
    {
        if (self::$override !== null) {
            return self::$override;
        }

        if (!method_exists($pdo, 'loadExtension')) {
            return false;
        }

        try {
            (new \ReflectionMethod($pdo, 'loadExtension'))->invoke($pdo, self::extensionFilename());
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Force sqliteVecAvailable() to a fixed value — tests only. Pass null
     * to restore real detection.
     */
    public static function setOverrideForTests(?bool $available): void
    {
        self::$override = $available;
    }

    private static function extensionFilename(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'vec0.dylib',
            'Windows' => 'vec0.dll',
            default => 'vec0.so',
        };
    }
}
