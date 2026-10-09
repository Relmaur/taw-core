<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Guard;

use TAW\Core\Rag\RagSettings;
use TAW\Core\Rag\Usage\UsageMeter;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The hard per-request ceilings of one chat turn, and the worst case they
 * add up to.
 *
 * Every number that bounds how many tokens a single visitor message can
 * buy lives here, because two places must agree on them: the code that
 * enforces each cap ({@see \TAW\Core\Rag\Orchestrator\ChatOrchestrator},
 * {@see \TAW\Core\Rest\RagChatEndpoint}) and {@see self::worstCaseCostMicros()},
 * which the budget check reserves *before* the request runs. If a cap is
 * raised here, the reserve rises with it automatically — the budget can't
 * silently drift out of step with what a request is allowed to spend.
 */
final class ChatLimits
{
    /** Prior turns sent back to the model. */
    public const HISTORY_TURNS = 6;

    /** Each prior turn is cut to this many characters. */
    public const HISTORY_TURN_CHARS = 1500;

    /** A tool result's JSON is cut to this many characters before the model sees it. */
    public const TOOL_RESULT_CHARS = 6000;

    /** Tool calls honoured per model reply; extras get an error result instead of running. */
    public const TOOL_CALLS_PER_TURN = 3;

    /**
     * Characters per token used for the estimate. Real tokenizers average
     * ~4 for English prose; Spanish and JSON tokenize denser, so 3 keeps
     * the estimate on the expensive side, which is the safe side here.
     */
    private const CHARS_PER_TOKEN = 3;

    /** Overhead of the assistant message that carries one tool call. */
    private const TOOL_CALL_MESSAGE_CHARS = 300;

    /** The "answer now" nudge sent when the iteration cap is hit. */
    private const FINAL_NUDGE_CHARS = 200;

    /**
     * The most one chat request can cost with the current settings, in
     * micro-dollars.
     *
     * Each model call resends the whole conversation, so call k carries the
     * base prompt plus everything the k earlier tool rounds added. The loop
     * makes at most `maxToolIterations` calls, plus one forced final answer
     * when the cap is reached, and every call can produce up to
     * `maxOutputTokens`.
     */
    public static function worstCaseCostMicros(int $systemPromptChars, int $toolDefinitionChars): int
    {
        $baseChars = $systemPromptChars
            + $toolDefinitionChars
            + self::HISTORY_TURNS * self::HISTORY_TURN_CHARS
            + RagSettings::maxMessageChars();

        $charsAddedPerRound = self::TOOL_CALLS_PER_TURN * (self::TOOL_RESULT_CHARS + self::TOOL_CALL_MESSAGE_CHARS);
        $iterations = RagSettings::maxToolIterations();

        $inputChars = 0;
        for ($round = 0; $round < $iterations; $round++) {
            $inputChars += $baseChars + $round * $charsAddedPerRound;
        }
        $inputChars += $baseChars + $iterations * $charsAddedPerRound + self::FINAL_NUDGE_CHARS;

        $calls = $iterations + 1;

        return UsageMeter::costMicros(
            UsageMeter::KIND_CHAT,
            (int) ceil($inputChars / self::CHARS_PER_TOKEN),
            $calls * RagSettings::maxOutputTokens()
        );
    }
}
