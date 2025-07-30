<?php

namespace Thalia\ShopifyRestToGraphql\Endpoints;

use Thalia\ShopifyRestToGraphql\GraphqlException;
use Thalia\ShopifyRestToGraphql\GraphqlService;

class ReturnEndpoints
{
    private GraphqlService $graphqlService;
    private string $shopDomain;
    private string $accessToken;

    public function __construct(string $shopDomain = null, string $accessToken = null)
    {

        if ($shopDomain === null || $accessToken === null) {
            throw new \InvalidArgumentException('Shop domain and access token must be provided.');
        }


        $this->shopDomain = $shopDomain;
        $this->accessToken = $accessToken;

        $this->graphqlService = new GraphqlService($this->shopDomain, $this->accessToken);
    }

    private function execute(string $query, array $variables, string $rootField): array
    {
        $responseData = $this->graphqlService->graphqlQueryThalia($query, $variables);

        if (!empty($responseData['errors'])) {
            throw new GraphqlException(
                "GraphQL errors returned from {$this->shopDomain}",
                500,
                $responseData['errors']
            );
        }

        if (!isset($responseData['data'])) {
            throw new GraphqlException(
                "Missing `data` key in GraphQL response from {$this->shopDomain}",
                500,
                []
            );
        }

        if (!isset($responseData['data'][$rootField])) {
            throw new GraphqlException(
                "Missing root field `{$rootField}` in GraphQL response from {$this->shopDomain}",
                500,
                []
            );
        }

        $payload = $responseData['data'][$rootField];

        if (!empty($payload['userErrors'])) {
            throw new GraphqlException(
                "GraphQL userErrors on {$rootField}",
                400,
                $payload['userErrors']
            );
        }

        return $payload;
    }

    public function returnApproveRequest(string $id): array
    {
        $variables = ['input' => ['id' => "gid://shopify/Return/{$id}"]];
        $query = <<<'GRAPHQL'
        mutation ReturnApproveRequest($input: ReturnApproveRequestInput!) {
          returnApproveRequest(input: $input) {
            return {
              id
              name
              status
              totalQuantity
              returnLineItems(first: 50) {
                edges { node {
                  id
                  quantity
                  refundableQuantity
                  refundedQuantity
                  returnReason
                  returnReasonNote
                }}
              }
              refunds(first: 50) { edges { node {
                id
                createdAt
                totalRefundedSet { shopMoney { amount currencyCode } }
                refundLineItems(first: 10) { edges { node { id quantity } } }
                staffMember { id }
              }}}
              returnShippingFees { 
                id 
                amountSet { shopMoney { amount currencyCode } }
              }
              reverseFulfillmentOrders(first: 50) { edges { node { id status } } }
              exchangeLineItems(first: 50) { edges { node { id lineItem { id title } } } }
              decline { reason note }
            }
            userErrors { field message code }
          }
        }
        GRAPHQL;

        return $this->execute($query, ['input' => $variables['input']], 'returnApproveRequest');
    }

    public function refundCreate(array $params): array
    {
        $variables = [
            'input' => [
                'orderId' => "gid://shopify/Order/{$params['orderId']}",
                'refundLineItems' => array_map(fn($li) => [
                    'lineItemId' => "gid://shopify/LineItem/{$li['lineItemId']}",
                    'quantity'   => $li['quantity']
                ], $params['lineItems']),
            ]
        ];
        $query = <<<'GRAPHQL'
        mutation RefundCreate($input: RefundInput!) {
          refundCreate(input: $input) {
            refund {
              id
              totalRefundedSet { presentmentMoney { amount currencyCode } }
            }
            userErrors { field message }
          }
        }
        GRAPHQL;

        return $this->execute($query, $variables, 'refundCreate');
    }

    public function returnCancelRequest(string $id, array $params): array
    {
        $input = ['id' => "gid://shopify/Return/{$id}", 'notifyCustomer' => $params['notifyCustomer'] ?? false];
        if (!empty($params['declineNote'])) {
            $input['declineReason'] = $params['declineReason'] ?? 'OTHER';
            $input['declineNote']   = $params['declineNote'];
        }

        $query = <<<'GRAPHQL'
            mutation ReturnDeclineRequest($input: ReturnDeclineRequestInput!) {
              returnDeclineRequest(input: $input) {
                return { id status }
                userErrors { code field message }
              }
            }
        GRAPHQL;

        return $this->execute($query, ['input' => $input], 'returnDeclineRequest');
    }

    public function returnRefund(array $params): array
    {
        if (empty($params['orderTransactions'])) {
            throw new \InvalidArgumentException('orderTransactions missing!');
        }

        $input = [
            'notifyCustomer'        => $params['notifyCustomer'] ?? false,
            'returnId'              => "gid://shopify/Return/{$params['returnId']}",
            'returnRefundLineItems' => array_map(fn($li) => [
                'returnLineItemId' => $li['returnLineItemId'], 
                'quantity'         => $li['quantity']
            ], $params['returnRefundLineItems']),
            'orderTransactions'     => array_map(fn($ot) => [
                'parentId'          => $ot['transactionId'],
                'transactionAmount' => [
                    'amount'       => $ot['amount'],
                    'currencyCode' => $ot['currencyCode']
                ]
            ], $params['orderTransactions']),
        ];

        $query = <<<'GRAPHQL'
        mutation returnRefund($input: ReturnRefundInput!) {
          returnRefund(returnRefundInput: $input) {
            refund { id note createdAt }
            userErrors { field message code }
          }
        }
        GRAPHQL;

        return $this->execute($query, ['input' => $input], 'returnRefund');
    }
}
