<?php
namespace Step\Api;

class Category extends \ApiTester
{
    protected $endpoint = 'categories';

    /**
     * Create Magento Category by REST API
     *
     * @param array $params
     * @return string
     */
    public function createCategory(array $params)
    {
        $this->amBearerAuthenticated($this->getAuthToke());
        $this->haveHttpHeader('Content-Type', 'application/json');
        $this->sendPOST($this->endpoint, $params);
        $this->seeResponseCodeIs(200);
        return $this->grabDataFromResponseByJsonPath('$.id')[0];
    }

    /**
     * Get Magento Category Data by REST API
     *
     * @param string $id
     * @return string
     */
    public function getCategory($id)
    {
        $this->amBearerAuthenticated($this->getAuthToke());
        $this->haveHttpHeader('Content-Type', 'application/json');
        $this->sendGET($this->endpoint . "/$id");
        $this->seeResponseCodeIs(200);
        return $this->grabResponse();
    }
}