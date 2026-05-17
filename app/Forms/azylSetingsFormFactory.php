<?php

declare(strict_types=1);

namespace App\Forms;

use Nepada\PhoneNumberInput\PhoneNumberInput;
use Nette\Application\UI\Form;

class AzylSetingsFormFactory extends Form
{
    public string $jsonLink; //předání odkazu pro json
    public function setLink($link): void
    {
        $this->jsonLink = $link;
    }
    public function create(): Form
    {
        $form = new Form;
        $form->addHidden('id');
        $form->addText('azylName', 'Jméno azylu')
            ->setRequired('Zadejte jméno azylu')
            ->setHtmlAttribute('class', 'form-control')
            ->addRule(Form::MaxLength, 'Jméno azylu může mít maximálně %d znaků', 255);
        $form->addTextArea('description', 'Popis azylu')
            ->setHtmlAttribute('id', 'azylDescription')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('rows','15')
            ->addRule(Form::MaxLength, 'Info o azylu může mít maximálně %d znaků', 2048);
        $form->addTextArea('shortDescription', 'Krátký popisek')
            ->setHtmlAttribute('id', 'azylshortDescription')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('rows','5')
            ->addRule(Form::MaxLength, 'Info o azylu může mít maximálně %d znaků', 256);
        $form->addText('bankAccount', 'Bankovní účet')
            ->setHtmlAttribute('class', 'form-control');
        $form->addText('ico', 'IČO')
            ->setHtmlAttribute('class', 'form-control')   ;
        $form->addText('bankCode', 'Kód banky')
            ->setHtmlAttribute('class', 'form-control');
        $form->addText('bankSpecificCode', 'Variabilní symbol')
             ->setDefaultValue('269')
             ->setHtmlAttribute('class', 'form-control');
        $form->addText('phoneNumber', 'Telefonní číslo azylu')
            ->setRequired('Zadejte telefonní číslo')
            ->setHtmlType('tel')
            ->addRule(PhoneNumberInput::REGION, 'Prosím zadejte platný telefonní číslo. Pro ČR nebo SR začíná na +420 nebo +421.',['CZ', 'SK'])
            ->setDefaultValue('+420')
            ->setHtmlAttribute('class', 'form-control');
        $form->addEmail('email', 'E-mail')
        ->setHtmlAttribute('class', 'form-control');
        $form->addText('web', 'web')
               ->setHtmlAttribute('class', 'form-control')
                ->setHtmlAttribute('placeholder', 'https://');
        $form->addSelect('city','Město', [])
             ->setHtmlAttribute('class', 'form-control select2')
             ->setHtmlAttribute('id', 'citySelect')
             ->setHtmlAttribute('data-url', $this->jsonLink)
             ->setRequired(false)
             ->setPrompt('-- nevyplněno --')
             ->checkDefaultValue(false);
        $form->addText('street', 'Ulice')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('placeholder', 'např. Psí 1')
            ->setRequired(false);
        $form->addText('houseNumber', 'Číslo popisné')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('placeholder', 'např. 42')
            ->setRequired(false);
        $form->addText('zipCode', 'PSČ')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('placeholder', 'např. 60200')
            ->setRequired(false);
        $form->addSelect('countryCode', 'Stát', ['CZ' => 'Česká republika', 'SK' => 'Slovensko', 'DE' => 'Německo', 'AT' => 'Rakousko', 'PL' => 'Polsko'])
            ->setHtmlAttribute('class', 'form-control')
            ->setRequired(false);
        $form->addHidden('latitude')->setHtmlAttribute('id', 'inputLatitude');
        $form->addHidden('longitude')->setHtmlAttribute('id', 'inputLongitude');
        $form->addText('shippingFee', 'Poštovné (Kč)')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('type', 'number')
            ->setHtmlAttribute('step', '0.01')
            ->setHtmlAttribute('min', '0')
            ->setHtmlAttribute('placeholder', 'např. 99')
            ->setRequired(false);
        $form->addText('packagingFee', 'Balné (Kč, počítá se jednou za objednávku)')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('type', 'number')
            ->setHtmlAttribute('step', '0.01')
            ->setHtmlAttribute('min', '0')
            ->setHtmlAttribute('placeholder', 'např. 25')
            ->setRequired(false);
        $form->addText('shopFeePercent', 'Dobrovolný příspěvek na VAZ (%)')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('type', 'number')
            ->setHtmlAttribute('step', '0.5')
            ->setHtmlAttribute('min', '0')
            ->setHtmlAttribute('max', '100')
            ->setHtmlAttribute('placeholder', 'prázdné = systémový default (5 %)')
            ->setRequired(false);
        $form->addSelect('shopThemeColor', 'Barva eshopu', [
            '' => '— výchozí (zelená) —',
            'green' => 'Zelená',
            'blue' => 'Modrá',
            'orange' => 'Oranžová',
        ])->setHtmlAttribute('class', 'form-control')
          ->setRequired(false);
        $form->addSubmit('sendAzylSettings', 'Uložit')
            ->setHtmlAttribute('class', 'btn btn-primary');

        return $form;
    }

}