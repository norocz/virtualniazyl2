<?php
declare(strict_types=1);

namespace App\Forms;

use Nette\Application\UI\Form;

class ShopProductFormFactory
{
    public function create(): Form
    {
        $form = new Form;

        $form->addText('name', 'Název')
            ->setRequired('Zadejte název produktu')
            ->setHtmlAttribute('class', 'form-control');

        $form->addText('sku', 'SKU / Kód')
            ->setHtmlAttribute('class', 'form-control');

        $form->addText('shortDescription', 'Krátký popis')
            ->setHtmlAttribute('class', 'form-control')
            ->addRule(Form::MaxLength, 'Max %d znaků', 512);

        $form->addTextArea('description', 'Popis')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('rows', 6);

        $form->addFloat('price', 'Cena (Kč)')
            ->setRequired('Zadejte cenu')
            ->addRule(Form::Min, 'Cena musí být kladná', 0.01)
            ->setHtmlAttribute('class', 'form-control');

        $form->addInteger('stock', 'Skladem')
            ->setDefaultValue(0)
            ->addRule(Form::Min, 'Nesmí být záporné', 0)
            ->setHtmlAttribute('class', 'form-control');

        $form->addInteger('weightGrams', 'Váha (g)')
            ->setHtmlAttribute('class', 'form-control');

        $form->addText('category', 'Kategorie')
            ->setHtmlAttribute('class', 'form-control');

        $form->addCheckbox('unlimitedStock', 'Neomezené zásoby')
            ->setHtmlAttribute('class', 'form-check-input');

        $form->addCheckbox('isActive', 'Aktivní')
            ->setDefaultValue(true)
            ->setHtmlAttribute('class', 'form-check-input');

        $form->addSubmit('save', 'Uložit')
            ->setHtmlAttribute('class', 'btn btn-primary');

        return $form;
    }
}
