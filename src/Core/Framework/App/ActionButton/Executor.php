<?php declare(strict_types=1);

namespace Shopware\Core\Framework\App\ActionButton;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\App\ActionButton\Response\ActionButtonResponseFactory;
use Shopware\Core\Framework\App\Exception\ActionProcessException;
use Shopware\Core\Framework\App\Exception\AppUrlChangeDetectedException;
use Shopware\Core\Framework\App\Hmac\Guzzle\AuthMiddleware;
use Shopware\Core\Framework\App\ShopId\ShopIdProvider;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal only for use by the app-system, will be considered internal from v6.4.0 onward
 */
class Executor
{
    private Client $guzzleClient;

    private LoggerInterface $logger;

    private ActionButtonResponseFactory $actionButtonResponseFactory;

    private ShopIdProvider $shopIdProvider;

    public function __construct(
        Client $guzzle,
        LoggerInterface $logger,
        ActionButtonResponseFactory $actionButtonResponseFactory,
        ShopIdProvider $shopIdProvider
    ) {
        $this->guzzleClient = $guzzle;
        $this->logger = $logger;
        $this->actionButtonResponseFactory = $actionButtonResponseFactory;
        $this->shopIdProvider = $shopIdProvider;
    }

    public function execute(AppAction $action, Context $context): Response
    {
        try {
            $this->shopIdProvider->getShopId();
        } catch (AppUrlChangeDetectedException $e) {
            throw new ActionProcessException($action->getActionId(), $e->getMessage());
        }

        $payload = $action->asPayload();
        $payload['meta'] = [
            'timestamp' => (new \DateTime())->getTimestamp(),
            'reference' => Uuid::randomHex(),
            'language' => $context->getLanguageId(),
        ];

        try {
            $response = $this->guzzleClient->post(
                $action->getTargetUrl(),
                [
                    AuthMiddleware::APP_REQUEST_CONTEXT => $context,
                    AuthMiddleware::APP_REQUEST_TYPE => [
                        AuthMiddleware::APP_SECRET => $action->getAppSecret(),
                        AuthMiddleware::VALIDATED_RESPONSE => true,
                    ],
                    'json' => $payload,
                ]
            );
        } catch (ServerException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            $body = $e->getResponse()->getBody()->getContents();

            // InCase use only want to response without action type response
            // bypass check auth if status code is success
            if ($statusCode >= 200 && $statusCode < 300 && empty($body)) {
                return new JsonResponse();
            }

            $this->logger->notice(sprintf('ActionButton execution failed to target url "%s".', $action->getTargetUrl()), [
                'exceptionMessage' => $e->getMessage(),
                'statusCode' => $statusCode,
                'response' => $e->getResponse()->getBody()->getContents(),
            ]);

            throw new ActionProcessException($action->getActionId(), 'ActionButton execution failed');
        }

        $content = $response->getBody()->getContents();

        if (empty($content)) {
            return new JsonResponse();
        }

        $content = json_decode($content, true);

        if (!\array_key_exists('actionType', $content) || !\array_key_exists('payload', $content)) {
            throw new ActionProcessException($action->getActionId(), 'Invalid app response');
        }

        $actionResponse = $this->actionButtonResponseFactory->createFromResponse(
            $action,
            $content['actionType'],
            $content['payload'],
            $context
        );

        return new JsonResponse($actionResponse);
    }
}
