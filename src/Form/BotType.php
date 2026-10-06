<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\BotData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form of a chat bot, used both to create one and to edit it.
 */
final class BotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Bot name',
                'attr' => ['autocomplete' => 'off', 'autofocus' => true, 'maxlength' => 255],
            ])
            ->add('avatar', FileType::class, [
                'label' => 'Bot avatar',
                'required' => false,
                'attr' => ['accept' => 'image/*'],
            ])
            ->add('webhookUrl', UrlType::class, [
                'label' => 'Webhook URL',
                'required' => false,
                'attr' => ['maxlength' => 2048],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => BotData::class]);
    }
}
