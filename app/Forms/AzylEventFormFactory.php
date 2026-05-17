<?php
declare(strict_types=1);

namespace App\Forms;

use App\Model\Orm\Enums\RecurrenceTypeEnum;
use Nette\Application\UI\Form;

class AzylEventFormFactory
{
    public function create(): Form
    {
        $form = new Form;

        $form->addHidden('id');

        $form->addText('title', 'Název události')
            ->setRequired('Zadejte název události')
            ->setHtmlAttribute('class', 'form-control')
            ->addRule(Form::MaxLength, 'Název může mít maximálně %d znaků', 255);

        $form->addTextArea('shortDescription', 'Krátký popis')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('rows', '3')
            ->addRule(Form::MaxLength, 'Krátký popis může mít maximálně %d znaků', 512);

        $form->addTextArea('description', 'Podrobný popis')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('id', 'eventDescription')
            ->setHtmlAttribute('rows', '10');

        $form->addText('location', 'Místo konání')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('placeholder', 'např. Park Lužánky, Brno')
            ->addRule(Form::MaxLength, 'Místo může mít maximálně %d znaků', 255);

        $form->addText('dateFrom', 'Začátek')
            ->setRequired('Zadejte datum a čas začátku')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('type', 'datetime-local');

        $form->addText('dateTo', 'Konec')
            ->setRequired('Zadejte datum a čas konce')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('type', 'datetime-local');

        $recurrenceOptions = [];
        foreach (RecurrenceTypeEnum::cases() as $case) {
            $recurrenceOptions[$case->value] = $case->label();
        }
        $form->addSelect('recurrenceType', 'Opakování', $recurrenceOptions)
            ->setHtmlAttribute('class', 'form-control')
            ->setDefaultValue(RecurrenceTypeEnum::None->value);

        $form->addText('recurrenceEndDate', 'Opakovat do')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('type', 'date')
            ->setRequired(false);

        $form->addText('maxParticipants', 'Max. účastníků')
            ->setHtmlAttribute('class', 'form-control')
            ->setHtmlAttribute('type', 'number')
            ->setHtmlAttribute('min', '1')
            ->setHtmlAttribute('placeholder', 'prázdné = neomezeno')
            ->setRequired(false);

        $form->addCheckbox('registrationEnabled', 'Povolit registraci přes web')
            ->setHtmlAttribute('class', 'form-check-input');

        $form->addCheckbox('isPublished', 'Zveřejnit událost')
            ->setHtmlAttribute('class', 'form-check-input');

        $form->addSubmit('save', 'Uložit')
            ->setHtmlAttribute('class', 'btn btn-primary');

        return $form;
    }
}
