<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

/**
 * A run's answers as the company's service key opens them.
 *
 * {@see $answers} holds every answer the key opened, {@code [slug => plaintext]}; {@see $unreadable}
 * lists the slugs of the answers present on the run that it could not open (sealed to a key the
 * service has since replaced, or a wrong configured key), empty when every answer opened. An
 * unreadable slug is never in {@see $answers}.
 */
final class FlowRunAnswers
{
    /**
     * @param array<string,string> $answers
     * @param list<string> $unreadable
     */
    public function __construct(
        public readonly array $answers,
        public readonly array $unreadable = [],
    ) {
    }
}
