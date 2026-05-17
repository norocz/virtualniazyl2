<?php
declare(strict_types=1);

namespace App\Forms;

use Nette\Application\UI\Form;

class ShopCheckoutFormFactory
{
    public function create(): Form
    {
        $form = new Form;

        $form->addText('buyerName', 'Jméno a příjmení')
            ->setRequired('Zadejte prosím jméno')
            ->setHtmlAttribute('class', 'form-control');

        $form->addEmail('buyerEmail', 'E-mail')
            ->setRequired('Zadejte prosím e-mail')
            ->setHtmlAttribute('class', 'form-control');

        $form->addText('buyerPhone', 'Telefon')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('type', 'tel');

        $form->addText('deliveryStreet', 'Ulice')
            ->setRequired('Zadejte ulici')
            ->setHtmlAttribute('class', 'form-control');

        $form->addText('deliveryHouseNumber', 'Č.p.')
            ->setRequired('Zadejte číslo popisné')
            ->setHtmlAttribute('class', 'form-control');

        $form->addText('deliveryCity', 'Město')
            ->setRequired('Zadejte město')
            ->setHtmlAttribute('class', 'form-control');

        $form->addText('deliveryPsc', 'PSČ')
            ->setRequired('Zadejte PSČ')
            ->addRule(Form::PATTERN, 'PSČ musí být ve formátu 12345 nebo 123 45', '\d{3}\s?\d{2}')
            ->setHtmlAttribute('class', 'form-control');

        $form->addTextArea('deliveryNote', 'Poznámka')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('rows', 2);

        $form->addCheckbox('agreeTerms', 'Souhlas s podmínkami')
            ->setRequired('Pro dokončení objednávky musíte souhlasit s podmínkami')
            ->setHtmlAttribute('class', 'form-check-input');

        $form->addSubmit('submit', 'Závazně objednat')
            ->setHtmlAttribute('class', 'btn btn-primary btn-lg');

        return $form;
    }
}

class ShopPhotoUploadFormFactory
{
    public function create(): Form
    {
        $form = new Form;

        $form->addUpload('photo', 'Fotka')
            ->setRequired('Vyberte soubor')
            ->addRule(Form::IMAGE, 'Musí být obrázek (JPG, PNG, WEBP)')
            ->addRule(Form::MAX_FILE_SIZE, 'Max 5 MB', 5 * 1024 * 1024)
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('accept', 'image/jpeg,image/png,image/webp');

        $form->addSubmit('upload', 'Nahrát')
            ->setHtmlAttribute('class', 'btn btn-primary');

        return $form;
    }
}
