<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\AcceptanceTestFramework\DataGenerator\Api;

use Magento\AcceptanceTestFramework\DataGenerator\Handlers\DataObjectHandler;
use Magento\AcceptanceTestFramework\DataGenerator\Handlers\JsonDefinitionObjectHandler;
use Magento\AcceptanceTestFramework\DataGenerator\Objects\EntityDataObject;
use Magento\AcceptanceTestFramework\DataGenerator\Objects\JsonDefinition;
use Magento\AcceptanceTestFramework\Util\ApiClientUtil;

class ApiExecutor
{
    /**
     * Describes the operation for the executor ('create','update','delete')
     *
     * @var string
     */
    private $operation;

    /**
     * The entity object data being created, updated, or deleted.
     * @var EntityDataObject $entityObject
     */
    private $entityObject;

    /**
     * The json definitions used to map the operation.
     * @var JsonDefinition $jsonDefinition
     */
    private $jsonDefinition;

    /**
     * The array of dependentEntities this class can be given. When finding linked entities, APIExecutor
     * uses this repository before looking for static data.
     * @var null
     */
    private $dependentEntities;

    const PRIMITIVE_TYPES = ['string', 'boolean', 'integer', 'double', 'array'];

    /**
     * ApiSubObject constructor.
     * @param string $operation
     * @param EntityDataObject $entityObject
     * @param array $dependentEntities
     */
    public function __construct($operation, $entityObject, $dependentEntities = null)
    {
        $this->operation = $operation;
        $this->entityObject = $entityObject;
        if ($dependentEntities != null) {
            foreach ($dependentEntities as $entity) {
                $this->dependentEntities[$entity->getName()] = $entity;
            }
        }

        $this->jsonDefinition = JsonDefinitionObjectHandler::getInstance()->getJsonDefinition(
            $this->operation,
            $this->entityObject->getType()
        );
    }

    /**
     * Executes an api request based on parameters given by constructor.
     *
     * @return string | null
     */
    public function executeRequest()
    {
        $apiClientUrl = $this->jsonDefinition->getApiUrl();

        $matchedParams = [];
        preg_match_all("/[{](.+?)[}]/", $apiClientUrl, $matchedParams);

        if (!empty($matchedParams)) {
            foreach ($matchedParams[0] as $paramKey => $paramValue) {
                $param = $this->entityObject->getDataByName($matchedParams[1][$paramKey]);
                $apiClientUrl = str_replace($paramValue, $param, $apiClientUrl);
            }
        }

        $authorization = $this->jsonDefinition->getAuth();
        $headers = $this->jsonDefinition->getHeaders();

        if ($authorization) {
            $headers[] = $this->getAuthorizationHeader($authorization);
        }

        $jsonBody = $this->getEncodedJsonString();

        $apiClientUtil = new ApiClientUtil(
            $apiClientUrl,
            $headers,
            $this->jsonDefinition->getApiMethod(),
            empty($jsonBody) ? null : $jsonBody
        );

        return $apiClientUtil->submit();
    }

    /**
     * Returns the authorization token needed for some requests via REST call.
     *
     * @param string $authUrl
     * @return string
     */
    private function getAuthorizationHeader($authUrl)
    {
        $headers = ['Content-Type: application/json'];
        $authCreds = [
            'username' => getenv('MAGENTO_ADMIN_USERNAME'),
            'password' => getenv('MAGENTO_ADMIN_PASSWORD')
        ];

        $apiClientUtil = new ApiClientUtil($authUrl, $headers, 'POST', json_encode($authCreds));
        $token = $apiClientUtil->submit();
        $authHeader = 'Authorization: Bearer ' . str_replace('"', "", $token);

        return $authHeader;
    }

    /**
     * This function returns an array which is structurally equal to the json which is needed by the web api for
     * entity creation. The function retrieves an array describing the json metadata and traverses any dependencies
     * recursively forming an array which represents the json structure for the api of the desired type.
     *
     * @param EntityDataObject $entityObject
     * @param array $jsonDefMetadata
     *
     * @return array
     */
    private function getJsonDataArray($entityObject, $jsonDefMetadata = null)
    {
        $jsonArrayMetadata = !$jsonDefMetadata ? JsonDefinitionObjectHandler::getInstance()->getJsonDefinition(
            $this->operation,
            $entityObject->getType()
        )->getJsonMetadata() : $jsonDefMetadata;

        $jsonArray = [];

        foreach ($jsonArrayMetadata as $jsonElement) {
            $jsonElementType = $jsonElement->getValue();

            if (in_array($jsonElementType, ApiExecutor::PRIMITIVE_TYPES)) {
                $elementData = $entityObject->getDataByName($jsonElement->getKey());
                $elementUniquenessData = $entityObject->getUniquenessDataByName($jsonElement->getKey());
                if ($elementUniquenessData) {
                    if ($elementUniquenessData == 'prefix') {
                        if (DataObjectHandler::UNIQUENESS_FUNCTION == 'msq') {
                            $elementData = msq($entityObject->getName().'.' . $jsonElement->getKey()).$elementData;
                        } elseif (DataObjectHandler::UNIQUENESS_FUNCTION == 'msqs') {
                            $elementData = msqs($entityObject->getName().'.' . $jsonElement->getKey()).$elementData;
                        }
                    } elseif ($elementUniquenessData == 'suffix') {
                        if (DataObjectHandler::UNIQUENESS_FUNCTION == 'msq') {
                            $elementData .= msq($entityObject->getName() . '.'. $jsonElement->getKey());
                        } elseif (DataObjectHandler::UNIQUENESS_FUNCTION == 'msqs') {
                            $elementData .= msqs($entityObject->getName() . '.'. $jsonElement->getKey());
                        }
                    }
                }
                $jsonArray[$jsonElement->getKey()] = $this->castValue($jsonElementType, $elementData);
            } else {
                $entityNamesOfType = $entityObject->getLinkedEntitiesOfType($jsonElementType);

                foreach ($entityNamesOfType as $entityName) {
                    // If this entity's name exists in the dependentEntities (Test-defined data), use that.
                    // Else go to the DataManager and try and get the entity from the overall repository of data.
                    if (array_key_exists($entityName, $this->dependentEntities)) {
                        $linkedEntityObj = $this->dependentEntities[$entityName];
                    } else {
                        $linkedEntityObj = DataObjectHandler::getInstance()->getObject($entityName);
                    }

                    $jsonDataSubArray = self::getJsonDataArray($linkedEntityObj);

                    if ($jsonElement->getType() == 'array') {
                        $jsonArray[$jsonElement->getKey()][] = $jsonDataSubArray;
                    } else {
                        $jsonArray[$jsonElement->getKey()] = $jsonDataSubArray;
                    }
                }
            }
        }

        return $jsonArray;
    }

    /**
     * This function retrieves an array representative of json body for a request and returns it encoded as a string.
     *
     * @return string
     */
    public function getEncodedJsonString()
    {
        $jsonArray = $this->getJsonDataArray($this->entityObject, $this->jsonDefinition->getJsonMetadata());

        return json_encode([$this->entityObject->getType() => $jsonArray], JSON_PRETTY_PRINT);
    }

    // @codingStandardsIgnoreStart
    /**
     * This function takes a string value and its corresponding type and returns the string cast
     * into its the type passed.
     *
     * @param string $type
     * @param string $value
     * @return mixed
     */
    private function castValue($type, $value)
    {
        $newVal = $value;

        switch ($type) {
            case 'string':
                break;
            case 'integer':
                $newVal = (integer)$value;
                break;
            case 'boolean':
                $newVal = (boolean)$value;
                break;
            case 'double':
                $newVal = (double)$value;
                break;
        }

        return $newVal;
    }
    // @codingStandardsIgnoreEnd
}