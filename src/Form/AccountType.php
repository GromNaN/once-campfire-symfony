<?php

declare(strict_types=1);

namespace App\Form;

use App\Form\Data\AccountData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form of the account: its name and its picture.
 *
 * The picture is replaced by uploading a new one, and the button beside it
 * deletes the current one, which is how the original behaves.
 */
final class AccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Account name',
                'attr' => ['autocomplete' => 'off', 'maxlength' => 255],
            ])
            ->add('logo', FileType::class, [
                'label' => 'Logo',
                'required' => false,
                'attr' => ['accept' => 'image/*'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountData::class,
            'method' => 'PATCH',
        ]);
    }
}
