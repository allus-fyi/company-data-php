<?php

declare(strict_types=1);

namespace Allus\CompanyData\Model;

use Allus\CompanyData\CountryData;

/**
 * The field-type registry — the whole of what a contact-field TYPE means.
 *
 * A type is a ROW, not a literal: the row says what its parent is, which primitive draws it,
 * which named check verifies it, which additive regexes it must match, which sub-fields it
 * carries and on which storage lane its value lives. The rows are served by
 * {@code GET /api/contact-field-types}; this class interprets them, so adding a type that
 * reuses existing primitives and checks is a row and nothing else.
 *
 * TWO FIXED VOCABULARIES, and only these two are code. {@see INPUTS} names the editor a value is
 * drawn with and {@see CHECKS} what is verified beyond a regex; a row may only name a member of
 * each, so a new member is code here rather than data.
 *
 * INHERITANCE. A child inherits any column it leaves null from its nearest ancestor that sets
 * it — {@code input}, {@code lane}, {@code check}, {@code options}, {@code fields}.
 * {@code validation} is the exception and is ADDITIVE: a value must match the regex of every
 * ancestor that has one, root first, plus the type's own. {@see resolve()} answers the row with
 * every inherited column filled in and the validations in that order, and every consumer works
 * on that resolved definition rather than on a raw row.
 *
 * Pinned case-for-case by {@code testdata/contract-field-validation-vector.json}.
 */
final class FieldTypes
{
    /** The storage lanes a value can live on. {@code inline} is the value itself; the other two are files. */
    public const LANES = ['inline', 'photo', 'document'];

    /** The drawing primitives a row may name. A new member is code here, not a row. */
    public const INPUTS = [
        'line', 'date', 'list', 'multilist', 'country', 'nationality', 'state', 'phone',
        'composite', 'file', 'pages',
    ];

    /** The named checks a row may name — verification beyond a regex. A new member is code here, not a row. */
    public const CHECKS = ['url', 'card', 'number', 'integer', 'decimal', 'float'];

    /**
     * The lanes each primitive can store on. {@code file} is the only primitive with a choice,
     * which is why a root with that input is the only row whose lane an operator picks.
     */
    public const INPUT_LANES = [
        'line' => ['inline'],
        'date' => ['inline'],
        'list' => ['inline'],
        'multilist' => ['inline'],
        'country' => ['inline'],
        'nationality' => ['inline'],
        'state' => ['inline'],
        'phone' => ['inline'],
        'composite' => ['inline'],
        'file' => ['photo', 'document'],
        'pages' => ['document'],
    ];

    /** The primitives a sub-field entry may name: no composite nesting and no binary. */
    public const ENTRY_INPUTS = ['line', 'date', 'list', 'country', 'nationality', 'state', 'phone'];

    /**
     * The members a {@code file}/{@code pages} envelope carries itself. They belong to the
     * primitive, so a {@code fields} entry may never claim one — the entries are the extra
     * metadata beside them.
     */
    public const ENVELOPE_MEMBERS = ['file', 'pages', 'original_name', 'mime_type', 'size', 'name', 'full', 'thumb'];

    /** The members ONE page of a {@code pages} envelope may carry. */
    public const PAGE_MEMBERS = ['label', 'file', 'original_name', 'mime_type', 'size'];

    /** The page slots the multi-page upload draws: a front, an optional back, repeatable extras. */
    public const PAGE_LABELS = ['front', 'back', 'additional'];

    /** The longest a stored {@code validation} regex may be. */
    public const MAX_VALIDATION_LENGTH = 200;

    /** The delimiters a stored regex is compiled with, first one the pattern does not contain. */
    private const DELIMITERS = ['~', '#', '%', '!', '@', ';', ':', ',', '|', '/'];

    private const URL_RE = '~^https?://[^\s/$.?#][^\s]*\.[^\s]{2,}$~i';
    private const MIME_RE = '~^[\w.+-]+/[\w.+-]+$~';
    private const PHONE_RE = '~^\+?\d{4,15}$~';
    private const CARD_RE = '~^\d{12,19}$~';
    private const DATE_RE = '~^(\d{4})-(\d{2})-(\d{2})$~';
    /** Numeric grammars accept ASCII digits only. */
    private const INTEGER_RE = '~^-?[0-9]+$~';
    /**
     * decimal(10,2) is a FIXED shape: up to 8 integer digits + up to 2 decimal digits
     * (10 significant digits total), never a per-field configurable precision.
     */
    private const DECIMAL_RE = '~^-?[0-9]{1,8}(\.[0-9]{1,2})?$~';
    /** Float accepts decimal or scientific notation. */
    private const FLOAT_RE = '~^-?[0-9]+(\.[0-9]+)?([eE][+-]?[0-9]+)?$~';

    /** @var array<string, array<string,mixed>> raw rows keyed by type */
    private array $rows = [];

    /** @var array<string, array<string,mixed>> resolved definitions, memoised */
    private array $resolved = [];

    /**
     * @param iterable<array<string,mixed>> $rows the raw {@code GET /api/contact-field-types} array.
     *
     * An instance with no rows knows no type, which is the honest answer for a client that has
     * not loaded the registry: every type resolves as unknown and validates as "accept anything".
     */
    public function __construct(iterable $rows = [])
    {
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['type']) && is_string($row['type']) && $row['type'] !== '') {
                $this->rows[$row['type']] = $row;
            }
        }
    }

    // ── the tree ────────────────────────────────────────────────────────────

    /**
     * The raw rows keyed by type. A wholly numeric identifier is an INT key here, because PHP
     * arrays hold no numeric-string key; {@see types()} answers the names as strings.
     *
     * @return array<array-key, array<string,mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * @return list<string> every type the registry carries
     *
     * A type identifier is {@code ^[a-z0-9_]{1,40}$}, which admits a wholly numeric name, and PHP
     * turns such an array key into an int. The names are cast back so a caller always receives the
     * identifier it was served — a strictly typed comparison against it holds, and passing one to
     * a string parameter does not raise.
     */
    public function types(): array
    {
        return array_map(strval(...), array_keys($this->rows));
    }

    /** Whether the registry carries this type at all. */
    public function knows(?string $type): bool
    {
        return $type !== null && isset($this->rows[$type]);
    }

    /**
     * The resolved definition: every inherited column filled in, validations root-first.
     *
     * A type the registry does not carry resolves to the UNKNOWN definition — every column
     * null, no validations, {@code known} false. That is a distinct answer from a known type
     * with nothing set, and callers must read it as "this client cannot draw or store this",
     * never as a default.
     *
     * @return array{type:string,parent:?string,label:string,is_system:bool,known:bool,input:?string,lane:?string,check:?string,options:?array,fields:?array,validations:list<string>}
     */
    public function resolve(?string $type): array
    {
        $key = $type ?? '';

        return $this->resolved[$key] ??= $this->resolveIn($key);
    }

    /** @return array<string,mixed> */
    private function resolveIn(string $type): array
    {
        if (!isset($this->rows[$type])) {
            return [
                'type' => $type,
                'parent' => null,
                'label' => $type,
                'is_system' => false,
                'known' => false,
                'input' => null,
                'lane' => null,
                'check' => null,
                'options' => null,
                'fields' => null,
                'validations' => [],
            ];
        }

        // Walk to the root collecting the chain, then fill downward: the nearest ancestor that
        // sets an inherited column wins, and the validations come out root-first.
        $chain = [];
        $seen = [];
        for ($cursor = $type; $cursor !== null && isset($this->rows[$cursor]) && !isset($seen[$cursor]); $cursor = $this->rows[$cursor]['parent'] ?? null) {
            $seen[$cursor] = true;
            $chain[] = $this->rows[$cursor];
        }
        $chain = array_reverse($chain);

        $row = $this->rows[$type];
        $definition = [
            'type' => $type,
            'parent' => $row['parent'] ?? null,
            'label' => (string) ($row['label'] ?? $type),
            'is_system' => (bool) ($row['is_system'] ?? false),
            'known' => true,
            'input' => null,
            'lane' => null,
            'check' => null,
            'options' => null,
            'fields' => null,
            'validations' => [],
        ];
        foreach ($chain as $ancestor) {
            foreach (['input', 'lane', 'check', 'options', 'fields'] as $column) {
                if (($ancestor[$column] ?? null) !== null) {
                    $definition[$column] = $ancestor[$column];
                }
            }
            $validation = $ancestor['validation'] ?? null;
            if (is_string($validation) && $validation !== '') {
                $definition['validations'][] = $validation;
            }
        }

        return $definition;
    }

    /**
     * The type and every descendant of it. An unknown type answers itself alone, so a lookup
     * keyed on a type the registry does not carry still addresses that type rather than nothing.
     *
     * @return list<string>
     */
    public function descendants(string $type): array
    {
        $out = [$type];
        $frontier = [$type => true];

        // Bounded by the number of rows: each pass adds only types not already collected.
        for ($guard = count($this->rows); $guard > 0 && $frontier !== []; $guard--) {
            $next = [];
            foreach ($this->rows as $candidate => $row) {
                // A numeric identifier comes back off the array as an int; the string is the name.
                $candidate = (string) $candidate;
                $parent = $row['parent'] ?? null;
                if ($parent !== null && isset($frontier[$parent]) && !in_array($candidate, $out, true)) {
                    $out[] = $candidate;
                    $next[$candidate] = true;
                }
            }
            $frontier = $next;
        }

        return $out;
    }

    /** Whether a request for {@code $requested} is answered by a field of {@code $actual}. */
    public function accepts(string $requested, string $actual): bool
    {
        return $actual === $requested || in_array($actual, $this->descendants($requested), true);
    }

    // ── storage lane ────────────────────────────────────────────────────────

    /** Whether this type's value is a file rather than an inline value. */
    public function isBinary(?string $type): bool
    {
        $lane = $this->resolve($type)['lane'];

        return $lane !== null && $lane !== 'inline';
    }

    /** Whether this type uses the document upload/storage lane. */
    public function isDocumentLike(?string $type): bool
    {
        return $this->resolve($type)['lane'] === 'document';
    }

    /** Whether this type carries the multi-page ID-document envelope. */
    public function isIdDocument(?string $type): bool
    {
        return $this->resolve($type)['input'] === 'pages';
    }

    /** @return list<string> every type on a lane other than {@code inline} */
    public function binaryTypes(): array
    {
        return array_values(array_filter($this->types(), fn (string $type): bool => $this->isBinary($type)));
    }

    /** @return list<string> every type on the {@code document} lane */
    public function documentLikeTypes(): array
    {
        return array_values(array_filter($this->types(), fn (string $type): bool => $this->isDocumentLike($type)));
    }

    /** @return list<string> every type drawn by the multi-page upload */
    public function idDocumentTypes(): array
    {
        return array_values(array_filter($this->types(), fn (string $type): bool => $this->isIdDocument($type)));
    }

    // ── derived sets ────────────────────────────────────────────────────────

    /**
     * A choice type whose options are supplied elsewhere. It is usable only where something
     * else carries them — a flow element — so it is offered for no contact field, no request
     * row and no claim.
     */
    public function isOptionLessChoice(string $type): bool
    {
        $definition = $this->resolve($type);

        return in_array($definition['input'], ['list', 'multilist'], true)
            && ($definition['options'] === null || $definition['options'] === []);
    }

    /**
     * The option domain a choice value is held to: the ROW's own resolved options when it carries
     * any, else the ones the caller supplies, and NEVER a merge of the two — a row that states its
     * domain owns it, and a row that states none borrows the caller's whole.
     *
     * {@code null} means neither source has a domain: an option-less row asked about with nothing
     * supplied. A value cannot be measured against that, so {@see validate()} refuses rather than
     * testing membership of an empty list, which would refuse every value including a legitimate
     * one.
     *
     * Public so a caller can RENDER exactly the domain the validator will enforce.
     *
     * @param ?list<string> $suppliedOptions
     * @return ?list<string>
     */
    public function optionsFor(?string $type, ?array $suppliedOptions = null): ?array
    {
        $rowOptions = $this->resolve($type)['options'];
        if (is_array($rowOptions) && $rowOptions !== []) {
            return array_values($rowOptions);
        }
        if ($suppliedOptions !== null && $suppliedOptions !== []) {
            return array_values($suppliedOptions);
        }

        return null;
    }

    /** @return list<string> the types a contact field, a request row or an admin field may declare */
    public function requestableTypes(): array
    {
        return array_values(array_filter(
            $this->types(),
            fn (string $type): bool => !$this->isOptionLessChoice($type),
        ));
    }

    /** @return list<string> the requestable set plus the option-less choice types a flow element supplies options for */
    public function flowTypes(): array
    {
        return array_values(array_unique([...$this->requestableTypes(), ...array_filter(
            $this->types(),
            fn (string $type): bool => $this->isOptionLessChoice($type),
        )]));
    }

    /**
     * The types an OAuth claim may declare: the requestable set on the {@code inline} lane. A
     * file can never be sealed to a relying party's app key, so no claim can name a binary type.
     *
     * @return list<string>
     */
    public function claimableTypes(): array
    {
        return array_values(array_filter(
            $this->requestableTypes(),
            fn (string $type): bool => $this->resolve($type)['lane'] === 'inline',
        ));
    }

    // ── display ─────────────────────────────────────────────────────────────

    /**
     * The label to render. A seeded row's {@code label} is the {@code fieldtype_*} translation
     * key and a data-added row's is the literal an operator typed; {@code is_system} is the
     * discriminator, and a literal is rendered verbatim rather than looked up.
     */
    public function labelFor(string $type): string
    {
        return $this->resolve($type)['label'];
    }

    /**
     * The requested types in display order: roots A→Z, each followed by its own children A→Z,
     * recursively, by the stored {@code label}. A requested type the registry does not carry
     * sorts after the tree, so a picker built from a stale set still shows every entry it was
     * given.
     *
     * @param list<string> $types
     * @return list<string>
     */
    public function ordered(array $types): array
    {
        $wanted = array_fill_keys($types, true);
        $rows = $this->rows;

        // Sorted as (label, type) PAIRS rather than by array key: an identifier may be wholly
        // numeric, and an array key would come back as an int and stop being the name it was served.
        $childrenOf = static function (?string $parent) use ($rows): array {
            $children = [];
            foreach ($rows as $type => $row) {
                if (($row['parent'] ?? null) === $parent) {
                    $children[] = [(string) ($row['label'] ?? $type), (string) $type];
                }
            }
            usort($children, static fn (array $a, array $b): int => strcasecmp($a[0], $b[0]) ?: strcmp($a[0], $b[0]));

            return array_map(static fn (array $pair): string => $pair[1], $children);
        };

        $out = [];
        $walk = function (?string $parent) use (&$walk, &$out, $childrenOf, $wanted): void {
            foreach ($childrenOf($parent) as $type) {
                if (isset($wanted[$type])) {
                    $out[] = $type;
                }
                $walk($type);
            }
        };
        $walk(null);

        $unknown = array_values(array_diff($types, $out));
        usort($unknown, static fn (string $a, string $b): int => strcasecmp($a, $b) ?: strcmp($a, $b));

        return [...$out, ...$unknown];
    }

    /**
     * The nearest ancestor, self included, that is {@code date} or {@code number}; otherwise the
     * type itself. It collapses a type to the domain its comparison operators are chosen from;
     * nothing in {@see validate()} consults it, and no value's shape follows it.
     */
    public function effectiveType(string $type): string
    {
        $seen = [];
        for ($cursor = $type; $cursor !== null && isset($this->rows[$cursor]) && !isset($seen[$cursor]); $cursor = $this->rows[$cursor]['parent'] ?? null) {
            $seen[$cursor] = true;
            if ($cursor === 'date' || $cursor === 'number') {
                return $cursor;
            }
        }

        return $type;
    }

    // ── validation ──────────────────────────────────────────────────────────

    /**
     * Validate a plaintext value against a type, in the one fixed order: the
     * primitive's own rule, then the resolved check, then every regex root-first, then the
     * sub-field entries. The CHECK's normalised value is what those regexes see; the
     * primitive's is not.
     *
     * An EMPTY value is valid — required is the caller's job — and a type the registry does not
     * carry accepts anything, which is the pinned answer for a client older than a type.
     *
     * @param ?list<string> $suppliedOptions the caller's own option list, for a choice type whose
     *                                        row states none of its own ({@see optionsFor()})
     * @return ?string null when valid, else the name of the first failing rule
     */
    public function validate(?string $type, mixed $value, ?array $suppliedOptions = null): ?string
    {
        $text = $value === null ? '' : (is_string($value) ? $value : (string) $value);
        if ($text === '') {
            return null;
        }
        $definition = $this->resolve($type);
        if (!$definition['known']) {
            return null;
        }

        $failure = $this->applyPrimitive($definition, $text, $this->optionsFor($type, $suppliedOptions));
        if ($failure !== null) {
            return $failure;
        }

        // A CHECK'S NORMALISATION CARRIES; A PRIMITIVE'S DOES NOT, and the asymmetry is the rule
        // rather than an oversight. A check states the canonical form of the value it verifies —
        // a URL with its scheme, a card number without its separators — so a regex a child adds
        // below it describes that form and is tested against it. A primitive draws a value it
        // does not rewrite, so nothing it does reaches the regex step.
        $matched = $text;
        if ($definition['check'] !== null) {
            $failure = self::applyCheck($definition['check'], $text);
            if ($failure !== null) {
                return $failure;
            }
            $matched = self::normaliseForCheck($definition['check'], $text);
        }
        foreach ($definition['validations'] as $regex) {
            if (!self::matches($regex, $matched)) {
                return 'validation';
            }
        }

        return null;
    }

    /**
     * True when {@code $value} is an acceptable plaintext for {@code $type}.
     *
     * @param ?list<string> $suppliedOptions
     */
    public function isFieldValueValid(?string $type, mixed $value, ?array $suppliedOptions = null): bool
    {
        return $this->validate($type, $value, $suppliedOptions) === null;
    }

    /**
     * null when valid, else the name of the first failing rule.
     *
     * @param ?list<string> $suppliedOptions
     */
    public function fieldValueError(?string $type, mixed $value, ?array $suppliedOptions = null): ?string
    {
        return $this->validate($type, $value, $suppliedOptions);
    }

    /**
     * The primitive's own rule, plus the sub-field entries for the three primitives that carry them.
     *
     * {@code $options} is the domain a choice value is held to, already resolved by
     * {@see optionsFor()}; null is "there is no domain", which is refused rather than tested.
     *
     * @param ?list<string> $options
     */
    private function applyPrimitive(array $definition, string $value, ?array $options): ?string
    {
        $input = $definition['input'];

        return match ($input) {
            'line', null => null,
            'date' => self::isCalendarDate($value) ? null : 'date',
            'list' => $options === null
                ? 'options_unavailable'
                : (in_array($value, $options, true) ? null : 'list'),
            'multilist' => $options === null
                ? 'options_unavailable'
                : (self::isOptionArray($value, $options) ? null : 'multilist'),
            'country', 'nationality' => in_array($value, CountryData::COUNTRY_CODES, true) ? null : $input,
            'state' => in_array($value, CountryData::US_STATE_CODES, true) ? null : 'state',
            'phone' => preg_match(self::PHONE_RE, str_replace([' ', '-', '(', ')', '.'], '', $value)) === 1 ? null : 'phone',
            'composite' => self::validateObject($value, $definition['fields'] ?? [], []),
            'file', 'pages' => self::validateObject($value, $definition['fields'] ?? [], self::ENVELOPE_MEMBERS),
            default => null,
        };
    }

    /**
     * A JSON object value: no unknown key, every required entry present, and each non-empty
     * entry valid for its own primitive, check and regex.
     *
     * {@code $envelopeMembers} are the primitive's own members, accepted beside the entries and
     * validated by {@see validateEnvelopeMember()} — the one home for what each of them looks
     * like.
     *
     * DECODED WITHOUT THE ASSOC FLAG, so the JSON CONTAINER KIND survives: an associative decode
     * answers the identical empty PHP array for {@code {}} and {@code []}, and no ordering of
     * emptiness and listness tests can tell them apart afterwards. A JSON object arrives here as a
     * stdClass and a JSON array as a PHP array, so the two shapes stay distinguishable and the
     * declared shape is the one enforced.
     */
    private static function validateObject(string $value, array $fields, array $envelopeMembers): ?string
    {
        $decoded = json_decode($value);
        if (!$decoded instanceof \stdClass) {
            return 'object';
        }
        // An all-digit JSON key becomes an int array key, so every key is cast back to its name.
        $object = get_object_vars($decoded);

        $entries = [];
        foreach ($fields as $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $entries[(string) $entry['key']] = $entry;
            }
        }

        foreach ($object as $key => $raw) {
            $key = (string) $key;
            if (isset($entries[$key])) {
                if (!is_string($raw)) {
                    return $key;
                }
                if ($raw !== '' && self::validateEntry($entries[$key], $raw) !== null) {
                    return $key;
                }
                continue;
            }
            if (!in_array($key, $envelopeMembers, true)) {
                return 'unknown_key';
            }
            $failure = self::validateEnvelopeMember($key, $raw);
            if ($failure !== null) {
                return $failure;
            }
        }

        foreach ($entries as $key => $entry) {
            if (!empty($entry['required']) && (!array_key_exists($key, $object) || $object[$key] === '')) {
                // A wholly numeric key is an int off the array; the failing rule's NAME is a string.
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * ONE member of a {@code file}/{@code pages} envelope, by its own shape — the single home for
     * what each member looks like, so a member added to the envelope is one branch here and
     * nothing else.
     *
     * {@code size} is a JSON integer, {@code pages} the multi-page list below, {@code mime_type} a
     * MIME string when it carries anything, and every other member a string.
     */
    private static function validateEnvelopeMember(string $key, mixed $raw): ?string
    {
        if ($key === 'pages') {
            return self::validatePages($raw);
        }
        if ($key === 'size') {
            return is_int($raw) ? null : 'size';
        }
        if (!is_string($raw)) {
            return $key;
        }
        if ($key === 'mime_type' && $raw !== '' && preg_match(self::MIME_RE, $raw) !== 1) {
            return 'mime_type';
        }

        return null;
    }

    /**
     * The {@code pages} member of an ID-document envelope: a LIST of page objects, never a scalar.
     *
     * Each page names one uploaded file plus that file's own metadata. {@code file} is the
     * reference and is required; {@code label} says which slot the page fills, and the slots are
     * exactly the ones the multi-page editor draws — a front, an optional back, and repeatable
     * extras. An empty list is a document whose pages have not been uploaded yet, which is a valid
     * envelope.
     */
    private static function validatePages(mixed $raw): ?string
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return 'pages';
        }
        foreach ($raw as $page) {
            if (!$page instanceof \stdClass) {
                return 'pages';
            }
            $members = get_object_vars($page);
            foreach ($members as $key => $member) {
                $key = (string) $key;
                if (!in_array($key, self::PAGE_MEMBERS, true)) {
                    return 'pages';
                }
                if ($key === 'label') {
                    if (!is_string($member) || !in_array($member, self::PAGE_LABELS, true)) {
                        return 'pages';
                    }
                    continue;
                }
                if ($key === 'file') {
                    if (!is_string($member) || $member === '') {
                        return 'pages';
                    }
                    continue;
                }
                if (self::validateEnvelopeMember($key, $member) !== null) {
                    return 'pages';
                }
            }
            if (!array_key_exists('file', $members)) {
                return 'pages';
            }
        }

        return null;
    }

    /** One sub-field entry: its primitive rule, then its check, then its regex. */
    private static function validateEntry(array $entry, string $value): ?string
    {
        $input = (string) ($entry['input'] ?? 'line');
        $options = is_array($entry['options'] ?? null) ? $entry['options'] : [];
        $failure = match ($input) {
            'date' => self::isCalendarDate($value) ? null : 'date',
            'list' => in_array($value, $options, true) ? null : 'list',
            'country', 'nationality' => in_array($value, CountryData::COUNTRY_CODES, true) ? null : $input,
            'state' => in_array($value, CountryData::US_STATE_CODES, true) ? null : 'state',
            'phone' => preg_match(self::PHONE_RE, str_replace([' ', '-', '(', ')', '.'], '', $value)) === 1 ? null : 'phone',
            default => null,
        };
        if ($failure !== null) {
            return $failure;
        }
        // The entry runs the same primitive → check → regex order a top-level value does, and
        // the check's normalisation carries into its regex for the same reason it does there —
        // so a composite's entry can never disagree with a value of the same shape.
        $matched = $value;
        $check = $entry['check'] ?? null;
        if (is_string($check) && $check !== '') {
            if (self::applyCheck($check, $value) !== null) {
                return $check;
            }
            $matched = self::normaliseForCheck($check, $value);
        }
        $regex = $entry['validation'] ?? null;
        if (is_string($regex) && $regex !== '' && !self::matches($regex, $matched)) {
            return 'validation';
        }

        return null;
    }

    /**
     * The CANONICAL FORM a named check verifies — and the form a regex below that check is
     * tested against, since the check is what states it.
     */
    public static function normaliseForCheck(string $check, string $value): string
    {
        return match ($check) {
            'url' => preg_match('#^https?://#i', $value) === 1 ? $value : 'https://' . $value,
            'card' => str_replace([' ', '-'], '', $value),
            'number', 'integer', 'decimal', 'float' => trim($value),
            default => $value,
        };
    }

    /** One named check, applied to the whole value in its canonical form. */
    public static function applyCheck(string $check, string $value): ?string
    {
        $normalised = self::normaliseForCheck($check, $value);
        $ok = match ($check) {
            'url' => preg_match(self::URL_RE, $normalised) === 1,
            'card' => preg_match(self::CARD_RE, $normalised) === 1 && self::luhnOk($normalised),
            'number' => $normalised !== '' && is_numeric($normalised) && is_finite((float) $normalised),
            'integer' => preg_match(self::INTEGER_RE, $normalised) === 1,
            'decimal' => preg_match(self::DECIMAL_RE, $normalised) === 1,
            'float' => preg_match(self::FLOAT_RE, $normalised) === 1,
            default => true,
        };

        return $ok ? null : $check;
    }

    /**
     * Whether a value matches a stored regex, which is anchored to the WHOLE value here.
     *
     * A pattern that cannot be compiled is refused at write, so reaching this with one means
     * the stored row predates the rule it is now held to: no verdict can be stated, and
     * refusing the value would refuse every value of that type.
     */
    public static function matches(string $regex, string $value): bool
    {
        $compiled = self::compile($regex);

        return $compiled === null || preg_match($compiled, $value) === 1;
    }

    /**
     * The delimited, whole-value-anchored PCRE pattern for a stored regex, or null when it
     * cannot be compiled.
     */
    public static function compile(string $regex): ?string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (!str_contains($regex, $delimiter)) {
                $pattern = $delimiter . '^(?:' . $regex . ')$' . $delimiter;

                // @preg_match is the answer being TESTED — whether this author-supplied pattern
                // compiles at all — and its failure is reported to the caller rather than discarded.
                return @preg_match($pattern, '') === false ? null : $pattern;
            }
        }

        return null;
    }

    /**
     * A JSON LIST whose every element is a declared option.
     *
     * Decoded WITHOUT the assoc flag for the same reason {@see validateObject()} is: a non-assoc
     * decode yields a PHP array only for a JSON array, so a JSON object cannot pass as an empty
     * list.
     *
     * @param list<string> $options
     */
    private static function isOptionArray(string $value, array $options): bool
    {
        $decoded = json_decode($value);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return false;
        }
        foreach ($decoded as $element) {
            if (!is_string($element) || !in_array($element, $options, true)) {
                return false;
            }
        }

        return true;
    }

    private static function isCalendarDate(string $value): bool
    {
        if (preg_match(self::DATE_RE, $value, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function luhnOk(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }
}
