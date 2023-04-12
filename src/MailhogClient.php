<?php
declare(strict_types=1);

namespace rpkamp\Mailhog;

use Generator;
use Http\Client\HttpClient;
use Http\Message\RequestFactory;
use rpkamp\Mailhog\Message\Message;
use rpkamp\Mailhog\Message\MessageFactory;
use rpkamp\Mailhog\Specification\Specification;
use RuntimeException;

use function array_filter;
use function array_key_exists;
use function array_slice;
use function count;
use function iterator_to_array;
use function json_decode;
use function json_encode;
use function rtrim;
use function sprintf;

class MailhogClient
{
    /**
     * @var HttpClient
     */
    private $httpClient;

    /**
     * @var RequestFactory
     */
    private $requestFactory;

    /**
     * @var string
     */
    private $baseUri;

    public function __construct(HttpClient $client, RequestFactory $requestFactory, string $baseUri)
    {
        $this->httpClient = $client;
        $this->requestFactory = $requestFactory;
        $this->baseUri = rtrim($baseUri, '/');
    }

    /**
     * @return Generator|Message[]
     */
    public function findAllMessages()
    {
        $start = 0;
        while (true) {
            $request = $this->requestFactory->createRequest(
                'GET',
                sprintf(
                    '%s/api/messages/?page=%d',
                    $this->baseUri,
                    $start
                )
            );

            $response = $this->httpClient->sendRequest($request);

            $allMessageData = json_decode($response->getBody()->getContents(), true);

            foreach ($allMessageData['data'] as $messageData) {
                $message = $this->fetchMetadata($messageData['id']);

                yield MessageFactory::fromMailhogResponse($message);
            }

            $start++;

            if ($start >= $allMessageData['meta']['pages_total']) {
                return;
            }
        }
    }

    /**
     * @return mixed[]
     */
    public function fetchMetadata(int $messageId): array
    {
        $return = $this->fetchByFormat($messageId, 'json');
        if (array_key_exists('plain', $return['formats'])) {
            $return['body'] = $this->fetchByFormat($messageId, 'plain')[0];

            return $return;
        }

        $return['body'] = $this->fetchByFormat($messageId, 'html')[0];

        return $return;
    }

    /**
     * @return mixed[]
     */
    public function fetchByFormat(int $messageId, string $format): array
    {
        $request = $this->requestFactory->createRequest(
            'GET',
            sprintf(
                '%s/api/messages/%d.%s',
                $this->baseUri,
                $messageId,
                $format
            )
        );

        $response = $this->httpClient->sendRequest($request);
        $content = $response->getBody()->getContents();
        if ($format === 'plain') {
            return [rtrim($content, "\r\n")];
        }

        if ($format === 'html') {
            return [rtrim($content, "\r\n")];
        }

        return json_decode($content, true)['data'];
    }

    /**
     * @return Message[]
     */
    public function findLatestMessages(int $numberOfMessages): array
    {
        $messages = iterator_to_array($this->findAllMessages());

        return array_slice($messages, $numberOfMessages * -1, $numberOfMessages);
    }

    /**
     * @return Message[]
     */
    public function findMessagesSatisfying(Specification $specification): array
    {
        return array_filter(
            iterator_to_array($this->findAllMessages()),
            static function (Message $message) use ($specification) {
                return $specification->isSatisfiedBy($message);
            }
        );
    }

    public function getLastMessage(): Message
    {
        $messages = $this->findLatestMessages(1);

        if (count($messages) === 0) {
            throw new NoSuchMessageException('No last message found. Inbox empty?');
        }

        return $messages[0];
    }

    public function getNumberOfMessages(): int
    {
        $messages = iterator_to_array($this->findAllMessages());

        return count($messages);
    }

    public function deleteMessage(string $messageId): void
    {
        $request = $this->requestFactory->createRequest('DELETE', sprintf('%s/api/messages/%s', $this->baseUri, $messageId));

        $this->httpClient->sendRequest($request);
    }

    public function purgeMessages(): void
    {
        $request = $this->requestFactory->createRequest('DELETE', sprintf('%s/api/messages/', $this->baseUri));

        $this->httpClient->sendRequest($request);
    }

    public function releaseMessage(string $messageId, string $host, int $port, string $emailAddress): void
    {
        $body = json_encode([
            'Host' => $host,
            'Port' => (string) $port,
            'Email' => $emailAddress,
        ]);

        if (false === $body) {
            throw new RuntimeException(
                sprintf('Unable to JSON encode data to release message %s', $messageId)
            );
        }

        $request = $this->requestFactory->createRequest(
            'POST',
            sprintf('%s/api/messages/%s/release', $this->baseUri, $messageId),
            [],
            $body
        );

        $this->httpClient->sendRequest($request);
    }

    public function getMessageById(string $messageId): Message
    {
        $request = $this->requestFactory->createRequest(
            'GET',
            sprintf(
                '%s/api/messages/%s',
                $this->baseUri,
                $messageId
            )
        );

        $response = $this->httpClient->sendRequest($request);

        $messageData = json_decode($response->getBody()->getContents(), true);

        if (null === $messageData) {
            throw NoSuchMessageException::forMessageId($messageId);
        }

        return MessageFactory::fromMailhogResponse($messageData);
    }
}
