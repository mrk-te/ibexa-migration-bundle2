<?php

namespace Kaliop\IbexaMigrationBundle\Core\FieldHandler;

use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\Exceptions\NotFoundException;
use Ibexa\Contracts\Core\Repository\Exceptions\UnauthorizedException;
use Ibexa\Contracts\Core\Repository\LocationService;
use Ibexa\Core\Base\Exceptions\InvalidArgumentException;
use Ibexa\FieldTypePage\FieldType\LandingPage\Type;
use Ibexa\FieldTypePage\FieldType\LandingPage\Value;
use Ibexa\FieldTypePage\FieldType\Page\Block\Definition\BlockDefinitionFactory;
use Kaliop\IbexaMigrationBundle\API\FieldValueConverterInterface;

class IbexaLandingPage extends AbstractFieldHandler implements FieldValueConverterInterface
{
    public function __construct(
        private readonly ?BlockDefinitionFactory $blockDefinitionFactory,
        private readonly ?Type $type,
        private readonly ?ContentService $contentService,
        private readonly ?LocationService $locationService,
    ) {
    }

    /**
     * @return array<string, callable(mixed $value): mixed>
     */
    private function getFieldValueToHashCallackBySimpleValueType(): array
    {
        return [
            'embed' => $this->replaceContentIdByRemoteId(...),
            'locationlist' => $this->replaceLocationIdListStringByRemoteIdList(...),
        ];
    }

    /**
     * @return array<string, callable(mixed $value): mixed>
     */
    private function getHashToFieldValueCallackBySimpleValueType(): array
    {
        return [
            'embed' => $this->replacePotentialRemoteIdByContentId(...),
            'locationlist' => $this->replacePotentialLocationRemoteIdListByLocationListString(...),
        ];
    }

    /**
     * Converts the Content Field value as gotten from the repo into something that can generate a re-usable migration definition into something the repo can understand
     *
     * @param mixed $fieldValue The Content Field value hash as gotten from the repo
     * @param array $context    The context for execution of the current migrations. Contains f.e. the path to the migration
     * @return array<string, mixed> the array as a field value in a migration definition
     * @throws InvalidArgumentException
     */
    public function fieldValueToHash($fieldValue, array $context = array()): array
    {
        if (!$this->blockDefinitionFactory || !$this->type || !$this->contentService) {
            throw new \DomainException('Missing required dependencies for ibexa_landing_page field handler');
        }
        if (!$fieldValue instanceof Value) {
            throw new \DomainException('Bad value type');
        }

        $hash = $this->type->toHash($fieldValue);
        foreach ($this->getFieldValueToHashCallackBySimpleValueType() as $valueType => $callback) {
            $hash = $this->getFieldHashWithSomeValueTypeModified(
                $hash,
                $valueType,
                $callback
            );
        }

        return $this->getFieldHashWithSomeValueTypeModified(
            $hash,
            'nested_attribute',
            fn(?string $value, string $blockIdentifier, string $attributeIdentifier) => $this->replaceSimpleValueTypeOnNestedAttributes(
                $this->getFieldValueToHashCallackBySimpleValueType(),
                $value,
                $blockIdentifier,
                $attributeIdentifier
            ),
        );
    }

    /**
     * Converts the Content Field value as gotten from the migration definition into something the repo can understand
     *
     * @param mixed $fieldHash The Content Field value hash as gotten from the migration definition
     * @param array $context   The context for execution of the current migrations. Contains f.e. the path to the migration
     * @return mixed the obj usable as field value in a Content create/update struct
     */
    public function hashToFieldValue($fieldHash, array $context = array()): Value
    {
        if (!$this->blockDefinitionFactory || !$this->type || !$this->contentService) {
            throw new \DomainException('Missing required dependencies for ibexa_landing_page field handler');
        }

        if (!$fieldHash) {
            return $this->type->fromHash(null);
        }

        if (!is_array($fieldHash)) {
            throw new \DomainException('Bad value type');
        }

        foreach ($this->getHashToFieldValueCallackBySimpleValueType() as $valueType => $callback) {
            $fieldHash = $this->getFieldHashWithSomeValueTypeModified(
                $fieldHash,
                $valueType,
                $callback
            );
        }

        $fieldHash = $this->getFieldHashWithSomeValueTypeModified(
            $fieldHash,
            'nested_attribute',
            fn(?string $value, string $blockIdentifier, string $attributeIdentifier) => $this->replaceSimpleValueTypeOnNestedAttributes(
                $this->getHashToFieldValueCallackBySimpleValueType(),
                $value,
                $blockIdentifier,
                $attributeIdentifier
            ),
        );

        return $this->type->fromHash($fieldHash);
    }

    /**
     * @throws NotFoundException
     * @throws UnauthorizedException
     */
    private function replaceContentIdByRemoteId(?int $value): ?string
    {
        if (!$value) {
            return null;
        }
        return $this->contentService->loadContentInfo($value)->remoteId;
    }

    /**
     * @throws NotFoundException
     * @throws UnauthorizedException
     */
    private function replacePotentialRemoteIdByContentId(string|int|null $value): ?int
    {
        if (!$value) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        return $this->contentService->loadContentInfoByRemoteId($value)->id;
    }

    /**
     * @throws NotFoundException
     * @throws UnauthorizedException
     */
    private function replaceLocationIdListStringByRemoteIdList(string $value): ?array
    {
        if (!$value) {
            return null;
        }
        return array_map(
            fn(string $locationId) => $this->replaceLocationIdByLocationRemoteId($locationId),
            explode(',', $value)
        );
    }

    /**
     * @param string[]|string|null $value
     * @return string|null
     * @throws NotFoundException
     * @throws UnauthorizedException
     */
    private function replacePotentialLocationRemoteIdListByLocationListString(array|string|null $value): ?string
    {
        if (!$value) {
            return null;
        }

        if (is_array($value)) {
            return implode(',', array_map(fn($locationRemoteId) => $this->replaceLocationRemoteIdByLocationId($locationRemoteId), $value));
        }

        return $value;
    }


    /**
     * @throws NotFoundException
     * @throws UnauthorizedException
     */
    private function replaceLocationIdByLocationRemoteId(int $value): string
    {
        return $this->locationService->loadLocation($value)->remoteId;
    }

    /**
     * @throws NotFoundException
     * @throws UnauthorizedException
     */
    private function replaceLocationRemoteIdByLocationId(string $value): int
    {
        return $this->locationService->loadLocationByRemoteId($value)->id;
    }

    /**
     * @param array<string, callable(mixed $value): mixed> $callbackBySimpleValueType
     * @param string|null                                  $value
     * @param string                                       $blockIdentifier
     * @param string                                       $attributeIdentifier
     * @return string|null
     */
    private function replaceSimpleValueTypeOnNestedAttributes(array $callbackBySimpleValueType, ?string $value, string $blockIdentifier, string $attributeIdentifier): ?string
    {
        if (!$value) {
            return null;
        }

        $data = json_decode($value, true);

        foreach ($callbackBySimpleValueType as $valueType => $callback) {
            $data = $this->getFieldValueWithSomeValueTypeModified(
                $data,
                $blockIdentifier,
                $attributeIdentifier,
                $valueType,
                $callback
            );
        }

        return json_encode($data);
    }

    private function getFieldValueWithSomeValueTypeModified(array $valueHash, string $blockIdentifier, string $attributeIdentifier, string $someValueType, mixed $callback): array
    {
        $attributesToHandle = $this->getSomeValueTypeAttributesByNestedAttribute($someValueType, $blockIdentifier, $attributeIdentifier);
        foreach ($valueHash['attributes'] as &$parentAttributes) {
            foreach($parentAttributes as $attributeIdentifier => &$attribute) {
                if (in_array($attributeIdentifier, $attributesToHandle, true)) {
                    $attribute['value'] = $callback($attribute['value']);
                }
            }
        }

        return $valueHash;
    }

    private function getFieldHashWithSomeValueTypeModified(array $fieldHash, string $someValueType, mixed $callback): array
    {
        $someTypeAttributesByBlockIdentifier = $this->getSomeValueTypeAttributesByBlockIdentifier($someValueType);
        $blockIdentifiersToHandle = array_keys($someTypeAttributesByBlockIdentifier);

        if (!isset($fieldHash['zones'])) {
            return $fieldHash;
        }

        foreach ($fieldHash['zones'] as &$zoneInfo) {
            if (!isset($zoneInfo['blocks'])) {
                continue;
            }
            foreach ($zoneInfo['blocks'] as &$blockInfo) {
                if (!in_array($blockInfo['type'] ?? null, $blockIdentifiersToHandle, true)) {
                    continue;
                }

                $attributeNameToHandle = $someTypeAttributesByBlockIdentifier[$blockInfo['type']];
                foreach ($blockInfo['attributes'] as &$attribute) {
                    if (in_array($attribute['name'] ?? null, $attributeNameToHandle, true)) {
                        $attribute['value'] = $callback($attribute['value'], $blockInfo['type'], $attribute['name']);
                    }
                }
            }
        }

        return $fieldHash;
    }

    private function getSomeValueTypeAttributesByBlockIdentifier(string $someValueType): array
    {
        static $someValueTypeAttributesByBlockIdentifierBySomeValueType = [];
        if (isset($someValueTypeAttributesByBlockIdentifierBySomeValueType[$someValueType])) {
            return $someValueTypeAttributesByBlockIdentifierBySomeValueType[$someValueType];
        }

        $someValueTypeAttributesByBlockIdentifierBySomeValueType[$someValueType] = [];

        $blockDefinition = $this->blockDefinitionFactory->getConfiguration();
        foreach ($blockDefinition as $blockIdentifier => $config) {
            foreach ($config['attributes'] ?? [] as $attributeIdentifier => $attributeInfo) {
                if ($attributeInfo['type'] === $someValueType) {
                    if (!isset($someValueTypeAttributesByBlockIdentifierBySomeValueType[$someValueType][$blockIdentifier])) {
                        $someValueTypeAttributesByBlockIdentifierBySomeValueType[$someValueType][$blockIdentifier] = [];
                    }
                    $someValueTypeAttributesByBlockIdentifierBySomeValueType[$someValueType][$blockIdentifier][] = $attributeIdentifier;
                }
            }
        }

        return $someValueTypeAttributesByBlockIdentifierBySomeValueType[$someValueType];
    }

    private function getSomeValueTypeAttributesByNestedAttribute(string $someValueType, string $targetBlockIdentifier, string $targetAttributeIdentifier): array
    {
        static $someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType = [];
        if (isset($someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType[$someValueType])) {
            return $someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType[$someValueType][$targetBlockIdentifier][$targetAttributeIdentifier] ?? [];
        }

        $someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType[$someValueType] = [];

        $blockDefinition = $this->blockDefinitionFactory->getConfiguration();
        foreach ($blockDefinition as $blockIdentifier => $config) {
            foreach ($config['attributes'] ?? [] as $attributeIdentifier => $attributeInfo) {
                if ($attributeInfo['type'] === 'nested_attribute') {
                    if (!isset($someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType[$someValueType][$blockIdentifier])) {
                        $someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType[$someValueType][$blockIdentifier] = [];
                    }
                    foreach ($attributeInfo['options']['attributes'] ?? [] as $subAttributeIdentifier => $subAttributeInfo) {
                        if ($subAttributeInfo['type'] === $someValueType) {
                            if (!isset($someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType[$someValueType][$blockIdentifier][$attributeIdentifier])) {
                                $someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType[$someValueType][$blockIdentifier][$attributeIdentifier] = [];
                            }
                            $someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType[$someValueType][$blockIdentifier][$attributeIdentifier][] = $subAttributeIdentifier;
                        }
                    }
                }
            }
        }

        return $someValueTypeAttributesByNestedAttributeByBlockIdentifierBySomeValueType[$someValueType][$targetBlockIdentifier][$targetAttributeIdentifier] ?? [];
    }
}
