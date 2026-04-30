<?php

namespace App\Notifications;

use App\Notifications\Concerns\HasChannelRouting;
use Illuminate\Notifications\Notification;

abstract class BaseFailedNotification extends Notification
{
    use HasChannelRouting;

    public function __construct(
        public \Throwable $exception
    ) {}

    abstract public function getMessage(): FailedNotificationMessage;

    /**
     * Create a failed notification message.
     *
     * @param  array<string, string>  $fields
     */
    protected function message(
        string $title,
        string $body,
        string $actionText,
        string $actionUrl,
        string $footerText,
        string $errorLabel,
        array $fields = [],
    ): FailedNotificationMessage {
        return new FailedNotificationMessage(
            title: $title,
            body: $body,
            errorMessage: $this->exception->getMessage(),
            errorLabel: $errorLabel,
            actionText: $actionText,
            actionUrl: $actionUrl,
            footerText: $footerText,
            fields: $fields,
        );
    }
}
