<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Metabox;

use TAW\Tests\TestCase;

/**
 * A repeater listens for its rows' changes by delegation, so every change
 * event the metabox form fires by hand must bubble; otherwise an edit inside
 * a row isn't saved in the block editor (#84).
 */
final class ChangeEventsBubbleTest extends TestCase
{
    public function test_every_hand_fired_change_event_bubbles(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4) . '/src/Core/Metabox/Metabox.php');

        preg_match_all("/new Event\('change'([^)]*)\)/", $source, $events);

        $this->assertNotEmpty($events[0]);
        foreach ($events[1] as $i => $options) {
            $this->assertStringContainsString('bubbles: true', $options, $events[0][$i]);
        }
    }
}
