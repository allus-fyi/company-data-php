<?php

declare(strict_types=1);

namespace Allus\CompanyData;

use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Errors\ApiError;

/**
 * A document leaf's participant PDF sources and the generation inputs they need.
 *
 * A leaf output rule's PDF is a company template (`asset_key`), a flow field's answer
 * (`source_field: slug` → source key `field:<slug>`) or what a bound customer shared on its
 * connection (`source_connection: {party, request_slug}` → `conn:<party>:<request_slug>`). The
 * generating party uploads its own copy of every HELD source of the run's current leaf, sealed under
 * the call's one-time key, before it calls `/generate`; the server refuses a generate whose inputs
 * are not exactly the held set.
 *
 * A held source is `['source_key' => …, 'kind' => 'field'|'conn', 'slug' => ?string, 'file' => …]`:
 * a `field` source names the flow field and the generating party's own answer file, a `conn` source
 * the generating party's own copy made at run start.
 */
final class FlowSources
{
    /**
     * The file a plaintext `{"_enc_file": file, …}` answer value names, else null. A captured,
     * uploaded or frozen-linked file answer is that plaintext reference, never a ciphertext wrapper;
     * every other answer value is a wrapper and answers null.
     */
    public static function fileRef(mixed $value): ?string
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }
        if (is_array($value) && is_string($value['_enc_file'] ?? null) && $value['_enc_file'] !== '') {
            return $value['_enc_file'];
        }

        return null;
    }

    /**
     * The held set of the leaf `$nodeKey`, in rule order, each source key once.
     *
     * Reads every rule of every output of the leaf (a leaf with the older `pdfs` list carries
     * template rules only). `field:<slug>` is held when the generating party's own answer copy for
     * the slug (`for_user_id === $ownUserId`) is a file reference; `conn:<party>:<slug>` when
     * `$sourceFiles` (the run read's own copies) names it.
     *
     * @param array<string,mixed>       $definition
     * @param list<array<string,mixed>> $answers
     * @param array<string,string>      $sourceFiles
     * @return list<array{source_key: string, kind: string, slug: ?string, file: string}>
     */
    public static function held(array $definition, ?string $nodeKey, array $answers, ?string $ownUserId, array $sourceFiles): array
    {
        $node = null;
        foreach ((is_array($definition['nodes'] ?? null) ? $definition['nodes'] : []) as $n) {
            if (is_array($n) && ($n['key'] ?? null) === $nodeKey) {
                $node = $n;
                break;
            }
        }
        if ($node === null || !is_array($node['outputs'] ?? null)) {
            return [];
        }
        $ownFiles = [];
        foreach ($answers as $row) {
            if ($ownUserId === null || !is_array($row) || ($row['for_user_id'] ?? null) !== $ownUserId) {
                continue;
            }
            $file = self::fileRef($row['value'] ?? null);
            if ($file !== null && is_string($row['slug'] ?? null)) {
                $ownFiles[$row['slug']] = $file;
            }
        }
        $out = [];
        $seen = [];
        foreach ($node['outputs'] as $output) {
            $rules = is_array($output) && is_array($output['rules'] ?? null) ? $output['rules'] : [];
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $field = $rule['source_field'] ?? null;
                $conn = $rule['source_connection'] ?? null;
                if (is_string($field) && $field !== '') {
                    $key = 'field:' . $field;
                    if (!isset($seen[$key]) && isset($ownFiles[$field])) {
                        $seen[$key] = true;
                        $out[] = ['source_key' => $key, 'kind' => 'field', 'slug' => $field, 'file' => $ownFiles[$field]];
                    }
                } elseif (is_array($conn) && is_string($conn['party'] ?? null) && is_string($conn['request_slug'] ?? null)) {
                    $key = 'conn:' . $conn['party'] . ':' . $conn['request_slug'];
                    $file = $sourceFiles[$key] ?? null;
                    if (!isset($seen[$key]) && is_string($file) && $file !== '') {
                        $seen[$key] = true;
                        $out[] = ['source_key' => $key, 'kind' => 'conn', 'slug' => null, 'file' => $file];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Upload each held source, then POST `$generatePath` with `[otk, values, inputs]`.
     *
     * `$envelopeOf` fetches and decrypts the generating party's own copy of one source to its
     * envelope JSON string. Each envelope is sealed under the SAME one-time key as `values` and
     * POSTed to `{$generatePath}/inputs` as `[source_key, value]` → `[input]`; `inputs` is `[]`
     * when nothing is held.
     *
     * @param callable(string, array<string,mixed>): (array<string,mixed>|string) $post
     * @param array<string,mixed>                                               $answers
     * @param list<array{source_key: string, kind: string, slug: ?string, file: string}> $held
     * @param callable(array{source_key: string, kind: string, slug: ?string, file: string}): string $envelopeOf
     * @return array<string,mixed>|string
     */
    public static function generateWithInputs(callable $post, string $generatePath, array $answers, array $held, callable $envelopeOf): array|string
    {
        $otk = Crypto::newOneTimeKey();
        $inputs = [];
        foreach ($held as $src) {
            $res = $post($generatePath . '/inputs', [
                'source_key' => $src['source_key'],
                'value' => Crypto::oneTimeKeySeal($otk, $envelopeOf($src)),
            ]);
            $input = is_array($res) ? ($res['input'] ?? null) : null;
            if (!is_string($input) || $input === '') {
                throw new ApiError(0, null, 'generate/inputs answered no input for ' . $src['source_key']);
            }
            $inputs[] = ['source_key' => $src['source_key'], 'input' => $input];
        }
        $body = Crypto::oneTimeKeyBundle($answers, $otk);
        $body['inputs'] = $inputs;

        return $post($generatePath, $body);
    }
}
