<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\AzylEvent;
use App\Model\Orm\Entity\AzylEventReservation;
use Nette\Mail\Mailer;
use Nette\Mail\Message;

class EventRegistrationMailService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $fromEmail,
        private readonly string $siteUrl,
    ) {}

    public function sendConfirmation(AzylEventReservation $r, string $cancelUrl): void
    {
        $to = $r->getEffectiveEmail();
        if (!$to) {
            return;
        }
        $event = $r->getEvent();
        $mail  = new Message();
        $mail->setFrom($this->fromEmail, 'Virtuální azyl — Události')
             ->addTo($to)
             ->setSubject('Potvrzení registrace: ' . $event->getTitle())
             ->setHtmlBody($this->confirmHtml($r, $cancelUrl));
        $this->mailer->send($mail);
    }

    public function sendWaitlist(AzylEventReservation $r, string $cancelUrl): void
    {
        $to = $r->getEffectiveEmail();
        if (!$to) {
            return;
        }
        $event = $r->getEvent();
        $mail  = new Message();
        $mail->setFrom($this->fromEmail, 'Virtuální azyl — Události')
             ->addTo($to)
             ->setSubject('Jste na čekací listině: ' . $event->getTitle())
             ->setHtmlBody($this->waitlistHtml($r, $cancelUrl));
        $this->mailer->send($mail);
    }

    public function sendPromoted(AzylEventReservation $r, string $cancelUrl): void
    {
        $to = $r->getEffectiveEmail();
        if (!$to) {
            return;
        }
        $event = $r->getEvent();
        $mail  = new Message();
        $mail->setFrom($this->fromEmail, 'Virtuální azyl — Události')
             ->addTo($to)
             ->setSubject('Vaše místo je potvrzeno: ' . $event->getTitle())
             ->setHtmlBody($this->promotedHtml($r, $cancelUrl));
        $this->mailer->send($mail);
    }

    public function sendOrganizerMessage(AzylEvent $event, array $reservations, string $subject, string $body): void
    {
        foreach ($reservations as $r) {
            $to = $r->getEffectiveEmail();
            if (!$to) {
                continue;
            }
            $mail = new Message();
            $mail->setFrom($this->fromEmail, $event->getAzyl()->getAzylName())
                 ->addTo($to)
                 ->setSubject('[' . $event->getTitle() . '] ' . $subject)
                 ->setHtmlBody($this->organizerMsgHtml($event, $r->getEffectiveName(), $body));
            $this->mailer->send($mail);
        }
    }

    // ── HTML builders ─────────────────────────────────────────────────────

    private function confirmHtml(AzylEventReservation $r, string $cancelUrl): string
    {
        $event = $r->getEvent();
        $name  = htmlspecialchars($r->getEffectiveName());
        $title = htmlspecialchars($event->getTitle());
        $date  = $event->getDateFrom()->format('d.m.Y H:i');
        $loc   = $event->getLocation() ? htmlspecialchars($event->getLocation()) : '';
        $cnt   = $r->getParticipantsCount();
        $cancel = htmlspecialchars($cancelUrl);

        return <<<HTML
        <p>Dobrý den, <strong>{$name}</strong>,</p>
        <p>vaše registrace na událost <strong>{$title}</strong> byla úspěšně zaznamenána.</p>
        <table style="margin:16px 0;border-collapse:collapse;font-size:14px;">
            <tr><td style="color:#666;padding:4px 12px 4px 0;">Datum:</td><td><strong>{$date}</strong></td></tr>
            {$this->locRow($loc)}
            <tr><td style="color:#666;padding:4px 12px 4px 0;">Počet osob:</td><td><strong>{$cnt}</strong></td></tr>
        </table>
        <p><a href="{$cancel}" style="color:#dc3545;">Zrušit registraci</a></p>
        <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
        <p style="color:#999;font-size:12px;">Virtuální azyl · virtualniazyl.cz</p>
        HTML;
    }

    private function waitlistHtml(AzylEventReservation $r, string $cancelUrl): string
    {
        $event  = $r->getEvent();
        $name   = htmlspecialchars($r->getEffectiveName());
        $title  = htmlspecialchars($event->getTitle());
        $date   = $event->getDateFrom()->format('d.m.Y H:i');
        $cancel = htmlspecialchars($cancelUrl);

        return <<<HTML
        <p>Dobrý den, <strong>{$name}</strong>,</p>
        <p>událost <strong>{$title}</strong> ({$date}) je momentálně plně obsazena.
        Zařadili jsme vás na <strong>čekací listinu</strong>.</p>
        <p>Pokud se uvolní místo, dáme vám okamžitě vědět e-mailem.</p>
        <p><a href="{$cancel}" style="color:#dc3545;">Odhlásit se z čekací listiny</a></p>
        <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
        <p style="color:#999;font-size:12px;">Virtuální azyl · virtualniazyl.cz</p>
        HTML;
    }

    private function promotedHtml(AzylEventReservation $r, string $cancelUrl): string
    {
        $event  = $r->getEvent();
        $name   = htmlspecialchars($r->getEffectiveName());
        $title  = htmlspecialchars($event->getTitle());
        $date   = $event->getDateFrom()->format('d.m.Y H:i');
        $loc    = $event->getLocation() ? htmlspecialchars($event->getLocation()) : '';
        $cancel = htmlspecialchars($cancelUrl);

        return <<<HTML
        <p>Dobrý den, <strong>{$name}</strong>,</p>
        <p>uvolnilo se místo a vaše účast na <strong>{$title}</strong> je nyní <strong>potvrzena</strong>!</p>
        <table style="margin:16px 0;border-collapse:collapse;font-size:14px;">
            <tr><td style="color:#666;padding:4px 12px 4px 0;">Datum:</td><td><strong>{$date}</strong></td></tr>
            {$this->locRow($loc)}
        </table>
        <p><a href="{$cancel}" style="color:#dc3545;">Zrušit registraci</a></p>
        <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
        <p style="color:#999;font-size:12px;">Virtuální azyl · virtualniazyl.cz</p>
        HTML;
    }

    private function organizerMsgHtml(AzylEvent $event, string $name, string $body): string
    {
        $title   = htmlspecialchars($event->getTitle());
        $orgName = htmlspecialchars($event->getAzyl()->getAzylName());
        $name    = htmlspecialchars($name);
        $body    = nl2br(htmlspecialchars($body));

        return <<<HTML
        <p>Dobrý den, <strong>{$name}</strong>,</p>
        <p>pořadatel události <strong>{$title}</strong> ({$orgName}) vám posílá zprávu:</p>
        <blockquote style="margin:16px 0;padding:12px 16px;background:#f8f8f8;border-left:4px solid #ccc;">
            {$body}
        </blockquote>
        <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
        <p style="color:#999;font-size:12px;">Virtuální azyl · virtualniazyl.cz</p>
        HTML;
    }

    private function locRow(string $loc): string
    {
        if ($loc === '') {
            return '';
        }
        return "<tr><td style=\"color:#666;padding:4px 12px 4px 0;\">Místo:</td><td><strong>{$loc}</strong></td></tr>";
    }
}
