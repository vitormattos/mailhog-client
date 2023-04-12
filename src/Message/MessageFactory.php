<?php
declare(strict_types=1);

namespace rpkamp\Mailhog\Message;

use function implode;

class MessageFactory
{
    /**
     * @param mixed[] $mailhogResponse
     */
    public static function fromMailhogResponse(array $mailhogResponse): Message
    {
        return new Message(
            (string) $mailhogResponse['id'],
            Contact::fromString($mailhogResponse['sender_message']),
            ContactCollection::fromString(implode(',', $mailhogResponse['recipients_message_to'])),
            ContactCollection::fromString(implode(',', $mailhogResponse['recipients_message_cc'])),
            ContactCollection::fromString(implode(',', $mailhogResponse['recipients_message_bcc'])),
            $mailhogResponse['subject'],
            $mailhogResponse['body'],
            $mailhogResponse['attachments'],
            new Headers([])
        );
    }
}
