<?php

declare(strict_types=1);

namespace Allus\CompanyData;

use Allus\CompanyData\Crypto\Crypto;
use Allus\CompanyData\Errors\ApiError;
use Allus\CompanyData\Errors\ConfigError;
use Allus\CompanyData\Errors\DecryptError;
use Allus\CompanyData\Errors\PluginInputUnavailable;
use Allus\CompanyData\Errors\ValidationError;
use Allus\CompanyData\Http\CurlTransport;
use Allus\CompanyData\Http\Transport;
use Allus\CompanyData\Model\PluginOutputs;
use Allus\CompanyData\Model\PluginPass;
use Allus\CompanyData\Model\PluginPicksInvalid;

/**
 * Plugin fields on a contract-flow step, from the company party's side.
 *
 * A flow element of kind `plugin` asks a company-configured plugin: its blocks are answered by
 * picks and typed values, its inputs are wired to earlier flow keys, and its outputs come back
 * from the plugin. The SDK talks to the plugin through the platform's forwarder:
 *
 *   1. a PASS from the run's pass route (`{pass, forwarder_url, plugins, specs}`);
 *   2. the request `{field_type, op, block?, query?, picks, values, inputs, reply_key}` sealed to
 *      the plugin's public key with the platform wrapper, `reply_key` being the public half of a
 *      fresh RSA-2048 pair made for the call;
 *   3. `POST {forwarder_url}/call` `{pass, plugin_id, request}` over a PLAIN transport — the API
 *      client attaches the bearer token and rebuilds URLs against the API base, so it must never
 *      carry this call;
 *   4. the reply `{reply}` opened with the private half of the reply pair.
 *
 * Inputs and bounds are read from ONE live answer map: the run's answers this party can read,
 * overlaid with the caller's draft for the current step's slugs, plugin answers expanded, constants
 * computed. Another party's private value is never sent to a plugin.
 *
 * A party's VIEW of a run is an array: `definition`, `currentNode`, `referenceDate`, `stored` (the
 * run's answers this party can read, decrypted), `privateSlugs` (the run's `private_slugs`; null =
 * unknown) and `ownPartyKeys` (the party keys bound to the caller).
 *
 * @internal the SDK's own flow code; the public surface is the methods on Client / CustomerClient.
 *
 * @phpstan-type View array{definition: array<string,mixed>, currentNode: ?string, referenceDate: ?string, stored: array<string,mixed>, privateSlugs: ?list<string>, ownPartyKeys: list<string>}
 */
final class FlowPlugins
{
    /**
     * @param array<string,mixed> $definition
     *
     * @return list<array<string,mixed>>
     */
    private static function nodes(array $definition): array
    {
        $out = [];
        foreach ((is_array($definition['nodes'] ?? null) ? $definition['nodes'] : []) as $n) {
            if (is_array($n)) {
                $out[] = $n;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $node
     *
     * @return list<array<string,mixed>>
     */
    private static function elements(array $node): array
    {
        $out = [];
        foreach ((is_array($node['elements'] ?? null) ? $node['elements'] : []) as $el) {
            if (is_array($el)) {
                $out[] = $el;
            }
        }

        return $out;
    }

    /**
     * The slugs of every plugin element of the definition.
     *
     * @param array<string,mixed> $definition
     *
     * @return list<string>
     */
    public static function pluginSlugsOf(array $definition): array
    {
        $out = [];
        foreach (self::nodes($definition) as $n) {
            foreach (self::elements($n) as $el) {
                if (($el['kind'] ?? null) === 'plugin' && is_string($el['slug'] ?? null)) {
                    $out[] = $el['slug'];
                }
            }
        }

        return $out;
    }

    /**
     * The field and plugin elements of one node, by slug.
     *
     * @param array<string,mixed> $definition
     *
     * @return array<string,array<string,mixed>>
     */
    private static function nodeElements(array $definition, ?string $nodeKey): array
    {
        $out = [];
        foreach (self::nodes($definition) as $n) {
            if (($n['key'] ?? null) !== $nodeKey) {
                continue;
            }
            foreach (self::elements($n) as $el) {
                $kind = $el['kind'] ?? null;
                if (($kind === 'field' || $kind === 'plugin') && is_string($el['slug'] ?? null)) {
                    $out[$el['slug']] = $el;
                }
            }
        }

        return $out;
    }

    /**
     * The node an element slug sits on, with the element → [node, element] or null.
     *
     * @param array<string,mixed> $definition
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}|null
     */
    private static function elementNode(array $definition, string $slug): ?array
    {
        foreach (self::nodes($definition) as $n) {
            foreach (self::elements($n) as $el) {
                $kind = $el['kind'] ?? null;
                if (($el['slug'] ?? null) === $slug && ($kind === 'field' || $kind === 'plugin')) {
                    return [$n, $el];
                }
            }
        }

        return null;
    }

    /**
     * The draft entries that belong to the current step; everything else is ignored.
     *
     * @param View                     $view
     * @param array<string,mixed>|null $draft
     *
     * @return array<string,mixed>
     */
    private static function currentDraft(array $view, ?array $draft): array
    {
        $own = self::nodeElements($view['definition'], $view['currentNode']);
        $out = [];
        foreach ($draft ?? [] as $slug => $v) {
            if (isset($own[(string) $slug])) {
                $out[(string) $slug] = $v;
            }
        }

        return $out;
    }

    /**
     * The ONE live answer map: the answers the party can read, overlaid with the draft for the
     * current step's slugs, plugin answers expanded, constants computed at the run's reference
     * date.
     *
     * @param View                     $view
     * @param array<string,mixed>|null $draft
     *
     * @return array<string,mixed>
     */
    public static function liveAnswerMap(array $view, ?array $draft = null): array
    {
        $merged = array_replace($view['stored'], self::currentDraft($view, $draft));
        $expanded = FlowCondition::expandPluginAnswers($merged, self::pluginSlugsOf($view['definition']));
        $constants = is_array($view['definition']['constants'] ?? null) ? array_values($view['definition']['constants']) : [];

        return FlowCondition::computeConstants($constants, $expanded, $view['referenceDate']);
    }

    /**
     * The keys a current-step draft value is derived from: a field's `default` refs. A plugin
     * answer derives from nothing here — its outputs are never private, whatever inputs produced
     * them.
     *
     * @param array<string,mixed> $definition
     *
     * @return list<string>
     */
    private static function draftSourceRefs(array $definition, string $slug): array
    {
        $found = self::elementNode($definition, $slug);
        if ($found === null || ($found[1]['kind'] ?? null) !== 'field') {
            return [];
        }
        $default = $found[1]['default'] ?? null;

        return $default === null ? [] : FlowCondition::exprRefs($default);
    }

    /**
     * Whether a flow key's value is private to someone else, fail-closed.
     *
     * A constant is private when any key it reads is. A key on the current step taken from the
     * draft is private only when its field's default reads a private source — whatever the draft
     * value is; a plugin answer is never private. Any other key is private when its slug is in `private_slugs`; with no `private_slugs` list, a key
     * another party answered is private.
     *
     * @param View                $view
     * @param array<string,mixed> $draft
     * @param array<string,bool>  $seen
     */
    private static function isPrivateSource(string $key, array $view, array $draft, array &$seen = []): bool
    {
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;
        foreach ((is_array($view['definition']['constants'] ?? null) ? $view['definition']['constants'] : []) as $c) {
            if (is_array($c) && ($c['key'] ?? null) === $key) {
                foreach (FlowCondition::exprRefs($c['expr'] ?? null) as $ref) {
                    if (self::isPrivateSource($ref, $view, $draft, $seen)) {
                        return true;
                    }
                }

                return false;
            }
        }
        $base = explode('.', $key)[0];
        if (array_key_exists($base, $draft)) {
            foreach (self::draftSourceRefs($view['definition'], $base) as $ref) {
                if (self::isPrivateSource($ref, $view, $draft, $seen)) {
                    return true;
                }
            }

            return false;
        }
        if ($view['privateSlugs'] !== null) {
            return in_array($base, $view['privateSlugs'], true) || in_array($key, $view['privateSlugs'], true);
        }
        $found = self::elementNode($view['definition'], $base);
        $party = ($found !== null && isset($found[0]['party'])) ? (string) $found[0]['party'] : null;

        return $party === null || !in_array($party, $view['ownPartyKeys'], true);
    }

    /**
     * Whether a value the party submits for `$slug` is private: its field's default reads a
     * private source. A plugin answer never is. `$draft` holds the submitted slugs.
     *
     * @param View                $view
     * @param array<string,mixed> $draft
     */
    public static function isDraftPrivate(array $view, string $slug, array $draft): bool
    {
        $own = self::currentDraft($view, $draft);
        if (!array_key_exists($slug, $own)) {
            return false;
        }
        $seen = [];

        return self::isPrivateSource($slug, $view, $own, $seen);
    }

    /**
     * Convert a value to a plugin input's declared type; `$ok` is false when it does not convert.
     */
    private static function convertInput(mixed $type, mixed $value, bool &$ok): mixed
    {
        $ok = true;
        switch ($type) {
            case 'number':
                $n = FlowCondition::flowNumber($value);
                if ($n === null) {
                    $ok = false;

                    return null;
                }

                // A whole number travels as a JSON integer.
                return (floor($n) === $n && abs($n) < PHP_INT_MAX) ? (int) $n : $n;
            case 'date':
                if (is_string($value) && FlowCondition::flowDateTimestamp($value) !== null) {
                    return trim($value);
                }
                break;
            case 'boolean':
                if (is_bool($value)) {
                    return $value;
                }
                if ($value === 'true' || $value === 'false') {
                    return $value === 'true';
                }
                break;
            case 'text':
                return FlowCondition::flowString($value);
        }
        $ok = false;

        return null;
    }

    /**
     * The inputs of a plugin call, each converted to its declared type. A REQUIRED input that is
     * unwired, unanswered, another party's private value or not convertible raises
     * {@see PluginInputUnavailable}; an OPTIONAL one is left out of the call.
     *
     * @param array<string,mixed> $spec
     * @param array<string,mixed> $live
     * @param View                $view
     * @param array<string,mixed> $draft
     *
     * @return array<string,mixed>
     */
    private static function resolveInputs(array $spec, array $live, array $view, array $draft): array
    {
        $snapshot = is_array($spec['snapshot'] ?? null) ? $spec['snapshot'] : [];
        $wiring = is_array($spec['inputs'] ?? null) ? $spec['inputs'] : [];
        $out = [];
        foreach ((is_array($snapshot['inputs'] ?? null) ? $snapshot['inputs'] : []) as $def) {
            if (!is_array($def) || !is_string($def['key'] ?? null)) {
                continue;
            }
            $key = $def['key'];
            $required = ($def['required'] ?? null) === true;
            $ref = $wiring[$key] ?? null;
            $source = (is_string($ref) && $ref !== '') ? $ref : null;
            $reason = null;
            if ($source === null) {
                $reason = 'unwired';
            } else {
                $raw = $live[$source] ?? null;
                if ($raw === null || $raw === '') {
                    $reason = 'unanswered';
                } else {
                    $seen = [];
                    if (self::isPrivateSource($source, $view, $draft, $seen)) {
                        $reason = 'other_party_private';
                    } else {
                        $ok = true;
                        $converted = self::convertInput($def['type'] ?? null, $raw, $ok);
                        if ($ok) {
                            $out[$key] = $converted;
                        } else {
                            $reason = 'not_convertible';
                        }
                    }
                }
            }
            if ($reason !== null && $required) {
                throw new PluginInputUnavailable($key, $source, $reason);
            }
        }

        return $out;
    }

    /**
     * Resolve the plugin element `$slug` of the current step: its spec (from the pass, else the
     * pinned definition) and its inputs read from the live answer map.
     *
     * @param View                     $view
     * @param array<string,mixed>|null $draft
     *
     * @return array{pluginId: string, fieldType: string, inputs: array<string,mixed>}
     */
    public static function prepareCall(array $view, PluginPass $pass, string $slug, ?array $draft = null): array
    {
        $element = self::nodeElements($view['definition'], $view['currentNode'])[$slug] ?? null;
        if ($element === null || ($element['kind'] ?? null) !== 'plugin') {
            throw new ConfigError("'{$slug}' is not a plugin field on the run's current step");
        }
        $spec = $pass->specs[$slug] ?? (is_array($element['plugin'] ?? null) ? $element['plugin'] : null);
        if (!is_array($spec) || !isset($spec['plugin_id'], $spec['field_type'])) {
            throw new ConfigError("plugin field '{$slug}' carries no plugin spec");
        }
        $own = self::currentDraft($view, $draft);
        $inputs = self::resolveInputs($spec, self::liveAnswerMap($view, $own), $view, $own);

        return ['pluginId' => (string) $spec['plugin_id'], 'fieldType' => (string) $spec['field_type'], 'inputs' => $inputs];
    }

    /**
     * Refuse a value outside its flow field's `min`/`max`, each computed over the live answer map.
     *
     * A bound that computes to null is no bound. Numbers compare as numbers and dates as dates; a
     * value that is neither is left to type validation.
     *
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $live
     *
     * @throws ValidationError naming the bound
     */
    public static function checkBounds(array $definition, string $slug, mixed $value, array $live, ?string $referenceDate): void
    {
        $found = self::elementNode($definition, $slug);
        if ($found === null || ($found[1]['kind'] ?? null) !== 'field') {
            return;
        }
        $element = $found[1];
        $fieldType = isset($element['field_type']) ? (string) $element['field_type'] : null;
        foreach (['min', 'max'] as $which) {
            $expr = $element[$which] ?? null;
            if ($expr === null) {
                continue;
            }
            $bound = FlowCondition::evaluateExpression($expr, $live, $referenceDate);
            if ($bound === null || is_bool($bound)) {
                continue;
            }
            $outside = null;
            $bn = FlowCondition::flowNumber($bound);
            if ($bn !== null) {
                $vn = FlowCondition::flowNumber($value);
                if ($vn !== null) {
                    $outside = $which === 'min' ? $vn < $bn : $vn > $bn;
                }
            } else {
                $bd = FlowCondition::flowDateTimestamp($bound);
                $vd = FlowCondition::flowDateTimestamp($value);
                if ($bd !== null && $vd !== null) {
                    $outside = $which === 'min' ? $vd < $bd : $vd > $bd;
                }
            }
            if ($outside === true) {
                throw new ValidationError($slug, $fieldType, $which, $bound);
            }
        }
    }

    /**
     * One plugin call: seal, post, open.
     *
     * A `409 plugin.key_changed` reseals once with the key it returns; a 401 or 403 fetches a new
     * pass once. Every other refusal surfaces as an {@see ApiError} carrying the forwarder's
     * status and key (`plugin.not_responding`, `plugin.busy`, `plugin.rate_limited`,
     * `plugin.unavailable`, …). The call goes over `$transport`, a plain transport that carries no
     * allme credential, to `{forwarder_url}/call` exactly as the pass names it.
     *
     * @param callable(): PluginPass                                                     $renewPass
     * @param array{pluginId: string, fieldType: string, inputs: array<string,mixed>}   $call
     * @param array<string,mixed>                                                        $request the op-specific members
     *
     * @return array<string,mixed> the opened reply
     */
    public static function callPlugin(PluginPass $pass, callable $renewPass, array $call, array $request, ?Transport $transport = null): array
    {
        $transport ??= new CurlTransport();
        [$replyPrivate, $replySpki] = Crypto::generateReplyKeyPair();
        $body = $request;
        foreach (['picks', 'values'] as $member) {
            if (array_key_exists($member, $body)) {
                $body[$member] = (object) (is_array($body[$member]) ? $body[$member] : []);
            }
        }
        $body['field_type'] = $call['fieldType'];
        $body['inputs'] = (object) $call['inputs'];
        $body['reply_key'] = $replySpki;
        $plaintext = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $publicKey = $pass->publicKeyOf($call['pluginId']);
        $resealed = false;
        $renewed = false;
        for (;;) {
            if ($publicKey === null) {
                throw new ApiError(0, 'plugin.not_responding', "plugin {$call['pluginId']} publishes no usable key");
            }
            $sealed = json_encode(Crypto::encryptForPublicKey($plaintext, Crypto::loadPublicKey($publicKey)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $payload = json_encode(['pass' => $pass->pass, 'plugin_id' => $call['pluginId'], 'request' => $sealed], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $resp = $transport->send('POST', $pass->forwarderUrl . '/call', null, $payload, [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);
            $decoded = $resp->body === '' ? null : json_decode($resp->body, true);
            $answer = (is_array($decoded) && !array_is_list($decoded)) ? $decoded : [];
            $errorKey = is_string($answer['error_key'] ?? null) ? $answer['error_key'] : null;
            if ($resp->status === 200) {
                $wrapper = $answer['reply'] ?? null;
                if (!is_string($wrapper) && !is_array($wrapper)) {
                    throw new DecryptError('plugin reply carries no sealed reply');
                }
                $plain = Crypto::decrypt($wrapper, $replyPrivate);
                $shape = json_decode($plain);
                if (!$shape instanceof \stdClass) {
                    throw new DecryptError('plugin reply plaintext is not a JSON object');
                }
                /** @var array<string,mixed> $opened */
                $opened = json_decode($plain, true);

                return $opened;
            }
            if ($resp->status === 409 && $errorKey === 'plugin.key_changed' && !$resealed && is_string($answer['public_key'] ?? null)) {
                $publicKey = $answer['public_key'];
                $resealed = true;
                continue;
            }
            if (($resp->status === 401 || $resp->status === 403) && !$renewed) {
                $pass = $renewPass();
                $publicKey = $pass->publicKeyOf($call['pluginId']);
                $renewed = true;
                continue;
            }
            $details = $answer;
            unset($details['error'], $details['error_key']);
            $message = isset($answer['error']) && is_scalar($answer['error']) ? (string) $answer['error'] : null;
            throw new ApiError($resp->status, $errorKey, $message, $details);
        }
    }

    /**
     * Turn an `outputs` reply into its result.
     *
     * @param array<string,mixed> $reply
     */
    public static function outputsResult(array $reply): PluginOutputs|PluginPicksInvalid
    {
        if (($reply['picks_invalid'] ?? null) === true) {
            return new PluginPicksInvalid($reply);
        }

        return new PluginOutputs(is_array($reply['outputs'] ?? null) ? $reply['outputs'] : [], $reply);
    }
}
