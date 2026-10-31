<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_ai_yared_course\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared protection for the resources every teacher uses at the same time.
 *
 * - Circuit breaker: when a provider (or one of its API keys) fails with quota, authentication,
 *   model or server errors, every user skips it for a short time and goes to the next one.
 * - Concurrency semaphore: limits simultaneous requests per provider key, so many background
 *   jobs do not exhaust the quota at once.
 * - Per-user rate limit for the external API.
 * - Per-user lock to create jobs without race conditions.
 *
 * State lives in a Moodle application cache and Moodle's lock factory, so it is shared by every
 * cron process and web request of the site.
 *
 * @package    local_ai_yared_course
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_guard {

    /** Lock component name. */
    protected const LOCKS = 'local_ai_yared_course';

    /**
     * Shared cache.
     *
     * @return \cache
     */
    protected static function cache(): \cache {
        return \cache::make('local_ai_yared_course', 'aiguard');
    }

    /**
     * Identifier of a provider + API key that never exposes the key.
     *
     * @param string $provider Provider key.
     * @param string $apikey API key.
     * @return string
     */
    public static function key(string $provider, string $apikey): string {
        return $provider . '_' . substr(sha1($apikey), 0, 10);
    }

    /**
     * Split a setting that may hold several API keys (one per line or separated by commas).
     *
     * @param string $apikey Raw setting.
     * @return string[] At least one element (an empty string when there is no key).
     */
    public static function split_keys(string $apikey): array {
        $keys = preg_split('/[\s,;]+/', trim($apikey), -1, PREG_SPLIT_NO_EMPTY);
        return $keys ? array_values(array_unique($keys)) : [''];
    }

    /**
     * Whether a provider key is temporarily skipped.
     *
     * @param string $key Value of key().
     * @return bool
     */
    public static function is_tripped(string $key): bool {
        $until = (int) self::cache()->get('trip_' . $key);
        return $until > time();
    }

    /**
     * Skip a provider key for some seconds, for every user.
     *
     * @param string $key Value of key().
     * @param int $seconds Seconds.
     */
    public static function trip(string $key, int $seconds): void {
        self::cache()->set('trip_' . $key, time() + max(5, $seconds));
    }

    /**
     * Forget a failure after a successful request.
     *
     * @param string $key Value of key().
     */
    public static function clear(string $key): void {
        self::cache()->delete('trip_' . $key);
    }

    /**
     * Round-robin counter used to rotate between several API keys of the same provider.
     *
     * @param string $name Counter name.
     * @param int $modulo Number of keys.
     * @return int Offset between 0 and modulo - 1.
     */
    public static function rotate(string $name, int $modulo): int {
        if ($modulo <= 1) {
            return 0;
        }
        $cache = self::cache();
        $next = (int) $cache->get('rr_' . $name);
        $cache->set('rr_' . $name, $next + 1);
        return $next % $modulo;
    }

    /**
     * Wait for a free request slot of a provider key.
     *
     * Returns null when the semaphore is disabled (setting 0) or no slot was free after the maximum
     * wait; in that case the request goes ahead anyway, so a stuck lock never blocks the teachers.
     *
     * @param string $key Value of key().
     * @return \core\lock\lock|null
     */
    public static function acquire(string $key): ?\core\lock\lock {
        $configured = get_config('local_ai_yared_course', 'aimaxconcurrent');
        $max = ($configured === false || $configured === '') ? 4 : (int) $configured;
        if ($max <= 0) {
            return null;
        }
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCKS);
        $waitsetting = (int) get_config('local_ai_yared_course', 'aislotwait');
        $wait = $waitsetting > 0 ? max(10, $waitsetting) : 300;
        $deadline = time() + $wait;
        do {
            for ($slot = 1; $slot <= $max; $slot++) {
                // A slot is released when the request ends; the lifetime only protects against a crashed process.
                $lock = $factory->get_lock('ai_' . $key . '_' . $slot, 0, 900);
                if ($lock) {
                    return $lock;
                }
            }
            sleep(1);
        } while (time() < $deadline);
        return null;
    }

    /**
     * Take one of a few concurrent slots a user may hold for an operation (for example rewriting questions).
     *
     * @param string $bucket Name of the operation.
     * @param int $userid User.
     * @param int $max Maximum simultaneous operations of that user.
     * @return \core\lock\lock|null Null when every slot is busy.
     */
    public static function acquire_user_slot(string $bucket, int $userid, int $max): ?\core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCKS);
        for ($slot = 1; $slot <= max(1, $max); $slot++) {
            // The lifetime only protects against a crashed process; the lock is released when the request ends.
            $lock = $factory->get_lock($bucket . '_u' . $userid . '_' . $slot, 0, 600);
            if ($lock) {
                return $lock;
            }
        }
        return null;
    }

    /**
     * Take the lock that serialises job creation for one user.
     *
     * @param int $userid User.
     * @return \core\lock\lock|null Null when the lock could not be obtained in 15 seconds.
     */
    public static function lock_user(int $userid): ?\core\lock\lock {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCKS);
        $lock = $factory->get_lock('user_' . $userid, 15, 120);
        return $lock ?: null;
    }

    /**
     * Count one call of a user and tell if the limit for the window was exceeded.
     *
     * @param string $bucket Name of the limited operation.
     * @param int $userid User.
     * @param int $limit Maximum calls in the window.
     * @param int $window Window in seconds.
     * @return bool True when the call must be rejected.
     */
    public static function rate_limited(string $bucket, int $userid, int $limit, int $window = 60): bool {
        if ($limit <= 0) {
            return false;
        }
        $cache = self::cache();
        $name = 'rate_' . $bucket . '_' . $userid;
        $now = time();
        $calls = json_decode((string) $cache->get($name), true);
        $calls = is_array($calls) ? array_values(array_filter($calls, function ($t) use ($now, $window) {
            return (int) $t > $now - $window;
        })) : [];
        if (count($calls) >= $limit) {
            $cache->set($name, json_encode($calls));
            return true;
        }
        $calls[] = $now;
        $cache->set($name, json_encode($calls));
        return false;
    }
}
