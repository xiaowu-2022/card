<?php

namespace App\Infrastructure\Mail;

use App\Domain\Notification\Contracts\CompanyEmailTransport;
use App\Domain\Notification\DTOs\EmailConnection;
use App\Domain\Notification\Exceptions\EmailDeliveryUnknown;
use App\Support\Errors\DomainException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\PlainAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class ProtonSmtpTransport implements CompanyEmailTransport
{
    public function build(#[\SensitiveParameter] EmailConnection $connection): EsmtpTransport
    {
        // Fresh transport per company/send: no global Mail/config mutation or credential cache.
        // false disables implicit TLS only; requireTls prevents plaintext authentication/delivery.
        $transport = new EsmtpTransport('smtp.protonmail.ch', 587, false, authenticators: [new LoginAuthenticator, new PlainAuthenticator]);
        $transport->setAutoTls(true)->setRequireTls(true);
        $transport->setUsername($connection->fromAddress)->setPassword($connection->token);
        $transport->getStream()->setTimeout(15);
        $transport->getStream()->setStreamOptions(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);

        return $transport;
    }

    public function send(#[\SensitiveParameter] EmailConnection $connection, #[\SensitiveParameter] string $destination, string $subject, #[\SensitiveParameter] string $html): void
    {
        $transport = $this->build($connection);
        try {
            $email = (new Email)->from(new Address($connection->fromAddress, $connection->fromName))
                ->to(new Address($destination))->subject($subject)->html($html);
            $transport->send($email);
        } catch (TransportExceptionInterface $exception) {
            // Explicit SMTP negative replies are definitive. Never disclose server text/debug/credentials.
            if ($exception->getCode() >= 400 && $exception->getCode() <= 599) {
                throw new DomainException('EMAIL_SEND_REJECTED', 'Unable to send email. Check the saved SMTP settings or contact support.', 503);
            }
            throw new EmailDeliveryUnknown;
        } catch (\Throwable) {
            throw new EmailDeliveryUnknown;
        } finally {
            // Symfony's SMTP debug trace contains AUTH and message data; never attach it to logs.
            try {
                $transport->stop();
            } catch (\Throwable) {
                // A QUIT error must not turn an already accepted message into a retry.
            }
        }
    }
}
