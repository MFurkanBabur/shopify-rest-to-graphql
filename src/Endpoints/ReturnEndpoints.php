<?php

namespace Thalia\ShopifyRestToGraphql\Endpoints;

use Thalia\ShopifyRestToGraphql\GraphqlException;
use Thalia\ShopifyRestToGraphql\GraphqlService;

class ReturnEndpoints
{
    private $graphqlService;

    private $shopDomain;
    private $accessToken;

    public function __construct(string $shopDomain = null, string $accessToken = null)
    {

        if ($shopDomain === null || $accessToken === null) {
            throw new \InvalidArgumentException('Shop domain and access token must be provided.');
        }


        $this->shopDomain = $shopDomain;
        $this->accessToken = $accessToken;

        $this->graphqlService = new GraphqlService($this->shopDomain, $this->accessToken);

    }

    /**
     * To get Orders use this function.
     */
    public function returnApproveRequest($id)
    {
        /*
            Graphql Reference : https://shopify.dev/docs/api/admin-graphql/2025-01/mutations/returnApproveRequest
        */

        $variables['input']['id'] = "gid://shopify/Return/" . $id;

        $query = <<<"GRAPHQL"
        mutation ReturnApproveRequest(\$input: ReturnApproveRequestInput!) {
          returnApproveRequest(input: \$input) {
            return {
              id
              name
              status
              totalQuantity
        
              # --- İadeye bağlı Sipariş (Order) bilgisi ---
              order {
                id
              }
        
              # --- ReturnLineItemType içindeki mevcut alanlar (2025-01) ---
              returnLineItems(first: 50) {
                edges {
                  node {
                    id
                    quantity
                    refundableQuantity
                    refundedQuantity
                    returnReason
                    returnReasonNote
                  }
                }
              }
        
              # --- RefundConnection (2025-01’da Refund içinde amountSet YERİNE totalRefundedSet var) ---
              refunds(first: 50) {
                edges {
                  node {
                    id
                    createdAt
        
                    # Toplam iade edilen tutar (MoneyBag tipinde)
                    totalRefundedSet {
                      shopMoney {
                        amount
                        currencyCode
                      }
                      presentmentMoney {
                        amount
                        currencyCode
                      }
                    }
        
                    # Eğer refundLineItems çekmek isterseniz (opsiyonel)
                    refundLineItems(first: 10) {
                      edges {
                        node {
                          id
                          quantity
                        }
                      }
                    }
        
                    staffMember {
                      id
                    }
                  }
                }
              }
        
              # --- ReturnShippingFeeType (2025-01’da sadece amountSet mevcut) ---
              returnShippingFees {
                id
                amountSet {
                  shopMoney {
                    amount
                    currencyCode
                  }
                  presentmentMoney {
                    amount
                    currencyCode
                  }
                }
              }
        
              # --- ReverseFulfillmentOrderConnection (2025-01’da en temel alanlar) ---
              reverseFulfillmentOrders(first: 50) {
                edges {
                  node {
                    id
                    status
                  }
                }
              }
        
              # --- ExchangeLineItemConnection (2025-01’da quantity ve variant YOK, sadece id ve lineItem) ---
              exchangeLineItems(first: 50) {
                edges {
                  node {
                    id
                    lineItem {
                      id
                      title
                    }
                  }
                }
              }
        
              # --- ReturnDeclineType (2025-01’da sadece reason ve note) ---
              decline {
                reason
                note
              }
            }
        
            userErrors {
              field
              message
              code
            }
          }
        }
        GRAPHQL;

        $responseData = $this->graphqlService->graphqlQueryThalia($query, $variables);

        if (isset($responseData['data']['returnApproveRequest']['userErrors']) && count($responseData['data']['returnApproveRequest']['userErrors']) > 0) {
            throw new GraphqlException('GraphQL Error: ' . $this->shopDomain, 400, $responseData['data']['returnApproveRequest']['userErrors']);
        } else {
            return $responseData['data']['returnApproveRequest'];
        }
    }

    public function refundCreate($params)
    {
        /*
            Graphql Reference : https://shopify.dev/docs/api/admin-graphql/2025-01/mutations/refundcreate?example=creates-a-refund
        */

        $variables['input']['orderId'] = "gid://shopify/Order/" . $params['orderId'];

        $variables['input']['refundLineItems'] = [];
        foreach ($params['lineItems'] as $value) {
            $variables['input']['refundLineItems'][] = [
                "lineItemId" => "gid://shopify/LineItem/" . $value['lineItemId'],
                "quantity" => $value['quantity']
            ];
        }

        $query = <<<"GRAPHQL"
        mutation RefundCreate(\$input: RefundInput!) {
          refundCreate(input: \$input) {
            refund {
              id
              totalRefundedSet {
                presentmentMoney {
                  amount
                  currencyCode
                }
              }
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        $responseData = $this->graphqlService->graphqlQueryThalia($query, $variables);

        if (isset($responseData['data']['refundCreate']['userErrors']) && count($responseData['data']['refundCreate']['userErrors']) > 0) {
            throw new GraphqlException('GraphQL Error: ' . $this->shopDomain, 400, $responseData['data']['refundCreate']['userErrors']);
        } else {
            return $responseData['data']['refundCreate'];
        }
    }

    public function returnCancelRequest($id, $params)
    {
        /*
            Graphql Reference : https://shopify.dev/docs/api/admin-graphql/2025-01/mutations/returnDeclineRequest
        */

        $variables['id'] = "gid://shopify/Return/" . $id;

        if ($params['declineNote']) {
            $variables['declineReason'] = $params['declineReason'] ?? 'OTHER';
            $variables['declineNote'] = $params['declineNote'];
        }

        $variables['notifyCustomer'] = $params['notifyCustomer'] ?? false;

        $query = <<<"GRAPHQL"
            mutation ReturnDeclineRequest(\$input: ReturnDeclineRequestInput!) {
              returnDeclineRequest(input: \$input) {
                return {
                  id
                  status
                }
                userErrors {
                  code
                  field
                  message
                }
              }
            }
        GRAPHQL;

        $responseData = $this->graphqlService->graphqlQueryThalia($query, ['input' => $variables]);
        if (isset($responseData['data']['returnDeclineRequest']['userErrors']) && count($responseData['data']['returnDeclineRequest']['userErrors']) > 0) {
            throw new GraphqlException('GraphQL Error: ' . $this->shopDomain, 400, $responseData['data']['returnDeclineRequest']['userErrors']);
        } else {
            return $responseData['data']['returnDeclineRequest'];
        }
    }

    public function returnRefund($params)
    {
        /*
            Graphql Reference : https://shopify.dev/docs/api/admin-graphql/2025-01/mutations/returnrefund
        */

        if (empty($params['orderTransactions'])) {
            throw new \InvalidArgumentException('orderTransactions missing!');
        }

        $variables['notifyCustomer'] = $params['notifyCustomer'] ?? false;
        $variables['returnId'] = "gid://shopify/Return/" . $params['returnId'];

        foreach ($params['returnRefundLineItems'] as $value) {
            $variables['returnRefundLineItems'][] = [
                "returnLineItemId" => "gid://shopify/ReturnLineItem/" . $value['returnLineItemId'],
                "quantity" => $value['quantity']
            ];
        }

        $variables['orderTransactions'] = [];
        foreach ($params['orderTransactions'] as $as) {
            $variables['orderTransactions'][] =[
                'parentId' => $as['transactionId'],
                'transactionAmount' => [
                    'amount' => $as['amount'],
                    'currencyCode' => $as['currencyCode']
                ],
            ];
        }

        $query = <<<"GRAPHQL"
         mutation returnRefund(\$input: ReturnRefundInput!) {
          returnRefund(returnRefundInput: \$input) {
            refund {
              id
              note
              createdAt
            }
            userErrors {
              field
              message
              code
            }
          }
        }
        GRAPHQL;

        $responseData = $this->graphqlService->graphqlQueryThalia($query, ['input' => $variables]);

        if (isset($responseData['data']['returnRefund']['userErrors']) && count($responseData['data']['returnRefund']['userErrors']) > 0) {
            throw new GraphqlException('GraphQL Error: ' . $this->shopDomain, 400, $responseData['data']['returnRefund']['userErrors']);
        } else {
            return $responseData['data']['returnRefund'];
        }
    }
}
