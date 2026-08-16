<?php

namespace App\Support\Hl7;

use InvalidArgumentException;

/**
 * A parsed HL7 v2 message. Segment boundaries are the carriage return (the
 * HL7 standard) with a newline fallback for tolerant consumers; the field
 * and encoding characters are learned from the mandatory MSH segment, so a
 * message defines its own syntax (INTEROPERABILITY §HL7).
 *
 * This is the syntax layer only — it knows nothing about ORU semantics;
 * OruR01Parser / OruResultMapper interpret the segments.
 */
final class Hl7Message
{
    private string $fieldSeparator = '|';

    private string $componentSeparator = '^';

    private string $repetitionSeparator = '~';

    private string $escapeCharacter = '\\';

    private string $subcomponentSeparator = '&';

    /**
     * @var list<Hl7Segment>
     */
    private array $segments = [];

    public static function fromString(string $raw): self
    {
        $message = new self;

        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if ($lines === [] || ! str_starts_with($lines[0], 'MSH')) {
            throw new InvalidArgumentException('Not a valid HL7 message: the first segment must be MSH.');
        }

        // MSH: the field separator is the 4th character; the next four
        // characters are the encoding characters ^~\&.
        $message->fieldSeparator = $lines[0][3];
        $encoding = substr($lines[0], 4, 4);
        $message->componentSeparator = $encoding[0] ?? '^';
        $message->repetitionSeparator = $encoding[1] ?? '~';
        $message->escapeCharacter = $encoding[2] ?? '\\';
        $message->subcomponentSeparator = $encoding[3] ?? '&';

        $message->segments = array_map(
            static fn (string $line): Hl7Segment => new Hl7Segment(
                name: substr($line, 0, 3),
                raw: $line,
                fieldSeparator: $message->fieldSeparator,
                componentSeparator: $message->componentSeparator,
                repetitionSeparator: $message->repetitionSeparator,
                escapeCharacter: $message->escapeCharacter,
                subcomponentSeparator: $message->subcomponentSeparator,
            ),
            $lines
        );

        return $message;
    }

    /**
     * @return list<Hl7Segment>
     */
    public function segments(): array
    {
        return $this->segments;
    }

    /**
     * The first segment with the given name, or null.
     */
    public function segment(string $name): ?Hl7Segment
    {
        foreach ($this->segments as $segment) {
            if ($segment->name === $name) {
                return $segment;
            }
        }

        return null;
    }

    /**
     * All segments with the given name (repeating groups: PID → OBR → OBX*).
     *
     * @return list<Hl7Segment>
     */
    public function segmentsNamed(string $name): array
    {
        return array_values(array_filter(
            $this->segments,
            static fn (Hl7Segment $segment): bool => $segment->name === $name
        ));
    }
}
