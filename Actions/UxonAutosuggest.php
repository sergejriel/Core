<?php
namespace exface\Core\Actions;

use exface\Core\Actions\Traits\iProcessUxonTasksTrait;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Uxon\UxonSchema;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Model\MetaObjectNotFoundError;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Interfaces\UxonSchemaInterface;
use exface\Core\Mutations\MetaObjectUidMutationTarget;
use exface\Core\Mutations\MutationPoints\ObjectActionMutationPoint;
use exface\Core\Mutations\Prototypes\GenericUxonMutation;
use exface\Core\CommonLogic\Utils\JsonObject;
use exface\Core\Uxon\QueryBuilderSchema;
use JsonPath\InvalidJsonPathException;

/**
 * Returns autosuggest values for provided UXON objects.
 * 
 * Accepts the following parameters:
 * 
 * - `uxon` - the entire UXON being edited
 * - `text` - the text, that needs to get suggested (i.e. what was typed so far)
 * - `path` - the path to the currently edited node from the root of the UXON (a JSON array of node names)
 * - `input` - the type of input: `field` or `value`
 * - `schema` - the UXON schema to be used (if not set, the generic UxonSchema will be used).
 * - `prototype` - class or file path (relative to the vendor folder) to be used as prototype
 * - `object` - a selector for the root object of the UXON
 * - `target_uid` - UID of an object action when `input` is `mutation_hints`
 * 
 * The result is a JSON with the following structure
 * 
 * ```
 *  {
 *      "values": [
 *          "suggestion 1",
 *          "suggestion 2",
 *          "..."
 *      ],
 *      "templates": {
 *          "field1": {"": ""},
 *          "field2": {"": [""]}
 *      }
 *  }
 * 
 * ```
 * 
 * With `input` set to `mutation_hints`, the result is a list of enabled mutations with their
 * operation, configured JSONPath and concrete JSONEditor path.
 * 
 * # Technical Background
 * 
 * ## Mutation Links & Hints
 * Uxon mutations are defined in the UXON schema and can be applied to UXON objects. 
 * Each mutation has a name, an operation (append, insert, change, replace, remove, move), 
 * and a JSONPath that defines where the mutation can be applied. 
 * This action provides hints for these mutations 
 * by returning the enabled mutations and their concrete target paths in the current UXON.
 * 
 * Development documentation:
 * [mutation_links.documentation.md](../Docs/developer_docs/mutation_links/mutation_links.documentation.md)
 *
 * @author Andrej Kabachnik & Sergej Riel
 *
 */
class UxonAutosuggest extends AbstractAction
{
    use iProcessUxonTasksTrait;
    
    const PARAM_TEXT = 'text';
    const PARAM_TYPE = 'input';
    
    const TYPE_FIELD = 'field';
    const TYPE_VALUE = 'value';
    const TYPE_PRESET = 'preset';
    const TYPE_DETAILS = 'details';
    const TYPE_MODEL_BROWSER = 'modelbrowser';
    const TYPE_MUTATION_HINTS = 'mutation_hints';
    
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $type = $task->getParameter(self::PARAM_TYPE);
        if (strcasecmp($type, self::TYPE_MUTATION_HINTS) === 0) {
            return ResultFactory::createJSONResult($task, $this->suggestMutationHints($task));
        }

        $schemaBase = new UxonObject();
        
        $currentText = $task->getParameter(self::PARAM_TEXT);
        $path = $this->getParamPath($task);
        $uxon = $this->getParamUxon($task);
        $rootObject = $this->getRootObject($task);
        
        if($rootObject) {
            $schemaBase->setProperty('object_alias', $rootObject->getAliasWithNamespace());
        }
        
        $rootPrototypeClass = $this->getRootPrototypeClass($task);
        $schema = $this->getSchema($task, $rootPrototypeClass);

        if (! $schemaBase->isEmpty()) {
            $uxon = $schemaBase->extend($uxon);
        }
        
        switch (true) {
            case strcasecmp($type, self::TYPE_FIELD) === 0:
                $options = $this->suggestPropertyNames($schema, $uxon, $path, $rootPrototypeClass);
                break;
            case strcasecmp($type, self::TYPE_PRESET) === 0:
                $options = $this->suggestPresets($schema, $uxon, $path, $rootPrototypeClass);
                break;
            case strcasecmp($type, self::TYPE_DETAILS) === 0:
                $options = $this->suggestDetails($schema, $uxon, $path, $rootPrototypeClass);
                break;
            case strcasecmp($type, self::TYPE_MODEL_BROWSER) === 0:
                $options = $this->suggestModelBrowser($schema, $uxon, $path, $currentText, $rootPrototypeClass, $rootObject);
                break;
            // self::TYPE_VALUE is the default case, so we can just use default here
            default:
                $options = $this->suggestPropertyValues($schema, $uxon, $path, $currentText, $rootPrototypeClass, $rootObject);
                break;
        }
        return ResultFactory::createJSONResult($task, $options);
    }

    /**
     * Returns the enabled object-action mutations and their concrete target paths in the current UXON.
     *
     * @param TaskInterface $task
     * @return array
     */
    protected function suggestMutationHints(TaskInterface $task) : array
    {
        $targetUid = trim((string) $task->getParameter('target_uid'));
        if ($targetUid === '') {
            return [];
        }

        $mutationPoint = $this->getWorkbench()->getMutator()->getMutationPoint(ObjectActionMutationPoint::class);
        if ($mutationPoint->isDisabled()) {
            return [];
        }

        $target = new MetaObjectUidMutationTarget('exface.Core.OBJECT_ACTION', $targetUid);
        $uxon = $this->getParamUxon($task);
        $hints = [];
        foreach ($mutationPoint->getMutations($target) as $mutation) {
            if ($mutation->isDisabled() || ! ($mutation instanceof GenericUxonMutation)) {
                continue;
            }

            $config = $mutation->exportUxonObject()->toArray();
            foreach (['append', 'insert', 'change', 'replace'] as $operation) {
                foreach (($config[$operation] ?? []) as $jsonPath => $_) {
                    $hints = array_merge($hints, $this->createMutationHints(
                        $uxon,
                        $mutation->getName(),
                        $operation,
                        $jsonPath
                    ));
                }
            }
            foreach (($config['remove'] ?? []) as $jsonPath) {
                $hints = array_merge($hints, $this->createMutationHints(
                    $uxon,
                    $mutation->getName(),
                    'remove',
                    $jsonPath
                ));
            }
            foreach (($config['move'] ?? []) as $sourcePath => $targetPath) {
                $hints = array_merge(
                    $hints,
                    $this->createMutationHints($uxon, $mutation->getName(), 'move', $sourcePath),
                    $this->createMutationHints($uxon, $mutation->getName(), 'move', $targetPath)
                );
            }
        }

        return $hints;
    }

    /**
     * Resolves one mutation JSONPath to JSONEditor-compatible path arrays.
     *
     * @param UxonObject $uxon
     * @param string|null $mutationName
     * @param string $operation
     * @param string $jsonPath
     * @return array
     */
    protected function createMutationHints(
        UxonObject $uxon,
        ?string $mutationName,
        string $operation,
        string $jsonPath
    ) : array {
        $json = new JsonObject($uxon->toArray());
        $marker = ['__exf_mutation_target__' => uniqid('', true)];
        try {
            $json->set($jsonPath, $marker);
        } catch (InvalidJsonPathException $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            return [];
        }

        return array_map(
            static fn(array $path) => [
                'name' => $mutationName,
                'operation' => $operation,
                'json_path' => $jsonPath,
                'path' => $path
            ],
            $this->findMutationMarkerPaths($json->getValue(), $marker)
        );
    }

    /**
     * Finds every occurrence of a temporary marker and returns its path from the UXON root.
     *
     * @param mixed $value
     * @param array $marker
     * @param array $path
     * @return array
     */
    protected function findMutationMarkerPaths($value, array $marker, array $path = []) : array
    {
        if ($value === $marker) {
            return [$path];
        }
        if (! is_array($value)) {
            return [];
        }

        $paths = [];
        foreach ($value as $key => $child) {
            $paths = array_merge($paths, $this->findMutationMarkerPaths($child, $marker, array_merge($path, [$key])));
        }
        return $paths;
    }

    /**
     * 
     * @param UxonSchemaInterface $schema
     * @param UxonObject $uxon
     * @param array $path
     * @param string $rootPrototypeClass
     * @return array
     */
    protected function suggestPresets(UxonSchemaInterface $schema, UxonObject $uxon, array $path, string $rootPrototypeClass = null) : array
    {
        return $schema->getPresets($uxon, $path, $rootPrototypeClass);
    }
    
    protected function suggestModelBrowser(UxonSchemaInterface $schema, UxonObject $uxon, array $path, string $search, string $rootPrototypeClass = null, MetaObjectInterface $rootObject = null) : array
    {
        $type = $schema->getUxonType($uxon, $path, $rootPrototypeClass);
        
        try {
            $object = $schema->getMetaObject($uxon, $path, $rootObject);
        } catch (MetaObjectNotFoundError $e) {
            return [];
        }
        
        $data = [];
        switch (strtolower($type)) {
            case 'metamodel:attribute':
            default:
                $data = [
                    '_TYPE' => 'object',
                    '_BASE_OBJECT_NAME' => $object->getName(),
                    '_BASE_OBJECT_ALIAS_NS' => $object->getAliasWithNamespace(),
                    '_BASE_OBJECT_ALIAS' => $object->getAlias()
                ];
                foreach ($schema->getValidValues($uxon, $path, $search, $rootPrototypeClass, $rootObject) as $suggest) {
                    $alias = rtrim($suggest, "_");
                    if ($alias !== $suggest) {
                        $rel = $object->getRelation($alias);
                        $data['ATTRIBUTES'][] = [
                            'NAME' => $rel->getName(),
                            'ALIAS' => $alias,
                            '_SUGGEST' => $suggest,
                            '_RELATION' => true,
                            '_RELATION_TO' => $rel->getRightObject()->getName(),
                            'DESCRIPTION' => $rel->isForwardRelation() && $object->hasAttribute($alias) ? $object->getAttribute($alias)->getShortDescription() : ''
                        ];
                    } else {
                        $attr = $object->getAttribute($alias);
                        $data['ATTRIBUTES'][] = [
                            'NAME' => $attr->getName(),
                            'ALIAS' => $alias,
                            '_SUGGEST' => $suggest,
                            'DESCRIPTION' => $attr->getShortDescription()
                        ];
                    }
                }
                break;
        }
        
        return $data;
    }
    
    /**
     * 
     * @param UxonSchemaInterface $schema
     * @param UxonObject $uxon
     * @param array $path
     * @param string $rootPrototypeClass
     * @return array
     */
    protected function suggestDetails(UxonSchemaInterface $schema, UxonObject $uxon, array $path, string $rootPrototypeClass = null) : array
    {
        $rows = [];
        if (empty($path) === false) {
            $path[] = '';
        }
        $prototypeClass = $schema->getPrototypeClass($uxon, $path, $rootPrototypeClass);
        $prototypeSchemaClass = $prototypeClass::getUxonSchemaClass() ?? '\\' . UxonSchema::class;
        $filepathRelative = $schema->getFilenameForEntity($prototypeClass);
        
        if ($schema instanceof QueryBuilderSchema) {
            $metaObject = 'exface.Core.UXON_QUERY_BUILDER_ANNOTATION';
        } else {
            $metaObject = 'exface.Core.UXON_PROPERTY_ANNOTATION';
        }
        
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), $metaObject);
        $ds->getColumns()->addMultiple([
            'PROPERTY', 
            'TYPE', 
            'TEMPLATE', 
            'DEFAULT', 
            'TITLE', 
            'REQUIRED',
            'DESCRIPTION'
            
        ]);
        $ds->getFilters()->addConditionFromString('FILE', $filepathRelative, ComparatorDataType::EQUALS);
        $ds->getSorters()->addFromString('PROPERTY', SortingDirectionsDataType::ASC);
        
        if ($schema instanceof QueryBuilderSchema) {
            $ds->getColumns()->addFromExpression('TARGET');
            $ds->getFilters()->addConditionFromString('TARGET', $schema->getLevel(), ComparatorDataType::EQUALS);
        }
        
        try {
            $ds->dataRead();
        } catch (\Throwable $e) {
            // TODO
        }
        
        $rows = $ds->getRows();
        
        
        //convert markdown to html, remove <a> tags 
        foreach ($rows as &$propertyRow){
            $propertyRow['DESCRIPTION'] = UxonSchema::buildHtmlFromMarkdown($propertyRow['DESCRIPTION'] ?? '');
        }
        
        // Get class annotations
        $dsClass = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_ENTITY_ANNOTATION');
        $dsClass->getColumns()->addMultiple([
            'CLASSNAME',
            'TITLE',
            'DESCRIPTION'
        ]);
        $dsClass->getFilters()->addConditionFromString('FILE', $filepathRelative);
        try {
            $dsClass->dataRead();
        } catch (\Throwable $e) {
            // TODO
        }
        $classInfo = $dsClass->getRow(0);
        $classInfo['DESCRIPTION'] = UxonSchema::buildHtmlFromMarkdown($classInfo['DESCRIPTION'] ?? '');
        
        // TODO transform enum-types to arrays
        
        return [
            'alias' => $classInfo['CLASSNAME'] ? $classInfo['CLASSNAME'] : StringDataType::substringAfter($prototypeClass, '\\', '', false, true),
            'prototype' => $prototypeClass,
            'prototype_schema' => $prototypeSchemaClass::getSchemaName(),
            'properties' => $rows,
            'title' => $classInfo['TITLE'],
            'description' => $classInfo['DESCRIPTION']
        ];
    }
    
    /**
     * 
     * @param UxonSchemaInterface $schema
     * @param UxonObject $uxon
     * @param array $path
     * @param string $rootPrototypeClass
     * @return array
     */
    protected function suggestPropertyNames(UxonSchemaInterface $schema, UxonObject $uxon, array $path, string $rootPrototypeClass = null) : array
    {
        $prototypeClass = $schema->getPrototypeClass($uxon, $path, $rootPrototypeClass);
        return [
            'values' => $schema->getProperties($prototypeClass, $uxon, $path),
            'templates' => $schema->getPropertiesTemplates($prototypeClass, $uxon, $path)
        ];
    }
    
    protected function suggestPropertyValues(UxonSchemaInterface $schema, UxonObject $uxon, array $path, string $valueText, string $rootPrototypeClass = null, MetaObjectInterface $rootObject = null) : array
    {
        return ['values' => $schema->getValidValues($uxon, $path, $valueText, $rootPrototypeClass, $rootObject)];
    }
}