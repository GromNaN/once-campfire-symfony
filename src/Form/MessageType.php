<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\MessageData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class MessageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('body', TextareaType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['rows' => 1, 'placeholder' => 'Write a message'],
            ])
            // The composer picks files with its own control, so the field is
            // only there to carry what it sends.
            ->add('attachment', FileType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('clientMessageId', HiddenType::class, [
                'required' => false,
                'attr' => ['data-composer-target' => 'clientMessageId'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MessageData::class]);
    }
}
