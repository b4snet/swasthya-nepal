<?php

namespace App\Support\Hl7;

/**
 * One HL7 v2 segment (a single line such as MSH|^~\&|…|OBX|1|NM|…).
 * The encoding characters are learned from the MSH segment (INTEROPERABILITY
 * §HL7): the field separator is the character after "MSH"; MSH-2 carries the
 * component ^, repetition ~, escape \, and subcomponent & separators.
 *
 * Field, component, and repetition indexes are 1-based (HL7 convention);
 * out-of-range access returns '' — never throws.
 */
final class Hl7Segment
{
    public function __construct(
        public readonly string $name,
        public readonly string $raw,
        private readonly string $fieldSeparator,
        private readonly string $componentSeparator,
        private readonly string $repetitionSeparator,
        private readonly string $escapeCharacter,
        private readonly string $subcomponentSeparator,
    ) {}

    /**
     * All fields as raw strings (index 0 is the segment name, or the field
     * separator for MSH, whose numbering includes it as MSH-1).
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return explode($this->fieldSeparator, $this->raw);
    }

    /**
     * A single field (1-based; '' when absent). MSH is the one segment whose
     * numbering includes the field separator itself as MSH-1, so every other
     * MSH field is shifted by one relative to the raw split.
     */
    public function field(int $index): string
    {
        if ($this->name === 'MSH') {
            return $index === 1 ? $this->raw[3] : ($this->fields()[$index - 1] ?? '');
        }

        return $this->fields()[$index] ?? '';
    }

    /**
     * The components of a field (1-based).
     *
     * @return list<string>
     */
    public function components(int $field): array
    {
        return explode($this->componentSeparator, $this->field($field));
    }

    /**
     * A single component (1-based; '' when absent).
     */
    public function component(int $field, int $component): string
    {
        return $this->components($field)[$component - 1] ?? '';
    }

    /**
     * A single subcomponent (1-based; '' when absent).
     */
    public function subComponent(int $field, int $component, int $sub): string
    {
        return explode($this->subcomponentSeparator, $this->component($field, $component))[$sub - 1] ?? '';
    }

    /**
     * The repetitions of a field (split on '~'; at least one entry).
     *
     * @return list<string>
     */
    public function repetitions(int $field): array
    {
        $value = $this->field($field);

        return $value === '' ? [] : explode($this->repetitionSeparator, $value);
    }

    /**
     * A single repetition (1-based; '' when absent).
     */
    public function repetition(int $field, int $index): string
    {
        return $this->repetitions($field)[$index - 1] ?? '';
    }

    /**
     * Un-escape an HL7 field value (\F\ \S\ \T\ \R\ \E\ and \Xdddd\).
     */
    public function unescape(string $value): string
    {
        $map = [
            'F' => $this->fieldSeparator,
            'S' => $this->componentSeparator,
            'T' => $this->subcomponentSeparator,
            'R' => $this->repetitionSeparator,
            'E' => $this->escapeCharacter,
        ];

        return (string) preg_replace_callback('/\\\\([FSTRExX][0-9A-Fa-f]{0,4})\\\\/', function (array $m) use ($map): string {
            $code = $m[1];

            return $map[$code] ?? (str_starts_with($code, 'X') ? $this->hexToUtf8(substr($code, 1)) : '');
        }, $value);
    }

    private function hexToUtf8(string $hex): string
    {
        $bytes = '';
        foreach (str_split($hex, 2) as $pair) {
            $bytes .= chr((int) hexdec($pair));
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
    }
}
