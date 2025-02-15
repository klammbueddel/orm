<?php

declare(strict_types=1);

namespace Doctrine\ORM\Mapping\Driver;

use Doctrine\Common\Collections\Criteria;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Annotation;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping;
use Doctrine\ORM\Mapping\Builder;
use InvalidArgumentException;
use SimpleXMLElement;
use function class_exists;
use function constant;
use function explode;
use function file_get_contents;
use function get_class;
use function in_array;
use function simplexml_load_string;
use function sprintf;
use function str_replace;
use function strtoupper;
use function var_export;

/**
 * XmlDriver is a metadata driver that enables mapping through XML files.
 */
class XmlDriver extends FileDriver
{
    public const DEFAULT_FILE_EXTENSION = '.dcm.xml';

    /**
     * {@inheritDoc}
     */
    public function __construct($locator, $fileExtension = self::DEFAULT_FILE_EXTENSION)
    {
        parent::__construct($locator, $fileExtension);
    }

    /**
     * {@inheritDoc}
     *
     * @throws DBALException
     */
    public function loadMetadataForClass(
        string $className,
        ?Mapping\ComponentMetadata $parent,
        Mapping\ClassMetadataBuildingContext $metadataBuildingContext
    ) : Mapping\ComponentMetadata {
        $metadata = new Mapping\ClassMetadata($className, $parent, $metadataBuildingContext);

        /** @var SimpleXMLElement $xmlRoot */
        $xmlRoot = $this->getElement($className);

        if ($xmlRoot->getName() === 'entity') {
            if (isset($xmlRoot['repository-class'])) {
                $metadata->setCustomRepositoryClassName((string) $xmlRoot['repository-class']);
            }

            if (isset($xmlRoot['read-only']) && $this->evaluateBoolean($xmlRoot['read-only'])) {
                $metadata->asReadOnly();
            }
        } elseif ($xmlRoot->getName() === 'mapped-superclass') {
            if (isset($xmlRoot['repository-class'])) {
                $metadata->setCustomRepositoryClassName((string) $xmlRoot['repository-class']);
            }

            $metadata->isMappedSuperclass = true;
        } elseif ($xmlRoot->getName() === 'embeddable') {
            $metadata->isEmbeddedClass = true;
        } else {
            throw Mapping\MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
        }

        // Process table information
        $parent = $metadata->getParent();

        if ($parent && $parent->inheritanceType === Mapping\InheritanceType::SINGLE_TABLE) {
            // Handle the case where a middle mapped super class inherits from a single table inheritance tree.
            do {
                if (! $parent->isMappedSuperclass) {
                    $metadata->setTable($parent->table);

                    break;
                }

                $parent = $parent->getParent();
            } while ($parent !== null);
        } else {
            $tableAnnotation = new Annotation\Table();

            // Evaluate <entity...> attributes
            if (isset($xmlRoot['table'])) {
                $tableAnnotation->name = (string) $xmlRoot['table'];
            }

            if (isset($xmlRoot['schema'])) {
                $tableAnnotation->schema = (string) $xmlRoot['schema'];
            }

            // Evaluate <indexes...>
            if (isset($xmlRoot->indexes)) {
                $tableAnnotation->indexes = $this->parseIndexes($xmlRoot->indexes->children());
            }

            // Evaluate <unique-constraints..>
            if (isset($xmlRoot->{'unique-constraints'})) {
                $tableAnnotation->uniqueConstraints = $this->parseUniqueConstraints($xmlRoot->{'unique-constraints'}->children());
            }

            if (isset($xmlRoot->options)) {
                $tableAnnotation->options = $this->parseOptions($xmlRoot->options->children());
            }

            $tableBuilder = new Builder\TableMetadataBuilder($metadataBuildingContext);

            $tableBuilder
                ->withEntityClassMetadata($metadata)
                ->withTableAnnotation($tableAnnotation);

            $metadata->setTable($tableBuilder->build());
        }

        // Evaluate second level cache
        if (isset($xmlRoot->cache)) {
            $cacheBuilder = new Builder\CacheMetadataBuilder($metadataBuildingContext);

            $cacheBuilder
                ->withComponentMetadata($metadata)
                ->withCacheAnnotation($this->convertCacheElementToCacheAnnotation($xmlRoot->cache));

            $metadata->setCache($cacheBuilder->build());
        }

        if (isset($xmlRoot['inheritance-type'])) {
            $inheritanceType = strtoupper((string) $xmlRoot['inheritance-type']);

            $metadata->setInheritanceType(
                constant(sprintf('%s::%s', Mapping\InheritanceType::class, $inheritanceType))
            );

            if ($metadata->inheritanceType !== Mapping\InheritanceType::NONE) {
                $discriminatorColumnBuilder = new Builder\DiscriminatorColumnMetadataBuilder($metadataBuildingContext);

                $discriminatorColumnBuilder
                    ->withComponentMetadata($metadata)
                    ->withDiscriminatorColumnAnnotation(
                        isset($xmlRoot->{'discriminator-column'})
                            ? $this->convertDiscriminiatorColumnElementToDiscriminatorColumnAnnotation($xmlRoot->{'discriminator-column'})
                            : null
                    );


                $metadata->setDiscriminatorColumn($discriminatorColumnBuilder->build());

                // Evaluate <discriminator-map...>
                if (isset($xmlRoot->{'discriminator-map'})) {
                    $map = [];

                    foreach ($xmlRoot->{'discriminator-map'}->{'discriminator-mapping'} as $discrMapElement) {
                        $map[(string) $discrMapElement['value']] = (string) $discrMapElement['class'];
                    }

                    $metadata->setDiscriminatorMap($map);
                }
            }
        }

        // Evaluate <change-tracking-policy...>
        if (isset($xmlRoot['change-tracking-policy'])) {
            $changeTrackingPolicy = strtoupper((string) $xmlRoot['change-tracking-policy']);

            $metadata->setChangeTrackingPolicy(
                constant(sprintf('%s::%s', Mapping\ChangeTrackingPolicy::class, $changeTrackingPolicy))
            );
        }

        // Evaluate <field ...> mappings
        if (isset($xmlRoot->field)) {
            foreach ($xmlRoot->field as $fieldElement) {
                $fieldName     = (string) $fieldElement['name'];
                $fieldMetadata = $this->convertFieldElementToFieldMetadata($fieldElement, $fieldName, $metadata, $metadataBuildingContext);

                $metadata->addProperty($fieldMetadata);
            }
        }

        if (isset($xmlRoot->embedded)) {
            foreach ($xmlRoot->embedded as $embeddedMapping) {
                $columnPrefix = isset($embeddedMapping['column-prefix'])
                    ? (string) $embeddedMapping['column-prefix']
                    : null;

                $useColumnPrefix = isset($embeddedMapping['use-column-prefix'])
                    ? $this->evaluateBoolean($embeddedMapping['use-column-prefix'])
                    : true;

                $mapping = [
                    'fieldName'    => (string) $embeddedMapping['name'],
                    'class'        => (string) $embeddedMapping['class'],
                    'columnPrefix' => $useColumnPrefix ? $columnPrefix : false,
                ];

                $metadata->mapEmbedded($mapping);
            }
        }

        // Evaluate <id ...> mappings
        $associationIds = [];

        foreach ($xmlRoot->id as $idElement) {
            $fieldName = (string) $idElement['name'];

            if (isset($idElement['association-key']) && $this->evaluateBoolean($idElement['association-key'])) {
                $associationIds[$fieldName] = true;

                continue;
            }

            $fieldMetadata = $this->convertFieldElementToFieldMetadata($idElement, $fieldName, $metadata, $metadataBuildingContext);

            $fieldMetadata->setPrimaryKey(true);

            // Prevent PK and version on same field
            if ($fieldMetadata->isVersioned()) {
                throw Mapping\MappingException::cannotVersionIdField($className, $fieldName);
            }

            if (isset($idElement->generator)) {
                $strategy = (string) ($idElement->generator['strategy'] ?? 'AUTO');

                $idGeneratorType = constant(sprintf('%s::%s', Mapping\GeneratorType::class, strtoupper($strategy)));

                if ($idGeneratorType !== Mapping\GeneratorType::NONE) {
                    $idGeneratorDefinition = [];

                    // Check for SequenceGenerator/TableGenerator definition
                    if (isset($idElement->{'sequence-generator'})) {
                        $seqGenerator          = $idElement->{'sequence-generator'};
                        $idGeneratorDefinition = [
                            'sequenceName'   => (string) $seqGenerator['sequence-name'],
                            'allocationSize' => (string) $seqGenerator['allocation-size'],
                        ];
                    } elseif (isset($idElement->{'custom-id-generator'})) {
                        $customGenerator = $idElement->{'custom-id-generator'};

                        $idGeneratorDefinition = [
                            'class'     => (string) $customGenerator['class'],
                            'arguments' => [],
                        ];

                        if (! isset($idGeneratorDefinition['class'])) {
                            throw new Mapping\MappingException(
                                sprintf('Cannot instantiate custom generator, no class has been defined')
                            );
                        }

                        if (! class_exists($idGeneratorDefinition['class'])) {
                            throw new Mapping\MappingException(
                                sprintf('Cannot instantiate custom generator : %s', var_export($idGeneratorDefinition, true))
                            );
                        }
                    } elseif (isset($idElement->{'table-generator'})) {
                        throw Mapping\MappingException::tableIdGeneratorNotImplemented($className);
                    }

                    $fieldMetadata->setValueGenerator(
                        new Mapping\ValueGeneratorMetadata($idGeneratorType, $idGeneratorDefinition)
                    );
                }
            }

            $metadata->addProperty($fieldMetadata);
        }

        // Evaluate <one-to-one ...> mappings
        if (isset($xmlRoot->{'one-to-one'})) {
            foreach ($xmlRoot->{'one-to-one'} as $oneToOneElement) {
                $association  = new Mapping\OneToOneAssociationMetadata((string) $oneToOneElement['field']);
                $targetEntity = (string) $oneToOneElement['target-entity'];

                $association->setTargetEntity($targetEntity);

                if (isset($associationIds[$association->getName()])) {
                    $association->setPrimaryKey(true);
                }

                if (isset($oneToOneElement['fetch'])) {
                    $association->setFetchMode(
                        constant(sprintf('%s::%s', Mapping\FetchMode::class, (string) $oneToOneElement['fetch']))
                    );
                }

                if (isset($oneToOneElement['mapped-by'])) {
                    $association->setMappedBy((string) $oneToOneElement['mapped-by']);
                    $association->setOwningSide(false);
                } else {
                    if (isset($oneToOneElement['inversed-by'])) {
                        $association->setInversedBy((string) $oneToOneElement['inversed-by']);
                    }

                    $joinColumns = [];

                    if (isset($oneToOneElement->{'join-column'})) {
                        $joinColumns[] = $this->convertJoinColumnElementToJoinColumnMetadata($oneToOneElement->{'join-column'});
                    } elseif (isset($oneToOneElement->{'join-columns'})) {
                        foreach ($oneToOneElement->{'join-columns'}->{'join-column'} as $joinColumnElement) {
                            $joinColumns[] = $this->convertJoinColumnElementToJoinColumnMetadata($joinColumnElement);
                        }
                    }

                    $association->setJoinColumns($joinColumns);
                }

                if (isset($oneToOneElement->cascade)) {
                    $association->setCascade($this->getCascadeMappings($oneToOneElement->cascade));
                }

                if (isset($oneToOneElement['orphan-removal'])) {
                    $association->setOrphanRemoval($this->evaluateBoolean($oneToOneElement['orphan-removal']));
                }

                // Evaluate second level cache
                if (isset($oneToOneElement->cache)) {
                    $cacheBuilder = new Builder\CacheMetadataBuilder($metadataBuildingContext);

                    $cacheBuilder
                        ->withComponentMetadata($metadata)
                        ->withFieldName($association->getName())
                        ->withCacheAnnotation($this->convertCacheElementToCacheAnnotation($oneToOneElement->cache));

                    $association->setCache($cacheBuilder->build());
                }

                $metadata->addProperty($association);
            }
        }

        // Evaluate <one-to-many ...> mappings
        if (isset($xmlRoot->{'one-to-many'})) {
            foreach ($xmlRoot->{'one-to-many'} as $oneToManyElement) {
                $association  = new Mapping\OneToManyAssociationMetadata((string) $oneToManyElement['field']);
                $targetEntity = (string) $oneToManyElement['target-entity'];

                $association->setTargetEntity($targetEntity);
                $association->setOwningSide(false);
                $association->setMappedBy((string) $oneToManyElement['mapped-by']);

                if (isset($associationIds[$association->getName()])) {
                    throw Mapping\MappingException::illegalToManyIdentifierAssociation($className, $association->getName());
                }

                if (isset($oneToManyElement['fetch'])) {
                    $association->setFetchMode(
                        constant(sprintf('%s::%s', Mapping\FetchMode::class, (string) $oneToManyElement['fetch']))
                    );
                }

                if (isset($oneToManyElement->cascade)) {
                    $association->setCascade($this->getCascadeMappings($oneToManyElement->cascade));
                }

                if (isset($oneToManyElement['orphan-removal'])) {
                    $association->setOrphanRemoval($this->evaluateBoolean($oneToManyElement['orphan-removal']));
                }

                if (isset($oneToManyElement->{'order-by'})) {
                    $orderBy = [];

                    foreach ($oneToManyElement->{'order-by'}->{'order-by-field'} as $orderByField) {
                        $orderBy[(string) $orderByField['name']] = isset($orderByField['direction'])
                            ? (string) $orderByField['direction']
                            : Criteria::ASC;
                    }

                    $association->setOrderBy($orderBy);
                }

                if (isset($oneToManyElement['index-by'])) {
                    $association->setIndexedBy((string) $oneToManyElement['index-by']);
                } elseif (isset($oneToManyElement->{'index-by'})) {
                    throw new InvalidArgumentException('<index-by /> is not a valid tag');
                }

                // Evaluate second level cache
                if (isset($oneToManyElement->cache)) {
                    $cacheBuilder = new Builder\CacheMetadataBuilder($metadataBuildingContext);

                    $cacheBuilder
                        ->withComponentMetadata($metadata)
                        ->withFieldName($association->getName())
                        ->withCacheAnnotation($this->convertCacheElementToCacheAnnotation($oneToManyElement->cache));

                    $association->setCache($cacheBuilder->build());
                }

                $metadata->addProperty($association);
            }
        }

        // Evaluate <many-to-one ...> mappings
        if (isset($xmlRoot->{'many-to-one'})) {
            foreach ($xmlRoot->{'many-to-one'} as $manyToOneElement) {
                $association  = new Mapping\ManyToOneAssociationMetadata((string) $manyToOneElement['field']);
                $targetEntity = (string) $manyToOneElement['target-entity'];

                $association->setTargetEntity($targetEntity);

                if (isset($associationIds[$association->getName()])) {
                    $association->setPrimaryKey(true);
                }

                if (isset($manyToOneElement['fetch'])) {
                    $association->setFetchMode(
                        constant('Doctrine\ORM\Mapping\FetchMode::' . (string) $manyToOneElement['fetch'])
                    );
                }

                if (isset($manyToOneElement['inversed-by'])) {
                    $association->setInversedBy((string) $manyToOneElement['inversed-by']);
                }

                $joinColumns = [];

                if (isset($manyToOneElement->{'join-column'})) {
                    $joinColumns[] = $this->convertJoinColumnElementToJoinColumnMetadata($manyToOneElement->{'join-column'});
                } elseif (isset($manyToOneElement->{'join-columns'})) {
                    foreach ($manyToOneElement->{'join-columns'}->{'join-column'} as $joinColumnElement) {
                        $joinColumns[] = $this->convertJoinColumnElementToJoinColumnMetadata($joinColumnElement);
                    }
                }

                $association->setJoinColumns($joinColumns);

                if (isset($manyToOneElement->cascade)) {
                    $association->setCascade($this->getCascadeMappings($manyToOneElement->cascade));
                }

                // Evaluate second level cache
                if (isset($manyToOneElement->cache)) {
                    $cacheBuilder = new Builder\CacheMetadataBuilder($metadataBuildingContext);

                    $cacheBuilder
                        ->withComponentMetadata($metadata)
                        ->withFieldName($association->getName())
                        ->withCacheAnnotation($this->convertCacheElementToCacheAnnotation($manyToOneElement->cache));

                    $association->setCache($cacheBuilder->build());
                }

                $metadata->addProperty($association);
            }
        }

        // Evaluate <many-to-many ...> mappings
        if (isset($xmlRoot->{'many-to-many'})) {
            foreach ($xmlRoot->{'many-to-many'} as $manyToManyElement) {
                $association  = new Mapping\ManyToManyAssociationMetadata((string) $manyToManyElement['field']);
                $targetEntity = (string) $manyToManyElement['target-entity'];

                $association->setTargetEntity($targetEntity);

                if (isset($associationIds[$association->getName()])) {
                    throw Mapping\MappingException::illegalToManyIdentifierAssociation($className, $association->getName());
                }

                if (isset($manyToManyElement['fetch'])) {
                    $association->setFetchMode(
                        constant(sprintf('%s::%s', Mapping\FetchMode::class, (string) $manyToManyElement['fetch']))
                    );
                }

                if (isset($manyToManyElement['orphan-removal'])) {
                    $association->setOrphanRemoval($this->evaluateBoolean($manyToManyElement['orphan-removal']));
                }

                if (isset($manyToManyElement['mapped-by'])) {
                    $association->setMappedBy((string) $manyToManyElement['mapped-by']);
                    $association->setOwningSide(false);
                } elseif (isset($manyToManyElement->{'join-table'})) {
                    if (isset($manyToManyElement['inversed-by'])) {
                        $association->setInversedBy((string) $manyToManyElement['inversed-by']);
                    }

                    $joinTableElement = $manyToManyElement->{'join-table'};
                    $joinTable        = new Mapping\JoinTableMetadata();

                    if (isset($joinTableElement['name'])) {
                        $joinTable->setName((string) $joinTableElement['name']);
                    }

                    if (isset($joinTableElement['schema'])) {
                        $joinTable->setSchema((string) $joinTableElement['schema']);
                    }

                    if (isset($joinTableElement->{'join-columns'})) {
                        foreach ($joinTableElement->{'join-columns'}->{'join-column'} as $joinColumnElement) {
                            $joinColumn = $this->convertJoinColumnElementToJoinColumnMetadata($joinColumnElement);

                            $joinTable->addJoinColumn($joinColumn);
                        }
                    }

                    if (isset($joinTableElement->{'inverse-join-columns'})) {
                        foreach ($joinTableElement->{'inverse-join-columns'}->{'join-column'} as $joinColumnElement) {
                            $joinColumn = $this->convertJoinColumnElementToJoinColumnMetadata($joinColumnElement);

                            $joinTable->addInverseJoinColumn($joinColumn);
                        }
                    }

                    $association->setJoinTable($joinTable);
                }

                if (isset($manyToManyElement->cascade)) {
                    $association->setCascade($this->getCascadeMappings($manyToManyElement->cascade));
                }

                if (isset($manyToManyElement->{'order-by'})) {
                    $orderBy = [];

                    foreach ($manyToManyElement->{'order-by'}->{'order-by-field'} as $orderByField) {
                        $orderBy[(string) $orderByField['name']] = isset($orderByField['direction'])
                            ? (string) $orderByField['direction']
                            : Criteria::ASC;
                    }

                    $association->setOrderBy($orderBy);
                }

                if (isset($manyToManyElement['index-by'])) {
                    $association->setIndexedBy((string) $manyToManyElement['index-by']);
                } elseif (isset($manyToManyElement->{'index-by'})) {
                    throw new InvalidArgumentException('<index-by /> is not a valid tag');
                }

                // Evaluate second level cache
                if (isset($manyToManyElement->cache)) {
                    $cacheBuilder = new Builder\CacheMetadataBuilder($metadataBuildingContext);

                    $cacheBuilder
                        ->withComponentMetadata($metadata)
                        ->withFieldName($association->getName())
                        ->withCacheAnnotation($this->convertCacheElementToCacheAnnotation($manyToManyElement->cache));

                    $association->setCache($cacheBuilder->build());
                }

                $metadata->addProperty($association);
            }
        }

        // Evaluate association-overrides
        if (isset($xmlRoot->{'attribute-overrides'})) {
            foreach ($xmlRoot->{'attribute-overrides'}->{'attribute-override'} as $overrideElement) {
                $fieldName = (string) $overrideElement['name'];

                foreach ($overrideElement->field as $fieldElement) {
                    $fieldMetadata = $this->convertFieldElementToFieldMetadata($fieldElement, $fieldName, $metadata, $metadataBuildingContext);

                    $metadata->setPropertyOverride($fieldMetadata);
                }
            }
        }

        // Evaluate association-overrides
        if (isset($xmlRoot->{'association-overrides'})) {
            foreach ($xmlRoot->{'association-overrides'}->{'association-override'} as $overrideElement) {
                $fieldName = (string) $overrideElement['name'];
                $property  = $metadata->getProperty($fieldName);

                if (! $property) {
                    throw Mapping\MappingException::invalidOverrideFieldName($metadata->getClassName(), $fieldName);
                }

                $existingClass = get_class($property);
                $override      = new $existingClass($fieldName);

                // Check for join-columns
                if (isset($overrideElement->{'join-columns'})) {
                    $joinColumns = [];

                    foreach ($overrideElement->{'join-columns'}->{'join-column'} as $joinColumnElement) {
                        $joinColumns[] = $this->convertJoinColumnElementToJoinColumnMetadata($joinColumnElement);
                    }

                    $override->setJoinColumns($joinColumns);
                }

                // Check for join-table
                if ($overrideElement->{'join-table'}) {
                    $joinTableElement = $overrideElement->{'join-table'};
                    $joinTable        = new Mapping\JoinTableMetadata();

                    if (isset($joinTableElement['name'])) {
                        $joinTable->setName((string) $joinTableElement['name']);
                    }

                    if (isset($joinTableElement['schema'])) {
                        $joinTable->setSchema((string) $joinTableElement['schema']);
                    }

                    if (isset($joinTableElement->{'join-columns'})) {
                        foreach ($joinTableElement->{'join-columns'}->{'join-column'} as $joinColumnElement) {
                            $joinColumn = $this->convertJoinColumnElementToJoinColumnMetadata($joinColumnElement);

                            $joinTable->addJoinColumn($joinColumn);
                        }
                    }

                    if (isset($joinTableElement->{'inverse-join-columns'})) {
                        foreach ($joinTableElement->{'inverse-join-columns'}->{'join-column'} as $joinColumnElement) {
                            $joinColumn = $this->convertJoinColumnElementToJoinColumnMetadata($joinColumnElement);

                            $joinTable->addInverseJoinColumn($joinColumn);
                        }
                    }

                    $override->setJoinTable($joinTable);
                }

                // Check for inversed-by
                if (isset($overrideElement->{'inversed-by'})) {
                    $override->setInversedBy((string) $overrideElement->{'inversed-by'}['name']);
                }

                // Check for fetch
                if (isset($overrideElement['fetch'])) {
                    $override->setFetchMode(
                        constant('Doctrine\ORM\Mapping\FetchMode::' . (string) $overrideElement['fetch'])
                    );
                }

                $metadata->setPropertyOverride($override);
            }
        }

        // Evaluate <lifecycle-callbacks...>
        if (isset($xmlRoot->{'lifecycle-callbacks'})) {
            foreach ($xmlRoot->{'lifecycle-callbacks'}->{'lifecycle-callback'} as $lifecycleCallback) {
                $eventName  = constant(Events::class . '::' . (string) $lifecycleCallback['type']);
                $methodName = (string) $lifecycleCallback['method'];

                $metadata->addLifecycleCallback($eventName, $methodName);
            }
        }

        // Evaluate entity listener
        if (isset($xmlRoot->{'entity-listeners'})) {
            foreach ($xmlRoot->{'entity-listeners'}->{'entity-listener'} as $listenerElement) {
                $listenerClassName = (string) $listenerElement['class'];

                if (! class_exists($listenerClassName)) {
                    throw Mapping\MappingException::entityListenerClassNotFound(
                        $listenerClassName,
                        $metadata->getClassName()
                    );
                }

                foreach ($listenerElement as $callbackElement) {
                    $eventName  = (string) $callbackElement['type'];
                    $methodName = (string) $callbackElement['method'];

                    $metadata->addEntityListener($eventName, $listenerClassName, $methodName);
                }
            }
        }

        return $metadata;
    }

    /**
     * Parses (nested) index elements.
     *
     * @param SimpleXMLElement $indexes The XML element.
     *
     * @return Annotation\Index[] The indexes array.
     */
    private function parseIndexes(SimpleXMLElement $indexes) : array
    {
        $array = [];

        /** @var SimpleXMLElement $index */
        foreach ($indexes as $index) {
            $indexAnnotation = new Annotation\Index();

            $indexAnnotation->columns = explode(',', (string) $index['columns']);
            $indexAnnotation->options = isset($index->options) ? $this->parseOptions($index->options->children()) : [];
            $indexAnnotation->flags   = isset($index['flags']) ? explode(',', (string) $index['flags']) : [];

            if (isset($index['name'])) {
                $indexAnnotation->name = (string) $index['name'];
            }

            if (isset($index['unique'])) {
                $indexAnnotation->unique = $this->evaluateBoolean($index['unique']);
            }

            $array[] = $indexAnnotation;
        }

        return $array;
    }

    /**
     * Parses (nested) unique constraint elements.
     *
     * @param SimpleXMLElement $uniqueConstraints The XML element.
     *
     * @return Annotation\UniqueConstraint[] The unique constraints array.
     */
    private function parseUniqueConstraints(SimpleXMLElement $uniqueConstraints) : array
    {
        $array = [];

        /** @var SimpleXMLElement $uniqueConstraint */
        foreach ($uniqueConstraints as $uniqueConstraint) {
            $uniqueConstraintAnnotation = new Annotation\UniqueConstraint();

            $uniqueConstraintAnnotation->columns = explode(',', (string) $uniqueConstraint['columns']);
            $uniqueConstraintAnnotation->options = isset($uniqueConstraint->options) ? $this->parseOptions($uniqueConstraint->options->children()) : [];
            $uniqueConstraintAnnotation->flags   = isset($uniqueConstraint['flags']) ? explode(',', (string) $uniqueConstraint['flags']) : [];

            if (isset($uniqueConstraint['name'])) {
                $uniqueConstraintAnnotation->name = (string) $uniqueConstraint['name'];
            }

            $array[] = $uniqueConstraintAnnotation;
        }

        return $array;
    }

    /**
     * Parses (nested) option elements.
     *
     * @param SimpleXMLElement $options The XML element.
     *
     * @return mixed[] The options array.
     */
    private function parseOptions(SimpleXMLElement $options) : array
    {
        $array = [];

        /** @var SimpleXMLElement $option */
        foreach ($options as $option) {
            if ($option->count()) {
                $value = $this->parseOptions($option->children());
            } else {
                $value = (string) $option;
            }

            $attributes = $option->attributes();

            if (isset($attributes->name)) {
                $nameAttribute = (string) $attributes->name;

                $array[$nameAttribute] = in_array($nameAttribute, ['unsigned', 'fixed'], true)
                    ? $this->evaluateBoolean($value)
                    : $value;
            } else {
                $array[] = $value;
            }
        }

        return $array;
    }

    /**
     * @throws Mapping\MappingException
     */
    private function convertFieldElementToFieldMetadata(
        SimpleXMLElement $fieldElement,
        string $fieldName,
        Mapping\ClassMetadata $metadata,
        Mapping\ClassMetadataBuildingContext $metadataBuildingContext
    ) : Mapping\FieldMetadata {
        $className     = $metadata->getClassName();
        $isVersioned   = isset($fieldElement['version']) && $fieldElement['version'];
        $fieldMetadata = new Mapping\FieldMetadata($fieldName);
        $fieldType     = isset($fieldElement['type']) ? (string) $fieldElement['type'] : 'string';
        $columnName    = isset($fieldElement['column'])
            ? (string) $fieldElement['column']
            : $metadataBuildingContext->getNamingStrategy()->propertyToColumnName($fieldName, $className);

        $fieldMetadata->setType(Type::getType($fieldType));
        $fieldMetadata->setVersioned($isVersioned);
        $fieldMetadata->setColumnName($columnName);

        if (isset($fieldElement['length'])) {
            $fieldMetadata->setLength((int) $fieldElement['length']);
        }

        if (isset($fieldElement['precision'])) {
            $fieldMetadata->setPrecision((int) $fieldElement['precision']);
        }

        if (isset($fieldElement['scale'])) {
            $fieldMetadata->setScale((int) $fieldElement['scale']);
        }

        if (isset($fieldElement['unique'])) {
            $fieldMetadata->setUnique($this->evaluateBoolean($fieldElement['unique']));
        }

        if (isset($fieldElement['nullable'])) {
            $fieldMetadata->setNullable($this->evaluateBoolean($fieldElement['nullable']));
        }

        if (isset($fieldElement['column-definition'])) {
            $fieldMetadata->setColumnDefinition((string) $fieldElement['column-definition']);
        }

        if (isset($fieldElement->options)) {
            $fieldMetadata->setOptions($this->parseOptions($fieldElement->options->children()));
        }

        // Prevent column duplication
        if ($metadata->checkPropertyDuplication($columnName)) {
            throw Mapping\MappingException::duplicateColumnName($className, $columnName);
        }

        return $fieldMetadata;
    }

    /**
     * Constructs a joinColumn mapping array based on the information
     * found in the given SimpleXMLElement.
     *
     * @param SimpleXMLElement $joinColumnElement The XML element.
     */
    private function convertJoinColumnElementToJoinColumnMetadata(SimpleXMLElement $joinColumnElement) : Mapping\JoinColumnMetadata
    {
        $joinColumnMetadata = new Mapping\JoinColumnMetadata();

        $joinColumnMetadata->setColumnName((string) $joinColumnElement['name']);
        $joinColumnMetadata->setReferencedColumnName((string) $joinColumnElement['referenced-column-name']);

        if (isset($joinColumnElement['column-definition'])) {
            $joinColumnMetadata->setColumnDefinition((string) $joinColumnElement['column-definition']);
        }

        if (isset($joinColumnElement['field-name'])) {
            $joinColumnMetadata->setAliasedName((string) $joinColumnElement['field-name']);
        }

        if (isset($joinColumnElement['nullable'])) {
            $joinColumnMetadata->setNullable($this->evaluateBoolean($joinColumnElement['nullable']));
        }

        if (isset($joinColumnElement['unique'])) {
            $joinColumnMetadata->setUnique($this->evaluateBoolean($joinColumnElement['unique']));
        }

        if (isset($joinColumnElement['on-delete'])) {
            $joinColumnMetadata->setOnDelete(strtoupper((string) $joinColumnElement['on-delete']));
        }

        return $joinColumnMetadata;
    }

    /**
     * Parse the given Cache as CacheMetadata
     */
    private function convertCacheElementToCacheAnnotation(SimpleXMLElement $cacheMapping) : Annotation\Cache
    {
        $cacheAnnotation = new Annotation\Cache();

        if (isset($cacheMapping['region'])) {
            $cacheAnnotation->region = (string) $cacheMapping['region'];
        }

        if (isset($cacheMapping['usage'])) {
            $cacheAnnotation->usage = strtoupper((string) $cacheMapping['usage']);
        }

        return $cacheAnnotation;
    }

    private function convertDiscriminiatorColumnElementToDiscriminatorColumnAnnotation(
        SimpleXMLElement $discriminatorColumnMapping
    ) : Annotation\DiscriminatorColumn {
        $discriminatorColumnAnnotation = new Annotation\DiscriminatorColumn();

        $discriminatorColumnAnnotation->type = (string) ($discriminatorColumnMapping['type'] ?? 'string');
        $discriminatorColumnAnnotation->name = (string) $discriminatorColumnMapping['name'];

        if (isset($discriminatorColumnMapping['column-definition'])) {
            $discriminatorColumnAnnotation->columnDefinition = (string) $discriminatorColumnMapping['column-definition'];
        }

        if (isset($discriminatorColumnMapping['length'])) {
            $discriminatorColumnAnnotation->length = (int) $discriminatorColumnMapping['length'];
        }

        return $discriminatorColumnAnnotation;
    }

    /**
     * Gathers a list of cascade options found in the given cascade element.
     *
     * @param SimpleXMLElement $cascadeElement The cascade element.
     *
     * @return string[] The list of cascade options.
     */
    private function getCascadeMappings(SimpleXMLElement $cascadeElement) : array
    {
        $cascades = [];

        /** @var SimpleXMLElement $action */
        foreach ($cascadeElement->children() as $action) {
            // According to the JPA specifications, XML uses "cascade-persist"
            // instead of "persist". Here, both variations are supported
            // because Annotation use "persist" and we want to make sure that
            // this driver doesn't need to know anything about the supported
            // cascading actions
            $cascades[] = str_replace('cascade-', '', $action->getName());
        }

        return $cascades;
    }

    /**
     * {@inheritDoc}
     */
    protected function loadMappingFile($file)
    {
        $result = [];
        // Note: we do not use `simplexml_load_file()` because of https://bugs.php.net/bug.php?id=62577
        $xmlElement = simplexml_load_string(file_get_contents($file));

        if (isset($xmlElement->entity)) {
            foreach ($xmlElement->entity as $entityElement) {
                $entityName          = (string) $entityElement['name'];
                $result[$entityName] = $entityElement;
            }
        } elseif (isset($xmlElement->{'mapped-superclass'})) {
            foreach ($xmlElement->{'mapped-superclass'} as $mappedSuperClass) {
                $className          = (string) $mappedSuperClass['name'];
                $result[$className] = $mappedSuperClass;
            }
        } elseif (isset($xmlElement->embeddable)) {
            foreach ($xmlElement->embeddable as $embeddableElement) {
                $embeddableName          = (string) $embeddableElement['name'];
                $result[$embeddableName] = $embeddableElement;
            }
        }

        return $result;
    }

    /**
     * @param mixed $element
     *
     * @return bool
     */
    protected function evaluateBoolean($element)
    {
        $flag = (string) $element;

        return $flag === 'true' || $flag === '1';
    }
}
