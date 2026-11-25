<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use JsonException;

/**
 * Inspects Laravel database-queue JSON payloads strictly WITHOUT calling unserialize().
 */
class QueuePayloadInspector
{
    public const JOB_RESERVATIONS = 'App\\Jobs\\ReconcileInventoryMeliOrderReservationsJob';

    public const JOB_BEAUTY_PRICES = 'App\\Jobs\\ProcessMeliBeautyScheduledPriceJob';

    /** @var list<string> */
    public const ALLOWED_JOB_CLASSES = [
        self::JOB_RESERVATIONS,
        self::JOB_BEAUTY_PRICES,
    ];

    public const CLASSIFICATION_CANDIDATE = 'candidate';

    public const CLASSIFICATION_OTHER_MODULE = 'preserved_other_module';

    public const CLASSIFICATION_INVALID_JSON = 'preserved_invalid_json';

    public const CLASSIFICATION_INCONSISTENT = 'preserved_inconsistent_payload';

    /**
     * Validates and normalizes requested --job classes against the strict whitelist.
     *
     * @param  list<string>  $requestedClasses
     * @return list<string>
     */
    public function validateWhitelist(array $requestedClasses): array
    {
        $normalized = [];

        foreach ($requestedClasses as $raw) {
            $class = ltrim(trim((string) $raw), '\\');

            if ($class === '' || str_contains($class, '*') || str_contains($class, '?') || str_contains($class, '%')) {
                throw new QueueRecoveryException(
                    "Clase de job inválida o comodín prohibido: '{$raw}'.",
                    'invalid_job_whitelist',
                    ['requested' => $raw],
                );
            }

            if (! in_array($class, self::ALLOWED_JOB_CLASSES, true)) {
                throw new QueueRecoveryException(
                    "La clase '{$class}' no está autorizada en la lista blanca de R2.",
                    'invalid_job_whitelist',
                    [
                        'requested' => $class,
                        'allowed' => self::ALLOWED_JOB_CLASSES,
                    ],
                );
            }

            $normalized[$class] = true;
        }

        if ($normalized === []) {
            throw new QueueRecoveryException(
                'Debe especificar al menos una clase autorizada mediante --job.',
                'invalid_job_whitelist',
                ['allowed' => self::ALLOWED_JOB_CLASSES],
            );
        }

        $result = array_keys($normalized);
        sort($result);

        return $result;
    }

    /**
     * Inspects a single jobs.payload string safely without unserialize().
     *
     * @param  list<string>  $allowedClasses
     * @return array{
     *     classification: string,
     *     reason: ?string,
     *     job_class: ?string,
     *     payload_hash: string,
     *     entity_type: ?string,
     *     entity_id: ?int,
     *     meli_item_id: ?string
     * }
     */
    public function inspect(string $rawPayload, array $allowedClasses): array
    {
        $payloadHash = hash('sha256', $rawPayload);

        try {
            $decoded = json_decode($rawPayload, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->result(self::CLASSIFICATION_INVALID_JSON, 'invalid_json', null, $payloadHash);
        }

        if (! is_array($decoded)) {
            return $this->result(self::CLASSIFICATION_INVALID_JSON, 'non_object_json', null, $payloadHash);
        }

        $displayName = $decoded['displayName'] ?? null;
        if (! is_string($displayName) || trim($displayName) === '') {
            return $this->result(self::CLASSIFICATION_INCONSISTENT, 'missing_display_name', null, $payloadHash);
        }

        $displayName = ltrim(trim($displayName), '\\');
        $commandName = isset($decoded['data']) && is_array($decoded['data']) ? ($decoded['data']['commandName'] ?? null) : null;
        $serializedCommand = isset($decoded['data']) && is_array($decoded['data']) ? ($decoded['data']['command'] ?? null) : null;

        if ($commandName !== null) {
            if (! is_string($commandName) || ltrim(trim($commandName), '\\') !== $displayName) {
                return $this->result(
                    self::CLASSIFICATION_INCONSISTENT,
                    'display_name_command_name_mismatch',
                    $displayName,
                    $payloadHash,
                );
            }
        }

        $serializedClass = is_string($serializedCommand)
            ? $this->extractSerializedObjectClass($serializedCommand)
            : null;

        // If the payload claims to be one of the target classes, enforce strict consistency.
        $claimsTarget = in_array($displayName, $allowedClasses, true)
            || ($serializedClass !== null && in_array($serializedClass, $allowedClasses, true));

        if (! $claimsTarget) {
            if ($serializedClass !== null && $serializedClass !== $displayName) {
                return $this->result(
                    self::CLASSIFICATION_INCONSISTENT,
                    'serialized_class_mismatch',
                    $displayName,
                    $payloadHash,
                );
            }

            return $this->result(self::CLASSIFICATION_OTHER_MODULE, 'not_in_target_whitelist', $displayName, $payloadHash);
        }

        if (! is_string($serializedCommand) || $serializedClass === null) {
            return $this->result(
                self::CLASSIFICATION_INCONSISTENT,
                'missing_or_malformed_serialized_command',
                $displayName,
                $payloadHash,
            );
        }

        if ($serializedClass !== $displayName || ! in_array($displayName, $allowedClasses, true)) {
            return $this->result(
                self::CLASSIFICATION_INCONSISTENT,
                'serialized_class_mismatch',
                $displayName,
                $payloadHash,
            );
        }

        if ($displayName === self::JOB_RESERVATIONS) {
            $orderId = $this->extractPositiveIntProperty($serializedCommand, 'meliOrderId');
            if ($orderId === null) {
                return $this->result(
                    self::CLASSIFICATION_INCONSISTENT,
                    'missing_or_invalid_meli_order_id',
                    $displayName,
                    $payloadHash,
                );
            }

            return $this->result(
                self::CLASSIFICATION_CANDIDATE,
                null,
                $displayName,
                $payloadHash,
                'meli_order',
                $orderId,
                null,
            );
        }

        if ($displayName === self::JOB_BEAUTY_PRICES) {
            $discountId = $this->extractPositiveIntProperty($serializedCommand, 'discountId');
            if ($discountId === null) {
                return $this->result(
                    self::CLASSIFICATION_INCONSISTENT,
                    'missing_or_invalid_discount_id',
                    $displayName,
                    $payloadHash,
                );
            }

            $itemResult = $this->extractNullableStringProperty($serializedCommand, 'meliItemId');
            if (! $itemResult['valid']) {
                return $this->result(
                    self::CLASSIFICATION_INCONSISTENT,
                    'invalid_meli_item_id_property',
                    $displayName,
                    $payloadHash,
                );
            }

            return $this->result(
                self::CLASSIFICATION_CANDIDATE,
                null,
                $displayName,
                $payloadHash,
                'beauty_discount',
                $discountId,
                $itemResult['value'],
            );
        }

        return $this->result(self::CLASSIFICATION_OTHER_MODULE, 'not_in_target_whitelist', $displayName, $payloadHash);
    }

    private function extractSerializedObjectClass(string $serialized): ?string
    {
        if (preg_match('/\AO:(\d+):"([^"]+)":(\d+):\{/', $serialized, $matches) !== 1) {
            return null;
        }

        $declaredLength = (int) $matches[1];
        $className = $matches[2];

        if (strlen($className) !== $declaredLength) {
            return null;
        }

        return ltrim($className, '\\');
    }

    private function extractPositiveIntProperty(string $serialized, string $property): ?int
    {
        $len = strlen($property);
        $pattern = '/s:'.$len.':"'.preg_quote($property, '/').'";i:(-?\d+);/';

        if (preg_match_all($pattern, $serialized, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1][0];

        return $value > 0 ? $value : null;
    }

    /** @return array{valid: bool, value: ?string} */
    private function extractNullableStringProperty(string $serialized, string $property): array
    {
        $len = strlen($property);
        $prefix = 's:'.$len.':"'.$property.'";';
        $pos = strpos($serialized, $prefix);

        if ($pos === false) {
            return ['valid' => true, 'value' => null];
        }

        // Ensure property does not appear ambiguously multiple times.
        if (strpos($serialized, $prefix, $pos + strlen($prefix)) !== false) {
            return ['valid' => false, 'value' => null];
        }

        $rest = substr($serialized, $pos + strlen($prefix));

        if (str_starts_with($rest, 'N;')) {
            return ['valid' => true, 'value' => null];
        }

        if (preg_match('/\As:(\d+):"/', $rest, $matches) !== 1) {
            return ['valid' => false, 'value' => null];
        }

        $valueLength = (int) $matches[1];
        $headerLength = strlen($matches[0]);

        if (strlen($rest) < $headerLength + $valueLength + 2) {
            return ['valid' => false, 'value' => null];
        }

        $value = substr($rest, $headerLength, $valueLength);
        $closing = substr($rest, $headerLength + $valueLength, 2);

        if ($closing !== '";') {
            return ['valid' => false, 'value' => null];
        }

        $trimmed = trim($value);

        return ['valid' => true, 'value' => $trimmed !== '' ? $trimmed : null];
    }

    /**
     * @return array{
     *     classification: string,
     *     reason: ?string,
     *     job_class: ?string,
     *     payload_hash: string,
     *     entity_type: ?string,
     *     entity_id: ?int,
     *     meli_item_id: ?string
     * }
     */
    private function result(
        string $classification,
        ?string $reason,
        ?string $jobClass,
        string $payloadHash,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $meliItemId = null,
    ): array {
        return [
            'classification' => $classification,
            'reason' => $reason,
            'job_class' => $jobClass,
            'payload_hash' => $payloadHash,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'meli_item_id' => $meliItemId,
        ];
    }
}
