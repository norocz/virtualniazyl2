<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\Azyl;
use App\Model\Orm\Entity\Users;
use Nette\Mail\Mailer;
use Nette\Mail\Message;

class AzylCoManagerMailService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $fromEmail,
        private readonly string $siteUrl,
    ) {}

    public function sendInvitation(Users $invitedUser, Azyl $azyl, string $token): void
    {
        $name     = htmlspecialchars($invitedUser->getUserName());
        $azylName = htmlspecialchars($azyl->getAzylName());
        $link     = htmlspecialchars($this->siteUrl . '/azyl/prijmout-pozvani/' . $token);

        $mail = new Message();
        $mail->setFrom($this->fromEmail, 'Virtuální azyl')
             ->addTo($invitedUser->getEmail())
             ->setSubject('Pozvánka ke správě azylu ' . $azyl->getAzylName())
             ->setHtmlBody(<<<HTML
        <p>Dobrý den, <strong>{$name}</strong>,</p>
        <p>byli jste pozváni ke správě azylu <strong>{$azylName}</strong> na platformě Virtuální azyl.</p>
        <p>Pro přijetí pozvánky klikněte na níže uvedený odkaz:</p>
        <p><a href="{$link}" style="display:inline-block;padding:10px 20px;background:#28a745;color:#fff;text-decoration:none;border-radius:4px;">Přijmout pozvánku</a></p>
        <p style="color:#999;font-size:12px;">Odkaz je platný po dobu 7 dní. Pokud jste pozvánku neočekávali, tento e-mail ignorujte.</p>
        <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
        <p style="color:#999;font-size:12px;">Virtuální azyl · virtualniazyl.cz</p>
        HTML);
        $this->mailer->send($mail);
    }
}
