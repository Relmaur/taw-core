<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use PHPUnit\Framework\TestCase;
use TAW\CLI\SyncCommand;

/**
 * Issue #194: GitHub's tags API answers a rate limit (60 requests an hour
 * without a token) with a JSON object, which used to reach the tag parser and
 * end `taw sync` with a TypeError.
 */
final class SyncTagsResponseTest extends TestCase
{
    public function test_the_highest_version_tag_wins(): void
    {
        $body = (string) json_encode([['name' => 'v1.9.0'], ['name' => 'v1.10.2'], ['name' => 'v1.10.10'], ['name' => 'not-a-version']]);

        $this->assertSame(['latest' => 'v1.10.10', 'error' => null], SyncCommand::latestFromTagsResponse($body));
    }

    public function test_a_rate_limit_is_reported_not_fatal(): void
    {
        $body = '{"message":"API rate limit exceeded for 203.0.113.7. (But here\'s the good news: Authenticated requests get a higher rate limit.)","documentation_url":"https://docs.github.com/rest/overview/resources-in-the-rest-api#rate-limiting"}';

        $result = SyncCommand::latestFromTagsResponse($body);

        $this->assertNull($result['latest']);
        $this->assertStringStartsWith('GitHub API: API rate limit exceeded', (string) $result['error']);
        $this->assertStringContainsString('GITHUB_TOKEN', (string) $result['error']);
    }

    public function test_other_api_errors_carry_githubs_message(): void
    {
        $result = SyncCommand::latestFromTagsResponse('{"message":"Bad credentials","documentation_url":"https://docs.github.com/rest"}');

        $this->assertSame(['latest' => null, 'error' => 'GitHub API: Bad credentials.'], $result);
    }

    public function test_unreachable_garbled_or_tagless_responses_are_errors(): void
    {
        $this->assertStringStartsWith('Could not reach', (string) SyncCommand::latestFromTagsResponse(false)['error']);
        $this->assertStringStartsWith('Unexpected response', (string) SyncCommand::latestFromTagsResponse('<html>')['error']);
        $this->assertStringStartsWith('No usable version tags', (string) SyncCommand::latestFromTagsResponse('[]')['error']);
        $this->assertStringStartsWith('No usable version tags', (string) SyncCommand::latestFromTagsResponse('["v1.0.0", 3]')['error'], 'list items that are not tag objects');
    }
}
