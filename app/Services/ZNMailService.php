<?php
declare(strict_types=1);

namespace App\Services;

use App\Model\Orm\Entity\AnimalSighting;
use App\Model\Orm\Entity\FoundAnimal;
use App\Model\Orm\Entity\LostAnimal;
use Nette\Mail\Mailer;
use Nette\Mail\Message;

class ZNMailService
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $fromEmail,
        private readonly string $siteUrl,
    ) {}

    public function sendSightingNotification(AnimalSighting $sighting): void
    {
        $lost  = $sighting->getLostAnimal();
        $owner = $lost->getUser();

        $closeUrl   = $this->siteUrl . '/zn/close/' . $lost->getSecretToken();
        $detailUrl  = $this->siteUrl . '/zn/lost/' . $lost->getId();
        $animalName = $lost->getName() ?? $lost->getSpecies()->getName();

        $html = $this->buildSightingHtml($sighting, $animalName, $closeUrl, $detailUrl);

        $mail = new Message();
        $mail->setFrom($this->fromEmail, 'Virtuální azyl — Z&N')
             ->addTo($owner->getEmail())
             ->setSubject('[Z&N] Nový vzkaz ke ztratenému zvířeti: ' . $animalName)
             ->setHtmlBody($html);

        $this->mailer->send($mail);
    }

    public function sendFoundConfirmation(FoundAnimal $found): void
    {
        $email = $found->getReporterEmail();
        if (!$email) {
            return;
        }
        $confirmUrl = $this->siteUrl . '/zn/confirm/' . $found->getConfirmToken();

        $html = '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;">'
            . '<h2 style="color:#d97706;">Potvrzení zadání nalezeného zvířete</h2>'
            . '<p>Díky, že jste nahlásil/a nalezení zvířete na portálu Virtuální azyl.</p>'
            . '<p><strong>Druh:</strong> ' . htmlspecialchars($found->getSpecies()->getName()) . '<br>'
            . '<strong>Místo:</strong> ' . htmlspecialchars($found->getLocation()) . '</p>'
            . '<p>Pro potvrzení prosím klikněte na odkaz níže. Bez potvrzení nebudou vaše kontaktní údaje zobrazeny.</p>'
            . '<p><a href="' . $confirmUrl . '" style="display:inline-block;padding:12px 24px;background:#16a34a;color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">'
            . 'Potvrdit zadání</a></p>'
            . '<p style="color:#888;font-size:12px;">Pokud jste žádost nezadával/a, tento email ignorujte.</p>'
            . '</body></html>';

        $mail = new Message();
        $mail->setFrom($this->fromEmail, 'Virtuální azyl — Z&N')
             ->addTo($email)
             ->setSubject('[Z&N] Potvrďte nahlášení nalezeného zvířete')
             ->setHtmlBody($html);

        $this->mailer->send($mail);
    }

    private function buildSightingHtml(AnimalSighting $s, string $animalName, string $closeUrl, string $detailUrl): string
    {
        $typeLabel = $s->getTypeLabel();
        $loc = $s->getLocation() ? '<br><strong>Místo:</strong> ' . htmlspecialchars($s->getLocation()) : '';
        $phone = $s->getContactPhone() ? '<br><strong>Telefon:</strong> ' . htmlspecialchars($s->getContactPhone()) : '';
        $name = $s->getContactName() ? '<br><strong>Jméno:</strong> ' . htmlspecialchars($s->getContactName()) : '';

        return '<!DOCTYPE html><html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;">'
            . '<h2 style="color:#d97706;">Nový vzkaz ke ztratenému zvířeti</h2>'
            . '<p>Někdo zanechal vzkaz k vašemu hledanému zvířeti <strong>' . htmlspecialchars($animalName) . '</strong>.</p>'
            . '<div style="background:#fef3c7;border-left:4px solid #d97706;padding:12px 16px;margin:16px 0;border-radius:4px;">'
            . '<strong>' . htmlspecialchars($typeLabel) . '</strong><br>'
            . '<em>' . htmlspecialchars($s->getMessage()) . '</em>'
            . $loc
            . '</div>'
            . '<p><strong>Kontakt nálezce:</strong>'
            . $name
            . '<br><strong>Email:</strong> ' . htmlspecialchars($s->getContactEmail())
            . $phone
            . '</p>'
            . '<p style="margin-top:24px;">'
            . '<a href="' . $detailUrl . '" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;margin-right:8px;">Zobrazit inzerát</a>'
            . '<a href="' . $closeUrl . '" style="display:inline-block;padding:10px 20px;background:#16a34a;color:#fff;text-decoration:none;border-radius:6px;">Zvíře nalezeno — uzavřít</a>'
            . '</p>'
            . '<p style="color:#888;font-size:12px;margin-top:24px;">Tuto notifikaci jste obdržel/a, protože jste zadal/a hledání zvířete na Virtuální azyl.</p>'
            . '</body></html>';
    }
}
